<?php
if (!defined('ABSPATH')) exit;

/**
 * スクリーンショット集からキャラクター下書きを作成するMCP向けREST API。
 *
 * スクリーンショットのOCRや画面判定は呼び出し側で行い、このAPIには
 * 基本ACFへ直接保存する値と、自動入力パーサーへ渡す文言を分けて渡す。
 */
add_action('rest_api_init', 'koto_register_post_mcp_routes');

function koto_mcp_rest_permission_check($request, $required_scope = 'validate')
{
    if (function_exists('koto_mcp_require_scope')) {
        $auth = koto_mcp_require_scope($required_scope, $request instanceof WP_REST_Request ? $request : null);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return true;
    }
    $user = get_userdata(get_current_user_id());
    $has_mcp_role = $user && in_array('mcp_user', (array) $user->roles, true);
    if (!$has_mcp_role && !current_user_can('edit_posts')) {
        return false;
    }
    $user_id = get_current_user_id();
    $privileged = $has_mcp_role || ($user && array_intersect(['administrator', 'editor'], (array) $user->roles));
    if (!$privileged && !get_user_meta($user_id, '_koto_mcp_approved', true)) {
        return new WP_Error('mcp_pending_approval', 'MCP access approval is required.', ['status' => 403]);
    }
    return true;
}

function koto_register_post_mcp_routes()
{
    register_rest_route('kotodaman/v1', '/mcp/schema', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function ($request) {
            $test_mode = (bool) ($request instanceof WP_REST_Request ? $request->get_param('test_mode') : false);
            $result = koto_mcp_call_tool('get_field_schema', ['test_mode' => $test_mode], $request);
            return is_wp_error($result) ? $result : rest_ensure_response($result);
        },
        'permission_callback' => function ($request) {
            return koto_mcp_rest_permission_check($request, 'validate');
        },
        'args'                => [
            'test_mode' => [
                'required' => false,
                'default'  => false,
                'type'     => 'boolean',
            ],
        ],
    ]);

    register_rest_route('kotodaman/v1', '/mcp/draft/(?P<post_id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function ($request) {
            $post_id = (int) ($request instanceof WP_REST_Request ? $request->get_param('post_id') : 0);
            $result = koto_mcp_call_tool('get_draft', ['post_id' => $post_id], $request);
            return is_wp_error($result) ? $result : rest_ensure_response($result);
        },
        'permission_callback' => function ($request) {
            return koto_mcp_rest_permission_check($request, 'validate');
        },
        'args'                => [
            'post_id' => [
                'required' => true,
                'type'     => 'integer',
            ],
        ],
    ]);

    register_rest_route('kotodaman/v1', '/mcp/terms', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => function ($request) {
            $taxonomy = $request instanceof WP_REST_Request ? (string) $request->get_param('taxonomy') : 'all';
            $search = $request instanceof WP_REST_Request ? (string) $request->get_param('search') : '';
            $result = koto_mcp_call_tool('get_terms', ['taxonomy' => $taxonomy ?: 'all', 'search' => $search], $request);
            return is_wp_error($result) ? $result : rest_ensure_response($result);
        },
        'permission_callback' => function ($request) {
            return koto_mcp_rest_permission_check($request, 'validate');
        },
        'args'                => [
            'taxonomy' => [
                'required' => false,
                'default'  => 'all',
                'type'     => 'string',
            ],
            'search' => [
                'required' => false,
                'default'  => '',
                'type'     => 'string',
            ],
        ],
    ]);

    register_rest_route('kotodaman/v1', '/mcp/character-draft', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'koto_mcp_create_character_draft',
        'permission_callback' => function ($request) {
            $dry_run = $request instanceof WP_REST_Request ? (bool) $request->get_param('dry_run') : false;
            $perm = koto_mcp_rest_permission_check($request, $dry_run ? 'preview' : 'create');
            if (is_wp_error($perm) || !$perm) {
                return $perm;
            }
            $screenshots = $request instanceof WP_REST_Request ? $request->get_param('screenshots') : null;
            if (is_array($screenshots) && !empty($screenshots) && !current_user_can('upload_files')) {
                return new WP_Error('forbidden_upload_files', 'screenshots を指定する場合は upload_files 権限が必要です。', ['status' => 403]);
            }
            return true;
        },
        'args'                => [
            'character_name' => [
                'required'          => false,
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
            'preview_hash' => [
                'required' => false,
                'type'     => 'string',
            ],
            'idempotency_key' => [
                'required' => false,
                'type'     => 'string',
            ],
            'dry_run' => [
                'required' => false,
                'default'  => false,
                'type'     => 'boolean',
            ],
            'test_mode' => [
                'required' => false,
                'default'  => false,
                'type'     => 'boolean',
            ],
        ],
    ]);
}

/**
 * タクソノミー入力値（タームID、ターム名、スラッグ、表記ゆれ）から term_id を解決する。
 *
 * @param mixed $item 単一のターム指定値（intまたはstring）。
 * @param string $taxonomy タクソノミー名。
 * @return int 解決できた場合は term_id（1以上）、未解決時は 0。
 */
function koto_mcp_resolve_taxonomy_term_id($item, $taxonomy)
{
    if (!is_string($taxonomy) || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return 0;
    }
    if (is_int($item) || (is_string($item) && ctype_digit(trim($item)))) {
        $id = (int) trim((string) $item);
        if ($id > 0 && term_exists($id, $taxonomy)) {
            return $id;
        }
    }
    if (!is_string($item) && !is_int($item)) {
        return 0;
    }
    $cand = trim((string) $item);
    if ($cand === '') {
        return 0;
    }

    $candidates = [$cand];
    if ($taxonomy === 'attribute') {
        $stripped = preg_replace('/属性$/u', '', $cand);
        if ($stripped !== '' && $stripped !== $cand) {
            $candidates[] = $stripped;
        }
    } elseif ($taxonomy === 'species') {
        $stripped = preg_replace('/種族$/u', '', $cand);
        if ($stripped !== '' && $stripped !== $cand) {
            $candidates[] = $stripped;
        }
    } elseif ($taxonomy === 'rarity') {
        $norm = str_replace(['★', '☆'], '星', $cand);
        if ($norm !== $cand) {
            $candidates[] = $norm;
        }
        $digits = preg_replace('/^[★☆星]/u', '', $cand);
        if ($digits !== '' && $digits !== $cand) {
            $candidates[] = $digits;
            $candidates[] = '星' . $digits;
        }
    }

    foreach (array_unique($candidates) as $c) {
        $matched = get_terms(['taxonomy' => $taxonomy, 'name' => $c, 'hide_empty' => false]);
        if (is_array($matched) && !empty($matched)) {
            foreach ($matched as $mt) {
                if (strpos((string) $mt->slug, '%') === false) {
                    return (int) $mt->term_id;
                }
            }
            return (int) $matched[0]->term_id;
        }
        $term = get_term_by('slug', $c, $taxonomy) ?: get_term_by('name', $c, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }
    return 0;
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
    $rollback = static function ($error) use (&$attachment_ids) {
        foreach ($attachment_ids as $saved_attachment_id) {
            if (is_int($saved_attachment_id)) {
                wp_delete_attachment($saved_attachment_id, true);
            }
        }
        return $error;
    };
    foreach (array_slice($screenshots, 0, 20) as $index => $screenshot) {
        if (!is_array($screenshot) || empty($screenshot['data'])) {
            continue;
        }

        $data_uri = (string) $screenshot['data'];
        if (!preg_match('#^data:(image/(?:png|jpeg|webp));base64,(.+)$#s', $data_uri, $matches)) {
            return $rollback(new WP_Error(
                'invalid_screenshot',
                sprintf('screenshots[%d] はPNG/JPEG/WebPのdata URIで指定してください。', $index),
                ['status' => 400]
            ));
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || $binary === '' || strlen($binary) > 10 * 1024 * 1024) {
            return $rollback(new WP_Error(
                'invalid_screenshot_size',
                sprintf('screenshots[%d] のサイズが不正です。', $index),
                ['status' => 400]
            ));
        }
        if (function_exists('getimagesizefromstring')) {
            $image_info = @getimagesizefromstring($binary);
            if (!is_array($image_info) || ($image_info['mime'] ?? '') !== $matches[1]) {
                return $rollback(new WP_Error(
                    'invalid_screenshot_content',
                    sprintf('screenshots[%d] の画像データが破損しているか、MIMEタイプが一致しません。', $index),
                    ['status' => 400]
                ));
            }
        }

        $extension = $matches[1] === 'image/jpeg' ? 'jpg' : substr($matches[1], 6);
        $filename = sanitize_file_name(($screenshot['name'] ?? 'screenshot-' . ($index + 1)) . '.' . $extension);
        $upload = wp_upload_bits($filename, null, $binary);
        if (!empty($upload['error'])) {
            return $rollback(new WP_Error('screenshot_upload_failed', $upload['error'], ['status' => 500]));
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $matches[1],
            'post_title'     => sanitize_text_field($screenshot['name'] ?? 'キャラクタースクリーンショット'),
            'post_status'    => 'inherit',
            'post_parent'    => $post_id,
        ], $upload['file'], $post_id, true);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            if (!empty($upload['file']) && file_exists($upload['file'])) {
                @unlink($upload['file']);
            }
            return $rollback(is_wp_error($attachment_id) ? $attachment_id : new WP_Error('screenshot_attachment_failed', '添付ファイルの登録に失敗しました。', ['status' => 500]));
        }
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        $attachment_ids[] = $attachment_id;
    }

    return $attachment_ids;
}

/**
 * available_moji_loop の行データを正規化する（文字タームID配列化、文字色・解放場所の初期値補完）。
 *
 * @param array<int, mixed> $rows available_moji_loop の入力配列。
 * @param int|null $default_attr_id キャラクターのメイン属性タームID。
 * @return array<int, array<string, mixed>> 正規化後の行配列。
 */
function koto_mcp_normalize_available_moji_rows($rows, $default_attr_id = null)
{
    if (!is_array($rows)) return [];
    $normalized_rows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $raw_moji = $row['available_moji'] ?? ($row['field_693528c3cf77c'] ?? []);
        $moji_items = is_array($raw_moji) ? $raw_moji : ($raw_moji !== null && $raw_moji !== '' ? [$raw_moji] : []);
        $resolved_moji_ids = [];
        foreach ($moji_items as $item) {
            $tid = koto_mcp_resolve_taxonomy_term_id($item, 'available_moji');
            if ($tid > 0) {
                $resolved_moji_ids[] = $tid;
            }
        }
        $row['available_moji'] = array_values(array_unique($resolved_moji_ids));
        unset($row['field_693528c3cf77c']);

        $raw_attr = $row['moji_attr'] ?? ($row['field_693529bfcf77d'] ?? null);
        if (is_array($raw_attr)) {
            $raw_attr = $raw_attr[0] ?? null;
        }
        if ($raw_attr === null || $raw_attr === '' || $raw_attr === 'default') {
            if ($default_attr_id !== null) {
                $row['moji_attr'] = (int) $default_attr_id;
            }
        } elseif (is_int($raw_attr) || (is_string($raw_attr) && ctype_digit($raw_attr))) {
            $row['moji_attr'] = (int) $raw_attr;
        }
        unset($row['field_693529bfcf77d']);

        $raw_place = $row['unlock_place'] ?? ($row['field_693529d5cf77e'] ?? null);
        if ($raw_place === null || $raw_place === '') {
            $row['unlock_place'] = 'default';
        } else {
            $row['unlock_place'] = (string) $raw_place;
        }
        unset($row['field_693529d5cf77e']);

        $normalized_rows[] = $row;
    }
    return $normalized_rows;
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

    // available_moji_loop のデフォルト属性解決のため、attribute が指定されていれば先に処理する
    if (array_key_exists('attribute', $basic_fields)) {
        $attr_val = $basic_fields['attribute'];
        unset($basic_fields['attribute']);
        $basic_fields = array_merge(['attribute' => $attr_val], $basic_fields);
    }

    $default_attr_id = null;
    if (isset($basic_fields['attribute'])) {
        $raw_attr = is_array($basic_fields['attribute']) ? ($basic_fields['attribute'][0] ?? null) : $basic_fields['attribute'];
        $resolved_attr = koto_mcp_resolve_taxonomy_term_id($raw_attr, 'attribute');
        if ($resolved_attr > 0) {
            $default_attr_id = $resolved_attr;
        }
    }
    if ($default_attr_id === null) {
        $terms = get_the_terms($post_id, 'attribute');
        if ($terms && !is_wp_error($terms) && isset($terms[0])) {
            $default_attr_id = (int) $terms[0]->term_id;
        }
    }

    foreach ($basic_fields as $field_name => $value) {
        if (!is_string($field_name) || $field_name === 'post_title') {
            if ($field_name === 'post_title' && is_scalar($value)) {
                wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($value)]);
            }
            continue;
        }

        if (strpos($field_name, 'field_') === 0) {
            $warnings[] = sprintf('フィールドキーの直接指定はスキップしました: %s', $field_name);
            continue;
        }

        $field = koto_get_top_level_character_acf_field($field_name);
        if (!$field || empty($field['key'])) {
            $warnings[] = sprintf('未知のトップレベルACFフィールドをスキップしました: %s', $field_name);
            continue;
        }

        if (($field['type'] ?? '') === 'taxonomy' && !empty($field['taxonomy']) && $value !== null && $value !== '') {
            $items = is_array($value) ? $value : [$value];
            $resolved_ids = [];
            foreach ($items as $item) {
                $tid = koto_mcp_resolve_taxonomy_term_id($item, $field['taxonomy']);
                if ($tid > 0) {
                    $resolved_ids[] = $tid;
                }
            }
            $resolved_ids = array_values(array_unique($resolved_ids));
            $field_type = $field['field_type'] ?? 'multi_select';
            if (in_array($field_type, ['radio', 'select'], true)) {
                $value = $resolved_ids[0] ?? null;
            } else {
                $value = $resolved_ids;
            }
        }

        if ($field_name === 'name_ruby' && is_string($value)) {
            $value = trim((string) preg_replace('/[\(（][^\)）]*[\)）]|[\(（].*$/u', '', $value));
            $value = mb_convert_kana($value, 'c', 'UTF-8');
            if ($value === '') {
                continue;
            }
        }
        if ($field_name === 'available_moji_loop' && is_array($value)) {
            $value = koto_mcp_normalize_available_moji_rows($value, $default_attr_id);
        }
        if (!empty($field['sub_fields']) && is_array($value)) {
            $value = koto_convert_acf_rows_to_field_keys($value, $field);
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
    $preview_hash = sanitize_text_field((string) $request->get_param('preview_hash'));
    $idempotency_key = sanitize_text_field((string) $request->get_param('idempotency_key'));
    $dry_run = (bool) $request->get_param('dry_run');
    $test_mode = (bool) $request->get_param('test_mode');
    $prev_test_mode = $GLOBALS['koto_auto_input_test_mode'] ?? false;
    $GLOBALS['koto_auto_input_test_mode'] = $test_mode;
    $user_id = get_current_user_id();

    if ($user_id > 0) {
        $rate_key = 'koto_mcp_rate_' . $user_id;
        $now = time();
        $rate_state = get_transient($rate_key);
        if (is_array($rate_state) && isset($rate_state['count'], $rate_state['reset_at']) && (int) $rate_state['reset_at'] > $now) {
            $rate_count = (int) $rate_state['count'];
            $reset_at = (int) $rate_state['reset_at'];
        } else {
            $rate_count = 0;
            $reset_at = $now + MINUTE_IN_SECONDS;
        }
        if ($rate_count >= 60) {
            $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
            return new WP_Error('rate_limited', 'リクエスト回数が上限に達しました。しばらく待ってから再試行してください。', ['status' => 429]);
        }
        set_transient($rate_key, ['count' => $rate_count + 1, 'reset_at' => $reset_at], max(1, $reset_at - $now));
    }

    if (!$dry_run && $preview_hash !== '' && $character_name === '' && $basic_fields === null && $auto_input === null && function_exists('koto_mcp_preview_cache_key')) {
        $cached_preview = get_transient(koto_mcp_preview_cache_key($user_id, $preview_hash));
        if (is_array($cached_preview)) {
            $character_name = sanitize_text_field((string) ($cached_preview['character_name'] ?? ''));
            $basic_fields = $cached_preview['basic_fields'] ?? [];
            $auto_input = $cached_preview['auto_input'] ?? [];
            if ($screenshot_urls === null && isset($cached_preview['screenshot_urls'])) {
                $screenshot_urls = $cached_preview['screenshot_urls'];
            }
        }
    }

    if ($character_name === '') {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('missing_character_name', 'character_name は必須です（または preview_hash のキャッシュが期限切れです）。', ['status' => 400]);
    }
    if (mb_strlen($character_name, 'UTF-8') > 200) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('invalid_character_name', 'character_name は200文字以内で指定してください。', ['status' => 400]);
    }
    $basic_fields_is_list = is_array($basic_fields) && !empty($basic_fields) && array_keys($basic_fields) === range(0, count($basic_fields) - 1);
    if ($basic_fields !== null && (!is_array($basic_fields) || $basic_fields_is_list)) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('invalid_basic_fields', 'basic_fields はオブジェクトで指定してください。', ['status' => 400]);
    }
    if (is_array($basic_fields)) {
        foreach ($basic_fields as $bf_k => $bf_v) {
            if (is_string($bf_v) && mb_strlen($bf_v, 'UTF-8') > 2000) {
                $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
                return new WP_Error('invalid_basic_fields_value', sprintf('basic_fields.%s が最大長（2000文字）を超えています。', $bf_k), ['status' => 400]);
            }
        }
    }
    $auto_input_is_list = is_array($auto_input) && !empty($auto_input) && array_keys($auto_input) === range(0, count($auto_input) - 1);
    if ($auto_input !== null && (!is_array($auto_input) || $auto_input_is_list)) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('invalid_auto_input', 'auto_input はオブジェクトで指定してください。', ['status' => 400]);
    }
    if (is_array($auto_input)) {
        foreach ($auto_input as $input_key => $input_val) {
            if (!is_string($input_key) || ($input_val !== null && !is_string($input_val))) {
                $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
                return new WP_Error('invalid_auto_input_value', 'auto_input の各値は文字列で指定してください。', ['status' => 400]);
            }
            if (is_string($input_val) && mb_strlen($input_val, 'UTF-8') > 4000) {
                $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
                return new WP_Error('invalid_auto_input_value', sprintf('auto_input.%s が最大長（4000文字）を超えています。', $input_key), ['status' => 400]);
            }
        }
    }
    if ($screenshots !== null && !is_array($screenshots)) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('invalid_screenshots', 'screenshots は配列で指定してください。', ['status' => 400]);
    }
    if ($screenshot_urls !== null && !is_array($screenshot_urls)) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('invalid_screenshot_urls', 'screenshot_urls は配列で指定してください。', ['status' => 400]);
    }
    if (is_array($screenshot_urls)) {
        foreach ($screenshot_urls as $url_item) {
            if (!is_string($url_item)) {
                $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
                return new WP_Error('invalid_screenshot_urls_item', 'screenshot_urls の各要素は文字列で指定してください。', ['status' => 400]);
            }
        }
    }

    if (!is_array($basic_fields)) {
        $basic_fields = [];
    }
    if (!is_array($auto_input)) {
        $auto_input = [];
    }
    if (!empty($screenshots) && !current_user_can('upload_files')) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('forbidden_upload_files', 'screenshots を指定する場合は upload_files 権限が必要です。', ['status' => 403]);
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
    $parsed_counts = [];
    foreach ($acf_data as $k => $rows) {
        $parsed_counts[$k] = is_array($rows) ? count($rows) : 1;
    }
    $computed_hash = function_exists('koto_mcp_content_hash')
        ? koto_mcp_content_hash(['character_name' => $character_name, 'basic_fields' => $basic_fields, 'auto_input' => $auto_input], $acf_data)
        : '';

    $auto_warnings = function_exists('koto_mcp_detect_auto_input_warnings')
        ? koto_mcp_detect_auto_input_warnings($auto_input, $acf_data, $grouped_csv)
        : [];

    if ($dry_run) {
        if ($computed_hash !== '' && function_exists('koto_mcp_preview_cache_key')) {
            set_transient(
                koto_mcp_preview_cache_key($user_id, $computed_hash),
                [
                    'character_name'  => $character_name,
                    'basic_fields'    => $basic_fields,
                    'auto_input'      => $auto_input,
                    'screenshot_urls' => $screenshot_urls,
                ],
                30 * MINUTE_IN_SECONDS
            );
        }
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        $resp = [
            'dry_run'         => true,
            'character_name'  => $character_name,
            'preview_hash'    => $computed_hash,
            'basic_fields'    => array_keys($basic_fields),
            'auto_input'      => array_keys($acf_data),
            'parsed_counts'   => $parsed_counts,
            'warnings'        => $auto_warnings,
            'screenshot_urls' => $screenshot_urls,
        ];
        if ($test_mode) {
            $resp['test_mode'] = true;
            $resp['parsed_acf'] = $acf_data;
        }
        return rest_ensure_response($resp);
    }

    if ($preview_hash !== '' && $computed_hash !== '' && !hash_equals($computed_hash, $preview_hash)) {
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error('preview_hash_mismatch', 'preview_hash が現在の入力内容と一致しません。', ['status' => 400]);
    }

    $idempotency_option = '';
    $fingerprint = 'rest_create|' . $computed_hash;
    if ($idempotency_key !== '' && function_exists('koto_mcp_reserve_idempotency_key')) {
        $reservation = koto_mcp_reserve_idempotency_key($user_id, $idempotency_key, $fingerprint);
        if (is_wp_error($reservation)) {
            $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
            return $reservation;
        }
        if (!empty($reservation['result'])) {
            $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
            return rest_ensure_response($reservation['result']);
        }
        $idempotency_option = (string) ($reservation['option'] ?? '');
    }

    $post_id = koto_create_character_post_from_acf($character_name, []);
    if (!$post_id || is_wp_error($post_id)) {
        if ($idempotency_option !== '' && function_exists('koto_mcp_release_idempotency_key')) {
            koto_mcp_release_idempotency_key($idempotency_option);
        }
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        return new WP_Error(
            'character_draft_failed',
            is_wp_error($post_id) ? $post_id->get_error_message() : 'キャラクター下書きの作成に失敗しました。',
            ['status' => 500]
        );
    }

    $warnings = array_merge(
        koto_mcp_update_basic_fields($post_id, $basic_fields),
        $auto_warnings
    );
    koto_update_character_post_with_acf($post_id, $acf_data);
    $requested_auto_inputs = array_filter($auto_input, static function ($value) {
        return $value !== null && $value !== '';
    });
    foreach ($requested_auto_inputs as $input_key => $input_value) {
        if (!array_key_exists($input_key, $acf_data) || empty($acf_data[$input_key])) {
            $warnings[] = sprintf('%s を解析できませんでした。', $input_key);
        }
    }
    $warnings = array_values(array_unique($warnings));
    $attachment_ids = koto_mcp_save_screenshots($screenshots, $post_id);
    if (is_wp_error($attachment_ids)) {
        if ($idempotency_option !== '' && function_exists('koto_mcp_release_idempotency_key')) {
            koto_mcp_release_idempotency_key($idempotency_option);
        }
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
        wp_delete_post($post_id, true);
        return $attachment_ids;
    }

    if (!empty($screenshot_urls)) {
        update_post_meta($post_id, '_koto_mcp_screenshot_urls', $screenshot_urls);
        if (function_exists('koto_mcp_set_featured_image_from_url')) {
            koto_mcp_set_featured_image_from_url($post_id, $screenshot_urls[0]);
        }
    } elseif (defined('KOTO_MCP_DEFAULT_IMAGE_URL') && function_exists('koto_mcp_set_featured_image_from_url')) {
        update_post_meta($post_id, '_koto_mcp_screenshot_urls', [KOTO_MCP_DEFAULT_IMAGE_URL]);
        koto_mcp_set_featured_image_from_url($post_id, KOTO_MCP_DEFAULT_IMAGE_URL);
    }

    update_post_meta($post_id, '_koto_mcp_generated', 1);
    update_post_meta($post_id, '_koto_mcp_needs_review', 1);
    update_post_meta($post_id, '_koto_mcp_audit', ['user_id' => $user_id, 'content_hash' => $computed_hash, 'warnings' => $warnings, 'created_at' => current_time('mysql')]);
    do_action('acf/save_post', $post_id);
    if (function_exists('koto_mcp_maybe_cleanup_expired_options')) {
        koto_mcp_maybe_cleanup_expired_options();
    }
    $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;

    $resp = [
        'post_id'          => $post_id,
        'post_status'      => get_post_status($post_id),
        'edit_url'         => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id),
        'content_hash'     => $computed_hash,
        'screenshot_ids'   => $attachment_ids,
        'screenshot_urls'  => get_post_meta($post_id, '_koto_mcp_screenshot_urls', true) ?: $screenshot_urls,
        'basic_fields'     => array_keys($basic_fields),
        'auto_input'       => array_keys($acf_data),
        'parsed_counts'    => $parsed_counts,
        'warnings'         => $warnings,
    ];
    if ($test_mode) {
        $resp['test_mode'] = true;
        $resp['parsed_acf'] = $acf_data;
    }
    if ($idempotency_option !== '' && function_exists('koto_mcp_complete_idempotency_key')) {
        koto_mcp_complete_idempotency_key($idempotency_option, $resp, $fingerprint);
    }
    return rest_ensure_response($resp);
}
