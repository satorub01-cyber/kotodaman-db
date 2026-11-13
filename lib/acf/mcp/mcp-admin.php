<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP 管理画面モジュール
 *
 * WordPress管理画面「ユーザー > MCP利用者管理」メニューの登録、
 * 承認待ち・MCP利用可能者（トークン詳細含む）一覧表示、
 * 承認・却下・剥奪・再開（手動付与）アクションおよび通知メール送信を提供します。
 * 
 * ※ manage_options（管理者権限）保持者にのみメニューを表示し、操作を許可します。
 */

add_action('admin_menu', 'koto_mcp_register_approval_menu');
add_action('admin_init', 'koto_mcp_handle_approval_action');

function koto_mcp_register_approval_menu()
{
    // manage_options 権限を指定することで、管理者以外にはメニューおよびリンクが一切表示されない
    add_users_page('MCP利用者管理', 'MCP利用者管理', 'manage_options', 'koto-mcp-approvals', 'koto_mcp_approval_page');
}

/**
 * 指定ユーザーの全発行済みトークン情報を正本から解決して返す
 *
 * @param int $user_id
 * @return array<int, array<string, mixed>>
 */
function koto_mcp_get_user_tokens_summary($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return [];

    $family_ids = get_user_meta($user_id, '_koto_mcp_family_ids', true);
    if (!is_array($family_ids)) {
        $family_ids = [];
    }

    // フォールバック: メタが空の場合は wp_options から走査補完
    if (empty($family_ids)) {
        global $wpdb;
        $like = $wpdb->esc_like('koto_mcp_refresh_') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
            $like,
            $wpdb->esc_like('koto_mcp_refresh_family_') . '%',
            $wpdb->esc_like('koto_mcp_refresh_lock_') . '%'
        ));
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $v = maybe_unserialize($r->option_value);
                if (is_array($v) && (int) ($v['user_id'] ?? 0) === $user_id && !empty($v['family_id'])) {
                    $family_ids[] = (string) $v['family_id'];
                }
            }
            $family_ids = array_values(array_unique($family_ids));
        }
    }

    $tokens = [];
    $now = time();

    foreach ($family_ids as $fid) {
        $fid = (string) $fid;
        if ($fid === '') continue;

        $family_opt = koto_mcp_refresh_family_option_name($fid);
        $family = koto_mcp_storage_get($family_opt) ?: get_option($family_opt);
        if (!is_array($family)) continue;

        // 正本ファミリーから最新アクティブリフレッシュレコードを取得
        $active_refresh_hash = (string) ($family['active_refresh_hash'] ?? '');
        $refresh_record = null;
        if ($active_refresh_hash !== '') {
            $record_opt = koto_mcp_refresh_record_option_name($active_refresh_hash);
            $refresh_record = koto_mcp_storage_get($record_opt) ?: get_option($record_opt);
        }

        $status = '有効';
        if (($family['status'] ?? 'active') !== 'active') {
            $status = '失効';
        } elseif (is_array($refresh_record) && (int) ($refresh_record['expires_at'] ?? 0) <= $now) {
            $status = '期限切れ';
        }

        $client_id = is_array($refresh_record) ? (string) ($refresh_record['client_id'] ?? '') : '';
        $client = koto_mcp_storage_get('koto_mcp_client_' . $client_id) ?: get_transient('koto_mcp_client_' . $client_id);
        $client_name = is_array($client) ? ($client['client_name'] ?? $client['name'] ?? $client_id) : ($client_id ?: '不明');

        $tokens[] = [
            'client_name' => $client_name,
            'issued_at'   => is_array($refresh_record) ? (int) ($refresh_record['issued_at'] ?? 0) : (int) ($family['updated_at'] ?? 0),
            'expires_at'  => is_array($refresh_record) ? (int) ($refresh_record['expires_at'] ?? 0) : 0,
            'scope'       => is_array($refresh_record) ? (string) ($refresh_record['scope'] ?? '') : '',
            'status'      => $status,
        ];
    }

    return $tokens;
}

/**
 * 承認・再開通知メール送信（AIクライアント固有名は含めない）
 *
 * @param WP_User $user
 * @param bool $is_resume
 */
function koto_mcp_send_approval_email($user, $is_resume = false)
{
    if (!$user instanceof WP_User || empty($user->user_email)) return;

    $subject = $is_resume
        ? '[コトダマンDB] MCP利用が再開されました'
        : '[コトダマンDB] MCP利用申請が承認されました';

    $action_label = $is_resume ? '再開' : '承認';

    $message = sprintf(
        "%s 様\n\n" .
        "コトダマンDB MCPの利用が%sされました。\n\n" .
        "【ご利用開始の手順】\n" .
        "ご利用を開始するには、お使いのAIクライアントから再度接続（認可）を行ってください。\n" .
        "ブラウザで認可画面が表示されましたら、「許可」ボタンを押すことで接続が完了します。\n\n" .
        "※本メールは送信専用です。",
        $user->display_name ?: $user->user_login,
        $action_label
    );

    wp_mail($user->user_email, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
}

function koto_mcp_approval_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('この画面を表示する権限がありません。', 'permission_denied', ['response' => 403]);
    }

    $pending_users = get_users(['meta_key' => '_koto_mcp_requested', 'meta_value' => 1]);
    $approved_users = get_users(['meta_key' => '_koto_mcp_approved', 'meta_value' => 1]);

    // 未許可・停止中ユーザー一覧（手動付与用セレクタ）
    $all_users = get_users(['number' => 500, 'orderby' => 'display_name']);
    $non_approved_users = array_filter($all_users, static function ($u) {
        return (string) get_user_meta($u->ID, '_koto_mcp_approved', true) !== '1';
    });

    echo '<div class="wrap">';
    echo '<h1>コトダマンDB MCP 利用者管理</h1>';

    // 1. 承認待ちの申請一覧
    echo '<h2>承認待ちの申請一覧</h2>';
    if (empty($pending_users)) {
        echo '<p>現在、承認待ちの申請はありません。</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ユーザー名</th><th>表示名</th><th>メールアドレス</th><th>権限グループ</th><th>申請日時</th><th>操作</th></tr></thead>';
        echo '<tbody>';
        foreach ($pending_users as $user) {
            $req_at = (int) get_user_meta($user->ID, '_koto_mcp_requested_at', true);
            $req_date_str = $req_at > 0 ? wp_date('Y-m-d H:i:s', $req_at) : '日時不明';

            $approve_url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_approve=' . $user->ID), 'koto_mcp_approve_' . $user->ID);
            $reject_url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_reject=' . $user->ID), 'koto_mcp_reject_' . $user->ID);
            echo '<tr>';
            echo '<td><strong>' . esc_html($user->user_login) . '</strong></td>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html(implode(', ', (array) $user->roles)) . '</td>';
            echo '<td>' . esc_html($req_date_str) . '</td>';
            echo '<td>';
            echo '<a class="button button-primary" href="' . esc_url($approve_url) . '">承認</a> ';
            echo '<a class="button button-secondary" href="' . esc_url($reject_url) . '" onclick="return confirm(\'申請を却下しますか？\');">却下</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // 2. MCP利用可能者一覧（タグ保持者）
    echo '<h2 style="margin-top:30px;">MCP利用可能者一覧</h2>';
    if (empty($approved_users)) {
        echo '<p>現在、MCP利用が許可されているユーザーはいません。</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ユーザー名</th><th>表示名</th><th>メールアドレス</th><th>権限グループ</th><th>発行中トークン情報</th><th>操作</th></tr></thead>';
        echo '<tbody>';
        foreach ($approved_users as $user) {
            $revoke_url = wp_nonce_url(admin_url('users.php?page=koto-mcp-approvals&koto_mcp_revoke_user=' . $user->ID), 'koto_mcp_revoke_user_' . $user->ID);
            $tokens = koto_mcp_get_user_tokens_summary($user->ID);

            echo '<tr>';
            echo '<td><strong>' . esc_html($user->user_login) . '</strong></td>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html(implode(', ', (array) $user->roles)) . '</td>';
            echo '<td>';
            if (empty($tokens)) {
                echo '<span style="color:#666;">未発行（OAuth未接続）</span>';
            } else {
                echo '<ul style="margin:0; padding-left:15px;">';
                foreach ($tokens as $t) {
                    $status_color = $t['status'] === '有効' ? '#2e7d32' : '#c62828';
                    $exp_str = $t['expires_at'] > 0 ? wp_date('Y-m-d H:i', $t['expires_at']) : '期限なし';
                    $iss_str = $t['issued_at'] > 0 ? wp_date('Y-m-d H:i', $t['issued_at']) : '不明';
                    echo '<li>';
                    echo '<strong>' . esc_html($t['client_name']) . '</strong> ';
                    echo '<span style="color:' . esc_attr($status_color) . '; font-weight:bold;">[' . esc_html($t['status']) . ']</span> ';
                    echo '<span style="color:#555; font-size:12px;">(発行: ' . esc_html($iss_str) . ' / 期限: ' . esc_html($exp_str) . ' / スコープ: ' . esc_html($t['scope']) . ')</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</td>';
            echo '<td>';
            echo '<a class="button button-secondary" style="color:#a00; border-color:#a00;" href="' . esc_url($revoke_url) . '" onclick="return confirm(\'利用を停止（タグ剥奪）し、発行済み全トークンを無効化しますか？\\n※本人のWordPressアカウント自体は停止されません。\');">利用停止（剥奪）</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // 3. 停止中・未許可ユーザーへの手動付与（再開）
    echo '<h2 style="margin-top:30px;">ユーザーへのMCP利用許可（再開・手動付与）</h2>';
    echo '<form method="post" style="display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:10px;">';
    wp_nonce_field('koto_mcp_grant_user_action');
    echo '<label for="koto_mcp_grant_user_id">ユーザーを選択:</label>';
    echo '<select id="koto_mcp_grant_user_id" name="koto_mcp_grant_user_id">';
    echo '<option value="">-- 一覧から選択 --</option>';
    foreach ($non_approved_users as $u) {
        $is_revoked = get_user_meta($u->ID, '_koto_mcp_revoked', true);
        $suffix = $is_revoked ? ' (※利用停止中)' : '';
        echo '<option value="' . esc_attr($u->ID) . '">' . esc_html($u->user_login . ' (' . ($u->display_name ?: '名前なし') . ' / ' . implode(',', (array) $u->roles) . ')' . $suffix) . '</option>';
    }
    echo '</select>';
    echo '<span>または</span>';
    echo '<label for="koto_mcp_grant_user_identifier">直接指定 (ID / ログイン名 / メール):</label>';
    echo '<input type="text" id="koto_mcp_grant_user_identifier" name="koto_mcp_grant_user_identifier" placeholder="例: 123 または username" style="width:220px;">';
    echo '<button type="submit" class="button button-primary">MCP利用タグを付与（再開）</button>';
    echo '</form>';
    echo '<p class="description">※管理画面からタグを付与すると、停止中フラグは解除され、対象ユーザーに再接続案内メールが自動送信されます。一覧に表示されない場合も直接指定欄から付与可能です。</p>';

    echo '</div>';
}

function koto_mcp_handle_approval_action()
{
    if (!current_user_can('manage_options')) return;

    // 1. 承認アクション
    if (!empty($_GET['koto_mcp_approve'])) {
        $user_id = (int) $_GET['koto_mcp_approve'];
        check_admin_referer('koto_mcp_approve_' . $user_id);
        $user = get_userdata($user_id);
        if ($user) {
            $was_revoked = (bool) get_user_meta($user_id, '_koto_mcp_revoked', true);
            update_user_meta($user_id, '_koto_mcp_approved', 1);
            delete_user_meta($user_id, '_koto_mcp_revoked');
            koto_mcp_clear_request_meta($user_id); // 申請メタ完全消去

            // 管理者・編集者以外、または停止中からの再開時に案内メール送信
            $is_admin_or_editor = (bool) array_intersect(['administrator', 'editor'], (array) $user->roles);
            if (!$is_admin_or_editor || $was_revoked) {
                koto_mcp_send_approval_email($user, $was_revoked);
            }
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }

    // 2. 却下アクション
    if (!empty($_GET['koto_mcp_reject'])) {
        $user_id = (int) $_GET['koto_mcp_reject'];
        check_admin_referer('koto_mcp_reject_' . $user_id);
        if (get_userdata($user_id)) {
            koto_mcp_clear_request_meta($user_id); // 申請メタ完全消去
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }

    // 3. 剥奪アクション
    if (!empty($_GET['koto_mcp_revoke_user'])) {
        $user_id = (int) $_GET['koto_mcp_revoke_user'];
        check_admin_referer('koto_mcp_revoke_user_' . $user_id);
        if (get_userdata($user_id)) {
            delete_user_meta($user_id, '_koto_mcp_approved');
            update_user_meta($user_id, '_koto_mcp_revoked', 1); // 剥奪履歴フラグを記録
            koto_mcp_clear_request_meta($user_id); // 申請メタ完全消去
            koto_mcp_revoke_user_tokens($user_id); // 全トークン即時失効
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }

    // 4. 再開・手動付与アクション
    if (!empty($_POST['koto_mcp_grant_user_id']) || !empty($_POST['koto_mcp_grant_user_identifier'])) {
        check_admin_referer('koto_mcp_grant_user_action');
        $user = null;
        $user_id = 0;

        if (!empty($_POST['koto_mcp_grant_user_identifier'])) {
            $identifier = sanitize_text_field(wp_unslash($_POST['koto_mcp_grant_user_identifier']));
            if (ctype_digit($identifier)) {
                $user = get_userdata((int) $identifier);
            } else {
                $user = get_user_by('login', $identifier) ?: get_user_by('email', $identifier);
            }
            if ($user) {
                $user_id = (int) $user->ID;
            }
        } elseif (!empty($_POST['koto_mcp_grant_user_id'])) {
            $user_id = (int) $_POST['koto_mcp_grant_user_id'];
            $user = get_userdata($user_id);
        }

        if ($user && $user_id > 0) {
            $was_revoked = (bool) get_user_meta($user_id, '_koto_mcp_revoked', true);
            update_user_meta($user_id, '_koto_mcp_approved', 1);
            delete_user_meta($user_id, '_koto_mcp_revoked');
            koto_mcp_clear_request_meta($user_id); // 申請メタ完全消去
            koto_mcp_send_approval_email($user, $was_revoked);
        }
        wp_safe_redirect(admin_url('users.php?page=koto-mcp-approvals'));
        exit;
    }
}
