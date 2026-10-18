<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/acf-auto-input-parsers.php';

/**
 * トップレベルのACFフィールド名から、サブフィールドと衝突しないようトップレベルのフィールド定義配列を取得する。
 *
 * @param string $field_name ACFフィールド名またはフィールドキー。
 * @return array<string, mixed>|null フィールド定義配列（sub_fields含む）、見つからない場合は null。
 */
function koto_get_top_level_character_acf_field($field_name)
{
    if (!function_exists('acf_get_field')) {
        return null;
    }

    // 既知のキャラクタートップレベルフィールド名 → フィールドキーの確定マップ（サブフィールドとの同名衝突を完全に防ぐ）
    static $known_top_level_keys = [
        'name_ruby'                       => 'field_69298e45010a1',
        'available_moji_loop'             => 'field_6923dc9266244',
        'waza_name'                       => 'field_693fce13cea09',
        'waza_group_loop'                 => 'field_693985f087fc9',
        'waza_maltiplier_table_group'     => 'field_6939860a88001',
        'sugowaza_condition'              => 'field_6921a084af93c',
        'sugowaza_name'                   => 'field_693fce23cea0a',
        'sugo_shift_type'                 => 'field_694948a54ec6b',
        'sugowaza_group_loop'             => 'field_6921a053af92c',
        'sugowaza_maltiplier_table_group' => 'field_69379b1a04543',
        'koto_shift_type'                 => 'field_6950ee6aeae37',
        'kotowaza_loop_v2'                => 'field_693fc233585f3',
        'kotowaza_maltiplier_table_group' => 'field_693986368804d',
        'max_ls_hp'                       => 'field_6930525abfd59',
        'max_ls_atk'                      => 'field_69305287bfd5a',
        'ls_loop'                         => 'field_692d150d17187',
        'miracle_leader_loop'             => 'field_69d502369ba3d',
        'first_trait_name'                => 'field_693fcdcd8c318',
        'first_trait_loop'                => 'field_692d15ae17188',
        'second_trait_name'               => 'field_693fcde48c319',
        'second_trait_loop'               => 'field_693981d87156a',
        'blessing_trait_loop'             => 'field_693981fe6d220',
        'blessing_ex_trait_loop'          => 'field_6a4f265165fe4',
    ];

    if (strpos($field_name, 'field_') === 0) {
        $field = acf_get_field($field_name);
        return is_array($field) ? $field : null;
    }

    if (isset($known_top_level_keys[$field_name])) {
        $field = acf_get_field($known_top_level_keys[$field_name]);
        if (is_array($field)) {
            return $field;
        }
    }

    // 未知のトップレベルフィールドが追加された場合の動的解決フォールバック
    static $dynamic_top_level_map = null;
    if ($dynamic_top_level_map === null) {
        $dynamic_top_level_map = [];
        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            $groups = acf_get_field_groups();
            if (is_array($groups)) {
                foreach ($groups as $group) {
                    $fields = acf_get_fields($group);
                    if (is_array($fields)) {
                        foreach ($fields as $f) {
                            if (!empty($f['name']) && !empty($f['key']) && !isset($dynamic_top_level_map[$f['name']])) {
                                $dynamic_top_level_map[$f['name']] = $f['key'];
                            }
                        }
                    }
                }
            }
        }
    }

    if (isset($dynamic_top_level_map[$field_name])) {
        $field = acf_get_field($dynamic_top_level_map[$field_name]);
        if (is_array($field)) {
            return $field;
        }
    }

    return null;
}

/**
 * RepeaterやGroupへ渡す行データ配列内のサブフィールド名（name）を、
 * 親フィールド定義の sub_fields に基づいて再帰的にフィールドキー（field_xxxxxx）へ変換する。
 *
 * @param array<int|string, mixed> $data 変換対象の配列。
 * @param array<string, mixed> $field_obj 親ACFフィールド定義配列。
 * @return array<int|string, mixed> キー変換後の配列。
 */
function koto_convert_acf_rows_to_field_keys($data, $field_obj)
{
    if (!is_array($data) || empty($field_obj['sub_fields']) || !is_array($field_obj['sub_fields'])) {
        return $data;
    }

    $sub_by_name = [];
    $sub_by_key  = [];
    foreach ($field_obj['sub_fields'] as $sub) {
        if (empty($sub['key'])) continue;
        $sub_by_key[$sub['key']] = $sub;
        if (!empty($sub['name'])) {
            $sub_by_name[$sub['name']] = $sub;
        }
    }

    // 単一行（Group または Repeaterの1行分）を変換するクロージャ
    $convert_single_row = function ($row) use ($sub_by_name, $sub_by_key) {
        if (!is_array($row)) return $row;
        $converted = [];
        foreach ($row as $k => $v) {
            $sub_field = null;
            $target_key = $k;
            if (isset($sub_by_key[$k])) {
                $sub_field = $sub_by_key[$k];
                $target_key = $sub_field['key'];
            } elseif (isset($sub_by_name[$k])) {
                $sub_field = $sub_by_name[$k];
                $target_key = $sub_field['key'];
            }

            if ($sub_field && !empty($sub_field['sub_fields']) && is_array($v)) {
                $v = koto_convert_acf_rows_to_field_keys($v, $sub_field);
            }
            $converted[$target_key] = $v;
        }
        return $converted;
    };

    // Repeater（数値添字の行リスト）か Group（連想配列）かを判定
    $is_list = array_keys($data) === range(0, count($data) - 1);
    if ($is_list) {
        $result = [];
        foreach ($data as $row) {
            $result[] = $convert_single_row($row);
        }
        return $result;
    }

    return $convert_single_row($data);
}

/**
 * 解析済みACFデータを既存キャラクター投稿へ追記反映する。
 *
 * @param int $post_id 更新対象の投稿ID。
 * @param array<string, array<int, array<string, mixed>>> $acf_data 入力欄キーごとの解析結果。
 * @return bool 更新処理を実行できた場合 true、入力不備時 false。
 */
function koto_update_character_post_with_acf($post_id, $acf_data)
{
    if (!$post_id || empty($acf_data)) return false;

    // 直前のリクエストや同一プロセス内の古いメタ・ACF値キャッシュをクリア
    if (function_exists('clean_post_cache')) {
        clean_post_cache($post_id);
    }
    if (function_exists('acf_flush_value_cache')) {
        acf_flush_value_cache($post_id);
    }

    $default_attr_id = null;
    $terms = get_the_terms($post_id, 'attribute');
    if ($terms && !is_wp_error($terms) && isset($terms[0])) {
        $default_attr_id = $terms[0]->term_id;
    }

    $fields_to_update = [];
    $normal_fields_to_update = [];

    foreach ($acf_data as $input_key => $values) {
        if (!is_array($values)) continue;

        // 連想配列(単一行データ)の場合は、配列でラップして複数行データと同じ構造にする
        if (!isset($values[0])) {
            $values = [$values];
        }

        foreach ($values as $item) {
            if (!is_array($item)) continue;

            // 通常フィールドの更新として処理する
            if (isset($item['is_normal_field']) && $item['is_normal_field'] === true) {
                unset($item['is_normal_field']); // フィールド値としては不要なため削除
                foreach ($item as $field_key => $field_value) {
                    $normal_fields_to_update[$field_key] = $field_value;
                }
                continue;
            }

            if (isset($item['moji_attr']) && $item['moji_attr'] === 'default') {
                $item['moji_attr'] = $default_attr_id !== null ? $default_attr_id : null;
            }

            // ギミックが文字列（ターム名や "[ID]"）で直書きされた場合、ID配列に変換する
            if (isset($item['gimmick']) && is_string($item['gimmick'])) {
                if (preg_match('/^\[(\d+)\]$/', trim($item['gimmick']), $m)) {
                    $item['gimmick'] = [(int)$m[1]];
                } else {
                    $term = get_term_by('name', trim($item['gimmick']), 'gimmick');
                    if ($term && !is_wp_error($term)) {
                        $item['gimmick'] = [$term->term_id];
                    } else {
                        // タームが見つからない場合はエラー防止のため削除
                        unset($item['gimmick']);
                    }
                }
            }

            $acf_field_name = '';

            // 1. パース結果のACFキーによる仕分け
            if (isset($item['available_moji'])) {
                $acf_field_name = 'available_moji_loop';
            }
            // 2. 入力欄の種類による仕分け
            else {
                // auto-input-追加必須: 入力キーと保存先ACFフィールドの対応を追加
                if ($input_key === 'auto_input_trait1') {
                    $acf_field_name = 'first_trait_loop';
                } elseif ($input_key === 'auto_input_trait2') {
                    $acf_field_name = 'second_trait_loop';
                } elseif ($input_key === 'auto_input_waza') {
                    if (isset($item['use_maltiplier_table'])) {
                        $acf_field_name = 'waza_maltiplier_table_group';
                    } else {
                        $acf_field_name = 'waza_group_loop';
                    }
                } elseif ($input_key === 'auto_input_sugowaza') {
                    if (isset($item['use_maltiplier_table'])) {
                        $acf_field_name = 'sugowaza_maltiplier_table_group';
                    } else {
                        $acf_field_name = 'sugowaza_group_loop';
                    }
                } elseif ($input_key === 'auto_input_kotowaza') {
                    if (isset($item['use_maltiplier_table'])) {
                        $acf_field_name = 'kotowaza_maltiplier_table_group';
                    } else {
                        $acf_field_name = 'kotowaza_loop_v2';
                    }
                } elseif ($input_key === 'auto_input_blessing') {
                    $acf_field_name = 'blessing_trait_loop';
                } elseif ($input_key === 'auto_input_sugowaza_condition') {
                    $acf_field_name = 'sugowaza_condition';
                } elseif ($input_key === 'auto_input_leader') {
                    if (isset($item['trait_type'])) {
                        $acf_field_name = 'miracle_leader_loop';
                    } else {
                        $acf_field_name = 'ls_loop';
                    }
                }
            }

            if ($acf_field_name) {
                $fields_to_update[$acf_field_name][] = $item;
            }
        }
    }

    // 分類したデータをまとめて更新する
    foreach ($fields_to_update as $acf_field_name => $new_rows) {
        $field_obj = koto_get_top_level_character_acf_field($acf_field_name);
        $target_selector = $field_obj ? $field_obj['key'] : $acf_field_name;

        // 既存データを未フォーマット（raw）で取得し、GroupやTaxonomyのフォーマット変質を防ぐ
        $existing_data = get_field($target_selector, $post_id, false) ?: [];
        if (!is_array($existing_data)) $existing_data = [];

        // 新規行のサブフィールド名を再帰的に field_xxxxxx キーへ正規化
        if ($field_obj) {
            $new_rows = koto_convert_acf_rows_to_field_keys($new_rows, $field_obj);
        }

        foreach ($new_rows as $row) {
            $existing_data[] = $row;
        }
        // フィールド名にloopが含まれない、かつ「すごわざ発動条件」ではない場合にグループフィールドとして扱う
        if (strpos($acf_field_name, 'loop') === false && $acf_field_name !== 'sugowaza_condition') {
            // グループフィールドの場合は配列から外す
            $existing_data = $existing_data[0] ?? [];
        }
        update_field($target_selector, $existing_data, $post_id);
    }

    // 通常のフィールドをまとめて更新する
    foreach ($normal_fields_to_update as $field_key => $field_value) {
        $field_obj = koto_get_top_level_character_acf_field($field_key);
        $target_selector = $field_obj ? $field_obj['key'] : $field_key;
        update_field($target_selector, $field_value, $post_id);
    }

    if (function_exists('acf_flush_value_cache')) {
        acf_flush_value_cache($post_id);
    }

    return true;
}

/**
 * 解析済みACFデータからキャラクター投稿を新規作成し、必要な補助フィールドも設定する。
 *
 * @param string $character_name 作成するキャラクター名。
 * @param array<string, array<int, array<string, mixed>>> $acf_data 反映するACFデータ。
 * @return int|WP_Error 作成した投稿ID、またはエラー。
 */
function koto_create_character_post_from_acf($character_name, $acf_data)
{
    $post_data = [
        'post_title'  => !empty($character_name) ? $character_name : '名称未設定キャラ',
        'post_type'   => 'character',
        'post_status' => 'draft',
    ];

    $post_id = wp_insert_post($post_data);

    if ($post_id && !is_wp_error($post_id)) {
        koto_update_character_post_with_acf($post_id, $acf_data);

        if (!empty($character_name)) {
            $re = '/^(?:(?![^・]*[\(（])(?!(?:[\x{30A0}-\x{30FF}]+)・)(?:[^・]+)・(.+)|(.+))$/u';
            if (preg_match($re, $character_name, $match)) {
                $dispName = !empty($match[1]) ? $match[1] : (!empty($match[2]) ? $match[2] : $character_name);
            } else {
                $dispName = $character_name;
            }

            // 「(」や「（」が含まれる場合、それ以下をすべて捨てる
            $dispName = preg_replace('/[\(（].*$/u', '', $dispName);

            // カタカナをひらがなに変換する
            $name_ruby = mb_convert_kana($dispName, 'c', 'UTF-8');
            $ruby_field = koto_get_top_level_character_acf_field('name_ruby');
            $ruby_selector = $ruby_field ? $ruby_field['key'] : 'name_ruby';
            update_field($ruby_selector, $name_ruby, $post_id);
        }
    }

    return $post_id;
}
