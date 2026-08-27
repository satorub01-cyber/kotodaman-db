<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/acf-auto-input-parsers.php';

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
                }
            }

            if ($acf_field_name) {
                $fields_to_update[$acf_field_name][] = $item;
            }
        }
    }

    // 分類したデータをまとめて更新する
    foreach ($fields_to_update as $acf_field_name => $new_rows) {
        $existing_data = get_field($acf_field_name, $post_id) ?: [];
        if (!is_array($existing_data)) $existing_data = [];

        foreach ($new_rows as $row) {
            $existing_data[] = $row;
        }
        // フィールド名にloopが含まれない、かつ「すごわざ発動条件」ではない場合にグループフィールドとして扱う
        if (strpos($acf_field_name, 'loop') === false && $acf_field_name !== 'sugowaza_condition') {
            // グループフィールドの場合は配列から外す
            $existing_data = $existing_data[0] ?? [];
        }
        update_field($acf_field_name, $existing_data, $post_id);
    }

    // 通常のフィールドをまとめて更新する
    foreach ($normal_fields_to_update as $field_key => $field_value) {
        update_field($field_key, $field_value, $post_id);
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
            update_field('name_ruby', $name_ruby, $post_id);
        }
    }

    return $post_id;
}
