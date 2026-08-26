<?php
if (!defined('ABSPATH')) exit;

// =================================================================
// 1. CSV読み込み用関数と種別グループ化
// =================================================================
/**
 * 対応表CSVを読み込み、ヘッダーをキーにした連想配列の行リストを返す。
 *
 * @param string $csv_path 読み込むCSVファイルの絶対パス。
 * @return array<int, array<string, string>> CSVの各行データ。ファイル未存在時は空配列。
 */
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

/**
 * CSV行配列を「種別」列ごとにグルーピングする。
 *
 * @param array<int, array<string, mixed>> $csv_data koto_load_csv_dictionary の戻り値。
 * @return array<string, array<int, array<string, mixed>>> 種別名をキーにした行配列。
 */
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
/**
 * CSVテンプレート文言から、プレースホルダを含む正規表現パターンを生成する。
 *
 * @param string $template テンプレート文字列（{$val} 形式の変数を含む）。
 * @param array<int, string> $seen_vars 既出変数名の追跡用配列（参照渡し）。
 * @param string $match_mode マッチ方式（exact|prefix|suffix|partial）。
 * @return string preg_match で利用する正規表現。
 */
function koto_generate_regex_pattern($template, &$seen_vars, $match_mode = 'exact')
{
    $parts = preg_split('/(\{\$[a-zA-Z0-9_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
    $pattern = '';

    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (preg_match('/^\{\$([a-zA-Z0-9_]+)\}$/', $part, $m)) {
                $var_name = $m[1];
                if (!in_array($var_name, $seen_vars, true)) {
                    if (strpos($var_name, 'val') === 0) {
                        $pattern .= '(?P<' . $var_name . '>[0-9０-９]+)';
                    } else {
                        $pattern .= '(?P<' . $var_name . '>.+?)';
                    }
                    $seen_vars[] = $var_name;
                } else {
                    $pattern .= '(?P=' . $var_name . ')';
                }
            } else {
                // プレースホルダ以外のテキストをエスケープ
                $escaped = preg_quote($part, '/');
                // CSV内に意図して記述された正規表現構文（.*? や .*）を復元
                $escaped = str_replace('\.\*\?', '.*?', $escaped);
                $escaped = str_replace('\.\*', '.*', $escaped);

                $pattern .= $escaped;
            }
        }
    }

    if ($match_mode === 'exact') return '/^' . $pattern . '$/u';
    if ($match_mode === 'prefix') return '/^' . $pattern . '/u';
    if ($match_mode === 'suffix') return '/' . $pattern . '$/u';
    return '/' . $pattern . '/u';
}

/**
 * CSVテンプレートマッチ処理のオプションをデフォルト付きで正規化する。
 *
 * @param array<string, mixed> $options {
 *   @type null|array $no_match_return  文言に一致する行が無いときの戻り値（既定: null）
 *   @type null|array $empty_acf_return  マッチしたがACF行が空のときの acf_data（既定: [[]]）
 * }
 * @return array<string, mixed> 正規化済みオプション。
 */
function koto_normalize_csv_match_options($options = [])
{
    $defaults = [
        'no_match_return' => null,
        'empty_acf_return' => [[]],
    ];
    return array_merge($defaults, $options);
}

/**
 * koto_match_csv_template の戻り値が「マッチあり」かどうか。
 * no_match_return に [] を指定した場合も区別できる。
 *
 * @param mixed $result koto_match_csv_template の戻り値。
 * @return bool matched_text キーを持つ配列なら true。
 */
function koto_is_csv_template_match($result)
{
    return is_array($result) && array_key_exists('matched_text', $result);
}

/**
 * 生成したACF行配列を、empty_acf_return の方針に合わせて仕上げる。
 *
 * @param array<int, mixed> $acf_rows ACF行候補の配列。
 * @param mixed $empty_acf_return 有効行が無かった場合の戻り値。
 * @return mixed 有効行配列、または empty_acf_return。
 */
function koto_finalize_match_acf_data($acf_rows, $empty_acf_return)
{
    $non_empty_rows = [];
    foreach ($acf_rows as $row) {
        if (is_array($row) && !empty($row)) {
            $non_empty_rows[] = $row;
        }
    }
    if (empty($non_empty_rows)) {
        return $empty_acf_return;
    }
    return $non_empty_rows;
}

/**
 * シフト属性ごとに結果配列を複製し、各要素へ sugo_shift_attr を付与する。
 *
 * @param array<int, array<string, mixed>> $results 変換済み結果。
 * @param array<int, int|string> $shift_attr_ids シフト属性のタームID配列。
 * @return array<int, array<string, mixed>> 複製済み結果。
 */
function koto_duplicate_results_for_shift_attrs($results, $shift_attr_ids)
{
    if (empty($shift_attr_ids)) {
        return $results;
    }

    $duplicated_results = [];

    foreach ($shift_attr_ids as $shift_attr_id) {
        $shift_attr_id = (int) $shift_attr_id;
        if ($shift_attr_id <= 0) {
            continue;
        }

        foreach ($results as $result) {
            if (!is_array($result) || !isset($result['sugo_detail_loop']) || !is_array($result['sugo_detail_loop'])) {
                continue;
            }

            $result_copy = $result;
            $result_copy['sugo_shift_attr'] = $shift_attr_id;

            if (!empty($result_copy['sugo_detail_loop']) && is_array($result_copy['sugo_detail_loop'])) {
                foreach ($result_copy['sugo_detail_loop'] as $detail_index => $sugo_detail) {
                    if (!is_array($sugo_detail) || !array_key_exists('attack_attr', $sugo_detail)) {
                        continue;
                    }

                    $result_copy['sugo_detail_loop'][$detail_index]['attack_attr'] = $shift_attr_id;
                }
            }

            $duplicated_results[] = $result_copy;
        }
    }

    return $duplicated_results;
}

/**
 * 入力文言をCSVテンプレート群と照合し、最初に一致した1行をACFデータ化して返す。
 *
 * @param string $text 判定対象の文言。
 * @param array<int, array<string, mixed>> $csv_rows 種別で絞ったCSV行配列。
 * @param string $input_key 入力欄の識別キー（trait1/trait2/blessing など）。
 * @param string $match_mode マッチ方式（exact|prefix|suffix|partial）。
 * @param array<string, mixed> $options no_match_return / empty_acf_return を指定するオプション。
 * @return array<string, mixed>|mixed マッチ時は acf_data と matched_text を含む配列、未マッチ時は no_match_return。
 */
function koto_match_csv_template($text, $csv_rows, $input_key = '', $match_mode = 'exact', $options = [])
{
    $options = koto_normalize_csv_match_options($options);

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

            // 文言がマッチした時点でこの行を採用（JSONが空・不正でも後続行へ進まない）
            $acf_rows = koto_apply_variables_to_json_rows($row['ACFに入力するJSON'] ?? '', $matches, $input_key);
            return [
                'acf_data' => koto_finalize_match_acf_data($acf_rows, $options['empty_acf_return']),
                'matched_text' => $matches[0],
            ];
        }
    }
    return $options['no_match_return'];
}

// =================================================================
// 3. JSONへの変数適用＆特殊処理関数 (確実に引き継ぐ)
// =================================================================

/**
 * CSVの「ACFに入力するJSON」列で | 区切りの複数JSONを分割する。
 * fgetcsv 後はオブジェクト間が }"|{ になる（CSV上は ""|""）。
 *
 * @param string $json_template CSV上のJSONテンプレート文字列。
 * @return array<int, string> 分割済みJSON文字列の配列。
 */
function koto_split_json_templates_by_pipe($json_template)
{
    $json_template = trim((string) $json_template);
    if ($json_template === '') {
        return [];
    }

    // fgetcsv 後の不要なダブルクォーテーションを削除
    // CSV上で ""|""{ のようになっていると |"{ として読み込まれる
    $json_template = str_replace('}"|"{', '}|{', $json_template);

    $parts = preg_split('/\s*\|\s*(?=\{)/u', $json_template);
    if ($parts === false || count($parts) === 0) {
        return [trim($json_template, ' "')];
    }

    // 各パートの先頭と末尾に残る可能性のある空白やダブルクォーテーションを削除
    $parts = array_map(function ($part) {
        return trim($part, ' "');
    }, $parts);

    return array_values(array_filter($parts, function ($part) {
        return $part !== '';
    }));
}

/**
 * マッチ結果の acf_data を行の配列に正規化する（単一連想配列 or 複数行）。
 *
 * @param mixed $acf_data koto_match_csv_template の acf_data。
 * @return array<int, array<string, mixed>> 行配列に正規化した結果。
 */
function koto_ensure_acf_data_list($acf_data)
{
    if ($acf_data === null) {
        return [];
    }
    if (!is_array($acf_data) || empty($acf_data)) {
        return [];
    }
    if (isset($acf_data[0]) && is_array($acf_data[0])) {
        return $acf_data;
    }
    return [$acf_data];
}

/**
 * テンプレート（| 区切り可）に変数を適用し、デコード済み行の配列を返す。
 *
 * @param string $json_template JSONテンプレート文字列（| 区切り可）。
 * @param array<string, mixed> $matches 正規表現マッチ結果。
 * @param string $input_key 入力欄の識別キー。
 * @return array<int, array<string, mixed>> 1行以上のACFデータ配列。
 */
function koto_apply_variables_to_json_rows($json_template, $matches, $input_key = '')
{
    $json_template = trim((string) $json_template);
    if ($json_template === '') {
        return [[]];
    }

    $parts = koto_split_json_templates_by_pipe($json_template);
    if (empty($parts)) {
        return [[]];
    }

    $rows = [];
    foreach ($parts as $part) {
        $decoded = koto_apply_variables_to_json($part, $matches, $input_key);
        $rows[] = is_array($decoded) ? $decoded : [];
    }
    return $rows;
}

/**
 * JSONテンプレート内のプレースホルダを実値へ置換し、必要な型変換・ターム解決を施して配列化する。
 *
 * @param string $json_template CSVに定義したJSONテンプレート。
 * @param array<string, mixed> $matches 正規表現マッチ結果（プレースホルダ値の元）。
 * @param string $input_key 入力欄の識別キー（フィールドキー変換時に使用）。
 * @return array<string, mixed>|null JSONデコード成功時は配列、失敗時は null。
 */
function koto_apply_variables_to_json($json_template, $matches, $input_key = '')
{
    $replacements = [];
    $unquote_keys = [];
    $flat_waza_target_fields = [];

    foreach ($matches as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
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
            $mojis = preg_split('/[・\|]/u', $value);
            $mojis = array_map(function ($moji) {
                return str_replace(['「', '」'], '', $moji);
            }, $mojis);

            // available_mojiのnameからslugへの対応表
            $moji_slug_map = [
                'あ' => 'a',
                'ぁ' => 'xa',
                'い' => 'i',
                'ぃ' => 'xi',
                'う' => 'u',
                'ぅ' => 'xu',
                'え' => 'e',
                'ぇ' => 'xe',
                'お' => 'o',
                'ぉ' => 'xo',
                'か' => 'ka',
                'が' => 'ga',
                'き' => 'ki',
                'ぎ' => 'gi',
                'く' => 'ku',
                'ぐ' => 'gu',
                'け' => 'ke',
                'げ' => 'ge',
                'こ' => 'ko',
                'ご' => 'go',
                'さ' => 'sa',
                'ざ' => 'za',
                'し' => 'shi',
                'じ' => 'ji',
                'す' => 'su',
                'ず' => 'zu',
                'せ' => 'se',
                'ぜ' => 'ze',
                'そ' => 'so',
                'ぞ' => 'zo',
                'た' => 'ta',
                'だ' => 'da',
                'ち' => 'chi',
                'ぢ' => 'di',
                'つ' => 'tsu',
                'づ' => 'du',
                'っ' => 'xtsu',
                'て' => 'te',
                'で' => 'de',
                'と' => 'to',
                'ど' => 'do',
                'な' => 'na',
                'に' => 'ni',
                'ぬ' => 'nu',
                'ね' => 'ne',
                'の' => 'no',
                'は' => 'ha',
                'ば' => 'ba',
                'ぱ' => 'pa',
                'ひ' => 'hi',
                'び' => 'bi',
                'ぴ' => 'pi',
                'ふ' => 'fu',
                'ぶ' => 'bu',
                'ぷ' => 'pu',
                'へ' => 'he',
                'べ' => 'be',
                'ぺ' => 'pe',
                'ほ' => 'ho',
                'ぼ' => 'bo',
                'ぽ' => 'po',
                'ま' => 'ma',
                'み' => 'mi',
                'む' => 'mu',
                'め' => 'me',
                'も' => 'mo',
                'や' => 'ya',
                'ゃ' => 'xya',
                'ゆ' => 'yu',
                'ゅ' => 'xyu',
                'よ' => 'yo',
                'ょ' => 'xyo',
                'ら' => 'ra',
                'り' => 'ri',
                'る' => 'ru',
                'れ' => 're',
                'ろ' => 'ro',
                'わ' => 'wa',
                'を' => 'wo',
                'ん' => 'nn'
            ];

            // DBクエリのCollation問題を完全に回避するため、全タームを1回だけ取得してPHPで判定する
            static $all_moji_terms = null;
            if ($all_moji_terms === null) {
                $all_moji_terms = get_terms([
                    'taxonomy'   => 'available_moji',
                    'hide_empty' => false,
                ]);
            }

            $term_ids = [];
            foreach ($mojis as $moji) {
                $target_name = trim($moji);
                if (isset($moji_slug_map[$target_name])) {
                    $target_slug = $moji_slug_map[$target_name];

                    if (!is_wp_error($all_moji_terms)) {
                        foreach ($all_moji_terms as $t) {
                            // PHPの厳密比較 (===) でスラッグを判定
                            if ($t->slug === $target_slug) {
                                $term_ids[] = (int) $t->term_id;
                                break;
                            }
                        }
                    }
                }
            }

            // 【原因特定用】実際にパースされたID配列をエラーログに書き出す
            error_log('[dot_separated_moji Debug] Input: ' . $value . ' => Result IDs: ' . print_r($term_ids, true));

            $value = $term_ids;
        } elseif (strpos($key, 'affiliation') === 0) {
            $attr_names = preg_split('/[・\|]/u', $value);
            $attr_ids = [];
            foreach ($attr_names as $name) {
                $term = get_term_by('name', trim($name), 'affiliation');
                if ($term) {
                    $attr_ids[] = $term->term_id;
                }
            }
            $value = $attr_ids;
        } elseif (strpos($key, 'attr') === 0) {
            $value = str_replace('属性', '', $value);
            $attr_names = preg_split('/[・\|]/u', $value);
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
            $attr_names = preg_split('/[・\|]/u', $value);
            $attr_ids = [];
            foreach ($attr_names as $name) {
                $term = get_term_by('name', trim($name), 'species');
                if ($term) {
                    $attr_ids[] = $term->term_id;
                }
            }
            $value = $attr_ids;
        } elseif (strpos($key, 'moji') === 0) {
            $value = str_replace('文字', '', $value);
            $attr_names = preg_split('/[・\|]/u', $value);
            $attr_ids = [];
            foreach ($attr_names as $name) {
                $term = get_term_by('name', trim($name), 'available_moji');
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
                $values = preg_split('/[・\|]/u', $value);
                $values = array_map(function ($v) {
                    $term = get_term_by('name', trim($v), 'attribute');
                    return $term ? $term->term_id : null;
                }, $values);
                $target_detail = ',"target_attr" : [' . implode(',', array_filter($values)) . ']';
            } elseif (strpos($value, '種族') !== false) {
                $target_type = 'species';
                $value = str_replace('種族', '', $value);
                $values = preg_split('/[・\|]/u', $value);
                $values = array_map(function ($v) {
                    $term = get_term_by('name', trim($v), 'species');
                    return $term ? $term->term_id : null;
                }, $values);
                $target_detail = ',"target_species" : [' . implode(',', array_filter($values)) . ']';
            } elseif (strpos($value, '「') !== false) {
                $target_type = 'group';
                $value = str_replace(['「', '」'], ['', ''], $value);
                $values = preg_split('/[・\|]/u', $value);
                $values = array_map(function ($v) {
                    $term = get_term_by('name', trim($v), 'affiliation');
                    return $term ? $term->term_id : null;
                }, $values);
                $target_detail = ',"target_group" : [' . implode(',', array_filter($values)) . ']';
            }
            $value = '{"target_type" :"' . $target_type . '"' . $target_detail . '}';
            $unquote_keys[] = $key;
        } elseif (strpos($key, 'resistance') === 0) {
            if (function_exists('koto_get_status_map')) {
                $status_map = koto_get_status_map();
                $mapped = array_search($value, $status_map, true);
                if ($mapped !== false) {
                    $value = $mapped;
                }
            }
        } elseif (strpos($key, 'prefix') === 0) {
            $value = str_replace(['増加', '強化', '上昇', '軽減'], '', $value);
            if (function_exists('koto_get_buff_prefix_map')) {
                $prefix_map = koto_get_buff_prefix_map();
                if (isset($prefix_map[$value])) {
                    $value = $prefix_map[$value];
                }
            }
        } elseif (strpos($key, 'dot_separated_val') === 0) {
            // 「・」で区切られた文字列をそのまま配列に
            $values = preg_split('/・/u', $value);
            $value = array_filter(array_map('trim', $values));
        } elseif (strpos($key, 'pipe_separated_val') === 0) {
            // |区切りの値を配列に
            $values = preg_split('/\|/u', $value);
            $value = array_filter(array_map('trim', $values));
        } elseif (strpos($key, 'heal_prefix') === 0) {
            // 超大きく、大きく、かなりなどの治癒フレーズを処理
            $value = str_replace(['超大きく', '大きく', 'かなり'], ['1.5', '1.2', '0.8'], $value);
        } elseif (strpos($key, 'command_prefix') === 0) {
            // 少量、多量、超多量などのコマンドフレーズを処理
            $value = str_replace(['爆絶多量', '超絶多量', '超多量', '多量', '少量'], ['5.25', '4.5', '4.5', '2.3', '1.0'], $value);
        } elseif (strpos($key, 'at_prefix') === 0) {
            // わざの倍率処理
            $value = str_replace(['爆絶強力', '超絶強力', '超強力', '強力'], ['most_strong', 'super_strong', 'very_strong', 'strong'], $value);
            if ($key === 'at_prefix') $key = 'at_prefix_slash';
            $at_key = str_replace('at_prefix_', '', $key);
            $per_hit_flag = strpos($at_key, '_per_hit') !== false;
            if ($per_hit_flag) {
                $at_key = str_replace('_per_hit', '', $at_key);
            }
            if (function_exists('koto_get_attack_map')) {
                $multiplier_map = koto_get_attack_map($at_key);
                if (isset($multiplier_map[$value])) {
                    $value = $multiplier_map[$value];
                }
            }
            if ($per_hit_flag && isset($matches['hit_count']) && is_numeric($matches['hit_count']) && is_numeric($value)) {
                $hit_count = (float) $matches['hit_count'];
                if ($hit_count > 0) {
                    $value = (float) $value / $hit_count;
                }
            }
        } elseif (strpos($key, 'at_target_up') === 0) {
            // 上昇、大きく上昇などを処理
            $value = str_replace(['大きく上昇', '上昇'], ['2.0', '1.5'], $value);
        } elseif (strpos($key, 'waza_target') === 0) {
            // 敵単体or敵全体、このターン攻撃する味方など
            $value = str_replace(['敵単体', '敵全体'], ['single_oppo', 'all_oppo'], $value);
        } elseif (strpos($key, 'waza_character_target') === 0) {
            // わざ系は Group ではなくフラットな target 系フィールドを使う
            $raw_target = trim((string) $value);
            $target_main = 'self';
            if (mb_strpos($raw_target, '味方全体') !== false || mb_strpos($raw_target, '味方全員') !== false || $raw_target === '味方') {
                $target_main = 'all_ally';
            } elseif (strpos($raw_target, '自身') !== false) {
                $target_main = 'self';
            } else {
                $target_main = 'limited_ally';
            }

            $detail_type = 'none';
            $detail_attr_ids = [];
            $detail_species_ids = [];
            $detail_group_ids = [];
            $detail_other = '';

            if ($target_main === 'limited_ally' || $target_main === 'limited_hand') {
                if (strpos($raw_target, '属性') !== false) {
                    $raw_target = str_replace('属性', '', $raw_target);
                    $attr_names = preg_split('/[・\|]/u', trim($raw_target));
                    foreach ((array) $attr_names as $name) {
                        $term = get_term_by('name', trim($name), 'attribute');
                        if ($term) {
                            $detail_attr_ids[] = $term->term_id;
                        }
                    }
                    if (!empty($detail_attr_ids)) {
                        $detail_type = 'attr';
                    }
                } elseif (strpos($raw_target, '種族') !== false) {
                    $raw_target = str_replace('種族', '', $raw_target);
                    $species_names = preg_split('/[・\|]/u', trim($raw_target));
                    foreach ((array) $species_names as $name) {
                        $term = get_term_by('name', trim($name), 'species');
                        if ($term) {
                            $detail_species_ids[] = $term->term_id;
                        }
                    }
                    if (!empty($detail_species_ids)) {
                        $detail_type = 'species';
                    }
                } elseif (strpos($raw_target, '「') !== false && strpos($raw_target, '」') !== false) {
                    $raw_target = str_replace('「', '', $raw_target);
                    $raw_target = str_replace('」', '', $raw_target);
                    $group_names = preg_split('/[・\|]/u', trim($raw_target));
                    foreach ((array) $group_names as $name) {
                        $term = get_term_by('name', trim($name), 'affiliation');
                        if ($term) {
                            $detail_group_ids[] = $term->term_id;
                        }
                    }
                    if (!empty($detail_group_ids)) {
                        $detail_type = 'group';
                    }
                } else {
                    $other = preg_replace('/^手札の/u', '', $raw_target);
                    $other = preg_replace('/(?:の)?味方(?:全体|全員)?$/u', '', $other);
                    $other = trim((string) $other);
                    if ($other !== '' && $other !== $raw_target) {
                        $detail_type = 'other';
                        $detail_other = $other;
                    }
                }
            }

            $flat_waza_target_fields = [
                'waza_target_detail' => $detail_type,
                'target_detail_attr' => $detail_attr_ids,
                'target_detail_species' => $detail_species_ids,
                'target_detail_group' => $detail_group_ids,
                'target_detail_other' => $detail_other,
            ];
            $value = $target_main;
        } elseif (strpos($key, 'dot_separated_fuku_val') === 0) {
            // 「・」で切ったうえで特殊処理（福条件付き値の並び）
            $values = preg_split('/・/u', $value);
            $value = array_filter(array_map('trim', $values));
        } elseif (strpos($key, 'characters') === 0) {
            $value = implode(',', mb_str_split($value));
        }

        if (is_array($value)) {
            $value = '[' . implode(',', $value) . ']';
            $unquote_keys[] = $key;
        } elseif (is_numeric($value)) {
            $unquote_keys[] = $key;
        }
        $replacements[$key] = $value;
    }

    // $val が $val2 を壊さないよう、長いプレースホルダ名から置換
    uksort($replacements, function ($a, $b) {
        return strlen($b) - strlen($a);
    });
    $json_str = $json_template;
    foreach ($replacements as $key => $value) {
        $val_str = (string) $value;
        if (in_array($key, $unquote_keys, true)) {
            $json_str = str_replace('"$' . $key . '"', $val_str, $json_str);
        }
        $json_str = str_replace('$' . $key, $val_str, $json_str);
    }

    // --- デバッグ用ログ出力 ---
    $decoded = json_decode($json_str, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if (strpos($host, 'kotodaman-db.com') === false) {
            error_log('[AutoInput Error] JSON Decode Failed: ' . json_last_error_msg() . ' / String: ' . $json_str);
        }
    } elseif (is_array($decoded)) {
        // Groupフィールドが誤って配列でラップされている場合に修正します。
        // これらはGroupフィールドであり、単一の連想配列であるべきです。
        $group_fields = ['target_field_group', 'field_69397ce6c85cb', 'field_693981d871588', 'deck_ally_field_group'];

        foreach ($group_fields as $field_name) {
            if (isset($decoded[$field_name]) && is_array($decoded[$field_name])) {
                $field_value = $decoded[$field_name];
                // 値が1行だけのリピーター形式 [ 0 => [ 'key' => 'value' ] ] になっているかチェックします。
                if (count($field_value) === 1 && isset($field_value[0]) && is_array($field_value[0])) {
                    // Groupフィールドの正しい形式（単一の連想配列）にアンラップします。
                    $decoded[$field_name] = $field_value[0];
                }
            }
        }

        if (!empty($flat_waza_target_fields)) {
            foreach ($flat_waza_target_fields as $extra_key => $extra_value) {
                if (!array_key_exists($extra_key, $decoded) || $decoded[$extra_key] === '' || $decoded[$extra_key] === [] || $decoded[$extra_key] === null) {
                    $decoded[$extra_key] = $extra_value;
                }
            }
        }

        $key_map = [];
        if ($input_key === 'auto_input_trait1') {
            $key_map = [
                'whose_trait' => [
                    'parent' => 'field_69397ce6c85cb',
                    'children' => [
                        'target_type'    => 'field_69397ce6c85d2',
                        'target_attr'    => 'field_69397ce6c85d3',
                        'target_species' => 'field_69397ce6c85d4',
                        'target_group'   => 'field_69397ce6c85d5',
                        'target_other'   => 'field_69397ce6c85d6'
                    ]
                ],
                'deck_ally_field_group' => [
                    'parent' => 'field_69397bdd6ca4b',
                    'children' => [
                        'target_type'    => 'field_69397bde6ca52',
                        'target_attr'    => 'field_69397bde6ca53',
                        'target_species' => 'field_69397bde6ca54',
                        'target_group'   => 'field_69397bde6ca55',
                        'target_other'   => 'field_69397bde6ca56'
                    ]
                ]
            ];
        } elseif ($input_key === 'auto_input_trait2') {
            $key_map = [
                'whose_trait' => [
                    'parent' => 'field_693981d871588',
                    'children' => [
                        'target_type'    => 'field_693981d871589',
                        'target_attr'    => 'field_693981d87158a',
                        'target_species' => 'field_693981d87158b',
                        'target_group'   => 'field_693981d87158c',
                        'target_other'   => 'field_693981d87158d'
                    ]
                ],
                'deck_ally_field_group' => [
                    'parent' => 'field_693981d871590',
                    'children' => [
                        'target_type'    => 'field_693981d871591',
                        'target_attr'    => 'field_693981d871592',
                        'target_species' => 'field_693981d871593',
                        'target_group'   => 'field_693981d871594',
                        'target_other'   => 'field_693981d871595'
                    ]
                ]
            ];
        }

        // 定義したマップに従って、パース後の配列キーを安全に置換する
        foreach ($key_map as $group_name => $map) {
            if (isset($decoded[$group_name])) {
                $group_data = $decoded[$group_name];
                $new_group_data = [];

                if (is_array($group_data)) {
                    foreach ($group_data as $sub_key => $sub_val) {
                        if (isset($map['children'][$sub_key])) {
                            $new_group_data[$map['children'][$sub_key]] = $sub_val;
                        } else {
                            $new_group_data[$sub_key] = $sub_val;
                        }
                    }
                }

                $decoded[$map['parent']] = $new_group_data;
                unset($decoded[$group_name]);
            }
        }
    }

    // -------------------------

    return $decoded;
}

// =================================================================
// 4. 前処理と文言の分割
// =================================================================
/**
 * 種別に応じて、前処理で除去する文言一覧を返す。
 *
 * @param string $category 種別（日本語名 or waza/trait/leader/blessing）。
 * @return array<int, string> 該当種別で無視する文言の配列。
 */
function koto_get_ignore_texts_by_category($category = '')
{
    $category_map = [
        'waza' => 'わざ',
        'trait' => 'とくせい',
        'leader' => 'リーダーとくせい',
        'blessing' => '祝福',
    ];

    $normalized_category = $category_map[$category] ?? $category;

    $ignore_texts_by_category = [
        'わざ' => [
            'さらに',
            '、または',
            '追加で',
            // '【',
            // '】',
            '(汚染による効果を受けない)',
            '(味方のコトダマンのみ適用されます)',
            '(ターン開始時に重圧付与後の経過ターン数に応じた段階の被ダメージ増加状態を付与し、味方行動終了時に被ダメージ増加状態の段階と効果値に応じたダメージを与える効果)',
            '(一部攻撃を除く)',
            '。',
        ],
        'とくせい' => [
            'サブ属性を対象とするリーダーとくせい・とくせいの効果を受けることができる(受ける効果はメイン属性と重複しない)',
            '。サブ属性を対象とするリーダーとくせい・とくせいの効果を受けることができる(受ける効果はメイン属性と重複しない)',
            '(福に応じて数値が変動)',
            '※濁音、半濁音、小文字も同じ文字とする',
            '(メイン属性のみを参照する)',
            '※この効果のダメージは小数点切り上げとなり、HPが必ず1残る',
            'このコトダマンを含むことばで指定コンボ達成した場合、達成段階に応じて効果が変化する。',
            '※同じとくせいを持ったコトダマンが複数いる場合、1ターンに2体まで発動する',
            'また、クエスト終了まで変身状態は維持される。',
            '※この効果は重複しません。',
            '※コンボ＋の効果は重複せず、最も高い数値が加算されます',
        ],
        'すごわざ発動条件' => [
            '「',
            '」',
            '以上',
        ],
        'リーダーとくせい' => [],
        '祝福' => [],
    ];

    $category_ignore_texts = $ignore_texts_by_category[$normalized_category] ?? [];
    return $category_ignore_texts;
}

/**
 * CSV照合前の入力文言を正規化する（記号統一・空白除去・不要文言除去）。
 *
 * @param string $text 元の入力文言。
 * @param string $category 種別。除外文言の切り替えに使用。
 * @return string 前処理後の文言。
 */
function koto_preprocess_text($text, $category = '')
{
    $ignore_texts = koto_get_ignore_texts_by_category($category);

    $text = preg_replace('/\(敵の行動時、そのターンに自身が各敵にわざ・すごわざ・コトわざで与えた合計ダメージの\d+%の値で固定ダメージを与える効果\)/u', '', $text);
    // 英数字とスペースを半角に
    $text = mb_convert_kana($text, 'as', 'UTF-8');

    // CSVのフォーマットに合わせるため、特定の記号を全角または半角に正規化
    $hankaku_to_zenkaku = [
        '[' => '【',
        ']' => '】',
        '｢' => '「',
        '｣' => '」',
        '･' => '・', // 半角中点を全角に
        '·' => '・', // 中点を全角に
    ];
    $zenkaku_to_hankaku = [
        '％' => '%',
        '（' => '(',
        '）' => ')',
        '＋' => '+',
        '－' => '-',
        '｜' => '|',
    ];
    $typo_corrections = [
        '味方のコトダマンのみ適用されます' => '味方のコトダマンのみ適用される',
        '味方のコトダマンのみ適用される' => '味方のコトダマンのみ適用される',
        'すこわざ' => 'すごわざ',
        'わさ' => 'わざ',
        'すごわさ' => 'すごわざ',
        'すこわさ' => 'すごわざ',
        '擊' => '撃',
    ];
    $text = str_replace(array_keys($hankaku_to_zenkaku), array_values($hankaku_to_zenkaku), $text);
    $text = str_replace(array_keys($zenkaku_to_hankaku), array_values($zenkaku_to_hankaku), $text);
    $text = str_replace(array_keys($typo_corrections), array_values($typo_corrections), $text);

    // 改行コードを統一
    $text = str_replace(['\n', '\r', "\n", "\r"], "\n", $text);
    $text = str_replace(' ', '', $text);
    $text = trim(str_replace($ignore_texts, '', $text));

    return trim($text);
}

/**
 * 丸数字・改行・記号を区切りとして文言を分割する。
 *
 * @param string $text 分割対象の文言。
 * @return array<int, string> 空要素を除いた分割後の文言配列。
 */
function koto_split_by_circled_numbers($text)
{
    $parts = preg_split('/[①②③④⑤⑥⑦⑧⑨⑩\n\/／●■★]/u', $text, -1, PREG_SPLIT_NO_EMPTY);
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
    $text = koto_preprocess_text($text, $type);

    switch ($type) {
        case 'とくせい':
            return koto_parse_trait($text, $grouped_csv, $input_key);

        case 'わざ':
            return koto_parse_waza($text, $grouped_csv, $input_key);

        case 'すごわざ発動条件':
            return koto_parse_sugowaza_condition($text, $grouped_csv, $input_key);
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

        $type = '';
        if (strpos($key, 'trait') !== false) {
            $type = 'とくせい';
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

// =================================================================
// 7. 取得したACFで記事を作る関数 / 既存キャラに追加する関数
// =================================================================
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
                        $acf_field_name = 'kotowaza_group_loop';
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
    $texts = isset($_POST['texts']) ? $_POST['texts'] : [];

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $parsed_data = koto_build_acf_data_from_inputs($texts, $grouped_csv);

    wp_send_json_success($parsed_data);
}

/**
 * 既存投稿へ自動入力結果を反映するAJAXエンドポイント。
 *
 * @return void
 */
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

    if (empty($acf_data)) {
        wp_send_json_error([
            'message' => 'パース結果が空です。対応表に未登録の文言、またはJSONの生成に失敗した可能性があります。',
        ]);
    }

    $result = koto_update_character_post_with_acf($post_id, $acf_data);

    if ($result) {
        // ACFフィールドの更新後に計算用データを再生成（spec等の更新）
        do_action('acf/save_post', $post_id);

        wp_send_json_success(['message' => '自動入力を反映しました。内容を保存します。']);
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
    $texts = isset($_POST['texts']) ? $_POST['texts'] : [];
    $character_name = isset($_POST['character_name']) ? sanitize_text_field($_POST['character_name']) : '';

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $csv_data = koto_load_csv_dictionary($csv_path);
    $grouped_csv = koto_group_csv_by_type($csv_data);

    $acf_data = koto_build_acf_data_from_inputs($texts, $grouped_csv);

    $post_id = koto_create_character_post_from_acf($character_name, $acf_data);

    if ($post_id && !is_wp_error($post_id)) {
        // ACFフィールドの更新後に計算用データを再生成（spec等の更新）
        do_action('acf/save_post', $post_id);

        wp_send_json_success([
            'post_id' => $post_id,
            'message' => '記事を作成しました',
            'edit_url' => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id)
        ]);
    } else {
        wp_send_json_error(['message' => '記事の作成に失敗しました']);
    }
}
