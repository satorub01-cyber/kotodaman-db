<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP OAuth 2.1 認可サーバーモジュール
 *
 * 動的クライアント登録（RFC 7591）、認可エンドポイント（PKCE S256、ログイン・同意画面）、
 * トークン発行・更新（Authorization Code & Refresh Token grant、トークンファミリー、
 * Grace Period、リプレイ攻撃防止、並行ロックポーリング）、トークン失効（RFC 7009）を提供します。
 */

/**
 * Protected Resource Metadata (RFC 9728) 配列を生成する。
 *
 * @return array<string, mixed>
 */
function koto_mcp_oauth_protected_resource_metadata()
{
    return [
        'resource' => koto_mcp_server_url(),
        'authorization_servers' => [koto_mcp_issuer_url()],
        'scopes_supported' => ['validate', 'preview', 'create'],
        'bearer_methods_supported' => ['header'],
    ];
}

/**
 * Authorization Server Metadata (RFC 8414) 配列を生成する。
 *
 * @return array<string, mixed>
 */
function koto_mcp_oauth_authorization_server_metadata()
{
    $base = koto_mcp_base_url();
    $issuer = koto_mcp_issuer_url();
    return [
        'issuer' => $issuer,
        'authorization_endpoint' => koto_mcp_authorization_endpoint_url(),
        'token_endpoint' => $base . '/mcp/oauth/token',
        'registration_endpoint' => $base . '/mcp/oauth/register',
        'revocation_endpoint' => $base . '/mcp/oauth/revoke',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'code_challenge_methods_supported' => ['S256'],
        'scopes_supported' => ['validate', 'preview', 'create'],
        'client_id_metadata_document_supported' => true,
    ];
}

// RFC 8414 / RFC 9728 パス型ディスカバリおよび OAuth エンドポイントの早期捕捉（リライトルール状態に依存せず常時動作）
add_action('init', function () {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);

    // 1. Protected Resource Metadata ディスカバリ (RFC 9728)
    if (strpos($path, '/.well-known/oauth-protected-resource') === 0) {
        status_header(200);
        header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode(koto_mcp_oauth_protected_resource_metadata());
        exit;
    }

    // 2. Authorization Server Metadata ディスカバリ (RFC 8414) / OpenID Configuration
    if (strpos($path, '/.well-known/oauth-authorization-server') === 0 || strpos($path, '/.well-known/openid-configuration') === 0) {
        status_header(200);
        header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode(koto_mcp_oauth_authorization_server_metadata());
        exit;
    }

    // 3. /mcp/oauth/authorize ブラウザ認可画面
    if ($path === '/mcp/oauth/authorize' || $path === '/mcp/oauth/authorize/') {
        koto_mcp_oauth_authorize_browser();
        exit;
    }

    // 4. WordPress リライトルール登録（標準フォールバック）
    add_rewrite_rule('^\\.well-known/oauth-protected-resource/?$', 'index.php?koto_mcp_meta=protected', 'top');
    add_rewrite_rule('^\\.well-known/oauth-authorization-server/?$', 'index.php?koto_mcp_meta=authorization', 'top');
    add_rewrite_rule('^mcp/oauth/authorize/?$', 'index.php?koto_mcp_oauth_authorize=1', 'top');
    add_rewrite_tag('%koto_mcp_meta%', '([^&]+)');
    add_rewrite_tag('%koto_mcp_oauth_authorize%', '([0-1])');
}, 1);

/**
 * 申請メタ（フラグと日時）のライフサイクル管理ヘルパー
 */
function koto_mcp_record_request_meta($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return;
    update_user_meta($user_id, '_koto_mcp_requested', 1);
    update_user_meta($user_id, '_koto_mcp_requested_at', time());
}

function koto_mcp_clear_request_meta($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return;
    delete_user_meta($user_id, '_koto_mcp_requested');
    delete_user_meta($user_id, '_koto_mcp_requested_at');
}

/**
 * 無停止切り替えマイグレーション:
 * - 未剥奪の管理者・編集者・mcp_user へのタグ自動付与
 * - 既存の全リフレッシュトークンからユーザーメタ _koto_mcp_family_ids の構築
 */
function koto_mcp_migrate_existing_users_to_tag()
{
    if (get_option('koto_mcp_tag_migration_v3_done')) {
        return;
    }

    // 1. 未剥奪の管理者・編集者・mcp_user へのタグ付与
    $roles_to_migrate = ['administrator', 'editor', 'mcp_user'];
    $users = get_users(['role__in' => $roles_to_migrate]);
    foreach ($users as $u) {
        if (!get_user_meta($u->ID, '_koto_mcp_revoked', true)) {
            update_user_meta($u->ID, '_koto_mcp_approved', 1);
        }
    }

    // 2. 既存の全リフレッシュトークンレコードを走査し、ユーザーメタ _koto_mcp_family_ids を構築
    global $wpdb;
    $like = $wpdb->esc_like('koto_mcp_refresh_') . '%';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
        $like,
        $wpdb->esc_like('koto_mcp_refresh_family_') . '%',
        $wpdb->esc_like('koto_mcp_refresh_lock_') . '%'
    ));

    $user_families_map = [];
    if (is_array($results)) {
        foreach ($results as $row) {
            $val = maybe_unserialize($row->option_value);
            if (!is_array($val)) continue;
            $uid = (int) ($val['user_id'] ?? 0);
            $fid = (string) ($val['family_id'] ?? '');
            if ($uid > 0 && $fid !== '') {
                if (!isset($user_families_map[$uid])) {
                    $user_families_map[$uid] = [];
                }
                if (!in_array($fid, $user_families_map[$uid], true)) {
                    $user_families_map[$uid][] = $fid;
                }
                if (!get_user_meta($uid, '_koto_mcp_revoked', true)) {
                    update_user_meta($uid, '_koto_mcp_approved', 1);
                }
            }
        }
    }

    foreach ($user_families_map as $uid => $fids) {
        update_user_meta($uid, '_koto_mcp_family_ids', $fids);
    }

    update_option('koto_mcp_tag_migration_v3_done', 1, false);
}
add_action('init', 'koto_mcp_migrate_existing_users_to_tag', 0);

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
    $response = $meta === 'protected'
        ? koto_mcp_oauth_protected_resource_metadata()
        : koto_mcp_oauth_authorization_server_metadata();
    status_header(200);
    header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
    header('Access-Control-Allow-Origin: *');
    echo wp_json_encode($response);
    exit;
});

function koto_mcp_metadata_response($request)
{
    return rest_ensure_response(koto_mcp_oauth_protected_resource_metadata());
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
    $client_record = [
        'client_id' => $id,
        'client_type' => 'public',
        'allow_no_pkce' => false,
        'redirect_uris' => $redirect_uris,
        'name' => $client_name,
    ];
    koto_mcp_storage_put('koto_mcp_client_' . $id, $client_record, 'no');
    set_transient('koto_mcp_client_' . $id, $client_record, YEAR_IN_SECONDS);
    $resp = rest_ensure_response(['client_id' => $id, 'client_name' => $client_name, 'redirect_uris' => $redirect_uris, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']);
    if ($resp instanceof WP_REST_Response) {
        $resp->header('Access-Control-Allow-Origin', '*');
    }
    return $resp;
}

/**
 * RFC 8707 resource URI を比較用に正規化する。
 *
 * @param string $url
 * @return string
 */
function koto_mcp_oauth_canonical_resource_uri($url)
{
    $url = is_string($url) ? trim($url) : '';
    if ($url === '' || strpos($url, '#') !== false) {
        return '';
    }
    $parsed = wp_parse_url($url);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
        return '';
    }
    $scheme = strtolower((string) $parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    $host = strtolower((string) $parsed['host']);
    $port = isset($parsed['port']) ? (int) $parsed['port'] : 0;
    $default_port = $scheme === 'https' ? 443 : 80;
    $port_part = ($port > 0 && $port !== $default_port) ? (':' . $port) : '';
    $path = isset($parsed['path']) ? untrailingslashit((string) $parsed['path']) : '';
    return $scheme . '://' . $host . $port_part . $path;
}

/**
 * 要求された RFC 8707 resource / aud が本MCPサーバーを指しているか検証する。
 *
 * @param string $resource
 * @return bool
 */
function koto_mcp_oauth_is_valid_resource($resource)
{
    $resource = is_string($resource) ? trim($resource) : '';
    if ($resource === '') {
        return true;
    }
    $canon = koto_mcp_oauth_canonical_resource_uri($resource);
    if ($canon === '') {
        return false;
    }
    $allowed = array_filter([
        koto_mcp_oauth_canonical_resource_uri(koto_mcp_server_url()),
        koto_mcp_oauth_canonical_resource_uri(koto_mcp_base_url()),
    ]);
    return in_array($canon, $allowed, true);
}

/**
 * IPアドレスがプライベート／ループバック／リンクローカル等の予約領域か判定する（SSRF防御）。
 *
 * @param string $ip
 * @return bool
 */
function koto_mcp_oauth_is_private_or_reserved_ip($ip)
{
    if (!is_string($ip) || $ip === '') {
        return true;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * client_id が CIMD (SEP-991) 対象の HTTPS URL か判定する。
 *
 * @param string $client_id
 * @return bool
 */
function koto_mcp_oauth_is_cimd_url($client_id)
{
    if (!is_string($client_id) || stripos($client_id, 'https://') !== 0) {
        return false;
    }
    if (strlen($client_id) > 2048 || strpos($client_id, '#') !== false) {
        return false;
    }
    $parsed = wp_parse_url($client_id);
    if (!is_array($parsed) || empty($parsed['host'])) {
        return false;
    }
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        return false;
    }
    $path = (string) ($parsed['path'] ?? '');
    if ($path === '' || $path === '/') {
        return false;
    }
    return true;
}

/**
 * CIMD URL からクライアントメタデータJSONを取得・検証し、1時間キャッシュして返す（SEP-991）。
 *
 * @param string $client_id
 * @return array<string, mixed>|null
 */
function koto_mcp_oauth_resolve_cimd_client($client_id)
{
    if (!koto_mcp_oauth_is_cimd_url($client_id)) {
        return null;
    }

    $cache_key = 'koto_mcp_cimd_' . hash('sha256', $client_id);
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['client']) && is_array($cached['client'])) {
        return $cached['client'];
    }

    $parsed = wp_parse_url($client_id);
    $host = strtolower(trim((string) ($parsed['host'] ?? ''), '[]'));
    if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local' || substr($host, -5) === '.test') {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (koto_mcp_oauth_is_private_or_reserved_ip($host)) {
            return null;
        }
    }

    // テスト差し替えや追加検証用フック（通常運用時は null を返し wp_safe_remote_get を使用）
    $custom_response = apply_filters('koto_mcp_cimd_http_fetcher', null, $client_id);
    if ($custom_response === null) {
        if (filter_var($host, FILTER_VALIDATE_IP) === false && function_exists('gethostbynamel')) {
            $ips = @gethostbynamel($host);
            if (!is_array($ips) || empty($ips)) {
                return null;
            }
            foreach ($ips as $ip) {
                if (koto_mcp_oauth_is_private_or_reserved_ip($ip)) {
                    return null;
                }
            }
        }
        $custom_response = wp_safe_remote_get($client_id, [
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 65536,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    if (is_wp_error($custom_response) || !is_array($custom_response)) {
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($custom_response);
    if ($code !== 200) {
        return null;
    }
    $raw_body = (string) wp_remote_retrieve_body($custom_response);
    if ($raw_body === '' || strlen($raw_body) > 65536) {
        return null;
    }
    $doc = json_decode($raw_body, true);
    if (!is_array($doc)) {
        return null;
    }
    if (($doc['client_id'] ?? null) !== $client_id) {
        return null;
    }
    $auth_method = (string) ($doc['token_endpoint_auth_method'] ?? 'none');
    if ($auth_method !== 'none') {
        return null;
    }
    $valid_uris = koto_mcp_oauth_registered_redirect_uris($doc);
    if (empty($valid_uris)) {
        return null;
    }

    $client_record = [
        'client_id' => $client_id,
        'client_type' => 'public',
        'is_cimd' => true,
        'allow_no_pkce' => false,
        'name' => sanitize_text_field((string) ($doc['client_name'] ?? $host)),
        'redirect_uris' => $valid_uris,
        'token_endpoint_auth_method' => 'none',
    ];
    if (!empty($doc['scope']) && is_string($doc['scope'])) {
        $norm_scope = koto_mcp_normalize_scope($doc['scope']);
        if (!is_wp_error($norm_scope)) {
            $client_record['default_scope'] = $norm_scope;
        }
    }

    set_transient($cache_key, ['client' => $client_record, 'cached_at' => time()], HOUR_IN_SECONDS);
    return $client_record;
}

/**
 * client_id からローカル登録（DCR / 静的クライアント）または CIMD クライアントを解決する。
 *
 * @param string $client_id
 * @return array<string, mixed>|null
 */
function koto_mcp_oauth_resolve_client($client_id)
{
    $client_id = trim((string) $client_id);
    if ($client_id === '') {
        return null;
    }
    $client = koto_mcp_storage_get('koto_mcp_client_' . $client_id);
    if (!is_array($client)) {
        $client = get_transient('koto_mcp_client_' . $client_id);
    }
    if (is_array($client)) {
        if (!empty($client['disabled'])) {
            return null;
        }
        return $client;
    }
    if (koto_mcp_oauth_is_cimd_url($client_id)) {
        return koto_mcp_oauth_resolve_cimd_client($client_id);
    }
    return null;
}

function koto_mcp_hash_client_secret($secret)
{
    return hash_hmac('sha256', (string) $secret, wp_salt('auth'));
}

function koto_mcp_verify_client_secret($client, $secret)
{
    if (!is_array($client) || !is_string($secret) || $secret === '') {
        return false;
    }
    $stored_hash = (string) ($client['client_secret_hash'] ?? '');
    if ($stored_hash === '') {
        return false;
    }
    return hash_equals($stored_hash, koto_mcp_hash_client_secret($secret));
}

/**
 * 管理者向けの静的 Confidential OAuth クライアントを作成する。
 *
 * @param string $name
 * @param array<int, string> $redirect_uris
 * @param bool $allow_no_pkce
 * @param string $default_scope
 * @return array<string, mixed>|WP_Error
 */
function koto_mcp_create_static_oauth_client($name, array $redirect_uris, $allow_no_pkce = true, $default_scope = 'validate preview create')
{
    $name = sanitize_text_field((string) $name);
    if ($name === '') {
        return new WP_Error('invalid_client_name', 'クライアント名を入力してください。');
    }
    $valid_uris = [];
    foreach ($redirect_uris as $uri) {
        $trimmed = trim((string) $uri);
        if ($trimmed === '') continue;
        if (!koto_mcp_oauth_is_valid_redirect_uri($trimmed)) {
            return new WP_Error('invalid_redirect_uri', '無効なリダイレクトURIが含まれています: ' . $trimmed);
        }
        $valid_uris[] = esc_url_raw($trimmed);
    }
    $valid_uris = array_values(array_unique(array_filter($valid_uris)));
    if (empty($valid_uris)) {
        return new WP_Error('missing_redirect_uri', '有効なHTTPSリダイレクトURIを1つ以上入力してください。');
    }
    $norm_scope = koto_mcp_normalize_scope($default_scope);
    if (is_wp_error($norm_scope)) {
        return $norm_scope;
    }

    $client_id = 'koto_static_' . wp_generate_password(20, false, false);
    $client_secret = 'koto_sec_' . wp_generate_password(40, false, false);
    $record = [
        'client_id' => $client_id,
        'client_type' => 'confidential',
        'is_static' => true,
        'disabled' => false,
        'allow_no_pkce' => (bool) $allow_no_pkce,
        'default_scope' => $norm_scope,
        'name' => $name,
        'redirect_uris' => $valid_uris,
        'client_secret_hash' => koto_mcp_hash_client_secret($client_secret),
        'created_at' => time(),
    ];
    koto_mcp_storage_put('koto_mcp_client_' . $client_id, $record, 'no');

    $index = get_option('koto_mcp_static_clients', []);
    if (!is_array($index)) $index = [];
    $index[$client_id] = 1;
    update_option('koto_mcp_static_clients', $index, false);

    return [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'client' => $record,
    ];
}

function koto_mcp_list_static_oauth_clients()
{
    $index = get_option('koto_mcp_static_clients', []);
    if (!is_array($index) || empty($index)) {
        return [];
    }
    $clients = [];
    foreach (array_keys($index) as $client_id) {
        $record = koto_mcp_storage_get('koto_mcp_client_' . $client_id);
        if (is_array($record)) {
            $clients[$client_id] = $record;
        }
    }
    return $clients;
}

function koto_mcp_set_static_oauth_client_disabled($client_id, $disabled)
{
    $record = koto_mcp_storage_get('koto_mcp_client_' . $client_id);
    if (!is_array($record) || empty($record['is_static'])) {
        return false;
    }
    $record['disabled'] = (bool) $disabled;
    koto_mcp_storage_put('koto_mcp_client_' . $client_id, $record, 'no');
    return true;
}

function koto_mcp_delete_static_oauth_client($client_id)
{
    $client_id = sanitize_text_field((string) $client_id);
    $index = get_option('koto_mcp_static_clients', []);
    if (is_array($index) && isset($index[$client_id])) {
        unset($index[$client_id]);
        update_option('koto_mcp_static_clients', $index, false);
    }
    koto_mcp_storage_delete('koto_mcp_client_' . $client_id);
    delete_transient('koto_mcp_client_' . $client_id);
    return true;
}

/**
 * トークンリクエストから client_id および client_secret（Basic または POST）を抽出する。
 *
 * @param WP_REST_Request $request
 * @param array<string, mixed> $body
 * @return array{client_id: string, client_secret: string}|WP_Error
 */
function koto_mcp_oauth_extract_client_credentials($request, array $body)
{
    $body_id = sanitize_text_field((string) ($body['client_id'] ?? ''));
    $body_secret = isset($body['client_secret']) && is_string($body['client_secret']) ? $body['client_secret'] : '';

    $basic_id = '';
    $basic_secret = '';
    $has_basic = false;
    $auth_header = koto_mcp_get_authorization_header($request);
    if (stripos($auth_header, 'Basic ') === 0) {
        $decoded = base64_decode(substr(trim($auth_header), 6), true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            list($raw_id, $raw_secret) = explode(':', $decoded, 2);
            $basic_id = sanitize_text_field(rawurldecode($raw_id));
            $basic_secret = rawurldecode($raw_secret);
            $has_basic = true;
        }
    } elseif (!empty($_SERVER['PHP_AUTH_USER'])) {
        $basic_id = sanitize_text_field(rawurldecode((string) $_SERVER['PHP_AUTH_USER']));
        $basic_secret = isset($_SERVER['PHP_AUTH_PW']) ? rawurldecode((string) $_SERVER['PHP_AUTH_PW']) : '';
        $has_basic = true;
    }

    if ($has_basic && $body_secret !== '') {
        return new WP_Error('invalid_request', 'Multiple client authentication methods were used.', ['status' => 400]);
    }
    if ($has_basic && $body_id !== '' && $basic_id !== '' && $body_id !== $basic_id) {
        return new WP_Error('invalid_request', 'client_id mismatch between authorization header and request body.', ['status' => 400]);
    }

    return [
        'client_id' => $basic_id !== '' ? $basic_id : $body_id,
        'client_secret' => $has_basic ? $basic_secret : $body_secret,
    ];
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
    // 全てのWordPressログインユーザーが認可画面および申請フォームへアクセス可能
    return (int) $user_id > 0;
}

function koto_mcp_oauth_signon(array $credentials, $secure_cookie = '')
{
    global $wp_filter;

    $saved_hooks = [];
    foreach (['authenticate', 'wp_authenticate_user', 'wp_authenticate'] as $hook_name) {
        if (isset($wp_filter[$hook_name])) {
            $saved_hooks[$hook_name] = clone $wp_filter[$hook_name];
        }
    }

    $on_set_auth_cookie = static function ($auth_cookie, $expire = 0, $expiration = 0, $user_id = 0, $scheme = '') {
        $cookie_name = ($scheme === 'secure_auth') ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $_COOKIE[$cookie_name] = $auth_cookie;
    };
    $on_set_logged_in_cookie = static function ($logged_in_cookie) {
        $_COOKIE[LOGGED_IN_COOKIE] = $logged_in_cookie;
    };

    add_action('set_auth_cookie', $on_set_auth_cookie, 10, 5);
    add_action('set_logged_in_cookie', $on_set_logged_in_cookie, 10, 1);

    try {
        remove_all_filters('authenticate');
        remove_all_filters('wp_authenticate_user');
        remove_all_actions('wp_authenticate');

        add_filter('authenticate', 'wp_authenticate_username_password', 20, 3);
        add_filter('authenticate', 'wp_authenticate_email_password', 20, 3);
        if (function_exists('wp_authenticate_application_password')) {
            add_filter('authenticate', 'wp_authenticate_application_password', 20, 3);
        }
        add_filter('authenticate', 'wp_authenticate_spam_check', 99);

        return wp_signon($credentials, $secure_cookie);
    } finally {
        remove_action('set_auth_cookie', $on_set_auth_cookie, 10);
        remove_action('set_logged_in_cookie', $on_set_logged_in_cookie, 10);
        foreach (['authenticate', 'wp_authenticate_user', 'wp_authenticate'] as $hook_name) {
            if (isset($saved_hooks[$hook_name])) {
                $wp_filter[$hook_name] = $saved_hooks[$hook_name];
            } else {
                unset($wp_filter[$hook_name]);
            }
        }
    }
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
    echo '</form>';
    $collab_url = function_exists('koto_mcp_collaborate_page_url') ? koto_mcp_collaborate_page_url() : home_url('/collaborate/');
    echo '<p><a href="' . esc_url($collab_url) . '" target="_blank" rel="noopener">アカウントをお持ちでない方・協力登録はこちら</a></p>';
    echo '</body></html>';
    exit;
}

/**
 * 同意画面での「許可」アクション時の承認・申請判定処理
 *
 * @param int $user_id ユーザーID
 * @return array{approved: bool, is_revoked?: bool, message_title?: string, message_body?: string}
 */
function koto_mcp_oauth_process_consent_approval($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return ['approved' => false];
    }

    if (koto_mcp_is_user_approved($user_id)) {
        return ['approved' => true];
    }

    $user = get_userdata($user_id);
    if (!$user) {
        return ['approved' => false];
    }

    $is_revoked = (bool) get_user_meta($user_id, '_koto_mcp_revoked', true);
    $is_admin_or_editor = (bool) array_intersect(['administrator', 'editor'], (array) $user->roles);

    // 未剥奪の管理者・編集者のみ即時タグ付与して承認
    if (!$is_revoked && $is_admin_or_editor) {
        update_user_meta($user_id, '_koto_mcp_approved', 1);
        koto_mcp_clear_request_meta($user_id);
        return ['approved' => true];
    }

    // 剥奪済みの編集者・管理者（自己復活禁止）、または一般ユーザー:
    // 申請メタ（フラグ＋日時）を保存し、承認待ち状態を返す
    koto_mcp_record_request_meta($user_id);

    return [
        'approved'      => false,
        'is_revoked'    => $is_revoked,
        'message_title' => $is_revoked ? 'MCP利用再開の申請を受け付けました' : 'MCP利用申請を受け付けました',
        'message_body'  => $is_revoked
            ? 'このアカウントのMCP利用は現在停止されています。再開には管理者による承認が必要です。'
            : 'MCPの利用申請を受け付けました。現在、管理者の承認待ちです。',
    ];
}

function koto_mcp_oauth_authorize_browser()
{
    $params = wp_unslash($_REQUEST);
    $client_id = sanitize_text_field($params['client_id'] ?? '');
    $client = koto_mcp_oauth_resolve_client($client_id);
    if (!is_array($client)) {
        koto_mcp_oauth_browser_error('invalid_client', 'Unknown or disabled OAuth client.', 400);
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

    $default_scope = (string) ($client['default_scope'] ?? 'validate preview');
    $scope = koto_mcp_normalize_scope($params['scope'] ?? '', $default_scope);
    if (is_wp_error($scope)) {
        koto_mcp_oauth_redirect_error($redirect, 'invalid_scope', $scope->get_error_message(), $state);
    }

    $requested_resource = isset($params['resource']) && is_string($params['resource']) ? trim($params['resource']) : '';
    if (!koto_mcp_oauth_is_valid_resource($requested_resource)) {
        koto_mcp_oauth_redirect_error($redirect, 'invalid_target', 'Requested resource indicator does not match this MCP server.', $state);
    }
    $resolved_resource = $requested_resource !== '' ? koto_mcp_oauth_canonical_resource_uri($requested_resource) : koto_mcp_server_url();

    $challenge = sanitize_text_field($params['code_challenge'] ?? '');
    $challenge_method = sanitize_text_field($params['code_challenge_method'] ?? ($challenge !== '' ? 'S256' : ''));
    $allow_no_pkce = (($client['client_type'] ?? 'public') === 'confidential') && !empty($client['allow_no_pkce']);
    if ($challenge !== '') {
        if ($challenge_method !== 'S256') {
            koto_mcp_oauth_redirect_error($redirect, 'invalid_request', 'code_challenge_method=S256 is required.', $state);
        }
    } elseif (!$allow_no_pkce) {
        koto_mcp_oauth_redirect_error($redirect, 'invalid_request', 'code_challenge and code_challenge_method=S256 are required.', $state);
    }

    $request_fields = koto_mcp_oauth_request_fields($params);
    $request_fields['response_type'] = 'code';
    $request_fields['client_id'] = $client_id;
    $request_fields['redirect_uri'] = $redirect;
    $request_fields['code_challenge'] = $challenge;
    $request_fields['code_challenge_method'] = $challenge !== '' ? 'S256' : '';
    $request_fields['scope'] = $scope;
    $request_fields['state'] = $state;
    $request_fields['resource'] = $requested_resource;

    if (!is_user_logged_in()) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && ($params['koto_mcp_login_action'] ?? '') === 'login') {
            if (!wp_verify_nonce((string) ($params['_wpnonce'] ?? ''), 'koto_mcp_oauth_login')) {
                koto_mcp_oauth_render_login($request_fields, 'ログインフォームの有効期限が切れました。もう一度お試しください。');
            }
            $raw_login = (isset($_POST['log']) && is_string($_POST['log']))
                ? wp_unslash($_POST['log'])
                : (string) ($params['log'] ?? '');
            $username = sanitize_text_field($raw_login);
            if (koto_mcp_oauth_login_is_rate_limited($username)) {
                koto_mcp_oauth_render_login($request_fields, 'ログイン試行が多すぎます。15分後に再試行してください。');
            }
            $raw_password = (isset($_POST['pwd']) && is_string($_POST['pwd']))
                ? $_POST['pwd']
                : (string) ($params['pwd'] ?? '');
            $user = koto_mcp_oauth_signon([
                'user_login' => $raw_login,
                'user_password' => $raw_password,
                'remember' => !empty($params['rememberme']),
            ], is_ssl());
            if (is_wp_error($user)) {
                error_log(sprintf(
                    '[Koto MCP OAuth] wp_signon failed for user "%s": code=%s message=%s',
                    $username,
                    (string) $user->get_error_code(),
                    wp_strip_all_tags((string) $user->get_error_message())
                ));
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

    // 同意画面（許可 / 拒否）の表示および送信処理
    // 申請の入り口はAIクライアントからのフォームのみとし、許可ボタン押下が申請アクションを兼ねる
    $user = wp_get_current_user();
    $user_id = (int) $user->ID;

    $nonce_action = 'koto_mcp_oauth_consent|' . $user_id . '|' . $client_id . '|' . $redirect . '|' . $scope . '|' . $challenge . '|' . $state . '|' . ($request_fields['resource'] ?? '');
    $is_consent_post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
        && isset($params['koto_mcp_consent_action']);

    if (!$is_consent_post) {
        status_header(200);
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        echo '<!doctype html><html><head><meta charset="utf-8"><title>MCP クライアント接続の確認</title></head><body>';
        echo '<h1>MCP クライアント接続の確認</h1>';
        echo '<p>ログインユーザー: ' . esc_html($user->user_login) . '</p>';
        echo '<p>クライアント: ' . esc_html((string) ($client['name'] ?? $client_id)) . '</p>';
        echo '<p>リダイレクトURI: ' . esc_html($redirect) . '</p>';
        echo '<p>要求スコープ: ' . esc_html($scope) . '</p>';
        echo '<p>※MCP経由の投稿作成は常に下書きとなり、作者はあなたになります。公開権限はAIには引き継がれません。</p>';
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

    // 許可ボタン押下後の判定フロー
    $consent_result = koto_mcp_oauth_process_consent_approval($user_id);
    if (empty($consent_result['approved'])) {
        $message_title = $consent_result['message_title'] ?? 'MCP利用申請を受け付けました';
        $message_body  = $consent_result['message_body'] ?? 'MCPの利用申請を受け付けました。現在、管理者の承認待ちです。';

        status_header(200);
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($message_title) . '</title></head><body>';
        echo '<h1>' . esc_html($message_title) . '</h1>';
        echo '<p>' . esc_html($message_body) . '</p>';
        echo '<p>承認が完了しましたらご登録のメールアドレス（' . esc_html($user->user_email) . '）宛てに通知が届きます。通知確認後、再度お使いのAIクライアントから接続を行ってください。</p>';
        echo '<p><button onclick="window.close();">画面を閉じる</button></p>';
        echo '</body></html>';
        exit;
    }

    $code = wp_generate_password(48, false, false);
    $code_data = [
        'user_id' => get_current_user_id(),
        'client_id' => $client_id,
        'redirect_uri' => $redirect,
        'challenge' => $challenge,
        'resource' => $resolved_resource,
        'scope' => $scope,
        'family_id' => wp_generate_password(32, false, false),
        'expires_at' => time() + 300,
    ];
    $code_hash = hash('sha256', $code);
    koto_mcp_storage_put('koto_mcp_code_' . $code_hash, $code_data, 'no');
    set_transient('koto_mcp_code_' . $code_hash, $code_data, 300);
    koto_mcp_oauth_safe_redirect(add_query_arg(['code' => $code, 'state' => $state], $redirect));
}

function koto_mcp_oauth_body_params($request)
{
    $params = [];
    if ($request instanceof WP_REST_Request) {
        $params = $request->get_params();
        $json = $request->get_json_params();
        if (is_array($json)) {
            $params = array_merge($params, $json);
        }
    }
    if (!empty($_POST) && is_array($_POST)) {
        $params = array_merge($params, wp_unslash($_POST));
    }
    return $params;
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

function koto_mcp_revoke_refresh_family($family_id, $reason = 'manual')
{
    if (!$family_id) return;
    $family_option = koto_mcp_refresh_family_option_name($family_id);
    $family = koto_mcp_storage_get($family_option);
    if (!is_array($family)) {
        $family = get_option($family_option);
    }
    if (!is_array($family)) return;

    koto_mcp_log_token_event('family_revoked', [
        'family_id' => (string) $family_id,
        'reason' => $reason,
        'previous_status' => $family['status'] ?? 'active',
    ]);

    $tokens = is_array($family['tokens'] ?? null) ? $family['tokens'] : [];
    foreach (array_keys($tokens) as $token_hash) {
        $record_option = koto_mcp_refresh_record_option_name((string) $token_hash);
        $record = koto_mcp_storage_get($record_option);
        if (!is_array($record)) {
            $record = get_option($record_option);
        }
        if (is_array($record)) {
            $record['status'] = 'revoked';
            $record['revoked_at'] = time();
            $record['revocation_reason'] = $reason;
            koto_mcp_storage_put($record_option, $record, 'no');
            update_option($record_option, $record, false);
        }
    }

    // ファミリーに紐づくアクティブなアクセストークンも即時無効化
    if (!empty($family['active_access_hash'])) {
        $access_key = 'koto_mcp_token_' . $family['active_access_hash'];
        $access_data = koto_mcp_storage_get($access_key);
        if (is_array($access_data)) {
            $access_data['status'] = 'revoked';
            $access_data['revoked_at'] = time();
            $access_data['revocation_reason'] = $reason;
            koto_mcp_storage_put($access_key, $access_data, 'no');
        }
        delete_transient($access_key);
    }

    $family['status'] = 'revoked';
    $family['updated_at'] = time();
    $family['revocation_reason'] = $reason;
    koto_mcp_storage_put($family_option, $family, 'no');
    update_option($family_option, $family, false);
}

function koto_mcp_issue_token($user_id, $scope, $client_id, $family_id = null, $aud = '')
{
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !koto_mcp_is_user_approved($user_id)) {
        return koto_mcp_oauth_token_error('invalid_grant', 'The WordPress user is not approved for MCP access.', 400);
    }

    $family_id = $family_id ?: wp_generate_password(32, false, false);
    $aud = $aud !== '' ? koto_mcp_oauth_canonical_resource_uri($aud) : koto_mcp_oauth_canonical_resource_uri(koto_mcp_server_url());
    $access_ttl = defined('KOTO_MCP_ACCESS_TOKEN_TTL') ? (int) KOTO_MCP_ACCESS_TOKEN_TTL : HOUR_IN_SECONDS;
    $refresh_ttl = defined('KOTO_MCP_REFRESH_TOKEN_TTL') ? (int) KOTO_MCP_REFRESH_TOKEN_TTL : (30 * DAY_IN_SECONDS);
    $now = time();

    // HMAC-SHA256 自己検証署名付きトークンの生成
    $access = koto_mcp_create_signed_token('at', [
        'uid' => (int) $user_id,
        'scp' => $scope,
        'cid' => (string) $client_id,
        'fid' => (string) $family_id,
        'aud' => $aud,
        'iat' => $now,
        'exp' => $now + $access_ttl,
    ]);
    $refresh = koto_mcp_create_signed_token('rt', [
        'uid' => (int) $user_id,
        'scp' => $scope,
        'cid' => (string) $client_id,
        'fid' => (string) $family_id,
        'aud' => $aud,
        'iat' => $now,
        'exp' => $now + $refresh_ttl,
    ]);

    // ユーザーメタ _koto_mcp_family_ids にインデックスを追加
    $user_families = get_user_meta((int) $user_id, '_koto_mcp_family_ids', true);
    if (!is_array($user_families)) {
        $user_families = [];
    }
    if (!in_array((string) $family_id, $user_families, true)) {
        $user_families[] = (string) $family_id;
        update_user_meta((int) $user_id, '_koto_mcp_family_ids', $user_families);
    }

    $access_hash = hash('sha256', $access);
    $refresh_hash = hash('sha256', $refresh);

    $access_data = [
        'user_id'    => (int) $user_id,
        'scope'      => $scope,
        'client_id'  => $client_id,
        'family_id'  => $family_id,
        'aud'        => $aud,
        'status'     => 'active',
        'issued_at'  => $now,
        'expires_at' => $now + $access_ttl,
    ];

    // direct DB ストレージへ永続化（transient にも同期保存）
    koto_mcp_storage_put('koto_mcp_token_' . $access_hash, $access_data, 'no');
    set_transient('koto_mcp_token_' . $access_hash, $access_data, $access_ttl);

    $refresh_data = [
        'user_id'    => (int) $user_id,
        'scope'      => $scope,
        'client_id'  => $client_id,
        'family_id'  => $family_id,
        'aud'        => $aud,
        'status'     => 'active',
        'issued_at'  => $now,
        'expires_at' => $now + $refresh_ttl,
    ];
    $record_option = koto_mcp_refresh_record_option_name($refresh_hash);
    koto_mcp_storage_put($record_option, $refresh_data, 'no');
    update_option($record_option, $refresh_data, false);

    $family_option = koto_mcp_refresh_family_option_name($family_id);
    $family = koto_mcp_storage_get($family_option);
    if (!is_array($family)) {
        $family = get_option($family_option);
    }
    if (!is_array($family)) {
        $family = ['status' => 'active', 'tokens' => [], 'updated_at' => $now];
    }
    if (($family['status'] ?? 'active') !== 'active') {
        koto_mcp_log_token_event('token_issue_rejected', [
            'family_id' => $family_id,
            'reason' => 'family_revoked',
        ]);
        return koto_mcp_oauth_token_error('invalid_grant', 'The token family is revoked.', 400);
    }

    $response_data = [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => $access_ttl,
        'refresh_token' => $refresh,
        'scope'         => $scope,
    ];

    $family['tokens'][$refresh_hash] = 1;
    $family['active_access_hash'] = $access_hash;
    $family['active_refresh_hash'] = $refresh_hash;
    $family['last_issued'] = $response_data;
    $family['last_rotated_at'] = $now;
    $family['updated_at'] = $now;

    koto_mcp_storage_put($family_option, $family, 'no');
    update_option($family_option, $family, false);

    koto_mcp_log_token_event('token_issued', [
        'access_id' => koto_mcp_short_token_id($access),
        'refresh_id' => koto_mcp_short_token_id($refresh),
        'family_id' => $family_id,
        'user_id' => (int) $user_id,
        'client_id' => $client_id ?: 'none',
        'expires_in' => $access_ttl,
        'has_refresh_token' => true,
    ]);

    $response = rest_ensure_response($response_data);
    if ($response instanceof WP_REST_Response) {
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
        $response->header('Access-Control-Allow-Origin', '*');
    }
    koto_mcp_maybe_cleanup_expired_options();
    return $response;
}

function koto_mcp_oauth_token($request)
{
    $body = koto_mcp_oauth_body_params($request);
    $grant_type = sanitize_text_field((string) ($body['grant_type'] ?? ''));

    $creds = koto_mcp_oauth_extract_client_credentials($request, $body);
    if (is_wp_error($creds)) {
        return koto_mcp_oauth_token_error($creds->get_error_code(), $creds->get_error_message(), 400);
    }
    $client_id = $creds['client_id'];
    $client_secret = $creds['client_secret'];

    $requested_resource = isset($body['resource']) && is_string($body['resource']) ? trim($body['resource']) : '';
    if (!koto_mcp_oauth_is_valid_resource($requested_resource)) {
        return koto_mcp_oauth_token_error('invalid_target', 'Requested resource does not match this MCP server.', 400);
    }

    if ($grant_type === 'refresh_token') {
        $refresh_token = (string) ($body['refresh_token'] ?? '');
        if ($refresh_token === '') {
            return koto_mcp_oauth_token_error('invalid_request', 'refresh_token is required.', 400);
        }

        $refresh_hash = hash('sha256', $refresh_token);
        $refresh_id = koto_mcp_short_token_id($refresh_hash, true);

        // リフレッシュレコードの探索（direct DB -> get_option -> HMAC署名復元）
        $record_option = koto_mcp_refresh_record_option_name($refresh_hash);
        $refresh = koto_mcp_storage_get($record_option);
        if (!is_array($refresh)) {
            $refresh = get_option($record_option);
        }
        if (!is_array($refresh)) {
            $verified_rt = koto_mcp_verify_signed_token($refresh_token);
            if (is_array($verified_rt) && ($verified_rt['t'] ?? '') === 'rt' && !empty($verified_rt['uid'])) {
                $refresh = [
                    'user_id'    => (int) $verified_rt['uid'],
                    'scope'      => (string) ($verified_rt['scp'] ?? 'validate preview'),
                    'client_id'  => (string) ($verified_rt['cid'] ?? ''),
                    'family_id'  => (string) ($verified_rt['fid'] ?? ''),
                    'aud'        => (string) ($verified_rt['aud'] ?? ''),
                    'status'     => 'active',
                    'issued_at'  => (int) ($verified_rt['iat'] ?? time()),
                    'expires_at' => (int) ($verified_rt['exp'] ?? (time() + 30 * DAY_IN_SECONDS)),
                ];
                koto_mcp_storage_put($record_option, $refresh, 'no');
            }
        }

        if (!is_array($refresh)) {
            koto_mcp_log_token_event('token_request', [
                'grant_type' => 'refresh_token',
                'refresh_id' => $refresh_id,
                'result' => 'refresh_failed',
                'reason' => 'invalid_refresh_token',
            ]);
            return koto_mcp_oauth_token_error('invalid_grant', 'Invalid refresh token.', 400);
        }

        $family_id = (string) ($refresh['family_id'] ?? '');
        if ($family_id === '') {
            return koto_mcp_oauth_token_error('invalid_grant', 'Invalid refresh token family.', 400);
        }

        // ファミリー単位での真の SQL アトミックロックを取得（並行リクエストの完全直列化 & 503防止）
        $lock_name = 'fam_' . substr(hash('sha256', $family_id), 0, 24);
        if (!koto_mcp_acquire_atomic_lock($lock_name, 5.0)) {
            koto_mcp_log_token_event('token_request', [
                'grant_type' => 'refresh_token',
                'refresh_id' => $refresh_id,
                'family_id' => $family_id,
                'client_id' => $client_id ?: 'none',
                'result' => 'refresh_locked',
            ]);
            return koto_mcp_oauth_token_error('temporarily_unavailable', 'Refresh token is busy. Please retry.', 503);
        }

        try {
            // ロック取得後、DBから最新のファミリーおよびリフレッシュレコードを再読み込み
            $family_option = koto_mcp_refresh_family_option_name($family_id);
            $family = koto_mcp_storage_get($family_option);
            if (!is_array($family)) {
                $family = get_option($family_option);
            }
            $refresh = koto_mcp_storage_get($record_option);
            if (!is_array($refresh)) {
                $refresh = get_option($record_option);
            }

            // client_id 整合性検証
            $expected_client_id = (string) ($refresh['client_id'] ?? '');
            if ($client_id !== '' && $expected_client_id !== '' && $client_id !== $expected_client_id) {
                koto_mcp_log_token_event('token_request', [
                    'grant_type' => 'refresh_token',
                    'refresh_id' => $refresh_id,
                    'family_id' => $family_id,
                    'result' => 'refresh_failed',
                    'reason' => 'client_binding_mismatch',
                ]);
                return koto_mcp_oauth_token_error('invalid_grant', 'Refresh token client binding mismatch.', 400);
            }
            $effective_client_id = $client_id !== '' ? $client_id : $expected_client_id;
            if ($effective_client_id !== '') {
                $raw_client = koto_mcp_storage_get('koto_mcp_client_' . $effective_client_id);
                if (!is_array($raw_client)) {
                    $raw_client = get_transient('koto_mcp_client_' . $effective_client_id);
                }
                if (is_array($raw_client)) {
                    if (!empty($raw_client['disabled'])) {
                        return koto_mcp_oauth_token_error('invalid_client', 'Client is disabled.', 401);
                    }
                    if (($raw_client['client_type'] ?? 'public') === 'confidential' && !koto_mcp_verify_client_secret($raw_client, $client_secret)) {
                        return koto_mcp_oauth_token_error('invalid_client', 'Invalid client credentials.', 401);
                    }
                }
            }

            $stored_aud = (string) ($refresh['aud'] ?? '');
            if ($requested_resource !== '' && $stored_aud !== '' && koto_mcp_oauth_canonical_resource_uri($requested_resource) !== koto_mcp_oauth_canonical_resource_uri($stored_aud)) {
                return koto_mcp_oauth_token_error('invalid_target', 'Requested resource does not match token audience.', 400);
            }
            $effective_aud = $requested_resource !== '' ? koto_mcp_oauth_canonical_resource_uri($requested_resource) : $stored_aud;

            $now = time();

            // 期限切れチェック（30日）
            if (!empty($refresh['expires_at']) && (int) $refresh['expires_at'] < $now) {
                koto_mcp_log_token_event('token_request', [
                    'grant_type' => 'refresh_token',
                    'refresh_id' => $refresh_id,
                    'family_id' => $family_id,
                    'result' => 'refresh_failed',
                    'reason' => 'refresh_token_expired',
                ]);
                return koto_mcp_oauth_token_error('invalid_grant', 'Refresh token expired.', 400);
            }

            // ファミリー失効チェック
            if (!is_array($family) || ($family['status'] ?? '') !== 'active') {
                koto_mcp_log_token_event('token_request', [
                    'grant_type' => 'refresh_token',
                    'refresh_id' => $refresh_id,
                    'family_id' => $family_id,
                    'result' => 'refresh_failed',
                    'reason' => 'family_revoked',
                ]);
                return koto_mcp_oauth_token_error('invalid_grant', 'Refresh token family is revoked.', 400);
            }

            // ユーザーの存在および承認状態の最優先検証
            // 剥奪済みユーザーにはデバウンス返却も行わず、即座にファミリーを失効させて拒否する
            $refresh_user_id = (int) ($refresh['user_id'] ?? 0);
            $refresh_user = get_userdata($refresh_user_id);
            if (!$refresh_user || !koto_mcp_is_user_approved($refresh_user_id)) {
                koto_mcp_log_token_event('token_request', [
                    'grant_type' => 'refresh_token',
                    'refresh_id' => $refresh_id,
                    'family_id' => $family_id,
                    'result' => 'refresh_failed',
                    'reason' => 'user_unauthorized',
                ]);
                koto_mcp_revoke_refresh_family($family_id, 'user_unauthorized');
                return koto_mcp_oauth_token_error('invalid_grant', 'The WordPress user is no longer authorized for MCP access.', 400);
            }

            // 【15秒デバウンスウィンドウ】
            // 直近 15 秒以内に同ファミリーでローテーションが行われ、最新トークンペアが保持されている場合、
            // 並行ワーカーやクライアントの再試行と判定。
            // ただし、キャッシュされた最新アクセストークンが現在も有効（未失効かつ30秒以上の残寿命あり）である場合のみデバウンス返却する。
            $last_rotated = (int) ($family['last_rotated_at'] ?? 0);
            if ($last_rotated > 0 && ($now - $last_rotated) <= 15 && !empty($family['last_issued']) && is_array($family['last_issued'])) {
                $cached_at_hash = (string) ($family['active_access_hash'] ?? '');
                $cached_at_record = $cached_at_hash !== '' ? koto_mcp_storage_get('koto_mcp_token_' . $cached_at_hash) : null;
                $is_cached_valid = is_array($cached_at_record)
                    && ($cached_at_record['status'] ?? '') === 'active'
                    && !empty($cached_at_record['expires_at'])
                    && (int) $cached_at_record['expires_at'] > ($now + 30);

                if ($is_cached_valid) {
                    koto_mcp_log_token_event('token_request', [
                        'grant_type' => 'refresh_token',
                        'refresh_id' => $refresh_id,
                        'family_id' => $family_id,
                        'result' => 'refresh_debounce_hit',
                        'rotated_ago_sec' => $now - $last_rotated,
                    ]);
                    $cached_response = rest_ensure_response($family['last_issued']);
                    if ($cached_response instanceof WP_REST_Response) {
                        $cached_response->header('Cache-Control', 'no-store');
                        $cached_response->header('Pragma', 'no-cache');
                        $cached_response->header('Access-Control-Allow-Origin', '*');
                    }
                    return $cached_response;
                }
            }

            // 【非破壊安全ローテーション】
            // 提示されたリフレッシュトークンが既に rotated 状態であっても、
            // ファミリーが active であり、トークンの発行期限内（30日以内）であれば、
            // replay_detected でファミリーを道連れ即死させず、安全に最新セッションへ再発行して継続させる。
            $is_already_rotated = (($refresh['status'] ?? '') === 'rotated');

            // スコープの解決（指定があれば元のスコープ内で絞り込み、なければ元のスコープを維持）
            $requested_scope = sanitize_text_field((string) ($body['scope'] ?? ''));
            $final_scope = (string) $refresh['scope'];
            if ($requested_scope !== '') {
                $orig_scopes = koto_mcp_scope_string_to_list($final_scope);
                $new_scopes = koto_mcp_scope_string_to_list($requested_scope);
                if (empty(array_diff($new_scopes, $orig_scopes))) {
                    $final_scope = implode(' ', $new_scopes);
                }
            }

            $token_response = koto_mcp_issue_token($refresh_user_id, $final_scope, $effective_client_id, $family_id, $effective_aud);
            if (is_wp_error($token_response)) {
                return $token_response;
            }
            $issued_data = $token_response instanceof WP_REST_Response ? $token_response->get_data() : $token_response;
            $new_refresh_token = (string) ($issued_data['refresh_token'] ?? '');
            $new_refresh_hash = hash('sha256', $new_refresh_token);

            $refresh['status'] = 'rotated';
            $refresh['rotated_at'] = $now;
            $refresh['rotated_to'] = $new_refresh_hash;
            $refresh['last_issued'] = is_array($issued_data) ? $issued_data : null;
            koto_mcp_storage_put($record_option, $refresh, 'no');
            update_option($record_option, $refresh, false);

            koto_mcp_log_token_event('token_request', [
                'grant_type' => 'refresh_token',
                'refresh_id' => $refresh_id,
                'new_refresh_id' => koto_mcp_short_token_id($new_refresh_hash, true),
                'family_id' => $family_id,
                'was_rotated' => $is_already_rotated,
                'result' => 'refresh_success',
            ]);

            return $token_response;
        } finally {
            koto_mcp_release_atomic_lock($lock_name);
        }
    }

    if ($grant_type !== 'authorization_code') {
        koto_mcp_log_token_event('token_request', [
            'grant_type' => $grant_type ?: 'none',
            'result' => 'unsupported_grant_type',
        ]);
        return koto_mcp_oauth_token_error('unsupported_grant_type', 'Only authorization_code and refresh_token grants are supported.', 400);
    }

    $code = (string) ($body['code'] ?? '');
    $redirect_uri = esc_url_raw((string) ($body['redirect_uri'] ?? ''));
    if ($code === '' || $client_id === '' || $redirect_uri === '') {
        return koto_mcp_oauth_token_error('invalid_request', 'code, client_id, and redirect_uri are required.', 400);
    }

    $code_hash = hash('sha256', $code);
    $code_lock = 'code_' . substr($code_hash, 0, 24);
    if (!koto_mcp_acquire_atomic_lock($code_lock, 5.0)) {
        return koto_mcp_oauth_token_error('invalid_grant', 'Authorization code is already being processed.', 400);
    }

    try {
        $code_key = 'koto_mcp_code_' . $code_hash;
        $data = koto_mcp_storage_get($code_key);
        if (!is_array($data)) {
            $data = get_transient($code_key);
        }
        koto_mcp_storage_delete($code_key);
        delete_transient($code_key);

        if (!is_array($data)) {
            return koto_mcp_oauth_token_error('invalid_grant', 'Invalid authorization code.', 400);
        }
        if (!empty($data['expires_at']) && (int) $data['expires_at'] < time()) {
            return koto_mcp_oauth_token_error('invalid_grant', 'Authorization code expired.', 400);
        }
        if (($data['client_id'] ?? '') !== $client_id || ($data['redirect_uri'] ?? '') !== $redirect_uri) {
            return koto_mcp_oauth_token_error('invalid_grant', 'Authorization code client/redirect binding mismatch.', 400);
        }

        $client_record = koto_mcp_storage_get('koto_mcp_client_' . $client_id);
        if (!is_array($client_record)) {
            $client_record = get_transient('koto_mcp_client_' . $client_id);
        }
        $is_confidential = is_array($client_record) && ($client_record['client_type'] ?? 'public') === 'confidential';
        if (is_array($client_record) && !empty($client_record['disabled'])) {
            return koto_mcp_oauth_token_error('invalid_client', 'Client is disabled.', 401);
        }
        $secret_verified = false;
        if ($is_confidential) {
            if (!koto_mcp_verify_client_secret($client_record, $client_secret)) {
                return koto_mcp_oauth_token_error('invalid_client', 'Invalid client credentials.', 401);
            }
            $secret_verified = true;
        }

        $verifier = (string) ($body['code_verifier'] ?? '');
        $stored_challenge = (string) ($data['challenge'] ?? '');
        if ($stored_challenge !== '') {
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!$verifier || !hash_equals($stored_challenge, $challenge)) {
                return koto_mcp_oauth_token_error('invalid_grant', 'PKCE verification failed.', 400);
            }
        } elseif (!($is_confidential && !empty($client_record['allow_no_pkce']) && $secret_verified)) {
            return koto_mcp_oauth_token_error('invalid_grant', 'PKCE verification failed.', 400);
        }

        $code_resource = (string) ($data['resource'] ?? '');
        if ($requested_resource !== '' && $code_resource !== '' && koto_mcp_oauth_canonical_resource_uri($requested_resource) !== koto_mcp_oauth_canonical_resource_uri($code_resource)) {
            return koto_mcp_oauth_token_error('invalid_target', 'Requested resource does not match authorization code target.', 400);
        }
        $effective_aud = $requested_resource !== '' ? koto_mcp_oauth_canonical_resource_uri($requested_resource) : $code_resource;

        $code_user_id = (int) ($data['user_id'] ?? 0);
        if ($code_user_id <= 0 || !koto_mcp_is_user_approved($code_user_id)) {
            koto_mcp_log_token_event('token_request', [
                'grant_type' => 'authorization_code',
                'client_id'  => $client_id,
                'user_id'    => $code_user_id,
                'result'     => 'code_exchange_failed',
                'reason'     => 'user_unauthorized',
            ]);
            return koto_mcp_oauth_token_error('invalid_grant', 'The WordPress user is no longer authorized for MCP access.', 400);
        }

        return koto_mcp_issue_token($code_user_id, (string) $data['scope'], $client_id, (string) ($data['family_id'] ?? ''), $effective_aud);
    } finally {
        koto_mcp_release_atomic_lock($code_lock);
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
    $family_id_to_revoke = '';

    // 1. アクセストークンとしての失効処理
    $access_key = 'koto_mcp_token_' . $token_hash;
    $access_data = koto_mcp_storage_get($access_key);
    if (!is_array($access_data)) {
        $access_data = get_transient($access_key);
    }
    if (!is_array($access_data)) {
        $verified_at = koto_mcp_verify_signed_token($token);
        if (is_array($verified_at) && ($verified_at['t'] ?? '') === 'at') {
            $access_data = [
                'user_id'   => $verified_at['uid'],
                'client_id' => $verified_at['cid'],
                'family_id' => $verified_at['fid'],
                'status'    => 'active',
            ];
        }
    }
    if (is_array($access_data)) {
        if ($client_id === '' || ($access_data['client_id'] ?? '') === $client_id) {
            $access_data['status'] = 'revoked';
            $access_data['revoked_at'] = time();
            $access_data['revocation_reason'] = 'manual';
            koto_mcp_storage_put($access_key, $access_data, 'no');
            delete_transient($access_key);
            if (!empty($access_data['family_id'])) {
                $family_id_to_revoke = (string) $access_data['family_id'];
            }
        }
    }

    // 2. リフレッシュトークンとしての失効処理
    $refresh_option = koto_mcp_refresh_record_option_name($token_hash);
    $refresh = koto_mcp_storage_get($refresh_option);
    if (!is_array($refresh)) {
        $refresh = get_option($refresh_option);
    }
    if (!is_array($refresh)) {
        $verified_rt = koto_mcp_verify_signed_token($token);
        if (is_array($verified_rt) && ($verified_rt['t'] ?? '') === 'rt') {
            $refresh = [
                'client_id' => $verified_rt['cid'],
                'family_id' => $verified_rt['fid'],
                'status'    => 'active',
            ];
        }
    }
    if (is_array($refresh)) {
        if ($client_id !== '' && ($refresh['client_id'] ?? '') !== $client_id) {
            return new WP_Error('invalid_grant', 'Token client binding mismatch.', ['status' => 400]);
        }
        $refresh['status'] = 'revoked';
        $refresh['revoked_at'] = time();
        $refresh['revocation_reason'] = 'manual';
        koto_mcp_storage_put($refresh_option, $refresh, 'no');
        update_option($refresh_option, $refresh, false);
        delete_transient('koto_mcp_refresh_' . $token_hash);
        if (!empty($refresh['family_id'])) {
            $family_id_to_revoke = (string) $refresh['family_id'];
        }
    }

    // 3. 関連するファミリー全体を明示的失効
    if ($family_id_to_revoke !== '') {
        koto_mcp_revoke_refresh_family($family_id_to_revoke, 'manual');
    }

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

    $revoked_families = [];

    // 1. ユーザーメタ _koto_mcp_family_ids に登録された全ファミリーを失効
    $user_families = get_user_meta($user_id, '_koto_mcp_family_ids', true);
    if (is_array($user_families)) {
        foreach ($user_families as $fid) {
            $fid = (string) $fid;
            if ($fid !== '' && !isset($revoked_families[$fid])) {
                koto_mcp_revoke_refresh_family($fid, 'admin_revoked');
                $revoked_families[$fid] = true;
            }
        }
    }

    // 2. 移行前データや未インデックス分のフォールバック走査（全件確実に無効化）
    $like = $wpdb->esc_like('koto_mcp_refresh_') . '%';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
        $like,
        $wpdb->esc_like('koto_mcp_refresh_family_') . '%',
        $wpdb->esc_like('koto_mcp_refresh_lock_') . '%'
    ));

    if (is_array($results)) {
        foreach ($results as $row) {
            $val = maybe_unserialize($row->option_value);
            if (is_array($val) && (int) ($val['user_id'] ?? 0) === $user_id) {
                $family_id = (string) ($val['family_id'] ?? '');
                if ($family_id !== '' && !isset($revoked_families[$family_id])) {
                    koto_mcp_revoke_refresh_family($family_id, 'admin_revoked');
                    $revoked_families[$family_id] = true;
                }
            }
        }
    }
}
