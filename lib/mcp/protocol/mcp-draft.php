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
        'magnification_estimate_tf'      => true,
        'koto_magnification_estimate_tf' => false,
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

    // すごわざトークン生成・とくせいモードシフト/変身の保存結果サマリ（行インデックス付き）を抽出
    $sugowaza_tokens = [];
    $sugo_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('sugowaza_group_loop') : null;
    $raw_sugo_rows = ($sugo_fdef && !empty($sugo_fdef['key'])) ? get_field($sugo_fdef['key'], $post->ID) : (function_exists('get_field') ? get_field('sugowaza_group_loop', $post->ID) : []);
    if (is_array($raw_sugo_rows)) {
        foreach (array_values($raw_sugo_rows) as $g_idx => $grp) {
            if (!is_array($grp) || empty($grp['sugo_detail_loop']) || !is_array($grp['sugo_detail_loop'])) {
                continue;
            }
            foreach (array_values($grp['sugo_detail_loop']) as $d_idx => $d_row) {
                if (!is_array($d_row) || ($d_row['waza_type'] ?? '') !== 'token') {
                    continue;
                }
                $rt_ids = [];
                $raw_rt = $d_row['related_token'] ?? [];
                foreach ((is_array($raw_rt) ? $raw_rt : ($raw_rt ? [$raw_rt] : [])) as $t_item) {
                    $tid = is_object($t_item) ? (int) ($t_item->ID ?? 0) : (is_numeric($t_item) ? (int) $t_item : 0);
                    if ($tid > 0) {
                        $rt_ids[] = $tid;
                    }
                }
                $sugowaza_tokens[] = [
                    'group_index'   => (int) $g_idx,
                    'detail_index'  => (int) $d_idx,
                    'related_token' => array_values(array_unique($rt_ids)),
                ];
            }
        }
    }

    $trait_mode_shifts = [];
    foreach (['first_trait_loop' => 'trait1', 'second_trait_loop' => 'trait2'] as $loop_name => $slot_label) {
        $tfdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($loop_name) : null;
        $trows = ($tfdef && !empty($tfdef['key'])) ? get_field($tfdef['key'], $post->ID) : (function_exists('get_field') ? get_field($loop_name, $post->ID) : []);
        if (!is_array($trows)) {
            continue;
        }
        foreach (array_values($trows) as $r_idx => $t_row) {
            if (!is_array($t_row) || ($t_row['trait_type'] ?? '') !== 'mode_shift') {
                continue;
            }
            $rf_ids = [];
            $raw_rf = $t_row['related_form'] ?? [];
            foreach ((is_array($raw_rf) ? $raw_rf : ($raw_rf ? [$raw_rf] : [])) as $f_item) {
                $fid = is_object($f_item) ? (int) ($f_item->ID ?? 0) : (is_numeric($f_item) ? (int) $f_item : 0);
                if ($fid > 0) {
                    $rf_ids[] = $fid;
                }
            }
            $trait_mode_shifts[] = [
                'slot'          => $slot_label,
                'row_index'     => (int) $r_idx,
                'relation_ship' => (string) ($t_row['relation_ship'] ?? ''),
                'related_form'  => array_values(array_unique($rf_ids)),
            ];
        }
    }

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
        'sugowaza_tokens'   => $sugowaza_tokens,
        'trait_mode_shifts' => $trait_mode_shifts,
    ];

    return [
        'post_id'        => (int) $post->ID,
        'character_name' => (string) $post->post_title,
        'status'         => (string) $post->post_status,
        'edit_url'       => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post->ID),
        'basic_fields'   => $basic_fields,
        'computed'       => $computed,
        'blessing'       => koto_mcp_extract_blessing_items($post->ID, false),
        'ex_blessing'    => koto_mcp_extract_blessing_items($post->ID, true),
        'mcp_meta'       => [
            'generated'       => (bool) get_post_meta($post->ID, '_koto_mcp_generated', true),
            'needs_review'    => (bool) get_post_meta($post->ID, '_koto_mcp_needs_review', true),
            'screenshot_urls' => is_array($screenshots) ? $screenshots : [],
            'audit'           => is_array($audit) ? $audit : null,
        ],
    ];
}

/**
 * ACFタクソノミーまたはオブジェクト・配列値からターム名（日本語名）を抽出するヘルパー。
 *
 * @param mixed $raw_term タームID、WP_Termオブジェクト、配列、文字列。
 * @param string $taxonomy タクソノミー名。
 * @return string ターム名。取得できない場合は空文字列。
 */
function koto_mcp_extract_term_name($raw_term, $taxonomy = '')
{
    if (empty($raw_term)) {
        return '';
    }
    if (is_array($raw_term)) {
        $first = reset($raw_term);
        return koto_mcp_extract_term_name($first, $taxonomy);
    }
    if (is_object($raw_term)) {
        if (!empty($raw_term->name)) {
            return (string) $raw_term->name;
        }
        if (!empty($raw_term->term_id) && $taxonomy !== '') {
            $t = get_term((int) $raw_term->term_id, $taxonomy);
            if ($t && !is_wp_error($t)) {
                return (string) $t->name;
            }
        }
    }
    if (is_numeric($raw_term) && (int) $raw_term > 0 && $taxonomy !== '') {
        $t = get_term((int) $raw_term, $taxonomy);
        if ($t && !is_wp_error($t)) {
            return (string) $t->name;
        }
    }
    if (is_string($raw_term) && !is_numeric($raw_term)) {
        return trim($raw_term);
    }
    return '';
}

/**
 * 既存キャラクター投稿のACFフィールドから祝福またはEX祝福のアイテム配列を抽出する。
 *
 * @param int $post_id 投稿ID。
 * @param bool $is_ex EX祝福かどうか（false: 通常祝福, true: EX祝福）。
 * @return array<int, array<string, mixed>> 抽出された祝福アイテム配列。
 */
function koto_mcp_extract_blessing_items($post_id, $is_ex = false)
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || !function_exists('get_field')) {
        return [];
    }

    $field_name = $is_ex ? 'blessing_ex_trait_loop' : 'blessing_trait_loop';
    $fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field($field_name) : null;
    $rows = ($fdef && !empty($fdef['key'])) ? get_field($fdef['key'], $post_id) : get_field($field_name, $post_id);
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $items = [];
    foreach (array_values($rows) as $row_index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $pt_pattern = $row['pt_pattern'] ?? 'default';
        $raw_pt = $row['need_point'] ?? '';
        $point = ($raw_pt !== '' && $raw_pt !== null) ? (is_numeric($raw_pt) ? (int) $raw_pt : $raw_pt) : 20;

        $tt = $row['trait_type'] ?? '';
        $su = $row['status_up'] ?? '';
        $dc = $row['damage_correction'] ?? '';
        $de = $row['draw_eff'] ?? '';
        $other_t = $row['other_traits'] ?? '';
        $super_h = $row['super_gimmick_healing'] ?? '';
        $tg = is_array($row['target_field_group'] ?? null) ? $row['target_field_group'] : [];
        $target_type = $tg['target_type'] ?? '';

        $token_key = '';
        $label = '';
        $target_name = '';

        if ($tt === 'mode_shift') {
            $rel = (string) ($row['relation_ship'] ?? 'mode_shift');
            $raw_rf = $row['related_form'] ?? [];
            $rf_ids = [];
            foreach ((is_array($raw_rf) ? $raw_rf : ($raw_rf ? [$raw_rf] : [])) as $rf_item) {
                $fid = is_object($rf_item) ? (int) ($rf_item->ID ?? 0) : (is_numeric($rf_item) ? (int) $rf_item : 0);
                if ($fid > 0) {
                    $rf_ids[] = $fid;
                }
            }
            $ms_text = '';
            if (function_exists('get_koto_trait_text_from_row')) {
                $temp_row = $row;
                $temp_row['_is_blessing'] = true;
                $ms_text = trim(wp_strip_all_tags((string) get_koto_trait_text_from_row($temp_row)));
            }
            $items[] = [
                'row_index'     => (int) $row_index,
                'type'          => 'mode_shift',
                'relation_ship' => $rel,
                'related_form'  => array_values(array_unique($rf_ids)),
                'point'         => $point,
                'text'          => $ms_text !== '' ? $ms_text : 'モードシフト・変身',
            ];
            continue;
        }

        if ($tt === 'status_up') {
            if ($su === 'hp') {
                $token_key = 'hp_up';
                $label = 'HPアップ';
            } elseif ($su === 'atk') {
                if (!empty($row['per_unit_tf'])) {
                    $token_key = 'atk_up_per_unit';
                    $label = 'ATKアップ(ユニット数依存)';
                } else {
                    $token_key = 'atk_up';
                    $label = 'ATKアップ';
                }
            } elseif ($su === 'mitigation') {
                $token_key = 'attr_mitigation';
                $label = '属性軽減';
                $target_name = koto_mcp_extract_term_name($tg['target_attr'] ?? null, 'attribute');
            } elseif ($su === 'dodge') {
                $token_key = 'dodge';
                $label = '回避率UP';
            } elseif ($su === 'healing_effect') {
                $token_key = 'healing_up';
                $label = '回復効果アップ';
            }
        } elseif ($tt === 'damage_correction') {
            if ($dc === 'killer') {
                if ($target_type === 'attr') {
                    $val = (string) ($row['blessing_value'] ?? '');
                    if ($val === '30') {
                        $token_key = 'attr_30_killer';
                        $label = '属性キラー30%';
                    } else {
                        $token_key = 'attr_killer';
                        $label = '属性キラー';
                    }
                    $target_name = koto_mcp_extract_term_name($tg['target_attr'] ?? null, 'attribute');
                } elseif ($target_type === 'species') {
                    $token_key = 'species_killer';
                    $label = '種族キラー';
                    $target_name = koto_mcp_extract_term_name($tg['target_species'] ?? null, 'species');
                }
            }
        } elseif ($tt === 'draw_eff' && $de === 'atk_buff') {
            $token_key = 'group_draw_buff';
            $label = 'グループドローバフ';
        } elseif ($tt === 'draw_eff' && $de === 'healing') {
            $token_key = 'first_aid';
            $label = '応急手当';
        } elseif (in_array($other_t, ['combo_plus', 'comb_plus'], true) || in_array($super_h, ['combo_plus', 'comb_plus'], true)) {
            $token_key = 'comb_plus';
            $label = 'コンボ＋';
        } elseif ($tt === 'gimmick') {
            $g_val = $row['gimmick'] ?? null;
            $g_slug = '';
            $g_name = '';
            if (is_object($g_val)) {
                $g_slug = $g_val->slug ?? '';
                $g_name = $g_val->name ?? '';
            } elseif (is_array($g_val) && !empty($g_val)) {
                $first = reset($g_val);
                if (is_object($first)) {
                    $g_slug = $first->slug ?? '';
                    $g_name = $first->name ?? '';
                } elseif (is_numeric($first)) {
                    $t = get_term((int) $first, 'gimmick');
                    if ($t && !is_wp_error($t)) {
                        $g_slug = $t->slug;
                        $g_name = $t->name;
                    }
                }
            } elseif (is_numeric($g_val)) {
                $t = get_term((int) $g_val, 'gimmick');
                if ($t && !is_wp_error($t)) {
                    $g_slug = $t->slug;
                    $g_name = $t->name;
                }
            }

            $is_super = !empty($row['super_gimmick_moji_index'])
                || !empty($row['super_gimmick_healing'])
                || (($row['gimmick_super_mode'] ?? '') === 'super')
                || (strpos((string) ($row['other_text'] ?? ''), 'スーパー化') !== false);

            if ($g_slug !== '') {
                $token_key = $is_super ? "gimmick_super_{$g_slug}" : "gimmick_{$g_slug}";
                $label = $is_super ? ($g_name ? "{$g_name}・スーパー化" : 'スーパーギミック耐性') : ($g_name ?: 'ギミック耐性');
            }
        }

        $text = '';
        if (!empty($row['other_text'])) {
            $text = trim((string) $row['other_text']);
        }
        if ($text === '' && function_exists('get_koto_trait_text_from_row')) {
            $temp_row = $row;
            $temp_row['_is_blessing'] = true;
            if (!empty($row['blessing_value'])) {
                $temp_row['trait_rate'] = $row['blessing_value'];
            } elseif (!empty($row['max_value'])) {
                $temp_row['trait_rate'] = $row['max_value'];
            } elseif (!empty($row['min_value'])) {
                $temp_row['trait_rate'] = $row['min_value'];
            }
            $gen_text = get_koto_trait_text_from_row($temp_row);
            if ($gen_text !== '') {
                $text = $gen_text;
            }
        }
        if ($text === '' && $label !== '') {
            $text = $target_name !== '' ? "{$target_name}{$label}" : $label;
        }

        if ($token_key !== '') {
            $item = [
                'type'  => 'token',
                'token' => $token_key,
            ];
            if ($target_name !== '') {
                $item['target'] = $target_name;
            }
            if ($point !== '' && $point !== null) {
                $item['point'] = $point;
            }
            if ($label !== '') {
                $item['label'] = $label;
            }
            if ($text !== '') {
                $item['text'] = $text;
            }
        } else {
            $item = [
                'type'  => 'free',
                'point' => $point,
                'text'  => $text !== '' ? $text : ($row['trait_type'] ?? '祝福とくせい'),
            ];
        }

        $items[] = $item;
    }

    return $items;
}

/**
 * 前処理済みのわざ系文言から未解析の効果文言を抽出し、警告メッセージ配列を返す。
 *
 * @param string $prep_text 前処理済み効果テキスト。
 * @param array<string, array<int, array<string, mixed>>> $csv 種別グループ済みCSV辞書。
 * @param string $input_key 元の入力キー（auto_input_waza 等）。
 * @param string $label_prefix 警告メッセージ先頭に付与するラベル（省略時は $input_key）。
 * @return array<int, string>
 */
function koto_mcp_collect_waza_effect_warnings($prep_text, $csv, $input_key, $label_prefix = '')
{
    $warnings = [];
    $label = $label_prefix !== '' ? $label_prefix : $input_key;
    $waza_rows = $csv['わざ'] ?? [];
    $waza_condition_rows = $csv['わざ条件'] ?? [];
    $waza_shift_rows = $csv['わざシフトタイプ'] ?? [];

    $shift_type = koto_match_csv_template($prep_text, $waza_shift_rows, $input_key, 'prefix');
    if (koto_is_csv_template_match($shift_type)) {
        $prep_text = trim(mb_substr($prep_text, mb_strlen($shift_type['matched_text'])));
    }
    $parent_parts = koto_split_by_circled_numbers($prep_text);
    foreach ($parent_parts as $part) {
        $remaining_text = trim((string) $part);
        if ($remaining_text === '') {
            continue;
        }
        $has_cond = false;
        while (mb_strlen($remaining_text, 'UTF-8') > 0) {
            $prev_len = mb_strlen($remaining_text, 'UTF-8');
            $remaining_text = preg_replace('/^(?:[・\s、,]+|(?:または|もしくは)[・\s、,]*)+/u', '', $remaining_text);
            if ($remaining_text === '') {
                break;
            }
            $match = koto_match_csv_template($remaining_text, $waza_condition_rows, $input_key, 'prefix');
            if (!koto_is_csv_template_match($match)) {
                break;
            }
            $has_cond = true;
            $remaining_text = trim(mb_substr($remaining_text, mb_strlen($match['matched_text'])));
            if (mb_strlen($remaining_text, 'UTF-8') >= $prev_len) {
                break;
            }
        }
        if ($has_cond) {
            $remaining_text = preg_replace('/^(?:の場合|の時|のとき|場合|時|とき|で)?[・\s、,]+/u', '', $remaining_text);
        }
        $child_parts = preg_split('/(?:\+|、さらに)/u', $remaining_text);
        if (!is_array($child_parts)) {
            continue;
        }
        foreach ($child_parts as $child_part) {
            $child_part = preg_replace('/^[、,\s]+/u', '', (string) $child_part);
            $child_part = preg_replace('/[、,\s]+$/u', '', $child_part);
            if ($child_part === '') {
                continue;
            }
            $match = koto_match_csv_template($child_part, $waza_rows, $input_key);
            if (!koto_is_csv_template_match($match)) {
                $warnings[] = sprintf('%s の一部の効果文言を解析できませんでした: %s', $label, $child_part);
            }
        }
    }

    return $warnings;
}

/**
 * auto_input の部分未解析（丸数字区分や＋区切りの一部脱落）を検出して警告配列を返す。
 *
 * @param array<string, string|array> $safe_auto_input 入力文言連想配列。
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
        if ($input_value === '' || $input_value === [] || $input_value === null || !isset($parsed[$input_key]) || empty($parsed[$input_key])) {
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
        if ($type === '') {
            continue;
        }
        if ($type === 'コトわざ') {
            $koto_rows = function_exists('koto_normalize_kotowaza_input') ? koto_normalize_kotowaza_input($input_value) : null;
            if (!is_array($koto_rows)) {
                continue;
            }
            foreach ($koto_rows as $k_idx => $k_row) {
                $cond_text = trim((string) ($k_row['condition'] ?? ''));
                if ($cond_text !== '') {
                    $prep_cond = koto_preprocess_text($cond_text, 'すごわざ発動条件');
                    $cond_parts = koto_split_by_circled_numbers($prep_cond);
                    if (empty($cond_parts)) {
                        $cond_parts = [$prep_cond];
                    }
                    foreach ($cond_parts as $c_part) {
                        $trimmed_c = trim((string) $c_part);
                        if ($trimmed_c === '') {
                            continue;
                        }
                        $sub_parsed = koto_parse_text_by_type($trimmed_c, 'すごわざ発動条件', $csv, $input_key);
                        if (empty($sub_parsed) || empty($sub_parsed[0]['sugo_cond_loop'])) {
                            $warnings[] = sprintf('%s[%d](%d凸).condition の一部の文言を解析できませんでした: %s', $input_key, $k_idx, $k_idx, $trimmed_c);
                        }
                    }
                }
                $eff_text = trim((string) ($k_row['effect'] ?? ''));
                if ($eff_text !== '') {
                    $prep_eff = koto_preprocess_text($eff_text, 'わざ');
                    $eff_label = sprintf('%s[%d](%d凸).effect', $input_key, $k_idx, $k_idx);
                    foreach (koto_mcp_collect_waza_effect_warnings($prep_eff, $csv, $input_key, $eff_label) as $w_msg) {
                        $warnings[] = $w_msg;
                    }
                }
            }
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

        $text_for_warning = $input_value;
        if ($type === 'わざ' && function_exists('koto_normalize_sugowaza_structured_input')) {
            $norm_sugo = koto_normalize_sugowaza_structured_input($input_value);
            if ($norm_sugo !== null) {
                $text_for_warning = $norm_sugo['text'];
            }
        } elseif ($type === 'とくせい' && function_exists('koto_normalize_trait_structured_input')) {
            $norm_trait = koto_normalize_trait_structured_input($input_value);
            if ($norm_trait !== null) {
                $text_for_warning = $norm_trait['text'];
            }
        }

        if (!is_string($text_for_warning) || trim($text_for_warning) === '') {
            continue;
        }

        $prep_text = koto_preprocess_text($text_for_warning, $type);
        if ($type === 'わざ') {
            foreach (koto_mcp_collect_waza_effect_warnings($prep_text, $csv, $input_key, $input_key) as $w_msg) {
                $warnings[] = $w_msg;
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

    if (!empty($GLOBALS['koto_last_auto_input_warnings']) && is_array($GLOBALS['koto_last_auto_input_warnings'])) {
        foreach ($GLOBALS['koto_last_auto_input_warnings'] as $w_key => $w_list) {
            foreach ((array) $w_list as $w_msg) {
                if (is_string($w_msg) && $w_msg !== '') {
                    $warnings[] = ($w_key !== '_default' && strpos($w_msg, $w_key) === false)
                        ? sprintf('%s: %s', $w_key, $w_msg)
                        : $w_msg;
                }
            }
        }
    }

    return array_values(array_unique($warnings));
}

/**
 * status_auto_tf（Lv.120手動入力フラグ）と Lv.120 ステータス入力の整合性を正規化する。
 *
 * lv_120_hp / lv_120_atk（旧エイリアス 120_hp / 120_atk 含む）が未入力のまま
 * status_auto_tf が true に設定されている場合、自動計算へフォールバックするため false に補正し警告を返す。
 *
 * @param array<string, mixed> $basic_fields 正規化対象の基本フィールド配列（参照渡し）。
 * @param int $post_id 更新時の投稿ID（既存の手動Lv.120値が保存済みかの確認用）。
 * @return array<int, string> 発生した警告メッセージ配列。
 */
function koto_mcp_normalize_lv120_manual_flag(&$basic_fields, $post_id = 0)
{
    if (!is_array($basic_fields) || !array_key_exists('status_auto_tf', $basic_fields)) {
        return [];
    }
    $raw_flag = $basic_fields['status_auto_tf'];
    $is_truthy = ($raw_flag === true || $raw_flag === 1 || $raw_flag === '1' || (is_string($raw_flag) && strtolower(trim($raw_flag)) === 'true'));
    if (!$is_truthy) {
        return [];
    }

    $has_positive_val = static function ($keys) use ($basic_fields) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $basic_fields) && is_numeric($basic_fields[$k]) && (float) $basic_fields[$k] > 0) {
                return true;
            }
        }
        return false;
    };

    $has_120_hp = $has_positive_val(['lv_120_hp', '120_hp']);
    $has_120_atk = $has_positive_val(['lv_120_atk', '120_atk']);

    if ($post_id > 0 && function_exists('get_field')) {
        if (!$has_120_hp) {
            $hp_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('lv_120_hp') : null;
            $saved_hp = ($hp_fdef && !empty($hp_fdef['key'])) ? get_field($hp_fdef['key'], $post_id, false) : get_post_meta($post_id, 'lv_120_hp', true);
            if (is_numeric($saved_hp) && (float) $saved_hp > 0) {
                $has_120_hp = true;
            }
        }
        if (!$has_120_atk) {
            $atk_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('lv_120_atk') : null;
            $saved_atk = ($atk_fdef && !empty($atk_fdef['key'])) ? get_field($atk_fdef['key'], $post_id, false) : get_post_meta($post_id, 'lv_120_atk', true);
            if (is_numeric($saved_atk) && (float) $saved_atk > 0) {
                $has_120_atk = true;
            }
        }
    }

    if ($has_120_hp && $has_120_atk) {
        return [];
    }

    $basic_fields['status_auto_tf'] = false;
    return [
        'basic_fields.status_auto_tf が true でしたが、lv_120_hp / lv_120_atk が未入力のため自動で false（Lv.99基礎値からの自動計算モード）に補正しました。Lv.120の画像またはLv.120パラメータが渡された場合のみ true を指定してください。',
    ];
}

/**
 * basic_fields.same_character の厳格バリデーション・"self" 正規化・グループ順序整合チェックを行う。
 *
 * ルール:
 * - 非空で指定する場合、自身を含めること（update時は自身の post_id、create時は "self" を自身の位置に配置）。
 * - すべての要素は実在する非ゴミ箱・非保護の character 投稿ID（または "self"）であること。
 * - 同じグループ全員で同じ順番に入力すること（他メンバーの既存値と順序・構成が食い違う場合は警告を返す。他投稿は自動書き換えしない）。
 *
 * @param mixed $value same_character 入力値。
 * @param int $post_id 対象投稿ID（create時は 0）。
 * @return array{normalized: array<int, int|string>, errors: array<int, array<string, mixed>>, warnings: array<int, string>}
 */
function koto_mcp_validate_same_character($value, $post_id = 0)
{
    $post_id = (int) $post_id;
    $errors = [];
    $warnings = [];

    if ($value === null || $value === '' || $value === []) {
        return ['normalized' => [], 'errors' => [], 'warnings' => []];
    }

    if (is_array($value)) {
        $is_list = array_keys($value) === range(0, count($value) - 1);
        if (!$is_list) {
            return [
                'normalized' => [],
                'errors'     => [koto_mcp_json_error('VALIDATION_FAILED', 'same_character', 'invalid_type', 'same_character は投稿ID（または "self"）の配列で指定してください。')],
                'warnings'   => [],
            ];
        }
        $raw_items = $value;
    } else {
        $raw_items = [$value];
    }

    $normalized = [];
    $seen_keys = [];
    $has_duplicate = false;
    $uid = get_current_user_id();

    foreach ($raw_items as $item) {
        if (is_string($item) && strtolower(trim($item)) === 'self') {
            $entry = $post_id > 0 ? $post_id : 'self';
            $key = is_int($entry) ? ('id:' . $entry) : 'self';
            if (isset($seen_keys[$key])) {
                $has_duplicate = true;
                continue;
            }
            $seen_keys[$key] = true;
            $normalized[] = $entry;
            continue;
        }

        if (is_bool($item) || (!is_int($item) && !(is_string($item) && preg_match('/^\d+$/', trim($item))))) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'same_character',
                'invalid_type',
                'same_character の各要素は正のキャラクター投稿ID（整数）または "self" で指定してください。'
            );
            continue;
        }

        $rel_id = (int) trim((string) $item);
        if ($rel_id <= 0) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'same_character',
                'invalid_type',
                'same_character の投稿IDは1以上の整数で指定してください。'
            );
            continue;
        }

        if (koto_mcp_is_protected_template_post($rel_id)) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'same_character',
                'protected_template',
                sprintf('same_character に保護対象記事（雛型等: ID %d）は指定できません。', $rel_id)
            );
            continue;
        }

        $rel_post = get_post($rel_id);
        if (!($rel_post instanceof WP_Post) || $rel_post->post_type !== 'character' || in_array($rel_post->post_status, ['trash', 'auto-draft'], true)) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'same_character',
                'unknown_character',
                sprintf('same_character に指定されたキャラクター投稿（ID: %d）が存在しません。', $rel_id)
            );
            continue;
        }
        if (koto_mcp_is_protected_template_post($rel_post)) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'same_character',
                'protected_template',
                sprintf('same_character に保護対象記事（%s / ID: %d）は指定できません。', $rel_post->post_title, $rel_id)
            );
            continue;
        }
        if ($rel_post->post_status === 'private' && $uid > 0 && !user_can($uid, 'read_post', $rel_id)) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'same_character',
                'forbidden',
                sprintf('same_character に指定された非公開投稿（ID: %d）を参照できません。', $rel_id)
            );
            continue;
        }

        $key = 'id:' . $rel_id;
        if (isset($seen_keys[$key])) {
            $has_duplicate = true;
            continue;
        }
        $seen_keys[$key] = true;
        $normalized[] = $rel_id;
    }

    if ($has_duplicate) {
        $warnings[] = 'basic_fields.same_character に重複IDが含まれていたため、先出順を維持して重複を除去しました。';
    }

    // 自身を含むルール（非空時必須）
    if (empty($errors) && !empty($normalized)) {
        if ($post_id > 0) {
            if (!in_array($post_id, $normalized, true)) {
                $errors[] = koto_mcp_json_error(
                    'VALIDATION_FAILED',
                    'same_character',
                    'self_not_included',
                    sprintf('same_character を指定する場合は自身（post_id: %d）も含め、同じグループ全員で同じ順番に並べてください。', $post_id)
                );
            }
        } else {
            if (!in_array('self', $normalized, true)) {
                $errors[] = koto_mcp_json_error(
                    'VALIDATION_FAILED',
                    'same_character',
                    'self_not_included',
                    '新規作成（create_draft）時に same_character を指定する場合は、自身の位置に "self" を含めてください（例: [100, "self"]）。あるいは全キャラ作成後に update_draft で全員の投稿IDを同じ順番で指定してください。'
                );
            }
        }
    }

    // グループ内他メンバーとの順序・構成整合性チェック（警告のみ、他投稿は自動書き換えしない）
    if (empty($errors) && !empty($normalized)) {
        $sc_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('same_character') : null;
        $unset_members = [];
        $known_ids_in_order = array_values(array_filter($normalized, 'is_int'));

        foreach ($known_ids_in_order as $other_id) {
            if ($other_id === $post_id) {
                continue;
            }
            $other_raw = ($sc_fdef && !empty($sc_fdef['key']))
                ? get_field($sc_fdef['key'], $other_id, false)
                : get_post_meta($other_id, 'same_character', true);
            $other_ids = is_array($other_raw)
                ? array_values(array_filter(array_map('intval', $other_raw)))
                : (is_numeric($other_raw) && (int) $other_raw > 0 ? [(int) $other_raw] : []);

            $other_title = get_the_title($other_id);
            if (empty($other_ids)) {
                $unset_members[] = sprintf('%d(%s)', $other_id, $other_title);
                continue;
            }

            if ($post_id > 0) {
                if ($other_ids !== $normalized) {
                    $warnings[] = sprintf(
                        'same_character 順序・構成の不一致: 関連キャラ %d(%s) の現在の same_character は [%s] ですが、今回の指定は [%s] です。同じグループ全員で同じ順番に揃えてください。',
                        $other_id,
                        $other_title,
                        implode(', ', $other_ids),
                        implode(', ', $normalized)
                    );
                }
            } else {
                // create_draft 時は新規作成後の自身IDがまだ他キャラに入っていないため、既存ID同士の相対順序を確認＋更新リマインド
                $other_filtered = array_values(array_intersect($other_ids, $known_ids_in_order));
                $expected_filtered = array_values(array_intersect($known_ids_in_order, $other_ids));
                if ($other_filtered !== $expected_filtered) {
                    $warnings[] = sprintf(
                        'same_character 順序の不一致: 関連キャラ %d(%s) の現在の same_character は [%s] であり、今回指定した相対順序 [%s] と異なります。全員で同じ順番に揃えてください。',
                        $other_id,
                        $other_title,
                        implode(', ', $other_ids),
                        implode(', ', array_map('strval', $normalized))
                    );
                } else {
                    $warnings[] = sprintf(
                        '新規キャラ作成後、関連キャラ %d(%s) の same_character（現在: [%s]）にも新しい投稿IDを含めて同じ順番で update_draft してください。',
                        $other_id,
                        $other_title,
                        implode(', ', $other_ids)
                    );
                }
            }
        }

        if (!empty($unset_members)) {
            $warnings[] = sprintf(
                'same_character 未設定の関連キャラがあります（%s）。同じグループ全員に同じ順番で same_character を設定してください。',
                implode(', ', $unset_members)
            );
        }
    }

    return [
        'normalized' => $normalized,
        'errors'     => $errors,
        'warnings'   => $warnings,
    ];
}

/**
 * 既存キャラクター下書きのすごわざトークン・とくせい/祝福/EX祝福モードシフト行を直接更新する
 * relation_patches ペイロードを検証・正規化する。
 *
 * 同時に複数キャラの下書きを作成できないため、全キャラの post_id 確定後に
 * 既存スキル・とくせい・祝福行を壊さずに related_token / related_form / relation_ship のみを
 * ピンポイント反映するためのパイプライン。
 *
 * @param mixed $raw_patches relation_patches 引数。
 * @param int $post_id 対象投稿ID（update_draft / preview_draft with post_id が必須）。
 * @return array{normalized: array<string, mixed>, errors: array<int, array<string, mixed>>, warnings: array<int, string>, has_another_image: bool}
 */
function koto_mcp_validate_relation_patches($raw_patches, $post_id = 0)
{
    $post_id = (int) $post_id;
    $errors = [];
    $warnings = [];
    $normalized = [];
    $has_another_image = false;

    if ($raw_patches === null || $raw_patches === []) {
        return [
            'normalized'        => [],
            'errors'            => [],
            'warnings'          => [],
            'has_another_image' => false,
        ];
    }

    if (!koto_mcp_is_object_like_array($raw_patches)) {
        return [
            'normalized'        => [],
            'errors'            => [koto_mcp_json_error('VALIDATION_FAILED', 'relation_patches', 'invalid_type', 'relation_patches はオブジェクトで指定してください。')],
            'warnings'          => [],
            'has_another_image' => false,
        ];
    }

    if ($post_id <= 0) {
        return [
            'normalized'        => [],
            'errors'            => [koto_mcp_json_error('VALIDATION_FAILED', 'relation_patches', 'not_supported_on_create', 'relation_patches は既存下書きへの後追い反映専用です。post_id を指定した preview_draft / update_draft で使用してください。')],
            'warnings'          => [],
            'has_another_image' => false,
        ];
    }

    $allowed_sections = ['sugowaza_tokens', 'trait_mode_shifts', 'blessing_mode_shifts', 'ex_blessing_mode_shifts'];
    foreach (array_keys($raw_patches) as $sec_key) {
        if (!in_array($sec_key, $allowed_sections, true)) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'relation_patches.' . $sec_key,
                'invalid_value',
                sprintf('未知の relation_patches キーです: %s（許可キー: %s）', (string) $sec_key, implode(', ', $allowed_sections))
            );
        }
    }

    // 1. sugowaza_tokens
    if (array_key_exists('sugowaza_tokens', $raw_patches) && $raw_patches['sugowaza_tokens'] !== null) {
        $items = $raw_patches['sugowaza_tokens'];
        $is_list = is_array($items) && (empty($items) || array_keys($items) === range(0, count($items) - 1));
        if (!$is_list) {
            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', 'relation_patches.sugowaza_tokens', 'invalid_type', 'relation_patches.sugowaza_tokens は配列で指定してください。');
        } else {
            $sugo_field = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('sugowaza_group_loop') : null;
            $sugo_selector = ($sugo_field && !empty($sugo_field['key'])) ? $sugo_field['key'] : 'field_6921a053af92c';
            $existing_sugo = function_exists('get_field') ? (get_field($sugo_selector, $post_id, false) ?: []) : [];
            $existing_sugo = is_array($existing_sugo) ? array_values($existing_sugo) : [];

            $norm_tokens = [];
            foreach ($items as $idx => $entry) {
                $field_path = 'relation_patches.sugowaza_tokens.' . $idx;
                if (!koto_mcp_is_object_like_array($entry)) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path, 'invalid_type', '各要素はオブジェクトで指定してください。');
                    continue;
                }
                $group_index = 0;
                if (array_key_exists('group_index', $entry) && $entry['group_index'] !== null && $entry['group_index'] !== '') {
                    if (!is_int($entry['group_index']) && !(is_string($entry['group_index']) && ctype_digit($entry['group_index']))) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.group_index', 'invalid_type', 'group_index は0以上の整数で指定してください。');
                        continue;
                    }
                    $group_index = (int) $entry['group_index'];
                }
                $detail_index = null;
                if (array_key_exists('detail_index', $entry) && $entry['detail_index'] !== null && $entry['detail_index'] !== '') {
                    if (!is_int($entry['detail_index']) && !(is_string($entry['detail_index']) && ctype_digit($entry['detail_index']))) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.detail_index', 'invalid_type', 'detail_index は0以上の整数で指定してください。');
                        continue;
                    }
                    $detail_index = (int) $entry['detail_index'];
                }
                if (!array_key_exists('related_token', $entry)) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.related_token', 'required_empty', 'related_token は必須です。');
                    continue;
                }
                $raw_rt = $entry['related_token'];
                $resolved = koto_resolve_character_post_references($raw_rt, [
                    'allow_self'  => false,
                    'field_label' => $field_path . '.related_token',
                    'strict_name' => true,
                ]);
                if (!empty($resolved['errors'])) {
                    foreach ($resolved['errors'] as $e) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.related_token', (string) ($e['reason'] ?? 'invalid_value'), (string) ($e['message'] ?? ''));
                    }
                    continue;
                }

                // 既存行との整合チェック（detail_index 指定時は waza_type === 'token' 必須、省略時は曖昧さチェック）
                if (isset($existing_sugo[$group_index]) && is_array($existing_sugo[$group_index])) {
                    $details = $existing_sugo[$group_index]['field_692e810bf6dbc'] ?? ($existing_sugo[$group_index]['sugo_detail_loop'] ?? []);
                    $details = is_array($details) ? array_values($details) : [];
                    if ($detail_index !== null) {
                        if (!isset($details[$detail_index]) || !is_array($details[$detail_index])) {
                            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.detail_index', 'index_out_of_range', sprintf('sugowaza_group_loop[%d].sugo_detail_loop[%d] が存在しません。', $group_index, $detail_index));
                            continue;
                        }
                        $cur_type = (string) ($details[$detail_index]['field_6921a053af92f'] ?? ($details[$detail_index]['waza_type'] ?? ''));
                        if ($cur_type !== 'token') {
                            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.detail_index', 'target_row_type_mismatch', sprintf('sugowaza_group_loop[%d].sugo_detail_loop[%d] の waza_type は "%s" のためトークン更新できません（token行のみ対象）。', $group_index, $detail_index, $cur_type));
                            continue;
                        }
                    } else {
                        $token_indices = [];
                        foreach ($details as $d_i => $d_row) {
                            if (is_array($d_row) && (($d_row['field_6921a053af92f'] ?? ($d_row['waza_type'] ?? '')) === 'token')) {
                                $token_indices[] = $d_i;
                            }
                        }
                        if (count($token_indices) > 1) {
                            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.detail_index', 'ambiguous_target_row', sprintf('sugowaza_group_loop[%d] に複数の token 行（detail_index: %s）が存在します。detail_index を明示指定してください。', $group_index, implode(', ', $token_indices)));
                            continue;
                        }
                    }
                } elseif ($detail_index !== null) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.group_index', 'index_out_of_range', sprintf('sugowaza_group_loop[%d] が存在しません。', $group_index));
                    continue;
                }

                $norm_tokens[] = [
                    'group_index'   => $group_index,
                    'detail_index'  => $detail_index,
                    'related_token' => $resolved['ids'],
                ];
            }
            if (!empty($norm_tokens)) {
                $normalized['sugowaza_tokens'] = $norm_tokens;
            }
        }
    }

    // 2. trait_mode_shifts（first_trait_loop / second_trait_loop）
    if (array_key_exists('trait_mode_shifts', $raw_patches) && $raw_patches['trait_mode_shifts'] !== null) {
        $items = $raw_patches['trait_mode_shifts'];
        $is_list = is_array($items) && (empty($items) || array_keys($items) === range(0, count($items) - 1));
        if (!$is_list) {
            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', 'relation_patches.trait_mode_shifts', 'invalid_type', 'relation_patches.trait_mode_shifts は配列で指定してください。');
        } else {
            $slot_map = [
                'trait1'            => ['field' => 'first_trait_loop',  'key' => 'field_692d15ae17188', 'tt_key' => 'field_6932ed164e618'],
                'first_trait'       => ['field' => 'first_trait_loop',  'key' => 'field_692d15ae17188', 'tt_key' => 'field_6932ed164e618'],
                'first_trait_loop'  => ['field' => 'first_trait_loop',  'key' => 'field_692d15ae17188', 'tt_key' => 'field_6932ed164e618'],
                'trait2'            => ['field' => 'second_trait_loop', 'key' => 'field_693981d87156a', 'tt_key' => 'field_693981d87158e'],
                'second_trait'      => ['field' => 'second_trait_loop', 'key' => 'field_693981d87156a', 'tt_key' => 'field_693981d87158e'],
                'second_trait_loop' => ['field' => 'second_trait_loop', 'key' => 'field_693981d87156a', 'tt_key' => 'field_693981d87158e'],
            ];
            $allowed_trait_rels = ['mode_shift', 'before_transform', 'after_transform'];
            $cached_loops = [];
            $norm_traits = [];

            foreach ($items as $idx => $entry) {
                $field_path = 'relation_patches.trait_mode_shifts.' . $idx;
                if (!koto_mcp_is_object_like_array($entry)) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path, 'invalid_type', '各要素はオブジェクトで指定してください。');
                    continue;
                }
                $raw_slot = trim((string) ($entry['slot'] ?? ''));
                if (!isset($slot_map[$raw_slot])) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.slot', 'invalid_choice', 'slot は "trait1" または "trait2" で指定してください。');
                    continue;
                }
                $slot_info = $slot_map[$raw_slot];
                $canon_slot = $slot_info['field'] === 'first_trait_loop' ? 'trait1' : 'trait2';

                $row_index = null;
                if (array_key_exists('row_index', $entry) && $entry['row_index'] !== null && $entry['row_index'] !== '') {
                    if (!is_int($entry['row_index']) && !(is_string($entry['row_index']) && ctype_digit($entry['row_index']))) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'invalid_type', 'row_index は0以上の整数で指定してください。');
                        continue;
                    }
                    $row_index = (int) $entry['row_index'];
                }

                $rel = null;
                if (array_key_exists('relation_ship', $entry) && $entry['relation_ship'] !== null && $entry['relation_ship'] !== '') {
                    $rel = trim((string) $entry['relation_ship']);
                    if (!in_array($rel, $allowed_trait_rels, true)) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.relation_ship', 'invalid_choice', sprintf('とくせい1・2の relation_ship が不正です: %s（許可値: mode_shift, before_transform, after_transform。絵違い another_image は祝福・EX祝福専用です）', $rel));
                        continue;
                    }
                }

                $has_rf = array_key_exists('related_form', $entry);
                $rf_ids = null;
                if ($has_rf) {
                    $resolved = koto_resolve_character_post_references($entry['related_form'], [
                        'allow_self'   => true,
                        'self_post_id' => $post_id,
                        'field_label'  => $field_path . '.related_form',
                        'strict_name'  => true,
                    ]);
                    if (!empty($resolved['errors'])) {
                        foreach ($resolved['errors'] as $e) {
                            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.related_form', (string) ($e['reason'] ?? 'invalid_value'), (string) ($e['message'] ?? ''));
                        }
                        continue;
                    }
                    $rf_ids = $resolved['ids'];
                }

                if ($rel === null && !$has_rf) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path, 'required_empty', 'related_form または relation_ship のいずれかを指定してください。');
                    continue;
                }

                if (!isset($cached_loops[$canon_slot])) {
                    $raw_loop = function_exists('get_field') ? (get_field($slot_info['key'], $post_id, false) ?: []) : [];
                    $cached_loops[$canon_slot] = is_array($raw_loop) ? array_values($raw_loop) : [];
                }
                $existing_rows = $cached_loops[$canon_slot];

                if ($row_index !== null) {
                    if (!isset($existing_rows[$row_index]) || !is_array($existing_rows[$row_index])) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'index_out_of_range', sprintf('%s[%d] が存在しません。', $slot_info['field'], $row_index));
                        continue;
                    }
                    $cur_tt = (string) ($existing_rows[$row_index][$slot_info['tt_key']] ?? ($existing_rows[$row_index]['trait_type'] ?? ''));
                    if ($cur_tt !== 'mode_shift') {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'target_row_type_mismatch', sprintf('%s[%d] の trait_type は "%s" のため更新できません（mode_shift行のみ対象）。', $slot_info['field'], $row_index, $cur_tt));
                        continue;
                    }
                } else {
                    $ms_indices = [];
                    foreach ($existing_rows as $r_i => $r_row) {
                        if (is_array($r_row) && (($r_row[$slot_info['tt_key']] ?? ($r_row['trait_type'] ?? '')) === 'mode_shift')) {
                            $ms_indices[] = $r_i;
                        }
                    }
                    if (count($ms_indices) > 1) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'ambiguous_target_row', sprintf('%s に複数の mode_shift 行（row_index: %s）が存在します。row_index を明示指定してください。', $slot_info['field'], implode(', ', $ms_indices)));
                        continue;
                    }
                }

                $patch_item = [
                    'slot'      => $canon_slot,
                    'row_index' => $row_index,
                ];
                if ($rel !== null) {
                    $patch_item['relation_ship'] = $rel;
                }
                if ($has_rf) {
                    $patch_item['related_form'] = $rf_ids;
                }
                $norm_traits[] = $patch_item;
            }
            if (!empty($norm_traits)) {
                $normalized['trait_mode_shifts'] = $norm_traits;
            }
        }
    }

    // 3. blessing_mode_shifts & ex_blessing_mode_shifts
    $blessing_section_defs = [
        'blessing_mode_shifts' => [
            'field'  => 'blessing_trait_loop',
            'key'    => 'field_693981fe6d220',
            'tt_key' => 'field_693981ff6d244',
            'label'  => '祝福',
        ],
        'ex_blessing_mode_shifts' => [
            'field'  => 'blessing_ex_trait_loop',
            'key'    => 'field_6a4f265165fe4',
            'tt_key' => 'field_6a4f2651a34a7',
            'label'  => 'EX祝福',
        ],
    ];
    $allowed_blessing_rels = ['mode_shift', 'another_image', 'before_transform', 'after_transform'];

    foreach ($blessing_section_defs as $sec_key => $sdef) {
        if (!array_key_exists($sec_key, $raw_patches) || $raw_patches[$sec_key] === null) {
            continue;
        }
        $items = $raw_patches[$sec_key];
        $is_list = is_array($items) && (empty($items) || array_keys($items) === range(0, count($items) - 1));
        if (!$is_list) {
            $errors[] = koto_mcp_json_error('VALIDATION_FAILED', 'relation_patches.' . $sec_key, 'invalid_type', sprintf('relation_patches.%s は配列で指定してください。', $sec_key));
            continue;
        }

        $raw_existing = function_exists('get_field') ? (get_field($sdef['key'], $post_id, false) ?: []) : [];
        $existing_rows = is_array($raw_existing) ? array_values($raw_existing) : [];
        $append_count = 0;
        $norm_list = [];

        foreach ($items as $idx => $entry) {
            $field_path = 'relation_patches.' . $sec_key . '.' . $idx;
            if (!koto_mcp_is_object_like_array($entry)) {
                $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path, 'invalid_type', '各要素はオブジェクトで指定してください。');
                continue;
            }
            $row_index = null;
            if (array_key_exists('row_index', $entry) && $entry['row_index'] !== null && $entry['row_index'] !== '') {
                if (!is_int($entry['row_index']) && !(is_string($entry['row_index']) && ctype_digit($entry['row_index']))) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'invalid_type', 'row_index は0以上の整数で指定してください。');
                    continue;
                }
                $row_index = (int) $entry['row_index'];
            }

            $rel = null;
            if (array_key_exists('relation_ship', $entry) && $entry['relation_ship'] !== null && $entry['relation_ship'] !== '') {
                $rel = trim((string) $entry['relation_ship']);
                if (!in_array($rel, $allowed_blessing_rels, true)) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.relation_ship', 'invalid_choice', sprintf('%sの relation_ship が不正です: %s（許可値: mode_shift, another_image, before_transform, after_transform）', $sdef['label'], $rel));
                    continue;
                }
                if ($rel === 'another_image') {
                    $has_another_image = true;
                }
            }

            $has_rf = array_key_exists('related_form', $entry);
            $rf_ids = null;
            if ($has_rf) {
                $resolved = koto_resolve_character_post_references($entry['related_form'], [
                    'allow_self'   => true,
                    'self_post_id' => $post_id,
                    'field_label'  => $field_path . '.related_form',
                    'strict_name'  => true,
                ]);
                if (!empty($resolved['errors'])) {
                    foreach ($resolved['errors'] as $e) {
                        $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.related_form', (string) ($e['reason'] ?? 'invalid_value'), (string) ($e['message'] ?? ''));
                    }
                    continue;
                }
                $rf_ids = $resolved['ids'];
            }

            $point = null;
            if (array_key_exists('point', $entry) && $entry['point'] !== null && $entry['point'] !== '') {
                if (!is_numeric($entry['point']) || (int) $entry['point'] < 0) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.point', 'invalid_number', 'point は0以上の数値で指定してください。');
                    continue;
                }
                $point = (string) (int) $entry['point'];
            }

            if ($rel === null && !$has_rf && $point === null) {
                $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path, 'required_empty', 'related_form / relation_ship / point のいずれかを指定してください。');
                continue;
            }

            if ($row_index !== null) {
                if (!isset($existing_rows[$row_index]) || !is_array($existing_rows[$row_index])) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'index_out_of_range', sprintf('%s[%d] が存在しません。', $sdef['field'], $row_index));
                    continue;
                }
                $cur_tt = (string) ($existing_rows[$row_index][$sdef['tt_key']] ?? ($existing_rows[$row_index]['trait_type'] ?? ''));
                if ($cur_tt !== 'mode_shift') {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'target_row_type_mismatch', sprintf('%s[%d] の trait_type は "%s" のため更新できません（mode_shift行のみ対象）。', $sdef['field'], $row_index, $cur_tt));
                    continue;
                }
            } else {
                $ms_indices = [];
                foreach ($existing_rows as $r_i => $r_row) {
                    if (is_array($r_row) && (($r_row[$sdef['tt_key']] ?? ($r_row['trait_type'] ?? '')) === 'mode_shift')) {
                        $ms_indices[] = $r_i;
                    }
                }
                if (count($ms_indices) > 1) {
                    $errors[] = koto_mcp_json_error('VALIDATION_FAILED', $field_path . '.row_index', 'ambiguous_target_row', sprintf('%s に複数の mode_shift 行（row_index: %s）が存在します。row_index を明示指定してください。', $sdef['field'], implode(', ', $ms_indices)));
                    continue;
                }
                if (count($ms_indices) === 0) {
                    $append_count++;
                }
            }

            $patch_item = ['row_index' => $row_index];
            if ($rel !== null) $patch_item['relation_ship'] = $rel;
            if ($has_rf) $patch_item['related_form'] = $rf_ids;
            if ($point !== null) $patch_item['point'] = $point;
            $norm_list[] = $patch_item;
        }

        if ($append_count > 0 && (count($existing_rows) + $append_count) > 8) {
            $errors[] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'relation_patches.' . $sec_key,
                'items_limit_exceeded',
                sprintf('%sの項目数が上限（最大8項目）を超えます（既存: %d件 + 追加: %d件）。', $sdef['label'], count($existing_rows), $append_count)
            );
        }

        if (!empty($norm_list)) {
            $normalized[$sec_key] = $norm_list;
        }
    }

    return [
        'normalized'        => $normalized,
        'errors'            => $errors,
        'warnings'          => $warnings,
        'has_another_image' => $has_another_image,
    ];
}

/**
 * 検証済みの relation_patches を既存投稿のACFリピーター行へ直接ピンポイント適用する。
 *
 * @param int $post_id 更新対象の投稿ID。
 * @param array<string, mixed> $patches 正規化済み relation_patches。
 * @return bool 1件以上更新した場合 true。
 */
function koto_mcp_apply_relation_patches($post_id, $patches)
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || !is_array($patches) || empty($patches) || !function_exists('get_field') || !function_exists('update_field')) {
        return false;
    }

    if (function_exists('clean_post_cache')) {
        clean_post_cache($post_id);
    }
    if (function_exists('acf_flush_value_cache')) {
        acf_flush_value_cache($post_id);
    }

    $updated = false;

    // 1. sugowaza_tokens -> sugowaza_group_loop[].sugo_detail_loop[]
    if (!empty($patches['sugowaza_tokens']) && is_array($patches['sugowaza_tokens'])) {
        $sugo_key = 'field_6921a053af92c';
        $detail_key = 'field_692e810bf6dbc';
        $wtype_key = 'field_6921a053af92f';
        $rtok_key = 'field_6938dfb895858';

        $rows = get_field($sugo_key, $post_id, false);
        $rows = is_array($rows) ? array_values($rows) : [];

        foreach ($patches['sugowaza_tokens'] as $p) {
            $g_idx = (int) ($p['group_index'] ?? 0);
            $d_idx = array_key_exists('detail_index', $p) ? $p['detail_index'] : null;
            $rt_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($p['related_token'] ?? [])))));

            if (!isset($rows[$g_idx]) || !is_array($rows[$g_idx])) {
                $rows[] = [
                    'field_69744ae60f7bd' => [],
                    $detail_key           => [
                        [
                            $wtype_key => 'token',
                            $rtok_key  => $rt_ids,
                        ],
                    ],
                ];
                $updated = true;
                continue;
            }

            $details = $rows[$g_idx][$detail_key] ?? ($rows[$g_idx]['sugo_detail_loop'] ?? []);
            $details = is_array($details) ? array_values($details) : [];

            $target_d_idx = null;
            if ($d_idx !== null && isset($details[(int) $d_idx]) && is_array($details[(int) $d_idx])) {
                $target_d_idx = (int) $d_idx;
            } else {
                foreach ($details as $cur_i => $cur_row) {
                    if (is_array($cur_row) && (($cur_row[$wtype_key] ?? ($cur_row['waza_type'] ?? '')) === 'token')) {
                        $target_d_idx = $cur_i;
                        break;
                    }
                }
            }

            if ($target_d_idx !== null) {
                $details[$target_d_idx][$wtype_key] = 'token';
                $details[$target_d_idx][$rtok_key] = $rt_ids;
                unset($details[$target_d_idx]['related_token']);
            } else {
                $details[] = [
                    $wtype_key => 'token',
                    $rtok_key  => $rt_ids,
                ];
            }

            $rows[$g_idx][$detail_key] = $details;
            unset($rows[$g_idx]['sugo_detail_loop']);
            $updated = true;
        }

        if ($updated) {
            update_field($sugo_key, $rows, $post_id);
        }
    }

    // 2. trait_mode_shifts -> first_trait_loop / second_trait_loop
    if (!empty($patches['trait_mode_shifts']) && is_array($patches['trait_mode_shifts'])) {
        $slot_defs = [
            'trait1' => [
                'loop_key' => 'field_692d15ae17188',
                'tt_key'   => 'field_6932ed164e618',
                'rf_key'   => 'field_6934360a85727',
                'rel_key'  => 'field_69343a96c1bc6',
            ],
            'trait2' => [
                'loop_key' => 'field_693981d87156a',
                'tt_key'   => 'field_693981d87158e',
                'rf_key'   => 'field_693981d9715a5',
                'rel_key'  => 'field_693981d9715a6',
            ],
        ];
        $grouped = [];
        foreach ($patches['trait_mode_shifts'] as $p) {
            $slot = (string) ($p['slot'] ?? '');
            if (isset($slot_defs[$slot])) {
                $grouped[$slot][] = $p;
            }
        }
        foreach ($grouped as $slot => $slot_patches) {
            $sd = $slot_defs[$slot];
            $rows = get_field($sd['loop_key'], $post_id, false);
            $rows = is_array($rows) ? array_values($rows) : [];
            $slot_dirty = false;

            foreach ($slot_patches as $p) {
                $r_idx = array_key_exists('row_index', $p) ? $p['row_index'] : null;
                $target_idx = null;
                if ($r_idx !== null && isset($rows[(int) $r_idx]) && is_array($rows[(int) $r_idx])) {
                    $target_idx = (int) $r_idx;
                } else {
                    foreach ($rows as $cur_i => $cur_row) {
                        if (is_array($cur_row) && (($cur_row[$sd['tt_key']] ?? ($cur_row['trait_type'] ?? '')) === 'mode_shift')) {
                            $target_idx = $cur_i;
                            break;
                        }
                    }
                }

                if ($target_idx !== null) {
                    $rows[$target_idx][$sd['tt_key']] = 'mode_shift';
                    if (array_key_exists('relation_ship', $p)) {
                        $rows[$target_idx][$sd['rel_key']] = (string) $p['relation_ship'];
                        unset($rows[$target_idx]['relation_ship']);
                    }
                    if (array_key_exists('related_form', $p)) {
                        $rf_ids = array_values(array_unique(array_filter(array_map('intval', (array) $p['related_form']))));
                        $rows[$target_idx][$sd['rf_key']] = $rf_ids;
                        unset($rows[$target_idx]['related_form']);
                    }
                } else {
                    $new_row = [
                        $sd['tt_key']  => 'mode_shift',
                        $sd['rel_key'] => (string) ($p['relation_ship'] ?? 'mode_shift'),
                        $sd['rf_key']  => array_values(array_unique(array_filter(array_map('intval', (array) ($p['related_form'] ?? []))))),
                    ];
                    $rows[] = $new_row;
                }
                $slot_dirty = true;
                $updated = true;
            }

            if ($slot_dirty) {
                update_field($sd['loop_key'], $rows, $post_id);
            }
        }
    }

    // 3. blessing_mode_shifts & ex_blessing_mode_shifts
    $blessing_defs = [
        'blessing_mode_shifts' => [
            'loop_key' => 'field_693981fe6d220',
            'tt_key'   => 'field_693981ff6d244',
            'pt_key'   => 'field_693c24d51fb50',
            'np_key'   => 'field_693c26051fb51',
            'rf_key'   => 'field_693982006d25b',
            'rel_key'  => 'field_693982006d25c',
        ],
        'ex_blessing_mode_shifts' => [
            'loop_key' => 'field_6a4f265165fe4',
            'tt_key'   => 'field_6a4f2651a34a7',
            'pt_key'   => 'field_6a4f2651a34dc',
            'np_key'   => 'field_6a4f2651a3511',
            'rf_key'   => 'field_6a4f2651a39e3',
            'rel_key'  => 'field_6a4f2651a3a18',
        ],
    ];
    foreach ($blessing_defs as $sec_key => $bd) {
        if (empty($patches[$sec_key]) || !is_array($patches[$sec_key])) {
            continue;
        }
        $rows = get_field($bd['loop_key'], $post_id, false);
        $rows = is_array($rows) ? array_values($rows) : [];
        $sec_dirty = false;

        foreach ($patches[$sec_key] as $p) {
            $r_idx = array_key_exists('row_index', $p) ? $p['row_index'] : null;
            $target_idx = null;
            if ($r_idx !== null && isset($rows[(int) $r_idx]) && is_array($rows[(int) $r_idx])) {
                $target_idx = (int) $r_idx;
            } else {
                foreach ($rows as $cur_i => $cur_row) {
                    if (is_array($cur_row) && (($cur_row[$bd['tt_key']] ?? ($cur_row['trait_type'] ?? '')) === 'mode_shift')) {
                        $target_idx = $cur_i;
                        break;
                    }
                }
            }

            if ($target_idx !== null) {
                $rows[$target_idx][$bd['tt_key']] = 'mode_shift';
                if (array_key_exists('relation_ship', $p)) {
                    $rows[$target_idx][$bd['rel_key']] = (string) $p['relation_ship'];
                    unset($rows[$target_idx]['relation_ship']);
                }
                if (array_key_exists('related_form', $p)) {
                    $rf_ids = array_values(array_unique(array_filter(array_map('intval', (array) $p['related_form']))));
                    $rows[$target_idx][$bd['rf_key']] = $rf_ids;
                    unset($rows[$target_idx]['related_form']);
                }
                if (array_key_exists('point', $p)) {
                    $rows[$target_idx][$bd['pt_key']] = 'single';
                    $rows[$target_idx][$bd['np_key']] = (string) $p['point'];
                    unset($rows[$target_idx]['need_point'], $rows[$target_idx]['pt_pattern']);
                }
            } else {
                $rows[] = [
                    $bd['tt_key']  => 'mode_shift',
                    $bd['pt_key']  => 'single',
                    $bd['np_key']  => (string) ($p['point'] ?? '20'),
                    $bd['rel_key'] => (string) ($p['relation_ship'] ?? 'mode_shift'),
                    $bd['rf_key']  => array_values(array_unique(array_filter(array_map('intval', (array) ($p['related_form'] ?? []))))),
                ];
            }
            $sec_dirty = true;
            $updated = true;
        }

        if ($sec_dirty) {
            update_field($bd['loop_key'], $rows, $post_id);
        }
    }

    if (function_exists('acf_flush_value_cache')) {
        acf_flush_value_cache($post_id);
    }

    return $updated;
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
                foreach (['name_ruby', 'attribute', 'species', 'rarity'] as $req_k) {
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

    $lv120_warnings = koto_mcp_normalize_lv120_manual_flag($fields, (int) ($args['post_id'] ?? 0));
    $same_char_warnings = [];

    $args['basic_fields'] = $fields;

    $schema = koto_mcp_field_schema();
    foreach (['name_ruby', 'attribute', 'species', 'rarity'] as $required) {
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
            if ($name === 'same_character') {
                $sc_res = koto_mcp_validate_same_character($value, (int) ($args['post_id'] ?? 0));
                if (!empty($sc_res['errors'])) {
                    foreach ($sc_res['errors'] as $sc_err) {
                        $errors['invalid_fields'][] = $sc_err;
                    }
                } else {
                    $args['basic_fields']['same_character'] = $sc_res['normalized'];
                }
                if (!empty($sc_res['warnings'])) {
                    $same_char_warnings = array_merge($same_char_warnings, $sc_res['warnings']);
                }
                continue;
            }
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
    $structured_keys = ['auto_input_blessing', 'auto_input_ex_blessing', 'auto_input_kotowaza', 'auto_input_sugowaza', 'auto_input_trait1', 'auto_input_trait2'];
    foreach ($raw_auto_input as $k => $v) {
        if (!is_string($k)) continue;
        if (is_string($v)) {
            $safe_auto_input[$k] = $v;
        } elseif (is_array($v) && in_array($k, $structured_keys, true)) {
            $safe_auto_input[$k] = $v;
        }
    }
    $GLOBALS['koto_last_blessing_parse_meta'] = [];
    $GLOBALS['koto_last_auto_input_warnings'] = [];
    $GLOBALS['koto_last_auto_input_errors'] = [];
    $csv = koto_group_csv_by_type(koto_load_csv_dictionary(get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv'));
    $mcp_context = [
        'rarity'  => $args['basic_fields']['rarity'] ?? null,
        'post_id' => $args['post_id'] ?? 0,
    ];
    $parsed = koto_build_acf_data_from_inputs($safe_auto_input, $csv, $mcp_context);
    $requested = array_filter($raw_auto_input, static function ($value, $key) {
        if ($value === null || $value === '' || $value === []) return false;
        if ($key === 'auto_input_kotowaza') {
            $norm_k = function_exists('koto_normalize_kotowaza_input') ? koto_normalize_kotowaza_input($value) : null;
            if (!is_array($norm_k)) {
                return true;
            }
            foreach ($norm_k as $k_row) {
                if (($k_row['condition'] ?? '') !== '' || ($k_row['effect'] ?? '') !== '') {
                    return true;
                }
            }
            return false;
        }
        if ($key === 'auto_input_sugowaza' && is_array($value)) {
            $norm_s = function_exists('koto_normalize_sugowaza_structured_input') ? koto_normalize_sugowaza_structured_input($value) : null;
            if ($norm_s === null) {
                return true;
            }
            return $norm_s['text'] !== '' || !empty($norm_s['tokens']);
        }
        if (($key === 'auto_input_trait1' || $key === 'auto_input_trait2') && is_array($value)) {
            $norm_t = function_exists('koto_normalize_trait_structured_input') ? koto_normalize_trait_structured_input($value) : null;
            if ($norm_t === null) {
                return true;
            }
            return $norm_t['text'] !== '' || !empty($norm_t['mode_shifts']);
        }
        if (is_array($value)) {
            $norm = function_exists('koto_normalize_blessing_input') ? koto_normalize_blessing_input($value) : $value;
            return !empty($norm['template']) || !empty($norm['rows']) || !empty($norm['items']);
        }
        return true;
    }, ARRAY_FILTER_USE_BOTH);
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
    $kotowaza_handled_error = false;
    if (!empty($requested['auto_input_kotowaza'])) {
        $norm_koto = function_exists('koto_normalize_kotowaza_input') ? koto_normalize_kotowaza_input($raw_auto_input['auto_input_kotowaza']) : null;
        if ($norm_koto === null) {
            $errors['invalid_fields'][] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'auto_input.auto_input_kotowaza',
                'invalid_type',
                'auto_input.auto_input_kotowaza は 0凸〜4凸の {condition, effect} 配列（最大5件）で指定してください。'
            );
            $kotowaza_handled_error = true;
        } elseif (count($norm_koto) > 5) {
            $errors['invalid_fields'][] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'auto_input.auto_input_kotowaza',
                'items_limit_exceeded',
                sprintf('コトワザの項目数が上限（0凸〜4凸の最大5件）を超えています（指定件数: %d件）。', count($norm_koto))
            );
            $kotowaza_handled_error = true;
        }
    }
    // 構造化入力（すごわざトークン・とくせいモードシフト/変身）の形式チェック
    $structured_handled_errors = [];
    if (!empty($requested['auto_input_sugowaza']) && is_array($raw_auto_input['auto_input_sugowaza'])) {
        $norm_s = function_exists('koto_normalize_sugowaza_structured_input') ? koto_normalize_sugowaza_structured_input($raw_auto_input['auto_input_sugowaza']) : null;
        if ($norm_s === null) {
            $errors['invalid_fields'][] = koto_mcp_json_error(
                'VALIDATION_FAILED',
                'auto_input.auto_input_sugowaza',
                'invalid_type',
                'auto_input.auto_input_sugowaza をオブジェクトで指定する場合は {"text": "...", "tokens": [{"related_token": [投稿ID], "group_index": 0}]} 形式で指定してください。'
            );
            $structured_handled_errors['auto_input_sugowaza'] = true;
        }
    }
    foreach (['auto_input_trait1', 'auto_input_trait2'] as $t_key) {
        if (!empty($requested[$t_key]) && is_array($raw_auto_input[$t_key])) {
            $norm_t = function_exists('koto_normalize_trait_structured_input') ? koto_normalize_trait_structured_input($raw_auto_input[$t_key]) : null;
            if ($norm_t === null) {
                $errors['invalid_fields'][] = koto_mcp_json_error(
                    'VALIDATION_FAILED',
                    'auto_input.' . $t_key,
                    'invalid_type',
                    sprintf('auto_input.%s をオブジェクトで指定する場合は {"text": "...", "mode_shift": {"relation_ship": "mode_shift|before_transform|after_transform", "related_form": [投稿ID]}} 形式で指定してください。', $t_key)
                );
                $structured_handled_errors[$t_key] = true;
            }
        }
        // とくせい1・2では another_image（絵違いモードシフト）は不可（祝福・EX祝福専用）
        if (!empty($parsed[$t_key]) && is_array($parsed[$t_key])) {
            foreach ($parsed[$t_key] as $t_row) {
                if (is_array($t_row) && ($t_row['trait_type'] ?? '') === 'mode_shift' && ($t_row['relation_ship'] ?? '') === 'another_image') {
                    $errors['invalid_fields'][] = koto_mcp_json_error(
                        'VALIDATION_FAILED',
                        'auto_input.' . $t_key,
                        'invalid_choice',
                        sprintf('auto_input.%s で relation_ship="another_image"（絵違いモードシフト）は指定できません（祝福・EX祝福専用です）。', $t_key)
                    );
                    $structured_handled_errors[$t_key] = true;
                }
            }
        }
    }
    // 自動入力解析中に発生した明示IDエラー・不正選択肢エラーを VALIDATION_FAILED へ反映
    if (!empty($GLOBALS['koto_last_auto_input_errors']) && is_array($GLOBALS['koto_last_auto_input_errors'])) {
        foreach ($GLOBALS['koto_last_auto_input_errors'] as $err_key => $err_list) {
            foreach ((array) $err_list as $err_item) {
                $reason = is_array($err_item) ? (string) ($err_item['reason'] ?? 'invalid_value') : 'invalid_value';
                $msg = is_array($err_item) ? (string) ($err_item['message'] ?? '') : (string) $err_item;
                $field_name = $err_key !== '_default' ? ('auto_input.' . $err_key) : 'auto_input';
                $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $field_name, $reason, $msg);
                $structured_handled_errors[$err_key] = true;
            }
        }
    }

    foreach ($requested as $input_key => $input_value) {
        if (($input_key === 'auto_input_kotowaza' && $kotowaza_handled_error) || !empty($structured_handled_errors[$input_key])) {
            continue;
        }
        $b_meta = $GLOBALS['koto_last_blessing_parse_meta'][$input_key] ?? null;
        if (is_array($b_meta) && !empty($b_meta['unknown_template'])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', 'auto_input.' . $input_key, 'unknown_blessing_template', sprintf('Unknown blessing template: %s', $b_meta['unknown_template']));
            continue;
        }
        if (!array_key_exists($input_key, $parsed) || empty($parsed[$input_key])) {
            $errors['invalid_fields'][] = koto_mcp_json_error('VALIDATION_FAILED', $input_key, 'unparsed_text');
        }
    }
    // relation_patches（既存行のトークン・モードシフト/変身への後追い直接反映パイプライン）検証
    $relation_patch_warnings = [];
    $has_another_image = false;
    if (array_key_exists('relation_patches', $args) && $args['relation_patches'] !== null && $args['relation_patches'] !== []) {
        $rp_res = koto_mcp_validate_relation_patches($args['relation_patches'], (int) ($args['post_id'] ?? 0));
        if (!empty($rp_res['errors'])) {
            foreach ($rp_res['errors'] as $rp_err) {
                $errors['invalid_fields'][] = $rp_err;
            }
        } else {
            $args['relation_patches'] = $rp_res['normalized'];
        }
        if (!empty($rp_res['warnings'])) {
            $relation_patch_warnings = array_merge($relation_patch_warnings, $rp_res['warnings']);
        }
        if (!empty($rp_res['has_another_image'])) {
            $has_another_image = true;
        }
    }

    // 祝福・EX祝福で another_image（絵違いモードシフト）が指定されている場合、basic_fields.another_image_name の有無を確認
    foreach (['auto_input_blessing', 'auto_input_ex_blessing'] as $b_check_key) {
        if (!empty($parsed[$b_check_key]) && is_array($parsed[$b_check_key])) {
            foreach ($parsed[$b_check_key] as $b_row) {
                if (is_array($b_row) && ($b_row['trait_type'] ?? '') === 'mode_shift' && ($b_row['relation_ship'] ?? '') === 'another_image') {
                    $has_another_image = true;
                }
            }
        }
    }
    if ($has_another_image) {
        $cur_another_name = trim((string) ($args['basic_fields']['another_image_name'] ?? ''));
        if ($cur_another_name === '' && !empty($args['post_id'])) {
            $ano_fdef = function_exists('koto_get_top_level_character_acf_field') ? koto_get_top_level_character_acf_field('another_image_name') : null;
            $saved_ano = ($ano_fdef && !empty($ano_fdef['key']))
                ? get_field($ano_fdef['key'], (int) $args['post_id'], false)
                : get_post_meta((int) $args['post_id'], 'another_image_name', true);
            $cur_another_name = trim((string) $saved_ano);
        }
        if ($cur_another_name === '') {
            $relation_patch_warnings[] = '絵違いモードシフト（another_image）が指定されていますが、basic_fields.another_image_name（絵違いキャラ名）が未設定です。ユーザーから明示指定された絵違いキャラ名を設定してください。';
        }
    }

    $warnings = array_values(array_unique(array_merge(
        $lv120_warnings,
        $same_char_warnings,
        $relation_patch_warnings,
        koto_mcp_detect_auto_input_warnings($safe_auto_input, $parsed, $csv)
    )));
    return ['errors' => $errors, 'parsed_acf' => $parsed, 'warnings' => $warnings, 'normalized_args' => $args];
}

function koto_mcp_content_hash($args, $parsed)
{
    $payload = [
        'character_name' => $args['character_name'] ?? '',
        'basic_fields'   => $args['basic_fields'] ?? [],
        'auto_input'     => $args['auto_input'] ?? [],
        'parsed_acf'     => $parsed,
    ];
    if (!empty($args['relation_patches'])) {
        $payload['relation_patches'] = $args['relation_patches'];
    }
    return hash('sha256', wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function koto_mcp_set_featured_image_from_url($post_id, $url)
{
    global $wpdb;
    $url = esc_url_raw($url);
    $parsed_url = $url ? wp_parse_url($url) : false;
    $scheme = is_array($parsed_url) ? strtolower((string) ($parsed_url['scheme'] ?? '')) : '';
    $host = is_array($parsed_url) ? strtolower((string) ($parsed_url['host'] ?? '')) : '';
    $path = is_array($parsed_url) ? (string) ($parsed_url['path'] ?? '') : '';
    $query = is_array($parsed_url) ? (string) ($parsed_url['query'] ?? '') : '';
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $allowed_exts = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    if (
        !$url
        || !in_array($scheme, ['https', 'http'], true)
        || $host !== 'www.kotodaman-db.com'
        || $query !== ''
        || !str_starts_with($path, '/wp-content/uploads/')
        || strpos($path, '..') !== false
        || !in_array($ext, $allowed_exts, true)
    ) {
        return;
    }
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
