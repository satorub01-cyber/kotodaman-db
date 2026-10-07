<?php
/**
 * キャラ画像設定 Ajax処理 & データ取得関数
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 未使用（未紐付け）画像を取得する共通関数
 * 
 * @param string $search 検索文字列
 * @param int $paged ページ番号
 * @param int $per_page 1ページあたりの取得件数
 * @return array ['items' => array, 'has_more' => bool, 'total' => int]
 */
function image_setter_get_unused_images($search = '', $paged = 1, $per_page = 60) {
    global $wpdb;

    $paged = max(1, intval($paged));
    $per_page = max(1, min(120, intval($per_page)));
    $offset = ($paged - 1) * $per_page;

    // 1. 使用中画像IDのリストを取得
    $meta_keys = ['character_image', 'another_character_image', 'pre_evo_image', '_thumbnail_id'];
    $keys_placeholder = implode("','", array_map('esc_sql', $meta_keys));

    $used_ids = $wpdb->get_col("
        SELECT DISTINCT CAST(meta_value AS UNSIGNED)
        FROM {$wpdb->postmeta}
        WHERE meta_key IN ('$keys_placeholder')
        AND meta_value REGEXP '^[0-9]+$'
    ");

    // 2. 準備中画像のIDも除外
    $prep_ids = $wpdb->get_col("
        SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
        AND (guid LIKE '%無題51_20260205005357%' OR post_name LIKE '%無題51_20260205005357%')
    ");
    if (!empty($prep_ids)) {
        $used_ids = array_merge($used_ids, $prep_ids);
    }

    $not_in_sql = '';
    if (!empty($used_ids)) {
        $used_clean = implode(',', array_map('intval', array_unique($used_ids)));
        $not_in_sql = "AND ID NOT IN ({$used_clean})";
    }

    // 3. 検索条件
    $search_sql = '';
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $search_sql = $wpdb->prepare("AND (post_title LIKE %s OR post_name LIKE %s)", $like, $like);
    }

    // 4. データ取得（LIMIT付きでメモリを保護）
    $sql = "
        SELECT ID, post_title, post_name, guid
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
        AND post_mime_type LIKE 'image/%'
        {$not_in_sql}
        {$search_sql}
        ORDER BY ID DESC
        LIMIT " . ($per_page + 1) . " OFFSET {$offset}
    ";

    $raw_results = $wpdb->get_results($sql);
    $has_more = count($raw_results) > $per_page;

    if ($has_more) {
        array_pop($raw_results);
    }

    $items = [];
    foreach ($raw_results as $m) {
        $full_src = wp_get_attachment_url($m->ID);
        $thumb_src = wp_get_attachment_image_url($m->ID, 'medium') ?: $full_src;
        $file_name = basename(get_attached_file($m->ID) ?: $full_src);

        $items[] = [
            'id'        => $m->ID,
            'title'     => $m->post_title ?: $file_name,
            'filename'  => $file_name,
            'thumb_url' => $thumb_src,
            'full_url'  => $full_src,
        ];
    }

    return [
        'items'    => $items,
        'has_more' => $has_more,
        'page'     => $paged,
    ];
}

/**
 * Ajax: 画像の検索・追加読み込み
 */
add_action('wp_ajax_image_setter_search_media', 'image_setter_handle_search_media');

function image_setter_handle_search_media() {
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません。']);
    }

    $posted_nonce = $_POST['nonce'] ?? $_REQUEST['nonce'] ?? '';
    if (!wp_verify_nonce($posted_nonce, 'image_setter_nonce')) {
        wp_send_json_error(['message' => 'セキュリティチェックに失敗しました。']);
    }

    $search = sanitize_text_field($_POST['search'] ?? '');
    $paged = max(1, intval($_POST['paged'] ?? 1));

    $data = image_setter_get_unused_images($search, $paged, 60);
    wp_send_json_success($data);
}

/**
 * Ajax: キャラ画像割り当て保存ハンドラ
 */
add_action('wp_ajax_image_setter_save_image', 'image_setter_handle_save_image');

function image_setter_handle_save_image() {
    // 権限チェック
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません。']);
    }

    // Nonceチェック (POST / REQUEST の双方に対応)
    $posted_nonce = $_POST['nonce'] ?? $_REQUEST['nonce'] ?? '';
    if (!wp_verify_nonce($posted_nonce, 'image_setter_nonce')) {
        wp_send_json_error(['message' => 'セキュリティチェックに失敗しました。ページを再読み込みしてください。']);
    }

    $char_id = isset($_POST['character_id']) ? intval($_POST['character_id']) : 0;
    $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
    $image_type = isset($_POST['image_type']) ? sanitize_key($_POST['image_type']) : '';

    if (!$char_id || !get_post($char_id)) {
        wp_send_json_error(['message' => 'キャラクターが正しく選択されていません。']);
    }

    if (!$image_id || !wp_attachment_is_image($image_id)) {
        wp_send_json_error(['message' => '指定された画像が存在しません。']);
    }

    $field_map = [
        'pre_evo'   => 'pre_evo_image',
        'character' => 'character_image',
        'another'   => 'another_character_image',
    ];

    $label_map = [
        'pre_evo'   => '進化前',
        'character' => '進化後',
        'another'   => '絵違い',
    ];

    if (!isset($field_map[$image_type])) {
        wp_send_json_error(['message' => '不正な画像タイプです。']);
    }

    $field_name = $field_map[$image_type];
    $label_name = $label_map[$image_type];

    // ACFフィールド更新（ACFがない場合は通常のpostmeta）
    if (function_exists('update_field')) {
        update_field($field_name, $image_id, $char_id);
    } else {
        update_post_meta($char_id, $field_name, $image_id);
    }

    // 進化後画像の場合はアイキャッチ画像（サムネイル）も同時に更新
    $thumb_updated = false;
    if ($image_type === 'character') {
        set_post_thumbnail($char_id, $image_id);
        $thumb_updated = true;
    }

    $char_title = get_the_title($char_id);
    $msg = "{$char_title}の{$label_name}に設定しました";

    // 現在の各画像設定状況を取得して返す
    $pre_id = get_post_meta($char_id, 'pre_evo_image', true);
    $main_id = get_post_meta($char_id, 'character_image', true);
    $ano_id = get_post_meta($char_id, 'another_character_image', true);
    $current_thumb_id = get_post_thumbnail_id($char_id);

    wp_send_json_success([
        'message'        => $msg,
        'character_id'   => $char_id,
        'character_name' => $char_title,
        'image_id'       => $image_id,
        'image_type'     => $image_type,
        'image_label'    => $label_name,
        'thumb_updated'  => $thumb_updated,
        'status' => [
            'has_pre'     => !empty($pre_id),
            'has_main'    => !empty($main_id),
            'has_another' => !empty($ano_id),
            'has_thumb'   => !empty($current_thumb_id),
        ]
    ]);
}
