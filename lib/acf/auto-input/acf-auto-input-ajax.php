<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/acf-auto-input-wp-acf.php';

// =================================================================
// AJAXリクエストのフック
// =================================================================
add_action('wp_ajax_koto_parse_auto_input', 'koto_ajax_parse_auto_input');
add_action('wp_ajax_koto_update_post_from_auto_input', 'koto_ajax_update_post_from_auto_input');
add_action('wp_ajax_koto_create_post_from_auto_input', 'koto_ajax_create_post_from_auto_input');

/**
 * 自動入力のプレビュー解析を行うAJAXエンドポイント。
 * POSTされた文言をCSV照合し、解析結果をJSONで返す。
 *
 * @return void
 */
function koto_ajax_parse_auto_input()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません'], 403);
    }

    if (!check_ajax_referer('koto_auto_input_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'セキュリティチェックに失敗しました'], 403);
    }

    $texts = isset($_POST['texts']) ? wp_unslash($_POST['texts']) : [];
    $context = [
        'post_id' => isset($_POST['post_id']) ? intval($_POST['post_id']) : 0,
        'rarity'  => isset($_POST['rarity']) ? sanitize_text_field(wp_unslash($_POST['rarity'])) : null,
    ];

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $parsed_data = koto_build_acf_data_from_inputs($texts, $grouped_csv, $context);

    wp_send_json_success($parsed_data);
}

/**
 * 既存投稿へ自動入力結果を反映するAJAXエンドポイント。
 *
 * @return void
 */
function koto_ajax_update_post_from_auto_input()
{
    if (!check_ajax_referer('koto_auto_input_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'セキュリティチェックに失敗しました'], 403);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $texts = isset($_POST['texts']) ? wp_unslash($_POST['texts']) : [];

    if (!$post_id) {
        wp_send_json_error(['message' => '対象の記事が選択されていません']);
    }

    $target_post = get_post($post_id);
    if (!($target_post instanceof WP_Post) || $target_post->post_type !== 'character') {
        wp_send_json_error(['message' => '対象のキャラクター記事が見つかりません'], 400);
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'この記事を編集する権限がありません'], 403);
    }

    $context = [
        'post_id' => $post_id,
        'rarity'  => isset($_POST['rarity']) ? sanitize_text_field(wp_unslash($_POST['rarity'])) : null,
    ];

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $acf_data = koto_build_acf_data_from_inputs($texts, $grouped_csv, $context);

    if (empty($acf_data)) {
        wp_send_json_error([
            'message' => 'パース結果が空です。対応表に未登録の文言、またはJSONの生成に失敗した可能性があります。',
        ]);
    }

    $result = koto_update_character_post_with_acf($post_id, $acf_data);

    if ($result) {
        // ACFフィールドの更新後に計算用データを再生成（spec等の更新）
        do_action('acf/save_post', $post_id);

        $warnings = function_exists('koto_get_blessing_auto_input_warnings') ? koto_get_blessing_auto_input_warnings() : [];
        $message = '自動入力を反映しました。内容を保存します。';
        if (!empty($warnings)) {
            $message .= "\n\n【警告】\n・" . implode("\n・", $warnings);
        }

        wp_send_json_success([
            'message'  => $message,
            'warnings' => $warnings,
        ]);
    } else {
        wp_send_json_error(['message' => '自動入力の反映に失敗しました']);
    }
}

/**
 * 自動入力結果を使って新規投稿を作成するAJAXエンドポイント。
 *
 * @return void
 */
function koto_ajax_create_post_from_auto_input()
{
    if (!check_ajax_referer('koto_auto_input_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'セキュリティチェックに失敗しました'], 403);
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '新規記事を作成する権限がありません'], 403);
    }

    $texts = isset($_POST['texts']) ? wp_unslash($_POST['texts']) : [];
    $character_name = isset($_POST['character_name']) ? sanitize_text_field($_POST['character_name']) : '';
    $context = [
        'rarity' => isset($_POST['rarity']) ? sanitize_text_field(wp_unslash($_POST['rarity'])) : null,
    ];

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $acf_data = koto_build_acf_data_from_inputs($texts, $grouped_csv, $context);

    $post_id = koto_create_character_post_from_acf($character_name, $acf_data);

    if ($post_id && !is_wp_error($post_id)) {
        // ACFフィールドの更新後に計算用データを再生成（spec等の更新）
        do_action('acf/save_post', $post_id);

        $warnings = function_exists('koto_get_blessing_auto_input_warnings') ? koto_get_blessing_auto_input_warnings() : [];
        $message = '記事を作成しました';
        if (!empty($warnings)) {
            $message .= "\n\n【警告】\n・" . implode("\n・", $warnings);
        }

        wp_send_json_success([
            'post_id'  => $post_id,
            'message'  => $message,
            'warnings' => $warnings,
            'edit_url' => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id)
        ]);
    } else {
        wp_send_json_error(['message' => '記事の作成に失敗しました']);
    }
}
