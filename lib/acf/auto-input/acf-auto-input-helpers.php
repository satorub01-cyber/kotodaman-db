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

            foreach ($result_copy['sugo_detail_loop'] as $detail_index => $sugo_detail) {
                if (!is_array($sugo_detail)) {
                    continue;
                }

                $waza_type = isset($sugo_detail['waza_type']) ? $sugo_detail['waza_type'] : null;

                // waza_typeの値に応じて更新するキーを分岐
                if ($waza_type === 'attack' || $waza_type === 'command') {
                    $result_copy['sugo_detail_loop'][$detail_index]['attack_attr'] = $shift_attr_id;
                } else {
                    $result_copy['sugo_detail_loop'][$detail_index]['waza_target_detail'] = 'attr';
                    $result_copy['sugo_detail_loop'][$detail_index]['target_detail_attr'] = $shift_attr_id;
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
            // auto-input-追加必須: 新しい入力キー固有のマッチ結果加工が必要な場合はここへ追加
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
                if ($term && !is_wp_error($term)) {
                    $attr_ids[] = (int) $term->term_id;
                }
            }
            // 単一選択か複数選択かに関わらず、確実なIDの配列としてセットする
            if (strpos($key, 'single') !== false) {
                $value = !empty($attr_ids) ? [(int) $attr_ids[0]] : [];
            } else {
                $value = $attr_ids;
            }
        } elseif (strpos($key, 'species') === 0) {
            $value = str_replace('種族', '', $value);
            $attr_names = preg_split('/[・\|]/u', $value);
            $attr_ids = [];
            foreach ($attr_names as $name) {
                $term = get_term_by('name', trim($name), 'species');
                if ($term && !is_wp_error($term)) {
                    $attr_ids[] = (int) $term->term_id;
                }
            }
            // 単一選択か複数選択かに関わらず、確実なIDの配列としてセットする
            if (strpos($key, 'single') !== false) {
                $value = !empty($attr_ids) ? [(int) $attr_ids[0]] : [];
            } else {
                $value = $attr_ids;
            }
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

