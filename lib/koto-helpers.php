<?php
/**
 * コトダマン共通表示・計算ヘルパー関数群
 *
 * テンプレートや検索、ACFエディタなどで広く使用される共通関数を定義します。
 */

if (!defined('ABSPATH')) exit;

/**
 * ターム（属性・種族など）に設定されたアイコン画像を取得する関数
 *
 * ACFでタームに紐付けられた画像IDを取得し、imgタグとして返します。画像がない場合はターム名を返します。
 *
 * @param object|WP_Term $term タームオブジェクト
 * @param string $class_name imgタグにつけるクラス名（デフォルト: 'term-icon'）
 * @return string imgタグ、またはターム名（未設定時は '未設定'）
 */
function get_term_icon_html($term, $class_name = 'term-icon')
{
    if (!$term || !is_object($term)) return '未設定';

    // ACFでタームに紐付いた画像IDを取得
    $icon_id = get_field('term_icon', $term);

    if ($icon_id) {
        // 画像があればIMGタグを返す
        return wp_get_attachment_image($icon_id, 'small', false, ['class' => $class_name, 'alt' => $term->name]);
    } else {
        // 画像がなければ文字（名前）を返す
        return $term->name;
    }
}

/**
 * 所属グループのリストから、表示すべき「メインの1つ」を返す関数
 *
 * 優先順位リスト（Slug）および親子関係に基づき、代表となる所属グループタームオブジェクトを決定します。
 *
 * @param array|object|WP_Term $terms タクソノミーオブジェクトの配列、または単一オブジェクト
 * @return object|WP_Term|false 決定されたタームオブジェクト、空の場合は false
 */
function get_primary_affiliation_obj($terms)
{
    if (empty($terms)) return false;
    if (!is_array($terms)) $terms = [$terms]; // 配列でなければ配列化

    // 1つだけならそれを返す
    if (count($terms) === 1) {
        return $terms[0];
    }

    // 複数ある場合の優先順位リスト (Slug)
    $priority_slugs = [
        'omni_melody',          // 全の戦律
        'wish_of_three_kingdoms',
        'journey_to_dream',
    ];

    $found_term = null;

    // 優先リストと照合
    foreach ($priority_slugs as $slug) {
        foreach ($terms as $term) {
            if ($term->slug === $slug) {
                $found_term = $term;
                break 2;
            }
        }
    }

    // 子要素優先
    if (!$found_term) {
        foreach ($terms as $term) {
            if ($term->parent != 0) {
                $found_term = $term;
                break;
            }
        }
    }

    // 決まらなければ最初のもの
    if (!$found_term) {
        $found_term = $terms[0];
    }

    return $found_term;
}

/**
 * タクソノミーのリストを受け取り、名前を連結して文字列で返す汎用関数
 *
 * @param array|object|WP_Error|string $terms get_the_terms() や get_field() の戻り値
 * @param string $separator 区切り文字（デフォルト: '・'）
 * @param string $default データがない時の表示（デフォルト: '未入力'）
 * @return string 整形された文字列
 */
function get_terms_string($terms, $separator = '・', $default = '未入力')
{
    // エラーチェックや空チェック
    if (empty($terms) || is_wp_error($terms)) {
        return $default;
    }

    // A. 配列の場合（複数選択）
    if (is_array($terms)) {
        $names = wp_list_pluck($terms, 'name');
        return implode($separator, $names);
    }

    // B. 単体オブジェクトの場合（単数選択）
    if (is_object($terms)) {
        return $terms->name;
    }

    // C. もとから文字列が来てた時
    return (string)$terms;
}

/**
 * 対象グループデータから対象ラベル文字列を生成する関数
 *
 * 自身、味方全体、属性、種族、所属グループ、指定文字などの指定に応じた表示文字列を組み立てます。
 *
 * @param array $group_data 対象条件のグループ配列
 * @return string 生成された対象ラベル文字列
 */
function get_koto_target_label($group_data)
{
    if (empty($group_data)) return '';

    // 1. タイプ自動検出
    $type = isset($group_data['target_type']) ? $group_data['target_type'] : '';
    if (!$type) {
        if (!empty($group_data['target_species'])) $type = 'species';
        elseif (!empty($group_data['target_attr'])) $type = 'attr';
        elseif (!empty($group_data['target_group'])) $type = 'group';
        elseif (!empty($group_data['target_moji'])) $type = 'moji';
        elseif (!empty($group_data['target_other'])) $type = 'other';
    }

    // 2. データから名前をすべて取り出してつなぐ便利関数
    $get_names = function ($data) {
        if (empty($data)) return '';
        if (is_object($data)) $data = [$data];

        $names = [];
        if (is_array($data)) {
            foreach ($data as $term) {
                if (is_object($term) && isset($term->name)) {
                    $names[] = $term->name;
                }
            }
        }
        return implode('・', $names);
    };

    // 3. ラベル生成
    switch ($type) {
        case 'self':
            return '自身';
        case 'all':
            return '味方全体';

        case 'attr':
            $text = $get_names($group_data['target_attr']);
            return $text ? $text . '属性' : '';

        case 'species':
            $text = $get_names($group_data['target_species']);
            return $text ? $text . '種族' : '';

        case 'group':
            $terms = $group_data['target_group'];
            if (empty($terms)) return '';
            if (is_object($terms)) $terms = [$terms];

            // melody特例処理
            foreach ($terms as $t) {
                if (isset($t->slug) && $t->slug === 'melody') {
                    return '「全の戦律」または「斬・砲・突・重・超・打の戦律」の味方';
                }
            }

            $wrapped_names = array_map(fn($t) => "「{$t->name}」", $terms);
            $text = implode('・', $wrapped_names);
            return $text ? $text . 'の味方' : '';

        case 'moji':
            $terms = $group_data['target_moji'];
            if (empty($terms)) return '';
            if (is_object($terms)) $terms = [$terms];

            $wrapped_names = array_map(fn($t) => "「{$t->name}」", $terms);
            $text = implode('・', $wrapped_names);
            return $text ? $text . 'の味方' : '';

        case 'other':
            return $group_data['target_other'];

        default:
            return '';
    }
}

/**
 * 指定したメタキーの統計情報（平均・標準偏差・件数）を取得する
 *
 * 'total_99_hp', 'total_99_atk' 指定時は基礎値＋超化を合算して計算します。Transientで1時間キャッシュされます。
 *
 * @param string $meta_key 集計対象のメタキー
 * @return array{avg: float, std_dev: float, count: int} 平均値、標準偏差、件数の連想配列
 */
function get_koto_stat_distribution($meta_key)
{
    $cache_key = 'koto_stat_dist_' . $meta_key;
    $stats = get_transient($cache_key);

    if ($stats !== false) {
        return $stats;
    }

    global $wpdb;
    $values = [];

    // 特殊対応: Lv99の「基礎 + 超化」合計値の集計
    if ($meta_key === 'total_99_hp' || $meta_key === 'total_99_atk') {
        $base_key   = ($meta_key === 'total_99_hp') ? 'lv_99_hp' : 'lv_99_atk';
        $chouka_key = ($meta_key === 'total_99_hp') ? 'hp_chouka' : 'atk_chouka';

        $sql = $wpdb->prepare("
            SELECT (CAST(m1.meta_value AS SIGNED) + COALESCE(CAST(m2.meta_value AS SIGNED), 0)) as total_val
            FROM {$wpdb->postmeta} m1
            LEFT JOIN {$wpdb->postmeta} m2 ON m1.post_id = m2.post_id AND m2.meta_key = %s
            JOIN {$wpdb->posts} p ON m1.post_id = p.ID
            WHERE p.post_type = 'character' 
            AND p.post_status = 'publish' 
            AND m1.meta_key = %s
            AND m1.meta_value > 0
        ", $chouka_key, $base_key);

        $values = $wpdb->get_col($sql);
    } else {
        $sql = $wpdb->prepare("
            SELECT meta_value 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = 'character' 
            AND p.post_status = 'publish' 
            AND pm.meta_key = %s
            AND pm.meta_value > 0
        ", $meta_key);
        $values = $wpdb->get_col($sql);
    }

    if (empty($values)) {
        return ['avg' => 0, 'std_dev' => 1, 'count' => 0];
    }

    // 統計計算
    $count = count($values);
    $sum = array_sum($values);
    $avg = $sum / $count;

    $variance_sum = 0;
    foreach ($values as $val) {
        $variance_sum += pow((float)$val - $avg, 2);
    }
    $std_dev = sqrt($variance_sum / $count);

    $stats = [
        'avg' => $avg,
        'std_dev' => $std_dev,
        'count' => $count
    ];

    set_transient($cache_key, $stats, 3600); // 1時間キャッシュ

    return $stats;
}

/**
 * 数値とメタキーを渡して「偏差値」を計算してフォーマットした文字列を返す関数
 *
 * @param mixed $value 対象の数値（空や非数値の場合は '-' を返却）
 * @param string $meta_key 比較対象のメタキー（デフォルト: '120_atk'）
 * @param int $precision 小数点以下の桁数（デフォルト: 1）
 * @return string フォーマットされた偏差値文字列、または '-'
 */
function get_koto_deviation_score($value, $meta_key = '120_atk', $precision = 1)
{
    if (empty($value) || !is_numeric($value)) return '-';

    $stats = get_koto_stat_distribution($meta_key);

    if ($stats['std_dev'] == 0) return '50.0'; // 全員同じ数値の場合

    // 偏差値 = ( (個人の値 - 平均) / 標準偏差 ) * 10 + 50
    $score = (($value - $stats['avg']) / $stats['std_dev']) * 10 + 50;

    return number_format($score, $precision);
}

/**
 * キャラクター名から肩書・二つ名を除去して短縮した名称を取得するヘルパー関数
 *
 * @param string $full_name キャラクターのフルネーム
 * @return string 短縮されたキャラクター名
 */
if (!function_exists('koto_get_short_character_name')) {
    function koto_get_short_character_name($full_name)
    {
        // JSの \u30A0-\u30FF を PHPの \x{30A0}-\x{30FF} に変換
        $pattern = '/^(?:(?![^・]*[\(（])(?!(?:[\x{30A0}-\x{30FF}]+)・)(?:[^・]+)・(.+)|(.+))$/u';
        if (preg_match($pattern, $full_name, $matches)) {
            return !empty($matches[1]) ? $matches[1] : (!empty($matches[2]) ? $matches[2] : $full_name);
        }
        return $full_name;
    }
}
