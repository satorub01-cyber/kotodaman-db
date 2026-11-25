<?php
/**
 * コトダマンDB OGPモジュール - 統一ローダー & 基本設定
 *
 * 定数定義、共通ヘルパー（キャッシュパス・保護記事判定・JSON読込）および各モジュールの読み込みを行います。
 */

if (!defined('ABSPATH')) exit;

define('KOTO_OGP_DEFAULT_WIDTH', 1200);
define('KOTO_OGP_DEFAULT_HEIGHT', 630);
define('KOTO_OGP_YT_WIDTH', 1280);
define('KOTO_OGP_YT_HEIGHT', 720);
define('KOTO_OGP_MAX_TILES', 6);
define('KOTO_OGP_CACHE_VERSION', 'v1');
define('KOTO_OGP_BG_RGB', [247, 237, 227]);      // #f7ede3
define('KOTO_OGP_ACCENT_RGB', [19, 94, 150]);    // #135e96
define('KOTO_OGP_LOGO_RELATIVE_PATH', '/2026/11/DB_logo.png');
define('KOTO_OGP_LOGO_RESIZED_PATH', '/2026/11/DB_logo-768x204.png');
define('KOTO_OGP_LOGO_REMOTE_URL', 'https://www.kotodaman-db.com/wp-content/uploads/2026/11/DB_logo.png');


/**
 * OGP用キャッシュディレクトリのパスを取得・生成する。
 */
function koto_ogp_get_cache_dir($subdir = '')
{
    $uploads = wp_upload_dir();
    $base = rtrim((string)($uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads'), '/') . '/koto-ogp-cache';
    $clean_sub = is_scalar($subdir) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$subdir) : '';
    $dir = $clean_sub !== '' ? $base . '/' . $clean_sub : $base;
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    foreach (array_unique([$base, $dir]) as $target_dir) {
        if (is_dir($target_dir)) {
            $index_file = $target_dir . '/index.php';
            if (!file_exists($index_file)) {
                @file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
            $htaccess_file = $target_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                @file_put_contents($htaccess_file, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|inc)$\">\n    Require all denied\n</FilesMatch>\n");
            }
        }
    }
    return $dir;
}

/**
 * 保護対象記事（雛型・雛形・テンプレートID）かを判定する。
 */
function koto_ogp_is_protected_post($post)
{
    $protected_ids = function_exists('koto_mcp_template_post_ids')
        ? array_map('intval', (array)koto_mcp_template_post_ids())
        : [2947, 2023, 2637, 2638, 3845, 4603, 5294, 3683];

    if (is_array($post)) {
        $id = (int)($post['id'] ?? $post['ID'] ?? 0);
        if ($id > 0 && in_array($id, $protected_ids, true)) {
            return true;
        }
        $title = (string)($post['name'] ?? $post['post_title'] ?? '');
        if ($title !== '' && (mb_strpos($title, '雛型', 0, 'UTF-8') !== false || mb_strpos($title, '雛形', 0, 'UTF-8') !== false)) {
            return true;
        }
        return false;
    }

    if (function_exists('koto_mcp_is_protected_template_post')) {
        return koto_mcp_is_protected_template_post($post);
    }
    $id = is_numeric($post) ? (int)$post : ($post instanceof WP_Post ? (int)$post->ID : 0);
    if ($id > 0 && in_array($id, $protected_ids, true)) {
        return true;
    }
    $post_obj = is_numeric($post) ? get_post($id) : $post;
    if ($post_obj instanceof WP_Post) {
        $title = (string)$post_obj->post_title;
        if (mb_strpos($title, '雛型', 0, 'UTF-8') !== false || mb_strpos($title, '雛形', 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 指定された投稿IDが公開済み・パスワード保護なし・非保護対象の記事かを判定する。
 */
function koto_ogp_is_public_post($post_id, $required_post_type = '')
{
    $post_id = is_scalar($post_id) ? (int)$post_id : 0;
    if ($post_id <= 0 || koto_ogp_is_protected_post($post_id)) {
        return false;
    }
    $post = get_post($post_id);
    if (!($post instanceof WP_Post)) {
        return false;
    }
    if ($post->post_status !== 'publish' || !empty($post->post_password)) {
        return false;
    }
    if ($required_post_type !== '' && $post->post_type !== $required_post_type) {
        return false;
    }
    return true;
}

/**
 * OGPキャッシュ世代バージョンを取得する（キャラ画像変更やキャッシュクリア時にインクリメント）。
 */
function koto_ogp_get_cache_generation()
{
    $gen = get_option('koto_ogp_cache_gen', '1');
    return is_scalar($gen) && (string)$gen !== '' ? (string)$gen : '1';
}

/**
 * OGPキャッシュ世代バージョンを更新する。
 */
function koto_ogp_bump_cache_generation()
{
    $prev = (int)koto_ogp_get_cache_generation();
    $next = (string)max($prev + 1, time());
    update_option('koto_ogp_cache_gen', $next, true);
    return $next;
}

/**
 * 検索OGP用のバージョン文字列（キャッシュ世代＋JSON更新時刻）を取得する。
 */
function koto_ogp_get_search_cache_version($source = 'default')
{
    $gen = koto_ogp_get_cache_generation();
    $path = koto_ogp_get_characters_json_path($source);
    $mtime = (is_string($path) && $path !== '' && file_exists($path)) ? (int)@filemtime($path) : 0;
    return $mtime > 0 ? ($gen . '_' . $mtime) : $gen;
}

/**
 * 準備中画像（無題51_20260205005357）かどうかを判定する。
 */
function koto_ogp_is_placeholder_image($image_value)
{
    if (empty($image_value)) {
        return false;
    }

    if (is_array($image_value)) {
        $image_value = $image_value['ID'] ?? $image_value['id'] ?? $image_value['url'] ?? '';
        if (empty($image_value)) {
            return false;
        }
    }

    if (is_numeric($image_value)) {
        $att_id = (int)$image_value;
        if ($att_id <= 0) {
            return false;
        }
        if (function_exists('image_setter_is_image_set')) {
            return !image_setter_is_image_set($att_id);
        }
        $attached = get_attached_file($att_id);
        $url = wp_get_attachment_url($att_id);
        foreach ([$attached, $url] as $cand) {
            if (is_string($cand) && $cand !== '') {
                $decoded = function_exists('koto_ogp_fully_decode') ? koto_ogp_fully_decode($cand) : rawurldecode($cand);
                if (strpos($decoded, '無題51_20260205005357') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    $str = is_scalar($image_value) ? (string)$image_value : '';
    if ($str === '') {
        return false;
    }
    $decoded = function_exists('koto_ogp_fully_decode') ? koto_ogp_fully_decode($str) : rawurldecode($str);
    if (function_exists('image_setter_is_image_set') && !image_setter_is_image_set($decoded)) {
        return true;
    }
    return strpos($decoded, '無題51_20260205005357') !== false;
}

/**
 * 現在のリクエストがキャラクター検索結果ページか判定する。
 */
function koto_ogp_is_character_search_page()
{
    if (is_singular()) {
        return false;
    }
    $post_type = get_query_var('post_type');
    if (is_array($post_type)) {
        $post_type = reset($post_type);
    }
    if (empty($post_type) && isset($_GET['post_type']) && is_scalar($_GET['post_type'])) {
        $post_type = sanitize_text_field(wp_unslash((string)$_GET['post_type']));
    }
    if ($post_type !== 'character') {
        return false;
    }
    if (is_search()) {
        return true;
    }

    $params = koto_ogp_extract_allowed_search_query_params(wp_unslash($_GET));
    unset($params['post_type'], $params['s']);
    foreach ($params as $key => $value) {
        if (str_ends_with($key, '_relation')) {
            continue;
        }
        if (is_array($value) ? !empty($value) : trim((string)$value) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * 使用する検索JSONファイルパスを解決する。
 */
function koto_ogp_get_characters_json_path($source = 'default')
{
    $theme_dir = get_stylesheet_directory();
    if ($source === 'test') {
        $path = $theme_dir . '/lib/character-search/test_characters_search.json';
    } else {
        $path = '';
        if (function_exists('koto_json_generation_output_file_path')) {
            $candidate = koto_json_generation_output_file_path('all_characters_search.json');
            if ($candidate && file_exists($candidate)) {
                $path = $candidate;
            }
        }
        if ($path === '') {
            $path = $theme_dir . '/lib/character-search/all_characters_search.json';
        }
    }
    return apply_filters('koto_ogp_characters_json_path', $path, $source);
}

/**
 * JSONからキャラクター配列を読み込む（保護対象記事は除外）。
 */
function koto_ogp_load_characters_from_json($source = 'default')
{
    $path = koto_ogp_get_characters_json_path($source);
    if (!is_string($path) || $path === '' || str_contains($path, '://') || !file_exists($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    unset($raw);
    if (!is_array($data)) {
        return [];
    }
    return array_values(array_filter($data, fn($item) => is_array($item) && !koto_ogp_is_protected_post($item)));
}

// =================================================================
// OGP機能モジュールの読み込み（依存関係順）
// =================================================================
$koto_ogp_dir = __DIR__;
require_once $koto_ogp_dir . '/ogp-search.php';
require_once $koto_ogp_dir . '/ogp-image.php';
require_once $koto_ogp_dir . '/ogp-endpoint.php';
require_once $koto_ogp_dir . '/ogp-hooks.php';
require_once $koto_ogp_dir . '/ogp-admin.php';
