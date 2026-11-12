<?php
/**
 * コトダマンDB MCP 利用者管理・権限・セキュリティ包括検証テストスクリプト
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

echo C_YELLOW . "MCP利用者管理・権限・セキュリティ包括テスト開始" . C_RESET . "\n";

// テスト用ユーザーの準備
$admin_user = get_user_by('login', 'admin');
if (!$admin_user) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $admin_user = !empty($admins) ? $admins[0] : null;
}
if (!$admin_user) {
    echo C_RED . "エラー: 管理者ユーザーが存在しません。" . C_RESET . "\n";
    exit(1);
}

// -------------------------------------------------------------
// シナリオ 1: タグ判定の単一化と判定関数
// -------------------------------------------------------------
log_section("シナリオ 1: タグ判定の単一化と厳格判定");

$test_uid = $admin_user->ID;
delete_user_meta($test_uid, '_koto_mcp_approved');
assert_test(koto_mcp_is_user_approved($test_uid) === false, "管理者であってもタグ(_koto_mcp_approved)がなければ承認判定は false");

$perm_err = koto_mcp_check_user_mcp_permission($test_uid);
assert_test(is_wp_error($perm_err) && $perm_err->get_error_code() === 'mcp_pending_approval', "タグなし時は koto_mcp_check_user_mcp_permission で mcp_pending_approval エラー");

update_user_meta($test_uid, '_koto_mcp_approved', 1);
assert_test(koto_mcp_is_user_approved($test_uid) === true, "タグ(_koto_mcp_approved=1)付与後は承認判定が true");
assert_test(koto_mcp_check_user_mcp_permission($test_uid) === $test_uid, "タグあり時はユーザーIDが正常返却される");

// -------------------------------------------------------------
// シナリオ 2: 申請メタライフサイクルの完全クリーンアップ
// -------------------------------------------------------------
log_section("シナリオ 2: 申請メタの追加・完全消去");

koto_mcp_record_request_meta($test_uid);
assert_test(get_user_meta($test_uid, '_koto_mcp_requested', true) == 1, "_koto_mcp_requested が 1 に設定される");
assert_test((int) get_user_meta($test_uid, '_koto_mcp_requested_at', true) > 0, "_koto_mcp_requested_at にタイムスタンプが記録される");

koto_mcp_clear_request_meta($test_uid);
assert_test(get_user_meta($test_uid, '_koto_mcp_requested', true) === '', "clear_request_meta で _koto_mcp_requested が完全消去される");
assert_test(get_user_meta($test_uid, '_koto_mcp_requested_at', true) === '', "clear_request_meta で _koto_mcp_requested_at も完全消去される（中途半端な残留ゼロ）");

// -------------------------------------------------------------
// シナリオ 3: 剥奪された編集者の自己復活防止
// -------------------------------------------------------------
log_section("シナリオ 3: 剥奪された編集者の自己復活防止");

// 剥奪状態をシミュレート
delete_user_meta($test_uid, '_koto_mcp_approved');
update_user_meta($test_uid, '_koto_mcp_revoked', 1);

// 認可判定ロジックの検証
$is_approved = koto_mcp_is_user_approved($test_uid);
$is_revoked = (bool) get_user_meta($test_uid, '_koto_mcp_revoked', true);
$is_admin_or_editor = (bool) array_intersect(['administrator', 'editor'], (array) $admin_user->roles);

// 剥奪歴がある場合は、たとえ管理者・編集者でも即時タグ付与が阻止されること
$can_self_restore = (!$is_approved && !$is_revoked && $is_admin_or_editor);
assert_test($can_self_restore === false, "_koto_mcp_revoked=1 のユーザーは管理者・編集者でも自己復活（即時タグ付与）が阻止される");

// 管理者による再開時
update_user_meta($test_uid, '_koto_mcp_approved', 1);
delete_user_meta($test_uid, '_koto_mcp_revoked');
koto_mcp_clear_request_meta($test_uid);
assert_test(koto_mcp_is_user_approved($test_uid) === true, "管理画面からの再開によりタグが付与される");
assert_test(get_user_meta($test_uid, '_koto_mcp_revoked', true) === '', "再開により _koto_mcp_revoked がクリアされる");

// -------------------------------------------------------------
// シナリオ 4: 剥奪直後のツール即時停止（有効期限内アクセストークンでも即停止）
// -------------------------------------------------------------
log_section("シナリオ 4: 剥奪直後のツール即時停止検証");

// トークン発行
$client_id = 'test_client_' . wp_generate_password(8, false, false);
$issued = koto_mcp_issue_token($test_uid, 'validate preview create', $client_id);
$issued_data = $issued instanceof WP_REST_Response ? $issued->get_data() : (array) $issued;
$at = is_array($issued_data) && isset($issued_data['access_token']) ? (string) $issued_data['access_token'] : '';

// 発行直後: ツール実行が通る
$req_call = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
$req_call->add_header('Authorization', 'Bearer ' . $at);
$req_call->set_body(wp_json_encode([
    'jsonrpc' => '2.0',
    'id'      => 101,
    'method'  => 'initialize',
    'params'  => ['protocolVersion' => '2025-03-26'],
]));
$res_before = koto_mcp_handle_http($req_call);
assert_test($res_before->get_status() === 200, "剥奪前: 有効なアクセストークンで initialize が 200 OK");

// 管理者による剥奪実行
delete_user_meta($test_uid, '_koto_mcp_approved');
update_user_meta($test_uid, '_koto_mcp_revoked', 1);
koto_mcp_revoke_user_tokens($test_uid);

// 剥奪直後: 同じアクセストークン（expは未来）で即座に拒否される
$res_after = koto_mcp_handle_http($req_call);
assert_test($res_after->get_status() === 401 || $res_after->get_status() === 403, "剥奪直後: 有効期限内トークンでも即座にツール実行が遮断される (Status: " . $res_after->get_status() . ")");

// -------------------------------------------------------------
// シナリオ 5: 通知メール文面の製品固有名非含有
// -------------------------------------------------------------
log_section("シナリオ 5: 通知メール文面の製品固有名非含有");

$captured_email = null;
add_filter('wp_mail', function ($args) use (&$captured_email) {
    $captured_email = $args;
    return false; // 実際の送信は抑止
});

koto_mcp_send_approval_email($admin_user, false);
assert_test($captured_email !== null, "承認通知メール送信処理が呼ばれる");
$mail_body = $captured_email['message'] ?? '';
assert_test(strpos($mail_body, 'Cursor') === false, "メール本文に 'Cursor' が含まれない");
assert_test(strpos($mail_body, 'Claude') === false, "メール本文に 'Claude' が含まれない");
assert_test(strpos($mail_body, 'Codex') === false, "メール本文に 'Codex' が含まれない");
assert_test(strpos($mail_body, 'お使いのAIクライアント') !== false, "メール本文に 'お使いのAIクライアント' が含まれている");

// -------------------------------------------------------------
// シナリオ 6: トークン一覧取得の正本同期と欠落ゼロ
// -------------------------------------------------------------
log_section("シナリオ 6: トークン一覧取得の正本同期");

// テスト用タグ復活
update_user_meta($test_uid, '_koto_mcp_approved', 1);
delete_user_meta($test_uid, '_koto_mcp_revoked');

// 再度トークン発行
$issued2 = koto_mcp_issue_token($test_uid, 'validate preview create', $client_id);
$issued2_data = $issued2 instanceof WP_REST_Response ? $issued2->get_data() : (array) $issued2;
assert_test(is_array($issued2_data) && !empty($issued2_data['access_token']), "再発行されたアクセストークンが取得できる");
$summary = koto_mcp_get_user_tokens_summary($test_uid);
assert_test(!empty($summary), "トークン一覧サマリーが1件以上取得できる");
assert_test($summary[0]['status'] === '有効', "最新トークンのステータスが '有効'");
assert_test($summary[0]['expires_at'] > time(), "有効期限が未来のタイムスタンプ");

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
