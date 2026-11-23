<?php
/**
 * コトダマンDB OGPモジュール - Cocoon OGP / Twitter Card フック
 *
 * summary_large_image 強制、動的og:image、検索ページのog:url/title/description補完を行います。
 */

if (!defined('ABSPATH')) exit;

/**
 * Cocoon 親テーマの Twitter カード種別を summary_large_image に統一する。
 */
if (!function_exists('get_twitter_card_type')) {
    function get_twitter_card_type()
    {
        return 'summary_large_image';
    }
}


// =================================================================
// 6. Cocoon OGP / Twitter Card 統合フック
// =================================================================

/**
 * CocoonのOGP/Twitterカードタグ出力期間中だけフラグを立て、og:url / twitter:url の検索パラメータ保持を制御する。
 */
add_action('wp_head', function () {
    $GLOBALS['koto_ogp_in_sns_meta'] = true;
}, 0);
add_action('wp_head', function () {
    $GLOBALS['koto_ogp_in_sns_meta'] = false;
}, 2);

add_filter('generate_canonical_url', function ($canonical_url) {
    if (empty($GLOBALS['koto_ogp_in_sns_meta']) || !koto_ogp_is_character_search_page()) {
        return $canonical_url;
    }
    $clean = koto_ogp_extract_allowed_search_query_params(wp_unslash($_GET));
    $clean['post_type'] = 'character';
    if (function_exists('koto_build_character_search_url')) {
        return koto_build_character_search_url($clean);
    }
    $query = http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    $query = preg_replace('/%5B\d+%5D(?==|&|$)/', '%5B%5D', $query);
    return home_url('/?' . $query);
}, 20);

/**
 * Cocoonの og:image / twitter:image を動的生成エンドポイントURLへ差し替える。
 */
add_filter('ogp_card_ogp_image', function ($ogp_image) {
    if (koto_ogp_is_character_search_page()) {
        $params = koto_ogp_extract_allowed_search_query_params(wp_unslash($_GET));
        $params['mode'] = 'search';
        return koto_ogp_build_signed_image_url($params);
    }
    if (is_front_page()) {
        $front_id = (int)get_option('page_on_front');
        $ver = $front_id > 0 ? get_post_modified_time('U', true, $front_id) : '';
        return koto_ogp_build_signed_image_url([
            'mode' => 'top',
            'v'    => (string)$ver,
        ]);
    }
    if (is_singular()) {
        $post_id = (int)get_queried_object_id();
        if ($post_id > 0 && koto_ogp_is_public_post($post_id)) {
            $thumb_id = (int)get_post_thumbnail_id($post_id);
            if ($thumb_id <= 0 && get_post_type($post_id) === 'character') {
                $thumb_id = (int)get_post_meta($post_id, 'character_image', true);
            }
            if ($thumb_id > 0) {
                return koto_ogp_build_signed_image_url([
                    'mode'    => 'post',
                    'post_id' => (string)$post_id,
                    'v'       => (string)$thumb_id . '_' . (string)get_post_modified_time('U', true, $post_id),
                ]);
            }
        }
    }
    return koto_ogp_build_signed_image_url(['mode' => 'default']);
}, 20);

/**
 * キャラクター検索条件の要約テキストを生成する（og:title / og:description 用）。
 */
function koto_ogp_build_search_summary_parts(array $params)
{
    $parts = [];
    $attr_names = ['fire' => '火', 'water' => '水', 'wood' => '木', 'light' => '光', 'dark' => '闇', 'void' => '冥', 'heaven' => '天', 'rainbow' => '虹'];
    $spe_names  = ['god' => '神', 'demon' => '魔', 'hero' => '英', 'dragon' => '龍', 'beast' => '獣', 'spirit' => '霊', 'artifact' => '物', 'yokai' => '妖'];
    $rar_names  = ['legend' => 'レジェンド', 'grand' => 'グランド', 'dream' => 'ドリーム', 'miracle' => 'ミラクル', 'special' => 'スペシャル', '6' => '★6', '5' => '★5'];

    $kw = koto_ogp_get_param_string($params, 's', '');
    if ($kw !== '') {
        $parts[] = '「' . sanitize_text_field($kw) . '」';
    }
    $search_char = koto_ogp_get_param_string($params, 'search_char', '');
    if ($search_char !== '') {
        $parts[] = '文字:' . sanitize_text_field($search_char);
    }
    $attrs = koto_ogp_get_param_array($params, 'tx_attr');
    if (!empty($attrs)) {
        $labels = array_map(fn($a) => ($attr_names[$a] ?? $a) . '属性', $attrs);
        $parts[] = implode('・', $labels);
    }
    $spes = koto_ogp_get_param_array($params, 'tx_species');
    if (!empty($spes)) {
        $labels = array_map(fn($s) => ($spe_names[$s] ?? $s) . '種族', $spes);
        $parts[] = implode('・', $labels);
    }
    $rars = koto_ogp_get_param_array($params, 'tx_rarity');
    if (!empty($rars)) {
        $labels = array_map(fn($r) => $rar_names[$r] ?? $r, $rars);
        $parts[] = implode('・', $labels);
    }
    $gimmicks = array_slice(koto_ogp_get_param_array($params, 'tx_gimmick'), 0, 15);
    if (!empty($gimmicks)) {
        static $gimmick_term_cache = [];
        $g_labels = [];
        foreach ($gimmicks as $slug) {
            if (!array_key_exists($slug, $gimmick_term_cache)) {
                $term = get_term_by('slug', $slug, 'gimmick');
                $gimmick_term_cache[$slug] = ($term && !is_wp_error($term)) ? $term->name : $slug;
            }
            $g_labels[] = $gimmick_term_cache[$slug];
        }
        $parts[] = implode('・', $g_labels);
    }
    return $parts;
}

add_filter('ogp_card_title', function ($title) {
    if (!koto_ogp_is_character_search_page()) {
        return $title;
    }
    $params = koto_ogp_extract_allowed_search_query_params(wp_unslash($_GET));
    $parts = koto_ogp_build_search_summary_parts($params);
    $site_name = get_bloginfo('name');
    if (!empty($parts)) {
        return implode(' / ', $parts) . ' のキャラ検索結果 | ' . $site_name;
    }
    return 'キャラクター検索一覧 | ' . $site_name;
}, 20);

add_filter('ogp_card_description', function ($description) {
    if (!koto_ogp_is_character_search_page()) {
        return $description;
    }
    $params = koto_ogp_extract_allowed_search_query_params(wp_unslash($_GET));
    $parts = koto_ogp_build_search_summary_parts($params);
    if (!empty($parts)) {
        return '検索条件（' . implode(' / ', $parts) . '）に一致するコトダマンキャラクター一覧です。';
    }
    return 'コトダマンDBのキャラクター詳細検索結果一覧です。';
}, 20);
