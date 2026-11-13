<?php
/**
 * コトダマンDB MCP 互換拡張・OAuth拡張・REST互換・協力者登録・広告制御 統合テスト
 *
 * 実行方法:
 *   php lib/mcp/tests/test-mcp-compatibility.php
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

function koto_compat_assert($condition, $label) {
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
$cleanup_posts = [];
$cleanup_clients = [];
$cleanup_options = [];
$cleanup_transients = [];

register_shutdown_function(function () use (&$cleanup_users, &$cleanup_posts, &$cleanup_clients, &$cleanup_options, &$cleanup_transients) {
    wp_set_current_user(0);
    foreach ($cleanup_posts as $pid) {
        wp_delete_post((int) $pid, true);
    }
    foreach ($cleanup_clients as $cid) {
        koto_mcp_delete_static_oauth_client($cid);
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

// テスト用管理者・一般協力者を作成
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$admin_uid = wp_insert_user([
    'user_login' => 'koto_compat_admin_' . $suffix,
    'user_pass'  => wp_generate_password(20),
    'user_email' => 'koto_compat_admin_' . $suffix . '@example.test',
    'role'       => 'administrator',
]);
$sub_uid = wp_insert_user([
    'user_login' => 'koto_compat_sub_' . $suffix,
    'user_pass'  => wp_generate_password(20),
    'user_email' => 'koto_compat_sub_' . $suffix . '@example.test',
    'role'       => 'subscriber',
]);
if (is_wp_error($admin_uid) || is_wp_error($sub_uid)) {
    exit("テストユーザーの作成に失敗しました。\n");
}
$cleanup_users[] = $admin_uid;
$cleanup_users[] = $sub_uid;
update_user_meta($admin_uid, '_koto_mcp_approved', '1');

echo "========================================================\n";
echo "コトダマンDB MCP 互換拡張 統合テスト開始\n";
echo "========================================================\n\n";

// ------------------------------------------------------------
// シナリオ 1: CIMD (SEP-991) 解決と SSRF 防御
// ------------------------------------------------------------
echo "=== シナリオ 1: CIMD (SEP-991) メタデータ解決 & SSRF 防御 ===\n";

$cimd_url = 'https://client-meta.example.com/oauth/client.json';
$cimd_fetcher = function ($pre, $url) use ($cimd_url) {
    if ($url === $cimd_url) {
        return [
            'response' => ['code' => 200],
            'body'     => wp_json_encode([
                'client_id'                  => $cimd_url,
                'client_name'                => 'Example MCP Client',
                'redirect_uris'              => ['https://client-meta.example.com/callback'],
                'token_endpoint_auth_method' => 'none',
                'scope'                      => 'validate preview create',
            ]),
        ];
    }
    if ($url === 'https://mismatch.example.com/client.json') {
        return [
            'response' => ['code' => 200],
            'body'     => wp_json_encode([
                'client_id'     => 'https://other.example.com/client.json',
                'client_name'   => 'Mismatch Client',
                'redirect_uris' => ['https://mismatch.example.com/cb'],
            ]),
        ];
    }
    return $pre;
};
add_filter('koto_mcp_cimd_http_fetcher', $cimd_fetcher, 10, 2);

$cimd_client = koto_mcp_oauth_resolve_cimd_client($cimd_url);
koto_compat_assert(is_array($cimd_client) && ($cimd_client['client_id'] ?? '') === $cimd_url, 'CIMD URLクライアントが正常解決される');
koto_compat_assert(($cimd_client['client_type'] ?? '') === 'public' && !empty($cimd_client['is_cimd']), 'CIMDクライアント種別が public / is_cimd=true である');

$ssrf_localhost = koto_mcp_oauth_resolve_cimd_client('https://127.0.0.1/client.json');
koto_compat_assert($ssrf_localhost === null, 'CIMDでループバックIP (127.0.0.1) が拒否される');

$ssrf_metadata = koto_mcp_oauth_resolve_cimd_client('https://169.254.169.254/latest/meta-data/');
koto_compat_assert($ssrf_metadata === null, 'CIMDでメタデータIP (169.254.169.254) が拒否される');

$cimd_mismatch = koto_mcp_oauth_resolve_cimd_client('https://mismatch.example.com/client.json');
koto_compat_assert($cimd_mismatch === null, 'CIMDメタデータ内のclient_id不一致が拒否される');

remove_filter('koto_mcp_cimd_http_fetcher', $cimd_fetcher, 10);
$cleanup_transients[] = 'koto_mcp_cimd_' . hash('sha256', $cimd_url);

// ASメタデータ確認
$as_meta = koto_mcp_oauth_authorization_server_metadata();
koto_compat_assert(!empty($as_meta['client_id_metadata_document_supported']), 'ASメタデータに client_id_metadata_document_supported: true が含まれる');
koto_compat_assert(in_array('client_secret_basic', (array) ($as_meta['token_endpoint_auth_methods_supported'] ?? []), true), 'ASメタデータに client_secret_basic が含まれる');

// ------------------------------------------------------------
// シナリオ 2: Static Confidential Client & 非PKCEオプトイン
// ------------------------------------------------------------
echo "\n=== シナリオ 2: Static Confidential Client & 認証 / 非PKCE検証 ===\n";

$static_created = koto_mcp_create_static_oauth_client(
    'ChatGPT Actions Test',
    ['https://chat.openai.com/aip/g-test/oauth/callback'],
    true,
    'validate preview create'
);
koto_compat_assert(!is_wp_error($static_created) && !empty($static_created['client_id']) && !empty($static_created['client_secret']), 'Static Confidential Clientが発行される');
$sc_id = (string) ($static_created['client_id'] ?? '');
$sc_secret = (string) ($static_created['client_secret'] ?? '');
$cleanup_clients[] = $sc_id;

// 認可コードを直接仕込む（challengeなし = 非PKCE）
$code_no_pkce = 'code_nopkce_' . bin2hex(random_bytes(8));
$code_key = 'koto_mcp_code_' . hash('sha256', $code_no_pkce);
koto_mcp_storage_put($code_key, [
    'user_id'      => $admin_uid,
    'client_id'    => $sc_id,
    'redirect_uri' => 'https://chat.openai.com/aip/g-test/oauth/callback',
    'scope'        => 'validate preview create',
    'challenge'    => '',
    'resource'     => koto_mcp_server_url(),
    'expires_at'   => time() + 300,
], 'no');
$cleanup_options[] = $code_key;

// 誤ったシークレットでの交換は 401 invalid_client
$bad_secret_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$bad_secret_req->set_body_params([
    'grant_type'    => 'authorization_code',
    'code'          => $code_no_pkce,
    'redirect_uri'  => 'https://chat.openai.com/aip/g-test/oauth/callback',
    'client_id'     => $sc_id,
    'client_secret' => 'wrong_secret_value',
]);
$bad_secret_res = koto_mcp_oauth_token($bad_secret_req);
koto_compat_assert($bad_secret_res instanceof WP_REST_Response && $bad_secret_res->get_status() === 401, '誤ったclient_secretはHTTP 401で拒否される');

// 認可コードを再登録（使い捨て消費されたため）し、正しい Basic 認証で非PKCE交換
$code_no_pkce2 = 'code_nopkce2_' . bin2hex(random_bytes(8));
$code_key2 = 'koto_mcp_code_' . hash('sha256', $code_no_pkce2);
koto_mcp_storage_put($code_key2, [
    'user_id'      => $admin_uid,
    'client_id'    => $sc_id,
    'redirect_uri' => 'https://chat.openai.com/aip/g-test/oauth/callback',
    'scope'        => 'validate preview create',
    'challenge'    => '',
    'resource'     => koto_mcp_server_url(),
    'expires_at'   => time() + 300,
], 'no');
$cleanup_options[] = $code_key2;

$ok_basic_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$ok_basic_req->set_header('Authorization', 'Basic ' . base64_encode($sc_id . ':' . $sc_secret));
$ok_basic_req->set_body_params([
    'grant_type'   => 'authorization_code',
    'code'         => $code_no_pkce2,
    'redirect_uri' => 'https://chat.openai.com/aip/g-test/oauth/callback',
]);
$ok_basic_res = koto_mcp_oauth_token($ok_basic_req);
$ok_basic_data = $ok_basic_res instanceof WP_REST_Response ? $ok_basic_res->get_data() : [];
koto_compat_assert($ok_basic_res instanceof WP_REST_Response && $ok_basic_res->get_status() === 200 && !empty($ok_basic_data['access_token']), 'Confidential Client (allow_no_pkce=true) + Basic認証で非PKCEトークン交換が成功する');

// Public DCRクライアントでは非PKCE交換が拒否されること
$pub_reg_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/register');
$pub_reg_req->set_body_params([
    'client_name'   => 'Public Test',
    'redirect_uris' => ['https://public.example.com/cb'],
]);
$pub_reg_res = koto_mcp_oauth_register_client($pub_reg_req);
$pub_cid = ($pub_reg_res instanceof WP_REST_Response) ? (string) ($pub_reg_res->get_data()['client_id'] ?? '') : '';
$cleanup_clients[] = $pub_cid;
$pub_code = 'code_pub_nopkce_' . bin2hex(random_bytes(8));
$pub_code_key = 'koto_mcp_code_' . hash('sha256', $pub_code);
koto_mcp_storage_put($pub_code_key, [
    'user_id'      => $admin_uid,
    'client_id'    => $pub_cid,
    'redirect_uri' => 'https://public.example.com/cb',
    'scope'        => 'validate preview',
    'challenge'    => '',
    'expires_at'   => time() + 300,
], 'no');
$cleanup_options[] = $pub_code_key;

$pub_nopkce_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/oauth/token');
$pub_nopkce_req->set_body_params([
    'grant_type'   => 'authorization_code',
    'code'         => $pub_code,
    'redirect_uri' => 'https://public.example.com/cb',
    'client_id'    => $pub_cid,
]);
$pub_nopkce_res = koto_mcp_oauth_token($pub_nopkce_req);
koto_compat_assert($pub_nopkce_res instanceof WP_REST_Response && $pub_nopkce_res->get_status() === 400, 'Public Clientの非PKCE交換はHTTP 400で拒否される');

// ------------------------------------------------------------
// シナリオ 3: RFC 8707 `aud` & SEP-835 `insufficient_scope`
// ------------------------------------------------------------
echo "\n=== シナリオ 3: RFC 8707 resource(aud) & SEP-835 insufficient_scope ===\n";

$aud_wrong_res = koto_mcp_issue_token($admin_uid, 'validate', $pub_cid, null, 'https://evil.example.com/mcp');
$aud_wrong_token = ($aud_wrong_res instanceof WP_REST_Response) ? (string) ($aud_wrong_res->get_data()['access_token'] ?? '') : '';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $aud_wrong_token;
$aud_check_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$aud_check_req->set_header('Authorization', 'Bearer ' . $aud_wrong_token);
$aud_uid = koto_mcp_current_user_from_token($aud_check_req);
$aud_err = koto_mcp_require_scope('validate', $aud_check_req);
koto_compat_assert($aud_uid === 0 && is_wp_error($aud_err) && ($aud_err->get_error_data()['reason_code'] ?? '') === 'token_audience_mismatch', '別リソース向け(aud不一致)トークンは token_audience_mismatch で拒否される');

$read_only_res = koto_mcp_issue_token($admin_uid, 'validate preview', $pub_cid, null, koto_mcp_server_url());
$read_only_token = ($read_only_res instanceof WP_REST_Response) ? (string) ($read_only_res->get_data()['access_token'] ?? '') : '';

$scope_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$scope_req->set_header('Authorization', 'Bearer ' . $read_only_token);
$scope_req->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 301,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'trash_draft',
        'arguments' => ['post_id' => 999999],
    ],
]));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $read_only_token;
$scope_http_res = koto_mcp_handle_http($scope_req);
$scope_headers = $scope_http_res instanceof WP_REST_Response ? $scope_http_res->get_headers() : [];
$www_auth = (string) ($scope_headers['WWW-Authenticate'] ?? '');
koto_compat_assert($scope_http_res instanceof WP_REST_Response && $scope_http_res->get_status() === 403, 'スコープ不足 (validate preview で create 実行) は HTTP 403 を返す');
koto_compat_assert(strpos($www_auth, 'error="insufficient_scope"') !== false && strpos($www_auth, 'scope="create"') !== false, 'SEP-835: WWW-Authenticate に error="insufficient_scope" と scope="create" が含まれる');

unset($_SERVER['HTTP_AUTHORIZATION']);

// ------------------------------------------------------------
// シナリオ 4: server.php (2025-11-25, CORS, Origin, GET, Batch, isError, Annotations)
// ------------------------------------------------------------
echo "\n=== シナリオ 4: server.php (2025-11-25 / CORS / Origin / Batch / isError / Annotations) ===\n";

$opt_req = new WP_REST_Request('OPTIONS', '/kotodaman/v1/mcp');
$opt_req->set_header('Origin', 'https://inspector.modelcontextprotocol.io');
$opt_res = koto_mcp_handle_http($opt_req);
$opt_headers = $opt_res->get_headers();
koto_compat_assert($opt_res->get_status() === 204 && !empty($opt_headers['Access-Control-Allow-Headers']) && strpos($opt_headers['Access-Control-Allow-Headers'], 'MCP-Protocol-Version') !== false, 'OPTIONS プリフライトで 204 と MCP-Protocol-Version 許可CORSヘッダーを返す');

$bad_origin_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$bad_origin_req->set_header('Origin', 'null');
$bad_origin_res = koto_mcp_handle_http($bad_origin_req);
koto_compat_assert($bad_origin_res->get_status() === 403, '不正な Origin ("null") を HTTP 403 で拒否する');

$unauth_get_req = new WP_REST_Request('GET', '/kotodaman/v1/mcp');
$unauth_get_res = koto_mcp_handle_http($unauth_get_req);
koto_compat_assert($unauth_get_res->get_status() === 401 && !empty($unauth_get_res->get_headers()['WWW-Authenticate']), '未認証 GET /mcp は HTTP 401 + WWW-Authenticate を返す');

$auth_get_req = new WP_REST_Request('GET', '/kotodaman/v1/mcp');
$auth_get_req->set_header('Authorization', 'Bearer ' . $read_only_token);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $read_only_token;
$auth_get_res = koto_mcp_handle_http($auth_get_req);
koto_compat_assert($auth_get_res->get_status() === 405, '認証済み GET /mcp は HTTP 405 Method Not Allowed を返す');

// initialize 2025-11-25 ネゴシエーション
$init_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$init_req->set_header('Authorization', 'Bearer ' . $read_only_token);
$init_req->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'initialize',
    'params'  => ['protocolVersion' => '2025-11-25'],
]));
$init_res = koto_mcp_handle_http($init_req);
$init_data = $init_res->get_data();
koto_compat_assert(($init_data['result']['protocolVersion'] ?? '') === '2025-11-25', 'initialize が protocolVersion 2025-11-25 をネゴシエートする');
koto_compat_assert(($init_res->get_headers()['MCP-Protocol-Version'] ?? '') === '2025-11-25', 'レスポンスヘッダー MCP-Protocol-Version が 2025-11-25 である');

// tools/list 全10ツール + Annotations
$list_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$list_req->set_header('Authorization', 'Bearer ' . $read_only_token);
$list_req->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 2,
    'method'  => 'tools/list',
]));
$list_res = koto_mcp_handle_http($list_req);
$tools_arr = $list_res->get_data()['result']['tools'] ?? [];
$has_all_ann = (count($tools_arr) === 10);
$trash_destructive = false;
foreach ($tools_arr as $tool) {
    if (empty($tool['annotations']) || !array_key_exists('readOnlyHint', $tool['annotations'])) {
        $has_all_ann = false;
    }
    if (($tool['name'] ?? '') === 'trash_draft' && !empty($tool['annotations']['destructiveHint'])) {
        $trash_destructive = true;
    }
}
koto_compat_assert($has_all_ann && $trash_destructive, '全10ツールに annotations が付与され trash_draft が destructiveHint=true');

// JSON-RPC バッチリクエスト & ドメインエラー isError: true
$batch_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$batch_req->set_header('Authorization', 'Bearer ' . $read_only_token);
$batch_req->set_body(wp_json_encode([
    ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => ['name' => 'get_draft', 'arguments' => ['post_id' => 999999999]]],
]));
$batch_res = koto_mcp_handle_http($batch_req);
$batch_data = $batch_res->get_data();
koto_compat_assert(is_array($batch_data) && count($batch_data) === 2 && ($batch_data[0]['id'] ?? null) === 10, 'JSON-RPC バッチ配列リクエストが2件のレスポンスを返す');
koto_compat_assert(!empty($batch_data[1]['result']['isError']), 'tools/call ドメインエラー時に result.isError=true を返す');

unset($_SERVER['HTTP_AUTHORIZATION']);

// ------------------------------------------------------------
// シナリオ 5: REST互換ルート & OpenAPI 3.1.0 制約チェック
// ------------------------------------------------------------
echo "\n=== シナリオ 5: REST互換ルート / ページネーション / OpenAPI 3.1.0 ===\n";

$full_res = koto_mcp_issue_token($admin_uid, 'validate preview create', $pub_cid, null, koto_mcp_server_url());
$full_token = ($full_res instanceof WP_REST_Response) ? (string) ($full_res->get_data()['access_token'] ?? '') : '';

$openapi_req = new WP_REST_Request('GET', '/kotodaman/v1/mcp/openapi.json');
$openapi_res = koto_mcp_rest_openapi_json($openapi_req);
$openapi_data = $openapi_res instanceof WP_REST_Response ? $openapi_res->get_data() : [];
koto_compat_assert(($openapi_data['openapi'] ?? '') === '3.1.0' && count((array) ($openapi_data['paths'] ?? [])) === 7, 'GET /mcp/openapi.json が 3.1.0 / 7パスを返す');

$bounds_ok = true;
foreach ((array) ($openapi_data['paths'] ?? []) as $p => $methods) {
    foreach ((array) $methods as $m => $op) {
        if (!is_array($op)) {
            continue;
        }
        $sum = (string) ($op['summary'] ?? '');
        $desc = (string) ($op['description'] ?? '');
        if (mb_strlen($sum) > 300 || mb_strlen($desc) > 700) {
            $bounds_ok = false;
        }
    }
}
koto_compat_assert($bounds_ok, 'OpenAPI 全オペレーションが ChatGPT Actions 文字数制限 (summary<=300, description<=700) 以内');

// get_terms ページネーション
$terms_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$terms_req->set_header('Authorization', 'Bearer ' . $full_token);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $full_token;
$terms_paged = koto_mcp_call_tool('get_terms', ['taxonomy' => 'attribute', 'limit' => 2, 'offset' => 0], $terms_req);
koto_compat_assert(!is_wp_error($terms_paged) && array_key_exists('has_more', $terms_paged) && count((array) ($terms_paged['terms'] ?? [])) <= 2, 'get_terms が limit=2 / has_more ページネーションを返す');

// REST /characters/search & /draft/preview
$search_req = new WP_REST_Request('GET', '/kotodaman/v1/mcp/characters/search');
$search_req->set_param('name', 'ア');
$search_req->set_header('Authorization', 'Bearer ' . $full_token);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $full_token;
$rest_server = rest_get_server();
$search_res = $rest_server->dispatch($search_req);
koto_compat_assert($search_res->get_status() === 200 && array_key_exists('matches', (array) $search_res->get_data()), 'GET /mcp/characters/search が matches を返す');

$prev_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/draft/preview');
$prev_req->set_header('Authorization', 'Bearer ' . $full_token);
$prev_req->set_header('Content-Type', 'application/json');
$prev_req->set_body(wp_json_encode([
    'character_name' => '互換テストプレビュー',
    'basic_fields'   => [
        'name_ruby'  => 'ごかんてすと',
        'attribute'  => '火',
        'species'    => '神',
        'rarity'     => '★6',
    ],
]));
$prev_res = $rest_server->dispatch($prev_req);
$prev_data = $prev_res->get_data();
koto_compat_assert($prev_res->get_status() === 200 && !empty($prev_data['preview_hash']), 'POST /mcp/draft/preview が 200 と preview_hash を返す');

unset($_SERVER['HTTP_AUTHORIZATION']);

// ------------------------------------------------------------
// シナリオ 6: 協力者ポータル登録・メール認証・ロール昇格/降格
// ------------------------------------------------------------
echo "\n=== シナリオ 6: 協力者ポータル登録 & メール認証 & ロール昇格/降格 ===\n";

$hp_res = koto_mcp_collab_submit_registration([
    'username'         => 'spam_user_' . $suffix,
    'email'            => 'spam_' . $suffix . '@example.test',
    'password'         => 'ValidPass123!',
    'password_confirm' => 'ValidPass123!',
    'website_url'      => 'http://spam.example.com',
    'skip_rate_limit'  => true,
    'skip_mail'        => true,
]);
koto_compat_assert(is_wp_error($hp_res) && $hp_res->get_error_code() === 'koto_reg_spam', 'ハニーポット入力付き登録リクエストが拒否される');

$reg_username = 'collab_' . $suffix;
$reg_email = 'collab_' . $suffix . '@example.test';
$reg_pass = 'StrongPass!9876';
$sub_res = koto_mcp_collab_submit_registration([
    'username'         => $reg_username,
    'email'            => $reg_email,
    'display_name'     => '協力テストユーザー',
    'password'         => $reg_pass,
    'password_confirm' => $reg_pass,
    'request_mcp'      => true,
    'skip_rate_limit'  => true,
    'skip_mail'        => true,
]);
koto_compat_assert(!is_wp_error($sub_res) && ($sub_res['status'] ?? '') === 'pending_verification', 'メール認証前の登録受付が pending_verification を返す');
koto_compat_assert(username_exists($reg_username) === false, 'メール認証完了前にはWPユーザーが作成されない');

$ver_res = koto_mcp_collab_verify_registration((string) ($sub_res['token'] ?? ''));
koto_compat_assert(!is_wp_error($ver_res) && ($ver_res['role'] ?? '') === 'subscriber', 'メール認証後に subscriber としてWPユーザーが作成される');

$created_uid = (int) ($ver_res['user_id'] ?? 0);
$cleanup_users[] = $created_uid;
$created_user = get_userdata($created_uid);
koto_compat_assert($created_user && wp_check_password($reg_pass, $created_user->user_pass, $created_uid), '作成されたユーザーで元のパスワードによるログイン検証が通る');
koto_compat_assert(get_user_meta($created_uid, '_koto_mcp_requested', true) === '1', 'デフォルトONのMCP申請フラグ _koto_mcp_requested=1 が保存される');

$reuse_res = koto_mcp_collab_verify_registration((string) ($sub_res['token'] ?? ''));
koto_compat_assert(is_wp_error($reuse_res), '使用済み認証トークンの再利用が拒否される');

// subscriber <-> mcp_user 昇格・降格
koto_mcp_promote_subscriber_to_mcp_user($created_user);
$after_promote = get_userdata($created_uid);
koto_compat_assert(in_array('mcp_user', (array) $after_promote->roles, true), '承認時に subscriber -> mcp_user へ昇格する');

koto_mcp_demote_mcp_user_to_subscriber($after_promote);
$after_demote = get_userdata($created_uid);
koto_compat_assert(in_array('subscriber', (array) $after_demote->roles, true) && !in_array('mcp_user', (array) $after_demote->roles, true), '取消時に mcp_user -> subscriber へ降格する');

// 管理者は降格されない
$admin_user = get_userdata($admin_uid);
koto_mcp_demote_mcp_user_to_subscriber($admin_user);
$admin_after = get_userdata($admin_uid);
koto_compat_assert(in_array('administrator', (array) $admin_after->roles, true), '管理者は降格対象外として保護される');

// ショートコード出力チェック（インラインスタイル禁止 & wpautop耐性確認）
$portal_html = do_shortcode('[koto_collaborate_portal]');
$portal_wpautop = wpautop($portal_html);
koto_compat_assert(
    strpos($portal_html, 'koto-collab-portal-shortcode') !== false
    && strpos($portal_html, 'style=') === false
    && stripos($portal_html, '<style') === false,
    'ショートコード [koto_collaborate_portal] が描画され、インラインstyleを含まない'
);
koto_compat_assert(
    strpos($portal_wpautop, '<p></p>') === false
    && strpos($portal_wpautop, 'koto-collab-cards"><br') === false
    && strpos($portal_wpautop, 'koto-ai-tool-grid"><br') === false,
    'ショートコード出力に wpautop を適用してもレイアウト破壊用の空<p>や<br>が入らない'
);

// テンプレートファイル & 共通レンダラ出力チェック
$tmpl_file = get_stylesheet_directory() . '/collaboration-page.php';
$tmpl_src = file_exists($tmpl_file) ? (string) file_get_contents($tmpl_file) : '';
$tmpl_html = koto_mcp_render_collaboration_portal(['is_template' => true, 'page_title' => 'テスト協力ページ']);
koto_compat_assert(
    $tmpl_src !== ''
    && strpos($tmpl_src, 'Template Name:') !== false
    && strpos($tmpl_src, 'style=') === false
    && strpos($tmpl_html, 'koto-collab-portal-template') !== false
    && strpos($tmpl_html, '<h1 class="koto-collab-hero-title">テスト協力ページ</h1>') !== false,
    '固定ページテンプレート collaboration-page.php とテンプレート用レンダリングが正常動作する'
);

// ------------------------------------------------------------
// シナリオ 7: 広告非表示制御 (koto_user_can_hide_ads / Site Kit / is_ads_visible)
// ------------------------------------------------------------
echo "\n=== シナリオ 7: 条件付き広告非表示制御 ===\n";

wp_set_current_user(0);
koto_compat_assert(koto_user_can_hide_ads() === false && apply_filters('googlesitekit_adsense_tag_blocked', false) === false, '未ログイン時は広告非表示=false');

wp_set_current_user($admin_uid);
koto_compat_assert(koto_user_can_hide_ads() === true && apply_filters('googlesitekit_adsense_tag_blocked', false) === true && is_ads_visible() === false, '管理者は広告非表示=true / is_ads_visible()=false');

wp_set_current_user($sub_uid);
koto_compat_assert(koto_user_can_hide_ads() === false && apply_filters('googlesitekit_adsense_tag_blocked', false) === false, '公開記事0件の subscriber は広告非表示=false');

// subscriber が公開キャラを1件持つと非表示=trueになる
$pub_pid = wp_insert_post([
    'post_type'   => 'character',
    'post_status' => 'publish',
    'post_title'  => '広告テストキャラ_' . $suffix,
    'post_author' => $sub_uid,
]);
$cleanup_posts[] = $pub_pid;
clean_user_cache($sub_uid);
koto_compat_assert(koto_user_can_hide_ads($sub_uid) === true && is_ads_visible() === false, '公開キャラを1件持つユーザーは広告非表示=true');

// 削除後は再度 false になる
wp_delete_post($pub_pid, true);
koto_compat_assert(koto_user_can_hide_ads($sub_uid) === false, '公開投稿削除後は広告非表示=falseに戻る');
wp_set_current_user(0);

// ------------------------------------------------------------
// シナリオ 8: コトワザ自動入力（auto_input_kotowaza）& ガイドリソース検証
// ------------------------------------------------------------
echo "\n=== シナリオ 8: auto_input_kotowaza MCP/REST & ガイドリソース ===\n";

$koto_res_list = koto_mcp_resources_list();
$has_koto_guide_res = false;
$has_entry_guide_res = false;
foreach ($koto_res_list as $r_item) {
    if (($r_item['uri'] ?? '') === 'kotodaman://guide/kotowaza') {
        $has_koto_guide_res = true;
    }
    if (($r_item['uri'] ?? '') === 'kotodaman://guide/character-entry') {
        $has_entry_guide_res = true;
    }
}
$koto_guide_read = koto_mcp_read_resource('kotodaman://guide/kotowaza');
$koto_guide_text = (!is_wp_error($koto_guide_read) && !empty($koto_guide_read['contents'][0]['text']))
    ? (string) $koto_guide_read['contents'][0]['text']
    : '';
koto_compat_assert(
    $has_koto_guide_res && strpos($koto_guide_text, 'auto_input_kotowaza') !== false && strpos($koto_guide_text, 'koto_magnification_estimate_tf') !== false,
    'MCP Resource kotodaman://guide/kotowaza が一覧公開・取得でき、ガイド本文を含む'
);

$entry_guide_read = koto_mcp_read_resource('kotodaman://guide/character-entry');
$entry_guide_text = (!is_wp_error($entry_guide_read) && !empty($entry_guide_read['contents'][0]['text']))
    ? (string) $entry_guide_read['contents'][0]['text']
    : '';
koto_compat_assert(
    $has_entry_guide_res
    && strpos($entry_guide_text, 'イベント・コラボの基準') !== false
    && strpos($entry_guide_text, 'あるせーぬ＆そろもん') !== false
    && strpos($entry_guide_text, 'ヴぇるどら') !== false
    && strpos($entry_guide_text, '雛形') !== false,
    'MCP Resource kotodaman://guide/character-entry が一覧公開・取得でき、ガイド本文を含む'
);

$koto_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$koto_req->set_header('Authorization', 'Bearer ' . $full_token);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $full_token;
wp_set_current_user($admin_uid);

// 1. validate / preview_draft / create_draft / get_draft の一連フロー
$koto_payload = [
    'character_name' => 'コトワザテストキャラ_' . $suffix,
    'basic_fields'   => [
        'name_ruby'                      => 'ことわざてすと',
        'attribute'                      => '火',
        'species'                        => '神',
        'rarity'                         => 'スペシャル',
        'koto_magnification_estimate_tf' => true,
    ],
    'auto_input'     => [
        'auto_input_kotowaza' => [
            ['condition' => '①4文字以上②10コンボ以上', 'effect' => '敵単体に超絶強力な木属性攻撃'],
            ['condition' => '「し」を含む12コンボ以上', 'effect' => 'HPを大きく回復'],
        ],
    ],
];
$koto_arg_valid = koto_mcp_validate_tool_arguments('preview_draft', $koto_payload);
$koto_preview = !is_wp_error($koto_arg_valid)
    ? koto_mcp_call_tool('preview_draft', $koto_payload, $koto_req)
    : $koto_arg_valid;
koto_compat_assert(
    !is_wp_error($koto_preview)
    && !empty($koto_preview['valid'])
    && !empty($koto_preview['preview_hash'])
    && ($koto_preview['parsed_counts']['auto_input_kotowaza'] ?? 0) === 2
    && empty($koto_preview['warnings']),
    'preview_draft が auto_input_kotowaza (0〜1凸配列) を正常解析し警告なしで preview_hash を返す'
);

$koto_created = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => (string) ($koto_preview['preview_hash'] ?? ''),
    'idempotency_key' => 'koto_test_' . $suffix,
], $koto_req);
$koto_post_id = !is_wp_error($koto_created) ? (int) ($koto_created['post_id'] ?? 0) : 0;
if ($koto_post_id > 0) {
    $cleanup_posts[] = $koto_post_id;
}
$koto_draft = $koto_post_id > 0 ? koto_mcp_call_tool('get_draft', ['post_id' => $koto_post_id], $koto_req) : null;
koto_compat_assert(
    $koto_post_id > 0
    && is_array($koto_draft)
    && ($koto_draft['basic_fields']['koto_magnification_estimate_tf'] ?? false) === true,
    'create_draft 後に get_draft で koto_magnification_estimate_tf=true が取得できる'
);

// 2. 件数上限超過 (>5凸) および全件未解析テキストの拒否確認
$too_many_koto = array_fill(0, 6, ['condition' => '①4文字以上②10コンボ以上', 'effect' => 'HPを大きく回復']);
$too_many_err = koto_mcp_validate_tool_arguments('preview_draft', [
    'character_name' => '上限超過テスト_' . $suffix,
    'basic_fields'   => ['name_ruby' => 'てすと', 'attribute' => '火', 'species' => '神', 'rarity' => 'なし'],
    'auto_input'     => ['auto_input_kotowaza' => $too_many_koto],
]);
koto_compat_assert(
    is_wp_error($too_many_err) && $too_many_err->get_error_code() === 'INVALID_PARAMS',
    'auto_input_kotowaza が 6件超の場合は INVALID_PARAMS で拒否される'
);

$unparsed_koto_res = koto_mcp_call_tool('validate', [
    'character_name' => '未解析コトワザ_' . $suffix,
    'basic_fields'   => ['name_ruby' => 'みかいせき', 'attribute' => '火', 'species' => '神', 'rarity' => 'なし'],
    'auto_input'     => [
        'auto_input_kotowaza' => [
            ['condition' => '未知の条件テキストABC', 'effect' => '未知の効果テキストABC'],
        ],
    ],
], $koto_req);
koto_compat_assert(
    is_wp_error($unparsed_koto_res) && $unparsed_koto_res->get_error_code() === 'VALIDATION_FAILED',
    '全凸が未解析の auto_input_kotowaza は VALIDATION_FAILED (unparsed_text) になる'
);

// 3. 部分未解析時の警告検出 (warnings)
$partial_koto_preview = koto_mcp_call_tool('preview_draft', [
    'character_name' => '部分未解析コトワザ_' . $suffix,
    'basic_fields'   => ['name_ruby' => 'ぶぶん', 'attribute' => '火', 'species' => '神', 'rarity' => 'なし'],
    'auto_input'     => [
        'auto_input_kotowaza' => [
            ['condition' => '①4文字以上②10コンボ以上', 'effect' => '敵単体に超絶強力な木属性攻撃'],
            ['condition' => '未知の条件フレーズXYZ', 'effect' => '①HPを大きく回復②未知の効果フレーズXYZ'],
        ],
    ],
], $koto_req);
$partial_warn_str = implode("\n", (array) ($partial_koto_preview['warnings'] ?? []));
koto_compat_assert(
    !is_wp_error($partial_koto_preview)
    && !empty($partial_koto_preview['valid'])
    && strpos($partial_warn_str, '(1凸).condition') !== false
    && strpos($partial_warn_str, '(1凸).effect') !== false,
    '部分未解析の auto_input_kotowaza で 1凸.condition / 1凸.effect の警告(warnings)が返る'
);

// 4. REST エンドポイント (/mcp/character-draft dry_run) でも配列入力を受理できる
$rest_koto_req = new WP_REST_Request('POST', '/kotodaman/v1/mcp/character-draft');
$rest_koto_req->set_header('Authorization', 'Bearer ' . $full_token);
$rest_koto_req->set_header('Content-Type', 'application/json');
$rest_koto_req->set_body(wp_json_encode([
    'character_name' => 'RESTコトワザ_' . $suffix,
    'dry_run'        => true,
    'basic_fields'   => ['name_ruby' => 'れすと', 'attribute' => '水', 'species' => '神', 'rarity' => 'なし'],
    'auto_input'     => [
        'auto_input_kotowaza' => [
            ['condition' => '①4文字以上②10コンボ以上', 'effect' => 'HPを大きく回復'],
        ],
    ],
]));
$rest_koto_res = $rest_server->dispatch($rest_koto_req);
$rest_koto_data = $rest_koto_res->get_data();
koto_compat_assert(
    $rest_koto_res->get_status() === 200 && ($rest_koto_data['parsed_counts']['auto_input_kotowaza'] ?? 0) === 1,
    'POST /mcp/character-draft (dry_run) でも auto_input_kotowaza 配列入力を受理できる'
);

unset($_SERVER['HTTP_AUTHORIZATION']);
wp_set_current_user(0);

echo "\n========================================================\n";
echo "テスト結果サマリー: 合格={$pass_count}, 失敗={$fail_count}\n";
echo "========================================================\n";

exit($fail_count > 0 ? 1 : 0);
