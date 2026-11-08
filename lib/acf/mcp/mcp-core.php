<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP サーバー共通基盤モジュール
 *
 * 定数定義、雛型記事保護判定、テストモード判定、URLヘルパー、
 * Idempotency（冪等性）管理、ガベージコレクション、ロール初期化を提供します。
 */

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
if (!defined('KOTO_MCP_ACCESS_TOKEN_TTL')) {
    define('KOTO_MCP_ACCESS_TOKEN_TTL', HOUR_IN_SECONDS); // 1時間（3600秒）
}
if (!defined('KOTO_MCP_REFRESH_TOKEN_TTL')) {
    define('KOTO_MCP_REFRESH_TOKEN_TTL', 30 * DAY_IN_SECONDS); // 30日
}

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

function koto_mcp_server_url()
{
    return koto_mcp_base_url() . '/mcp';
}

function koto_mcp_is_object_like_array($value)
{
    if (!is_array($value)) return false;
    if (empty($value)) return true;
    return array_keys($value) !== range(0, count($value) - 1);
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
