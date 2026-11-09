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

    $spec_json = get_post_meta($post->ID, '_spec_json', true);
    $spec = is_string($spec_json) ? json_decode($spec_json, true) : (is_array($spec_json) ? $spec_json : null);

    $normalize_date = static function ($raw_date) {
        if (empty($raw_date) || !is_scalar($raw_date)) {
            return null;
        }
        $str = trim((string) $raw_date);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $str, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $str, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $str, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        return null;
    };

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
        } else {
            $basic_fields[$fname] = null;
        }
    }

    $multi_tax_fields = [
        'affiliation' => 'affiliation',
        'event'       => 'event',
        'quest'       => 'suitable_quest',
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
        } else {
            $basic_fields[$fname] = [];
        }
    }

    $scalar_defaults = [
        'name_ruby'                 => null,
        'status_auto_tf'            => false,
        'magnification_estimate_tf' => true,
        'hp_chouka'                 => null,
        'atk_chouka'                => null,
        'lv_99_hp'           => null,
        'lv_99_atk'          => null,
        'lv_120_hp'          => null,
        'lv_120_atk'         => null,
        '99_hp'              => null,
        '99_atk'             => null,
        '120_hp'             => null,
        '120_atk'            => null,
        'plus_hp'            => null,
        'plus_atk'           => null,
        'max_ls_hp'          => null,
        'max_ls_atk'         => null,
        'fuku_break'         => false,
        'get_place'          => null,
        'voice_actor'        => null,
        'cv'                 => null,
        'pre_evo_name'       => null,
        'another_image_name' => null,
        'talent_hp'          => null,
        'talent_atk'         => null,
        'waza_name'          => null,
        'sugowaza_name'      => null,
        'first_trait_name'   => null,
        'second_trait_name'  => null,
        'acquisition_text'   => null,
        'illust_text'        => null,
    ];

    foreach ($scalar_defaults as $fname => $default_val) {
        $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($fname) : null;
        $val = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post->ID, false) : get_post_meta($post->ID, $fname, true);
        if ($val === null || $val === '' || $val === false) {
            $basic_fields[$fname] = $default_val;
            continue;
        }
        if (($fdef['type'] ?? '') === 'true_false' || is_bool($default_val)) {
            $basic_fields[$fname] = (bool) $val;
        } elseif (($fdef['type'] ?? '') === 'number' || is_int($default_val)) {
            if (is_numeric($val)) {
                $basic_fields[$fname] = strpos((string) $val, '.') !== false ? (float) $val : (int) $val;
            } else {
                $basic_fields[$fname] = $default_val;
            }
        } elseif (is_numeric($val) && in_array($fname, ['hp_chouka', 'atk_chouka', 'lv_99_hp', 'lv_99_atk', 'lv_120_hp', 'lv_120_atk', '99_hp', '99_atk', '120_hp', '120_atk', 'plus_hp', 'plus_atk', 'max_ls_hp', 'max_ls_atk', 'talent_hp', 'talent_atk'], true)) {
            $basic_fields[$fname] = (int) $val;
        } else {
            $basic_fields[$fname] = is_string($val) ? trim($val) : $val;
        }
    }

    // 1. 声優 (voice_actor / cv)
    $va = $basic_fields['voice_actor'] ?? ($basic_fields['cv'] ?? null);
    if ($va === null || $va === '') {
        $meta_cv = get_post_meta($post->ID, 'voice_actor', true) ?: get_post_meta($post->ID, 'cv', true);
        if ($meta_cv !== '' && $meta_cv !== null) {
            $va = trim((string) $meta_cv);
        } elseif (!empty($spec['cv'])) {
            $va = trim((string) $spec['cv']);
        }
    }
    $basic_fields['voice_actor'] = ($va !== '' && $va !== null) ? $va : null;
    $basic_fields['cv'] = $basic_fields['voice_actor'];

    // 2. 進化前の名前 (pre_evo_name)
    if ($basic_fields['pre_evo_name'] === null || $basic_fields['pre_evo_name'] === '') {
        $pe_meta = get_post_meta($post->ID, 'pre_evo_name', true);
        if ($pe_meta !== '' && $pe_meta !== null) {
            $basic_fields['pre_evo_name'] = trim((string) $pe_meta);
        } elseif (!empty($spec['pre_evo_name'])) {
            $basic_fields['pre_evo_name'] = trim((string) $spec['pre_evo_name']);
        }
    }

    // 3. 絵違いの名前 (another_image_name)
    if ($basic_fields['another_image_name'] === null || $basic_fields['another_image_name'] === '') {
        $ano_meta = get_post_meta($post->ID, 'another_image_name', true);
        if ($ano_meta !== '' && $ano_meta !== null) {
            $basic_fields['another_image_name'] = trim((string) $ano_meta);
        } elseif (!empty($spec['another_image_name'])) {
            $basic_fields['another_image_name'] = trim((string) $spec['another_image_name']);
        }
    }

    // 4. 実装日 (実装月（わかれば実装日） / implement_date)
    $date_val = null;
    $fdef_date = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('実装月（わかれば実装日）') : null;
    if ($fdef_date && !empty($fdef_date['key'])) {
        $raw_d = get_field($fdef_date['key'], $post->ID, false);
        $date_val = $normalize_date($raw_d);
    }
    if ($date_val === null) {
        $meta_d = get_post_meta($post->ID, '実装月（わかれば実装日）', true);
        $date_val = $normalize_date($meta_d);
    }
    if ($date_val === null) {
        $impl_d = get_post_meta($post->ID, 'impl_date', true);
        $date_val = $normalize_date($impl_d);
    }
    if ($date_val === null && !empty($spec['release_date'])) {
        $date_val = $normalize_date($spec['release_date']);
    }
    $basic_fields['実装月（わかれば実装日）'] = $date_val;
    $basic_fields['implement_date'] = $date_val;

    // 5. わざ名 / すごわざ名
    if ($basic_fields['waza_name'] === null || $basic_fields['waza_name'] === '') {
        $wn = get_post_meta($post->ID, 'waza_name', true);
        if ($wn !== '' && $wn !== null) {
            $basic_fields['waza_name'] = trim((string) $wn);
        } elseif (!empty($spec['waza']['name'])) {
            $basic_fields['waza_name'] = trim((string) $spec['waza']['name']);
        }
    }
    if ($basic_fields['sugowaza_name'] === null || $basic_fields['sugowaza_name'] === '') {
        $sn = get_post_meta($post->ID, 'sugowaza_name', true);
        if ($sn !== '' && $sn !== null) {
            $basic_fields['sugowaza_name'] = trim((string) $sn);
        } elseif (!empty($spec['sugowaza']['name'])) {
            $basic_fields['sugowaza_name'] = trim((string) $spec['sugowaza']['name']);
        }
    }

    // 6. とくせい1 / とくせい2 名
    if ($basic_fields['first_trait_name'] === null || $basic_fields['first_trait_name'] === '') {
        $t1n = get_post_meta($post->ID, 'first_trait_name', true);
        if ($t1n !== '' && $t1n !== null) {
            $basic_fields['first_trait_name'] = trim((string) $t1n);
        } elseif (!empty($spec['traits'][0]['name'])) {
            $basic_fields['first_trait_name'] = trim((string) $spec['traits'][0]['name']);
        }
    }
    if ($basic_fields['second_trait_name'] === null || $basic_fields['second_trait_name'] === '') {
        $t2n = get_post_meta($post->ID, 'second_trait_name', true);
        if ($t2n !== '' && $t2n !== null) {
            $basic_fields['second_trait_name'] = trim((string) $t2n);
        } elseif (!empty($spec['traits'][1]['name'])) {
            $basic_fields['second_trait_name'] = trim((string) $spec['traits'][1]['name']);
        }
    }

    // 7. Lv.120 HP / ATK (自動計算値のフォールバック)
    if ($basic_fields['lv_120_hp'] === null) {
        $meta_120_hp = get_post_meta($post->ID, '120_hp', true);
        if (is_numeric($meta_120_hp) && (int) $meta_120_hp > 0) {
            $basic_fields['lv_120_hp'] = (int) $meta_120_hp;
        } elseif (!empty($spec['_val_120_hp']) && is_numeric($spec['_val_120_hp'])) {
            $basic_fields['lv_120_hp'] = (int) $spec['_val_120_hp'];
        }
    }
    $basic_fields['120_hp'] = $basic_fields['lv_120_hp'];

    if ($basic_fields['lv_120_atk'] === null) {
        $meta_120_atk = get_post_meta($post->ID, '120_atk', true);
        if (is_numeric($meta_120_atk) && (int) $meta_120_atk > 0) {
            $basic_fields['lv_120_atk'] = (int) $meta_120_atk;
        } elseif (!empty($spec['_val_120_atk']) && is_numeric($spec['_val_120_atk'])) {
            $basic_fields['lv_120_atk'] = (int) $spec['_val_120_atk'];
        }
    }
    $basic_fields['120_atk'] = $basic_fields['lv_120_atk'];

    // 8. 才能開花 HP / ATK (自動計算値のフォールバック)
    if ($basic_fields['talent_hp'] === null) {
        $meta_tal_hp = get_post_meta($post->ID, 'talent_hp', true);
        if (is_numeric($meta_tal_hp) && (int) $meta_tal_hp > 0) {
            $basic_fields['talent_hp'] = (int) $meta_tal_hp;
        } elseif (!empty($spec['talent_hp']) && is_numeric($spec['talent_hp'])) {
            $basic_fields['talent_hp'] = (int) $spec['talent_hp'];
        }
    }
    if ($basic_fields['talent_atk'] === null) {
        $meta_tal_atk = get_post_meta($post->ID, 'talent_atk', true);
        if (is_numeric($meta_tal_atk) && (int) $meta_tal_atk > 0) {
            $basic_fields['talent_atk'] = (int) $meta_tal_atk;
        } elseif (!empty($spec['talent_atk']) && is_numeric($spec['talent_atk'])) {
            $basic_fields['talent_atk'] = (int) $spec['talent_atk'];
        }
    }

    $moji_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('available_moji_loop') : null;
    $basic_fields['available_moji_loop'] = [];
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
            $basic_fields['available_moji_loop'] = $moji_rows;
        }
    }

    $sc_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('same_character') : null;
    $raw_sc = ($sc_fdef && !empty($sc_fdef['key'])) ? get_field($sc_fdef['key'], $post->ID, false) : get_post_meta($post->ID, 'same_character', true);
    $sc_ids = is_array($raw_sc) ? array_values(array_filter(array_map('intval', $raw_sc))) : (is_numeric($raw_sc) && (int) $raw_sc > 0 ? [(int) $raw_sc] : []);
    $basic_fields['same_character'] = $sc_ids;

    $audit = get_post_meta($post->ID, '_koto_mcp_audit', true);
    $screenshots = get_post_meta($post->ID, '_koto_mcp_screenshot_urls', true);

    $computed = [
        'lv_120_hp'         => $basic_fields['lv_120_hp'],
        'lv_120_atk'        => $basic_fields['lv_120_atk'],
        'talent_hp'         => $basic_fields['talent_hp'],
        'talent_atk'        => $basic_fields['talent_atk'],
        'waza_name'         => $basic_fields['waza_name'],
        'sugowaza_name'     => $basic_fields['sugowaza_name'],
        'first_trait_name'  => $basic_fields['first_trait_name'],
        'second_trait_name' => $basic_fields['second_trait_name'],
        'release_date'      => $date_val,
    ];

    return [
        'post_id'        => (int) $post->ID,
        'character_name' => (string) $post->post_title,
        'status'         => (string) $post->post_status,
        'edit_url'       => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post->ID),
        'basic_fields'   => $basic_fields,
        'computed'       => $computed,
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
        if ($type === '祝福') {
            $b_meta = $GLOBALS['koto_last_blessing_parse_meta'][$input_key] ?? null;
            if (is_array($b_meta) && !empty($b_meta['unparsed_rows'])) {
                foreach ($b_meta['unparsed_rows'] as $unparsed_row_text) {
                    $warnings[] = sprintf('%s の一部の文言を解析できませんでした: %s', $input_key, $unparsed_row_text);
                }
            }
            if (is_array($b_meta) && !empty($b_meta['warnings'])) {
                foreach ($b_meta['warnings'] as $b_warn) {
                    $warnings[] = sprintf('%s: %s', $input_key, $b_warn);
                }
            }
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

    // エイリアス正規化（例: implement_date -> 実装月（わかれば実装日）, cv -> voice_actor）
    $basic_field_aliases = [
        'implement_date' => '実装月（わかれば実装日）',
        'release_date'   => '実装月（わかれば実装日）',
        'cv'             => 'voice_actor',
    ];
    foreach ($basic_field_aliases as $alias_k => $canonical_k) {
        if (array_key_exists($alias_k, $fields)) {
            if (!array_key_exists($canonical_k, $fields) || $fields[$canonical_k] === null || $fields[$canonical_k] === '') {
                $fields[$canonical_k] = $fields[$alias_k];
            }
            unset($fields[$alias_k]);
        }
    }

    // AIによる下書きは magnification_estimate_tf を必ず true に設定
    $fields['magnification_estimate_tf'] = true;

    // get_place の表記ゆれ正規化（gatcha / ガチャ -> gacha, その他 -> other）
    if (isset($fields['get_place']) && is_string($fields['get_place'])) {
        $gp = strtolower(trim($fields['get_place']));
        if ($gp === 'gatcha' || $gp === 'gacha' || $gp === 'ガチャ') {
            $fields['get_place'] = 'gacha';
        } elseif ($gp === 'other' || $gp === 'その他') {
            $fields['get_place'] = 'other';
        }
    }

    $args['basic_fields'] = $fields;

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

        if ($definition['type'] === 'date_picker') {
            if (!is_string($value) && !is_numeric($value)) {
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
            } else {
                $raw_d = trim((string) $value);
                if ($raw_d !== '' && !preg_match('/^(\d{4})[-\/]?(\d{1,2})[-\/]?(\d{1,2})$/', $raw_d)) {
                    $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_date_format');
                }
            }
            continue;
        }

        if ($definition['type'] === 'relationship') {
            if ($value !== null && $value !== '') {
                $ids = is_array($value) ? $value : [$value];
                foreach ($ids as $rel_id) {
                    if (!is_numeric($rel_id) || (int) $rel_id <= 0) {
                        $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
                        break;
                    }
                }
            }
            continue;
        }

        if (in_array($definition['type'], ['text', 'textarea', 'url', 'email', 'select', 'radio'], true) && (!is_string($value) && !is_numeric($value))) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $name, 'invalid_type');
        }
    }
    $raw_auto_input = is_array($args['auto_input'] ?? null) ? $args['auto_input'] : [];
    $safe_auto_input = [];
    foreach ($raw_auto_input as $k => $v) {
        if (!is_string($k)) continue;
        if (is_string($v)) {
            $safe_auto_input[$k] = $v;
        } elseif (is_array($v) && ($k === 'auto_input_blessing' || $k === 'auto_input_ex_blessing')) {
            $safe_auto_input[$k] = $v;
        }
    }
    $GLOBALS['koto_last_blessing_parse_meta'] = [];
    $csv = koto_group_csv_by_type(koto_load_csv_dictionary(get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv'));
    $mcp_context = [
        'rarity'  => $args['basic_fields']['rarity'] ?? null,
        'post_id' => $args['post_id'] ?? 0,
    ];
    $parsed = koto_build_acf_data_from_inputs($safe_auto_input, $csv, $mcp_context);
    $requested = array_filter($raw_auto_input, static function ($value) {
        if ($value === null || $value === '' || $value === []) return false;
        if (is_array($value)) {
            $norm = function_exists('koto_normalize_blessing_input') ? koto_normalize_blessing_input($value) : $value;
            return !empty($norm['template']) || !empty($norm['rows']) || !empty($norm['items']);
        }
        return true;
    });
    foreach (['auto_input_blessing', 'auto_input_ex_blessing'] as $b_key) {
        if (!empty($raw_auto_input[$b_key])) {
            $norm_b = function_exists('koto_normalize_blessing_input') ? koto_normalize_blessing_input($raw_auto_input[$b_key]) : null;
            if ($norm_b) {
                $item_count = 0;
                if (!empty($norm_b['items'])) {
                    $item_count = count($norm_b['items']);
                } else {
                    $item_count = count($norm_b['rows']);
                    if (!empty($norm_b['template'])) {
                        $tpl_csv_rows = $csv['祝福テンプレート'] ?? [];
                        $matched_def = null;
                        if (strpos($norm_b['template'], '{') === 0) {
                            $matched_def = $norm_b['template'];
                        } else {
                            foreach ($tpl_csv_rows as $c_row) {
                                if (trim((string) ($c_row['文言'] ?? '')) === $norm_b['template']) {
                                    $matched_def = (string) ($c_row['ACFに入力するJSON'] ?? '');
                                    break;
                                }
                            }
                        }
                        if ($matched_def) {
                            $tokens = array_filter(array_map('trim', explode(',', trim($matched_def, "{} \t\n\r\0\x0B"))), 'strlen');
                            $item_count += count($tokens);
                        }
                    }
                }
                if ($item_count > 8) {
                    $errors['invalid_fields'][] = koto_mcp_json_error(
                        'VALIDATION_FAILED',
                        'auto_input.' . $b_key,
                        'items_limit_exceeded',
                        sprintf('%sの項目数が上限（最大8項目）を超えています（指定件数: %d件）。', $b_key === 'auto_input_ex_blessing' ? 'EX祝福' : '祝福', $item_count)
                    );
                }
            }
        }
    }
    foreach ($requested as $input_key => $input_value) {
        $b_meta = $GLOBALS['koto_last_blessing_parse_meta'][$input_key] ?? null;
        if (is_array($b_meta) && !empty($b_meta['unknown_template'])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', 'auto_input.' . $input_key, 'unknown_blessing_template', sprintf('Unknown blessing template: %s', $b_meta['unknown_template']));
            continue;
        }
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
