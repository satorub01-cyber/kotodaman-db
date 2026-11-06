<?php
if (!defined('ABSPATH')) exit;

const KOTO_MCP_PROTOCOL_VERSION = '2025-03-26';
const KOTO_MCP_DEFAULT_IMAGE_URL = 'https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png';

add_action('rest_api_init', 'koto_mcp_register_server_routes');
add_action('admin_menu', 'koto_mcp_register_approval_menu');
add_action('admin_init', 'koto_mcp_handle_approval_action');

function koto_mcp_base_url()
{
    $configured = getenv('KOTO_MCP_BASE_URL');
    if (!$configured) return untrailingslashit(rest_url('kotodaman/v1'));
    $configured = untrailingslashit(esc_url_raw($configured));
    if (strpos($configured, '/wp-json/') === false) {
        $configured .= '/wp-json/kotodaman/v1';
    }
    return $configured;
}

function koto_mcp_server_url()
{
    return koto_mcp_base_url() . '/mcp';
}

function koto_mcp_json_error($code, $field, $reason, $message = '')
{
    return [
        'code'    => $code,
        'field'   => $field,
        'reason'  => $reason,
        'message' => $message ?: $reason,
    ];
}

function koto_mcp_register_server_routes()
{
    register_rest_route('kotodaman/v1', '/mcp', [
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'koto_mcp_handle_http',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/register', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'koto_mcp_oauth_register_client',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/authorize', [
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'koto_mcp_oauth_authorize',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/token', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'koto_mcp_oauth_token',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/revoke', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'koto_mcp_oauth_revoke',
        'permission_callback' => '__return_true',
    ]);
}

function koto_mcp_metadata_response($request)
{
    $server = koto_mcp_server_url();
    return rest_ensure_response([
        'resource' => $server,
        'authorization_servers' => [koto_mcp_base_url()],
        'scopes_supported' => ['validate', 'preview', 'create'],
        'bearer_methods_supported' => ['header'],
    ]);
}

add_action('init', function () {
    add_rewrite_rule('^\\.well-known/oauth-protected-resource/?$', 'index.php?koto_mcp_meta=protected', 'top');
    add_rewrite_rule('^\\.well-known/oauth-authorization-server/?$', 'index.php?koto_mcp_meta=authorization', 'top');
    add_rewrite_tag('%koto_mcp_meta%', '([^&]+)');
});
add_filter('query_vars', function ($vars) {
    $vars[] = 'koto_mcp_meta';
    return $vars;
});
add_action('template_redirect', function () {
    $meta = get_query_var('koto_mcp_meta');
    if (!$meta) return;
    $base = koto_mcp_base_url();
    $response = $meta === 'protected'
        ? [
            'resource' => koto_mcp_server_url(),
            'authorization_servers' => [$base],
            'scopes_supported' => ['validate', 'preview', 'create'],
            'bearer_methods_supported' => ['header'],
        ]
        : [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/mcp/oauth/authorize',
            'token_endpoint' => $base . '/mcp/oauth/token',
            'registration_endpoint' => $base . '/mcp/oauth/register',
            'revocation_endpoint' => $base . '/mcp/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['validate', 'preview', 'create'],
        ];
    status_header(200);
    header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
    echo wp_json_encode($response);
    exit;
});

function koto_mcp_current_user_from_token()
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) return 0;
    $token = hash('sha256', trim($match[1]));
    $data = get_transient('koto_mcp_token_' . $token);
    if (!is_array($data) || empty($data['user_id'])) return 0;
    if (!empty($data['scope']) && !is_user_logged_in()) {
        wp_set_current_user((int) $data['user_id']);
    }
    return (int) $data['user_id'];
}

function koto_mcp_require_scope($scope)
{
    $user_id = koto_mcp_current_user_from_token();
    if (!$user_id) return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', ['status' => 401]);
    $token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $data = get_transient('koto_mcp_token_' . hash('sha256', trim($token)));
    $scopes = preg_split('/\s+/', trim((string) ($data['scope'] ?? '')));
    if (!in_array($scope, $scopes, true) && !user_can($user_id, 'manage_options')) {
        return new WP_Error('mcp_forbidden', 'Required OAuth scope is missing.', ['status' => 403]);
    }
    if (!user_can($user_id, 'edit_posts')) {
        return new WP_Error('mcp_forbidden', 'The WordPress user cannot edit posts.', ['status' => 403]);
    }
    $user = get_userdata($user_id);
    $privileged = $user && array_intersect(['administrator', 'editor'], (array) $user->roles);
    if (!$privileged && !get_user_meta($user_id, '_koto_mcp_approved', true)) {
        return new WP_Error('mcp_pending_approval', 'MCP access approval is required.', ['status' => 403]);
    }
    return $user_id;
}

function koto_mcp_handle_http($request)
{
    $body = json_decode($request->get_body(), true);
    if (!is_array($body)) return new WP_Error('invalid_json', 'JSON-RPC body is required.', ['status' => 400]);
    $method = $body['method'] ?? '';
    $id = $body['id'] ?? null;
    if ($method === 'initialize') {
        return rest_ensure_response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => KOTO_MCP_PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'kotodaman-db', 'version' => '1.0.0'],
            ],
        ]);
    }
    if ($method === 'notifications/initialized') return new WP_REST_Response(null, 202);
    if ($method === 'tools/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => koto_mcp_tools()]]);
    }
    if ($method !== 'tools/call') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
    $name = $body['params']['name'] ?? '';
    $arguments = $body['params']['arguments'] ?? [];
    $tool = koto_mcp_call_tool($name, $arguments);
    if (is_wp_error($tool)) {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $tool->get_error_code(), 'message' => $tool->get_error_message(), 'data' => $tool->get_error_data()]]);
    }
    return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => wp_json_encode($tool, JSON_UNESCAPED_UNICODE)]]]]);
}

function koto_mcp_tools()
{
    $object = ['type' => 'object'];
    return [
        ['name' => 'get_field_schema', 'description' => 'Return ACF field schema and manual guidance.', 'inputSchema' => $object],
        ['name' => 'get_terms', 'description' => 'Return term dictionaries with IDs.', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string']], 'required' => ['taxonomy']]],
        ['name' => 'search_character', 'description' => 'Search existing character posts before creating one.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
        ['name' => 'validate', 'description' => 'Validate without saving.', 'inputSchema' => koto_mcp_draft_schema(false)],
        ['name' => 'preview_draft', 'description' => 'Parse and hash a draft without saving.', 'inputSchema' => koto_mcp_draft_schema(false)],
        ['name' => 'create_draft', 'description' => 'Create a draft only after preview hash confirmation.', 'inputSchema' => koto_mcp_draft_schema(true)],
        ['name' => 'update_draft', 'description' => 'Update an existing draft.', 'inputSchema' => koto_mcp_draft_schema(true, true)],
        ['name' => 'trash_draft', 'description' => 'Move an existing draft to trash.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']]],
    ];
}

function koto_mcp_draft_schema($hash_required = false, $update = false)
{
    $schema = [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'character_name' => ['type' => 'string'],
            'basic_fields' => ['type' => 'object'],
            'auto_input' => ['type' => 'object'],
            'screenshot_urls' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']],
            'evidence' => ['type' => 'object'],
            'idempotency_key' => ['type' => 'string'],
            'preview_hash' => ['type' => 'string'],
            'human_approved' => ['type' => 'boolean'],
        ],
        'required' => $update ? ['post_id', 'idempotency_key', 'preview_hash'] : ($hash_required ? ['character_name', 'basic_fields', 'idempotency_key', 'preview_hash'] : ['character_name']),
    ];
    return $schema;
}

function koto_mcp_field_manuals()
{
    $path = get_stylesheet_directory() . '/documents/mcp-field-manual.json';
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function koto_mcp_field_schema()
{
    $fields = [];
    $files = glob(get_stylesheet_directory() . '/acf-json/group_*.json') ?: [];
    foreach ($files as $file) {
        $group = json_decode(file_get_contents($file), true);
        foreach (($group['fields'] ?? []) as $field) {
            if (empty($field['name']) || in_array($field['type'], ['tab', 'accordion'], true)) continue;
            $fields[$field['name']] = [
                'label' => $field['label'] ?? $field['name'],
                'type' => $field['type'],
                'required' => !empty($field['required']),
                'taxonomy' => $field['taxonomy'] ?? null,
                'choices' => $field['choices'] ?? null,
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
                'instructions' => $field['instructions'] ?? '',
            ];
        }
    }
    foreach (koto_mcp_field_manuals() as $name => $manual) {
        if (isset($fields[$name]) && is_array($manual)) $fields[$name]['manual'] = $manual;
    }
    return $fields;
}

function koto_mcp_validate_draft($args)
{
    $errors = ['missing_fields' => [], 'invalid_fields' => []];
    $fields = $args['basic_fields'] ?? [];
    $schema = koto_mcp_field_schema();
    foreach (['attribute', 'species', 'rarity'] as $required) {
        if (!array_key_exists($required, $fields) || $fields[$required] === null || $fields[$required] === '') {
            $errors['missing_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $required, 'required_empty');
        }
    }
    foreach ($fields as $name => $value) {
        if (!isset($schema[$name])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_value');
            continue;
        }
        if ($value === null) continue;
        $definition = $schema[$name];
        if (!empty($definition['taxonomy'])) {
            $ids = is_array($value) ? $value : [$value];
            foreach ($ids as $term_id) {
                if (!is_int($term_id) && !(is_string($term_id) && ctype_digit($term_id))) {
                    $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                    continue;
                }
                if (!term_exists((int) $term_id, $definition['taxonomy'])) {
                    $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                }
            }
        }
    }
    $csv = koto_group_csv_by_type(koto_load_csv_dictionary(get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv'));
    $parsed = koto_build_acf_data_from_inputs($args['auto_input'] ?? [], $csv);
    $requested = array_filter($args['auto_input'] ?? [], static function ($value) { return $value !== null && $value !== ''; });
    foreach ($requested as $input_key => $input_value) {
        if (!array_key_exists($input_key, $parsed) || empty($parsed[$input_key])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $input_key, 'unparsed_text');
        }
    }
    return ['errors' => $errors, 'parsed_acf' => $parsed];
}

function koto_mcp_content_hash($args, $parsed)
{
    return hash('sha256', wp_json_encode(['character_name' => $args['character_name'] ?? '', 'basic_fields' => $args['basic_fields'] ?? [], 'auto_input' => $args['auto_input'] ?? [], 'parsed_acf' => $parsed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function koto_mcp_call_tool($name, $args)
{
    $scope = in_array($name, ['get_field_schema', 'get_terms', 'search_character', 'validate'], true) ? 'validate' : 'preview';
    if (in_array($name, ['create_draft', 'update_draft', 'trash_draft'], true)) $scope = 'create';
    $auth = koto_mcp_require_scope($scope);
    if (is_wp_error($auth)) return $auth;
    $rate_key = 'koto_mcp_rate_' . $auth;
    $rate_count = (int) get_transient($rate_key);
    if ($rate_count >= 60) {
        return new WP_Error('RATE_LIMITED', 'Too many MCP requests. Please retry later.', ['field' => '', 'reason' => 'invalid_value', 'status' => 429]);
    }
    set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

    if ($name === 'get_field_schema') return ['fields' => koto_mcp_field_schema(), 'required_for_create' => ['attribute', 'species', 'rarity']];
    if ($name === 'get_terms') {
        $taxonomy = sanitize_key($args['taxonomy'] ?? '');
        if (!taxonomy_exists($taxonomy)) return new WP_Error('VALIDATION_FAILED', 'Unknown taxonomy.', ['field' => 'taxonomy', 'reason' => 'invalid_value']);
        return ['taxonomy' => $taxonomy, 'terms' => array_map(static function ($term) { return ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug]; }, get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]))];
    }
    if ($name === 'search_character') {
        $query = new WP_Query(['post_type' => 'character', 'post_status' => ['publish', 'draft', 'pending', 'private'], 's' => sanitize_text_field($args['name'] ?? ''), 'posts_per_page' => 20]);
        return ['matches' => array_map(static function ($post) { return ['id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status]; }, $query->posts)];
    }
    if ($name === 'trash_draft') {
        $post = get_post((int) ($args['post_id'] ?? 0));
        if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft') return new WP_Error('VALIDATION_FAILED', 'Only character drafts can be trashed.', ['field' => 'post_id', 'reason' => 'invalid_value']);
        wp_trash_post($post->ID);
        return ['post_id' => $post->ID, 'status' => 'trash'];
    }
    $validation = koto_mcp_validate_draft($args);
    if (!empty($validation['errors']['missing_fields']) || !empty($validation['errors']['invalid_fields'])) {
        return new WP_Error('VALIDATION_FAILED', 'Draft validation failed.', $validation['errors']);
    }
    $hash = koto_mcp_content_hash($args, $validation['parsed_acf']);
    if ($name === 'validate') return ['valid' => true, 'parsed_acf' => $validation['parsed_acf'], 'content_hash' => $hash];
    if ($name === 'preview_draft') return ['valid' => true, 'parsed_acf' => $validation['parsed_acf'], 'content_hash' => $hash, 'warnings' => []];
    if (empty($args['preview_hash']) || !hash_equals($hash, (string) $args['preview_hash'])) {
        return new WP_Error('PREVIEW_HASH_MISMATCH', 'preview_hash does not match the current content.', ['field' => 'preview_hash', 'reason' => 'invalid_value']);
    }
    $key = sanitize_key($args['idempotency_key'] ?? '');
    if ($key === '') return new WP_Error('VALIDATION_FAILED', 'idempotency_key is required.', ['field' => 'idempotency_key', 'reason' => 'required_empty']);
    $existing = get_transient('koto_mcp_idempotency_' . $auth . '_' . $key);
    if ($existing) return $existing;
    if ($name === 'create_draft') {
        $post_id = koto_create_character_post_from_acf(sanitize_text_field($args['character_name']), $validation['parsed_acf']);
    } else {
        $post_id = (int) $args['post_id'];
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft' || !current_user_can('edit_post', $post_id)) return new WP_Error('VALIDATION_FAILED', 'Only editable character drafts can be updated.', ['field' => 'post_id', 'reason' => 'invalid_value']);
        koto_update_character_post_with_acf($post_id, $validation['parsed_acf']);
    }
    if (is_wp_error($post_id) || !$post_id) return new WP_Error('CREATE_FAILED', 'Draft creation failed.', ['field' => 'post', 'reason' => 'invalid_value']);
    koto_mcp_update_basic_fields($post_id, $args['basic_fields'] ?? []);
    $urls = !empty($args['screenshot_urls']) ? $args['screenshot_urls'] : [KOTO_MCP_DEFAULT_IMAGE_URL];
    update_post_meta($post_id, '_koto_mcp_screenshot_urls', array_values(array_filter(array_map('esc_url_raw', $urls))));
    koto_mcp_set_featured_image_from_url($post_id, $urls[0] ?? '');
    update_post_meta($post_id, '_koto_mcp_generated', 1);
    update_post_meta($post_id, '_koto_mcp_needs_review', 1);
    update_post_meta($post_id, '_koto_mcp_audit', ['user_id' => $auth, 'content_hash' => $hash, 'evidence' => $args['evidence'] ?? [], 'created_at' => current_time('mysql')]);
    do_action('acf/save_post', $post_id);
    $result = ['post_id' => $post_id, 'status' => 'draft', 'edit_url' => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id), 'content_hash' => $hash, 'screenshot_urls' => get_post_meta($post_id, '_koto_mcp_screenshot_urls', true)];
    set_transient('koto_mcp_idempotency_' . $auth . '_' . $key, $result, DAY_IN_SECONDS);
    return $result;
}

function koto_mcp_set_featured_image_from_url($post_id, $url)
{
    $url = esc_url_raw($url);
    if (!$url || wp_parse_url($url, PHP_URL_HOST) !== 'www.kotodaman-db.com') return;
    $existing = attachment_url_to_postid($url);
    if ($existing) {
        set_post_thumbnail($post_id, $existing);
        $image_field = koto_get_top_level_character_acf_field('character_image');
        if ($image_field) update_field($image_field['key'], $existing, $post_id);
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($url, 15);
    if (is_wp_error($tmp)) return;
    $filename = sanitize_file_name(basename((string) wp_parse_url($url, PHP_URL_PATH)));
    $file = ['name' => $filename ?: 'koto-mcp-image.png', 'tmp_name' => $tmp];
    $attachment = media_handle_sideload($file, $post_id);
    if (is_wp_error($attachment)) {
        @unlink($tmp);
        return;
    }
    set_post_thumbnail($post_id, $attachment);
    $image_field = koto_get_top_level_character_acf_field('character_image');
    if ($image_field) update_field($image_field['key'], $attachment, $post_id);
}

function koto_mcp_oauth_register_client($request)
{
    $body = json_decode($request->get_body(), true) ?: [];
    $id = 'koto_' . wp_generate_password(24, false, false);
    set_transient('koto_mcp_client_' . $id, ['redirect_uris' => $body['redirect_uris'] ?? [], 'name' => $body['client_name'] ?? 'MCP client'], YEAR_IN_SECONDS);
    return rest_ensure_response(['client_id' => $id, 'client_name' => $body['client_name'] ?? 'MCP client', 'redirect_uris' => $body['redirect_uris'] ?? [], 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']);
}

function koto_mcp_oauth_authorize($request)
{
    if (!is_user_logged_in()) {
        auth_redirect();
        return;
    }
    $client_id = sanitize_text_field($request->get_param('client_id'));
    $client = get_transient('koto_mcp_client_' . $client_id);
    if (!is_array($client)) return new WP_Error('invalid_client', 'Unknown client.', ['status' => 400]);
    $user = wp_get_current_user();
    $privileged = array_intersect(['administrator', 'editor'], (array) $user->roles);
    if (!$privileged && !get_user_meta(get_current_user_id(), '_koto_mcp_approved', true)) {
        update_user_meta(get_current_user_id(), '_koto_mcp_requested', 1);
        return new WP_Error('approval_required', 'MCP access approval is required.', ['status' => 403]);
    }
    $redirect = esc_url_raw($request->get_param('redirect_uri'));
    $code = wp_generate_password(48, false, false);
    set_transient('koto_mcp_code_' . hash('sha256', $code), ['user_id' => get_current_user_id(), 'client_id' => $client_id, 'redirect_uri' => $redirect, 'challenge' => sanitize_text_field($request->get_param('code_challenge')), 'scope' => sanitize_text_field($request->get_param('scope') ?: 'validate preview create')], 300);
    return new WP_REST_Response(null, 302, ['Location' => add_query_arg(['code' => $code, 'state' => sanitize_text_field($request->get_param('state'))], $redirect)]);
}

function koto_mcp_oauth_token($request)
{
    $body = $request->get_json_params();
    if (($body['grant_type'] ?? '') === 'refresh_token') {
        $refresh = get_transient('koto_mcp_refresh_' . hash('sha256', (string) ($body['refresh_token'] ?? '')));
        if (!is_array($refresh)) return new WP_Error('invalid_grant', 'Invalid refresh token.', ['status' => 400]);
        return koto_mcp_issue_token($refresh['user_id'], $refresh['scope']);
    }
    $code = (string) ($body['code'] ?? '');
    $data = get_transient('koto_mcp_code_' . hash('sha256', $code));
    if (!is_array($data)) return new WP_Error('invalid_grant', 'Invalid authorization code.', ['status' => 400]);
    delete_transient('koto_mcp_code_' . hash('sha256', $code));
    return koto_mcp_issue_token($data['user_id'], $data['scope']);
}

function koto_mcp_issue_token($user_id, $scope)
{
    $access = wp_generate_password(64, false, false);
    $refresh = wp_generate_password(72, false, false);
    set_transient('koto_mcp_token_' . hash('sha256', $access), ['user_id' => (int) $user_id, 'scope' => $scope], HOUR_IN_SECONDS);
    set_transient('koto_mcp_refresh_' . hash('sha256', $refresh), ['user_id' => (int) $user_id, 'scope' => $scope], 30 * DAY_IN_SECONDS);
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => HOUR_IN_SECONDS, 'refresh_token' => $refresh, 'scope' => $scope];
}

function koto_mcp_oauth_revoke($request)
{
    $token = (string) ($request->get_json_params()['token'] ?? '');
    delete_transient('koto_mcp_token_' . hash('sha256', $token));
    delete_transient('koto_mcp_refresh_' . hash('sha256', $token));
    return ['revoked' => true];
}

function koto_mcp_register_approval_menu()
{
    add_users_page('MCP利用申請', 'MCP利用申請', 'manage_options', 'koto-mcp-approvals', 'koto_mcp_approval_page');
}

function koto_mcp_approval_page()
{
    if (!current_user_can('manage_options')) return;
    $users = get_users(['meta_key' => '_koto_mcp_requested', 'meta_value' => 1]);
    echo '<div class="wrap"><h1>MCP利用申請</h1><table class="widefat"><thead><tr><th>ユーザー</th><th>操作</th></tr></thead><tbody>';
    foreach ($users as $user) {
        $url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_approve=' . $user->ID), 'koto_mcp_approve_' . $user->ID);
        echo '<tr><td>' . esc_html($user->user_login) . ' (' . esc_html($user->user_email) . ')</td><td><a class="button button-primary" href="' . esc_url($url) . '">承認</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

function koto_mcp_handle_approval_action()
{
    if (!current_user_can('manage_options') || empty($_GET['koto_mcp_approve'])) return;
    $user_id = (int) $_GET['koto_mcp_approve'];
    check_admin_referer('koto_mcp_approve_' . $user_id);
    update_user_meta($user_id, '_koto_mcp_approved', 1);
    delete_user_meta($user_id, '_koto_mcp_requested');
    wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
    exit;
}
