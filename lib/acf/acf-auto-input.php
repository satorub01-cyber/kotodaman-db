<?php
if (!defined('ABSPATH')) exit;

// =================================================================
// 1. CSV読み込み用関数と種別グループ化
// =================================================================
function koto_load_csv_dictionary($csv_path)
{
    $csv_data = [];
    if (file_exists($csv_path)) {
        $file = fopen($csv_path, 'r');
        $headers = fgetcsv($file);
        // BOM削除
        if ($headers && isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        while (($row = fgetcsv($file)) !== FALSE) {
            if (count($headers) == count($row)) {
                $csv_data[] = array_combine($headers, $row);
            }
        }
        fclose($file);
    }
    return $csv_data;
}

function koto_group_csv_by_type($csv_data)
{
    $grouped = [];
    foreach ($csv_data as $row) {
        $type = $row['種別'] ?? '';
        if ($type) {
            $grouped[$type][] = $row;
        }
    }
    return $grouped;
}

// =================================================================
// 2. 正規表現パターンの生成関数とマッチング
// =================================================================
function koto_generate_regex_pattern($template, &$seen_vars, $match_mode = 'exact')
{
    preg_match_all('/\{\$(.*?)\}/', $template, $ph_matches);
    $pattern = preg_quote($template, '/');

    if (!empty($ph_matches[1])) {
        foreach ($ph_matches[1] as $idx => $var_name) {
            $quoted_ph = preg_quote($ph_matches[0][$idx], '/');
            $pos = strpos($pattern, $quoted_ph);
            if ($pos !== false) {
                if (!in_array($var_name, $seen_vars)) {
                    $replacement = '(?P<' . $var_name . '>.+?)';
                    $seen_vars[] = $var_name;
                } else {
                    $replacement = '(?P=' . $var_name . ')';
                }
                $pattern = substr_replace($pattern, $replacement, $pos, strlen($quoted_ph));
            }
        }
    }
    if ($match_mode === 'exact') return '/^' . $pattern . '$/u';
    if ($match_mode === 'prefix') return '/^' . $pattern . '/u';
    if ($match_mode === 'suffix') return '/' . $pattern . '$/u';
    return '/' . $pattern . '/u'; // partial
}

function koto_match_csv_template($text, $csv_rows, $input_key = '', $match_mode = 'exact')
{
    foreach ($csv_rows as $row) {
        if (empty($row['文言'])) continue;

        $seen_vars = [];
        $pattern = koto_generate_regex_pattern($row['文言'], $seen_vars, $match_mode);

        if (preg_match($pattern, $text, $matches)) {
            if ($input_key === 'auto_input_trait1') {
                $matches['trait_num'] = 'first_trait';
            } elseif ($input_key === 'auto_input_trait2') {
                $matches['trait_num'] = 'second_trait';
            } elseif ($input_key === 'auto_input_blessing') {
                $matches['trait_num'] = 'blessing';
            }

            $acf_json = koto_apply_variables_to_json($row['ACFに入力するJSON'], $matches);
            return [
                'acf_data' => $acf_json,
                'matched_text' => $matches[0]
            ];
        }
    }
    return null;
}

// =================================================================
// 3. JSONへの変数適用＆特殊処理関数 (確実に引き継ぐ)
// =================================================================
function koto_apply_variables_to_json($json_template, $matches)
{
    $json_str = $json_template;

    foreach ($matches as $key => $value) {
        if (is_string($key)) {
            // ▼ ここに事前に決めた命名規則による特殊処理を書く ▼
            if (strpos($key, 'dot_camma_val') === 0) {
                $value = str_replace('・', ',', $value);
            } elseif (strpos($key, 'gimmick_name_super_guard') === 0) {
                $term = get_term_by('name', 'スーパー' . trim($value) . 'ガード', 'gimmick');
                if ($term) $value = $term->term_id;
            } elseif (strpos($key, 'gimmick_name_super_breaker') === 0) {
                $term = get_term_by('name', 'スーパー' . trim($value) . 'ブレイカー', 'gimmick');
                if ($term) $value = $term->term_id;
            } elseif (strpos($key, 'gimmick_name_guard') === 0) {
                $prefix = (!empty($matches['gimmick_prefix']) && strpos($matches['gimmick_prefix'], '大きくUP') !== false) ? 'スーパー' : '';
                $term = get_term_by('name', $prefix . trim($value) . 'ガード', 'gimmick');
                if ($term) $value = $term->term_id;
            } elseif (strpos($key, 'gimmick_name_breaker') === 0) {
                $prefix = (!empty($matches['gimmick_prefix']) && strpos($matches['gimmick_prefix'], '大きくUP') !== false) ? 'スーパー' : '';
                $term = get_term_by('name', $prefix . trim($value) . 'ブレイカー', 'gimmick');
                if ($term) $value = $term->term_id;
            } elseif (strpos($key, 'gimmick_name') === 0) {
                $prefix = (!empty($matches['gimmick_prefix']) && strpos($matches['gimmick_prefix'], '大きくUP') !== false) ? 'スーパー' : '';
                $term = get_term_by('name', $prefix . trim($value), 'gimmick');
                if ($term) $value = $term->term_id;
            } elseif (strpos($key, 'dot_separated_moji') === 0) {
                $mojis = explode('・', $value);
                $term_ids = [];
                foreach ($mojis as $moji) {
                    $term = get_term_by('name', trim($moji), 'available_moji');
                    if ($term) {
                        $term_ids[] = $term->term_id;
                    }
                }
                $value = implode(',', $term_ids);
            } elseif (strpos($key, 'attr') === 0) {
                $value = str_replace('属性', '', $value);
                $attr_names = explode('・', $value);
                $attr_ids = [];
                foreach ($attr_names as $name) {
                    $term = get_term_by('name', trim($name), 'attribute');
                    if ($term) {
                        $attr_ids[] = $term->term_id;
                    }
                }
                $value = $attr_ids;
            } elseif (strpos($key, 'species') === 0) {
                $value = str_replace('種族', '', $value);
                $attr_names = explode('・', $value);
                $attr_ids = [];
                foreach ($attr_names as $name) {
                    $term = get_term_by('name', trim($name), 'species');
                    if ($term) {
                        $attr_ids[] = $term->term_id;
                    }
                }
                $value = $attr_ids;
            } elseif (strpos($key, 'affiliation') === 0) {
                $attr_names = explode('・', $value);
                $attr_ids = [];
                foreach ($attr_names as $name) {
                    $term = get_term_by('name', trim($name), 'affiliation');
                    if ($term) {
                        $attr_ids[] = $term->term_id;
                    }
                }
                $value = $attr_ids;
            } elseif (strpos($key, 'character_target') === 0 || strpos($key, 'whose_trait') === 0) {
                $target_type = 'self';
                $target_detail = '';
                if (strpos($value, '自身') !== false) {
                    $target_type = 'self';
                } elseif ($value === '味方') {
                    $target_type = 'all';
                } elseif (strpos($value, '属性') !== false) {
                    $target_type = 'attr';
                    $value = str_replace('属性', '', $value);
                    $values = explode('・', $value);
                    $values = array_map(function ($v) {
                        $term = get_term_by('name', trim($v), 'attribute');
                        return $term ? $term->term_id : null;
                    }, $values);
                    $target_detail = ',"target_attr" : [' . implode(',', array_filter($values)) . ']';
                } elseif (strpos($value, '種族') !== false) {
                    $target_type = 'species';
                    $value = str_replace('種族', '', $value);
                    $values = explode('・', $value);
                    $values = array_map(function ($v) {
                        $term = get_term_by('name', trim($v), 'species');
                        return $term ? $term->term_id : null;
                    }, $values);
                    $target_detail = ',"target_species" : [' . implode(',', array_filter($values)) . ']';
                } elseif (strpos($value, '「') !== false) {
                    $target_type = 'group';
                    $value = str_replace(['「', '」'], ['', ''], $value);
                    $values = explode('・', $value);
                    $values = array_map(function ($v) {
                        $term = get_term_by('name', trim($v), 'affiliation');
                        return $term ? $term->term_id : null;
                    }, $values);
                    $target_detail = ',"target_group" : [' . implode(',', array_filter($values)) . ']';
                }
                $value = '{"target_type" :"' . $target_type . '"' . $target_detail . '}';
            } elseif (strpos($key, 'dot_separated_moji') === 0) {
                $mojis = explode('・', $value);
                $term_ids = [];
                foreach ($mojis as $moji) {
                    $term = get_term_by('name', trim($moji), 'available_moji');
                    if ($term) {
                        $term_ids[] = $term->term_id;
                    }
                }
                $value = $term_ids;
            } elseif (strpos($key, 'resistance') === 0) {
                $status_map = koto_get_status_map();
                $value = array_search($value, $status_map, true);
                if (function_exists('koto_get_status_map')) {
                    $status_map = koto_get_status_map();
                    $value = array_search($value, $status_map, true);
                }
            } elseif (strpos($key, 'prefix') === 0) {
                $value = str_replace(['増加', '強化'], ['', ''], $value);
                $prefix_map = koto_get_buff_prefix_map();
                $value = $prefix_map[$value];
                if (function_exists('koto_get_buff_prefix_map')) {
                    $prefix_map = koto_get_buff_prefix_map();
                    if (isset($prefix_map[$value])) {
                        $value = $prefix_map[$value];
                    }
                }
            }
            
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $json_str = str_replace('$' . $key, $value, $json_str);
        }
    }

    // 文字列を連想配列にして返す
    return json_decode($json_str, true);
}

// =================================================================
// 4. 前処理と文言の分割
// =================================================================
function koto_preprocess_text($text)
{
    $ignore_texts = [
        'サブ属性を対象とするリーダーとくせい・とくせいの効果を受けることができる（受ける効果はメイン属性と重複しない）',
        '(福に応じて数値が変動)',
        '※濁音、半濁音、小文字も同じ文字とする',
        '(メイン属性のみを参照する)',
        '※この効果のダメージは小数点切り上げとなり、HPが必ず1残る',
        'このコトダマンを含むことばで指定コンボ達成した場合、達成段階に応じて効果が変化する。',
        '※同じとくせいを持ったコトダマンが複数いる場合、1ターンに2体まで発動する',
        'また、クエスト終了まで変身状態は維持される。',
        '※この効果は重複しません。'
    ];
    $text = trim(str_replace($ignore_texts, '', $text));

    $text = preg_replace('/\(敵の行動時、そのターンに自身が各敵にわざ・すごわざ・コトわざで与えた合計ダメージの\d+%の値で固定ダメージを与える効果\)/u', '', $text);

    return trim($text);
}

function koto_split_by_circled_numbers($text)
{
    $parts = preg_split('/[①②③④⑤⑥⑦⑧⑨⑩]/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $result = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if (!empty($trimmed)) {
            $result[] = $trimmed;
        }
    }
    if (empty($result)) {
        $result[] = trim($text);
    }
    return $result;
}

// =================================================================
// 5. 種別ごとの再帰的呼び出し関数
// =================================================================
function koto_parse_trait($text, $grouped_csv, $input_key = '')
{
    $parts = koto_split_by_circled_numbers($text);

    $results = [];
    foreach ($parts as $part) {
        $remaining_text = $part;
        $conditions = [];

        $condition_rows = $grouped_csv['とくせい条件'] ?? [];
        while (true) {
            // とくせい条件は文の前半部分なので「前方一致」でマッチさせる
            $match = koto_match_csv_template($remaining_text, $condition_rows, $input_key, 'prefix');
            if ($match) {
                if (is_array($match['acf_data'])) {
                    if (isset($match['acf_data']['condition_type_loop'])) {
                        foreach ($match['acf_data']['condition_type_loop'] as $cond_item) {
                            $conditions[] = $cond_item;
                        }
                    } else {
                        $conditions[] = $match['acf_data'];
                    }
                }
                // マッチした前方部分だけを確実に削る
                $remaining_text = trim(mb_substr($remaining_text, mb_strlen($match['matched_text'])));
            } else {
                break;
            }
        }

        $trait_rows = $grouped_csv['とくせい'] ?? [];
        $effect_data = [];
        // 条件の消し残りを考慮し、とくせいの効果部分は「後方一致」でマッチさせる
        $match = koto_match_csv_template($remaining_text, $trait_rows, $input_key, 'suffix');
        if ($match) {
            $effect_data = $match['acf_data'];

            if (isset($effect_data['gimmick_prefix'])) {
                unset($effect_data['gimmick_prefix']);
            }
        }

        if (is_array($effect_data)) {
            if (!empty($conditions)) {
                if (isset($effect_data['condition_type_loop']) && is_array($effect_data['condition_type_loop'])) {
                    $effect_data['condition_type_loop'] = array_merge($effect_data['condition_type_loop'], $conditions);
                } else {
                    $effect_data['condition_type_loop'] = $conditions;
                }
            }
            if (!empty($effect_data)) {
                $results[] = $effect_data;
            }
        }
    }

    return $results;
}

function koto_parse_text_by_type($text, $type, $grouped_csv, $input_key = '')
{
    $text = koto_preprocess_text($text);

    switch ($type) {
        case 'とくせい':
            return koto_parse_trait($text, $grouped_csv, $input_key);

        case 'わざ':
        case 'すごわざ条件':
        case '祝福':
        case 'リーダーとくせい':
            // $trait_rows = $grouped_csv[$type] ?? [];
            // $match = koto_match_csv_template($text, $trait_rows, $input_key);
            //  return $match ? $match['acf_data'] : null;
            return null;
        default:
            return null;
    }
}

// =================================================================
// 6. 種別ごとにパースしてすべてをACF化する関数
// =================================================================
function koto_build_acf_data_from_inputs($inputs, $grouped_csv)
{
    $acf_data = [];

    foreach ($inputs as $key => $text) {
        if (empty($text)) continue;

        $type = '';
        if (strpos($key, 'trait') !== false) {
            $type = 'とくせい';
        } elseif (strpos($key, 'waza') !== false && strpos($key, 'sugowaza_condition') === false) {
            $type = 'わざ';
        } elseif (strpos($key, 'sugowaza_condition') !== false) {
            $type = 'すごわざ条件';
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

// =================================================================
// 7. 取得したACFで記事を作る関数 / 既存キャラに追加する関数
// =================================================================
function koto_update_character_post_with_acf($post_id, $acf_data)
{
    if (!$post_id || empty($acf_data)) return false;

    $fields_to_update = [];

    foreach ($acf_data as $input_key => $values) {
        if (!is_array($values)) continue;

        // 連想配列(単一行データ)の場合は、配列でラップして複数行データと同じ構造にする
        if (!isset($values[0])) {
            $values = [$values];
        }

        foreach ($values as $item) {
            if (!is_array($item)) continue;

            $acf_field_name = '';

            // 1. パース結果のACFキーによる仕分け
            if (isset($item['available_moji'])) {
                $acf_field_name = 'available_moji_loop';
            }
            // 2. 入力欄の種類による仕分け
            else {
                if ($input_key === 'auto_input_trait1') {
                    $acf_field_name = 'first_trait_loop';
                } elseif ($input_key === 'auto_input_trait2') {
                    $acf_field_name = 'second_trait_loop';
                } elseif ($input_key === 'auto_input_waza') {
                    $acf_field_name = 'waza_group_loop';
                } elseif ($input_key === 'auto_input_sugowaza') {
                    $acf_field_name = 'sugowaza_group_loop';
                } elseif ($input_key === 'auto_input_blessing') {
                    $acf_field_name = 'blessing_trait_loop';
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

        update_field($acf_field_name, $existing_data, $post_id);
    }

    return true;
}

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

// =================================================================
// AJAXリクエストのフック
// =================================================================
add_action('wp_ajax_koto_parse_auto_input', 'koto_ajax_parse_auto_input');
add_action('wp_ajax_koto_update_post_from_auto_input', 'koto_ajax_update_post_from_auto_input');
add_action('wp_ajax_koto_create_post_from_auto_input', 'koto_ajax_create_post_from_auto_input');

function koto_ajax_parse_auto_input()
{
    $texts = isset($_POST['texts']) ? $_POST['texts'] : [];

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $parsed_data = koto_build_acf_data_from_inputs($texts, $grouped_csv);

    wp_send_json_success($parsed_data);
}

function koto_ajax_update_post_from_auto_input()
{
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $texts = isset($_POST['texts']) ? $_POST['texts'] : [];

    if (!$post_id) {
        wp_send_json_error(['message' => '対象の記事が選択されていません']);
    }

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $acf_data = koto_build_acf_data_from_inputs($texts, $grouped_csv);
    $result = koto_update_character_post_with_acf($post_id, $acf_data);

    if ($result) {
        wp_send_json_success(['message' => '自動入力を反映しました。画面を更新します。']);
    } else {
        wp_send_json_error(['message' => '自動入力の反映に失敗しました']);
    }
}

function koto_ajax_create_post_from_auto_input()
{
    $texts = isset($_POST['texts']) ? $_POST['texts'] : [];
    $character_name = isset($_POST['character_name']) ? sanitize_text_field($_POST['character_name']) : '';

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $acf_data = koto_build_acf_data_from_inputs($texts, $grouped_csv);

    $post_id = koto_create_character_post_from_acf($character_name, $acf_data);

    if ($post_id && !is_wp_error($post_id)) {
        wp_send_json_success([
            'post_id' => $post_id,
            'message' => '記事を作成しました',
            'edit_url' => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id)
        ]);
    } else {
        wp_send_json_error(['message' => '記事の作成に失敗しました']);
    }
}

/*
## 自動入力について
### 自動入力アルゴリズム
1. CSVファイルを「種別」列に応じて分けて
2. 種別ごとにCSVからACFをとってくる関数
3. ゲーム内文言をCSVに合わせて分割、削除する文言を削除
4. 種別ごとの文言に合わせて２の関数を再起的に呼び出す関数
5. 種別ごとに３の関数を呼び出してすべてをACF化する関数（行内容と入力欄を見てどの繰り返しフィールドか判断）
6. 4で作った各種関数で取得したACFで記事を作る関数と、既存キャラに追加する関数

### 種別ごとの再起呼び出し方法
#### とくせい
1. 丸数字で分割
2. とくせい条件の文言がなくなるまでとくせい条件を呼び出す
3. とくせいの文言からとくせい条件を呼び出す

#### わざ
#### すごわざ条件

### 種別
- とくせい
- とくせいの条件
- わざ
- わざ追加条件
- すごわざ条件
- 祝福
- リーダーとくせい
- リーダーとくせい条件
*/