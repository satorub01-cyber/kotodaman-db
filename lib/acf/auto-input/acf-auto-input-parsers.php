<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/acf-auto-input-helpers.php';

/**
 * とくせい文言を解析し、条件部と効果部を結合したACF行配列を返す。
 *
 * @param string $text とくせい入力文言。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 入力欄識別キー。
 * @return array<int, array<string, mixed>> とくせいループに投入する行配列。
 */
function koto_parse_trait($text, $grouped_csv, $input_key = '')
{
    $parts = koto_split_by_circled_numbers($text);

    $results = [];
    foreach ($parts as $part) {
        $remaining_text = $part;
        $conditions = [];

        $condition_rows = $grouped_csv['とくせい条件'] ?? [];
        $condition_match_options = [
            'no_match_return' => null,
            'empty_acf_return' => null,
        ];
        while (true) {
            // とくせい条件は文の前半部分なので「前方一致」でマッチさせる
            $match = koto_match_csv_template(
                $remaining_text,
                $condition_rows,
                $input_key,
                'prefix',
                $condition_match_options
            );
            if (!koto_is_csv_template_match($match)) {
                break;
            }
            if ($match['acf_data'] !== null) {
                foreach (koto_ensure_acf_data_list($match['acf_data']) as $acf_item) {
                    if (empty($acf_item)) {
                        continue;
                    }
                    if (isset($acf_item['condition_type_loop']) && is_array($acf_item['condition_type_loop'])) {
                        foreach ($acf_item['condition_type_loop'] as $cond_item) {
                            $conditions[] = $cond_item;
                        }
                    } else {
                        $conditions[] = $acf_item;
                    }
                }
            }
            // 条件がなくても文言がマッチしていれば前方テキストを削る
            $remaining_text = trim(mb_substr($remaining_text, mb_strlen($match['matched_text'])));
        }

        $trait_rows = $grouped_csv['とくせい'] ?? [];
        $effect_data = [];
        // 条件の消し残りを考慮し、とくせいの効果部分は「後方一致」でマッチさせる
        $match = koto_match_csv_template($remaining_text, $trait_rows, $input_key, 'suffix');
        $effect_rows = koto_is_csv_template_match($match)
            ? koto_ensure_acf_data_list($match['acf_data'])
            : [];

        foreach ($effect_rows as $effect_data) {
            if (!is_array($effect_data) || empty($effect_data)) {
                continue;
            }
            if (isset($effect_data['gimmick_prefix'])) {
                unset($effect_data['gimmick_prefix']);
            }
            if (!empty($conditions)) {
                if (isset($effect_data['condition_type_loop']) && is_array($effect_data['condition_type_loop'])) {
                    $effect_data['condition_type_loop'] = array_merge($effect_data['condition_type_loop'], $conditions);
                } else {
                    $effect_data['condition_type_loop'] = $conditions;
                }
            }
            if (!empty($effect_data['need_combos']) && is_array($effect_data['need_combos'])) {
                $need_combos = $effect_data['need_combos'];
                $effect_data['need_combo_first']  = $need_combos[0] ?? null;
                $effect_data['need_combo_second'] = $need_combos[1] ?? null;
                $effect_data['need_combo_third']  = $need_combos[2] ?? null;
                $effect_data['need_combo_forth'] = $need_combos[3] ?? null;

                unset($effect_data['need_combos']);
            }
            $results[] = $effect_data;
        }
    }

    return $results;
}

/**
 * わざ文言をCSVテンプレートに照合し、ACF行配列へ変換する。
 *
 * @param string $text わざ入力文言。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 入力欄識別キー。
 * @return array<int, array<string, mixed>> わざループに投入する行配列。
 */
function koto_parse_waza($text, $grouped_csv, $input_key = '')
{
    $waza_rows = $grouped_csv['わざ'] ?? [];
    $waza_condition_rows = $grouped_csv['わざ条件'] ?? [];
    $waza_shift_rows = $grouped_csv['わざシフトタイプ'] ?? [];
    $results = [];
    $malti_table = [];
    $temp_shift_row = [];

    // シフトタイプの判定
    $shift_type = koto_match_csv_template($text, $waza_shift_rows, $input_key, 'prefix');
    $shift_type_value = '';
    $shift_attr_ids = [];

    if (koto_is_csv_template_match($shift_type)) {
        $text = trim(mb_substr($text, mb_strlen($shift_type['matched_text'])));
        $shift_type_rows = koto_ensure_acf_data_list($shift_type['acf_data']);
        foreach ($shift_type_rows as $shift_type_row) {
            if (!is_array($shift_type_row)) {
                continue;
            }
            $shift_type_value = $shift_type_row['sugo_shift_type'] ?? $shift_type_row['koto_shift_type'] ?? '';
            if ($shift_type_value !== '') {
                break;
            }
        }
        if ($shift_type_value === 'attr') {
            foreach ($shift_type_rows as $shift_type_row) {
                if (!is_array($shift_type_row)) {
                    continue;
                }

                $raw_attrs = $shift_type_row['sugo_shift_attrs'] ?? $shift_type_row['koto_shift_attrs'] ?? null;
                if ($raw_attrs === null || $raw_attrs === '') {
                    continue;
                }

                if (is_array($raw_attrs)) {
                    $raw_values = $raw_attrs;
                } else {
                    $raw_values = [];
                    // 文字列の場合、全角スペースや括弧などのノイズを完全に無視して数字（ID）のみを抽出する
                    if (preg_match_all('/\d+/', (string) $raw_attrs, $matches)) {
                        $raw_values = $matches[0];
                    }
                }

                foreach ((array) $raw_values as $raw_value) {
                    // オブジェクト由来の連想配列が混入していた場合のケア
                    if (is_array($raw_value) && isset($raw_value['term_id'])) {
                        $raw_value = $raw_value['term_id'];
                    }

                    // 確実に整数型にキャストする
                    $term_id = (int) $raw_value;

                    // IDが0以下（変換失敗・空文字など）の場合は除外して安全な値のみ格納
                    if ($term_id > 0) {
                        $shift_attr_ids[] = $term_id;
                    }
                }
            }
        }
    }

    // 初期設定データの格納（シフト判定がある場合のみ追加）
    if (koto_is_csv_template_match($shift_type)) {
        $shift_rows = koto_ensure_acf_data_list($shift_type['acf_data']);
        foreach ($shift_rows as $shift_row) {
            if (!is_array($shift_row) || empty($shift_row) || isset($shift_row['is_normal_field']) === false) {
                continue;
            }

            if ($input_key === 'kotowaza') {
                $shift_row['koto_shift_type'] = $shift_row['sugo_shift_type'] ?? ($shift_row['koto_shift_type'] ?? '');
                unset($shift_row['sugo_shift_type']);
            }

            $temp_shift_row = $shift_row;
        }
    }

    $parent_parts = koto_split_by_circled_numbers($text);

    foreach ($parent_parts as $part) {
        $remaining_text = $part;
        $conditions = [];
        $all_effects = [];

        // わざ追加条件の抽出
        while (true) {
            $match = koto_match_csv_template(
                $remaining_text,
                $waza_condition_rows,
                $input_key,
                'prefix'
            );
            if (!koto_is_csv_template_match($match)) {
                break;
            }

            if ($match['acf_data'] !== null) {
                foreach (koto_ensure_acf_data_list($match['acf_data']) as $acf_item) {
                    if (empty($acf_item)) {
                        continue;
                    }
                    if (isset($acf_item['condition_type_loop']) && is_array($acf_item['condition_type_loop'])) {
                        foreach ($acf_item['condition_type_loop'] as $cond_item) {
                            $conditions[] = $cond_item;
                        }
                    } else {
                        $conditions[] = $acf_item;
                    }
                }
            }
            $remaining_text = trim(mb_substr($remaining_text, mb_strlen($match['matched_text'])));
        }

        // わざ効果の抽出（全角＋にも対応）
        // +で分割された各効果部分を処理（1つのgroup_loopで複数の効果を統合）
        $child_parts = explode('+', $remaining_text);

        foreach ($child_parts as $child_part) {
            $child_part = trim((string) $child_part);
            if ($child_part === '') {
                continue;
            }

            $child_segments = preg_split('/。さらに/u', $child_part);
            if ($child_segments === false || empty($child_segments)) {
                $child_segments = [$child_part];
            }

            $segment_conditions = $conditions;
            foreach ($child_segments as $segment_index => $child_segment) {
                $child_segment = trim((string) $child_segment);
                if ($child_segment === '') {
                    continue;
                }

                if ($segment_index > 0) {
                    while (true) {
                        $match = koto_match_csv_template(
                            $child_segment,
                            $waza_condition_rows,
                            $input_key,
                            'prefix'
                        );
                        if (!koto_is_csv_template_match($match)) {
                            break;
                        }

                        if ($match['acf_data'] !== null) {
                            foreach (koto_ensure_acf_data_list($match['acf_data']) as $acf_item) {
                                if (empty($acf_item)) {
                                    continue;
                                }
                                if (isset($acf_item['condition_type_loop']) && is_array($acf_item['condition_type_loop'])) {
                                    foreach ($acf_item['condition_type_loop'] as $cond_item) {
                                        $segment_conditions[] = $cond_item;
                                    }
                                } else {
                                    $segment_conditions[] = $acf_item;
                                }
                            }
                        }

                        $child_segment = trim(mb_substr($child_segment, mb_strlen($match['matched_text'])));
                    }
                }

                $match = koto_match_csv_template($child_segment, $waza_rows, $input_key);
                if (koto_is_csv_template_match($match)) {
                    $effect_rows = koto_ensure_acf_data_list($match['acf_data']);
                    foreach ($effect_rows as $effect_data) {
                        if (!is_array($effect_data) || empty($effect_data)) {
                            continue;
                        }
                        $all_effects[] = $effect_data;
                    }
                }
            }
        }

        // 1つの丸数字区分 = 1つのgroup_loop行にまとめる
        if (!empty($all_effects) || !empty($conditions)) {
            $parsed_part = [
                'waza_add_cond_loop' => $conditions,
                'sugo_detail_loop' => $all_effects,
            ];

            // シフト条件の階層調整
            $normalized_conditions = [];
            foreach ($parsed_part['waza_add_cond_loop'] as $cond_item) {
                if (!is_array($cond_item) || empty($cond_item)) {
                    continue;
                }

                if (array_key_exists('sugo_shift_moji', $cond_item)) {
                    $parsed_part['sugo_shift_moji'] = $cond_item['sugo_shift_moji'];
                    unset($cond_item['sugo_shift_moji']);
                }
                if (array_key_exists('sugo_shift_attacked', $cond_item)) {
                    $parsed_part['sugo_shift_attacked'] = $cond_item['sugo_shift_attacked'];
                    unset($cond_item['sugo_shift_attacked']);
                }

                if (!empty($cond_item)) {
                    $normalized_conditions[] = $cond_item;
                }
            }
            $parsed_part['waza_add_cond_loop'] = $normalized_conditions;

            // 空データ行は追加しない
            if (empty($parsed_part['waza_add_cond_loop']) && empty($parsed_part['sugo_detail_loop'])) {
                continue;
            }

            $results[] = $parsed_part;
        }
    }

    // 倍率テーブルの追加
    foreach ($results as $result) {
        if (isset($result['sugo_detail_loop'])) {
            foreach ($result['sugo_detail_loop'] as $sugo_detail) {
                $attack_type = $sugo_detail['attack_type'] ?? [];
                $moji_flag = in_array('moji', $attack_type, true);
                $converged_flag = in_array('converged', $attack_type, true);
                $moji_heal_flag = !empty($sugo_detail['is_moji_healing']);

                if ($moji_flag && $converged_flag) {
                    $malti_table = koto_get_maltiplyer_table('moji_converged');
                    break 2;
                } else if ($moji_flag && $moji_heal_flag) {
                    $malti_table = koto_get_maltiplyer_table('moji_heal');
                    break 2;
                } else if ($moji_flag) {
                    $target = $sugo_detail['waza_target'] ?? '';
                    if (strpos($target, 'single') !== false) {
                        $malti_table = koto_get_maltiplyer_table('moji_single');
                        break 2;
                    } else {
                        $malti_table = koto_get_maltiplyer_table('moji_all');
                        break 2;
                    }
                } else if ($converged_flag) {
                    $malti_table = koto_get_maltiplyer_table('converged');
                    break 2;
                }
            }
        }
    }


    if ($shift_type_value === 'random') {
        foreach ($results as $result_index => &$result) {
            if (!is_array($result)) {
                continue;
            }

            $result['random_count'] = (int) ($result_index + 1);
        }
        unset($result);
    }

    // 属性シフト時の複製処理
    if ($shift_type_value === 'attr') {
        $results = koto_duplicate_results_for_shift_attrs($results, $shift_attr_ids);
        if (empty($temp_shift_row)) {
            $temp_shift_row = [
                'is_normal_field' => true,
                'sugo_shift_type' => 'attr',
            ];
        }
    }
    if (!empty($malti_table)) {
        $results[] = $malti_table;
    }
    if (!empty($temp_shift_row)) {
        $temp_shift_row['is_normal_field'] = true;
        $results[] = $temp_shift_row;
    }

    return $results;
}
/**
 * すごわざ発動条件の文言を解析し、ACF行配列を返す。
 *
 * @param string $text 入力文言。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 入力欄識別キー。
 * @return array<int, array<string, mixed>> すごわざ条件フィールドへ投入する行配列。
 */
function koto_parse_sugowaza_condition($text, $grouped_csv, $input_key = '')
{
    $condition_rows = $grouped_csv['すごわざ発動条件'] ?? [];
    $results = [];

    // 条件文字の置換と分割
    $parts = koto_split_by_circled_numbers($text);
    $ommited_cond_types = ['m', 'c', 't', 's', 'e', 'i'];
    $full_cond_types = ['文字', 'コンボ', 'のことば', 'からはじまる', 'でおわる', 'を含む'];
    $parts = str_replace($ommited_cond_types, $full_cond_types, $parts);

    $match_options = [
        'no_match_return' => null,
        'empty_acf_return' => [[]],
    ];

    foreach ($parts as $part) {
        $remaining_text = trim((string)$part);
        $child_rows = [];

        while ($remaining_text !== '') {
            // 無限ループ防止用の文字数記録
            $previous_length = mb_strlen($remaining_text, 'UTF-8');

            $match = koto_match_csv_template(
                $remaining_text,
                $condition_rows,
                $input_key,
                'prefix',
                $match_options
            );

            // マッチしなくなったらループを抜けて次のpartへ
            if (!koto_is_csv_template_match($match)) {
                break;
            }

            if ($match['acf_data'] !== null) {
                $acf_rows = koto_ensure_acf_data_list($match['acf_data']);

                foreach ($acf_rows as $row) {
                    if (!is_array($row) || empty($row)) {
                        continue;
                    }

                    // 階層構造（condition_type_loop）をフラット化して抽出
                    if (isset($row['condition_type_loop']) && is_array($row['condition_type_loop'])) {
                        foreach ($row['condition_type_loop'] as $cond_item) {
                            if (is_array($cond_item) && !empty($cond_item)) {
                                $child_rows[] = $cond_item;
                            }
                        }
                    } else {
                        $child_rows[] = $row;
                    }
                }
            }

            $matched_text = $match['matched_text'] ?? '';
            $matched_len = mb_strlen($matched_text, 'UTF-8');

            // 無限ループ防止: マッチ文字列長が0の場合
            if ($matched_len === 0) {
                break;
            }

            // 処理済みテキストを削る
            $remaining_text = trim(mb_substr($remaining_text, $matched_len, null, 'UTF-8'));

            // 無限ループ防止: テキストが減っていない場合
            if (mb_strlen($remaining_text, 'UTF-8') >= $previous_length) {
                break;
            }
        }
        $results[]['sugo_cond_loop'] = $child_rows;
    }
    return $results;
}
function koto_get_maltiplyer_table($type = 'default')
{
    $result = [
        'use_maltiplier_table' => true,
        'multi_cond_type' => 'default',
        'maltiplier_table' => []
    ];
    switch ($type):
        case 'moji_converged':
            $result['multi_cond_type'] = 'both';
            $result['maltiplier_table'] = [
                ['moji_count' => 4, 'enemy_count' => 1, 'rate' => 8.16],
                ['moji_count' => 5, 'enemy_count' => 1, 'rate' => 9.6],
                ['moji_count' => 6, 'enemy_count' => 1, 'rate' => 10.88],
                ['moji_count' => 7, 'enemy_count' => 1, 'rate' => 12.0],
                ['moji_count' => 4, 'enemy_count' => 2, 'rate' => 6.63],
                ['moji_count' => 5, 'enemy_count' => 2, 'rate' => 7.8],
                ['moji_count' => 6, 'enemy_count' => 2, 'rate' => 8.84],
                ['moji_count' => 7, 'enemy_count' => 2, 'rate' => 9.75],
                ['moji_count' => 4, 'enemy_count' => 3, 'rate' => 5.1],
                ['moji_count' => 5, 'enemy_count' => 3, 'rate' => 6.0],
                ['moji_count' => 6, 'enemy_count' => 3, 'rate' => 6.8],
                ['moji_count' => 7, 'enemy_count' => 3, 'rate' => 7.5],
            ];
            break;
        case 'moji_heal':
            $result['multi_cond_type'] = 'moji';
            $result['maltiplier_table'] = [
                ['moji_count' => 4, 'rate' => 0.8],
                ['moji_count' => 5, 'rate' => 1.6],
                ['moji_count' => 6, 'rate' => 1.8],
                ['moji_count' => 7, 'rate' => 2.3],
            ];
            break;
        case 'moji_single':
            $result['multi_cond_type'] = 'moji';
            $result['maltiplier_table'] = [
                ['moji_count' => 4, 'rate' => 6],
                ['moji_count' => 5, 'rate' => 7.5],
                ['moji_count' => 6, 'rate' => 9.5],
                ['moji_count' => 7, 'rate' => 11.5],
            ];
            break;
        case 'moji_all':
            $result['multi_cond_type'] = 'moji';
            $result['maltiplier_table'] = [
                ['moji_count' => 4, 'rate' => 5.1],
                ['moji_count' => 5, 'rate' => 6.6],
                ['moji_count' => 6, 'rate' => 7.9],
                ['moji_count' => 7, 'rate' => 9.5],
            ];
            break;
        case 'converged':
            $result['multi_cond_type'] = 'enemy_count';
            $result['maltiplier_table'] = [
                ['enemy_count' => 1, 'rate' => 9],
                ['enemy_count' => 2, 'rate' => 7.5],
                ['enemy_count' => 3, 'rate' => 6],
            ];
            break;
        default:
            break;
    endswitch;
    return $result;
}

/**
 * コトわざの入力データ（配列）を解析し、ACF行配列を返す。
 * koto_parse_waza および koto_parse_sugowaza_condition を内部で呼び出して処理を行います。
 *
 * @param array $texts コトわざ入力データ（0〜4凸のcondition, effectを含む連想配列の配列）。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 入力欄識別キー。
 * @return array<int, array<string, mixed>> コトわざフィールドへ投入する行配列。
 */
function koto_parse_kotowaza($texts, $grouped_csv, $input_key = '')
{
    $results = [];
    $malti_table = [];
    $temp_shift_row = [];

    // JSから配列として渡ってこない場合は空配列を返す
    if (!is_array($texts)) {
        return [];
    }

    foreach ($texts as $index => $data) {
        $condition_text = $data['condition'] ?? '';
        $effect_text = $data['effect'] ?? '';

        // 条件・効果ともに空の凸数（未入力の行）はスキップ
        if (empty($condition_text) && empty($effect_text)) {
            $results[] = [
                'kotowaza_condition'  => [],
                'kotowaza_group_loop' => [],
            ];
            continue;
        }

        $parsed_condition = [];
        $parsed_effect = [];

        // 1. 条件のパース
        if (!empty($condition_text)) {
            // 文字列として前処理を実施
            $preprocessed_cond = koto_preprocess_text($condition_text, 'すごわざ発動条件');
            // 'kotowaza' をキーとして渡し、内部で処理させる
            $parsed_condition = koto_parse_sugowaza_condition($preprocessed_cond, $grouped_csv, 'kotowaza');
        }

        // 2. 効果のパース
        if (!empty($effect_text)) {
            // 文字列として前処理を実施
            $preprocessed_effect = koto_preprocess_text($effect_text, 'わざ');
            // 'kotowaza' をキーとして渡し、内部で処理させる
            $waza_result = koto_parse_waza($preprocessed_effect, $grouped_csv, 'kotowaza');

            if (!empty($waza_result)) {
                foreach ($waza_result as $wr) {
                    if (isset($wr['use_maltiplier_table'])) {
                        // 倍率テーブル
                        $malti_table = $wr;
                    } elseif (isset($wr['is_normal_field'])) {
                        // シフトタイプなどの通常フィールド
                        $temp_shift_row = $wr;
                    } else {
                        // わざ効果ループを抽出
                        $parsed_effect[] = $wr;
                    }
                }
            }
        }

        // 3. 要求された形式で配列にまとめる
        $results[] = [
            'kotowaza_condition'  => $parsed_condition,
            'kotowaza_group_loop' => $parsed_effect,
        ];
    }

    // シフトタイプおよび乗算テーブルを配列の末尾に追加
    // (koto_update_character_post_with_acf 側で自動的に適切に処理されます)
    if (!empty($malti_table)) {
        $results[] = $malti_table;
    }
    if (!empty($temp_shift_row)) {
        $results[] = $temp_shift_row;
    }

    return $results;
}

// auto-input-追加必須: 新しい koto_parse_* 関数はこのファイルへ追加し、下の振り分けへ接続
/**
 * 種別ごとに適切なパーサーへ振り分け、入力文言をACFデータへ変換する。
 *
 * @param string $text 入力文言。
 * @param string $type 種別名（とくせい/わざ/すごわざ条件/祝福/リーダーとくせい）。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 入力欄識別キー。
 * @return array<int, array<string, mixed>>|null 種別に対応した結果配列。未対応種別は null。
 */
function koto_parse_text_by_type($text, $type, $grouped_csv, $input_key = '')
{
    if (!is_array($text)) {
        $text = koto_preprocess_text($text, $type);
    }
    switch ($type) {
        case 'とくせい':
            return koto_parse_trait($text, $grouped_csv, $input_key);

        case 'わざ':
            return koto_parse_waza($text, $grouped_csv, $input_key);

        case 'すごわざ発動条件':
            return koto_parse_sugowaza_condition($text, $grouped_csv, $input_key);
        case 'コトわざ':
            return koto_parse_kotowaza($text, $grouped_csv, $input_key);
        case '祝福':
        case 'リーダーとくせい':
            return null;
        default:
            return null;
    }
}

// =================================================================
// 6. 種別ごとにパースしてすべてをACF化する関数
// =================================================================
/**
 * 複数入力欄の文言をまとめて解析し、入力欄キーごとのACFデータを構築する。
 *
 * @param array<string, string> $inputs 入力欄キーと文言の連想配列。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @return array<string, array<int, array<string, mixed>>> 入力欄キーごとのACF行配列。
 */
function koto_build_acf_data_from_inputs($inputs, $grouped_csv)
{
    $acf_data = [];

    foreach ($inputs as $key => $text) {
        if (empty($text)) continue;
        // auto-input-追加必須: パース不要の通常フィールドはここへ追加
        // =========================================================
        // わざ名・すごわざ名はパース処理を通さず、そのまま通常フィールドとして登録
        // =========================================================
        if ($key === 'auto_input_waza_name') {
            $acf_data[$key] = [
                [
                    'is_normal_field' => true,
                    'waza_name' => $text // ※実際のACFの「わざ名」フィールド名に合わせてください
                ]
            ];
            continue;
        }
        if ($key === 'auto_input_sugowaza_name') {
            $acf_data[$key] = [
                [
                    'is_normal_field' => true,
                    'sugowaza_name' => $text // ※実際のACFの「すごわざ名」フィールド名に合わせてください
                ]
            ];
            continue;
        }

        // auto-input-追加必須: 新しい入力キーを既存または新規パーサーへ振り分け
        $type = '';
        if (strpos($key, 'trait') !== false) {
            $type = 'とくせい';
        } elseif (strpos($key, 'kotowaza') !== false) { // 'waza' よりも先に判定する
            $type = 'コトわざ';
        } elseif (strpos($key, 'waza') !== false && strpos($key, 'sugowaza_condition') === false) {
            $type = 'わざ';
        } elseif (strpos($key, 'sugowaza_condition') !== false) {
            $type = 'すごわざ発動条件';
        } elseif (strpos($key, 'blessing') !== false) {
            $type = '祝福';
        } elseif (strpos($key, 'leader') !== false) {
            $type = 'リーダーとくせい';
        }

        if ($type) {
            $parsed = koto_parse_text_by_type($text, $type, $grouped_csv, $key);
            if (!empty($parsed)) {
                $acf_data[$key] = $parsed;
            }
        }
    }

    return $acf_data;
}
