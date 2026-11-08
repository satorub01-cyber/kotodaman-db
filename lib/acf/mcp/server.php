<?php
if (!defined('ABSPATH')) exit;

const KOTO_MCP_PROTOCOL_VERSION = '2025-03-26';
const KOTO_MCP_DEFAULT_IMAGE_URL = 'https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png';
const KOTO_MCP_MAX_BODY_BYTES = 262144;
const KOTO_MCP_MAX_NAME_CHARS = 200;
const KOTO_MCP_MAX_AUTO_INPUT_CHARS = 4000;
const KOTO_MCP_MAX_BASIC_FIELD_CHARS = 2000;
const KOTO_MCP_MAX_EVIDENCE_BYTES = 16384;
if (!defined('KOTO_MCP_TEST_MODE')) {
    define('KOTO_MCP_TEST_MODE', false);
}

/**
 * 保護対象の雛型・特殊記事ID一覧を返す。
 *
 * @return array<int, int>
 */
function koto_mcp_template_post_ids()
{
    return [2947, 2023, 2637, 2638, 3845, 4603, 5294, 3683];
}

/**
 * 指定された投稿（または投稿ID）が保護対象の雛型・テンプレート記事かどうか判定する。
 *
 * @param int|WP_Post|null $post 投稿IDまたはWP_Postオブジェクト。
 * @return bool 保護対象の場合 true。
 */
function koto_mcp_is_protected_template_post($post)
{
    $id = 0;
    if (is_numeric($post)) {
        $id = (int) $post;
    } elseif ($post instanceof WP_Post) {
        $id = (int) $post->ID;
    } elseif (is_object($post) && isset($post->ID)) {
        $id = (int) $post->ID;
    }
    if ($id > 0 && in_array($id, koto_mcp_template_post_ids(), true)) {
        return true;
    }

    if (is_numeric($post)) {
        $post = get_post((int) $post);
    }
    if ($post instanceof WP_Post || (is_object($post) && isset($post->post_title))) {
        $title = (string) $post->post_title;
        if (mb_strpos($title, '雛型', 0, 'UTF-8') !== false || mb_strpos($title, '雛形', 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * MCPのテスト（詳細出力）モード判定。初期値は false。
 *
 * @param array<string, mixed> $args ツール呼び出し引数。
 * @return bool テストモードが有効な場合 true。
 */
function koto_mcp_is_test_mode($args = [])
{
    if (is_array($args) && array_key_exists('test_mode', $args)) {
        return (bool) $args['test_mode'];
    }
    if (!empty($GLOBALS['koto_auto_input_test_mode'])) {
        return true;
    }
    if (defined('KOTO_MCP_TEST_MODE') && KOTO_MCP_TEST_MODE) {
        return true;
    }
    $env = getenv('KOTO_MCP_TEST_MODE');
    return $env === '1' || strtolower((string) $env) === 'true';
}

function koto_mcp_preview_cache_key($user_id, $hash)
{
    return 'koto_mcp_prev_' . (int) $user_id . '_' . substr(preg_replace('/[^a-f0-9]/', '', (string) $hash), 0, 40);
}

add_action('rest_api_init', 'koto_mcp_register_server_routes');
add_action('admin_menu', 'koto_mcp_register_approval_menu');
add_action('admin_init', 'koto_mcp_handle_approval_action');
add_action('init', function () {
    $role = get_role('mcp_user');
    if (!$role) {
        $role = add_role('mcp_user', 'MCP利用者', ['read' => true]);
    }
    if ($role) {
        foreach (['read', 'edit_posts', 'upload_files'] as $capability) {
            if (!$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }
});

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

    if (array_key_exists('test_mode', $arguments) && !is_bool($arguments['test_mode'])) {
        return new WP_Error('INVALID_PARAMS', 'test_mode must be a boolean.', ['field' => 'test_mode', 'reason' => 'invalid_type', 'status' => 400]);
    }
    if ($name === 'get_terms' && array_key_exists('search', $arguments) && !is_string($arguments['search'])) {
        return new WP_Error('INVALID_PARAMS', 'search must be a string.', ['field' => 'search', 'reason' => 'invalid_type', 'status' => 400]);
    }
    if ($name === 'get_terms' && isset($arguments['search']) && mb_strlen((string) $arguments['search'], 'UTF-8') > 200) {
        return new WP_Error('INVALID_PARAMS', 'search exceeds maximum length.', ['field' => 'search', 'reason' => 'above_max', 'status' => 400]);
    }
    if ($name === 'search_character' && isset($arguments['name']) && mb_strlen((string) $arguments['name'], 'UTF-8') > KOTO_MCP_MAX_NAME_CHARS) {
        return new WP_Error('INVALID_PARAMS', 'name exceeds maximum length.', ['field' => 'name', 'reason' => 'above_max', 'status' => 400]);
    }
    if ($name === 'get_draft') {
        if (!array_key_exists('post_id', $arguments) || (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }

    if (in_array($name, ['validate', 'preview_draft', 'create_draft', 'update_draft'], true)) {
        if (array_key_exists('post_id', $arguments) && $arguments['post_id'] !== null && (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
        if (array_key_exists('character_name', $arguments)) {
            if (!is_string($arguments['character_name'])) {
                return new WP_Error('INVALID_PARAMS', 'character_name must be a string.', ['field' => 'character_name', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (mb_strlen($arguments['character_name'], 'UTF-8') > KOTO_MCP_MAX_NAME_CHARS) {
                return new WP_Error('INVALID_PARAMS', 'character_name exceeds maximum length (200 chars).', ['field' => 'character_name', 'reason' => 'above_max', 'status' => 400]);
            }
        }
        if (in_array($name, ['validate', 'preview_draft'], true) && empty($arguments['post_id'])) {
            if (!array_key_exists('character_name', $arguments) || trim((string) $arguments['character_name']) === '') {
                return new WP_Error('INVALID_PARAMS', 'character_name is required.', ['field' => 'character_name', 'reason' => 'required_empty', 'status' => 400]);
            }
        }
        if (array_key_exists('basic_fields', $arguments)) {
            if (!koto_mcp_is_object_like_array($arguments['basic_fields'])) {
                return new WP_Error('INVALID_PARAMS', 'basic_fields must be an object.', ['field' => 'basic_fields', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (count($arguments['basic_fields']) > 60) {
                return new WP_Error('INVALID_PARAMS', 'Too many basic_fields entries.', ['field' => 'basic_fields', 'reason' => 'above_max', 'status' => 400]);
            }
            foreach ($arguments['basic_fields'] as $bf_key => $bf_val) {
                if (is_string($bf_val) && mb_strlen($bf_val, 'UTF-8') > KOTO_MCP_MAX_BASIC_FIELD_CHARS) {
                    return new WP_Error('INVALID_PARAMS', sprintf('basic_fields.%s exceeds maximum length.', $bf_key), ['field' => 'basic_fields.' . $bf_key, 'reason' => 'above_max', 'status' => 400]);
                }
                if (is_array($bf_val) && count($bf_val) > 50) {
                    return new WP_Error('INVALID_PARAMS', sprintf('basic_fields.%s has too many items.', $bf_key), ['field' => 'basic_fields.' . $bf_key, 'reason' => 'above_max', 'status' => 400]);
                }
            }
        }
        if (array_key_exists('auto_input', $arguments)) {
            if (!koto_mcp_is_object_like_array($arguments['auto_input'])) {
                return new WP_Error('INVALID_PARAMS', 'auto_input must be an object.', ['field' => 'auto_input', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (count($arguments['auto_input']) > 20) {
                return new WP_Error('INVALID_PARAMS', 'Too many auto_input entries.', ['field' => 'auto_input', 'reason' => 'above_max', 'status' => 400]);
            }
            foreach ($arguments['auto_input'] as $input_key => $input_val) {
                if (!is_string($input_key) || ($input_val !== null && !is_string($input_val))) {
                    return new WP_Error('INVALID_PARAMS', 'auto_input values must be strings.', ['field' => 'auto_input.' . $input_key, 'reason' => 'invalid_type', 'status' => 400]);
                }
                if (is_string($input_val) && mb_strlen($input_val, 'UTF-8') > KOTO_MCP_MAX_AUTO_INPUT_CHARS) {
                    return new WP_Error('INVALID_PARAMS', sprintf('auto_input.%s exceeds maximum length (4000 chars).', $input_key), ['field' => 'auto_input.' . $input_key, 'reason' => 'above_max', 'status' => 400]);
                }
            }
        }
        if (array_key_exists('screenshot_urls', $arguments)) {
            if (!is_array($arguments['screenshot_urls'])) {
                return new WP_Error('INVALID_PARAMS', 'screenshot_urls must be an array.', ['field' => 'screenshot_urls', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (count($arguments['screenshot_urls']) > 20) {
                return new WP_Error('INVALID_PARAMS', 'screenshot_urls exceeds maximum count (20).', ['field' => 'screenshot_urls', 'reason' => 'above_max', 'status' => 400]);
            }
            foreach ($arguments['screenshot_urls'] as $index => $url) {
                if (!is_string($url)) {
                    return new WP_Error('INVALID_PARAMS', 'screenshot_urls items must be strings.', ['field' => 'screenshot_urls.' . $index, 'reason' => 'invalid_type', 'status' => 400]);
                }
                if (strlen($url) > 2048) {
                    return new WP_Error('INVALID_PARAMS', 'screenshot_urls item exceeds maximum length.', ['field' => 'screenshot_urls.' . $index, 'reason' => 'above_max', 'status' => 400]);
                }
            }
        }
        if (array_key_exists('evidence', $arguments)) {
            if (!koto_mcp_is_object_like_array($arguments['evidence'])) {
                return new WP_Error('INVALID_PARAMS', 'evidence must be an object.', ['field' => 'evidence', 'reason' => 'invalid_type', 'status' => 400]);
            }
            $encoded_ev = wp_json_encode($arguments['evidence'], JSON_UNESCAPED_UNICODE);
            if (is_string($encoded_ev) && strlen($encoded_ev) > KOTO_MCP_MAX_EVIDENCE_BYTES) {
                return new WP_Error('INVALID_PARAMS', 'evidence exceeds maximum size (16KB).', ['field' => 'evidence', 'reason' => 'above_max', 'status' => 400]);
            }
        }
        if (array_key_exists('human_approved', $arguments) && !is_bool($arguments['human_approved'])) {
            return new WP_Error('INVALID_PARAMS', 'human_approved must be a boolean.', ['field' => 'human_approved', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }

    if (in_array($name, ['create_draft', 'update_draft'], true)) {
        if (!array_key_exists('idempotency_key', $arguments) || !is_string($arguments['idempotency_key']) || trim($arguments['idempotency_key']) === '' || strlen(trim($arguments['idempotency_key'])) > 255) {
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

function koto_mcp_reserve_idempotency_key($user_id, $key, $fingerprint = '')
{
    $option = koto_mcp_idempotency_option_name($user_id, $key);
    $now = time();
    $reservation = [
        'state' => 'processing',
        'fingerprint' => $fingerprint,
        'updated_at' => $now,
        'lock_expires_at' => $now + 120,
        'expires_at' => $now + DAY_IN_SECONDS,
    ];

    if (add_option($option, $reservation, '', false)) {
        return ['option' => $option];
    }

    $existing = get_option($option);
    $is_expired = is_array($existing) && !empty($existing['expires_at']) && (int) $existing['expires_at'] < $now;
    $is_stale_lock = is_array($existing) && ($existing['state'] ?? '') === 'processing' && !empty($existing['lock_expires_at']) && (int) $existing['lock_expires_at'] < $now;
    if ($is_expired || $is_stale_lock) {
        delete_option($option);
        if (add_option($option, $reservation, '', false)) {
            return ['option' => $option];
        }
        $existing = get_option($option);
    }

    if (is_array($existing) && $fingerprint !== '' && !empty($existing['fingerprint']) && $existing['fingerprint'] !== $fingerprint) {
        return new WP_Error('IDEMPOTENCY_CONFLICT', 'idempotency_key was already used with different parameters.', ['field' => 'idempotency_key', 'reason' => 'conflict', 'status' => 409]);
    }

    if (is_array($existing) && ($existing['state'] ?? '') === 'done' && array_key_exists('result', $existing)) {
        return ['result' => $existing['result']];
    }

    return new WP_Error('IDEMPOTENCY_IN_PROGRESS', 'An identical request is already processing.', ['field' => 'idempotency_key', 'reason' => 'conflict', 'status' => 409]);
}

function koto_mcp_complete_idempotency_key($option, $result, $fingerprint = '')
{
    update_option($option, ['state' => 'done', 'fingerprint' => $fingerprint, 'updated_at' => time(), 'expires_at' => time() + DAY_IN_SECONDS, 'result' => $result], false);
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

function koto_mcp_is_secure_or_loopback_transport()
{
    if (is_ssl()) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https') {
        return true;
    }
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $server_host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST'])) : '';
    $loopbacks = ['localhost', '127.0.0.1', '[::1]', '::1'];
    foreach ([$host, $server_host] as $h) {
        if ($h === '') continue;
        if (in_array($h, $loopbacks, true) || substr($h, -6) === '.local' || substr($h, -5) === '.test') {
            return true;
        }
    }
    return false;
}

function koto_mcp_check_user_mcp_permission($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return new WP_Error('mcp_unauthorized', 'Authentication is required.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }
    $user = get_userdata($user_id);
    $has_mcp_role = $user && in_array('mcp_user', (array) $user->roles, true);
    if (!$has_mcp_role && !user_can($user_id, 'edit_posts')) {
        return new WP_Error('mcp_forbidden', 'MCP利用者ロールまたはedit_posts権限が必要です。利用には管理者への申請が必要です。', ['status' => 403]);
    }
    $privileged = $has_mcp_role || ($user && array_intersect(['administrator', 'editor'], (array) $user->roles));
    if (!$privileged && !get_user_meta($user_id, '_koto_mcp_approved', true)) {
        return new WP_Error('mcp_pending_approval', 'MCP利用の承認が必要です。利用には管理者への申請が必要です。', ['status' => 403]);
    }
    return $user_id;
}

function koto_mcp_authenticate_app_password_header($header)
{
    if (!is_string($header) || trim($header) === '' || !function_exists('wp_authenticate_application_password')) {
        return 0;
    }
    if (!koto_mcp_is_secure_or_loopback_transport()) {
        return new WP_Error('mcp_insecure_transport', 'Application password authentication requires HTTPS or local loopback.', ['status' => 401, 'oauth_error' => 'invalid_request']);
    }

    $raw_cred = '';
    if (preg_match('/^Basic\s+(.+)$/i', trim($header), $m)) {
        $decoded = base64_decode(trim($m[1]), true);
        if (is_string($decoded) && strpos($decoded, ':') !== false) {
            $raw_cred = $decoded;
        }
    } elseif (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        $candidate = trim($m[1]);
        if (strpos($candidate, ':') !== false) {
            $raw_cred = $candidate;
        } else {
            $decoded = base64_decode($candidate, true);
            if (is_string($decoded) && strpos($decoded, ':') !== false && preg_match('/^[\x20-\x7E]+$/', $decoded)) {
                $raw_cred = $decoded;
            }
        }
    }

    if ($raw_cred === '' || strpos($raw_cred, ':') === false) {
        return 0;
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : 'unknown';
    $fail_key = 'koto_mcp_auth_fail_' . hash('sha256', $ip);
    $now = time();
    $fail_state = get_transient($fail_key);
    if (is_array($fail_state) && isset($fail_state['count'], $fail_state['reset_at']) && (int) $fail_state['reset_at'] > $now) {
        if ((int) $fail_state['count'] >= 10) {
            return new WP_Error('mcp_rate_limited', 'Too many failed authentication attempts. Please retry later.', ['status' => 429]);
        }
    }

    [$username, $app_pass] = explode(':', $raw_cred, 2);
    $username = trim($username);
    $app_pass = trim($app_pass);
    if ($username === '' || $app_pass === '') {
        return new WP_Error('mcp_unauthorized', 'Invalid application password credentials.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }

    $user = wp_authenticate_application_password(null, $username, $app_pass);
    if ($user instanceof WP_User && $user->ID > 0) {
        if (!is_user_logged_in()) {
            wp_set_current_user((int) $user->ID);
        }
        return (int) $user->ID;
    }

    $fail_count = (is_array($fail_state) && isset($fail_state['count'], $fail_state['reset_at']) && (int) $fail_state['reset_at'] > $now)
        ? ((int) $fail_state['count'] + 1)
        : 1;
    $reset_at = (is_array($fail_state) && isset($fail_state['reset_at']) && (int) $fail_state['reset_at'] > $now)
        ? (int) $fail_state['reset_at']
        : ($now + 5 * MINUTE_IN_SECONDS);
    set_transient($fail_key, ['count' => $fail_count, 'reset_at' => $reset_at], max(1, $reset_at - $now));

    return new WP_Error('mcp_unauthorized', 'Invalid application password.', ['status' => 401, 'oauth_error' => 'invalid_token']);
}

function koto_mcp_current_user_from_token($request = null)
{
    $header = koto_mcp_get_authorization_header($request);
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        $raw_token = trim($match[1]);
        $token_hash = hash('sha256', $raw_token);
        $data = get_transient('koto_mcp_token_' . $token_hash);
        if (is_array($data) && !empty($data['user_id'])) {
            if (!empty($data['family_id'])) {
                $family = get_option(koto_mcp_refresh_family_option_name((string) $data['family_id']));
                if (is_array($family) && ($family['status'] ?? 'active') !== 'active') {
                    return 0;
                }
            }
            if (!empty($data['scope']) && !is_user_logged_in()) {
                wp_set_current_user((int) $data['user_id']);
            }
            return (int) $data['user_id'];
        }
    }
    $app_user = koto_mcp_authenticate_app_password_header($header);
    if (is_int($app_user) && $app_user > 0) {
        return $app_user;
    }
    return 0;
}

function koto_mcp_require_scope($scope, $request = null)
{
    $header = koto_mcp_get_authorization_header($request);
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        $token = trim((string) ($match[1] ?? ''));
        if ($token === '') {
            return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', ['status' => 401, 'oauth_error' => 'invalid_token']);
        }

        $data = get_transient('koto_mcp_token_' . hash('sha256', $token));
        if (is_array($data) && !empty($data['user_id'])) {
            if (!empty($data['family_id'])) {
                $family = get_option(koto_mcp_refresh_family_option_name((string) $data['family_id']));
                if (is_array($family) && ($family['status'] ?? 'active') !== 'active') {
                    return new WP_Error('mcp_unauthorized', 'OAuth access token has been revoked.', ['status' => 401, 'oauth_error' => 'invalid_token']);
                }
            }

            $user_id = (int) $data['user_id'];
            if (!is_user_logged_in()) {
                wp_set_current_user($user_id);
            }

            if (!koto_mcp_has_scope((string) ($data['scope'] ?? ''), $scope)) {
                return new WP_Error('mcp_forbidden', 'Required OAuth scope is missing.', ['status' => 403]);
            }
            return koto_mcp_check_user_mcp_permission($user_id);
        }

        // Application Password 形式（user:app_password またはそのBase64）の Bearer 指定を検証
        $app_auth = koto_mcp_authenticate_app_password_header($header);
        if (is_wp_error($app_auth)) {
            return $app_auth;
        }
        if (is_int($app_auth) && $app_auth > 0) {
            return koto_mcp_check_user_mcp_permission($app_auth);
        }

        return new WP_Error('mcp_unauthorized', 'OAuth access token is invalid or expired.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }

    // HTTP Basic 認証（Application Password）または WordPress REST 標準認証のフォールバック
    if (stripos($header, 'Basic ') === 0) {
        $app_auth = koto_mcp_authenticate_app_password_header($header);
        if (is_wp_error($app_auth)) {
            return $app_auth;
        }
        if (is_int($app_auth) && $app_auth > 0) {
            return koto_mcp_check_user_mcp_permission($app_auth);
        }
        return new WP_Error('mcp_unauthorized', 'Invalid Basic authentication credentials.', ['status' => 401, 'oauth_error' => 'invalid_token']);
    }

    if ($header === '' && is_user_logged_in()) {
        return koto_mcp_check_user_mcp_permission(get_current_user_id());
    }

    return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', ['status' => 401, 'oauth_error' => 'invalid_token']);
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

function koto_mcp_instructions_text()
{
    return implode("\n", [
        'コトダマンDB キャラクター下書き作成MCPサーバーです。スクリーンショットからキャラクター情報を読み取り、WordPressの下書き（draft）を作成・更新します（自動公開は行いません）。',
        '',
        '【スクリーンショットの読み取り・運用ルール】',
        '1. 外部チャットAI（Claude / Muse / ChatGPT等）の場合：ユーザーがチャットに添付したスクリーンショット画像（複数枚可）から直接情報を読み取ってください。',
        '2. ローカルCLIエージェントの場合：複数枚またはZIPが渡されたら `php lib/acf/mcp/prepare_screenshots.php <入力パス>` で生成した `contact_sheet.jpg` 1枚のみを読み取ってください。',
        '3. 画面に存在しない値や判別不能な値は絶対に推測で埋めず、空欄にするか人間に確認を求めてください。',
        '',
        '【最短・省トークン作成手順（4ステップ）】',
        'Step 1 (スキーマ確認): 不明なフィールド仕様がある場合のみ `get_field_schema` を呼ぶ（フィールド規則と `workflow_guide` が返却されます）。',
        'Step 2 (データ構築・ターム自動解決):',
        '  - `basic_fields` には基本データ（必須: `attribute`, `species`, `rarity`）を入れる。これらは日本語名（例: `"attribute": "水"`, `"species": "神"`, `"rarity": "なし"`, `"affiliation": ["エヴァンゲリオン"]`, `"available_moji_loop": [{"available_moji": "い"}]`）で直接指定でき、サーバー側でタームIDに自動解決されるため、不要な `get_terms` の連発は避けてください（未知のグループ名等を検索する時のみ `get_terms(taxonomy: "affiliation", search: "キーワード")` を使用）。',
        '  - `name_ruby`: キャラ名のひらがな読みのみを指定し、`()` / `（）` 内の文字列は含めない（例: `シンジ(決戦)` → `"しんじ"`）。',
        '  - `rarity`: ★6通常キャラは `"なし"`、特殊枠は `"スペシャル"`, `"レジェンド"`, `"グランド"`。★5以下は `"5"` 等。',
        '  - `status_auto_tf`: Lv.120キャラの場合は `true`。',
        '  - `available_moji_loop`: 初期使用可能文字のみを入れる（とくせい1・2や祝福の追加文字は `auto_input` 側で自動生成されるため含めない）。',
        '  - `auto_input` には、わざ・すごわざ・とくせい・リーダー・祝福・コトワザのゲーム内説明文をそのまま渡す。',
        'Step 3 (`preview_draft` 一括実行): 抽出した `character_name`, `basic_fields`, `auto_input` を一度で `preview_draft` に渡し、`preview_hash` を取得する。',
        'Step 4 (`create_draft` / `update_draft` 最小引数実行): 直前の `preview_draft` 成功後、`create_draft` には `preview_hash` と `idempotency_key` の2項目のみ（`update_draft` は加えて `post_id`）を渡す。サーバー側の30分キャッシュから入力一式が自動復元されるため、`basic_fields` や `auto_input` を重複送信しないでください。',
        '',
        '【エラー時の対応】',
        '- `VALIDATION_FAILED` を受けた場合は、エラー箇所（`missing_fields` / `invalid_fields`）のみを最大3回まで読み直して修正し、3回で解決しない場合は処理を停止して人間に確認を求めてください。',
    ]);
}

function koto_mcp_workflow_guide_data()
{
    return [
        'summary' => 'スクリーンショットからキャラクター下書き（draft）を作成する標準手順とフィールド規則',
        'screenshot_handling' => [
            'external_chat_ai' => 'チャット画面に添付されたスクリーンショット画像（複数枚可）から直接文字・数値を読み取る。画面にない値は推測しない。',
            'local_cli_agent' => 'php lib/acf/mcp/prepare_screenshots.php <zip_or_dir> を実行し、生成された contact_sheet.jpg 1枚のみを read_file で読み取る。',
        ],
        'steps' => [
            '1_extract' => 'スクリーンショットから character_name, basic_fields（必須: attribute, species, rarity）, auto_input（スキル・とくせい文言）を抽出する。',
            '2_term_resolution' => 'attribute, species, rarity, affiliation, available_moji_loop は日本語名（例: "水", "神", "なし", [{"available_moji": "い"}]）で指定可能（サーバー側でタームIDに自動解決）。未知の所属グループ等を確認する場合のみ get_terms(taxonomy, search) を使う。',
            '3_preview' => 'preview_draft に全データを一括送信し、valid: true と preview_hash を確認する。',
            '4_create_or_update' => 'create_draft には preview_hash と idempotency_key のみ（update_draft は加えて post_id）を渡す。30分キャッシュから自動復元されるため basic_fields / auto_input の再送は不要。',
        ],
        'key_field_rules' => [
            'name_ruby' => 'ひらがな読みのみ。カッコ () / （） 内の文字列は含めない（例: シンジ(決戦) -> しんじ）。',
            'rarity' => '★6通常は "なし"、★6特殊は "スペシャル" / "レジェンド" / "グランド"、★5以下は数字（"5" 等）。',
            'status_auto_tf' => 'Lv.120のキャラクターは true に設定する。',
            'available_moji_loop' => '初期使用可能文字のみを指定する（例: [{"available_moji": "い"}]）。とくせい・祝福で追加される文字は auto_input から自動生成されるため含めない。',
        ],
        'error_recovery' => 'VALIDATION_FAILED 時は該当項目のみ最大3回まで再確認し、解決しない場合は停止して人間に確認を求める。自動公開は行わない。',
    ];
}

function koto_mcp_resources_list()
{
    return [
        [
            'uri' => 'kotodaman://guide/character-draft',
            'name' => 'コトダマンDB キャラクター下書き作成・運用ガイド',
            'description' => 'スクリーンショットの読み取り方法、basic_fields / auto_input の分類、最短4ステップ手順、禁止事項の完全ガイド（Markdown）',
            'mimeType' => 'text/markdown',
        ],
        [
            'uri' => 'kotodaman://guide/field-manual',
            'name' => 'ACFフィールド別読み取りマニュアル',
            'description' => 'スクリーンショット上のどの箇所を根拠に各フィールドを読み取るかの定義一覧（JSON）',
            'mimeType' => 'application/json',
        ],
    ];
}

function koto_mcp_read_resource($uri)
{
    if ($uri === 'kotodaman://guide/character-draft') {
        $guide_path = get_stylesheet_directory() . '/documents/mcp/character-draft-guide.md';
        $text = file_exists($guide_path) ? (string) file_get_contents($guide_path) : koto_mcp_instructions_text();
        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'text/markdown',
                    'text' => $text,
                ],
            ],
        ];
    }
    if ($uri === 'kotodaman://guide/field-manual') {
        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => wp_json_encode(koto_mcp_field_manuals(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
    return new WP_Error('RESOURCE_NOT_FOUND', 'Requested resource was not found.', ['status' => 404]);
}

function koto_mcp_prompts_list()
{
    return [
        [
            'name' => 'character_draft_from_screenshots',
            'description' => '添付されたスクリーンショットからコトダマンDBのキャラクター下書きを作成する標準プロンプト',
            'arguments' => [
                [
                    'name' => 'character_name_hint',
                    'description' => 'キャラクター名のヒント（省略可・画像から読み取る場合は不要）',
                    'required' => false,
                ],
            ],
        ],
    ];
}

function koto_mcp_get_prompt($name, $arguments = [])
{
    if ($name !== 'character_draft_from_screenshots') {
        return new WP_Error('PROMPT_NOT_FOUND', 'Requested prompt was not found.', ['status' => 404]);
    }
    $hint = is_array($arguments) && !empty($arguments['character_name_hint'])
        ? "\n対象キャラクター名ヒント: " . sanitize_text_field((string) $arguments['character_name_hint'])
        : '';
    return [
        'description' => 'スクリーンショットからキャラクター下書きを作成する手順指示',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => koto_mcp_instructions_text() . $hint . "\n\n添付されたスクリーンショットから情報を読み取り、`preview_draft` で検証した上で `create_draft` を実行し、作成された下書きの `edit_url` を提示してください。",
                ],
            ],
        ],
    ];
}

function koto_mcp_handle_http($request)
{
    if ($request instanceof WP_REST_Request) {
        $http_method = strtoupper($request->get_method());
        if ($http_method === 'OPTIONS') {
            return new WP_REST_Response(null, 204, ['Allow' => 'POST, OPTIONS']);
        }
        if ($http_method !== 'POST') {
            return new WP_REST_Response(null, 405, ['Allow' => 'POST, OPTIONS']);
        }
    }
    $raw_body = (string) $request->get_body();
    if (strlen($raw_body) > KOTO_MCP_MAX_BODY_BYTES) {
        return koto_mcp_jsonrpc_error_response(null, -32600, 'Request payload too large (max 256KB).', ['reason' => 'payload_too_large'], 413);
    }
    $body = json_decode($raw_body, true);
    if (!is_array($body)) return new WP_Error('invalid_json', 'JSON-RPC body is required.', ['status' => 400]);
    $method = $body['method'] ?? '';
    $id = $body['id'] ?? null;

    // Authorization ヘッダーが明示送信されている場合は、initialize 等でもトークン有効性を検証して期限切れ時に 401 + WWW-Authenticate を返す
    $auth_header = koto_mcp_get_authorization_header($request);
    if ($auth_header !== '' && in_array($method, ['initialize', 'tools/list', 'resources/list', 'prompts/list'], true)) {
        $early_auth = koto_mcp_require_scope('validate', $request);
        if (is_wp_error($early_auth)) {
            $detail = $early_auth->get_error_data();
            $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 401;
            $headers = [];
            if ($status === 401) {
                $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
                    is_array($detail) ? ($detail['oauth_error'] ?? 'invalid_token') : 'invalid_token',
                    $early_auth->get_error_message()
                );
            }
            return koto_mcp_jsonrpc_error_response($id, -32000, $early_auth->get_error_message(), [
                'code' => $early_auth->get_error_code(),
                'detail' => $detail,
            ], $status, $headers);
        }
    }

    if ($method === 'initialize') {
        return rest_ensure_response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => KOTO_MCP_PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                    'resources' => ['listChanged' => false],
                    'prompts' => ['listChanged' => false],
                ],
                'serverInfo' => ['name' => 'kotodaman-db', 'version' => '1.1.0'],
                'instructions' => koto_mcp_instructions_text(),
            ],
        ]);
    }
    if ($method === 'ping') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()]);
    }
    if ($method === 'notifications/initialized' || strpos((string) $method, 'notifications/') === 0) {
        return new WP_REST_Response(null, 202);
    }
    if ($method === 'tools/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => koto_mcp_tools()]]);
    }
    if ($method === 'resources/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => koto_mcp_resources_list()]]);
    }
    if ($method === 'resources/read') {
        $res_auth = koto_mcp_require_scope('validate', $request);
        if (is_wp_error($res_auth)) {
            $detail = $res_auth->get_error_data();
            $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 401;
            $headers = $status === 401 ? ['WWW-Authenticate' => koto_mcp_www_authenticate_header('invalid_token', $res_auth->get_error_message())] : [];
            return koto_mcp_jsonrpc_error_response($id, -32000, $res_auth->get_error_message(), ['code' => $res_auth->get_error_code(), 'detail' => $detail], $status, $headers);
        }
        $uri = (string) ($body['params']['uri'] ?? '');
        $res = koto_mcp_read_resource($uri);
        if (is_wp_error($res)) {
            return koto_mcp_jsonrpc_error_response($id, -32602, $res->get_error_message());
        }
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $res]);
    }
    if ($method === 'prompts/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => koto_mcp_prompts_list()]]);
    }
    if ($method === 'prompts/get') {
        $prompt_name = (string) ($body['params']['name'] ?? '');
        $prompt_args = is_array($body['params']['arguments'] ?? null) ? $body['params']['arguments'] : [];
        $prompt = koto_mcp_get_prompt($prompt_name, $prompt_args);
        if (is_wp_error($prompt)) {
            return koto_mcp_jsonrpc_error_response($id, -32602, $prompt->get_error_message());
        }
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $prompt]);
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
    return [
        ['name' => 'get_field_schema', 'description' => 'Return ACF field schema and manual guidance. Set test_mode=true for full unfiltered schema.', 'inputSchema' => ['type' => 'object', 'properties' => ['test_mode' => ['type' => 'boolean']]]],
        ['name' => 'get_terms', 'description' => 'Return term dictionaries with IDs. taxonomy accepts a single name, comma-separated names, or "all". Optional search filters by name/slug.', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string'], 'search' => ['type' => 'string'], 'test_mode' => ['type' => 'boolean']], 'required' => ['taxonomy']]],
        ['name' => 'search_character', 'description' => 'Search existing character posts before creating one.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
        ['name' => 'get_draft', 'description' => 'Return current basic_fields, title, status, and MCP review metadata of an existing character draft.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']]],
        ['name' => 'validate', 'description' => 'Validate without saving. Set test_mode=true to include full parsed_acf.', 'inputSchema' => koto_mcp_draft_schema(false)],
        ['name' => 'preview_draft', 'description' => 'Parse, validate, and cache a draft without saving. Set test_mode=true to include full parsed_acf.', 'inputSchema' => koto_mcp_draft_schema(false)],
        ['name' => 'create_draft', 'description' => 'Create a draft after preview_draft. Only preview_hash and idempotency_key are required if preview_draft was just called.', 'inputSchema' => koto_mcp_draft_schema(true)],
        ['name' => 'update_draft', 'description' => 'Update an existing draft after preview_draft.', 'inputSchema' => koto_mcp_draft_schema(true, true)],
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
            'test_mode' => ['type' => 'boolean'],
        ],
        'required' => $update ? ['post_id', 'idempotency_key', 'preview_hash'] : ($hash_required ? ['idempotency_key', 'preview_hash'] : []),
    ];
    return $schema;
}

/**
 * 既存キャラクター下書き投稿から basic_fields と MCPメタデータを抽出する。
 *
 * @param int $post_id 投稿ID。
 * @return array<string, mixed>|null 抽出データ。投稿が存在しない場合は null。
 */
function koto_mcp_extract_existing_draft_data($post_id)
{
    $post = get_post((int) $post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'character') {
        return null;
    }

    $basic_fields = [];
    $single_tax_fields = [
        'attribute'     => 'attribute',
        'sub_attribute' => 'attribute',
        'species'       => 'species',
        'rarity'        => 'rarity',
    ];
    foreach ($single_tax_fields as $fname => $tax) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $raw = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }
        $tid = (is_numeric($raw) && (int) $raw > 0) ? (int) $raw : 0;
        if ($tid <= 0 && $fname !== 'sub_attribute') {
            $terms = get_the_terms($post->ID, $tax);
            if (is_array($terms) && !empty($terms) && isset($terms[0]->term_id)) {
                $tid = (int) $terms[0]->term_id;
            }
        }
        if ($tid > 0) {
            $term = get_term($tid, $tax);
            $basic_fields[$fname] = ($term && !is_wp_error($term)) ? $term->name : $tid;
        }
    }

    $multi_tax_fields = [
        'affiliation' => 'affiliation',
        'event'       => 'event',
    ];
    foreach ($multi_tax_fields as $fname => $tax) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $raw = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        $ids = is_array($raw) ? array_filter(array_map('intval', $raw)) : (is_numeric($raw) && (int) $raw > 0 ? [(int) $raw] : []);
        if (empty($ids)) {
            $terms = get_the_terms($post->ID, $tax);
            if (is_array($terms) && !empty($terms)) {
                foreach ($terms as $t) {
                    if (isset($t->term_id)) {
                        $ids[] = (int) $t->term_id;
                    }
                }
            }
        }
        if (!empty($ids)) {
            $names = [];
            foreach (array_unique($ids) as $tid) {
                $term = get_term($tid, $tax);
                $names[] = ($term && !is_wp_error($term)) ? $term->name : $tid;
            }
            $basic_fields[$fname] = $names;
        }
    }

    $scalar_fields = [
        'name_ruby',
        'status_auto_tf',
        '99_hp',
        '99_atk',
        '120_hp',
        '120_atk',
        'plus_hp',
        'plus_atk',
        'max_ls_hp',
        'max_ls_atk',
        'fuku_break',
        'get_place',
        'implement_date',
        'cv',
        'acquisition_text',
        'illust_text',
    ];
    foreach ($scalar_fields as $fname) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $val = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        if ($val === null || $val === '' || $val === false) {
            continue;
        }
        if (($fdef['type'] ?? '') === 'true_false') {
            $basic_fields[$fname] = (bool) $val;
        } elseif (($fdef['type'] ?? '') === 'number') {
            if (is_numeric($val)) {
                $basic_fields[$fname] = strpos((string) $val, '.') !== false ? (float) $val : (int) $val;
            }
        } else {
            $basic_fields[$fname] = $val;
        }
    }

    $moji_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('available_moji_loop') : null;
    if ($moji_fdef && !empty($moji_fdef['key'])) {
        $raw_rows = get_field($moji_fdef['key'], $post->ID, false);
        if (is_array($raw_rows) && !empty($raw_rows)) {
            $moji_rows = [];
            foreach ($raw_rows as $r) {
                if (!is_array($r)) continue;
                $m_raw = $r['available_moji'] ?? ($r['field_693528c3cf77c'] ?? []);
                $m_ids = is_array($m_raw) ? $m_raw : ($m_raw !== null && $m_raw !== '' ? [$m_raw] : []);
                $m_names = [];
                foreach ($m_ids as $mid) {
                    if (is_numeric($mid) && (int) $mid > 0) {
                        $t = get_term((int) $mid, 'available_moji');
                        $m_names[] = ($t && !is_wp_error($t)) ? $t->name : (int) $mid;
                    } elseif (is_string($mid) && $mid !== '') {
                        $m_names[] = $mid;
                    }
                }
                if (empty($m_names)) continue;
                $row_out = [
                    'available_moji' => count($m_names) === 1 ? $m_names[0] : $m_names,
                ];
                $place = $r['unlock_place'] ?? ($r['field_693529d5cf77e'] ?? 'default');
                if ($place !== '' && $place !== null) {
                    $row_out['unlock_place'] = (string) $place;
                }
                $group_cond = $r['moji_group_cond'] ?? ($r['field_69f1b4c1fe16b'] ?? null);
                if ($group_cond !== null && $group_cond !== '' && $group_cond !== false && $group_cond !== 0 && $group_cond !== '0') {
                    $row_out['moji_group_cond'] = (bool) $group_cond;
                }
                $pt = $r['unlock_need_point'] ?? ($r['field_6937a4df8ab14'] ?? ($r['field_693529fccf77f'] ?? ''));
                if ($pt !== '' && $pt !== null) {
                    $row_out['unlock_need_point'] = is_numeric($pt) ? (int) $pt : $pt;
                }
                $moji_rows[] = $row_out;
            }
            if (!empty($moji_rows)) {
                $basic_fields['available_moji_loop'] = $moji_rows;
            }
        }
    }

    $audit = get_post_meta($post->ID, '_koto_mcp_audit', true);
    $screenshots = get_post_meta($post->ID, '_koto_mcp_screenshot_urls', true);

    return [
        'post_id'        => (int) $post->ID,
        'character_name' => (string) $post->post_title,
        'status'         => (string) $post->post_status,
        'edit_url'       => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post->ID),
        'basic_fields'   => $basic_fields,
        'mcp_meta'       => [
            'generated'       => (bool) get_post_meta($post->ID, '_koto_mcp_generated', true),
            'needs_review'    => (bool) get_post_meta($post->ID, '_koto_mcp_needs_review', true),
            'screenshot_urls' => is_array($screenshots) ? $screenshots : [],
            'audit'           => is_array($audit) ? $audit : null,
        ],
    ];
}

/**
 * auto_input の部分未解析（丸数字区分や＋区切りの一部脱落）を検出して警告配列を返す。
 *
 * @param array<string, string> $safe_auto_input 入力文言連想配列。
 * @param array<string, mixed> $parsed 解析結果連想配列。
 * @param array<string, array<int, array<string, mixed>>> $csv 種別グループ済みCSV辞書。
 * @return array<int, string> 警告メッセージ配列。
 */
function koto_mcp_detect_auto_input_warnings($safe_auto_input, $parsed, $csv)
{
    $warnings = [];
    if (!function_exists('koto_split_by_circled_numbers') || !function_exists('koto_preprocess_text')) {
        return $warnings;
    }

    foreach ($safe_auto_input as $input_key => $input_value) {
        if ($input_value === '' || !isset($parsed[$input_key]) || empty($parsed[$input_key])) {
            continue;
        }
        if (in_array($input_key, ['auto_input_waza_name', 'auto_input_sugowaza_name'], true)) {
            continue;
        }

        $type = '';
        if (strpos($input_key, 'trait') !== false) {
            $type = 'とくせい';
        } elseif (strpos($input_key, 'kotowaza') !== false) {
            $type = 'コトわざ';
        } elseif (strpos($input_key, 'waza') !== false && strpos($input_key, 'sugowaza_condition') === false) {
            $type = 'わざ';
        } elseif (strpos($input_key, 'sugowaza_condition') !== false) {
            $type = 'すごわざ発動条件';
        } elseif (strpos($input_key, 'blessing') !== false) {
            $type = '祝福';
        } elseif (strpos($input_key, 'leader') !== false) {
            $type = 'リーダーとくせい';
        }
        if ($type === '' || $type === 'コトわざ') {
            continue;
        }

        $prep_text = koto_preprocess_text($input_value, $type);
        if ($type === 'わざ') {
            $waza_rows = $csv['わざ'] ?? [];
            $waza_condition_rows = $csv['わざ条件'] ?? [];
            $waza_shift_rows = $csv['わざシフトタイプ'] ?? [];
            $shift_type = koto_match_csv_template($prep_text, $waza_shift_rows, $input_key, 'prefix');
            if (koto_is_csv_template_match($shift_type)) {
                $prep_text = trim(mb_substr($prep_text, mb_strlen($shift_type['matched_text'])));
            }
            $parent_parts = koto_split_by_circled_numbers($prep_text);
            foreach ($parent_parts as $part_idx => $part) {
                $remaining_text = trim((string) $part);
                if ($remaining_text === '') continue;
                $has_cond = false;
                while (mb_strlen($remaining_text, 'UTF-8') > 0) {
                    $prev_len = mb_strlen($remaining_text, 'UTF-8');
                    $remaining_text = preg_replace('/^(?:[・\s、,]+|(?:または|もしくは)[・\s、,]*)+/u', '', $remaining_text);
                    if ($remaining_text === '') break;
                    $match = koto_match_csv_template($remaining_text, $waza_condition_rows, $input_key, 'prefix');
                    if (!koto_is_csv_template_match($match)) break;
                    $has_cond = true;
                    $remaining_text = trim(mb_substr($remaining_text, mb_strlen($match['matched_text'])));
                    if (mb_strlen($remaining_text, 'UTF-8') >= $prev_len) break;
                }
                if ($has_cond) {
                    $remaining_text = preg_replace('/^(?:の場合|の時|のとき|場合|時|とき|で)?[・\s、,]+/u', '', $remaining_text);
                }
                $child_parts = preg_split('/(?:\+|、さらに)/u', $remaining_text);
                if (!is_array($child_parts)) continue;
                foreach ($child_parts as $child_part) {
                    $child_part = preg_replace('/^[、,\s]+/u', '', (string) $child_part);
                    $child_part = preg_replace('/[、,\s]+$/u', '', $child_part);
                    if ($child_part === '') continue;
                    $match = koto_match_csv_template($child_part, $waza_rows, $input_key);
                    if (!koto_is_csv_template_match($match)) {
                        $warnings[] = sprintf('%s の一部の効果文言を解析できませんでした: %s', $input_key, $child_part);
                    }
                }
            }
            continue;
        }

        $parts = koto_split_by_circled_numbers($prep_text);
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                $trimmed = trim((string) $part);
                if ($trimmed === '') continue;
                $sub_parsed = koto_parse_text_by_type($trimmed, $type, $csv, $input_key);
                $is_empty_sub = empty($sub_parsed) || ($type === 'すごわざ発動条件' && empty($sub_parsed[0]['sugo_cond_loop']));
                if ($is_empty_sub) {
                    $warnings[] = sprintf('%s の一部の文言を解析できませんでした: %s', $input_key, $trimmed);
                }
            }
        }
    }

    return array_values(array_unique($warnings));
}

function koto_mcp_field_manuals()
{
    $primary = get_stylesheet_directory() . '/documents/mcp/mcp-field-manual.json';
    $fallback = get_stylesheet_directory() . '/documents/mcp-field-manual.json';
    $path = file_exists($primary) ? $primary : $fallback;
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function koto_mcp_auto_input_managed_fields()
{
    return [
        'waza_group_loop',
        'waza_maltiplier_table_group',
        'sugowaza_condition',
        'sugo_shift_type',
        'sugowaza_group_loop',
        'sugowaza_maltiplier_table_group',
        'koto_shift_type',
        'kotowaza_loop_v2',
        'kotowaza_maltiplier_table_group',
        'first_trait_loop',
        'second_trait_loop',
        'ls_loop',
        'blessing_trait_loop',
        'blessing_ex_trait_loop',
        'miracle_leader_loop',
        'charge_skill_loop',
        'ex_trait_loop',
    ];
}

function koto_mcp_field_schema($compact = false)
{
    $fields = [];
    $excluded_in_compact = $compact ? koto_mcp_auto_input_managed_fields() : [];
    $files = glob(get_stylesheet_directory() . '/acf-json/group_*.json') ?: [];
    foreach ($files as $file) {
        $group = json_decode(file_get_contents($file), true);
        if (!is_array($group)) continue;
        $is_character_group = false;
        foreach (($group['location'] ?? []) as $or_group) {
            foreach ((array) $or_group as $rule) {
                if (($rule['param'] ?? '') === 'post_type' && ($rule['operator'] ?? '==') === '==' && ($rule['value'] ?? '') === 'character') {
                    $is_character_group = true;
                    break 2;
                }
            }
        }
        if (!$is_character_group && !empty($group['location'])) {
            continue;
        }
        foreach (($group['fields'] ?? []) as $field) {
            if (empty($field['name']) || in_array($field['type'], ['tab', 'accordion'], true)) continue;
            if ($compact && in_array($field['name'], $excluded_in_compact, true)) continue;
            if ($compact) {
                $entry = [
                    'label' => $field['label'] ?? $field['name'],
                    'type'  => $field['type'],
                ];
                if (!empty($field['required'])) $entry['required'] = true;
                if (!empty($field['taxonomy'])) $entry['taxonomy'] = $field['taxonomy'];
                if (!empty($field['choices'])) $entry['choices'] = $field['choices'];
                if (isset($field['min']) && $field['min'] !== '' && $field['min'] !== null) $entry['min'] = $field['min'];
                if (isset($field['max']) && $field['max'] !== '' && $field['max'] !== null) $entry['max'] = $field['max'];
                $fields[$field['name']] = $entry;
            } else {
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
    }
    foreach (koto_mcp_field_manuals() as $name => $manual) {
        if (isset($fields[$name]) && is_array($manual)) $fields[$name]['manual'] = $manual;
    }
    return $fields;
}

function koto_mcp_validate_draft(&$args)
{
    $errors = ['missing_fields' => [], 'invalid_fields' => []];

    if (!empty($args['post_id'])) {
        $post_id = (int) $args['post_id'];
        if (koto_mcp_is_protected_template_post($post_id)) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', 'post_id', 'protected_template', 'Template posts cannot be accessed or modified via MCP.');
        } else {
            $existing_data = koto_mcp_extract_existing_draft_data($post_id);
            if ($existing_data) {
                if ((!isset($args['character_name']) || trim((string) $args['character_name']) === '') && !empty($existing_data['character_name'])) {
                    $args['character_name'] = $existing_data['character_name'];
                }
                $existing_bf = is_array($existing_data['basic_fields'] ?? null) ? $existing_data['basic_fields'] : [];
                if (!isset($args['basic_fields']) || !is_array($args['basic_fields'])) {
                    $args['basic_fields'] = [];
                }
                foreach (['attribute', 'species', 'rarity'] as $req_k) {
                    $is_empty_field = !array_key_exists($req_k, $args['basic_fields']) || $args['basic_fields'][$req_k] === null || $args['basic_fields'][$req_k] === '' || (is_array($args['basic_fields'][$req_k]) && count($args['basic_fields'][$req_k]) === 0);
                    if ($is_empty_field && array_key_exists($req_k, $existing_bf)) {
                        $args['basic_fields'][$req_k] = $existing_bf[$req_k];
                    }
                }
            }
        }
    }

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
            foreach ($ids as $term_item) {
                $resolved_tid = function_exists('koto_mcp_resolve_taxonomy_term_id')
                    ? koto_mcp_resolve_taxonomy_term_id($term_item, $definition['taxonomy'])
                    : ((is_int($term_item) || (is_string($term_item) && ctype_digit($term_item))) && term_exists((int) $term_item, $definition['taxonomy']) ? (int) $term_item : 0);
                if ($resolved_tid <= 0) {
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
                if (!is_scalar($choice) || is_bool($choice) || !array_key_exists((string) $choice, $definition['choices'])) {
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

        if ($definition['type'] === 'repeater') {
            $is_list = is_array($value) && (empty($value) || array_keys($value) === range(0, count($value) - 1));
            if (!$is_list) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                continue;
            }
            if ($name === 'available_moji_loop') {
                $allowed_places = ['default', 'first_trait', 'second_trait', 'blessing', 'super_copy', 'super_change', 'super_both'];
                foreach ($value as $row) {
                    if (!koto_mcp_is_object_like_array($row)) {
                        $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                        break;
                    }
                    $raw_moji = $row['available_moji'] ?? ($row['field_693528c3cf77c'] ?? null);
                    $moji_items = is_array($raw_moji) ? $raw_moji : ($raw_moji !== null && $raw_moji !== '' ? [$raw_moji] : []);
                    if (empty($moji_items)) {
                        $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'required_empty');
                        break;
                    }
                    foreach ($moji_items as $m_item) {
                        if (is_int($m_item) || (is_string($m_item) && ctype_digit($m_item))) {
                            if (!term_exists((int) $m_item, 'available_moji')) {
                                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                            }
                        } elseif (is_string($m_item) && trim($m_item) !== '') {
                            $term = get_term_by('name', trim($m_item), 'available_moji') ?: get_term_by('slug', trim($m_item), 'available_moji');
                            if (!$term || is_wp_error($term)) {
                                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                            }
                        } else {
                            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                        }
                    }
                    if (isset($row['unlock_place']) && $row['unlock_place'] !== '' && $row['unlock_place'] !== null) {
                        if (!is_string($row['unlock_place']) || !in_array($row['unlock_place'], $allowed_places, true)) {
                            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_choice');
                        }
                    }
                }
            }
            continue;
        }

        if ($definition['type'] === 'group') {
            if (!koto_mcp_is_object_like_array($value)) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
            }
            continue;
        }

        if (in_array($definition['type'], ['text', 'textarea', 'url', 'email', 'date_picker', 'select', 'radio'], true) && (!is_string($value) && !is_numeric($value))) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
        }
    }
    $raw_auto_input = is_array($args['auto_input'] ?? null) ? $args['auto_input'] : [];
    $safe_auto_input = [];
    foreach ($raw_auto_input as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $safe_auto_input[$k] = $v;
        }
    }
    $csv = koto_group_csv_by_type(koto_load_csv_dictionary(get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv'));
    $parsed = koto_build_acf_data_from_inputs($safe_auto_input, $csv);
    $requested = array_filter($raw_auto_input, static function ($value) { return $value !== null && $value !== ''; });
    foreach ($requested as $input_key => $input_value) {
        if (!array_key_exists($input_key, $parsed) || empty($parsed[$input_key])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $input_key, 'unparsed_text');
        }
    }
    $warnings = koto_mcp_detect_auto_input_warnings($safe_auto_input, $parsed, $csv);
    return ['errors' => $errors, 'parsed_acf' => $parsed, 'warnings' => $warnings, 'normalized_args' => $args];
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
    $scope = in_array($name, ['get_field_schema', 'get_terms', 'search_character', 'get_draft', 'validate'], true) ? 'validate' : 'preview';
    if (in_array($name, ['create_draft', 'update_draft', 'trash_draft'], true)) $scope = 'create';
    $auth = koto_mcp_require_scope($scope, $request);
    if (is_wp_error($auth)) return $auth;

    $arg_validation = koto_mcp_validate_tool_arguments($name, $args);
    if (is_wp_error($arg_validation)) {
        return $arg_validation;
    }
    $rate_key = 'koto_mcp_rate_' . $auth;
    $now = time();
    $rate_state = get_transient($rate_key);
    if (is_array($rate_state) && isset($rate_state['count'], $rate_state['reset_at']) && (int) $rate_state['reset_at'] > $now) {
        $rate_count = (int) $rate_state['count'];
        $reset_at = (int) $rate_state['reset_at'];
    } elseif (is_numeric($rate_state) && (int) $rate_state > 0) {
        $rate_count = (int) $rate_state;
        $reset_at = $now + MINUTE_IN_SECONDS;
    } else {
        $rate_count = 0;
        $reset_at = $now + MINUTE_IN_SECONDS;
    }
    if ($rate_count >= 60) {
        return new WP_Error('RATE_LIMITED', 'Too many MCP requests. Please retry later.', ['field' => '', 'reason' => 'invalid_value', 'status' => 429]);
    }
    set_transient($rate_key, ['count' => $rate_count + 1, 'reset_at' => $reset_at], max(1, $reset_at - $now));

    $test_mode = koto_mcp_is_test_mode($args);
    $prev_test_mode = $GLOBALS['koto_auto_input_test_mode'] ?? false;
    $GLOBALS['koto_auto_input_test_mode'] = $test_mode;
    try {
        if ($name === 'get_field_schema') {
            $resp = [
                'fields' => koto_mcp_field_schema(!$test_mode),
                'required_for_create' => ['attribute', 'species', 'rarity'],
                'auto_input_keys' => [
                    'auto_input_waza_name',
                    'auto_input_waza',
                    'auto_input_sugowaza_name',
                    'auto_input_sugowaza_condition',
                    'auto_input_sugowaza',
                    'auto_input_trait1',
                    'auto_input_trait2',
                    'auto_input_leader',
                    'auto_input_blessing',
                    'auto_input_kotowaza',
                ],
                'workflow_guide' => koto_mcp_workflow_guide_data(),
            ];
            if ($test_mode) {
                $resp['test_mode'] = true;
            }
            return $resp;
        }
        if ($name === 'get_terms') {
            $raw_tax = trim((string) ($args['taxonomy'] ?? ''));
            $search = isset($args['search']) ? trim((string) $args['search']) : '';
            $tax_list = ($raw_tax === 'all')
                ? ['attribute', 'species', 'rarity', 'affiliation', 'event', 'gimmick', 'available_moji', 'suitable_quest']
                : array_values(array_filter(array_map('sanitize_key', explode(',', $raw_tax))));
            if (empty($tax_list)) {
                return new WP_Error('VALIDATION_FAILED', 'Unknown taxonomy.', ['field' => 'taxonomy', 'reason' => 'invalid_value']);
            }
            foreach ($tax_list as $tax_item) {
                if (!taxonomy_exists($tax_item)) {
                    return new WP_Error('VALIDATION_FAILED', 'Unknown taxonomy.', ['field' => 'taxonomy', 'reason' => 'invalid_value']);
                }
            }
            $fetch_terms = static function ($tax_item) use ($search) {
                $query_args = ['taxonomy' => $tax_item, 'hide_empty' => false];
                if ($search !== '') {
                    $query_args['search'] = $search;
                }
                $terms = get_terms($query_args);
                if (is_wp_error($terms) || !is_array($terms)) {
                    return [];
                }
                return array_values(array_map(static function ($term) {
                    return ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
                }, $terms));
            };
            if (count($tax_list) === 1 && $raw_tax !== 'all') {
                return ['taxonomy' => $tax_list[0], 'terms' => $fetch_terms($tax_list[0])];
            }
            $grouped = [];
            foreach ($tax_list as $tax_item) {
                $grouped[$tax_item] = $fetch_terms($tax_item);
            }
            return ['taxonomies' => $grouped];
        }
        if ($name === 'search_character') {
            $query = new WP_Query(['post_type' => 'character', 'post_status' => ['publish', 'draft', 'pending', 'private'], 's' => sanitize_text_field($args['name'] ?? ''), 'posts_per_page' => 20, 'no_found_rows' => true]);
            $matches = [];
            foreach ($query->posts as $post) {
                if (koto_mcp_is_protected_template_post($post)) continue;
                if ($post->post_status === 'private') {
                    if (!user_can($auth, 'read_post', $post->ID)) continue;
                } elseif ($post->post_status !== 'publish') {
                    if (!user_can($auth, 'edit_post', $post->ID)) continue;
                }
                $matches[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status];
            }
            return ['matches' => $matches];
        }
        if ($name === 'get_draft') {
            $post_id = (int) ($args['post_id'] ?? 0);
            if (koto_mcp_is_protected_template_post($post_id)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be accessed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'character') {
                return new WP_Error('NOT_FOUND', 'Character post not found.', ['field' => 'post_id', 'reason' => 'not_found', 'status' => 404]);
            }
            if (koto_mcp_is_protected_template_post($post)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be accessed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            if (!user_can($auth, 'edit_post', $post->ID)) {
                return new WP_Error('FORBIDDEN', 'The draft cannot be read by this user.', ['field' => 'post_id', 'reason' => 'forbidden', 'status' => 403]);
            }
            $draft_data = koto_mcp_extract_existing_draft_data($post->ID);
            if (!$draft_data) {
                return new WP_Error('NOT_FOUND', 'Failed to extract draft data.', ['field' => 'post_id', 'reason' => 'not_found', 'status' => 404]);
            }
            return $draft_data;
        }
        if ($name === 'trash_draft') {
            $post_id = (int) ($args['post_id'] ?? 0);
            if (koto_mcp_is_protected_template_post($post_id)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be trashed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft') return new WP_Error('VALIDATION_FAILED', 'Only character drafts can be trashed.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            if (koto_mcp_is_protected_template_post($post)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be trashed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            if (!user_can($auth, 'delete_post', $post->ID)) return new WP_Error('VALIDATION_FAILED', 'The draft cannot be deleted by this user.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            $trashed = wp_trash_post($post->ID);
            if (!$trashed) return new WP_Error('TRASH_FAILED', 'Failed to trash the draft.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            return ['post_id' => $post->ID, 'status' => 'trash'];
        }

        // create_draft / update_draft で preview_hash のみが渡された場合、直前の preview_draft キャッシュから入力を復元
        if (in_array($name, ['create_draft', 'update_draft'], true) && !empty($args['preview_hash'])) {
            $has_explicit_payload = array_key_exists('basic_fields', $args) || array_key_exists('auto_input', $args) || array_key_exists('character_name', $args);
            if (!$has_explicit_payload) {
                $cached_preview = get_transient(koto_mcp_preview_cache_key($auth, (string) $args['preview_hash']));
                if (is_array($cached_preview)) {
                    foreach (['character_name', 'basic_fields', 'auto_input', 'screenshot_urls', 'evidence'] as $ckey) {
                        if (!array_key_exists($ckey, $args) && array_key_exists($ckey, $cached_preview) && $cached_preview[$ckey] !== null) {
                            $args[$ckey] = $cached_preview[$ckey];
                        }
                    }
                }
            }
        }
        if ($name === 'create_draft' && (!isset($args['character_name']) || trim((string) $args['character_name']) === '')) {
            return new WP_Error('VALIDATION_FAILED', 'character_name is required (or preview_hash cache expired).', ['field' => 'character_name', 'reason' => 'required_empty']);
        }

        if (!empty($args['post_id']) && koto_mcp_is_protected_template_post((int) $args['post_id'])) {
            return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be accessed or modified via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
        }

        $validation = koto_mcp_validate_draft($args);
        if (!empty($validation['errors']['missing_fields']) || !empty($validation['errors']['invalid_fields'])) {
            return new WP_Error('VALIDATION_FAILED', 'Draft validation failed.', $validation['errors']);
        }
        $hash = koto_mcp_content_hash($args, $validation['parsed_acf']);
        $parsed_counts = [];
        foreach ($validation['parsed_acf'] as $k => $rows) {
            $parsed_counts[$k] = is_array($rows) ? count($rows) : 1;
        }
        if ($name === 'validate' || $name === 'preview_draft') {
            set_transient(
                koto_mcp_preview_cache_key($auth, $hash),
                [
                    'character_name'  => $args['character_name'] ?? '',
                    'basic_fields'    => $args['basic_fields'] ?? [],
                    'auto_input'      => $args['auto_input'] ?? [],
                    'screenshot_urls' => $args['screenshot_urls'] ?? null,
                    'evidence'        => $args['evidence'] ?? null,
                ],
                30 * MINUTE_IN_SECONDS
            );
            $resp = [
                'valid'         => true,
                'content_hash'  => $hash,
                'preview_hash'  => $hash,
                'parsed_counts' => $parsed_counts,
                'warnings'      => $validation['warnings'] ?? [],
            ];
            if ($test_mode) {
                $resp['test_mode'] = true;
                $resp['parsed_acf'] = $validation['parsed_acf'];
            }
            return $resp;
        }
        if (empty($args['preview_hash']) || !hash_equals($hash, (string) $args['preview_hash'])) {
            return new WP_Error('PREVIEW_HASH_MISMATCH', 'preview_hash does not match the current content.', ['field' => 'preview_hash', 'reason' => 'invalid_value']);
        }
        $key = trim((string) ($args['idempotency_key'] ?? ''));
        if ($key === '') return new WP_Error('VALIDATION_FAILED', 'idempotency_key is required.', ['field' => 'idempotency_key', 'reason' => 'required_empty']);
        $fingerprint = $name . '|' . (int) ($args['post_id'] ?? 0) . '|' . $hash;
        $reservation = koto_mcp_reserve_idempotency_key($auth, $key, $fingerprint);
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
            if (isset($args['character_name']) && is_string($args['character_name']) && trim($args['character_name']) !== '') {
                wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($args['character_name'])]);
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
        update_post_meta($post_id, '_koto_mcp_audit', ['user_id' => $auth, 'content_hash' => $hash, 'evidence' => $args['evidence'] ?? [], 'warnings' => $validation['warnings'] ?? [], 'created_at' => current_time('mysql')]);
        do_action('acf/save_post', $post_id);
        $result = [
            'post_id'         => $post_id,
            'status'          => 'draft',
            'edit_url'        => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id),
            'content_hash'    => $hash,
            'screenshot_urls' => get_post_meta($post_id, '_koto_mcp_screenshot_urls', true),
            'warnings'        => $validation['warnings'] ?? [],
        ];
        if ($test_mode) {
            $result['test_mode'] = true;
            $result['parsed_acf'] = $validation['parsed_acf'];
        }
        koto_mcp_complete_idempotency_key($idempotency_option, $result, $fingerprint);
        koto_mcp_maybe_cleanup_expired_options();
        return $result;
    } finally {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
    }
}

function koto_mcp_set_featured_image_from_url($post_id, $url)
{
    global $wpdb;
    $url = esc_url_raw($url);
    $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
    if (!$url || !in_array($scheme, ['https', 'http'], true) || wp_parse_url($url, PHP_URL_HOST) !== 'www.kotodaman-db.com') return;
    $existing = attachment_url_to_postid($url);
    if (!$existing) {
        $raw_basename = rawurldecode(basename((string) wp_parse_url($url, PHP_URL_PATH)));
        $stem = pathinfo($raw_basename, PATHINFO_FILENAME);
        if ($stem !== '') {
            $like_file = '%' . $wpdb->esc_like($raw_basename);
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND (guid LIKE %s OR post_title = %s OR post_name = %s) ORDER BY ID ASC LIMIT 1",
                $like_file,
                $stem,
                sanitize_title($stem)
            ));
        }
    }
    if ($existing) {
        if (!wp_attachment_is_image($existing)) return;
        set_post_thumbnail($post_id, $existing);
        $image_field = koto_get_top_level_character_acf_field('character_image');
        if ($image_field) update_field($image_field['key'], $existing, $post_id);
        return;
    }

    if ($url !== KOTO_MCP_DEFAULT_IMAGE_URL && !current_user_can('upload_files')) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($url, 15);
    if (is_wp_error($tmp)) return;
    $filesize = @filesize($tmp);
    $image_info = function_exists('getimagesize') ? @getimagesize($tmp) : false;
    $allowed_mimes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    if ($filesize === false || $filesize <= 0 || $filesize > 10 * 1024 * 1024 || !is_array($image_info) || !in_array($image_info['mime'] ?? '', $allowed_mimes, true)) {
        @unlink($tmp);
        return;
    }
    $filename = sanitize_file_name(rawurldecode(basename((string) wp_parse_url($url, PHP_URL_PATH))));
    $file = ['name' => $filename ?: 'koto-mcp-image.png', 'tmp_name' => $tmp];
    $parent_post_id = ($url === KOTO_MCP_DEFAULT_IMAGE_URL) ? 0 : $post_id;
    $attachment = media_handle_sideload($file, $parent_post_id);
    if (is_wp_error($attachment)) {
        @unlink($tmp);
        return;
    }
    set_post_thumbnail($post_id, $attachment);
    $image_field = koto_get_top_level_character_acf_field('character_image');
    if ($image_field) update_field($image_field['key'], $attachment, $post_id);
}

function koto_mcp_oauth_is_valid_redirect_uri($uri)
{
    if (!is_string($uri)) return false;
    $trimmed = trim($uri);
    if ($trimmed === '' || strpos($trimmed, '#') !== false) return false;
    $parsed = wp_parse_url($trimmed);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) return false;
    $scheme = strtolower((string) $parsed['scheme']);
    $host = strtolower((string) $parsed['host']);
    if ($scheme === 'https') return true;
    if ($scheme === 'http') {
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $allowed_http_hosts = array_filter(['localhost', '127.0.0.1', '[::1]', '::1', $site_host]);
        return in_array($host, $allowed_http_hosts, true);
    }
    return false;
}

function koto_mcp_oauth_registered_redirect_uris($client)
{
    $uris = [];
    foreach ((array) ($client['redirect_uris'] ?? []) as $uri) {
        if (!is_string($uri)) continue;
        $trimmed = trim($uri);
        if (!koto_mcp_oauth_is_valid_redirect_uri($trimmed)) continue;
        $normalized = esc_url_raw($trimmed);
        if ($normalized !== '') {
            $uris[] = $normalized;
        }
    }
    return array_values(array_unique($uris));
}

function koto_mcp_oauth_resolve_redirect_uri($submitted, $registered_uris)
{
    $submitted = is_string($submitted) ? trim($submitted) : '';
    if ($submitted !== '' && strpos($submitted, '#') !== false) {
        return new WP_Error('invalid_request', 'redirect_uri must not contain a fragment.', ['status' => 400]);
    }
    $submitted = $submitted !== '' ? esc_url_raw($submitted) : '';
    if ($submitted === '' && count($registered_uris) === 1) {
        return $registered_uris[0];
    }
    if ($submitted === '' || !in_array($submitted, $registered_uris, true)) {
        return new WP_Error('invalid_request', 'redirect_uri must exactly match a registered redirect URI.', ['status' => 400]);
    }
    return $submitted;
}

function koto_mcp_oauth_safe_redirect($target_url)
{
    $host = wp_parse_url($target_url, PHP_URL_HOST);
    $filter = static function ($hosts) use ($host) {
        if ($host && !in_array($host, $hosts, true)) {
            $hosts[] = $host;
        }
        return $hosts;
    };
    add_filter('allowed_redirect_hosts', $filter);
    wp_safe_redirect($target_url);
    remove_filter('allowed_redirect_hosts', $filter);
    exit;
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
    koto_mcp_oauth_safe_redirect(add_query_arg($params, $redirect));
}

function koto_mcp_oauth_register_client($request)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : 'unknown';
    $rate_key = 'koto_mcp_reg_rate_' . hash('sha256', $ip);
    $now = time();
    $rate_state = get_transient($rate_key);
    if (is_array($rate_state) && isset($rate_state['count'], $rate_state['reset_at']) && (int) $rate_state['reset_at'] > $now) {
        $rate_count = (int) $rate_state['count'];
        $reset_at = (int) $rate_state['reset_at'];
    } else {
        $rate_count = 0;
        $reset_at = $now + MINUTE_IN_SECONDS;
    }
    if ($rate_count >= 30) {
        return new WP_Error('rate_limited', 'Too many client registration requests.', ['status' => 429]);
    }
    set_transient($rate_key, ['count' => $rate_count + 1, 'reset_at' => $reset_at], max(1, $reset_at - $now));

    $body = json_decode($request->get_body(), true) ?: [];
    $redirect_uris = [];
    foreach ((array) ($body['redirect_uris'] ?? []) as $uri) {
        if (!is_string($uri)) continue;
        $trimmed = trim($uri);
        if (!koto_mcp_oauth_is_valid_redirect_uri($trimmed)) continue;
        $normalized = esc_url_raw($trimmed);
        if ($normalized !== '') {
            $redirect_uris[] = $normalized;
        }
    }
    $redirect_uris = array_values(array_unique($redirect_uris));
    if (empty($redirect_uris)) {
        return new WP_Error('invalid_client_metadata', 'At least one valid redirect_uris entry (HTTPS or loopback HTTP without fragments) is required.', ['status' => 400]);
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

function koto_mcp_oauth_browser_error($error, $description, $status = 400)
{
    status_header((int) $status);
    nocache_headers();
    header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
    echo wp_json_encode([
        'error' => sanitize_key($error),
        'error_description' => sanitize_text_field($description),
    ]);
    exit;
}

function koto_mcp_oauth_request_fields($params)
{
    $fields = [
        'response_type',
        'client_id',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'scope',
        'state',
        'resource',
    ];
    $result = [];
    foreach ($fields as $field) {
        if (isset($params[$field]) && is_scalar($params[$field])) {
            $result[$field] = (string) $params[$field];
        }
    }
    return $result;
}

function koto_mcp_oauth_login_rate_keys($username)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    $normalized_username = strtolower(trim((string) $username));
    return [
        'ip' => 'koto_mcp_login_ip_' . hash('sha256', $ip),
        'username' => 'koto_mcp_login_user_' . hash('sha256', $normalized_username),
    ];
}

function koto_mcp_oauth_login_is_rate_limited($username)
{
    foreach (koto_mcp_oauth_login_rate_keys($username) as $key) {
        $state = get_transient($key);
        if (is_array($state) && (int) ($state['count'] ?? 0) >= 5) {
            return true;
        }
    }
    return false;
}

function koto_mcp_oauth_login_record_failure($username)
{
    foreach (koto_mcp_oauth_login_rate_keys($username) as $key) {
        $state = get_transient($key);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        set_transient($key, ['count' => $count + 1], 15 * MINUTE_IN_SECONDS);
    }
}

function koto_mcp_oauth_login_clear_rate_limits($username)
{
    foreach (koto_mcp_oauth_login_rate_keys($username) as $key) {
        delete_transient($key);
    }
}

function koto_mcp_oauth_user_has_access($user_id)
{
    $user = get_userdata((int) $user_id);
    $roles = $user ? (array) $user->roles : [];
    return in_array('mcp_user', $roles, true) || user_can((int) $user_id, 'edit_posts');
}

function koto_mcp_oauth_render_login($request_fields, $error = '')
{
    status_header(200);
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    echo '<!doctype html><html><head><meta charset="utf-8"><title>MCP Login</title></head><body>';
    echo '<h1>MCP認可</h1>';
    echo '<p>認可を続けるにはWordPressへログインしてください。</p>';
    if ($error !== '') {
        echo '<p role="alert">' . esc_html($error) . '</p>';
    }
    echo '<p>この認可ページには、wp-login.php専用CAPTCHAを自動移植していません。代替策として、ログイン試行をIP/ユーザー名単位で5回・15分に制限しています。</p>';
    echo '<form method="post" autocomplete="on">';
    foreach ($request_fields as $field => $value) {
        echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '">';
    }
    wp_nonce_field('koto_mcp_oauth_login');
    echo '<label>ユーザー名またはメールアドレス <input type="text" name="log" required autocomplete="username"></label><br>';
    echo '<label>パスワード <input type="password" name="pwd" required autocomplete="current-password"></label><br>';
    echo '<label><input type="checkbox" name="rememberme" value="forever"> ログイン状態を保持</label><br>';
    echo '<button type="submit" name="koto_mcp_login_action" value="login">ログインして続行</button>';
    echo '</form></body></html>';
    exit;
}

function koto_mcp_oauth_authorize_browser()
{
    $params = wp_unslash($_REQUEST);
    $client_id = sanitize_text_field($params['client_id'] ?? '');
    $client = get_transient('koto_mcp_client_' . $client_id);
    if (!is_array($client)) {
        koto_mcp_oauth_browser_error('invalid_client', 'Unknown OAuth client.', 400);
    }

    $registered_uris = koto_mcp_oauth_registered_redirect_uris($client);
    $redirect = koto_mcp_oauth_resolve_redirect_uri($params['redirect_uri'] ?? '', $registered_uris);
    $state = sanitize_text_field($params['state'] ?? '');
    if (is_wp_error($redirect)) {
        koto_mcp_oauth_browser_error($redirect->get_error_code(), $redirect->get_error_message(), 400);
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

    $request_fields = koto_mcp_oauth_request_fields($params);
    $request_fields['response_type'] = 'code';
    $request_fields['client_id'] = $client_id;
    $request_fields['redirect_uri'] = $redirect;
    $request_fields['code_challenge'] = $challenge;
    $request_fields['code_challenge_method'] = 'S256';
    $request_fields['scope'] = $scope;
    $request_fields['state'] = $state;

    if (!is_user_logged_in()) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && ($params['koto_mcp_login_action'] ?? '') === 'login') {
            if (!wp_verify_nonce((string) ($params['_wpnonce'] ?? ''), 'koto_mcp_oauth_login')) {
                koto_mcp_oauth_render_login($request_fields, 'ログインフォームの有効期限が切れました。もう一度お試しください。');
            }
            $username = sanitize_text_field((string) ($params['log'] ?? ''));
            if (koto_mcp_oauth_login_is_rate_limited($username)) {
                koto_mcp_oauth_render_login($request_fields, 'ログイン試行が多すぎます。15分後に再試行してください。');
            }
            $user = wp_signon([
                'user_login' => $username,
                'user_password' => (string) ($params['pwd'] ?? ''),
                'remember' => !empty($params['rememberme']),
            ], is_ssl());
            if (is_wp_error($user)) {
                koto_mcp_oauth_login_record_failure($username);
                koto_mcp_oauth_render_login($request_fields, 'ログインに失敗しました。ユーザー名またはパスワードを確認してください。');
            }
            koto_mcp_oauth_login_clear_rate_limits($username);
            wp_set_current_user($user->ID);
            $params = array_merge($params, $request_fields);
        } else {
            koto_mcp_oauth_render_login($request_fields);
        }
    }

    if (!koto_mcp_oauth_user_has_access(get_current_user_id())) {
        wp_die('MCP利用権限がありません。利用には管理者への申請が必要です。', 'permission_denied', ['response' => 403]);
    }

    $user = wp_get_current_user();
    $privileged = array_intersect(['administrator', 'editor', 'mcp_user'], (array) $user->roles);
    if (!$privileged && !get_user_meta(get_current_user_id(), '_koto_mcp_approved', true)) {
        update_user_meta(get_current_user_id(), '_koto_mcp_requested', 1);
        wp_die('MCP利用の承認が必要です。利用には管理者への申請が必要です。', 'approval_required', ['response' => 403]);
    }

    if (koto_mcp_has_scope($scope, 'create')) {
        $nonce_action = 'koto_mcp_oauth_consent|' . get_current_user_id() . '|' . $client_id . '|' . $redirect . '|' . $scope . '|' . $challenge . '|' . $state . '|' . ($request_fields['resource'] ?? '');
        $is_consent_post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            && isset($params['koto_mcp_consent_action']);
        if (!$is_consent_post) {
            status_header(200);
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            header('X-Frame-Options: DENY');
            header("Content-Security-Policy: frame-ancestors 'none'");
            echo '<!doctype html><html><head><meta charset="utf-8"><title>MCP Consent</title></head><body>';
            echo '<h1>MCP クライアント同意</h1>';
            echo '<p>ログインユーザー: ' . esc_html($user->user_login) . '</p>';
            echo '<p>クライアント: ' . esc_html((string) ($client['name'] ?? $client_id)) . '</p>';
            echo '<p>リダイレクトURI: ' . esc_html($redirect) . '</p>';
            echo '<p>要求スコープ: ' . esc_html($scope) . '</p>';
            echo '<form method="post">';
            foreach ($request_fields as $field => $value) {
                echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '">';
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
        'resource' => $request_fields['resource'] ?? '',
        'scope' => $scope,
        'family_id' => wp_generate_password(32, false, false),
    ], 300);
    koto_mcp_oauth_safe_redirect(add_query_arg(['code' => $code, 'state' => $state], $redirect));
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

    $response = rest_ensure_response(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => HOUR_IN_SECONDS, 'refresh_token' => $refresh, 'scope' => $scope]);
    if ($response instanceof WP_REST_Response) {
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
    }
    koto_mcp_maybe_cleanup_expired_options();
    return $response;
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

            $refresh_user_id = (int) ($refresh['user_id'] ?? 0);
            $refresh_user = get_userdata($refresh_user_id);
            $has_mcp_role = $refresh_user && in_array('mcp_user', (array) $refresh_user->roles, true);
            $privileged = $has_mcp_role || ($refresh_user && array_intersect(['administrator', 'editor'], (array) $refresh_user->roles));
            if (!$refresh_user || (!$has_mcp_role && !user_can($refresh_user_id, 'edit_posts')) || (!$privileged && !get_user_meta($refresh_user_id, '_koto_mcp_approved', true))) {
                koto_mcp_revoke_refresh_family($family_id);
                return new WP_Error('invalid_grant', 'The WordPress user is no longer authorized for MCP access.', ['status' => 400]);
            }

            $token_response = koto_mcp_issue_token($refresh_user_id, (string) $refresh['scope'], $client_id, $family_id);
            if (is_wp_error($token_response)) {
                return $token_response;
            }
            $issued_data = $token_response instanceof WP_REST_Response ? $token_response->get_data() : $token_response;
            $refresh['status'] = 'rotated';
            $refresh['rotated_at'] = time();
            $refresh['rotated_to'] = hash('sha256', (string) ($issued_data['refresh_token'] ?? ''));
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

    $code_hash = hash('sha256', $code);
    $code_lock = 'koto_mcp_code_lock_' . $code_hash;
    if (!add_option($code_lock, ['created_at' => time()], '', false)) {
        return new WP_Error('invalid_grant', 'Authorization code is already being processed.', ['status' => 400]);
    }

    try {
        $data = get_transient('koto_mcp_code_' . $code_hash);
        delete_transient('koto_mcp_code_' . $code_hash);
        if (!is_array($data)) return new WP_Error('invalid_grant', 'Invalid authorization code.', ['status' => 400]);
        if (($data['client_id'] ?? '') !== $client_id || ($data['redirect_uri'] ?? '') !== $redirect_uri) {
            return new WP_Error('invalid_grant', 'Authorization code client/redirect binding mismatch.', ['status' => 400]);
        }

        $verifier = (string) ($body['code_verifier'] ?? '');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (empty($data['challenge']) || !$verifier || !hash_equals((string) $data['challenge'], $challenge)) {
            return new WP_Error('invalid_grant', 'PKCE verification failed.', ['status' => 400]);
        }
        return koto_mcp_issue_token((int) $data['user_id'], (string) $data['scope'], $client_id, (string) ($data['family_id'] ?? ''));
    } finally {
        delete_option($code_lock);
    }
}

function koto_mcp_oauth_revoke($request)
{
    $params = koto_mcp_oauth_body_params($request);
    $token = (string) ($params['token'] ?? '');
    if ($token === '') {
        return ['revoked' => true];
    }
    $client_id = isset($params['client_id']) ? sanitize_text_field((string) $params['client_id']) : '';

    $token_hash = hash('sha256', $token);
    $access_data = get_transient('koto_mcp_token_' . $token_hash);
    if (is_array($access_data)) {
        if ($client_id === '' || ($access_data['client_id'] ?? '') === $client_id) {
            delete_transient('koto_mcp_token_' . $token_hash);
        }
    }

    $refresh_option = koto_mcp_refresh_record_option_name($token_hash);
    $refresh = get_option($refresh_option);
    if (is_array($refresh)) {
        if ($client_id !== '' && ($refresh['client_id'] ?? '') !== $client_id) {
            return new WP_Error('invalid_grant', 'Token client binding mismatch.', ['status' => 400]);
        }
        if (!empty($refresh['family_id'])) {
            koto_mcp_revoke_refresh_family((string) $refresh['family_id']);
        } else {
            $refresh['status'] = 'revoked';
            $refresh['revoked_at'] = time();
            update_option($refresh_option, $refresh, false);
        }
    }
    delete_transient('koto_mcp_refresh_' . $token_hash);
    return ['revoked' => true];
}

/**
 * 指定ユーザーの全MCPリフレッシュトークンファミリーを無効化（revoked）する。
 *
 * @param int $user_id WordPressユーザーID。
 */
function koto_mcp_revoke_user_tokens($user_id)
{
    global $wpdb;
    $user_id = (int) $user_id;
    if ($user_id <= 0) return;

    $like = $wpdb->esc_like('koto_mcp_refresh_') . '%';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT 500",
        $like,
        $wpdb->esc_like('koto_mcp_refresh_family_') . '%',
        $wpdb->esc_like('koto_mcp_refresh_lock_') . '%'
    ));

    $revoked_families = [];
    if (is_array($results)) {
        foreach ($results as $row) {
            $val = maybe_unserialize($row->option_value);
            if (is_array($val) && (int) ($val['user_id'] ?? 0) === $user_id) {
                $family_id = (string) ($val['family_id'] ?? '');
                if ($family_id !== '' && !isset($revoked_families[$family_id])) {
                    koto_mcp_revoke_refresh_family($family_id);
                    $revoked_families[$family_id] = true;
                }
            }
        }
    }
}

/**
 * 期限切れとなったMCP関連の options レコードをクリーンアップする（12時間毎に1回実行）。
 */
function koto_mcp_maybe_cleanup_expired_options()
{
    if (get_transient('koto_mcp_gc_lock')) {
        return;
    }
    set_transient('koto_mcp_gc_lock', 1, 12 * HOUR_IN_SECONDS);

    global $wpdb;
    $now = time();

    // 1. 期限切れの koto_mcp_idem_% をクリーンアップ (LIMIT 100)
    $idem_like = $wpdb->esc_like('koto_mcp_idem_') . '%';
    $idem_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
        $idem_like
    ));
    if (is_array($idem_rows)) {
        foreach ($idem_rows as $row) {
            $val = maybe_unserialize($row->option_value);
            if (is_array($val) && !empty($val['expires_at']) && (int) $val['expires_at'] < $now) {
                delete_option($row->option_name);
            }
        }
    }

    // 2. 期限切れの code_lock / refresh_lock (作成後5分以上経過した孤立ロック) (LIMIT 100)
    $lock_like_1 = $wpdb->esc_like('koto_mcp_code_lock_') . '%';
    $lock_like_2 = $wpdb->esc_like('koto_mcp_refresh_lock_') . '%';
    $lock_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 100",
        $lock_like_1,
        $lock_like_2
    ));
    if (is_array($lock_rows)) {
        foreach ($lock_rows as $row) {
            $val = maybe_unserialize($row->option_value);
            if (is_array($val) && !empty($val['created_at']) && ((int) $val['created_at'] + 300) < $now) {
                delete_option($row->option_name);
            }
        }
    }

    // 3. 期限切れの koto_mcp_refresh_% (30日経過) (LIMIT 100)
    $ref_like = $wpdb->esc_like('koto_mcp_refresh_') . '%';
    $ref_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT 100",
        $ref_like,
        $wpdb->esc_like('koto_mcp_refresh_family_') . '%',
        $wpdb->esc_like('koto_mcp_refresh_lock_') . '%'
    ));
    if (is_array($ref_rows)) {
        foreach ($ref_rows as $row) {
            $val = maybe_unserialize($row->option_value);
            if (is_array($val) && !empty($val['expires_at']) && (int) $val['expires_at'] < $now) {
                delete_option($row->option_name);
            }
        }
    }
}

function koto_mcp_register_approval_menu()
{
    add_users_page('MCP利用申請', 'MCP利用申請', 'manage_options', 'koto-mcp-approvals', 'koto_mcp_approval_page');
}

function koto_mcp_approval_page()
{
    if (!current_user_can('manage_options')) return;

    $pending_users = get_users(['meta_key' => '_koto_mcp_requested', 'meta_value' => 1]);
    $approved_users = get_users(['meta_key' => '_koto_mcp_approved', 'meta_value' => 1]);

    echo '<div class="wrap">';
    echo '<h1>MCP利用申請管理</h1>';

    echo '<h2>承認待ちの申請一覧</h2>';
    if (empty($pending_users)) {
        echo '<p>現在、承認待ちの申請はありません。</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ユーザー名</th><th>メールアドレス</th><th>権限グループ</th><th>操作</th></tr></thead>';
        echo '<tbody>';
        foreach ($pending_users as $user) {
            $approve_url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_approve=' . $user->ID), 'koto_mcp_approve_' . $user->ID);
            $reject_url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_reject=' . $user->ID), 'koto_mcp_reject_' . $user->ID);
            echo '<tr>';
            echo '<td>' . esc_html($user->user_login) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html(implode(', ', (array) $user->roles)) . '</td>';
            echo '<td>';
            echo '<a class="button button-primary" href="' . esc_url($approve_url) . '">承認</a> ';
            echo '<a class="button button-secondary" href="' . esc_url($reject_url) . '" onclick="return confirm(\'申請を却下しますか？\');">却下</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>承認済みユーザー一覧</h2>';
    if (empty($approved_users)) {
        echo '<p>承認済みのユーザーはいません（※管理者・編集者は常に利用可能です）。</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ユーザー名</th><th>メールアドレス</th><th>権限グループ</th><th>操作</th></tr></thead>';
        echo '<tbody>';
        foreach ($approved_users as $user) {
            $revoke_url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_revoke_user=' . $user->ID), 'koto_mcp_revoke_user_' . $user->ID);
            echo '<tr>';
            echo '<td>' . esc_html($user->user_login) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html(implode(', ', (array) $user->roles)) . '</td>';
            echo '<td>';
            echo '<a class="button button-secondary" href="' . esc_url($revoke_url) . '" onclick="return confirm(\'承認を取り消し、発行済みトークンを無効化しますか？\');">承認を取り消す</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}

function koto_mcp_handle_approval_action()
{
    if (!current_user_can('manage_options')) return;

    if (!empty($_GET['koto_mcp_approve'])) {
        $user_id = (int) $_GET['koto_mcp_approve'];
        check_admin_referer('koto_mcp_approve_' . $user_id);
        if (get_userdata($user_id)) {
            update_user_meta($user_id, '_koto_mcp_approved', 1);
            delete_user_meta($user_id, '_koto_mcp_requested');
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }

    if (!empty($_GET['koto_mcp_reject'])) {
        $user_id = (int) $_GET['koto_mcp_reject'];
        check_admin_referer('koto_mcp_reject_' . $user_id);
        if (get_userdata($user_id)) {
            delete_user_meta($user_id, '_koto_mcp_requested');
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }

    if (!empty($_GET['koto_mcp_revoke_user'])) {
        $user_id = (int) $_GET['koto_mcp_revoke_user'];
        check_admin_referer('koto_mcp_revoke_user_' . $user_id);
        if (get_userdata($user_id)) {
            delete_user_meta($user_id, '_koto_mcp_approved');
            delete_user_meta($user_id, '_koto_mcp_requested');
            koto_mcp_revoke_user_tokens($user_id);
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }
}
