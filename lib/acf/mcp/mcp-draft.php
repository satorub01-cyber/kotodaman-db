<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP キャラクター下書き処理モジュール
 *
 * 下書きバリデーション（koto_mcp_validate_draft）、既存キャラクターデータ抽出、
 * 自動入力警告検出、コンテンツハッシュ計算、アイキャッチ設定・ACF画像連動を提供します。
 */

/**
 * 既存キャラクター下書き投稿から basic_fields と MCPメタデータを抽出する。
 *
 * @param int $post_id 投稿ID。
 * @return array<string, mixed>|null 抽出データ。投稿が存在しない場合は null。
 */
function koto_mcp_extract_existing_draft_data($post_id)
{
    $post = get_post((int) $post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'character') {
        return null;
    }

    $basic_fields = [];
    $single_tax_fields = [
        'attribute'     => 'attribute',
        'sub_attribute' => 'attribute',
        'species'       => 'species',
        'rarity'        => 'rarity',
    ];
    foreach ($single_tax_fields as $fname => $tax) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $raw = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }
        $tid = (is_numeric($raw) && (int) $raw > 0) ? (int) $raw : 0;
        if ($tid <= 0 && $fname !== 'sub_attribute') {
            $terms = get_the_terms($post->ID, $tax);
            if (is_array($terms) && !empty($terms) && isset($terms[0]->term_id)) {
                $tid = (int) $terms[0]->term_id;
            }
        }
        if ($tid > 0) {
            $term = get_term($tid, $tax);
            $basic_fields[$fname] = ($term && !is_wp_error($term)) ? $term->name : $tid;
        }
    }

    $multi_tax_fields = [
        'affiliation' => 'affiliation',
        'event'       => 'event',
    ];
    foreach ($multi_tax_fields as $fname => $tax) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $raw = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        $ids = is_array($raw) ? array_filter(array_map('intval', $raw)) : (is_numeric($raw) && (int) $raw > 0 ? [(int) $raw] : []);
        if (empty($ids)) {
            $terms = get_the_terms($post->ID, $tax);
            if (is_array($terms) && !empty($terms)) {
                foreach ($terms as $t) {
                    if (isset($t->term_id)) {
                        $ids[] = (int) $t->term_id;
                    }
                }
            }
        }
        if (!empty($ids)) {
            $names = [];
            foreach (array_unique($ids) as $tid) {
                $term = get_term($tid, $tax);
                $names[] = ($term && !is_wp_error($term)) ? $term->name : $tid;
            }
            $basic_fields[$fname] = $names;
        }
    }

    $scalar_fields = [
        'name_ruby',
        'status_auto_tf',
        'hp_chouka',
        'atk_chouka',
        'lv_99_hp',
        'lv_99_atk',
        'lv_120_hp',
        'lv_120_atk',
        '99_hp',
        '99_atk',
        '120_hp',
        '120_atk',
        'plus_hp',
        'plus_atk',
        'max_ls_hp',
        'max_ls_atk',
        'fuku_break',
        'get_place',
        'implement_date',
        'cv',
        'acquisition_text',
        'illust_text',
    ];
    foreach ($scalar_fields as $fname) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $val = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        if ($val === null || $val === '' || $val === false) {
            continue;
        }
        if (($fdef['type'] ?? '') === 'true_false') {
            $basic_fields[$fname] = (bool) $val;
        } elseif (($fdef['type'] ?? '') === 'number') {
            if (is_numeric($val)) {
                $basic_fields[$fname] = strpos((string) $val, '.') !== false ? (float) $val : (int) $val;
            }
        } else {
            $basic_fields[$fname] = $val;
        }
    }

    $moji_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('available_moji_loop') : null;
    if ($moji_fdef && !empty($moji_fdef['key'])) {
        $raw_rows = get_field($moji_fdef['key'], $post->ID, false);
        if (is_array($raw_rows) && !empty($raw_rows)) {
            $moji_rows = [];
            foreach ($raw_rows as $r) {
                if (!is_array($r)) continue;
                $m_raw = $r['available_moji'] ?? ($r['field_693528c3cf77c'] ?? []);
                $m_ids = is_array($m_raw) ? $m_raw : ($m_raw !== null && $m_raw !== '' ? [$m_raw] : []);
                $m_names = [];
                foreach ($m_ids as $mid) {
                    if (is_numeric($mid) && (int) $mid > 0) {
                        $t = get_term((int) $mid, 'available_moji');
                        $m_names[] = ($t && !is_wp_error($t)) ? $t->name : (int) $mid;
                    } elseif (is_string($mid) && $mid !== '') {
                        $m_names[] = $mid;
                    }
                }
                if (empty($m_names)) continue;
                $row_out = [
                    'available_moji' => count($m_names) === 1 ? $m_names[0] : $m_names,
                ];
                $place = $r['unlock_place'] ?? ($r['field_693529d5cf77e'] ?? 'default');
                if ($place !== '' && $place !== null) {
                    $row_out['unlock_place'] = (string) $place;
                }
                $group_cond = $r['moji_group_cond'] ?? ($r['field_69f1b4c1fe16b'] ?? null);
                if ($group_cond !== null && $group_cond !== '' && $group_cond !== false && $group_cond !== 0 && $group_cond !== '0') {
                    $row_out['moji_group_cond'] = (bool) $group_cond;
                }
                $pt = $r['unlock_need_point'] ?? ($r['field_6937a4df8ab14'] ?? ($r['field_693529fccf77f'] ?? ''));
                if ($pt !== '' && $pt !== null) {
                    $row_out['unlock_need_point'] = is_numeric($pt) ? (int) $pt : $pt;
                }
                $moji_rows[] = $row_out;
            }
            if (!empty($moji_rows)) {
                $basic_fields['available_moji_loop'] = $moji_rows;
            }
        }
    }

    $audit = get_post_meta($post->ID, '_koto_mcp_audit', true);
    $screenshots = get_post_meta($post->ID, '_koto_mcp_screenshot_urls', true);

    return [
        'post_id'        => (int) $post->ID,
        'character_name' => (string) $post->post_title,
        'status'         => (string) $post->post_status,
        'edit_url'       => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post->ID),
        'basic_fields'   => $basic_fields,
        'mcp_meta'       => [
            'generated'       => (bool) get_post_meta($post->ID, '_koto_mcp_generated', true),
            'needs_review'    => (bool) get_post_meta($post->ID, '_koto_mcp_needs_review', true),
            'screenshot_urls' => is_array($screenshots) ? $screenshots : [],
            'audit'           => is_array($audit) ? $audit : null,
        ],
    ];
}

/**
 * auto_input の部分未解析（丸数字区分や＋区切りの一部脱落）を検出して警告配列を返す。
 *
 * @param array<string, string> $safe_auto_input 入力文言連想配列。
 * @param array<string, mixed> $parsed 解析結果連想配列。
 * @param array<string, array<int, array<string, mixed>>> $csv 種別グループ済みCSV辞書。
 * @return array<int, string> 警告メッセージ配列。
 */
function koto_mcp_detect_auto_input_warnings($safe_auto_input, $parsed, $csv)
{
    $warnings = [];
    if (!function_exists('koto_split_by_circled_numbers') || !function_exists('koto_preprocess_text')) {
        return $warnings;
    }

    foreach ($safe_auto_input as $input_key => $input_value) {
        if ($input_value === '' || !isset($parsed[$input_key]) || empty($parsed[$input_key])) {
            continue;
        }
        if (in_array($input_key, ['auto_input_waza_name', 'auto_input_sugowaza_name'], true)) {
            continue;
        }

        $type = '';
        if (strpos($input_key, 'trait') !== false) {
            $type = 'とくせい';
        } elseif (strpos($input_key, 'kotowaza') !== false) {
            $type = 'コトわざ';
        } elseif (strpos($input_key, 'waza') !== false && strpos($input_key, 'sugowaza_condition') === false) {
            $type = 'わざ';
        } elseif (strpos($input_key, 'sugowaza_condition') !== false) {
            $type = 'すごわざ発動条件';
        } elseif (strpos($input_key, 'blessing') !== false) {
            $type = '祝福';
        } elseif (strpos($input_key, 'leader') !== false) {
            $type = 'リーダーとくせい';
        }
        if ($type === '' || $type === 'コトわざ') {
            continue;
        }

        $prep_text = koto_preprocess_text($input_value, $type);
        if ($type === 'わざ') {
            $waza_rows = $csv['わざ'] ?? [];
            $waza_condition_rows = $csv['わざ条件'] ?? [];
            $waza_shift_rows = $csv['わざシフトタイプ'] ?? [];
            $shift_type = koto_match_csv_template($prep_text, $waza_shift_rows, $input_key, 'prefix');
            if (koto_is_csv_template_match($shift_type)) {
                $prep_text = trim(mb_substr($prep_text, mb_strlen($shift_type['matched_text'])));
            }
            $parent_parts = koto_split_by_circled_numbers($prep_text);
            foreach ($parent_parts as $part_idx => $part) {
                $remaining_text = trim((string) $part);
                if ($remaining_text === '') continue;
                $has_cond = false;
                while (mb_strlen($remaining_text, 'UTF-8') > 0) {
                    $prev_len = mb_strlen($remaining_text, 'UTF-8');
                    $remaining_text = preg_replace('/^(?:[・\s、,]+|(?:または|もしくは)[・\s、,]*)+/u', '', $remaining_text);
                    if ($remaining_text === '') break;
                    $match = koto_match_csv_template($remaining_text, $waza_condition_rows, $input_key, 'prefix');
                    if (!koto_is_csv_template_match($match)) break;
                    $has_cond = true;
                    $remaining_text = trim(mb_substr($remaining_text, mb_strlen($match['matched_text'])));
                    if (mb_strlen($remaining_text, 'UTF-8') >= $prev_len) break;
                }
                if ($has_cond) {
                    $remaining_text = preg_replace('/^(?:の場合|の時|のとき|場合|時|とき|で)?[・\s、,]+/u', '', $remaining_text);
                }
                $child_parts = preg_split('/(?:\+|、さらに)/u', $remaining_text);
                if (!is_array($child_parts)) continue;
                foreach ($child_parts as $child_part) {
                    $child_part = preg_replace('/^[、,\s]+/u', '', (string) $child_part);
                    $child_part = preg_replace('/[、,\s]+$/u', '', $child_part);
                    if ($child_part === '') continue;
                    $match = koto_match_csv_template($child_part, $waza_rows, $input_key);
                    if (!koto_is_csv_template_match($match)) {
                        $warnings[] = sprintf('%s の一部の効果文言を解析できませんでした: %s', $input_key, $child_part);
                    }
                }
            }
            continue;
        }

        $parts = koto_split_by_circled_numbers($prep_text);
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                $trimmed = trim((string) $part);
                if ($trimmed === '') continue;
                $sub_parsed = koto_parse_text_by_type($trimmed, $type, $csv, $input_key);
                $is_empty_sub = empty($sub_parsed) || ($type === 'すごわざ発動条件' && empty($sub_parsed[0]['sugo_cond_loop']));
                if ($is_empty_sub) {
                    $warnings[] = sprintf('%s の一部の文言を解析できませんでした: %s', $input_key, $trimmed);
                }
            }
        }
    }

    return array_values(array_unique($warnings));
}

function koto_mcp_validate_draft(&$args)
{
    $errors = ['missing_fields' => [], 'invalid_fields' => []];

    if (!empty($args['post_id'])) {
        $post_id = (int) $args['post_id'];
        if (koto_mcp_is_protected_template_post($post_id)) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', 'post_id', 'protected_template', 'Template posts cannot be accessed or modified via MCP.');
        } else {
            $existing_data = koto_mcp_extract_existing_draft_data($post_id);
            if ($existing_data) {
                if ((!isset($args['character_name']) || trim((string) $args['character_name']) === '') && !empty($existing_data['character_name'])) {
                    $args['character_name'] = $existing_data['character_name'];
                }
                $existing_bf = is_array($existing_data['basic_fields'] ?? null) ? $existing_data['basic_fields'] : [];
                if (!isset($args['basic_fields']) || !is_array($args['basic_fields'])) {
                    $args['basic_fields'] = [];
                }
                foreach (['attribute', 'species', 'rarity'] as $req_k) {
                    $is_empty_field = !array_key_exists($req_k, $args['basic_fields']) || $args['basic_fields'][$req_k] === null || $args['basic_fields'][$req_k] === '' || (is_array($args['basic_fields'][$req_k]) && count($args['basic_fields'][$req_k]) === 0);
                    if ($is_empty_field && array_key_exists($req_k, $existing_bf)) {
                        $args['basic_fields'][$req_k] = $existing_bf[$req_k];
                    }
                }
            }
        }
    }

    $fields = is_array($args['basic_fields'] ?? null) ? $args['basic_fields'] : [];
    $schema = koto_mcp_field_schema();
    foreach (['attribute', 'species', 'rarity'] as $required) {
        $is_empty_array = is_array($fields[$required] ?? null) && count($fields[$required]) === 0;
        if (!array_key_exists($required, $fields) || $fields[$required] === null || $fields[$required] === '' || $is_empty_array) {
            $errors['missing_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $required, 'required_empty');
        }
    }
    foreach ($fields as $name => $value) {
        if (!isset($schema[$name])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_value');
            continue;
        }
        if ($value === null) continue;
        $definition = $schema[$name];
        if (!empty($definition['taxonomy'])) {
            if (is_array($value) && count($value) === 0) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'required_empty');
                continue;
            }
            $ids = is_array($value) ? $value : [$value];
            foreach ($ids as $term_item) {
                $resolved_tid = function_exists('koto_mcp_resolve_taxonomy_term_id')
                    ? koto_mcp_resolve_taxonomy_term_id($term_item, $definition['taxonomy'])
                    : ((is_int($term_item) || (is_string($term_item) && ctype_digit($term_item))) && term_exists((int) $term_item, $definition['taxonomy']) ? (int) $term_item : 0);
                if ($resolved_tid <= 0) {
                    $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                }
            }
            continue;
        }

        if (!empty($definition['choices']) && is_array($definition['choices'])) {
            $values = is_array($value) ? $value : [$value];
            if (count($values) === 0) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'required_empty');
                continue;
            }
            foreach ($values as $choice) {
                if (!is_scalar($choice) || is_bool($choice) || !array_key_exists((string) $choice, $definition['choices'])) {
                    $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_choice');
                }
            }
        }

        if ($definition['type'] === 'number' || $definition['type'] === 'range') {
            if (!is_numeric($value)) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_number');
                continue;
            }
            $number = (float) $value;
            if ($definition['min'] !== null && is_numeric($definition['min']) && $number < (float) $definition['min']) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'below_min');
            }
            if ($definition['max'] !== null && is_numeric($definition['max']) && $number > (float) $definition['max']) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'above_max');
            }
            continue;
        }

        if ($definition['type'] === 'true_false') {
            $valid_bool = is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
            if (!$valid_bool) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_boolean');
            }
            continue;
        }

        if ($definition['type'] === 'repeater') {
            $is_list = is_array($value) && (empty($value) || array_keys($value) === range(0, count($value) - 1));
            if (!$is_list) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                continue;
            }
            if ($name === 'available_moji_loop') {
                $allowed_places = ['default', 'first_trait', 'second_trait', 'blessing', 'super_copy', 'super_change', 'super_both'];
                foreach ($value as $row) {
                    if (!koto_mcp_is_object_like_array($row)) {
                        $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                        break;
                    }
                    $raw_moji = $row['available_moji'] ?? ($row['field_693528c3cf77c'] ?? null);
                    $moji_items = is_array($raw_moji) ? $raw_moji : ($raw_moji !== null && $raw_moji !== '' ? [$raw_moji] : []);
                    if (empty($moji_items)) {
                        $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'required_empty');
                        break;
                    }
                    foreach ($moji_items as $m_item) {
                        if (is_int($m_item) || (is_string($m_item) && ctype_digit($m_item))) {
                            if (!term_exists((int) $m_item, 'available_moji')) {
                                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                            }
                        } elseif (is_string($m_item) && trim($m_item) !== '') {
                            $resolved_tid = koto_mcp_resolve_taxonomy_term_id(trim($m_item), 'available_moji');
                            if ($resolved_tid <= 0) {
                                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'unknown_term');
                            }
                        } else {
                            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                        }
                    }
                    if (isset($row['unlock_place']) && $row['unlock_place'] !== '' && $row['unlock_place'] !== null) {
                        if (!is_string($row['unlock_place']) || !in_array($row['unlock_place'], $allowed_places, true)) {
                            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_choice');
                        }
                    }
                }
            }
            continue;
        }

        if ($definition['type'] === 'group') {
            if (!koto_mcp_is_object_like_array($value)) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
            }
            continue;
        }

        if (in_array($definition['type'], ['text', 'textarea', 'url', 'email', 'date_picker', 'select', 'radio'], true) && (!is_string($value) && !is_numeric($value))) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
        }
    }
    $raw_auto_input = is_array($args['auto_input'] ?? null) ? $args['auto_input'] : [];
    $safe_auto_input = [];
    foreach ($raw_auto_input as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $safe_auto_input[$k] = $v;
        }
    }
    $csv = koto_group_csv_by_type(koto_load_csv_dictionary(get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv'));
    $parsed = koto_build_acf_data_from_inputs($safe_auto_input, $csv);
    $requested = array_filter($raw_auto_input, static function ($value) { return $value !== null && $value !== ''; });
    foreach ($requested as $input_key => $input_value) {
        if (!array_key_exists($input_key, $parsed) || empty($parsed[$input_key])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $input_key, 'unparsed_text');
        }
    }
    $warnings = koto_mcp_detect_auto_input_warnings($safe_auto_input, $parsed, $csv);
    return ['errors' => $errors, 'parsed_acf' => $parsed, 'warnings' => $warnings, 'normalized_args' => $args];
}

function koto_mcp_content_hash($args, $parsed)
{
    return hash('sha256', wp_json_encode(['character_name' => $args['character_name'] ?? '', 'basic_fields' => $args['basic_fields'] ?? [], 'auto_input' => $args['auto_input'] ?? [], 'parsed_acf' => $parsed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function koto_mcp_set_featured_image_from_url($post_id, $url)
{
    global $wpdb;
    $url = esc_url_raw($url);
    $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
    if (!$url || !in_array($scheme, ['https', 'http'], true) || wp_parse_url($url, PHP_URL_HOST) !== 'www.kotodaman-db.com') return;
    $existing = attachment_url_to_postid($url);
    if (!$existing) {
        $raw_basename = rawurldecode(basename((string) wp_parse_url($url, PHP_URL_PATH)));
        $stem = pathinfo($raw_basename, PATHINFO_FILENAME);
        if ($stem !== '') {
            $like_file = '%' . $wpdb->esc_like($raw_basename);
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND (guid LIKE %s OR post_title = %s OR post_name = %s) ORDER BY ID ASC LIMIT 1",
                $like_file,
                $stem,
                sanitize_title($stem)
            ));
        }
    }
    if ($existing) {
        if (!wp_attachment_is_image($existing)) return;
        set_post_thumbnail($post_id, $existing);
        $image_field = koto_get_top_level_character_acf_field('character_image');
        if ($image_field) update_field($image_field['key'], $existing, $post_id);
        return;
    }

    if ($url !== KOTO_MCP_DEFAULT_IMAGE_URL && !current_user_can('upload_files')) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($url, 15);
    if (is_wp_error($tmp)) return;
    $filesize = @filesize($tmp);
    $image_info = function_exists('getimagesize') ? @getimagesize($tmp) : false;
    $allowed_mimes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    if ($filesize === false || $filesize <= 0 || $filesize > 10 * 1024 * 1024 || !is_array($image_info) || !in_array($image_info['mime'] ?? '', $allowed_mimes, true)) {
        @unlink($tmp);
        return;
    }
    $filename = sanitize_file_name(rawurldecode(basename((string) wp_parse_url($url, PHP_URL_PATH))));
    $file = ['name' => $filename ?: 'koto-mcp-image.png', 'tmp_name' => $tmp];
    $parent_post_id = ($url === KOTO_MCP_DEFAULT_IMAGE_URL) ? 0 : $post_id;
    $attachment = media_handle_sideload($file, $parent_post_id);
    if (is_wp_error($attachment)) {
        @unlink($tmp);
        return;
    }
    set_post_thumbnail($post_id, $attachment);
    $image_field = koto_get_top_level_character_acf_field('character_image');
    if ($image_field) update_field($image_field['key'], $attachment, $post_id);
}
