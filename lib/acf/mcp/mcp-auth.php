<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP 認証・権限管理モジュール
 *
 * OAuth Bearerトークン検証、Application Passwords認証、
 * ユーザー権限・承認チェック、スコープ制御、WWW-Authenticateヘッダー生成を提供します。
 */

// OAuth エンドポイント（/mcp/oauth/*）宛てリクエストで WP Core の Application Passwords が Basic 認証ヘッダーを誤爆横取りするのを防止
add_filter('application_password_is_api_request', static function ($is_api_request) {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, '/mcp/oauth/') !== false) {
        return false;
    }
    return $is_api_request;
}, 10, 1);

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

function koto_mcp_is_user_approved($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return false;
    }
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    $roles = (array) $user->roles;
    if (array_intersect(['administrator', 'editor'], $roles)) {
        return true;
    }
    if (get_user_meta($user_id, '_koto_mcp_approved', true)) {
        return true;
    }
    if (in_array('mcp_user', $roles, true) && !get_user_meta($user_id, '_koto_mcp_revoked', true)) {
        return true;
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
    if (!koto_mcp_is_user_approved($user_id)) {
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

function koto_mcp_short_token_id($token_or_hash, $is_hash = false)
{
    $str = (string) $token_or_hash;
    if ($str === '') {
        return 'none';
    }
    $hash = $is_hash ? $str : hash('sha256', $str);
    return substr($hash, 0, 12);
}

function koto_mcp_log_token_event($event, array $context = [])
{
    $parts = [
        'event=' . sanitize_key((string) $event),
        'ts=' . gmdate('Y-m-d\TH:i:s\Z'),
    ];
    foreach ($context as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        } elseif (is_array($v) || is_object($v)) {
            $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $v = (string) $v;
        }
        $v = str_replace(["\r", "\n"], ' ', $v);
        $parts[] = $k . '=' . $v;
    }
    error_log('[Koto MCP OAuth] ' . implode(' ', $parts));
}

function koto_mcp_oauth_token_error($error, $description, $status = 400)
{
    koto_mcp_log_token_event('token_error_response', [
        'error' => $error,
        'description' => $description,
        'status' => $status,
    ]);
    $response = new WP_REST_Response([
        'error' => sanitize_key($error),
        'error_description' => sanitize_text_field($description),
    ], (int) $status);
    $response->header('Content-Type', 'application/json; charset=' . get_bloginfo('charset'));
    $response->header('Cache-Control', 'no-store');
    $response->header('Pragma', 'no-cache');
    return $response;
}

/**
 * アクセストークン文字列からトークンメタデータを解決する。
 * direct DB、transient、HMAC-SHA256 自己検証署名の多重フォールバックにより、
 * デプロイやオブジェクトキャッシュ消去時でもトークンが不意に失われない。
 *
 * @param string $raw_token
 * @return array<string, mixed>|null
 */
function koto_mcp_resolve_access_token_data($raw_token)
{
    $token_hash = hash('sha256', $raw_token);

    // 1. direct DB ストレージからの直接取得
    $data = koto_mcp_storage_get('koto_mcp_token_' . $token_hash);

    // 2. 移行期・既存レコード用 transient フォールバック
    if (!is_array($data) || empty($data['user_id'])) {
        $data = get_transient('koto_mcp_token_' . $token_hash);
    }

    // 3. HMAC-SHA256 自己検証署名フォールバック
    if (!is_array($data) || empty($data['user_id'])) {
        $verified = koto_mcp_verify_signed_token($raw_token);
        if (is_array($verified) && ($verified['t'] ?? '') === 'at' && !empty($verified['uid'])) {
            $family_id = (string) ($verified['fid'] ?? '');
            $status = 'active';

            // ファミリーが既に失効していないか確認
            if ($family_id !== '') {
                $family_opt = koto_mcp_refresh_family_option_name($family_id);
                $family = koto_mcp_storage_get($family_opt);
                if ($family === null) {
                    $family = get_option($family_opt);
                }
                if (is_array($family) && ($family['status'] ?? 'active') !== 'active') {
                    $status = 'revoked';
                }
            }

            $data = [
                'user_id'    => (int) $verified['uid'],
                'scope'      => (string) ($verified['scp'] ?? 'validate preview'),
                'client_id'  => (string) ($verified['cid'] ?? ''),
                'family_id'  => $family_id,
                'status'     => $status,
                'issued_at'  => (int) ($verified['iat'] ?? time()),
                'expires_at' => (int) ($verified['exp'] ?? (time() + HOUR_IN_SECONDS)),
            ];
            // 復元したトークン情報を direct DB へ書き戻し
            koto_mcp_storage_put('koto_mcp_token_' . $token_hash, $data, 'no');
        }
    }

    return is_array($data) && !empty($data['user_id']) ? $data : null;
}

function koto_mcp_current_user_from_token($request = null)
{
    $header = koto_mcp_get_authorization_header($request);
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        $raw_token = trim($match[1]);
        $data = koto_mcp_resolve_access_token_data($raw_token);
        if (is_array($data) && !empty($data['user_id'])) {
            if (($data['status'] ?? 'active') !== 'active') {
                return 0;
            }
            $now = time();
            $clock_skew = 60; // 60秒の時刻同期マージン
            if (!empty($data['expires_at']) && ((int) $data['expires_at'] + $clock_skew) <= $now) {
                return 0;
            }
            if (!empty($data['family_id'])) {
                $family_opt = koto_mcp_refresh_family_option_name((string) $data['family_id']);
                $family = koto_mcp_storage_get($family_opt);
                if ($family === null) {
                    $family = get_option($family_opt);
                }
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
            koto_mcp_log_token_event('mcp_auth_401', [
                'reason_code' => 'token_missing',
                'required_scope' => $scope,
            ]);
            return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', [
                'status' => 401,
                'oauth_error' => 'invalid_token',
                'reason_code' => 'token_missing',
            ]);
        }

        $token_hash = hash('sha256', $token);
        $access_id = koto_mcp_short_token_id($token_hash, true);
        $data = koto_mcp_resolve_access_token_data($token);

        if (is_array($data) && !empty($data['user_id'])) {
            $family_id = (string) ($data['family_id'] ?? '');
            $now = time();
            $clock_skew = 60; // 60秒の通信ラグ・時刻ズレ許容マージン

            if (($data['status'] ?? 'active') !== 'active') {
                koto_mcp_log_token_event('mcp_auth_401', [
                    'reason_code' => 'token_revoked',
                    'sub_reason' => 'access_token_revoked',
                    'access_id' => $access_id,
                    'family_id' => $family_id ?: 'none',
                    'revoked_at' => $data['revoked_at'] ?? null,
                    'required_scope' => $scope,
                ]);
                return new WP_Error('mcp_unauthorized', 'OAuth access token has been revoked.', [
                    'status' => 401,
                    'oauth_error' => 'invalid_token',
                    'reason_code' => 'token_revoked',
                ]);
            }

            if ($family_id !== '') {
                $family_opt = koto_mcp_refresh_family_option_name($family_id);
                $family = koto_mcp_storage_get($family_opt);
                if ($family === null) {
                    $family = get_option($family_opt);
                }
                if (is_array($family) && ($family['status'] ?? 'active') !== 'active') {
                    koto_mcp_log_token_event('mcp_auth_401', [
                        'reason_code' => 'token_revoked',
                        'sub_reason' => 'family_revoked',
                        'access_id' => $access_id,
                        'family_id' => $family_id,
                        'family_updated_at' => $family['updated_at'] ?? null,
                        'required_scope' => $scope,
                    ]);
                    return new WP_Error('mcp_unauthorized', 'OAuth access token has been revoked.', [
                        'status' => 401,
                        'oauth_error' => 'invalid_token',
                        'reason_code' => 'token_revoked',
                    ]);
                }
            }

            if (!empty($data['expires_at']) && ((int) $data['expires_at'] + $clock_skew) <= $now) {
                koto_mcp_log_token_event('mcp_auth_401', [
                    'reason_code' => 'token_expired',
                    'access_id' => $access_id,
                    'family_id' => $family_id ?: 'none',
                    'issued_at' => $data['issued_at'] ?? null,
                    'expires_at' => (int) $data['expires_at'],
                    'now' => $now,
                    'expired_ago_sec' => $now - (int) $data['expires_at'],
                    'required_scope' => $scope,
                ]);
                return new WP_Error('mcp_unauthorized', 'OAuth access token has expired.', [
                    'status' => 401,
                    'oauth_error' => 'invalid_token',
                    'reason_code' => 'token_expired',
                ]);
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
            koto_mcp_log_token_event('mcp_auth_401', [
                'reason_code' => 'token_invalid',
                'sub_reason' => 'app_password_failed',
                'error' => $app_auth->get_error_code(),
                'required_scope' => $scope,
            ]);
            $error_data = (array) $app_auth->get_error_data();
            $error_data['reason_code'] = 'token_invalid';
            $app_auth->add_data($error_data);
            return $app_auth;
        }
        if (is_int($app_auth) && $app_auth > 0) {
            return koto_mcp_check_user_mcp_permission($app_auth);
        }

        koto_mcp_log_token_event('mcp_auth_401', [
            'reason_code' => 'token_invalid',
            'sub_reason' => 'token_not_found_or_evicted',
            'access_id' => $access_id,
            'required_scope' => $scope,
        ]);
        return new WP_Error('mcp_unauthorized', 'OAuth access token is invalid or expired.', [
            'status' => 401,
            'oauth_error' => 'invalid_token',
            'reason_code' => 'token_invalid',
        ]);
    }

    // HTTP Basic 認証（Application Password）または WordPress REST 標準認証のフォールバック
    if (stripos($header, 'Basic ') === 0) {
        $app_auth = koto_mcp_authenticate_app_password_header($header);
        if (is_wp_error($app_auth)) {
            koto_mcp_log_token_event('mcp_auth_401', [
                'reason_code' => 'token_invalid',
                'sub_reason' => 'basic_auth_failed',
                'error' => $app_auth->get_error_code(),
                'required_scope' => $scope,
            ]);
            $error_data = (array) $app_auth->get_error_data();
            $error_data['reason_code'] = 'token_invalid';
            $app_auth->add_data($error_data);
            return $app_auth;
        }
        if (is_int($app_auth) && $app_auth > 0) {
            return koto_mcp_check_user_mcp_permission($app_auth);
        }
        koto_mcp_log_token_event('mcp_auth_401', [
            'reason_code' => 'token_invalid',
            'sub_reason' => 'invalid_basic_credentials',
            'required_scope' => $scope,
        ]);
        return new WP_Error('mcp_unauthorized', 'Invalid Basic authentication credentials.', [
            'status' => 401,
            'oauth_error' => 'invalid_token',
            'reason_code' => 'token_invalid',
        ]);
    }

    if ($header === '' && is_user_logged_in()) {
        // WordPress 管理画面内（非RESTまたはX-WP-Nonce明示）でのみセッション認証を許可。
        // MCP Streamable HTTP エンドポイント宛ては OAuth Bearer または Application Password を必須とする。
        if ($request instanceof WP_REST_Request) {
            $nonce = $request->get_header('x_wp_nonce');
            if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
                return koto_mcp_check_user_mcp_permission(get_current_user_id());
            }
        } else {
            return koto_mcp_check_user_mcp_permission(get_current_user_id());
        }
    }

    koto_mcp_log_token_event('mcp_auth_401', [
        'reason_code' => 'token_missing',
        'required_scope' => $scope,
    ]);
    return new WP_Error('mcp_unauthorized', 'OAuth access token is required.', [
        'status' => 401,
        'oauth_error' => 'invalid_token',
        'reason_code' => 'token_missing',
    ]);
}
