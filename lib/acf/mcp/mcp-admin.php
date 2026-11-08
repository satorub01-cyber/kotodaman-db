<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP 管理画面モジュール
 *
 * WordPress管理画面「ユーザー > MCP利用申請」メニューの登録、
 * 承認待ち・承認済みユーザー一覧表示、利用承認・却下・トークン無効化アクションを提供します。
 */

add_action('admin_menu', 'koto_mcp_register_approval_menu');
add_action('admin_init', 'koto_mcp_handle_approval_action');

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
