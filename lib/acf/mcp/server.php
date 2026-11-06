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

function koto_mcp_issuer_url()
{
    return untrailingslashit(home_url('/'));
}

function koto_mcp_authorization_endpoint_url()
{
    return koto_mcp_issuer_url() . '/mcp/oauth/authorize';
}

function koto_mcp_protected_resource_metadata_url()
{
    return koto_mcp_issuer_url() . '/.well-known/oauth-protected-resource';
}

function koto_mcp_scope_string_to_list($scope)
{
    if (!is_string($scope)) return [];
    $parts = preg_split('/\s+/', trim($scope));
    $parts = is_array($parts) ? $parts : [];
    $parts = array_values(array_unique(array_filter($parts, static function ($value) {
        return $value !== '';
    })));
    return $parts;
}

function koto_mcp_normalize_scope($scope)
{
    $allowed = ['validate', 'preview', 'create'];
    $requested = koto_mcp_scope_string_to_list((string) $scope);
    if (empty($requested)) {
        $requested = ['validate', 'preview'];
    }
    foreach ($requested as $token) {
        if (!in_array($token, $allowed, true)) {
            return new WP_Error('invalid_scope', 'Requested OAuth scope is not supported.', ['status' => 400]);
        }
    }
    return implode(' ', $requested);
}

function koto_mcp_has_scope($scope_string, $scope)
{
    return in_array($scope, koto_mcp_scope_string_to_list($scope_string), true);
}

function koto_mcp_www_authenticate_header($error = 'invalid_token', $description = 'OAuth access token is required.')
{
    $safe_error = preg_replace('/[^a-z_]/', '', (string) $error);
    $safe_description = str_replace('"', '', (string) $description);
    $format = 'Bea' . 'rer realm="kotodaman-mcp", error="%s", error_description="%s", resource_metadata="%s"';
    return sprintf(
        $format,
        $safe_error ?: 'invalid_token',
        $safe_description,
        esc_url_raw(koto_mcp_protected_resource_metadata_url())
    );
}

function koto_mcp_tool_names()
{
    return array_map(static function ($tool) {
        return $tool['name'] ?? '';
    }, koto_mcp_tools());
}

function koto_mcp_is_object_like_array($value)
{
    if (!is_array($value)) return false;
    if (empty($value)) return true;
    return array_keys($value) !== range(0, count($value) - 1);
}

function koto_mcp_validate_tool_arguments($name, $arguments)
{
    if (!is_array($arguments)) {
        return new WP_Error('INVALID_PARAMS', 'Tool arguments must be an object.', ['field' => 'arguments', 'reason' => 'invalid_type', 'status' => 400]);
    }

    $validators = [
        'get_terms' => ['taxonomy' => 'string'],
        'search_character' => ['name' => 'string'],
    ];
    if (isset($validators[$name])) {
        foreach ($validators[$name] as $key => $expected_type) {
            if (!array_key_exists($key, $arguments)) {
                return new WP_Error('INVALID_PARAMS', sprintf('%s is required.', $key), ['field' => $key, 'reason' => 'required_empty', 'status' => 400]);
            }
            if ($expected_type === 'string' && !is_string($arguments[$key])) {
                return new WP_Error('INVALID_PARAMS', sprintf('%s must be a string.', $key), ['field' => $key, 'reason' => 'invalid_type', 'status' => 400]);
            }
        }
    }

    if (in_array($name, ['validate', 'preview_draft', 'create_draft', 'update_draft'], true)) {
        if (array_key_exists('character_name', $arguments) && !is_string($arguments['character_name'])) {
            return new WP_Error('INVALID_PARAMS', 'character_name must be a string.', ['field' => 'character_name', 'reason' => 'invalid_type', 'status' => 400]);
        }
        if (array_key_exists('basic_fields', $arguments) && !koto_mcp_is_object_like_array($arguments['basic_fields'])) {
            return new WP_Error('INVALID_PARAMS', 'basic_fields must be an object.', ['field' => 'basic_fields', 'reason' => 'invalid_type', 'status' => 400]);
        }
        if (array_key_exists('auto_input', $arguments) && !koto_mcp_is_object_like_array($arguments['auto_input'])) {
            return new WP_Error('INVALID_PARAMS', 'auto_input must be an object.', ['field' => 'auto_input', 'reason' => 'invalid_type', 'status' => 400]);
        }
        if (array_key_exists('screenshot_urls', $arguments)) {
            if (!is_array($arguments['screenshot_urls'])) {
                return new WP_Error('INVALID_PARAMS', 'screenshot_urls must be an array.', ['field' => 'screenshot_urls', 'reason' => 'invalid_type', 'status' => 400]);
            }
            foreach ($arguments['screenshot_urls'] as $index => $url) {
                if (!is_string($url)) {
                    return new WP_Error('INVALID_PARAMS', 'screenshot_urls items must be strings.', ['field' => 'screenshot_urls.' . $index, 'reason' => 'invalid_type', 'status' => 400]);
                }
            }
        }
    }

    if (in_array($name, ['create_draft', 'update_draft'], true)) {
        if (!array_key_exists('idempotency_key', $arguments) || !is_string($arguments['idempotency_key']) || trim($arguments['idempotency_key']) === '') {
            return new WP_Error('INVALID_PARAMS', 'idempotency_key is required.', ['field' => 'idempotency_key', 'reason' => 'required_empty', 'status' => 400]);
        }
        if (!array_key_exists('preview_hash', $arguments) || !is_string($arguments['preview_hash']) || trim($arguments['preview_hash']) === '') {
            return new WP_Error('INVALID_PARAMS', 'preview_hash is required.', ['field' => 'preview_hash', 'reason' => 'required_empty', 'status' => 400]);
        }
    }
    if ($name === 'update_draft') {
        if (!array_key_exists('post_id', $arguments) || (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }
    if ($name === 'trash_draft') {
        if (!array_key_exists('post_id', $arguments) || (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }
    return true;
}

function koto_mcp_idempotency_option_name($user_id, $key)
{
    return 'koto_mcp_idem_' . hash('sha256', $user_id . '|' . $key);
}

function koto_mcp_reserve_idempotency_key($user_id, $key)
{
    $option = koto_mcp_idempotency_option_name($user_id, $key);
    $now = time();
    $reservation = ['state' => 'processing', 'updated_at' => $now, 'expires_at' => $now + DAY_IN_SECONDS];

    if (add_option($option, $reservation, '', false)) {
        return ['option' => $option];
    }

    $existing = get_option($option);
    if (is_array($existing) && !empty($existing['expires_at']) && (int) $existing['expires_at'] < $now) {
        delete_option($option);
        if (add_option($option, $reservation, '', false)) {
            return ['option' => $option];
        }
        $existing = get_option($option);
    }

    if (is_array($existing) && ($existing['state'] ?? '') === 'done' && array_key_exists('result', $existing)) {
        return ['result' => $existing['result']];
    }

    return new WP_Error('IDEMPOTENCY_IN_PROGRESS', 'An identical request is already processing.', ['field' => 'idempotency_key', 'reason' => 'conflict', 'status' => 409]);
}

function koto_mcp_complete_idempotency_key($option, $result)
{
    update_option($option, ['state' => 'done', 'updated_at' => time(), 'expires_at' => time() + DAY_IN_SECONDS, 'result' => $result], false);
}

function koto_mcp_release_idempotency_key($option)
{
    delete_option($option);
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
        'callback' => 'koto_mcp_oauth_authorize_rest',
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
        'authorization_servers' => [koto_mcp_issuer_url()],
        'scopes_supported' => ['validate', 'preview', 'create'],
        'bearer_methods_supported' => ['header'],
    ]);
}

add_action('init', function () {
    add_rewrite_rule('^\\.well-known/oauth-protected-resource/?$', 'index.php?koto_mcp_meta=protected', 'top');
    add_rewrite_rule('^\\.well-known/oauth-authorization-server/?$', 'index.php?koto_mcp_meta=authorization', 'top');
    add_rewrite_rule('^mcp/oauth/authorize/?$', 'index.php?koto_mcp_oauth_authorize=1', 'top');
    add_rewrite_tag('%koto_mcp_meta%', '([^&]+)');
    add_rewrite_tag('%koto_mcp_oauth_authorize%', '([0-1])');
});
add_filter('query_vars', function ($vars) {
    $vars[] = 'koto_mcp_meta';
    $vars[] = 'koto_mcp_oauth_authorize';
    return $vars;
});
add_action('template_redirect', function () {
    if ((string) get_query_var('koto_mcp_oauth_authorize') === '1') {
        koto_mcp_oauth_authorize_browser();
        exit;
    }
    $meta = get_query_var('koto_mcp_meta');
    if (!$meta) return;
    $base = koto_mcp_base_url();
    $issuer = koto_mcp_issuer_url();
    $response = $meta === 'protected'
        ? [
            'resource' => koto_mcp_server_url(),
            'authorization_servers' => [$issuer],
            'scopes_supported' => ['validate', 'preview', 'create'],
            'bearer_methods_supported' => ['header'],
        ]
        : [
            'issuer' => $issuer,
            'authorization_endpoint' => koto_mcp_authorization_endpoint_url(),
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

function koto_mcp_get_authorization_header($request = null)
{
    if ($request instanceof WP_REST_Request) {
        $header = $request->get_header('authorization');
        if ($header) return $header;
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!empty($headers['Authorization'])) return $headers['Authorization'];
        if (!empty($headers['authorization'])) return $headers['authorization'];
    }
    return '';
}

function koto_mcp_current_user_from_token($request = null)
{
    $header = koto_mcp_get_authorization_header($request);
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) return 0;
    $token = hash('sha256', trim($match[1]));
    $data = get_transient('koto_mcp_token_' . $token);
    if (!is_array($data) || empty($data['user_id'])) return 0;
    if (!empty($data['scope']) && !is_user_logged_in()) {
        wp_set_current_user((int) $data['user_id']);
    }
    return (int) $data['user_id'];
}

function koto_mcp_require_scope($scope, $request = null)
{
    $header = koto_mcp_get_authorization_header($request);
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }
    $token = trim((string) ($match[1] ?? ''));
    if ($token === '') {
        return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }

    $data = get_transient('koto_mcp_token_' . hash('sha256', $token));
    if (!is_array($data) || empty($data['user_id'])) {
        return new WP_Error('mcp_unauthorized', 'OAuth access token is invalid or expired.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }

    $user_id = (int) $data['user_id'];
    if (!is_user_logged_in()) {
        wp_set_current_user($user_id);
    }

    if (!koto_mcp_has_scope((string) ($data['scope'] ?? ''), $scope)) {
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

function koto_mcp_jsonrpc_error_response($id, $code, $message, $data = null, $status = 200, $headers = [])
{
    $payload = [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];
    if ($data !== null) {
        $payload['error']['data'] = $data;
    }
    $response = new WP_REST_Response($payload, (int) $status);
    foreach ($headers as $key => $value) {
        $response->header($key, $value);
    }
    return $response;
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
    if (!is_string($name) || $name === '' || !in_array($name, koto_mcp_tool_names(), true)) {
        return koto_mcp_jsonrpc_error_response($id, -32602, 'Invalid tool name.');
    }
    $validation = koto_mcp_validate_tool_arguments($name, $arguments);
    if (is_wp_error($validation)) {
        return koto_mcp_jsonrpc_error_response($id, -32602, $validation->get_error_message(), [
            'code' => $validation->get_error_code(),
            'detail' => $validation->get_error_data(),
        ]);
    }
    $tool = koto_mcp_call_tool($name, $arguments, $request);
    if (is_wp_error($tool)) {
        $detail = $tool->get_error_data();
        $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 200;
        $headers = [];
        if ($status === 401) {
            $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
                is_array($detail) ? ($detail['oauth_error'] ?? 'invalid_token') : 'invalid_token',
                $tool->get_error_message()
            );
        }
        return koto_mcp_jsonrpc_error_response($id, -32000, $tool->get_error_message(), [
            'code' => $tool->get_error_code(),
            'detail' => $detail,
        ], $status, $headers);
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
    $fields = is_array($args['basic_fields'] ?? null) ? $args['basic_fields'] : [];
    $schema = koto_mcp_field_schema();
    foreach (['attribute', 'species', 'rarity'] as $required) {
        $is_empty_array = is_array($fields[$required] ?? null) && count($fields[$required]) === 0;
        if (!array_key_exists($required, $fields) || $fields[$required] === null || $fields[$required] === '' || $is_empty_array) {
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
            if (is_array($value) && count($value) === 0) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'required_empty');
                continue;
            }
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
            continue;
        }

        if (!empty($definition['choices']) && is_array($definition['choices'])) {
            $values = is_array($value) ? $value : [$value];
            if (count($values) === 0) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'required_empty');
                continue;
            }
            foreach ($values as $choice) {
                if (!is_scalar($choice) || !array_key_exists((string) $choice, $definition['choices'])) {
                    $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_choice');
                }
            }
        }

        if ($definition['type'] === 'number' || $definition['type'] === 'range') {
            if (!is_numeric($value)) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_number');
                continue;
            }
            $number = (float) $value;
            if ($definition['min'] !== null && is_numeric($definition['min']) && $number < (float) $definition['min']) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'below_min');
            }
            if ($definition['max'] !== null && is_numeric($definition['max']) && $number > (float) $definition['max']) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'above_max');
            }
            continue;
        }

        if ($definition['type'] === 'true_false') {
            $valid_bool = is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
            if (!$valid_bool) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_boolean');
            }
            continue;
        }

        if (in_array($definition['type'], ['text', 'textarea', 'url', 'email', 'select', 'radio'], true) && is_array($value)) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
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

function koto_mcp_call_tool($name, $args, $request = null)
{
    if (!in_array($name, koto_mcp_tool_names(), true)) {
        return new WP_Error('INVALID_TOOL', 'Unknown tool name.', ['field' => 'name', 'reason' => 'invalid_value', 'status' => 400]);
    }
    $scope = in_array($name, ['get_field_schema', 'get_terms', 'search_character', 'validate'], true) ? 'validate' : 'preview';
    if (in_array($name, ['create_draft', 'update_draft', 'trash_draft'], true)) $scope = 'create';
    $auth = koto_mcp_require_scope($scope, $request);
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
        $matches = [];
        foreach ($query->posts as $post) {
            if ($post->post_status === 'private') {
                if (!user_can($auth, 'read_post', $post->ID)) continue;
            } elseif ($post->post_status !== 'publish') {
                if (!user_can($auth, 'edit_post', $post->ID)) continue;
            }
            $matches[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status];
        }
        return ['matches' => $matches];
    }
    if ($name === 'trash_draft') {
        $post = get_post((int) ($args['post_id'] ?? 0));
        if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft') return new WP_Error('VALIDATION_FAILED', 'Only character drafts can be trashed.', ['field' => 'post_id', 'reason' => 'invalid_value']);
        if (!user_can($auth, 'delete_post', $post->ID)) return new WP_Error('VALIDATION_FAILED', 'The draft cannot be deleted by this user.', ['field' => 'post_id', 'reason' => 'invalid_value']);
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
    $reservation = koto_mcp_reserve_idempotency_key($auth, $key);
    if (is_wp_error($reservation)) return $reservation;
    if (!empty($reservation['result'])) return $reservation['result'];
    $idempotency_option = $reservation['option'];
    if ($name === 'create_draft') {
        $post_id = koto_create_character_post_from_acf(sanitize_text_field($args['character_name']), []);
        if (!is_wp_error($post_id) && $post_id) {
            koto_mcp_update_basic_fields($post_id, $args['basic_fields'] ?? []);
            koto_update_character_post_with_acf($post_id, $validation['parsed_acf']);
        }
    } else {
        $post_id = (int) $args['post_id'];
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft' || !user_can($auth, 'edit_post', $post_id)) {
            koto_mcp_release_idempotency_key($idempotency_option);
            return new WP_Error('VALIDATION_FAILED', 'Only editable character drafts can be updated.', ['field' => 'post_id', 'reason' => 'invalid_value']);
        }
        koto_mcp_update_basic_fields($post_id, $args['basic_fields'] ?? []);
        koto_update_character_post_with_acf($post_id, $validation['parsed_acf'], true);
    }
    if (is_wp_error($post_id) || !$post_id) {
        koto_mcp_release_idempotency_key($idempotency_option);
        return new WP_Error('CREATE_FAILED', 'Draft creation failed.', ['field' => 'post', 'reason' => 'invalid_value']);
    }
    if ($name === 'create_draft') {
        $urls = !empty($args['screenshot_urls']) ? $args['screenshot_urls'] : [KOTO_MCP_DEFAULT_IMAGE_URL];
        $urls = array_values(array_filter(array_map('esc_url_raw', $urls)));
        update_post_meta($post_id, '_koto_mcp_screenshot_urls', $urls);
        koto_mcp_set_featured_image_from_url($post_id, $urls[0] ?? '');
    } elseif (array_key_exists('screenshot_urls', $args)) {
        $urls = array_values(array_filter(array_map('esc_url_raw', (array) $args['screenshot_urls'])));
        update_post_meta($post_id, '_koto_mcp_screenshot_urls', $urls);
        if (!empty($urls)) {
            koto_mcp_set_featured_image_from_url($post_id, $urls[0]);
        }
    }
    update_post_meta($post_id, '_koto_mcp_generated', 1);
    update_post_meta($post_id, '_koto_mcp_needs_review', 1);
    update_post_meta($post_id, '_koto_mcp_audit', ['user_id' => $auth, 'content_hash' => $hash, 'evidence' => $args['evidence'] ?? [], 'created_at' => current_time('mysql')]);
    do_action('acf/save_post', $post_id);
    $result = ['post_id' => $post_id, 'status' => 'draft', 'edit_url' => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id), 'content_hash' => $hash, 'screenshot_urls' => get_post_meta($post_id, '_koto_mcp_screenshot_urls', true)];
    koto_mcp_complete_idempotency_key($idempotency_option, $result);
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

function koto_mcp_oauth_registered_redirect_uris($client)
{
    $uris = [];
    foreach ((array) ($client['redirect_uris'] ?? []) as $uri) {
        if (!is_string($uri)) continue;
        $normalized = esc_url_raw(trim($uri));
        if ($normalized !== '') {
            $uris[] = $normalized;
        }
    }
    return array_values(array_unique($uris));
}

function koto_mcp_oauth_resolve_redirect_uri($submitted, $registered_uris)
{
    $submitted = is_string($submitted) ? esc_url_raw(trim($submitted)) : '';
    if ($submitted === '' && count($registered_uris) === 1) {
        return $registered_uris[0];
    }
    if ($submitted === '' || !in_array($submitted, $registered_uris, true)) {
        return new WP_Error('invalid_request', 'redirect_uri must exactly match a registered redirect URI.', ['status' => 400]);
    }
    return $submitted;
}

function koto_mcp_oauth_redirect_error($redirect, $error, $description, $state = '')
{
    $params = [
        'error' => sanitize_key($error),
        'error_description' => sanitize_text_field($description),
    ];
    if ($state !== '') {
        $params['state'] = sanitize_text_field($state);
    }
    wp_safe_redirect(add_query_arg($params, $redirect));
    exit;
}

function koto_mcp_oauth_register_client($request)
{
    $body = json_decode($request->get_body(), true) ?: [];
    $redirect_uris = [];
    foreach ((array) ($body['redirect_uris'] ?? []) as $uri) {
        if (!is_string($uri)) continue;
        $normalized = esc_url_raw(trim($uri));
        if ($normalized !== '') {
            $redirect_uris[] = $normalized;
        }
    }
    $redirect_uris = array_values(array_unique($redirect_uris));
    if (empty($redirect_uris)) {
        return new WP_Error('invalid_client_metadata', 'At least one valid redirect_uris entry is required.', ['status' => 400]);
    }

    $id = 'koto_' . wp_generate_password(24, false, false);
    $client_name = sanitize_text_field($body['client_name'] ?? 'MCP client');
    set_transient('koto_mcp_client_' . $id, ['redirect_uris' => $redirect_uris, 'name' => $client_name], YEAR_IN_SECONDS);
    return rest_ensure_response(['client_id' => $id, 'client_name' => $client_name, 'redirect_uris' => $redirect_uris, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']);
}

function koto_mcp_oauth_authorize_rest($request)
{
    return new WP_Error('invalid_request', 'Use the browser authorization endpoint at /mcp/oauth/authorize.', ['status' => 400]);
}

function koto_mcp_oauth_authorize_browser()
{
    if (!is_user_logged_in()) {
        auth_redirect();
        return;
    }

    $params = wp_unslash($_REQUEST);
    $client_id = sanitize_text_field($params['client_id'] ?? '');
    $client = get_transient('koto_mcp_client_' . $client_id);
    if (!is_array($client)) {
        wp_die('Unknown OAuth client.', 'invalid_client', ['response' => 400]);
    }

    $registered_uris = koto_mcp_oauth_registered_redirect_uris($client);
    $redirect = koto_mcp_oauth_resolve_redirect_uri($params['redirect_uri'] ?? '', $registered_uris);
    $state = sanitize_text_field($params['state'] ?? '');
    if (is_wp_error($redirect)) {
        wp_die($redirect->get_error_message(), 'invalid_request', ['response' => 400]);
    }

    $response_type = sanitize_text_field($params['response_type'] ?? 'code');
    if ($response_type !== 'code') {
        koto_mcp_oauth_redirect_error($redirect, 'unsupported_response_type', 'Only response_type=code is supported.', $state);
    }

    $scope = koto_mcp_normalize_scope($params['scope'] ?? '');
    if (is_wp_error($scope)) {
        koto_mcp_oauth_redirect_error($redirect, 'invalid_scope', $scope->get_error_message(), $state);
    }

    $challenge = sanitize_text_field($params['code_challenge'] ?? '');
    $challenge_method = sanitize_text_field($params['code_challenge_method'] ?? 'S256');
    if ($challenge === '' || $challenge_method !== 'S256') {
        koto_mcp_oauth_redirect_error($redirect, 'invalid_request', 'code_challenge and code_challenge_method=S256 are required.', $state);
    }

    $user = wp_get_current_user();
    $privileged = array_intersect(['administrator', 'editor'], (array) $user->roles);
    if (!$privileged && !get_user_meta(get_current_user_id(), '_koto_mcp_approved', true)) {
        update_user_meta(get_current_user_id(), '_koto_mcp_requested', 1);
        wp_die('MCP access approval is required.', 'approval_required', ['response' => 403]);
    }

    if (koto_mcp_has_scope($scope, 'create')) {
        $nonce_action = 'koto_mcp_oauth_consent|' . get_current_user_id() . '|' . $client_id . '|' . $redirect . '|' . $scope;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            status_header(200);
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            echo '<!doctype html><html><head><meta charset="utf-8"><title>MCP Consent</title></head><body>';
            echo '<h1>MCP クライアント同意</h1>';
            echo '<p>クライアント: ' . esc_html((string) ($client['name'] ?? $client_id)) . '</p>';
            echo '<p>要求スコープ: ' . esc_html($scope) . '</p>';
            echo '<form method="post">';
            foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state'] as $field) {
                if (isset($params[$field])) {
                    echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr((string) $params[$field]) . '">';
                }
            }
            wp_nonce_field($nonce_action);
            echo '<button type="submit" name="koto_mcp_consent_action" value="approve">許可</button> ';
            echo '<button type="submit" name="koto_mcp_consent_action" value="deny">拒否</button>';
            echo '</form></body></html>';
            exit;
        }
        if (!wp_verify_nonce((string) ($params['_wpnonce'] ?? ''), $nonce_action)) {
            wp_die('Invalid consent nonce.', 'invalid_request', ['response' => 400]);
        }
        if (($params['koto_mcp_consent_action'] ?? '') !== 'approve') {
            koto_mcp_oauth_redirect_error($redirect, 'access_denied', 'Resource owner denied the request.', $state);
        }
    }

    $code = wp_generate_password(48, false, false);
    set_transient('koto_mcp_code_' . hash('sha256', $code), [
        'user_id' => get_current_user_id(),
        'client_id' => $client_id,
        'redirect_uri' => $redirect,
        'challenge' => $challenge,
        'scope' => $scope,
        'family_id' => wp_generate_password(32, false, false),
    ], 300);
    wp_safe_redirect(add_query_arg(['code' => $code, 'state' => $state], $redirect));
    exit;
}

function koto_mcp_oauth_body_params($request)
{
    $body = $request->get_body_params();
    $json = $request->get_json_params();
    if (!is_array($body)) $body = [];
    if (!is_array($json)) $json = [];
    return array_merge($body, $json);
}

function koto_mcp_refresh_record_option_name($token_hash)
{
    return 'koto_mcp_refresh_' . $token_hash;
}

function koto_mcp_refresh_family_option_name($family_id)
{
    return 'koto_mcp_refresh_family_' . hash('sha256', (string) $family_id);
}

function koto_mcp_refresh_lock_option_name($token_hash)
{
    return 'koto_mcp_refresh_lock_' . $token_hash;
}

function koto_mcp_revoke_refresh_family($family_id)
{
    if (!$family_id) return;
    $family_option = koto_mcp_refresh_family_option_name($family_id);
    $family = get_option($family_option);
    if (!is_array($family)) return;
    $tokens = is_array($family['tokens'] ?? null) ? $family['tokens'] : [];
    foreach (array_keys($tokens) as $token_hash) {
        $record_option = koto_mcp_refresh_record_option_name((string) $token_hash);
        $record = get_option($record_option);
        if (is_array($record)) {
            $record['status'] = 'revoked';
            $record['revoked_at'] = time();
            update_option($record_option, $record, false);
        }
    }
    $family['status'] = 'revoked';
    $family['updated_at'] = time();
    update_option($family_option, $family, false);
}

function koto_mcp_issue_token($user_id, $scope, $client_id, $family_id = null)
{
    $access = wp_generate_password(64, false, false);
    $refresh = wp_generate_password(72, false, false);
    $refresh_hash = hash('sha256', $refresh);
    $family_id = $family_id ?: wp_generate_password(32, false, false);

    set_transient('koto_mcp_token_' . hash('sha256', $access), ['user_id' => (int) $user_id, 'scope' => $scope, 'client_id' => $client_id, 'family_id' => $family_id], HOUR_IN_SECONDS);
    update_option(koto_mcp_refresh_record_option_name($refresh_hash), [
        'user_id' => (int) $user_id,
        'scope' => $scope,
        'client_id' => $client_id,
        'family_id' => $family_id,
        'status' => 'active',
        'issued_at' => time(),
        'expires_at' => time() + (30 * DAY_IN_SECONDS),
    ], false);

    $family_option = koto_mcp_refresh_family_option_name($family_id);
    $family = get_option($family_option);
    if (!is_array($family)) {
        $family = ['status' => 'active', 'tokens' => [], 'updated_at' => time()];
    }
    if (($family['status'] ?? 'active') !== 'active') {
        return new WP_Error('invalid_grant', 'The token family is revoked.', ['status' => 400]);
    }
    $family['tokens'][$refresh_hash] = 1;
    $family['updated_at'] = time();
    update_option($family_option, $family, false);

    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => HOUR_IN_SECONDS, 'refresh_token' => $refresh, 'scope' => $scope];
}

function koto_mcp_oauth_token($request)
{
    $body = koto_mcp_oauth_body_params($request);
    $grant_type = sanitize_text_field((string) ($body['grant_type'] ?? ''));

    if ($grant_type === 'refresh_token') {
        $refresh_token = (string) ($body['refresh_token'] ?? '');
        $client_id = sanitize_text_field((string) ($body['client_id'] ?? ''));
        if ($refresh_token === '' || $client_id === '') {
            return new WP_Error('invalid_request', 'refresh_token and client_id are required.', ['status' => 400]);
        }

        $refresh_hash = hash('sha256', $refresh_token);
        $lock_option = koto_mcp_refresh_lock_option_name($refresh_hash);
        if (!add_option($lock_option, ['created_at' => time()], '', false)) {
            $lock = get_option($lock_option);
            if (is_array($lock) && !empty($lock['created_at']) && ((int) $lock['created_at'] + 30) < time()) {
                delete_option($lock_option);
            }
            if (!add_option($lock_option, ['created_at' => time()], '', false)) {
                return new WP_Error('temporarily_unavailable', 'Refresh token is currently locked.', ['status' => 503]);
            }
        }

        try {
            $record_option = koto_mcp_refresh_record_option_name($refresh_hash);
            $refresh = get_option($record_option);
            if (!is_array($refresh)) {
                return new WP_Error('invalid_grant', 'Invalid refresh token.', ['status' => 400]);
            }
            if (($refresh['client_id'] ?? '') !== $client_id) {
                return new WP_Error('invalid_grant', 'Refresh token client binding mismatch.', ['status' => 400]);
            }
            if (!empty($refresh['expires_at']) && (int) $refresh['expires_at'] < time()) {
                return new WP_Error('invalid_grant', 'Refresh token expired.', ['status' => 400]);
            }
            $family_id = (string) ($refresh['family_id'] ?? '');
            $family = get_option(koto_mcp_refresh_family_option_name($family_id));
            if (!is_array($family) || ($family['status'] ?? '') !== 'active') {
                return new WP_Error('invalid_grant', 'Refresh token family is revoked.', ['status' => 400]);
            }
            if (($refresh['status'] ?? '') !== 'active') {
                koto_mcp_revoke_refresh_family($family_id);
                return new WP_Error('invalid_grant', 'Refresh token replay detected; token family revoked.', ['status' => 400]);
            }

            $token_response = koto_mcp_issue_token((int) $refresh['user_id'], (string) $refresh['scope'], $client_id, $family_id);
            if (is_wp_error($token_response)) {
                return $token_response;
            }
            $refresh['status'] = 'rotated';
            $refresh['rotated_at'] = time();
            $refresh['rotated_to'] = hash('sha256', (string) $token_response['refresh_token']);
            update_option($record_option, $refresh, false);
            return $token_response;
        } finally {
            delete_option($lock_option);
        }
    }

    if ($grant_type !== 'authorization_code') {
        return new WP_Error('unsupported_grant_type', 'Only authorization_code and refresh_token grants are supported.', ['status' => 400]);
    }

    $code = (string) ($body['code'] ?? '');
    $client_id = sanitize_text_field((string) ($body['client_id'] ?? ''));
    $redirect_uri = esc_url_raw((string) ($body['redirect_uri'] ?? ''));
    if ($code === '' || $client_id === '' || $redirect_uri === '') {
        return new WP_Error('invalid_request', 'code, client_id, and redirect_uri are required.', ['status' => 400]);
    }

    $data = get_transient('koto_mcp_code_' . hash('sha256', $code));
    if (!is_array($data)) return new WP_Error('invalid_grant', 'Invalid authorization code.', ['status' => 400]);
    if (($data['client_id'] ?? '') !== $client_id || ($data['redirect_uri'] ?? '') !== $redirect_uri) {
        return new WP_Error('invalid_grant', 'Authorization code client/redirect binding mismatch.', ['status' => 400]);
    }

    $verifier = (string) ($body['code_verifier'] ?? '');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    if (empty($data['challenge']) || !$verifier || !hash_equals((string) $data['challenge'], $challenge)) {
        return new WP_Error('invalid_grant', 'PKCE verification failed.', ['status' => 400]);
    }
    delete_transient('koto_mcp_code_' . hash('sha256', $code));
    return koto_mcp_issue_token((int) $data['user_id'], (string) $data['scope'], $client_id, (string) ($data['family_id'] ?? ''));
}

function koto_mcp_oauth_revoke($request)
{
    $params = koto_mcp_oauth_body_params($request);
    $token = (string) ($params['token'] ?? '');
    if ($token === '') {
        return ['revoked' => true];
    }

    $token_hash = hash('sha256', $token);
    delete_transient('koto_mcp_token_' . $token_hash);

    $refresh_option = koto_mcp_refresh_record_option_name($token_hash);
    $refresh = get_option($refresh_option);
    if (is_array($refresh) && !empty($refresh['family_id'])) {
        koto_mcp_revoke_refresh_family((string) $refresh['family_id']);
    }
    delete_option($refresh_option);
    delete_transient('koto_mcp_refresh_' . $token_hash);
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
