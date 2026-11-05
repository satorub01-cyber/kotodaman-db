<?php
if (!defined('ABSPATH')) exit;

/**
 * スクリーンショット集からキャラクター下書きを作成するMCP向けREST API。
 *
 * スクリーンショットのOCRや画面判定は呼び出し側で行い、このAPIには
 * 基本ACFへ直接保存する値と、自動入力パーサーへ渡す文言を分けて渡す。
 */
add_action('rest_api_init', 'koto_register_post_mcp_routes');

function koto_register_post_mcp_routes()
{
    register_rest_route('kotodaman/v1', '/mcp/character-draft', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'koto_mcp_create_character_draft',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args'                => [
            'character_name' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'basic_fields' => [
                'required' => false,
                'type'     => 'object',
            ],
            'auto_input' => [
                'required' => false,
                'type'     => 'object',
            ],
            'screenshots' => [
                'required' => false,
                'type'     => 'array',
            ],
            'screenshot_urls' => [
                'required' => false,
                'type'     => 'array',
            ],
            'dry_run' => [
                'required' => false,
                'default'  => false,
                'type'     => 'boolean',
            ],
        ],
    ]);
}

/**
 * MCPから受け取った画像データをメディアとして保存する。
 *
 * @param array<int, mixed> $screenshots data URI形式の画像配列。
 * @param int $post_id 添付先投稿ID。
 * @return array<int, int|WP_Error> 添付ファイルIDまたはエラー。
 */
function koto_mcp_save_screenshots($screenshots, $post_id)
{
    if (empty($screenshots) || !is_array($screenshots)) {
        return [];
    }

    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $attachment_ids = [];
    foreach (array_slice($screenshots, 0, 20) as $index => $screenshot) {
        if (!is_array($screenshot) || empty($screenshot['data'])) {
            continue;
        }

        $data_uri = (string) $screenshot['data'];
        if (!preg_match('#^data:(image/(?:png|jpeg|webp));base64,(.+)$#s', $data_uri, $matches)) {
            return [new WP_Error(
                'invalid_screenshot',
                sprintf('screenshots[%d] はPNG/JPEG/WebPのdata URIで指定してください。', $index),
                ['status' => 400]
            )];
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 10 * 1024 * 1024) {
            return [new WP_Error(
                'invalid_screenshot_size',
                sprintf('screenshots[%d] のサイズが不正です。', $index),
                ['status' => 400]
            )];
        }

        $extension = $matches[1] === 'image/jpeg' ? 'jpg' : substr($matches[1], 6);
        $filename = sanitize_file_name(($screenshot['name'] ?? 'screenshot-' . ($index + 1)) . '.' . $extension);
        $upload = wp_upload_bits($filename, null, $binary);
        if (!empty($upload['error'])) {
            return [new WP_Error('screenshot_upload_failed', $upload['error'], ['status' => 500])];
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $matches[1],
            'post_title'     => sanitize_text_field($screenshot['name'] ?? 'キャラクタースクリーンショット'),
            'post_status'    => 'inherit',
            'post_parent'    => $post_id,
        ], $upload['file'], $post_id);

        if (is_wp_error($attachment_id)) {
            return [$attachment_id];
        }
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        $attachment_ids[] = $attachment_id;
    }

    return $attachment_ids;
}

/**
 * 基本ACF値をフィールド定義で検証して直接保存する。
 *
 * @param int $post_id 投稿ID。
 * @param array<string, mixed> $basic_fields 基本ACF値。
 * @return array<int, string> 保存できなかったフィールドの警告。
 */
function koto_mcp_update_basic_fields($post_id, $basic_fields)
{
    $warnings = [];
    if (!is_array($basic_fields)) {
        return ['basic_fields はオブジェクトで指定してください。'];
    }

    foreach ($basic_fields as $field_name => $value) {
        if (!is_string($field_name) || $field_name === 'post_title') {
            if ($field_name === 'post_title' && is_scalar($value)) {
                wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($value)]);
            }
            continue;
        }

        $field = koto_get_top_level_character_acf_field($field_name);
        if (!$field || empty($field['key'])) {
            $warnings[] = sprintf('未知のトップレベルACFフィールドをスキップしました: %s', $field_name);
            continue;
        }
        update_field($field['key'], $value, $post_id);
    }

    return $warnings;
}

/**
 * キャラクター下書きを作成するRESTコールバック。
 *
 * @param WP_REST_Request $request RESTリクエスト。
 * @return WP_REST_Response|WP_Error
 */
function koto_mcp_create_character_draft($request)
{
    $character_name = sanitize_text_field((string) $request->get_param('character_name'));
    $basic_fields = $request->get_param('basic_fields');
    $auto_input = $request->get_param('auto_input');
    $screenshots = $request->get_param('screenshots');
    $screenshot_urls = $request->get_param('screenshot_urls');
    $dry_run = (bool) $request->get_param('dry_run');

    if ($character_name === '') {
        return new WP_Error('missing_character_name', 'character_name は必須です。', ['status' => 400]);
    }
    if (!is_array($basic_fields)) {
        $basic_fields = [];
    }
    if (!is_array($auto_input)) {
        $auto_input = [];
    }
    if (!is_array($screenshot_urls)) {
        $screenshot_urls = [];
    }

    $screenshot_urls = array_values(array_filter(array_map(function ($url) {
        return is_string($url) ? esc_url_raw(trim($url)) : '';
    }, $screenshot_urls)));

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $grouped_csv = koto_group_csv_by_type(koto_load_csv_dictionary($csv_path));
    $acf_data = koto_build_acf_data_from_inputs($auto_input, $grouped_csv);

    if ($dry_run) {
        return rest_ensure_response([
            'dry_run'        => true,
            'character_name' => $character_name,
            'basic_fields'   => array_keys($basic_fields),
            'auto_input'     => array_keys($acf_data),
            'screenshot_urls' => $screenshot_urls,
            'parsed_acf'     => $acf_data,
        ]);
    }

    $post_id = koto_create_character_post_from_acf($character_name, $acf_data);
    if (!$post_id || is_wp_error($post_id)) {
        return new WP_Error(
            'character_draft_failed',
            is_wp_error($post_id) ? $post_id->get_error_message() : 'キャラクター下書きの作成に失敗しました。',
            ['status' => 500]
        );
    }

    $warnings = koto_mcp_update_basic_fields($post_id, $basic_fields);
    if (!empty($auto_input) && empty($acf_data)) {
        $warnings[] = 'auto_input を解析できる文言がありませんでした。';
    }
    $attachment_ids = koto_mcp_save_screenshots($screenshots, $post_id);
    foreach ($attachment_ids as $attachment_id) {
        if (is_wp_error($attachment_id)) {
            foreach ($attachment_ids as $saved_attachment_id) {
                if (is_int($saved_attachment_id)) {
                    wp_delete_attachment($saved_attachment_id, true);
                }
            }
            wp_delete_post($post_id, true);
            return $attachment_id;
        }
    }

    if (!empty($screenshot_urls)) {
        update_post_meta($post_id, '_koto_mcp_screenshot_urls', $screenshot_urls);
    }

    do_action('acf/save_post', $post_id);

    return rest_ensure_response([
        'post_id'          => $post_id,
        'post_status'      => get_post_status($post_id),
        'edit_url'         => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id),
        'screenshot_ids'   => $attachment_ids,
        'screenshot_urls'  => $screenshot_urls,
        'basic_fields'     => array_keys($basic_fields),
        'auto_input'       => array_keys($acf_data),
        'warnings'         => $warnings,
    ]);
}
