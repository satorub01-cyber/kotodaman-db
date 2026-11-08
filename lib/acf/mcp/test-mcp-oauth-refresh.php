<?php
/**
 * MCP OAuth 2.1 トークンリフレッシュ・接続継続テストスクリプト
 *
 * 実行方法:
 *   php /var/www/html/wp-content/themes/cocoon-child-master/lib/acf/mcp/test-mcp-oauth-refresh.php
 */

require_once '/var/www/html/wp-load.php';

// 出力カラー定義
const C_RESET  = "\033[0m";
const C_GREEN  = "\033[32m";
const C_RED    = "\033[31m";
const C_YELLOW = "\033[33m";
const C_BLUE   = "\033[34m";

function log_pass($msg) {
    echo C_GREEN . "  [PASS] " . C_RESET . $msg . "\n";
}
function log_fail($msg) {
    echo C_RED . "  [FAIL] " . C_RESET . $msg . "\n";
}
function log_section($title) {
    echo "\n" . C_BLUE . "=== " . $title . " ===" . C_RESET . "\n";
}

$tests_passed = 0;
$tests_failed = 0;

function assert_test($condition, $description) {
    global $tests_passed, $tests_failed;
    if ($condition) {
        log_pass($description);
        $tests_passed++;
    } else {
        log_fail($description);
        $tests_failed++;
    }
}

// テスト用管理者の取得
$admin_user = get_user_by('login', 'admin');
if (!$admin_user) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $admin_user = !empty($admins) ? $admins[0] : null;
}
if (!$admin_user) {
    echo C_RED . "エラー: テスト用管理者が存在しません。" . C_RESET . "\n";
    exit(1);
}
$user_id = (int) $admin_user->ID;
wp_set_current_user($user_id);

echo C_YELLOW . "テスト開始 (User ID: {$user_id}, Login: {$admin_user->user_login})" . C_RESET . "\n";

// -------------------------------------------------------------
// シナリオ 1: 未認証 initialize / tools/list の 401 チャレンジ検証
// -------------------------------------------------------------
log_section("シナリオ 1: 未認証アクセス時の 401 + WWW-Authenticate 検証");

// 1-1: initialize (未認証)
$req_init = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_init->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'initialize',
    'params'  => ['protocolVersion' => '2025-03-26'],
]));
$res_init = koto_mcp_handle_http($req_init);
assert_test($res_init instanceof WP_REST_Response, "initialize が WP_REST_Response を返却");
assert_test($res_init->get_status() === 401, "未認証 initialize は HTTP 401 を返却 (Status: " . $res_init->get_status() . ")");
$headers_init = $res_init->get_headers();
assert_test(!empty($headers_init['WWW-Authenticate']), "WWW-Authenticate ヘッダーが存在する");
assert_test(strpos($headers_init['WWW-Authenticate'] ?? '', 'invalid_token') !== false, "WWW-Authenticate に invalid_token が含まれる");

// 1-2: tools/list (未認証)
$req_list = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_list->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 2,
    'method'  => 'tools/list',
]));
$res_list = koto_mcp_handle_http($req_list);
assert_test($res_list->get_status() === 401, "未認証 tools/list は HTTP 401 を返却 (Status: " . $res_list->get_status() . ")");

// -------------------------------------------------------------
// シナリオ 2: 認可コード発行 & 初回トークン交換検証 (PKCE)
// -------------------------------------------------------------
log_section("シナリオ 2: 動的クライアント登録 & PKCE トークン交換");

// 2-1: クライアント登録
$reg_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/register');
$reg_req->set_body(wp_json_encode([
    'client_name'   => 'Test AI Agent',
    'redirect_uris' => ['http://127.0.0.1:8080/callback'],
]));
$reg_res = koto_mcp_oauth_register_client($reg_req);
$reg_data = $reg_res instanceof WP_REST_Response ? $reg_res->get_data() : $reg_res;
$client_id = $reg_data['client_id'] ?? '';
assert_test(!empty($client_id), "動的クライアント登録成功 (Client ID: {$client_id})");
assert_test(koto_mcp_storage_get('koto_mcp_client_' . $client_id) !== null, "クライアント情報が direct DB に永続化されている");

// 2-2: PKCE パラメータと認可コード生成
$verifier = wp_generate_password(64, false, false);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$code = wp_generate_password(48, false, false);
$code_hash = hash('sha256', $code);
$family_id = wp_generate_password(32, false, false);

$code_data = [
    'user_id'      => $user_id,
    'client_id'    => $client_id,
    'redirect_uri' => 'http://127.0.0.1:8080/callback',
    'challenge'    => $challenge,
    'scope'        => 'validate preview create',
    'family_id'    => $family_id,
    'expires_at'   => time() + 300,
];
koto_mcp_storage_put('koto_mcp_code_' . $code_hash, $code_data, 'no');

// 2-3: トークン交換
$token_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$token_req->set_param('grant_type', 'authorization_code');
$token_req->set_param('code', $code);
$token_req->set_param('client_id', $client_id);
$token_req->set_param('redirect_uri', 'http://127.0.0.1:8080/callback');
$token_req->set_param('code_verifier', $verifier);

$token_res = koto_mcp_oauth_token($token_req);
assert_test($token_res instanceof WP_REST_Response, "トークンレスポンスが WP_REST_Response");
$token_data = $token_res->get_data();
$access_token = $token_data['access_token'] ?? '';
$refresh_token = $token_data['refresh_token'] ?? '';
$expires_in = $token_data['expires_in'] ?? 0;

assert_test(strpos($access_token, 'koto_at_') === 0, "アクセストークンが koto_at_ 形式 (HMAC自己検証署名付き)");
assert_test(strpos($refresh_token, 'koto_rt_') === 0, "リフレッシュトークンが koto_rt_ 形式");
assert_test((int) $expires_in === 3600, "アクセストークンの有効期限が設計通り 3600 秒 (1時間)");

$verified_at = koto_mcp_verify_signed_token($access_token);
assert_test($verified_at !== null && (int) $verified_at['uid'] === $user_id, "アクセストークンの暗号学的HMAC署名検証が成功");

// -------------------------------------------------------------
// シナリオ 3: 再接続直後の連続ツール実行検証
// -------------------------------------------------------------
log_section("シナリオ 3: 再接続直後の連続ツール実行");

// 3-1: initialize (認証あり)
$req_auth_init = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_auth_init->add_header('Authorization', 'Bearer ' . $access_token);
$req_auth_init->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 3,
    'method'  => 'initialize',
    'params'  => ['protocolVersion' => '2025-03-26'],
]));
$res_auth_init = koto_mcp_handle_http($req_auth_init);
assert_test($res_auth_init->get_status() === 200, "認証済み initialize が 200 OK");

// 3-2: tools/list (認証あり)
$req_auth_list = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_auth_list->add_header('Authorization', 'Bearer ' . $access_token);
$req_auth_list->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 4,
    'method'  => 'tools/list',
]));
$res_auth_list = koto_mcp_handle_http($req_auth_list);
assert_test($res_auth_list->get_status() === 200, "認証済み tools/list が 200 OK");

// 3-3: tools/call (認証あり、get_terms)
$req_auth_call = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_auth_call->add_header('Authorization', 'Bearer ' . $access_token);
$req_auth_call->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 5,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'get_terms',
        'arguments' => ['taxonomy' => 'attribute'],
    ],
]));
$res_auth_call = koto_mcp_handle_http($req_auth_call);
assert_test($res_auth_call->get_status() === 200, "認証済み tools/call が 200 OK");

// -------------------------------------------------------------
// シナリオ 4: アクセストークン失効時の自動リフレッシュ検証
// -------------------------------------------------------------
log_section("シナリオ 4: アクセストークン期限切れ & リフレッシュ実行");

// 4-1: アクセストークンの有効期限を過去（失効状態）に偽装
$at_hash = hash('sha256', $access_token);
$at_record = koto_mcp_storage_get('koto_mcp_token_' . $at_hash);
$at_record['expires_at'] = time() - 100; // 期限切れ (60s skew 超過)
koto_mcp_storage_put('koto_mcp_token_' . $at_hash, $at_record, 'no');
delete_transient('koto_mcp_token_' . $at_hash);

$res_expired = koto_mcp_handle_http($req_auth_call);
assert_test($res_expired->get_status() === 401, "期限切れアクセストークンでの呼び出しは 401 (Status: " . $res_expired->get_status() . ")");

// 4-2: リフレッシュトークンによる更新
$refresh_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$refresh_req->set_param('grant_type', 'refresh_token');
$refresh_req->set_param('refresh_token', $refresh_token);
$refresh_req->set_param('client_id', $client_id);

$refresh_res = koto_mcp_oauth_token($refresh_req);
assert_test($refresh_res instanceof WP_REST_Response && $refresh_res->get_status() === 200, "リフレッシュリクエストが 200 OK で成功");

$refreshed_data = $refresh_res->get_data();
$new_access_token = $refreshed_data['access_token'] ?? '';
$new_refresh_token = $refreshed_data['refresh_token'] ?? '';
assert_test(!empty($new_access_token) && $new_access_token !== $access_token, "新しいアクセストークンが発行された");
assert_test(!empty($new_refresh_token) && $new_refresh_token !== $refresh_token, "新しいリフレッシュトークンがローテーション発行された");

// 4-3: 新アクセストークンでのツール呼び出し
$req_new_call = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_new_call->add_header('Authorization', 'Bearer ' . $new_access_token);
$req_new_call->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 6,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'get_terms',
        'arguments' => ['taxonomy' => 'attribute'],
    ],
]));
$res_new_call = koto_mcp_handle_http($req_new_call);
assert_test($res_new_call->get_status() === 200, "新アクセストークンでのツール呼び出しが即座に成功");

// -------------------------------------------------------------
// シナリオ 5: 並行リフレッシュ & 15秒デバウンス検証
// -------------------------------------------------------------
log_section("シナリオ 5: 並行リフレッシュと 15秒デバウンス");

// 直前に発行された $new_refresh_token に対し、短時間に連続 5 回リフレッシュを要求
$debounce_success_count = 0;
for ($i = 0; $i < 5; $i++) {
    $par_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
    $par_req->set_param('grant_type', 'refresh_token');
    $par_req->set_param('refresh_token', $new_refresh_token);
    $par_req->set_param('client_id', $client_id);

    $par_res = koto_mcp_oauth_token($par_req);
    if ($par_res instanceof WP_REST_Response && $par_res->get_status() === 200) {
        $debounce_success_count++;
    }
}
assert_test($debounce_success_count === 5, "5 回の連続リフレッシュ要求がすべて 200 OK で成功 (デバウンス & ロック正常)");

// -------------------------------------------------------------
// シナリオ 6: ローテーション済み旧リフレッシュトークン（R1）再送救済検証
// -------------------------------------------------------------
log_section("シナリオ 6: 旧リフレッシュトークン（R1）の安全救済");

// 既にローテーション済みの古い $refresh_token (R1) を再度提示
$old_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$old_req->set_param('grant_type', 'refresh_token');
$old_req->set_param('refresh_token', $refresh_token); // R1 (古いトークン)
$old_req->set_param('client_id', $client_id);

$old_res = koto_mcp_oauth_token($old_req);
assert_test($old_res instanceof WP_REST_Response && $old_res->get_status() === 200, "旧トークン再送時にファミリー道連れ即死せず 200 OK で救済成功");

// 救済後も最新アクセストークンが生きていることを確認
$res_check = koto_mcp_handle_http($req_new_call);
assert_test($res_check->get_status() === 200, "救済後もアクティブなアクセストークンは 200 OK を維持");

// -------------------------------------------------------------
// シナリオ 7: 明示的 Revoke 検証
// -------------------------------------------------------------
log_section("シナリオ 7: 明示的失効 (Revoke) と完全無効化");

// revoke エンドポイントを呼び出し
$revoke_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/revoke');
$revoke_req->set_param('token', $new_access_token);
$revoke_req->set_param('client_id', $client_id);

$revoke_res = koto_mcp_oauth_revoke($revoke_req);
assert_test(is_array($revoke_res) && !empty($revoke_res['revoked']), "Revoke API が revoked: true を返却");

// Revoke 後、同じアクセストークンでの呼び出しが 401 となることを確認
$res_after_revoke = koto_mcp_handle_http($req_new_call);
assert_test($res_after_revoke->get_status() === 401, "Revoke 後のアクセストークンは即座に 401 (Status: " . $res_after_revoke->get_status() . ")");

// Revoke 後、リフレッシュトークンも失効していることを確認
$post_revoke_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$post_revoke_req->set_param('grant_type', 'refresh_token');
$post_revoke_req->set_param('refresh_token', $new_refresh_token);
$post_revoke_req->set_param('client_id', $client_id);

$post_revoke_res = koto_mcp_oauth_token($post_revoke_req);
assert_test($post_revoke_res instanceof WP_REST_Response && $post_revoke_res->get_status() === 400, "Revoke 後のリフレッシュ要求は 400 invalid_grant で拒絶");

// -------------------------------------------------------------
// テスト終了と後片付け
// -------------------------------------------------------------
log_section("テスト結果サマリー");
echo "合格: " . C_GREEN . $tests_passed . C_RESET . "\n";
echo "失敗: " . ($tests_failed > 0 ? C_RED : C_GREEN) . $tests_failed . C_RESET . "\n";

if ($tests_failed === 0) {
    echo C_GREEN . "🎉 全 7 シナリオの検証に完全合格しました！" . C_RESET . "\n";
    exit(0);
} else {
    echo C_RED . "⚠️ 一部のテストで失敗が発生しました。" . C_RESET . "\n";
    exit(1);
}
