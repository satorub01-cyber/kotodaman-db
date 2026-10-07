<?php
/**
 * キャラ画像設定 Ajax処理
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
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

