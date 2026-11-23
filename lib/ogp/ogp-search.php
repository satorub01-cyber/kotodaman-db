<?php
/**
 * コトダマンDB OGPモジュール - 検索フィルタ & レアリティ優先ソート
 *
 * URLクエリパラメータに基づくキャラクター絞り込みと、レアリティ優先ソートを提供します。
 */

if (!defined('ABSPATH')) exit;

// =================================================================
// 2. レアリティ優先ソート & PHP版検索条件フィルタリング
// =================================================================

/**
 * ユーザーの指定に基づくレアリティ優先グループを返す。
 * レジェンド・グランド(3) ＞ ドリーム・ミラクル(2) ＞ スペシャル(1) ＞ その他(0)
 */
function koto_ogp_get_rarity_priority_group($char)
{
    $rar_d = strtolower(trim((string)($char['rar_d'] ?? '')));
    if ($rar_d === 'legend' || $rar_d === 'grand') {
        return 3;
    }
    if ($rar_d === 'dream' || $rar_d === 'miracle') {
        return 2;
    }
    if ($rar_d === 'special') {
        return 1;
    }
    return 0;
}

/**
 * 検索結果キャラクター配列をサムネイル表示用にソートする。
 * 優先順位: レアリティグループ降順 ＞ ★数値(rar)降順 ＞ 同一レアリティ内ランダム
 */
function koto_ogp_sort_characters_for_thumbnail(array $characters)
{
    $indexed = [];
    foreach (array_values($characters) as $idx => $char) {
        if (!is_array($char)) {
            continue;
        }
        $indexed[] = [
            'idx'   => $idx,
            'rand'  => random_int(0, PHP_INT_MAX),
            'group' => koto_ogp_get_rarity_priority_group($char),
            'rar'   => (int)($char['rar'] ?? 0),
            'char'  => $char,
        ];
    }

    usort($indexed, function ($a, $b) {
        if ($a['group'] !== $b['group']) {
            return $b['group'] <=> $a['group'];
        }
        if ($a['rar'] !== $b['rar']) {
            return $b['rar'] <=> $a['rar'];
        }
        if ($a['rand'] !== $b['rand']) {
            return $a['rand'] <=> $b['rand'];
        }
        return $a['idx'] <=> $b['idx'];
    });

    return array_column($indexed, 'char');
}

/**
 * 再帰的にURLデコードを行う。
 */
function koto_ogp_fully_decode($str)
{
    $prev = is_scalar($str) ? (string)$str : '';
    for ($i = 0; $i < 5; $i++) {
        $decoded = rawurldecode($prev);
        if ($decoded === $prev) {
            break;
        }
        $prev = $decoded;
    }
    return $prev;
}

/**
 * search-engine.js と同等の検索用文字列正規化（NFKC近似＋小文字化）。
 */
function koto_ogp_normalize_raw($str)
{
    $val = is_scalar($str) ? (string)$str : '';
    if (function_exists('mb_convert_kana')) {
        $val = mb_convert_kana($val, 'KVas', 'UTF-8');
    }
    if (function_exists('mb_strtolower')) {
        $val = mb_strtolower($val, 'UTF-8');
    } else {
        $val = strtolower($val);
    }
    return trim($val);
}

/**
 * 通常の比較用正規化（単独の & / ＆ 以外は & を除去）。
 */
function koto_ogp_normalize_text($str)
{
    $val = koto_ogp_normalize_raw($str);
    if ($val === '&' || $val === '＆') {
        return $val;
    }
    return str_replace(['&', '＆'], '', $val);
}

/**
 * パラメータ値を単一文字列として安全に取得する（配列混入時の Array to string conversion 防止）。
 */
function koto_ogp_get_param_string($params, $key, $default = '')
{
    if (!is_array($params) || !isset($params[$key])) {
        return (string)$default;
    }
    $val = $params[$key];
    if (is_array($val)) {
        $val = reset($val);
    }
    return is_scalar($val) ? trim((string)$val) : (string)$default;
}

/**
 * パラメータ値を配列として安全に取得する。
 */
function koto_ogp_get_param_array($params, $key)
{
    if (!is_array($params) || !isset($params[$key])) {
        return [];
    }
    $val = $params[$key];
    $arr = is_array($val) ? $val : [$val];
    $out = [];
    foreach ($arr as $item) {
        if (is_scalar($item)) {
            $s = trim((string)$item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
    }
    return $out;
}

/**
 * 正規化済み集合を作成して一致判定する。
 */
function koto_ogp_build_norm_lookup(array $values)
{
    $norm_map = [];
    $raw_map = [];
    foreach ($values as $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $s = (string)$v;
        $raw_map[strtolower(trim($s))] = true;
        $norm_map[koto_ogp_normalize_text(koto_ogp_fully_decode($s))] = true;
    }
    return ['norm' => $norm_map, 'raw' => $raw_map];
}

/**
 * AND / OR 関係でトークン配列とターゲット配列を比較する。
 */
function koto_ogp_match_relation(array $tokens, array $target_values, $relation = 'OR')
{
    if (empty($tokens)) {
        return true;
    }
    if (empty($target_values)) {
        return false;
    }
    $lookup = koto_ogp_build_norm_lookup($target_values);
    $is_and = (strtoupper((string)$relation) === 'AND');

    foreach ($tokens as $tok) {
        $raw = strtolower(trim((string)$tok));
        $norm = koto_ogp_normalize_text(koto_ogp_fully_decode($tok));
        $hit = isset($lookup['norm'][$norm]) || isset($lookup['raw'][$raw]);
        if ($is_and && !$hit) {
            return false;
        }
        if (!$is_and && $hit) {
            return true;
        }
    }
    return $is_and;
}

/**
 * 属性数値 => スラッグマップ、種族数値 => スラッグマップを取得する。
 */
function koto_ogp_get_num_to_slug_maps()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $attr_map = function_exists('koto_get_attr_num')
        ? array_flip(koto_get_attr_num())
        : [1 => 'fire', 2 => 'water', 3 => 'wood', 4 => 'light', 5 => 'dark', 6 => 'void', 7 => 'heaven', 8 => 'rainbow'];
    $species_map = function_exists('koto_get_species_num')
        ? array_flip(koto_get_species_num())
        : [1 => 'god', 2 => 'demon', 3 => 'hero', 4 => 'dragon', 5 => 'beast', 6 => 'spirit', 7 => 'artifact', 8 => 'yokai'];
    $cache = ['attr' => $attr_map, 'species' => $species_map];
    return $cache;
}

/**
 * リーダー効果の対象照合（PHP版）。
 */
function koto_ogp_match_leader_target(array $tgts, array $target_cond)
{
    if (empty($tgts)) {
        return false;
    }
    if (!empty($target_cond['isAll'])) {
        foreach ($tgts as $t) {
            if (($t['ty'] ?? '') === 'all') {
                return true;
            }
        }
        return false;
    }
    foreach ($tgts as $t) {
        if (($t['ty'] ?? '') !== ($target_cond['type'] ?? '')) {
            continue;
        }
        $slgs = isset($t['slgs']) && is_array($t['slgs']) ? $t['slgs'] : [];
        if (empty($slgs)) {
            continue;
        }
        foreach ($target_cond['slugs'] as $slug) {
            if (($target_cond['type'] ?? '') === 'group') {
                if (koto_ogp_match_relation([(string)$slug], $slgs, 'OR')) {
                    return true;
                }
            } else {
                if (in_array((int)$slug, array_map('intval', $slgs), true) || in_array((string)$slug, array_map('strval', $slgs), true)) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * リーダー効果群の照合（PHP版）。
 */
function koto_ogp_match_leader_character(array $char, array $params)
{
    $leaders = isset($char['leader']) && is_array($char['leader']) ? $char['leader'] : [];

    // クエリからリーダー条件を解析
    $raw_target_type = koto_ogp_get_param_string($params, 'leader_target_type', '');
    $target_rel = strtoupper(koto_ogp_get_param_string($params, 'leader_target_relation', 'OR')) === 'AND' ? 'AND' : 'OR';
    $attr_slug_to_id = function_exists('koto_get_attr_num') ? koto_get_attr_num() : ['fire' => 1, 'water' => 2, 'wood' => 3, 'light' => 4, 'dark' => 5, 'void' => 6, 'heaven' => 7, 'rainbow' => 8];
    $spe_slug_to_id = function_exists('koto_get_species_num') ? koto_get_species_num() : ['god' => 1, 'demon' => 2, 'hero' => 3, 'dragon' => 4, 'beast' => 5, 'spirit' => 6, 'artifact' => 7, 'yokai' => 8];

    $target = ['active' => false, 'type' => 'attr', 'slugs' => [], 'relation' => $target_rel, 'isAll' => false];
    if ($raw_target_type === 'all') {
        $target = ['active' => true, 'type' => 'attr', 'slugs' => [1, 2, 3, 4, 5, 6, 7, 8], 'relation' => 'AND', 'isAll' => true];
    } elseif ($raw_target_type === 'attribute' || $raw_target_type === '') {
        $attrs = koto_ogp_get_param_array($params, 'leader_target_attr');
        $slugs = array_values(array_filter(array_map(fn($s) => $attr_slug_to_id[$s] ?? 0, $attrs)));
        $target = ['active' => !empty($slugs), 'type' => 'attr', 'slugs' => $slugs, 'relation' => $target_rel, 'isAll' => false];
    } elseif ($raw_target_type === 'species') {
        $spes = koto_ogp_get_param_array($params, 'leader_target_species');
        $slugs = array_values(array_filter(array_map(fn($s) => $spe_slug_to_id[$s] ?? 0, $spes)));
        $target = ['active' => !empty($slugs), 'type' => 'species', 'slugs' => $slugs, 'relation' => $target_rel, 'isAll' => false];
    } elseif ($raw_target_type === 'affiliation') {
        $grps = koto_ogp_get_param_array($params, 'leader_target_group');
        $target = ['active' => !empty($grps), 'type' => 'group', 'slugs' => $grps, 'relation' => $target_rel, 'isAll' => false];
    }

    $hp_raw = koto_ogp_get_param_string($params, 'leader_hp_val', '');
    $atk_raw = koto_ogp_get_param_string($params, 'leader_atk_val', '');
    $hp = ($hp_raw !== '' && is_numeric($hp_raw) && (float)$hp_raw > 0) ? (float)$hp_raw : null;
    $atk = ($atk_raw !== '' && is_numeric($atk_raw) && (float)$atk_raw > 0) ? (float)$atk_raw : null;

    $status_types = koto_ogp_get_param_array($params, 'leader_status_type');
    $status_vals = isset($params['leader_status_val']) ? array_values((array)$params['leader_status_val']) : [];
    $statuses = [];
    foreach ($status_types as $i => $st) {
        if ($st !== '') {
            $statuses[] = ['status' => $st, 'minVal' => isset($status_vals[$i]) && is_scalar($status_vals[$i]) && is_numeric($status_vals[$i]) ? (float)$status_vals[$i] : 0];
        }
    }

    $other_types = koto_ogp_get_param_array($params, 'leader_other_type');
    $other_vals = isset($params['leader_other_val']) ? array_values((array)$params['leader_other_val']) : [];
    $others = [];
    foreach ($other_types as $i => $ot) {
        if ($ot !== '') {
            $others[] = ['type' => $ot, 'minVal' => isset($other_vals[$i]) && is_scalar($other_vals[$i]) && is_numeric($other_vals[$i]) ? (float)$other_vals[$i] : 0];
        }
    }

    $cond_indices = [];
    foreach ($params as $k => $v) {
        if (preg_match('/^leader_cond_(\d+)_type$/', (string)$k, $m) && koto_ogp_get_param_string($params, (string)$k, '') !== '') {
            $cond_indices[] = (int)$m[1];
        }
    }
    sort($cond_indices);

    $is_active = $target['active'] || $hp !== null || $atk !== null || !empty($statuses) || !empty($others) || !empty($cond_indices);
    if (!$is_active) {
        return true;
    }
    if (empty($leaders)) {
        return false;
    }

    $check_with_target = function ($predicate, $allow_all_fallback = false, $ignore_target = false) use ($leaders, $target) {
        if (!$target['active'] || $ignore_target) {
            foreach ($leaders as $ldr) {
                $effs = isset($ldr['effs']) && is_array($ldr['effs']) ? $ldr['effs'] : [];
                if (empty($effs)) {
                    if ($predicate($ldr, null)) return true;
                } else {
                    foreach ($effs as $eff) {
                        if ($predicate($ldr, $eff)) return true;
                    }
                }
            }
            return false;
        }
        $match_slug = function ($slug) use ($leaders, $target, $predicate, $allow_all_fallback) {
            $single_target = ['type' => $target['type'], 'slugs' => [$slug], 'isAll' => $target['isAll']];
            foreach ($leaders as $ldr) {
                $effs = isset($ldr['effs']) && is_array($ldr['effs']) ? $ldr['effs'] : [];
                foreach ($effs as $eff) {
                    if (!$predicate($ldr, $eff)) continue;
                    $tgts = isset($eff['tgts']) && is_array($eff['tgts']) ? $eff['tgts'] : [];
                    if (($allow_all_fallback || $target['isAll'])) {
                        foreach ($tgts as $t) {
                            if (($t['ty'] ?? '') === 'all') return true;
                        }
                    }
                    if (koto_ogp_match_leader_target($tgts, $single_target)) return true;
                }
            }
            return false;
        };
        if ($target['relation'] === 'AND') {
            foreach ($target['slugs'] as $slug) {
                if (!$match_slug($slug)) return false;
            }
            return true;
        }
        foreach ($target['slugs'] as $slug) {
            if ($match_slug($slug)) return true;
        }
        return false;
    };

    if ($hp !== null) {
        $ok = $check_with_target(fn($ldr, $eff) => in_array($ldr['ty'] ?? '', ['fixed', 'per_unit'], true) && (float)($eff['vals']['hp'] ?? 0) >= $hp);
        if (!$ok) return false;
    }
    if ($atk !== null) {
        $ok = $check_with_target(fn($ldr, $eff) => in_array($ldr['ty'] ?? '', ['fixed', 'per_unit'], true) && (float)($eff['vals']['atk'] ?? 0) >= $atk);
        if (!$ok) return false;
    }

    $leader_status_keys = ['poison', 'sleep', 'curse', 'confusion', 'pollution', 'burn', 'remodel', 'weakness', 'mutation', 'erasure'];
    foreach ($statuses as $st) {
        $ok = $check_with_target(function ($ldr, $eff) use ($st, $leader_status_keys) {
            if (!in_array($ldr['ty'] ?? '', ['fixed', 'per_unit'], true)) return false;
            $vals = $eff['vals'] ?? [];
            if ($st['status'] === 'all') {
                if (isset($vals['all']) && $vals['all'] !== '') {
                    $v = (float)$vals['all'];
                } else {
                    $all_vals = array_map(fn($k) => (float)($vals[$k] ?? 0), $leader_status_keys);
                    $v = min($all_vals) > 0 ? min($all_vals) : 0;
                }
            } else {
                $direct = (float)($vals[$st['status']] ?? 0);
                $v = $direct > 0 ? $direct : (float)($vals['all'] ?? 0);
            }
            return $st['minVal'] > 0 ? $v >= $st['minVal'] : $v > 0;
        }, true, false);
        if (!$ok) return false;
    }

    $buff_map = [
        'hp'               => ['ty' => 'fixed', 'stat' => 'hp', 'matchBy' => 'vals_key', 'ignoreTarget' => false],
        'atk'              => ['ty' => 'fixed', 'stat' => 'atk', 'matchBy' => 'vals_key', 'ignoreTarget' => false],
        'status_resistance'=> ['ty' => 'fixed', 'stat' => null, 'matchBy' => 'vals_key', 'ignoreTarget' => false],
        'crit_rate'        => ['ty' => 'fixed', 'stat' => 'crit_rate', 'matchBy' => 'vals_key', 'ignoreTarget' => false],
        'crit_damage'      => ['ty' => 'fixed', 'stat' => 'crit_damage', 'matchBy' => 'vals_key', 'ignoreTarget' => false],
        'damage_reduction' => ['ty' => 'fixed', 'stat' => 'mitigation', 'matchBy' => 'vals_key', 'ignoreTarget' => false],
        'exp_up'           => ['ty' => 'exp_up', 'stat' => 'exp', 'matchBy' => 'ty', 'ignoreTarget' => true],
        'over_healing'     => ['ty' => 'over_healing', 'stat' => 'over_heal', 'matchBy' => 'ty', 'ignoreTarget' => false],
        'converged'        => ['ty' => 'converged', 'stat' => 'converged', 'matchBy' => 'ty', 'ignoreTarget' => false],
        'corruption'       => ['ty' => 'corruption', 'stat' => 'corruption_rate', 'matchBy' => 'ty_or_vals', 'ignoreTarget' => false],
        'over_attack'      => ['ty' => 'over_attack', 'stat' => 'over_atk', 'matchBy' => 'ty', 'ignoreTarget' => false],
        'random_crit'      => ['ty' => 'random_crit', 'stat' => 'rand_crit', 'matchBy' => 'ty', 'ignoreTarget' => false],
        'damage_cut'       => ['ty' => 'damage_cut', 'stat' => 'corruption_rate', 'matchBy' => 'ty', 'ignoreTarget' => false],
        'combo_buff'       => ['ty' => 'combo_buff', 'stat' => null, 'matchBy' => 'ty', 'ignoreTarget' => false],
        'reflection'       => ['ty' => 'reflection', 'stat' => null, 'matchBy' => 'ty', 'ignoreTarget' => false],
    ];

    foreach ($others as $oth) {
        $map = $buff_map[$oth['type']] ?? null;
        if (!$map) return false;
        $min_val = $oth['minVal'];
        $ok = $check_with_target(function ($ldr, $eff) use ($map, $min_val) {
            $vals = $eff['vals'] ?? [];
            if ($map['matchBy'] === 'vals_key') {
                if (!in_array($ldr['ty'] ?? '', ['fixed', 'per_unit'], true)) return false;
                $v = $map['stat'] === 'crit_damage'
                    ? (float)($vals['crit_damage'] ?? $vals['critical_damage'] ?? 0)
                    : (float)($map['stat'] ? ($vals[$map['stat']] ?? 0) : 0);
                return $min_val > 0 ? $v >= $min_val : $v > 0;
            }
            if ($map['matchBy'] === 'ty_or_vals') {
                $v = (float)($vals[$map['stat']] ?? $ldr['oth_val'] ?? 0);
                if (($ldr['ty'] ?? '') === $map['ty']) return $min_val > 0 ? $v >= $min_val : true;
                $val_num = (float)($vals[$map['stat']] ?? 0);
                return $val_num > 0 && ($min_val > 0 ? $val_num >= $min_val : true);
            }
            if (($ldr['ty'] ?? '') !== $map['ty']) return false;
            if ($min_val > 0) {
                $v = (float)(($map['stat'] && isset($vals[$map['stat']])) ? $vals[$map['stat']] : ($ldr['oth_val'] ?? 0));
                return $v >= $min_val;
            }
            return true;
        }, false, (bool)$map['ignoreTarget']);
        if (!$ok) return false;
    }

    if ($hp === null && $atk === null && empty($statuses) && empty($others) && $target['active']) {
        if (!$check_with_target(fn($ldr, $eff) => !empty($eff))) {
            return false;
        }
    }

    foreach ($cond_indices as $idx) {
        $prefix = "leader_cond_{$idx}_";
        $c_type = koto_ogp_get_param_string($params, $prefix . 'type', '');
        $c_target_raw = koto_ogp_get_param_string($params, $prefix . 'target', '');
        $c_num_raw = koto_ogp_get_param_string($params, $prefix . 'val', '');
        $c_num = ($c_num_raw !== '' && is_numeric($c_num_raw) && (float)$c_num_raw > 0) ? (float)$c_num_raw : null;
        $c_text = koto_ogp_get_param_string($params, $prefix . 'text', '');

        $c_target_type = '';
        $c_target_slugs = [];
        if ($c_type === 'chara_num' || $c_type === 'cooperate') {
            if ($c_target_raw === 'attribute') {
                $c_target_type = 'attr';
                $c_target_slugs = array_values(array_filter(array_map(fn($s) => $attr_slug_to_id[$s] ?? 0, koto_ogp_get_param_array($params, $prefix . 'attr'))));
            } elseif ($c_target_raw === 'species') {
                $c_target_type = 'species';
                $c_target_slugs = array_values(array_filter(array_map(fn($s) => $spe_slug_to_id[$s] ?? 0, koto_ogp_get_param_array($params, $prefix . 'species'))));
            } elseif ($c_target_raw === 'affiliation') {
                $c_target_type = 'group';
                $c_target_slugs = koto_ogp_get_param_array($params, $prefix . 'group');
            }
        }

        $matched_cond = false;
        foreach ($leaders as $ldr) {
            $cond_groups = isset($ldr['cond']) && is_array($ldr['cond']) ? $ldr['cond'] : [];
            foreach ($cond_groups as $grp) {
                $cond_list = is_array($grp) && isset($grp[0]) ? $grp : [$grp];
                foreach ($cond_list as $c) {
                    if (!is_array($c) || ($c['ty'] ?? '') !== $c_type) continue;
                    $tgts = isset($c['tgts']) && is_array($c['tgts']) ? $c['tgts'] : [];
                    if (!empty($c_target_slugs)) {
                        $m_tgts = [];
                        foreach ($tgts as $ct) {
                            if (($ct['ty'] ?? '') !== $c_target_type || empty($ct['slgs'])) continue;
                            foreach ($c_target_slugs as $s) {
                                $hit = ($c_target_type === 'group')
                                    ? koto_ogp_match_relation([(string)$s], $ct['slgs'], 'OR')
                                    : in_array((int)$s, array_map('intval', $ct['slgs']), true);
                                if ($hit) {
                                    $m_tgts[] = $ct;
                                    break;
                                }
                            }
                        }
                        if (empty($m_tgts)) continue;
                        if ($c_num !== null && $c_type === 'chara_num') {
                            $num_ok = false;
                            foreach ($m_tgts as $ct) {
                                if ((float)($ct['num'] ?? 0) > 0 && (float)$ct['num'] <= $c_num) {
                                    $num_ok = true;
                                    break;
                                }
                            }
                            if (!$num_ok) continue;
                        }
                        $matched_cond = true;
                        break 3;
                    }
                    if ($c_num !== null) {
                        if ($c_type === 'chara_num') {
                            foreach ($tgts as $ct) {
                                if ((float)($ct['num'] ?? 0) > 0 && (float)$ct['num'] <= $c_num) {
                                    $matched_cond = true;
                                    break 4;
                                }
                            }
                            continue;
                        }
                        $vals = isset($c['vals']) && is_array($c['vals']) ? $c['vals'] : [];
                        foreach ($vals as $v) {
                            if ((float)$v > 0 && (float)$v <= $c_num) {
                                $matched_cond = true;
                                break 4;
                            }
                        }
                        if ($c_type === 'wave_count' && (float)($ldr['lm_wave'] ?? 0) > 0 && (float)$ldr['lm_wave'] <= $c_num) {
                            $matched_cond = true;
                            break 3;
                        }
                        continue;
                    }
                    if ($c_text !== '') {
                        $norm_text = koto_ogp_normalize_text($c_text);
                        $vals = isset($c['vals']) && is_array($c['vals']) ? $c['vals'] : [];
                        foreach ($vals as $v) {
                            if (mb_strpos(koto_ogp_normalize_text((string)$v), $norm_text, 0, 'UTF-8') !== false) {
                                $matched_cond = true;
                                break 4;
                            }
                        }
                        continue;
                    }
                    $matched_cond = true;
                    break 3;
                }
            }
        }
        if (!$matched_cond) return false;
    }

    return true;
}

/**
 * URLクエリパラメータに基づいてキャラクター配列をフィルタリングする。
 */
function koto_ogp_filter_characters(array $characters, array $params)
{
    if (empty($characters)) {
        return [];
    }

    $maps = koto_ogp_get_num_to_slug_maps();
    $event_map = function_exists('koto_get_event_map') ? koto_get_event_map() : [];

    // フリーワード解析
    $raw_kw = koto_ogp_get_param_string($params, 's', '');
    $kw_norm = koto_ogp_normalize_text($raw_kw);
    $kw_terms = $kw_norm !== '' ? preg_split('/\s+/u', $kw_norm, -1, PREG_SPLIT_NO_EMPTY) : [];
    $include_trait_resist = !empty($params['include_trait_status_resistance']);

    // 使用可能文字解析
    $raw_char_str = koto_ogp_normalize_text(koto_ogp_get_param_string($params, 'search_char', ''));
    $target_chars = [];
    if ($raw_char_str !== '') {
        $chars_split = preg_split('//u', $raw_char_str, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars_split as $ch) {
            if (trim($ch) !== '' && $ch !== ',' && $ch !== '、') {
                $target_chars[$ch] = true;
            }
        }
    }
    $target_char_tokens = array_keys($target_chars);
    $rel_char = koto_ogp_get_param_string($params, 'search_char_relation', 'AND');

    // CV
    $search_cv = koto_ogp_normalize_text(koto_ogp_get_param_string($params, 'tx_cv', ''));

    // タクソノミー・配列トークン
    $attr_tokens    = koto_ogp_get_param_array($params, 'tx_attr');
    $rel_attr       = koto_ogp_get_param_string($params, 'tx_attr_relation', 'OR');
    $sub_attr_tog   = koto_ogp_get_param_string($params, 'tx_attr_sub', 'OR');
    $species_tokens = koto_ogp_get_param_array($params, 'tx_species');
    $rel_species    = koto_ogp_get_param_string($params, 'tx_species_relation', 'OR');
    $rarity_tokens  = koto_ogp_get_param_array($params, 'tx_rarity');
    $rel_rarity     = koto_ogp_get_param_string($params, 'tx_rarity_relation', 'OR');
    $group_tokens   = koto_ogp_get_param_array($params, 'tx_group');
    $rel_group      = koto_ogp_get_param_string($params, 'tx_group_relation', 'OR');
    $event_tokens   = koto_ogp_get_param_array($params, 'tx_event');
    $rel_event      = koto_ogp_get_param_string($params, 'tx_event_relation', 'OR');
    $quest_tokens   = koto_ogp_get_param_array($params, 'tx_quest');
    $rel_quest      = koto_ogp_get_param_string($params, 'tx_quest_relation', 'OR');
    $gimmick_tokens = koto_ogp_get_param_array($params, 'tx_gimmick');
    $rel_gimmick    = koto_ogp_get_param_string($params, 'tx_gimmick_relation', 'OR');
    $acq_tokens     = koto_ogp_get_param_array($params, 'tx_acq');
    $rel_acq        = koto_ogp_get_param_string($params, 'tx_acq_relation', 'OR');
    $axis_tokens    = koto_ogp_get_param_array($params, 'tx_axis');
    $rel_axis       = koto_ogp_get_param_string($params, 'tx_axis_relation', 'OR');
    $priority_tokens = koto_ogp_get_param_array($params, 'tx_priority');

    // わざ・すごわざ・コトワザタグ
    $skill_tags   = koto_ogp_get_param_array($params, 'tx_skill_tags');
    $skill_scopes = koto_ogp_get_param_array($params, 'scope_skill');
    if (empty($skill_scopes)) {
        $skill_scopes = ['waza', 'sugo', 'kotowaza'];
    }
    $rel_skill = strtoupper(koto_ogp_get_param_string($params, 'tx_skill_tags_relation', 'OR'));

    // とくせい・祝福タグ
    $trait_tags   = koto_ogp_get_param_array($params, 'tx_trait_tags');
    $trait_scopes = koto_ogp_get_param_array($params, 'scope_trait');
    if (empty($trait_scopes)) {
        $trait_scopes = ['t1', 't2', 'blessing'];
    }
    $rel_trait = strtoupper(koto_ogp_get_param_string($params, 'tx_trait_tags_relation', 'OR'));
    $norm_trait_tags = array_map(function ($t) {
        $t = trim((string)$t);
        return $t === 'give_trait' ? $t : preg_replace('/^trait_/', '', $t);
    }, $trait_tags);

    $filtered = [];
    foreach ($characters as $char) {
        if (!is_array($char)) {
            continue;
        }

        // 1. 属性
        if (!empty($attr_tokens)) {
            $main_slug = $maps['attr'][(int)($char['attr'] ?? 0)] ?? '';
            $attr_vals = $main_slug !== '' ? [$main_slug] : [];
            if ($sub_attr_tog === 'OR' && !empty($char['sub_attrs']) && is_array($char['sub_attrs'])) {
                foreach ($char['sub_attrs'] as $sa) {
                    $sub_slug = $maps['attr'][(int)$sa] ?? '';
                    if ($sub_slug !== '') {
                        $attr_vals[] = $sub_slug;
                    }
                }
            }
            if (!koto_ogp_match_relation($attr_tokens, $attr_vals, $rel_attr)) {
                continue;
            }
        }

        // 2. フリーワード
        if (!empty($kw_terms)) {
            $all_ev = array_merge(
                is_array($char['events'] ?? null) ? $char['events'] : [],
                is_array($char['sub-event'] ?? null) ? $char['sub-event'] : (is_array($char['sub_event'] ?? null) ? $char['sub_event'] : []),
                is_array($char['gerilla_root'] ?? null) ? $char['gerilla_root'] : (is_array($char['guerilla_root'] ?? null) ? $char['guerilla_root'] : [])
            );
            $ev_names = [];
            foreach ($all_ev as $slug) {
                $dec = koto_ogp_fully_decode((string)$slug);
                $ev_names[] = $event_map[$slug] ?? $event_map[$dec] ?? $dec;
            }
            $base_targets = array_merge(
                [$char['name'] ?? '', $char['name_ruby'] ?? ''],
                $ev_names,
                is_array($char['group_jp'] ?? null) ? $char['group_jp'] : [],
                is_array($char['gimmick_jp'] ?? null) ? $char['gimmick_jp'] : [],
                is_array($char['trait1_jp'] ?? null) ? $char['trait1_jp'] : [],
                is_array($char['trait2_jp'] ?? null) ? $char['trait2_jp'] : [],
                is_array($char['blessing_jp'] ?? null) ? $char['blessing_jp'] : [],
                is_array($char['leader_status_resistance_jp'] ?? null) ? $char['leader_status_resistance_jp'] : []
            );
            $kw_base = implode("\n", array_map('koto_ogp_normalize_text', $base_targets));
            $kw_resist = '';
            if ($include_trait_resist && !empty($char['trait_status_resistance_jp']) && is_array($char['trait_status_resistance_jp'])) {
                $kw_resist = implode("\n", array_map('koto_ogp_normalize_text', $char['trait_status_resistance_jp']));
            }

            $kw_ok = true;
            foreach ($kw_terms as $term) {
                $hit = ($term !== '' && mb_strpos($kw_base, $term, 0, 'UTF-8') !== false);
                if (!$hit && $kw_resist !== '') {
                    $hit = (mb_strpos($kw_resist, $term, 0, 'UTF-8') !== false);
                }
                if (!$hit) {
                    $kw_ok = false;
                    break;
                }
            }
            if (!$kw_ok) {
                continue;
            }
        }

        // 3. CV
        if ($search_cv !== '') {
            $cv_norm = koto_ogp_normalize_text((string)($char['cv'] ?? ''));
            if ($cv_norm === '' || mb_strpos($cv_norm, $search_cv, 0, 'UTF-8') === false) {
                continue;
            }
        }

        // 4. 使用可能文字（search-engine.js仕様：ORでもANDでも一致する文字があれば対象、ANDなら全文字必須）
        if (!empty($target_char_tokens)) {
            $char_vals = [];
            if (!empty($char['chars']) && is_array($char['chars'])) {
                foreach ($char['chars'] as $c_item) {
                    if (!empty($c_item['val'])) {
                        $char_vals[] = (string)$c_item['val'];
                    }
                }
            }
            if (!koto_ogp_match_relation($target_char_tokens, $char_vals, $rel_char === 'AND' ? 'AND' : 'OR')) {
                continue;
            }
        }

        // 5. 種族・レア・所属・イベント・クエスト・ギミック・入手・軸・優先度
        $spe_slug = $maps['species'][(int)($char['spe'] ?? 0)] ?? '';
        if (!koto_ogp_match_relation($species_tokens, $spe_slug !== '' ? [$spe_slug] : [], $rel_species)) continue;
        if (!koto_ogp_match_relation($rarity_tokens, (array)($char['rar_t'] ?? []), $rel_rarity)) continue;
        if (!koto_ogp_match_relation($group_tokens, (array)($char['group_en'] ?? []), $rel_group)) continue;

        if (!empty($event_tokens)) {
            $all_ev = array_merge(
                is_array($char['events'] ?? null) ? $char['events'] : [],
                is_array($char['sub-event'] ?? null) ? $char['sub-event'] : (is_array($char['sub_event'] ?? null) ? $char['sub_event'] : []),
                is_array($char['gerilla_root'] ?? null) ? $char['gerilla_root'] : (is_array($char['guerilla_root'] ?? null) ? $char['guerilla_root'] : [])
            );
            if (!koto_ogp_match_relation($event_tokens, $all_ev, $rel_event)) continue;
        }

        if (!koto_ogp_match_relation($quest_tokens, (array)($char['quests'] ?? []), $rel_quest)) continue;
        if (!koto_ogp_match_relation($gimmick_tokens, (array)($char['gimmick_en'] ?? []), $rel_gimmick)) continue;
        if (!koto_ogp_match_relation($acq_tokens, !empty($char['acq']) ? (is_array($char['acq']) ? $char['acq'] : [$char['acq']]) : [], $rel_acq)) continue;
        if (!koto_ogp_match_relation($axis_tokens, (array)($char['axis'] ?? []), $rel_axis)) continue;

        if (!empty($priority_tokens)) {
            $pri_str = (string)($char['pri'] ?? '');
            if (!in_array($pri_str, $priority_tokens, true)) continue;
        }

        // 6. スキルタグ
        if (!empty($skill_tags)) {
            $s_waza = in_array('waza', $skill_scopes, true);
            $s_sugo = in_array('sugo', $skill_scopes, true);
            $s_koto = in_array('kotowaza', $skill_scopes, true);
            $has_match = ($rel_skill === 'AND');
            foreach ($skill_tags as $tag) {
                $needle = ' ' . $tag . ' ';
                $m = ($s_waza && !empty($char['waza_t']) && strpos((string)$char['waza_t'], $needle) !== false)
                    || ($s_sugo && !empty($char['sugo_t']) && strpos((string)$char['sugo_t'], $needle) !== false)
                    || ($s_koto && !empty($char['koto_t']) && strpos((string)$char['koto_t'], $needle) !== false);
                if ($rel_skill === 'AND' && !$m) {
                    $has_match = false;
                    break;
                }
                if ($rel_skill !== 'AND' && $m) {
                    $has_match = true;
                    break;
                }
            }
            if (!$has_match) continue;
        }

        // 7. とくせい・祝福タグ
        if (!empty($norm_trait_tags)) {
            $s_t1 = in_array('t1', $trait_scopes, true);
            $s_t2 = in_array('t2', $trait_scopes, true);
            $s_bl = in_array('blessing', $trait_scopes, true);
            $has_match = ($rel_trait === 'AND');
            foreach ($norm_trait_tags as $tag) {
                $m = ($s_t1 && koto_ogp_match_relation([$tag], (array)($char['trait1_en'] ?? []), 'OR'))
                    || ($s_t2 && koto_ogp_match_relation([$tag], (array)($char['trait2_en'] ?? []), 'OR'))
                    || ($s_bl && koto_ogp_match_relation([$tag], (array)($char['blessing_en'] ?? []), 'OR'));
                if ($rel_trait === 'AND' && !$m) {
                    $has_match = false;
                    break;
                }
                if ($rel_trait !== 'AND' && $m) {
                    $has_match = true;
                    break;
                }
            }
            if (!$has_match) continue;
        }

        // 8. リーダー検索
        if (!koto_ogp_match_leader_character($char, $params)) {
            continue;
        }

        $filtered[] = $char;
    }

    return $filtered;
}
