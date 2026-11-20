<?php
/**
 * コトダマンDB MCP OAuth 2.1 認可前段（未認証プローブ・ディスカバリ・DCR/CIMD・認可/再認可）汎用統合テスト
 *
 * 実行方法:
 *   php lib/mcp/tests/test-mcp-oauth-preauth.php
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

$wp_load = '/var/www/html/wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load = dirname(__DIR__, 6) . '/wp-load.php';
}
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

$pass_count = 0;
$fail_count = 0;

function koto_preauth_assert($condition, $label)
{
    global $pass_count, $fail_count;
    if ($condition) {
        $pass_count++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail_count++;
        echo "  [FAIL] {$label}\n";
    }
}

$cleanup_users = [];
$cleanup_clients = [];
$cleanup_options = [];
$cleanup_transients = [];
$orig_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

$test_ip = '203.0.113.' . wp_rand(10, 240);
$_SERVER['REMOTE_ADDR'] = $test_ip;
$cleanup_transients[] = 'koto_mcp_reg_rate_' . hash('sha256', $test_ip);
$cleanup_transients[] = 'koto_mcp_login_ip_' . hash('sha256', $test_ip);

register_shutdown_function(function () use (&$cleanup_users, &$cleanup_clients, &$cleanup_options, &$cleanup_transients, $orig_remote_addr) {
    wp_set_current_user(0);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    if ($orig_remote_addr === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $orig_remote_addr;
    }
    foreach ($cleanup_clients as $cid) {
        koto_mcp_storage_delete('koto_mcp_client_' . $cid);
        delete_transient('koto_mcp_client_' . $cid);
    }
    foreach ($cleanup_options as $opt) {
        koto_mcp_storage_delete($opt);
        delete_option($opt);
    }
    foreach ($cleanup_transients as $t) {
        delete_transient($t);
    }
    foreach ($cleanup_users as $uid) {
        koto_mcp_revoke_user_tokens((int) $uid);
        wp_delete_user((int) $uid);
    }
});

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$admin_login = 'koto_preauth_admin_' . $suffix;
$admin_pass = 'AdminPass!' . $suffix;
$admin_uid = wp_insert_user([
    'user_login' => $admin_login,
    'user_pass'  => $admin_pass,
    'user_email' => $admin_login . '@example.test',
    'role'       => 'administrator',
]);

$contrib_login = 'koto_preauth_ai_' . $suffix;
$contrib_pass = 'ContribPass!' . $suffix;
$contrib_uid = wp_insert_user([
    'user_login' => $contrib_login,
    'user_pass'  => $contrib_pass,
    'user_email' => $contrib_login . '@example.test',
    'role'       => 'contributor',
]);

if (is_wp_error($admin_uid) || is_wp_error($contrib_uid)) {
    exit("テストユーザーの作成に失敗しました。\n");
}
$cleanup_users[] = $admin_uid;
$cleanup_users[] = $contrib_uid;
update_user_meta($admin_uid, '_koto_mcp_approved', '1');
update_user_meta($contrib_uid, '_koto_mcp_approved', '1');
$cleanup_transients[] = 'koto_mcp_login_user_' . hash('sha256', strtolower($admin_login));
$cleanup_transients[] = 'koto_mcp_login_user_' . hash('sha256', strtolower($contrib_login));

echo "========================================================\n";
echo "MCP OAuth 2.1 認可前段 統合テスト開始\n";
echo "========================================================\n\n";

// ------------------------------------------------------------
// 1. 未認証プローブ（GET / HEAD / POST空ボディ / POST不正JSON / OPTIONS）
// ------------------------------------------------------------
echo "=== シナリオ 1: 未認証プローブ & WWW-Authenticate 検証 ===\n";

$probe_get = new WP_REST_Request('GET', '/kotodaman/v1/mcp');
$res_get = koto_mcp_handle_http($probe_get);
$hdr_get = $res_get->get_headers();
koto_preauth_assert(
    $res_get->get_status() === 401
    && strpos((string) ($hdr_get['WWW-Authenticate'] ?? ''), 'resource_metadata=') !== false
    && strpos((string) ($hdr_get['WWW-Authenticate'] ?? ''), 'scope="validate preview create"') !== false,
    '未認証 GET /mcp が HTTP 401 と resource_metadata・scope 付き WWW-Authenticate を返す'
);

$probe_head = new WP_REST_Request('HEAD', '/kotodaman/v1/mcp');
$res_head = koto_mcp_handle_http($probe_head);
$hdr_head = $res_head->get_headers();
koto_preauth_assert(
    $res_head->get_status() === 401
    && strpos((string) ($hdr_head['WWW-Authenticate'] ?? ''), 'resource_metadata=') !== false,
    '未認証 HEAD /mcp が HTTP 401 と WWW-Authenticate を返す'
);

$probe_empty_post = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$probe_empty_post->set_body('');
$res_empty_post = koto_mcp_handle_http($probe_empty_post);
$hdr_empty_post = $res_empty_post->get_headers();
koto_preauth_assert(
    $res_empty_post->get_status() === 401
    && strpos((string) ($hdr_empty_post['WWW-Authenticate'] ?? ''), 'resource_metadata=') !== false,
    '未認証空ボディ POST /mcp が 400 ではなく HTTP 401 + WWW-Authenticate を返す'
);

$probe_bad_json_post = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$probe_bad_json_post->set_body('{not-json');
$res_bad_json_post = koto_mcp_handle_http($probe_bad_json_post);
koto_preauth_assert(
    $res_bad_json_post->get_status() === 401
    && strpos((string) ($res_bad_json_post->get_headers()['WWW-Authenticate'] ?? ''), 'resource_metadata=') !== false,
    '未認証非JSON POST /mcp も HTTP 401 + WWW-Authenticate を返す'
);

$probe_init = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$probe_init->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]));
$res_init = koto_mcp_handle_http($probe_init);
koto_preauth_assert(
    $res_init->get_status() === 401 && ($res_init->get_data()['id'] ?? null) === 99,
    '未認証 initialize POST /mcp が id を維持したまま HTTP 401 を返す'
);

// ------------------------------------------------------------
// 2. ディスカバリパス解決 & メタデータ整合性
// ------------------------------------------------------------
echo "\n=== シナリオ 2: ディスカバリパス & メタデータ整合性 ===\n";

$discovery_cases = [
    '/.well-known/oauth-protected-resource'                         => 'protected',
    '/.well-known/oauth-protected-resource/wp-json/kotodaman/v1/mcp' => 'protected',
    '/wp-json/kotodaman/v1/.well-known/oauth-protected-resource'    => 'protected',
    '/wp-json/kotodaman/v1/mcp/.well-known/oauth-protected-resource' => 'protected',
    '/.well-known/oauth-authorization-server'                       => 'authorization',
    '/.well-known/oauth-authorization-server/wp-json/kotodaman/v1/mcp' => 'authorization',
    '/.well-known/openid-configuration'                             => 'authorization',
    '/wp-json/kotodaman/v1/.well-known/oauth-authorization-server'  => 'authorization',
    '/wp-json/kotodaman/v1/mcp/.well-known/oauth-authorization-server' => 'authorization',
    '/wp-json/kotodaman/v1/.well-known/openid-configuration'        => 'authorization',
    '/wp-json/kotodaman/v1/mcp/.well-known/openid-configuration'    => 'authorization',
    '/wp-json/kotodaman/v1/mcp'                                     => '',
];
$all_paths_ok = true;
foreach ($discovery_cases as $p => $expected) {
    if (koto_mcp_oauth_match_discovery_kind($p) !== $expected) {
        $all_paths_ok = false;
        break;
    }
}
koto_preauth_assert($all_paths_ok, 'RFC 9728 / RFC 8414 / OIDC / パス後置型すべてのディスカバリパスが正しく判定される');

$prm = koto_mcp_oauth_protected_resource_metadata();
$asm = koto_mcp_oauth_authorization_server_metadata();
koto_preauth_assert(
    ($prm['resource'] ?? '') === koto_mcp_server_url()
    && in_array($asm['issuer'] ?? '', (array) ($prm['authorization_servers'] ?? []), true)
    && ($asm['authorization_endpoint'] ?? '') === koto_mcp_authorization_endpoint_url()
    && !empty($asm['registration_endpoint'])
    && !empty($asm['token_endpoint'])
    && in_array('S256', (array) ($asm['code_challenge_methods_supported'] ?? []), true)
    && !empty($asm['client_id_metadata_document_supported']),
    'PRM と AS メタデータの issuer / endpoints / PKCE / CIMD が相互に整合している'
);

// /wp-json/kotodaman/v1/mcp/oauth/authorize への GET が 302 リダイレクトすること
$rest_auth_req = new WP_REST_Request('GET', '/kotodaman/v1/mcp/oauth/authorize');
$rest_auth_req->set_query_params(['client_id' => 'abc', 'response_type' => 'code']);
$rest_auth_res = koto_mcp_oauth_authorize_rest($rest_auth_req);
$rest_auth_headers = $rest_auth_res instanceof WP_REST_Response ? $rest_auth_res->get_headers() : [];
koto_preauth_assert(
    $rest_auth_res instanceof WP_REST_Response
    && $rest_auth_res->get_status() === 302
    && strpos((string) ($rest_auth_headers['Location'] ?? ''), koto_mcp_authorization_endpoint_url() . '?client_id=abc') === 0,
    'GET /wp-json/kotodaman/v1/mcp/oauth/authorize が /mcp/oauth/authorize へクエリを維持して 302 リダイレクトする'
);

// ------------------------------------------------------------
// 3. DCR (RFC 7591) & CIMD (SEP-991)
// ------------------------------------------------------------
echo "\n=== シナリオ 3: DCR (RFC 7591) & CIMD (SEP-991) ===\n";

// 3-1: JSONボディとスコープ付きDCRが 201 Created を返すこと
$dcr_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/register');
$dcr_req->set_body(wp_json_encode([
    'client_name'                => 'Standard MCP Client',
    'redirect_uris'              => ['https://client.example.com/oauth/callback', 'http://127.0.0.1/callback'],
    'token_endpoint_auth_method' => 'none',
    'grant_types'                => ['authorization_code', 'refresh_token'],
    'response_types'             => ['code'],
    'scope'                      => 'validate preview create',
]));
$dcr_res = koto_mcp_oauth_register_client($dcr_req);
$dcr_data = $dcr_res instanceof WP_REST_Response ? $dcr_res->get_data() : [];
$dcr_cid = (string) ($dcr_data['client_id'] ?? '');
if ($dcr_cid !== '') {
    $cleanup_clients[] = $dcr_cid;
}
koto_preauth_assert(
    $dcr_res instanceof WP_REST_Response
    && $dcr_res->get_status() === 201
    && $dcr_cid !== ''
    && !empty($dcr_data['client_id_issued_at'])
    && ($dcr_data['scope'] ?? '') === 'validate preview create',
    'DCR (JSONボディ + scope指定) が HTTP 201 Created と scope・client_id_issued_at を返す'
);

// 3-2: set_body_params 経由のDCRも受理されること
$dcr_param_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/register');
$dcr_param_req->set_body_params([
    'client_name'   => 'Param DCR Client',
    'redirect_uris' => ['https://param-client.example.com/cb'],
]);
$dcr_param_res = koto_mcp_oauth_register_client($dcr_param_req);
$dcr_param_cid = ($dcr_param_res instanceof WP_REST_Response) ? (string) ($dcr_param_res->get_data()['client_id'] ?? '') : '';
if ($dcr_param_cid !== '') {
    $cleanup_clients[] = $dcr_param_cid;
}
koto_preauth_assert(
    $dcr_param_res instanceof WP_REST_Response && $dcr_param_res->get_status() === 201 && $dcr_param_cid !== '',
    'DCR が WP_REST_Request body_params 形式でも HTTP 201 でクライアント登録できる'
);

// 3-3: 不正なDCRが RFC 7591 error JSON (400) を返すこと
$dcr_bad_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/register');
$dcr_bad_req->set_body(wp_json_encode(['client_name' => 'Bad Client', 'redirect_uris' => ['http://evil.example.com/cb']]));
$dcr_bad_res = koto_mcp_oauth_register_client($dcr_bad_req);
$dcr_bad_data = $dcr_bad_res instanceof WP_REST_Response ? $dcr_bad_res->get_data() : [];
koto_preauth_assert(
    $dcr_bad_res instanceof WP_REST_Response
    && $dcr_bad_res->get_status() === 400
    && ($dcr_bad_data['error'] ?? '') === 'invalid_client_metadata',
    '不正な redirect_uris の DCR が RFC 7591 準拠の 400 invalid_client_metadata JSON を返す'
);

// 3-4: %XX を含む CIMD URL が破壊されず解決され、空の token_endpoint_auth_method も許容すること
$cimd_encoded_url = 'https://cimd.example.com/oauth%2Fclient/meta.json';
$cimd_filter = static function ($pre, $url) use ($cimd_encoded_url) {
    if ($url === $cimd_encoded_url) {
        return [
            'response' => ['code' => 200],
            'body'     => wp_json_encode([
                'client_id'                  => $cimd_encoded_url,
                'client_name'                => 'Encoded CIMD Client',
                'redirect_uris'              => ['https://cimd.example.com/callback'],
                'token_endpoint_auth_method' => '',
                'scope'                      => 'validate preview create',
            ]),
        ];
    }
    return $pre;
};
add_filter('koto_mcp_cimd_http_fetcher', $cimd_filter, 10, 2);
$cleanup_transients[] = 'koto_mcp_cimd_' . hash('sha256', $cimd_encoded_url);

$cimd_auth_outcome = koto_mcp_oauth_process_authorize_request([
    'response_type'         => 'code',
    'client_id'             => $cimd_encoded_url,
    'redirect_uri'          => 'https://cimd.example.com/callback',
    'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', 'verifier12345', true)), '+/', '-_'), '='),
    'code_challenge_method' => 'S256',
    'state'                 => 'st+1/2=3&x%20y',
], 'GET');
remove_filter('koto_mcp_cimd_http_fetcher', $cimd_filter, 10);

koto_preauth_assert(
    ($cimd_auth_outcome['action'] ?? '') === 'login'
    && ($cimd_auth_outcome['request_fields']['client_id'] ?? '') === $cimd_encoded_url
    && ($cimd_auth_outcome['request_fields']['scope'] ?? '') === 'validate preview create'
    && ($cimd_auth_outcome['request_fields']['state'] ?? '') === 'st+1/2=3&x%20y',
    'CIMD URLの%XXエンコードおよび特殊文字を含むstate・default_scopeがauthorize前段で破壊されず保持される'
);

// ------------------------------------------------------------
// 4. ループバック動的ポート (RFC 8252) & state 透過性検証
// ------------------------------------------------------------
echo "\n=== シナリオ 4: ループバック動的ポート (RFC 8252) & state 透過性 ===\n";

$loopback_resolved = koto_mcp_oauth_resolve_redirect_uri('http://127.0.0.1:54321/callback', ['http://127.0.0.1/callback']);
$https_mismatch = koto_mcp_oauth_resolve_redirect_uri('https://client.example.com:8443/oauth/callback', ['https://client.example.com/oauth/callback']);
koto_preauth_assert(
    $loopback_resolved === 'http://127.0.0.1:54321/callback' && is_wp_error($https_mismatch),
    'RFC 8252: ループバックHTTPのみ動的ポートが許可され、HTTPSの不一致は引き続き拒否される'
);

$appended_with_state = koto_mcp_oauth_append_query_params('https://client.example.com/cb', [
    'code'  => 'code123',
    'state' => 'a+b/c=d&e%20f',
]);
$parsed_q = [];
parse_str((string) wp_parse_url($appended_with_state, PHP_URL_QUERY), $parsed_q);
$appended_no_state = koto_mcp_oauth_append_query_params('https://client.example.com/cb', [
    'code'  => 'code123',
    'state' => '',
]);
koto_preauth_assert(
    ($parsed_q['state'] ?? '') === 'a+b/c=d&e%20f' && strpos($appended_no_state, 'state=') === false,
    'リダイレクト時の state (+, /, =, &, %20) がバイト単位で復元可能かつ空state時は付与されない'
);

// ------------------------------------------------------------
// 5. 再認可フロー（ログイン済み時の prompt=login & switch_account -> AIアカウント認可 -> トークン交換 -> MCP実行）
// ------------------------------------------------------------
echo "\n=== シナリオ 5: 再認可フロー (prompt=login / アカウント切替 / 完全E2E) ===\n";

$verifier = wp_generate_password(64, false, false);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$special_state = 'reauth+state/1=2&ok%20test';
$redirect_uri = 'https://client.example.com/oauth/callback';
$resource_url = koto_mcp_server_url();

// ブラウザに別ユーザー（管理者）がログイン済みの状態をシミュレート
wp_set_current_user($admin_uid);

// 5-1: prompt=login で既ログイン中でもログイン画面へ誘導されること
$prompt_login_res = koto_mcp_oauth_process_authorize_request([
    'response_type'         => 'code',
    'client_id'             => $dcr_cid,
    'redirect_uri'          => $redirect_uri,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
    'state'                 => $special_state,
    'resource'              => $resource_url,
    'prompt'                => 'login',
], 'GET');
koto_preauth_assert(
    ($prompt_login_res['action'] ?? '') === 'login'
    && ($prompt_login_res['request_fields']['scope'] ?? '') === 'validate preview create',
    '既ログイン状態でも prompt=login 指定時はログイン画面へ誘導され、DCRのdefault_scopeが引き継がれる'
);

// 5-2: 通常再認可で既ログインの管理者同意画面が表示され、switch_account で寄稿者へ切替できること
$consent_admin_res = koto_mcp_oauth_process_authorize_request([
    'response_type'         => 'code',
    'client_id'             => $dcr_cid,
    'redirect_uri'          => $redirect_uri,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
    'state'                 => $special_state,
    'resource'              => $resource_url,
], 'GET');
koto_preauth_assert(
    ($consent_admin_res['action'] ?? '') === 'consent' && (int) ($consent_admin_res['user']->ID ?? 0) === $admin_uid,
    '既ログイン時は同意画面が表示される'
);

$switch_nonce = wp_create_nonce((string) ($consent_admin_res['nonce_action'] ?? ''));
$switch_res = koto_mcp_oauth_process_authorize_request(array_merge($consent_admin_res['request_fields'], [
    'koto_mcp_consent_action' => 'switch_account',
    '_wpnonce'                => $switch_nonce,
]), 'POST');
koto_preauth_assert(
    ($switch_res['action'] ?? '') === 'login' && get_current_user_id() === 0,
    '同意画面の switch_account でログアウトし OAuth パラメータを維持したままログイン画面へ遷移する'
);

// 5-3: AIアカウント（寄稿者）でログインPOST -> 同意画面表示
$login_nonce = wp_create_nonce('koto_mcp_oauth_login');
$login_post_res = koto_mcp_oauth_process_authorize_request(array_merge($switch_res['request_fields'], [
    'koto_mcp_login_action' => 'login',
    '_wpnonce'              => $login_nonce,
    'log'                   => $contrib_login,
    'pwd'                   => $contrib_pass,
]), 'POST');
koto_preauth_assert(
    ($login_post_res['action'] ?? '') === 'consent'
    && (int) ($login_post_res['user']->ID ?? 0) === $contrib_uid,
    '別アカウントでのログインPOST後に新ユーザー（寄稿者）の同意画面へ進む'
);

// 5-4: 同意許可POST -> code発行 & state一致
$approve_nonce = wp_create_nonce((string) ($login_post_res['nonce_action'] ?? ''));
$approve_res = koto_mcp_oauth_process_authorize_request(array_merge($login_post_res['request_fields'], [
    'koto_mcp_consent_action' => 'approve',
    '_wpnonce'                => $approve_nonce,
]), 'POST');
$redirected_url = (string) ($approve_res['url'] ?? '');
$redirected_q = [];
parse_str((string) wp_parse_url($redirected_url, PHP_URL_QUERY), $redirected_q);
$issued_code = (string) ($redirected_q['code'] ?? '');
koto_preauth_assert(
    ($approve_res['action'] ?? '') === 'redirect'
    && $issued_code !== ''
    && ($redirected_q['state'] ?? '') === $special_state,
    '同意許可後に認可コードが発行され、特殊文字を含む state が完全一致で返却される'
);

// 5-5: 認可コード交換 -> 新ユーザー紐付けトークン取得 & MCPツール呼び出し
wp_set_current_user(0);
$tok_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$tok_req->set_body_params([
    'grant_type'    => 'authorization_code',
    'code'          => $issued_code,
    'client_id'     => $dcr_cid,
    'redirect_uri'  => $redirect_uri,
    'code_verifier' => $verifier,
    'resource'      => $resource_url,
]);
$tok_res = koto_mcp_oauth_token($tok_req);
$tok_data = $tok_res instanceof WP_REST_Response ? $tok_res->get_data() : [];
$new_at = (string) ($tok_data['access_token'] ?? '');
$verified_at = koto_mcp_verify_signed_token($new_at);
koto_preauth_assert(
    $tok_res instanceof WP_REST_Response
    && $tok_res->get_status() === 200
    && is_array($verified_at)
    && (int) ($verified_at['uid'] ?? 0) === $contrib_uid
    && ($verified_at['scp'] ?? '') === 'validate preview create',
    '認可コード交換で切替先ユーザー（寄稿者）および全スコープに紐づくアクセストークンが発行される'
);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $new_at;
$mcp_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$mcp_req->set_header('Authorization', 'Bearer ' . $new_at);
$mcp_req->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 501,
    'method'  => 'tools/list',
]));
$mcp_res = koto_mcp_handle_http($mcp_req);
$mcp_data = $mcp_res instanceof WP_REST_Response ? $mcp_res->get_data() : [];
koto_preauth_assert(
    $mcp_res instanceof WP_REST_Response
    && $mcp_res->get_status() === 200
    && count((array) ($mcp_data['result']['tools'] ?? [])) === 10,
    '再認可後の新トークンで MCP tools/list が正常に実行できる'
);

// ------------------------------------------------------------
// 結果サマリー
// ------------------------------------------------------------
echo "\n========================================================\n";
echo "テスト結果サマリー: 合格={$pass_count}, 失敗={$fail_count}\n";
echo "========================================================\n";

exit($fail_count > 0 ? 1 : 0);
