<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB 祝福・EX祝福 自動入力モジュール
 *
 * 1. 祝福トークンレジストリ（★6 / ★5 の数値切り替えおよびACF構造定義）
 * 2. テンプレート展開処理（attr_killer1, species_killer1 等のインデックス必須チェック）
 * 3. 既存キャラ投稿IDからのテンプレート逆抽出ヘルパー（インデックス付き出力）
 * 4. タイプ2（とくせい自動入力流用＋祝福ポイント設定）変換処理
 * 5. 祝福入力データの正規化と統合パース
 */

/**
 * 祝福トークンレジストリを取得する。
 *
 * //要チェック: 新しいトークンの追加や、★6・★5ごとの数値（min_value / max_value / blessing_value）の変更はここで行えます。
 *
 * @return array<string, array<string, mixed>> トークン識別子をキーとした定義配列。
 */
function koto_get_blessing_token_registry()
{
    return [
        // HPアップ（レベル制CSV）
        'hp_up' => [
            'label'                => 'HPアップ',
            'trait_type'           => 'status_up',
            'status_up'            => 'hp',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'csv',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '200!'],
                'star5' => ['blessing_value' => '100,125,150,175,200,230,260,290,320,350'],
            ],
        ],

        // ATKアップ（レベル制CSV）
        'atk_up' => [
            'label'                => 'ATKアップ',
            'trait_type'           => 'status_up',
            'status_up'            => 'atk',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'csv',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '50!'],
                'star5' => ['blessing_value' => '25,30,35,40,45,50,55,60,65,70'],
            ],
        ],

        // 属性キラー（min_max制。インデックス付き attr_killer1, attr_killer2 で指定）
        // //要チェック: キラーは★5だと値が -5（★6: 10~20% -> ★5: 5~15%）
        'attr_killer' => [
            'label'                => '属性キラー',
            'trait_type'           => 'damage_correction',
            'damage_correction'    => 'killer',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'attr',
            'rarity_values'        => [
                'star6' => ['min_value' => '10', 'max_value' => '20'],
                'star5' => ['min_value' => '5',  'max_value' => '15'],
            ],
        ],

        // 種族キラー（min_max制。インデックス付き species_killer1, species_killer2 で指定）
        // //要チェック: キラーは★5だと値が -5（★6: 15~25% -> ★5: 10~20%）
        'species_killer' => [
            'label'                => '種族キラー',
            'trait_type'           => 'damage_correction',
            'damage_correction'    => 'killer',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'species',
            'rarity_values'        => [
                'star6' => ['min_value' => '15', 'max_value' => '25'],
                'star5' => ['min_value' => '10', 'max_value' => '20'],
            ],
        ],

        // 属性軽減（min_max制。インデックス付き attr_mitigation1, attr_mitigation2 で指定）
        'attr_mitigation' => [
            'label'                => '属性軽減',
            'trait_type'           => 'status_up',
            'status_up'            => 'mitigation',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'attr',
            'rarity_values'        => [
                'star6' => ['min_value' => '10', 'max_value' => '20'],
                'star5' => ['min_value' => '5',  'max_value' => '15'],
            ],
        ],

        // 回避率UP（min_max制）
        'dodge' => [
            'label'                => '回避率UP',
            'trait_type'           => 'status_up',
            'status_up'            => 'dodge',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['min_value' => '4', 'max_value' => '12'],
                'star5' => ['min_value' => '4', 'max_value' => '12'],
            ],
        ],
    ];
}

/**
 * レアリティが ★5 かどうかを判定する。
 *
 * @param mixed $rarity レアリティ値（文字列・数値・配列など）。
 * @param int $post_id 投稿ID（指定があればDBのタームもフォールバック確認）。
 * @return bool ★5の場合は true、それ以外（未指定、★6、スペシャル等）は false。
 */
function koto_is_blessing_star5_rarity($rarity = null, $post_id = 0)
{
    if (is_array($rarity)) {
        foreach ($rarity as $r_item) {
            if (koto_is_blessing_star5_rarity($r_item, 0)) {
                return true;
            }
        }
        return false;
    }

    if ($rarity !== null && $rarity !== '') {
        $str = trim((string) $rarity);
        if ($str === '5' || $str === '星5' || $str === '★5' || $str === '86') {
            return true;
        }
        if (preg_match('/^[^\d]*5[^\d]*$/u', $str) && strpos($str, '6') === false) {
            return true;
        }
    }

    if ($post_id > 0 && function_exists('wp_get_object_terms')) {
        $terms = wp_get_object_terms($post_id, 'rarity');
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $t) {
                if ($t->slug === '5' || $t->name === '5' || (int) $t->term_id === 86) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * 文字列または配列で渡された属性・種族リストを配列へ正規化する。
 *
 * @param mixed $val 配列またはカンマ・中黒区切り文字列。
 * @return array<int, string> 正規化された文字列配列。
 */
function koto_normalize_blessing_param_array($val)
{
    if (is_array($val)) {
        $out = [];
        foreach ($val as $item) {
            if (is_scalar($item)) {
                $trimmed = trim((string) $item);
                $out[] = $trimmed;
            }
        }
        // 末尾の連続する空文字を削除（中間のインデックス位置は保持する）
        while (!empty($out) && end($out) === '') {
            array_pop($out);
        }
        return $out;
    }
    if (is_scalar($val)) {
        $str = trim((string) $val);
        if ($str === '') {
            return [];
        }
        $parts = preg_split('/[・,、\/／\s]+/u', $str, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? array_values(array_filter(array_map('trim', $parts), 'strlen')) : [];
    }
    return [];
}

/**
 * 属性・種族の名称またはIDからタームIDを解決する。
 *
 * @param string|int $val 属性または種族の名称/ID。
 * @param string $taxonomy 'attribute' または 'species'。
 * @return int 解決されたタームID（見つからない場合は 0）。
 */
function koto_resolve_blessing_term_id($val, $taxonomy)
{
    $val_str = trim((string) $val);
    if ($val_str === '') return 0;

    $clean = preg_replace('/(属性|種族)$/u', '', $val_str);
    if (ctype_digit($clean)) {
        return (int) $clean;
    }

    if (function_exists('get_term_by')) {
        $term = get_term_by('name', $clean, $taxonomy) ?: get_term_by('slug', $clean, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }

    return 0;
}

/**
 * 1つのトークン定義とパラメータからACF行データを構築する。
 *
 * @param string $base_token 基本トークン名（例: 'attr_killer', 'hp_up'）。
 * @param int|null $target_id ターゲットタームID（属性IDまたは種族ID）。
 * @param bool $is_star5 ★5かどうか。
 * @return array<string, mixed>|null ACF行データ（未登録トークンの場合は null）。
 */
function koto_build_blessing_token_row($base_token, $target_id = null, $is_star5 = false)
{
    $registry = koto_get_blessing_token_registry();
    if (!isset($registry[$base_token])) {
        return null;
    }

    $def = $registry[$base_token];
    $rarity_key = $is_star5 ? 'star5' : 'star6';
    $values = $def['rarity_values'][$rarity_key] ?? ($def['rarity_values']['star6'] ?? []);

    $self_target = [
        'target_type'    => 'self',
        'target_attr'    => [],
        'target_species' => [],
        'target_group'   => [],
        'target_other'   => '',
    ];

    $target_group = $self_target;
    if ($def['target_type'] === 'attr') {
        $target_group = [
            'target_type'    => 'attr',
            'target_attr'    => $target_id ? [(int) $target_id] : [],
            'target_species' => [],
            'target_group'   => [],
            'target_other'   => '',
        ];
    } elseif ($def['target_type'] === 'species') {
        $target_group = [
            'target_type'    => 'species',
            'target_attr'    => [],
            'target_species' => $target_id ? [(int) $target_id] : [],
            'target_group'   => [],
            'target_other'   => '',
        ];
    }

    $row = [
        'whose_trait'          => $self_target,
        'target_field_group'   => $target_group,
        'trait_type'           => $def['trait_type'],
        'pt_pattern'           => $def['pt_pattern'],
        'blessing_level_value' => $def['blessing_level_value'],
        'rate_type'            => $def['rate_type'],
    ];

    if (!empty($def['status_up'])) {
        $row['status_up'] = $def['status_up'];
    }
    if (!empty($def['damage_correction'])) {
        $row['damage_correction'] = $def['damage_correction'];
    }

    foreach ($values as $k => $v) {
        $row[$k] = $v;
    }

    return $row;
}

/**
 * CSVの「祝福テンプレート」定義と属性・種族配列、レアリティを受け取り、ACFの行配列へ展開する。
 *
 * 属性・種族を必要とするトークン（attr_killer, species_killer, attr_mitigation）は
 * 必ずインデックス（例: attr_killer1, species_killer2）が付与されている必要があります。
 * インデックスに対応する属性・種族が不足している場合は $warnings に警告が追加されます。
 *
 * @param string $template_def CSVに記載されたテンプレート定義文字列（例: "{hp_up,attr_killer1,species_killer1}"）。
 * @param array<int, string|int> $attrs キラー対象の属性配列（1始まりのインデックスに対応）。
 * @param array<int, string|int> $species キラー対象の種族配列（1始まりのインデックスに対応）。
 * @param mixed $rarity レアリティ値（★5の場合はキラー値が -5 される）。
 * @param array<int, string> &$warnings 属性・種族不足などの警告を格納する参照配列。
 * @return array<int, array<string, mixed>> ACFリピーター行配列。
 */
function koto_expand_blessing_template_to_acf($template_def, array $attrs = [], array $species = [], $rarity = null, array &$warnings = [])
{
    $template_def = trim((string) $template_def);
    if ($template_def === '') {
        return [];
    }

    $is_star5 = koto_is_blessing_star5_rarity($rarity);

    // 属性・種族をタームID配列へ解決（0始まりキーを維持し、インデックス1は index 0 に対応）
    $attr_ids = [];
    foreach ($attrs as $i => $a) {
        $tid = koto_resolve_blessing_term_id($a, 'attribute');
        $attr_ids[$i] = $tid > 0 ? $tid : 0;
    }

    $species_ids = [];
    foreach ($species as $i => $s) {
        $tid = koto_resolve_blessing_term_id($s, 'species');
        $species_ids[$i] = $tid > 0 ? $tid : 0;
    }

    // JSON形式が直接書かれていた場合のフォールバック
    if (strpos($template_def, '{"') === 0 || strpos($template_def, '[{') === 0) {
        $decoded = json_decode($template_def, true);
        if (is_array($decoded)) {
            return isset($decoded[0]) ? $decoded : [$decoded];
        }
    }

    $trimmed = trim($template_def, "{} \t\n\r\0\x0B");
    if ($trimmed === '') {
        return [];
    }
    $tokens = array_values(array_filter(array_map('trim', explode(',', $trimmed)), 'strlen'));
    if (empty($tokens)) {
        return [];
    }

    $missing_attrs = [];
    $missing_species = [];

    $rows = [];
    foreach ($tokens as $token) {
        // 1. 属性キラー（インデックス必須: attr_killer1, attr_killer2 等）
        if (preg_match('/^attr_killer_?(\d+)$/i', $token, $m)) {
            $idx_num = (int) $m[1];
            $idx = max(0, $idx_num - 1);
            $target_id = !empty($attr_ids[$idx]) ? $attr_ids[$idx] : null;
            if (!$target_id) {
                $missing_attrs[$idx_num][] = $token;
            }
            $row = koto_build_blessing_token_row('attr_killer', $target_id, $is_star5);
            if ($row) $rows[] = $row;
            continue;
        }

        // 2. 種族キラー（インデックス必須: species_killer1, species_killer2 等）
        if (preg_match('/^species_killer_?(\d+)$/i', $token, $m)) {
            $idx_num = (int) $m[1];
            $idx = max(0, $idx_num - 1);
            $target_id = !empty($species_ids[$idx]) ? $species_ids[$idx] : null;
            if (!$target_id) {
                $missing_species[$idx_num][] = $token;
            }
            $row = koto_build_blessing_token_row('species_killer', $target_id, $is_star5);
            if ($row) $rows[] = $row;
            continue;
        }

        // 3. 属性軽減（インデックス必須: attr_mitigation1, attr_mitigation2 等）
        if (preg_match('/^(?:attr_)?mitigation_?(\d+)$/i', $token, $m)) {
            $idx_num = (int) $m[1];
            $idx = max(0, $idx_num - 1);
            $target_id = !empty($attr_ids[$idx]) ? $attr_ids[$idx] : null;
            if (!$target_id) {
                $missing_attrs[$idx_num][] = $token;
            }
            $row = koto_build_blessing_token_row('attr_mitigation', $target_id, $is_star5);
            if ($row) $rows[] = $row;
            continue;
        }

        // 4. インデックスなしキラー・軽減のフォールバック警告
        if (preg_match('/^attr_killer$/i', $token)) {
            $warnings[] = '属性キラー（attr_killer）にインデックス（例: attr_killer1）が指定されていません。';
            $row = koto_build_blessing_token_row('attr_killer', null, $is_star5);
            if ($row) $rows[] = $row;
            continue;
        }
        if (preg_match('/^species_killer$/i', $token)) {
            $warnings[] = '種族キラー（species_killer）にインデックス（例: species_killer1）が指定されていません。';
            $row = koto_build_blessing_token_row('species_killer', null, $is_star5);
            if ($row) $rows[] = $row;
            continue;
        }
        if (preg_match('/^(?:attr_)?mitigation$/i', $token)) {
            $warnings[] = '属性軽減（attr_mitigation）にインデックス（例: attr_mitigation1）が指定されていません。';
            $row = koto_build_blessing_token_row('attr_mitigation', null, $is_star5);
            if ($row) $rows[] = $row;
            continue;
        }

        // 5. その他の固定トークン（hp_up, atk_up, dodge 等）
        $row = koto_build_blessing_token_row($token, null, $is_star5);
        if ($row) {
            $rows[] = $row;
        }
    }

    // //要チェック: 属性・種族不足時の警告メッセージ文面やフォーマットの調整はここで行えます。
    if (!empty($missing_attrs)) {
        ksort($missing_attrs);
        foreach ($missing_attrs as $idx_num => $toks) {
            $tok_str = implode(', ', array_unique($toks));
            $warnings[] = sprintf('属性インデックス%d（%s）に対する属性が足りません。', $idx_num, $tok_str);
        }
    }
    if (!empty($missing_species)) {
        ksort($missing_species);
        foreach ($missing_species as $idx_num => $toks) {
            $tok_str = implode(', ', array_unique($toks));
            $warnings[] = sprintf('種族インデックス%d（%s）に対する種族が足りません。', $idx_num, $tok_str);
        }
    }

    return $rows;
}

/**
 * 既存キャラクター投稿の blessing_trait_loop からテンプレート定義（インデックス付きトークン形式）を逆抽出する。
 *
 * @param int $post_id 抽出元のキャラクター投稿ID。
 * @param string $template_key CSVに登録するテンプレート名。
 * @return array<string, mixed> 抽出結果の連想配列。
 */
function koto_extract_blessing_template_from_post($post_id, $template_key = 'pattern_custom')
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || !function_exists('get_field')) {
        return ['csv_line' => '', 'shorthand' => '', 'tokens' => [], 'raw_rows' => []];
    }
    $rows = get_field('blessing_trait_loop', $post_id);
    if (!is_array($rows)) {
        return ['csv_line' => '', 'shorthand' => '', 'tokens' => [], 'raw_rows' => []];
    }

    $tokens = [];
    $default_rows = [];
    $attr_killer_count = 0;
    $species_killer_count = 0;
    $attr_mitigation_count = 0;

    foreach ($rows as $r) {
        if (($r['pt_pattern'] ?? '') !== 'default') {
            continue;
        }
        $default_rows[] = $r;
        $tt = $r['trait_type'] ?? '';
        $su = $r['status_up'] ?? '';
        $dc = $r['damage_correction'] ?? '';
        $target_type = $r['target_field_group']['target_type'] ?? '';

        if ($tt === 'status_up' && $su === 'hp') {
            $tokens[] = 'hp_up';
        } elseif ($tt === 'status_up' && $su === 'atk') {
            $tokens[] = 'atk_up';
        } elseif ($tt === 'damage_correction' && $dc === 'killer' && $target_type === 'attr') {
            $attr_killer_count++;
            $tokens[] = 'attr_killer' . $attr_killer_count;
        } elseif ($tt === 'damage_correction' && $dc === 'killer' && $target_type === 'species') {
            $species_killer_count++;
            $tokens[] = 'species_killer' . $species_killer_count;
        } elseif ($tt === 'status_up' && $su === 'mitigation') {
            $attr_mitigation_count++;
            $tokens[] = 'attr_mitigation' . $attr_mitigation_count;
        } elseif ($tt === 'status_up' && $su === 'dodge') {
            $tokens[] = 'dodge';
        } else {
            $tokens[] = 'unknown_' . ($tt ?: 'trait');
        }
    }

    $shorthand = '{' . implode(',', $tokens) . '}';
    $csv_line = sprintf('祝福テンプレート,%s,"%s"', $template_key, $shorthand);

    return [
        'post_id'      => $post_id,
        'post_title'   => function_exists('get_the_title') ? get_the_title($post_id) : '',
        'template_key' => $template_key,
        'shorthand'    => $shorthand,
        'tokens'       => $tokens,
        'csv_line'     => $csv_line,
        'raw_rows'     => $default_rows,
    ];
}

/**
 * とくせいパーサー（koto_parse_trait）が生成した1行を祝福リピーター用の構造へ変換する。
 *
 * @param array<string, mixed> $row koto_parse_trait の出力行。
 * @param string $point 必要祝福ポイント。
 * @param bool $is_ex EX祝福かどうか。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @return array<int, array<string, mixed>> 変換後の行配列。
 */
function koto_convert_trait_row_to_blessing_rows(array $row, $point, $is_ex, array $grouped_csv)
{
    $point_str = trim((string) $point);

    // 1. 文字変換追加（available_moji_loop 行き）
    if (isset($row['available_moji'])) {
        $row['unlock_place'] = 'blessing';
        if ($point_str !== '') {
            $row['unlock_need_point'] = $point_str;
        }
        return [$row];
    }

    // 2. すごわざ発動条件追加（sugowaza_condition 行き）
    if (($row['trait_type'] ?? '') === 'sugowaza_condition' && !empty($row['sugowaza_condition'])) {
        $cond_raw = (string) $row['sugowaza_condition'];
        $cond_preprocessed = koto_preprocess_text($cond_raw, 'すごわざ発動条件');
        $cond_rows = koto_parse_sugowaza_condition($cond_preprocessed, $grouped_csv, 'auto_input_sugowaza_condition');
        if (!empty($cond_rows)) {
            $out = [];
            foreach ($cond_rows as $c_row) {
                $c_row['get_palce'] = $is_ex ? 'ex_blessing' : 'blessing';
                if ($point_str !== '') {
                    $c_row['need_blessing_point'] = $point_str;
                }
                $c_row['_target_repeater'] = 'sugowaza_condition';
                $out[] = $c_row;
            }
            return $out;
        }
    }

    // 3. 通常の祝福とくせい行（blessing_trait_loop / blessing_ex_trait_loop 行き）
    $row['pt_pattern'] = 'single';
    if ($point_str !== '') {
        $row['need_point'] = $point_str;
    }
    if (array_key_exists('trait_rate', $row)) {
        if (!isset($row['blessing_value']) || $row['blessing_value'] === '') {
            $row['blessing_value'] = (string) $row['trait_rate'];
        }
        unset($row['trait_rate']);
    }

    return [$row];
}

/**
 * 祝福・EX祝福の入力データを正規化する。
 *
 * @param mixed $input 入力データ。
 * @return array{template: string, attrs: array<int, string>, species: array<int, string>, rarity: mixed, rows: array<int, array{point: string, text: string}>}
 */
function koto_normalize_blessing_input($input)
{
    $normalized = [
        'template' => '',
        'attrs'    => [],
        'species'  => [],
        'rarity'   => null,
        'rows'     => [],
    ];

    if (is_string($input)) {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return $normalized;
        }
        if ((strpos($trimmed, '{') === 0 || strpos($trimmed, '[') === 0)) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $input = $decoded;
            }
        }
    }

    if (is_array($input)) {
        if (array_key_exists('template', $input) || array_key_exists('rows', $input) || array_key_exists('attrs', $input) || array_key_exists('attr', $input) || array_key_exists('species', $input) || array_key_exists('rarity', $input)) {
            $normalized['template'] = trim((string) ($input['template'] ?? ''));
            $raw_attrs = $input['attrs'] ?? ($input['attr'] ?? []);
            $raw_species = $input['species'] ?? [];
            $normalized['attrs'] = koto_normalize_blessing_param_array($raw_attrs);
            $normalized['species'] = koto_normalize_blessing_param_array($raw_species);
            $normalized['rarity'] = $input['rarity'] ?? null;

            if (!empty($input['rows']) && is_array($input['rows'])) {
                foreach ($input['rows'] as $r) {
                    if (is_array($r)) {
                        $pt = trim((string) ($r['point'] ?? ($r['need_point'] ?? ($r['pt'] ?? ''))));
                        $txt = trim((string) ($r['text'] ?? ''));
                        if ($txt !== '') {
                            $normalized['rows'][] = ['point' => $pt, 'text' => $txt];
                        }
                    } elseif (is_string($r) && trim($r) !== '') {
                        $sub = koto_normalize_blessing_input($r);
                        $normalized['rows'] = array_merge($normalized['rows'], $sub['rows']);
                    }
                }
            }
            return $normalized;
        }

        foreach ($input as $r) {
            if (is_array($r)) {
                $pt = trim((string) ($r['point'] ?? ($r['need_point'] ?? ($r['pt'] ?? ''))));
                $txt = trim((string) ($r['text'] ?? ''));
                if ($txt !== '') {
                    $normalized['rows'][] = ['point' => $pt, 'text' => $txt];
                }
            }
        }
        return $normalized;
    }

    if (is_string($input)) {
        $lines = preg_split('/[\r\n｜|]+/u', $input, -1, PREG_SPLIT_NO_EMPTY);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^@template\s*[:：]\s*([a-zA-Z0-9_\-]+)(?:\((.*?)\))?$/u', $line, $m)) {
                $normalized['template'] = trim($m[1]);
                if (!empty($m[2])) {
                    $param_pairs = preg_split('/[,、;；]+/u', $m[2], -1, PREG_SPLIT_NO_EMPTY);
                    foreach ((array) $param_pairs as $pair) {
                        if (preg_match('/^(attrs?|species|rarity)\s*=\s*(.+)$/u', trim($pair), $pm)) {
                            $pkey = strtolower($pm[1]);
                            $raw_val = trim($pm[2]);
                            if ($pkey === 'attr' || $pkey === 'attrs') {
                                $normalized['attrs'] = array_merge($normalized['attrs'], koto_normalize_blessing_param_array($raw_val));
                            } elseif ($pkey === 'species') {
                                $normalized['species'] = array_merge($normalized['species'], koto_normalize_blessing_param_array($raw_val));
                            } elseif ($pkey === 'rarity') {
                                $normalized['rarity'] = $raw_val;
                            }
                        }
                    }
                }
                continue;
            }

            if (preg_match('/^(?:\[|【)?\s*(\d+)\s*(?:pt|PT|ポイント)?\s*(?:\]|】)?\s*[:：]?\s*(.+)$/u', $line, $m) && (strpos($line, 'pt') !== false || strpos($line, 'PT') !== false || strpos($line, 'ポイント') !== false || preg_match('/^(?:\[|【)?\s*\d+\s*(?:\]|】|:|：)/u', $line))) {
                $normalized['rows'][] = [
                    'point' => trim($m[1]),
                    'text'  => trim($m[2]),
                ];
            } else {
                $normalized['rows'][] = [
                    'point' => '',
                    'text'  => $line,
                ];
            }
        }
    }

    return $normalized;
}

/**
 * 「祝福」「EX祝福」入力データを解析し、タイプ1（テンプレート）→タイプ2（とくせい流用）の順でACF行配列を返す。
 *
 * @param mixed $input 構造化配列、JSON文字列、またはテキスト記法。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 'auto_input_blessing' または 'auto_input_ex_blessing'。
 * @param array<string, mixed> $context 実行コンテキスト（'rarity', 'post_id' 等）。
 * @return array<int, array<string, mixed>> 解析されたACF行配列。
 */
function koto_parse_blessing($input, $grouped_csv, $input_key = 'auto_input_blessing', array $context = [])
{
    $is_ex = ($input_key === 'auto_input_ex_blessing');
    $normalized = koto_normalize_blessing_input($input);

    $rarity = $normalized['rarity'] !== null ? $normalized['rarity'] : ($context['rarity'] ?? null);
    $post_id = isset($context['post_id']) ? (int) $context['post_id'] : 0;
    if ($rarity === null && $post_id > 0) {
        $rarity = wp_get_object_terms($post_id, 'rarity');
    }

    if (!isset($GLOBALS['koto_last_blessing_parse_meta']) || !is_array($GLOBALS['koto_last_blessing_parse_meta'])) {
        $GLOBALS['koto_last_blessing_parse_meta'] = [];
    }
    $meta = [
        'unknown_template' => null,
        'unparsed_rows'    => [],
        'type1_count'      => 0,
        'type2_count'      => 0,
        'warnings'         => [],
    ];

    $type1_rows = [];
    $type2_rows = [];

    // 1. タイプ1: テンプレート注入
    if ($normalized['template'] !== '') {
        $tpl_name = $normalized['template'];
        $tpl_csv_rows = $grouped_csv['祝福テンプレート'] ?? [];
        $matched_tpl_def = null;
        foreach ($tpl_csv_rows as $csv_row) {
            if (trim((string) ($csv_row['文言'] ?? '')) === $tpl_name) {
                $matched_tpl_def = (string) ($csv_row['ACFに入力するJSON'] ?? '');
                break;
            }
        }

        if ($matched_tpl_def !== null) {
            $tpl_warnings = [];
            $type1_rows = koto_expand_blessing_template_to_acf(
                $matched_tpl_def,
                $normalized['attrs'],
                $normalized['species'],
                $rarity,
                $tpl_warnings
            );
            $meta['type1_count'] = count($type1_rows);
            if (!empty($tpl_warnings)) {
                $meta['warnings'] = array_merge($meta['warnings'], $tpl_warnings);
            }
        } else {
            $meta['unknown_template'] = $tpl_name;
            $meta['warnings'][] = sprintf('未登録の祝福テンプレートです: %s', $tpl_name);
        }
    }

    // 2. タイプ2: とくせい自動入力の流用＋祝福ポイント設定
    foreach ($normalized['rows'] as $row_item) {
        $point = $row_item['point'] ?? '';
        $raw_text = trim((string) ($row_item['text'] ?? ''));
        if ($raw_text === '') {
            continue;
        }

        $preprocessed = koto_preprocess_text($raw_text, 'とくせい');
        $parsed_traits = koto_parse_trait($preprocessed, $grouped_csv, $input_key);
        if (empty($parsed_traits)) {
            $meta['unparsed_rows'][] = $raw_text;
            continue;
        }

        foreach ($parsed_traits as $t_row) {
            $converted = koto_convert_trait_row_to_blessing_rows($t_row, $point, $is_ex, $grouped_csv);
            foreach ($converted as $c_row) {
                $type2_rows[] = $c_row;
            }
        }
    }
    $meta['type2_count'] = count($type2_rows);
    $GLOBALS['koto_last_blessing_parse_meta'][$input_key] = $meta;

    // タイプ1の後ろにタイプ2を配置して返却
    return array_merge($type1_rows, $type2_rows);
}

/**
 * 直近の祝福・EX祝福パースで発生した警告（属性・種族不足等）を取得する。
 *
 * @return array<int, string> 警告メッセージ一覧。
 */
function koto_get_blessing_auto_input_warnings()
{
    $warnings = [];
    if (!empty($GLOBALS['koto_last_blessing_parse_meta']) && is_array($GLOBALS['koto_last_blessing_parse_meta'])) {
        foreach ($GLOBALS['koto_last_blessing_parse_meta'] as $key => $meta) {
            $prefix_label = ($key === 'auto_input_ex_blessing') ? 'EX祝福' : '祝福';
            if (!empty($meta['warnings']) && is_array($meta['warnings'])) {
                foreach ($meta['warnings'] as $w) {
                    $warnings[] = sprintf('%s: %s', $prefix_label, $w);
                }
            }
        }
    }
    return $warnings;
}
