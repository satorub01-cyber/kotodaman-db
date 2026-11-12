<?php
/**
 * コトダマンDB MCP 利用者管理・権限・セキュリティ包括検証テストスクリプト
 *
 * 【注意】
 * 本スクリプトは開発・ステージング環境専用の検証テストです。
 * テスト専用の一時ユーザー（購読者・編集者）を動的に作成し、終了時に完全消去します。
 * 本番環境での実行は推奨されません。
 *
 * 実行方法:
 *   php lib/acf/mcp/test-mcp-permissions.php
 */

$wp_load_candidates = [
    __DIR__ . '/../../../../wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
    '/var/www/html/wp-load.php',
];

$loaded = false;
foreach ($wp_load_candidates as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    echo "エラー: wp-load.php が見つかりません。\n";
    exit(1);
}

// カラー定義
const C_RESET  = "\033[0m";
const C_GREEN  = "\033[32m";
const C_RED    = "\033[31m";
const C_YELLOW = "\033[33m";
const C_BLUE   = "\033[34m";

function log_pass($msg) { echo C_GREEN . "  [PASS] " . C_RESET . $msg . "\n"; }
function log_fail($msg) { echo C_RED . "  [FAIL] " . C_RESET . $msg . "\n"; }
function log_section($title) { echo "\n" . C_BLUE . "=== " . $title . " ===" . C_RESET . "\n"; }

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

echo C_YELLOW . "========================================================\n";
echo "コトダマンDB MCP 利用者管理・権限・セキュリティ包括テスト開始\n";
echo "（使い捨てテストユーザーを生成し、終了後に自動クリーンアップします）\n";
echo "========================================================\n" . C_RESET;

// -------------------------------------------------------------
// テスト専用使い捨てユーザーおよびデータのセットアップ
// -------------------------------------------------------------
$cleanup_user_ids = [];
$cleanup_post_ids = [];

register_shutdown_function(static function () use (&$cleanup_user_ids, &$cleanup_post_ids) {
    foreach ($cleanup_post_ids as $pid) {
        if ($pid > 0) {
            wp_delete_post($pid, true);
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($cleanup_user_ids as $uid) {
        if ($uid > 0) {
            koto_mcp_revoke_user_tokens($uid);
            wp_delete_user($uid);
        }
    }
});

// 低権限ユーザー（購読者）の生成
$sub_username = 'test_sub_' . wp_generate_password(8, false, false);
$sub_uid = wp_create_user($sub_username, wp_generate_password(16), $sub_username . '@example.com');
if (is_wp_error($sub_uid)) {
    echo C_RED . "エラー: テスト用購読者ユーザー作成失敗: " . $sub_uid->get_error_message() . C_RESET . "\n";
    exit(1);
}
$cleanup_user_ids[] = $sub_uid;
$sub_user = get_userdata($sub_uid);
$sub_user->set_role('subscriber');

// 編集者ユーザーの生成
$ed_username = 'test_ed_' . wp_generate_password(8, false, false);
$ed_uid = wp_create_user($ed_username, wp_generate_password(16), $ed_username . '@example.com');
if (is_wp_error($ed_uid)) {
    echo C_RED . "エラー: テスト用編集者ユーザー作成失敗: " . $ed_uid->get_error_message() . C_RESET . "\n";
    exit(1);
}
$cleanup_user_ids[] = $ed_uid;
$ed_user = get_userdata($ed_uid);
$ed_user->set_role('editor');

// -------------------------------------------------------------
// シナリオ 1: タグ判定の単一化と厳格判定
// -------------------------------------------------------------
log_section("シナリオ 1: タグ判定の単一化と厳格判定");

delete_user_meta($sub_uid, '_koto_mcp_approved');
assert_test(koto_mcp_is_user_approved($sub_uid) === false, "タグ(_koto_mcp_approved)がなければ承認判定は false");

$perm_err = koto_mcp_check_user_mcp_permission($sub_uid);
assert_test(is_wp_error($perm_err) && $perm_err->get_error_code() === 'mcp_pending_approval', "タグなし時は koto_mcp_check_user_mcp_permission で mcp_pending_approval エラー");

update_user_meta($sub_uid, '_koto_mcp_approved', 1);
assert_test(koto_mcp_is_user_approved($sub_uid) === true, "タグ(_koto_mcp_approved=1)付与後は承認判定が true");
assert_test(koto_mcp_check_user_mcp_permission($sub_uid) === $sub_uid, "タグあり時はユーザーIDが正常返却される");

// -------------------------------------------------------------
// シナリオ 2: 申請メタの追加・完全消去
// -------------------------------------------------------------
log_section("シナリオ 2: 申請メタの追加・完全消去");

koto_mcp_record_request_meta($sub_uid);
assert_test(get_user_meta($sub_uid, '_koto_mcp_requested', true) == 1, "_koto_mcp_requested が 1 に設定される");
assert_test((int) get_user_meta($sub_uid, '_koto_mcp_requested_at', true) > 0, "_koto_mcp_requested_at にタイムスタンプが記録される");

koto_mcp_clear_request_meta($sub_uid);
assert_test(get_user_meta($sub_uid, '_koto_mcp_requested', true) === '', "clear_request_meta で _koto_mcp_requested が完全消去される");
assert_test(get_user_meta($sub_uid, '_koto_mcp_requested_at', true) === '', "clear_request_meta で _koto_mcp_requested_at も完全消去される");

// -------------------------------------------------------------
// シナリオ 3: 認可実物関数による自己復活防止検証（編集者）
// -------------------------------------------------------------
log_section("シナリオ 3: 認可実物関数による自己復活防止検証");

// 剥奪状態をシミュレート
delete_user_meta($ed_uid, '_koto_mcp_approved');
update_user_meta($ed_uid, '_koto_mcp_revoked', 1);

// 実物関数 koto_mcp_oauth_process_consent_approval を実行
$consent_res = koto_mcp_oauth_process_consent_approval($ed_uid);
assert_test(is_array($consent_res) && empty($consent_res['approved']), "剥奪された編集者が認可関数を実行しても approved は false");
assert_test(!empty($consent_res['is_revoked']), "認可関数が停止中ステータス(is_revoked=true)を検出する");
assert_test(koto_mcp_is_user_approved($ed_uid) === false, "剥奪された編集者に自己復活タグは付与されない");
assert_test(get_user_meta($ed_uid, '_koto_mcp_requested', true) == 1, "再開申請メタ(_koto_mcp_requested=1)が記録される");

// 管理者による手動付与（再開）で復活することの検証
update_user_meta($ed_uid, '_koto_mcp_approved', 1);
delete_user_meta($ed_uid, '_koto_mcp_revoked');
koto_mcp_clear_request_meta($ed_uid);
assert_test(koto_mcp_is_user_approved($ed_uid) === true, "管理画面からの再開によりタグが付与される");
assert_test(get_user_meta($ed_uid, '_koto_mcp_revoked', true) === '', "再開により _koto_mcp_revoked がクリアされる");

// -------------------------------------------------------------
// シナリオ 4: 認可コード発行後の剥奪によるトークン交換拒否
// -------------------------------------------------------------
log_section("シナリオ 4: 認可コード発行後の剥奪によるトークン交換拒否");

$client_id = 'test_client_' . wp_generate_password(8, false, false);
$redirect_uri = 'http://127.0.0.1:8080/callback';
$verifier = wp_generate_password(48, false, false);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$code = wp_generate_password(48, false, false);
$code_hash = hash('sha256', $code);

// 認可コード保存
koto_mcp_storage_put('koto_mcp_code_' . $code_hash, [
    'user_id'      => $sub_uid,
    'client_id'    => $client_id,
    'redirect_uri' => $redirect_uri,
    'challenge'    => $challenge,
    'scope'        => 'validate preview create',
    'family_id'    => wp_generate_password(32, false, false),
    'expires_at'   => time() + 300,
], 'no');

// 交換前にユーザーのタグを剥奪
delete_user_meta($sub_uid, '_koto_mcp_approved');
update_user_meta($sub_uid, '_koto_mcp_revoked', 1);

// トークンエンドポイントへ認可コード交換リクエスト
$req_token = new WP_REST_Request('POST', '/mcp/oauth/token');
$req_token->set_body_params([
    'grant_type'    => 'authorization_code',
    'client_id'     => $client_id,
    'code'          => $code,
    'redirect_uri'  => $redirect_uri,
    'code_verifier' => $verifier,
]);
$token_resp = koto_mcp_oauth_token($req_token);
$token_status = $token_resp instanceof WP_REST_Response ? $token_resp->get_status() : 200;
$token_data = $token_resp instanceof WP_REST_Response ? $token_resp->get_data() : [];
assert_test($token_status === 400 && ($token_data['error'] ?? '') === 'invalid_grant', "認可コード発行後に剥奪されたユーザーのトークン交換は 400 invalid_grant で拒否される");

// -------------------------------------------------------------
// シナリオ 5: 剥奪直後のツール即時停止 & 15秒デバウンス回避検証
// -------------------------------------------------------------
log_section("シナリオ 5: 剥奪直後のツール即時停止 & デバウンス回避検証");

// タグ再付与
update_user_meta($sub_uid, '_koto_mcp_approved', 1);
delete_user_meta($sub_uid, '_koto_mcp_revoked');

// トークン発行
$issued = koto_mcp_issue_token($sub_uid, 'validate preview create', $client_id);
$issued_data = $issued instanceof WP_REST_Response ? $issued->get_data() : (array) $issued;
$at = (string) ($issued_data['access_token'] ?? '');
$rt = (string) ($issued_data['refresh_token'] ?? '');
assert_test(!empty($at) && !empty($rt), "テスト用トークン対が正常発行される");

// リフレッシュを一度実行して 15秒デバウンス状態を生成
$req_refresh1 = new WP_REST_Request('POST', '/mcp/oauth/token');
$req_refresh1->set_body_params([
    'grant_type'    => 'refresh_token',
    'client_id'     => $client_id,
    'refresh_token' => $rt,
]);
$res_refresh1 = koto_mcp_oauth_token($req_refresh1);
$ref1_data = $res_refresh1 instanceof WP_REST_Response ? $res_refresh1->get_data() : [];
$new_rt = (string) ($ref1_data['refresh_token'] ?? '');
$new_at = (string) ($ref1_data['access_token'] ?? '');
assert_test(!empty($new_rt), "1回目のリフレッシュが成功しローテーションされる");

// 即座にユーザーを剥奪
delete_user_meta($sub_uid, '_koto_mcp_approved');
update_user_meta($sub_uid, '_koto_mcp_revoked', 1);
koto_mcp_revoke_user_tokens($sub_uid);

// ① アクセストークンによるツール実行が即時遮断されること
$req_tool = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_tool->add_header('Authorization', 'Bearer ' . $new_at);
$req_tool->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 101,
    'method'  => 'get_field_schema',
    'params'  => [],
]));
$res_tool = koto_mcp_handle_http($req_tool);
assert_test($res_tool->get_status() === 401 || $res_tool->get_status() === 403, "剥奪直後: 有効期限内トークンでもツール実行が即時遮断される (Status: " . $res_tool->get_status() . ")");

// ② 15秒デバウンスウィンドウ内であってもリフレッシュが拒否され、デバウンス返却されないこと
$req_refresh2 = new WP_REST_Request('POST', '/mcp/oauth/token');
$req_refresh2->set_body_params([
    'grant_type'    => 'refresh_token',
    'client_id'     => $client_id,
    'refresh_token' => $new_rt,
]);
$res_refresh2 = koto_mcp_oauth_token($req_refresh2);
$ref2_status = $res_refresh2 instanceof WP_REST_Response ? $res_refresh2->get_status() : 200;
$ref2_data = $res_refresh2 instanceof WP_REST_Response ? $res_refresh2->get_data() : [];
assert_test($ref2_status === 400 && ($ref2_data['error'] ?? '') === 'invalid_grant', "剥奪後は直近15秒以内でもデバウンス返却されず 400 invalid_grant で拒否される");

// -------------------------------------------------------------
// シナリオ 6: 低権限ユーザーの実利用フロー包括検証（作成・読み返し・重複検索・他人遮断）
// -------------------------------------------------------------
log_section("シナリオ 6: 低権限ユーザーの実利用フロー包括検証");

// タグ再付与
update_user_meta($sub_uid, '_koto_mcp_approved', 1);
delete_user_meta($sub_uid, '_koto_mcp_revoked');

// create スコープ所持トークンを発行
$target_family = wp_generate_password(32, false, false);
$flow_token = koto_mcp_issue_token($sub_uid, 'validate preview create', $client_id, $target_family);
$flow_token_data = $flow_token instanceof WP_REST_Response ? $flow_token->get_data() : (array) $flow_token;
$flow_at = (string) ($flow_token_data['access_token'] ?? '');

$req_auth_header = new WP_REST_Request();
$req_auth_header->add_header('Authorization', 'Bearer ' . $flow_at);

// ① preview_draft を実行して preview_hash を取得
$char_name = 'テスト検証キャラ_' . wp_generate_password(6, false, false);
$preview_args = [
    'character_name' => $char_name,
    'basic_fields'   => [
        'name_ruby' => 'てすと',
        'attribute' => '光',
        'species'   => '神',
        'rarity'    => '6',
    ],
    'auto_input'     => [
        'auto_input_waza_name' => 'テストわざ',
        'auto_input_waza'      => '敵単体に超絶強力な水属性攻撃',
    ],
];
$preview_res = koto_mcp_call_tool('preview_draft', $preview_args, $req_auth_header);
assert_test(is_array($preview_res) && !empty($preview_res['preview_hash']), "低権限ユーザー: preview_draft が成功し preview_hash を取得");
$preview_hash = $preview_res['preview_hash'] ?? '';

// ② create_draft を実行して下書き作成（preview_hash と idempotency_key でキャッシュから自動復元）
$create_res = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => $preview_hash,
    'idempotency_key' => wp_generate_password(16, false, false),
], $req_auth_header);
$created_pid = is_array($create_res) ? (int) ($create_res['post_id'] ?? 0) : 0;
assert_test($created_pid > 0, "低権限ユーザー: create_draft で下書き投稿が正常作成される (ID: {$created_pid})");
if ($created_pid > 0) {
    $cleanup_post_ids[] = $created_pid;
    $post_obj = get_post($created_pid);
    assert_test($post_obj && (int) $post_obj->post_author === $sub_uid, "作成された下書きの post_author が低権限ユーザーと一致");
    assert_test($post_obj && $post_obj->post_status === 'draft', "作成された投稿ステータスが draft");
}

// ③ get_draft による自作下書きの読み返し（本修正の中心課題）
$get_draft_res = koto_mcp_call_tool('get_draft', ['post_id' => $created_pid], $req_auth_header);
assert_test(is_array($get_draft_res) && ($get_draft_res['post_id'] ?? 0) === $created_pid, "低権限ユーザー: get_draft で自作下書きを正常に読み返せる（403にならない）");
assert_test(is_array($get_draft_res) && ($get_draft_res['character_name'] ?? '') === $char_name, "読み返した下書きのタイトル(character_name)が一致する");

// ④ search_character による重複チェック（自作下書きが出現すること）
$search_res = koto_mcp_call_tool('search_character', ['name' => $char_name], $req_auth_header);
$matches = is_array($search_res) ? ($search_res['matches'] ?? []) : [];
$found_self = false;
foreach ($matches as $m) {
    if ((int) ($m['id'] ?? 0) === $created_pid) {
        $found_self = true;
        break;
    }
}
assert_test($found_self === true, "低権限ユーザー: search_character の重複チェック結果に自作下書きが含まれる");

// ⑤ 他人の下書きに対する読み返しの遮断検証
$other_post_id = wp_insert_post([
    'post_type'   => 'character',
    'post_status' => 'draft',
    'post_title'  => '他人作成下書き_' . wp_generate_password(6, false, false),
    'post_author' => $ed_uid,
]);
assert_test($other_post_id > 0, "テスト用他人下書きを作成 (ID: {$other_post_id})");
if ($other_post_id > 0) {
    $cleanup_post_ids[] = $other_post_id;
    $other_get_res = koto_mcp_call_tool('get_draft', ['post_id' => $other_post_id], $req_auth_header);
    assert_test(is_wp_error($other_get_res) && $other_get_res->get_error_code() === 'FORBIDDEN', "低権限ユーザー: 他人の下書きに対する get_draft は 403 FORBIDDEN で確実に遮断される");
}

// ⑥ validate のみトークンでの権限制限検証（読み返し可、作成不可）
$val_token = koto_mcp_issue_token($sub_uid, 'validate', $client_id);
$val_token_data = $val_token instanceof WP_REST_Response ? $val_token->get_data() : (array) $val_token;
$val_at = (string) ($val_token_data['access_token'] ?? '');
$req_val_header = new WP_REST_Request();
$req_val_header->add_header('Authorization', 'Bearer ' . $val_at);

$val_get_res = koto_mcp_call_tool('get_draft', ['post_id' => $created_pid], $req_val_header);
assert_test(is_array($val_get_res) && ($val_get_res['post_id'] ?? 0) === $created_pid, "validateのみトークン: 自作下書きの get_draft は読み返せる");

$val_create_res = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => $preview_hash,
    'idempotency_key' => wp_generate_password(16, false, false),
], $req_val_header);
assert_test(is_wp_error($val_create_res) && $val_create_res->get_error_code() === 'mcp_forbidden', "validateのみトークン: create_draft の実行は 403 mcp_forbidden で遮断される");

// -------------------------------------------------------------
// シナリオ 7: REST API (/mcp/character-draft) の整合性検証
// -------------------------------------------------------------
log_section("シナリオ 7: REST API (/mcp/character-draft) の整合性検証");

$dummy_screenshot = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

// ① dry_run: true かつ screenshots あり
$req_rest_dry = new WP_REST_Request('POST', '/kotodaman/v1/mcp/character-draft');
$req_rest_dry->add_header('Authorization', 'Bearer ' . $flow_at);
$req_rest_dry->set_body_params([
    'character_name' => 'RESTプレビューキャラ_' . wp_generate_password(6, false, false),
    'basic_fields'   => ['name_ruby' => 'てすと', 'attribute' => '火', 'species' => '獣', 'rarity' => '5'],
    'auto_input'     => ['auto_input_waza_name' => 'プレビューわざ'],
    'screenshots'    => [$dummy_screenshot],
    'dry_run'        => true,
]);
$res_rest_dry = rest_do_request($req_rest_dry);
assert_test($res_rest_dry->get_status() === 200, "REST: 低権限ユーザーが screenshots 付き dry_run=true で 200 OK (Status: " . $res_rest_dry->get_status() . ")");

// ② 通常作成（実作成）
$req_rest_create = new WP_REST_Request('POST', '/kotodaman/v1/mcp/character-draft');
$req_rest_create->add_header('Authorization', 'Bearer ' . $flow_at);
$req_rest_create->set_body_params([
    'character_name' => 'REST実作成キャラ_' . wp_generate_password(6, false, false),
    'basic_fields'   => ['name_ruby' => 'てすと', 'attribute' => '水', 'species' => '魔', 'rarity' => '6'],
    'auto_input'     => ['auto_input_waza_name' => '実わざ'],
    'dry_run'        => false,
]);
$res_rest_create = rest_do_request($req_rest_create);
$rest_create_data = $res_rest_create->get_data();
$rest_created_pid = is_array($rest_create_data) ? (int) ($rest_create_data['post_id'] ?? 0) : 0;
assert_test($res_rest_create->get_status() === 200 && $rest_created_pid > 0, "REST: 低権限ユーザーが create スコープで下書きを作成できる (ID: {$rest_created_pid})");
if ($rest_created_pid > 0) {
    $cleanup_post_ids[] = $rest_created_pid;
}

// -------------------------------------------------------------
// シナリオ 8: トークン一覧サマリー取得の一致検証
// -------------------------------------------------------------
log_section("シナリオ 8: トークン一覧サマリー取得の一致検証");

$summary = koto_mcp_get_user_tokens_summary($sub_uid);
assert_test(!empty($summary), "ユーザーのトークンサマリーが取得できる");

$matched_token = null;
foreach ($summary as $item) {
    if (($item['status'] ?? '') === '有効' && (int) ($item['expires_at'] ?? 0) > time()) {
        $matched_token = $item;
        break;
    }
}
assert_test($matched_token !== null, "トークンサマリー内にステータスが '有効' かつ期限が未来のアクティブトークンが存在する");

// -------------------------------------------------------------
// シナリオ 9: 通知メール文面の製品固有名非含有
// -------------------------------------------------------------
log_section("シナリオ 9: 通知メール文面の製品固有名非含有");

$captured_email = null;
add_filter('wp_mail', static function ($args) use (&$captured_email) {
    $captured_email = $args;
    return false; // 実際の送信は抑止
});

koto_mcp_send_approval_email($sub_user, false);
assert_test($captured_email !== null, "承認通知メール送信処理が呼ばれる");
$mail_body = $captured_email['message'] ?? '';
assert_test(strpos($mail_body, 'Cursor') === false, "メール本文に 'Cursor' が含まれない");
assert_test(strpos($mail_body, 'Claude') === false, "メール本文に 'Claude' が含まれない");
assert_test(strpos($mail_body, 'Codex') === false, "メール本文に 'Codex' が含まれない");
assert_test(strpos($mail_body, 'お使いのAIクライアント') !== false, "メール本文に 'お使いのAIクライアント' が含まれている");

// -------------------------------------------------------------
// 終了サマリー
// -------------------------------------------------------------
echo "\n" . C_YELLOW . "========================================" . C_RESET . "\n";
echo "テスト結果: 合計 " . ($tests_passed + $tests_failed) . " 件 | 成功: " . C_GREEN . $tests_passed . C_RESET . " | 失敗: " . ($tests_failed > 0 ? C_RED . $tests_failed . C_RESET : "0") . "\n";
if ($tests_failed === 0) {
    echo C_GREEN . "全テスト項目に合格しました！" . C_RESET . "\n";
} else {
    echo C_RED . "一部テストが失敗しました。" . C_RESET . "\n";
    exit(1);
}
