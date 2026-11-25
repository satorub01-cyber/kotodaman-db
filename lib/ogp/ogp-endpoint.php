<?php
/**
 * コトダマンDB OGPモジュール - 配信エンドポイント & キャッシュ制御
 *
 * ?koto_ogp=1 リクエストの署名検証、PNGキャッシュ保存・配信、記事保存時のキャッシュ削除を行います。
 */

if (!defined('ABSPATH')) exit;

// =================================================================
// 5. 高レベル画像生成・キャッシュ・エンドポイント処理
// =================================================================

/**
 * 署名対象パラメータを再帰的に正規化（スカラー文字列化・キーソート）する。
 */
function koto_ogp_normalize_sign_payload($value)
{
    if (is_array($value)) {
        $is_list = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = koto_ogp_normalize_sign_payload($v);
        }
        if (!$is_list) {
            ksort($out);
        }
        return $out;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return is_scalar($value) ? (string)$value : '';
}

/**
 * 署名用ペイロードを生成する。
 */
function koto_ogp_sign_params(array $params)
{
    $copy = $params;
    unset($copy['sig'], $copy['download']);
    $normalized = koto_ogp_normalize_sign_payload($copy);
    $serialized = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return substr(wp_hash('koto_ogp:' . $serialized), 0, 20);
}

/**
 * 署名パラメータを検証する。
 */
function koto_ogp_verify_signature(array $params, $sig)
{
    if (apply_filters('koto_ogp_bypass_signature', false, $params)) {
        return true;
    }
    if (!is_string($sig) || $sig === '') {
        return false;
    }
    $expected = koto_ogp_sign_params($params);
    return hash_equals($expected, $sig);
}

/**
 * 現在のリクエスト・ページ種別に応じたOGP画像生成パラメータを構築する。
 */
function koto_ogp_build_signed_image_url(array $params)
{
    $params['koto_ogp'] = '1';
    $params['sig'] = koto_ogp_sign_params($params);
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $query = preg_replace('/%5B\d+%5D(?==|&|$)/', '%5B%5D', $query);
    return home_url('/?' . $query);
}

/**
 * 古い一時ファイル（tmp_*.png）を掃除する（$force=false時は10分に1回へ間引き）。
 */
function koto_ogp_cleanup_stale_tmp_files($max_age_sec = 600, $force = false)
{
    if (!$force) {
        if (get_transient('koto_ogp_tmp_cleanup_lock')) {
            return;
        }
        set_transient('koto_ogp_tmp_cleanup_lock', 1, 600);
    }
    $dir = koto_ogp_get_cache_dir();
    $files = glob($dir . '/tmp_*.png');
    if (!is_array($files)) {
        return;
    }
    $now = time();
    foreach ($files as $f) {
        if (is_file($f) && ($now - (int)@filemtime($f)) > $max_age_sec) {
            @unlink($f);
        }
    }
}

/**
 * OGP画像のキャッシュミス時生成に対するIP単位のレート制限を判定・記録する。
 *
 * @return bool 制限超過時は true。
 */
function koto_ogp_is_generation_rate_limited()
{
    if (current_user_can('edit_others_posts')) {
        return false;
    }
    if (PHP_SAPI === 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
        return false;
    }

    $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    $rate_key = 'koto_ogp_gen_rl_' . md5($remote_ip);
    $hits = (int) get_transient($rate_key);
    if ($hits >= 20) {
        return true;
    }
    set_transient($rate_key, $hits + 1, MINUTE_IN_SECONDS);
    return false;
}

/**
 * 動的OGP画像を生成し、画像ファイルパスを返す。
 */
function koto_ogp_generate_image_file(array $options, bool &$rate_limited = false, array &$meta = [])
{
    $t_start = microtime(true);
    $rate_limited = false;
    $allowed_modes = ['search', 'post', 'top', 'custom', 'default'];
    $raw_mode  = is_scalar($options['mode'] ?? null) ? (string)$options['mode'] : 'default';
    $mode      = in_array($raw_mode, $allowed_modes, true) ? $raw_mode : 'default';
    $width     = max(200, min(1920, is_scalar($options['w'] ?? null) ? (int)$options['w'] : KOTO_OGP_DEFAULT_WIDTH));
    $height    = max(200, min(1080, is_scalar($options['h'] ?? null) ? (int)$options['h'] : KOTO_OGP_DEFAULT_HEIGHT));
    $show_logo = !isset($options['logo']) || (string)$options['logo'] !== '0';
    $bg_style  = (($options['bg'] ?? 'blur') === 'flat') ? 'flat' : 'blur';
    $source    = (($options['source'] ?? 'default') === 'test') ? 'test' : 'default';
    $use_cache = empty($options['nocache']) && $mode !== 'custom';
    $short_cache = false;

    $post_id = is_scalar($options['post_id'] ?? null) ? (int)$options['post_id'] : 0;
    if ($mode === 'post' && !koto_ogp_is_public_post($post_id)) {
        $mode = 'default';
        $post_id = 0;
    }
    if ($mode === 'post' && $post_id > 0 && empty(koto_ogp_resolve_character_image_candidates($post_id, true))) {
        $use_cache = false;
        $short_cache = true;
    }

    $meta = [
        'mode'        => $mode,
        'cache'       => $use_cache ? 'MISS' : 'BYPASS',
        'short_cache' => $short_cache,
        'elapsed_ms'  => 0,
    ];

    $cache_key_data = [
        'ver'  => KOTO_OGP_CACHE_VERSION,
        'mode' => $mode,
        'w'    => $width,
        'h'    => $height,
        'logo' => $show_logo ? 1 : 0,
        'bg'   => $bg_style,
        'src'  => $source,
        'opts' => $options,
    ];
    unset($cache_key_data['opts']['nocache'], $cache_key_data['opts']['download'], $cache_key_data['opts']['sig'], $cache_key_data['opts']['_wpnonce']);

    $prefix = $mode;
    if ($mode === 'post' && $post_id > 0) {
        $prefix = 'post_' . $post_id;
    }
    $cache_file = koto_ogp_get_cache_dir() . '/' . $prefix . '_' . hash('sha256', wp_json_encode($cache_key_data)) . '.png';

    if ($use_cache && file_exists($cache_file) && filesize($cache_file) > 0) {
        $meta['cache'] = 'HIT';
        $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
        return $cache_file;
    }

    if (koto_ogp_is_generation_rate_limited()) {
        $rate_limited = true;
        $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
        return '';
    }

    $render_opts = [
        'show_logo' => $show_logo,
        'bg'        => $bg_style,
        'source'    => $source,
    ];
    $canvas = null;

    if ($mode === 'search') {
        $all_chars = koto_ogp_load_characters_from_json($source);
        $search_params = isset($options['search_params']) && is_array($options['search_params']) ? $options['search_params'] : $options;
        $filtered  = koto_ogp_filter_characters($all_chars, $search_params);
        unset($all_chars);
        $sorted    = koto_ogp_sort_characters_for_thumbnail($filtered);
        $with_img = [];
        $without_img = [];
        foreach ($sorted as $c) {
            $t_url = is_scalar($c['thumb_url'] ?? null) ? trim((string)$c['thumb_url']) : '';
            if ($t_url !== '' && !koto_ogp_is_placeholder_image($t_url)) {
                $with_img[] = $c;
            } else {
                $without_img[] = $c;
            }
        }
        $ordered   = array_merge($with_img, $without_img);
        $top_chars = array_slice($ordered, 0, KOTO_OGP_MAX_TILES);
        $canvas    = koto_ogp_render_tile_canvas($top_chars, $width, $height, $render_opts);
    } elseif ($mode === 'post') {
        if ($post_id > 0 && koto_ogp_is_public_post($post_id)) {
            $canvas = koto_ogp_render_single_canvas($post_id, $width, $height, $render_opts);
        } else {
            $canvas = koto_ogp_render_tile_canvas([], $width, $height, $render_opts);
        }
    } elseif ($mode === 'top') {
        $top_chars = koto_ogp_get_top_page_characters($source);
        $canvas    = koto_ogp_render_tile_canvas($top_chars, $width, $height, $render_opts);
    } elseif ($mode === 'custom') {
        $ids = array_slice(array_values(array_filter(array_map('intval', (array)($options['ids'] ?? [])))), 0, KOTO_OGP_MAX_TILES);
        $selected_chars = [];
        if ($source === 'test') {
            $all_chars = koto_ogp_load_characters_from_json('test');
            $by_id = [];
            foreach ($all_chars as $c) {
                $by_id[(int)($c['id'] ?? 0)] = $c;
            }
            unset($all_chars);
            foreach ($ids as $id) {
                if (isset($by_id[$id])) {
                    $selected_chars[] = $by_id[$id];
                }
            }
        } else {
            foreach ($ids as $id) {
                if (koto_ogp_is_public_post($id, 'character')) {
                    $selected_chars[] = ['id' => $id];
                }
            }
        }
        $canvas = koto_ogp_render_tile_canvas($selected_chars, $width, $height, $render_opts);
    } else {
        $canvas = koto_ogp_render_tile_canvas([], $width, $height, $render_opts);
    }

    if (!$canvas) {
        $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
        return '';
    }

    koto_ogp_cleanup_stale_tmp_files();
    $temp_path = koto_ogp_get_cache_dir() . '/tmp_' . wp_generate_password(16, false) . '.png';
    imagesavealpha($canvas, true);
    $saved = @imagepng($canvas, $temp_path, 6);
    imagedestroy($canvas);

    if (!$saved) {
        @unlink($temp_path);
        $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
        return '';
    }
    if ($use_cache) {
        if (!@rename($temp_path, $cache_file)) {
            @unlink($temp_path);
            clearstatcache(true, $cache_file);
            if (file_exists($cache_file) && filesize($cache_file) > 0) {
                $meta['cache'] = 'HIT';
                $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
                return $cache_file;
            }
            $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
            return '';
        }
        $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
        return $cache_file;
    }
    $meta['elapsed_ms'] = (int)round((microtime(true) - $t_start) * 1000);
    return $temp_path;
}

/**
 * トップページ用のピックアップキャラクター（またはフォールバック高レアキャラ）を取得する。
 */
function koto_ogp_get_top_page_characters($source = 'default')
{
    $front_id = (int)get_option('page_on_front');
    if ($front_id > 0 && function_exists('get_field')) {
        $pickup_ids = get_field('pickup_chara', $front_id);
        if (!empty($pickup_ids) && is_array($pickup_ids)) {
            $chars = [];
            foreach ($pickup_ids as $item) {
                $cid = is_object($item) ? (int)($item->ID ?? 0) : (is_scalar($item) ? (int)$item : 0);
                if ($cid > 0 && koto_ogp_is_public_post($cid, 'character')) {
                    $chars[] = ['id' => $cid];
                }
                if (count($chars) >= KOTO_OGP_MAX_TILES) {
                    break;
                }
            }
            if (!empty($chars)) {
                return $chars;
            }
        }
    }

    $all_chars = koto_ogp_load_characters_from_json($source);
    if (!empty($all_chars)) {
        $sorted = koto_ogp_sort_characters_for_thumbnail($all_chars);
        return array_slice($sorted, 0, KOTO_OGP_MAX_TILES);
    }
    return [];
}

/**
 * キャラクター検索URLクエリから許可パラメータのみを安全に抽出する（長大文字列・巨大配列DoS対策付き）。
 */
function koto_ogp_extract_allowed_search_query_params(array $raw_get)
{
    $allowed_prefixes = [
        's', 'post_type', 'search_char', 'search_char_relation', 'tx_cv',
        'tx_attr', 'tx_attr_relation', 'tx_attr_sub',
        'tx_species', 'tx_species_relation',
        'tx_rarity', 'tx_rarity_relation',
        'tx_group', 'tx_group_relation',
        'tx_event', 'tx_event_relation',
        'tx_quest', 'tx_quest_relation',
        'tx_gimmick', 'tx_gimmick_relation',
        'tx_acq', 'tx_acq_relation',
        'tx_axis', 'tx_axis_relation',
        'tx_priority',
        'tx_skill_tags', 'scope_skill', 'tx_skill_tags_relation',
        'tx_trait_tags', 'scope_trait', 'tx_trait_tags_relation',
        'include_trait_status_resistance',
        'leader_target_type', 'leader_target_relation',
        'leader_target_attr', 'leader_target_species', 'leader_target_group',
        'leader_hp_val', 'leader_atk_val',
        'leader_status_type', 'leader_status_val',
        'leader_other_type', 'leader_other_val',
    ];

    $sanitize_one = static function ($v): string {
        $s = sanitize_text_field((string)$v);
        return mb_substr($s, 0, 200, 'UTF-8');
    };

    $clean = [];
    $cond_key_count = 0;
    foreach ($raw_get as $key => $val) {
        $key_str = (string)$key;
        $is_allowed = in_array($key_str, $allowed_prefixes, true);
        if (!$is_allowed && preg_match('/^leader_cond_(\d+)_[a-z_]+$/', $key_str, $m)) {
            $cond_idx = (int)$m[1];
            if ($cond_idx >= 1 && $cond_idx <= 20 && $cond_key_count < 60) {
                $is_allowed = true;
                $cond_key_count++;
            }
        }
        if (!$is_allowed) {
            continue;
        }
        if (is_array($val)) {
            $arr = [];
            foreach ($val as $item) {
                if (is_scalar($item)) {
                    $arr[] = $sanitize_one($item);
                    if (count($arr) >= 100) {
                        break;
                    }
                }
            }
            if (!empty($arr)) {
                $clean[$key_str] = $arr;
            }
        } elseif (is_scalar($val)) {
            $clean[$key_str] = $sanitize_one($val);
        }
    }
    ksort($clean);
    return $clean;
}

/**
 * OGPエンドポイントのエラーレスポンスを安全に出力して終了する。
 */
function koto_ogp_send_error_response($status_code, $message, $reason_code = '')
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $code = (int)$status_code;
    $err_label = preg_replace('/[^a-zA-Z0-9._ -]/', '', (string)($reason_code !== '' ? $reason_code : $message));
    $mode_label = isset($_GET['mode']) && is_scalar($_GET['mode']) ? sanitize_key((string)$_GET['mode']) : '';
    error_log(sprintf('[koto-ogp] error status=%d reason=%s mode=%s', $code, $err_label !== '' ? $err_label : 'error', $mode_label));

    status_header($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    if ($err_label !== '') {
        header('X-Koto-OGP-Error: ' . $err_label);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    exit((string)$message);
}

/**
 * カスタム画像生成用のリクエストパラメータを安全に抽出・正規化する。
 */
function koto_ogp_parse_custom_options(array $raw)
{
    $ids_raw = [];
    if (isset($raw['ids'])) {
        if (is_array($raw['ids'])) {
            $ids_raw = array_filter($raw['ids'], 'is_scalar');
        } elseif (is_scalar($raw['ids'])) {
            $ids_raw = explode(',', (string)$raw['ids']);
        }
    }
    return [
        'mode'    => 'custom',
        'ids'     => array_slice(array_values(array_filter(array_map('intval', $ids_raw))), 0, KOTO_OGP_MAX_TILES),
        'w'       => isset($raw['w']) && is_scalar($raw['w']) ? (int)$raw['w'] : KOTO_OGP_DEFAULT_WIDTH,
        'h'       => isset($raw['h']) && is_scalar($raw['h']) ? (int)$raw['h'] : KOTO_OGP_DEFAULT_HEIGHT,
        'logo'    => (($raw['logo'] ?? '1') === '0') ? '0' : '1',
        'bg'      => (($raw['bg'] ?? 'blur') === 'flat') ? 'flat' : 'blur',
        'source'  => (($raw['source'] ?? 'default') === 'test') ? 'test' : 'default',
        'nocache' => true,
    ];
}

/**
 * 生成済みPNGファイルをHTTPレスポンスとして安全に出力して終了する。
 */
function koto_ogp_send_png_file_response($file, $is_tmp_file = false, $is_custom = false, $download_filename = '', array $meta = [])
{
    if (!is_string($file) || $file === '' || !file_exists($file)) {
        koto_ogp_send_error_response(500, 'Failed to generate OGP image', 'missing_output_file');
    }

    if ($is_tmp_file) {
        register_shutdown_function(static function () use ($file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                @unlink($file);
            }
        });
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    clearstatcache(true, $file);
    $size = filesize($file);

    status_header(200);
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    if (!empty($meta['mode'])) {
        header('X-Koto-OGP-Mode: ' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$meta['mode']));
    }
    if (!empty($meta['cache'])) {
        header('X-Koto-OGP-Cache: ' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$meta['cache']));
    }
    if (isset($meta['elapsed_ms'])) {
        header('X-Koto-OGP-Time-Ms: ' . (int)$meta['elapsed_ms']);
    }
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    if ($is_custom) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
    } elseif (!empty($meta['short_cache'])) {
        header('Cache-Control: public, max-age=300');
    } else {
        header('Cache-Control: public, max-age=604800');
    }
    if ($download_filename !== '') {
        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '', $download_filename);
        if ($safe_name !== '') {
            header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        }
    }

    readfile($file);
    if ($is_tmp_file && file_exists($file)) {
        @unlink($file);
    }
    exit;
}

/**
 * ?koto_ogp=1 リクエストを処理し、PNG画像を出力して終了する。
 */
function koto_ogp_handle_image_request()
{
    if (!isset($_GET['koto_ogp']) || $_GET['koto_ogp'] !== '1') {
        return;
    }

    $raw_get = wp_unslash($_GET);
    $allowed_modes = ['search', 'post', 'top', 'custom', 'default'];
    $raw_mode = isset($raw_get['mode']) && is_scalar($raw_get['mode']) ? sanitize_key((string)$raw_get['mode']) : 'default';
    $mode = in_array($raw_mode, $allowed_modes, true) ? $raw_mode : 'default';

    if ($mode === 'custom') {
        $nonce = isset($raw_get['_wpnonce']) && is_scalar($raw_get['_wpnonce']) ? (string)$raw_get['_wpnonce'] : '';
        if (!is_user_logged_in() || !current_user_can('edit_others_posts') || !wp_verify_nonce($nonce, 'koto_ogp_custom_render')) {
            koto_ogp_send_error_response(403, 'Forbidden', 'custom_forbidden');
        }
        $options = koto_ogp_parse_custom_options($raw_get);
    } else {
        $sig = isset($raw_get['sig']) && is_scalar($raw_get['sig']) ? (string)$raw_get['sig'] : '';
        if (!koto_ogp_verify_signature($raw_get, $sig)) {
            koto_ogp_send_error_response(403, 'Invalid signature', 'invalid_signature');
        }
        $options = [
            'mode'    => $mode,
            'post_id' => isset($raw_get['post_id']) && is_scalar($raw_get['post_id']) ? (int)$raw_get['post_id'] : 0,
            'v'       => isset($raw_get['v']) && is_scalar($raw_get['v']) ? mb_substr(sanitize_text_field((string)$raw_get['v']), 0, 100, 'UTF-8') : '',
            'source'  => (($raw_get['source'] ?? 'default') === 'test') ? 'test' : 'default',
        ];
        if ($mode === 'search') {
            $options['search_params'] = koto_ogp_extract_allowed_search_query_params($raw_get);
        }
    }

    $rate_limited = false;
    $meta = [];
    $file = koto_ogp_generate_image_file($options, $rate_limited, $meta);
    if ($rate_limited) {
        koto_ogp_send_error_response(429, 'Too Many Requests', 'rate_limited');
    }
    if (!$file || !file_exists($file)) {
        koto_ogp_send_error_response(500, 'Failed to generate OGP image', 'generation_failed');
    }

    $is_tmp_file = str_starts_with(basename($file), 'tmp_');
    $download_filename = '';
    if (!empty($raw_get['download'])) {
        $download_filename = sprintf(
            'kotodaman-thumb-%dx%d.png',
            (int)($options['w'] ?? KOTO_OGP_DEFAULT_WIDTH),
            (int)($options['h'] ?? KOTO_OGP_DEFAULT_HEIGHT)
        );
    }
    koto_ogp_send_png_file_response($file, $is_tmp_file, $mode === 'custom', $download_filename, $meta);
}
add_action('parse_request', 'koto_ogp_handle_image_request', 1);

/**
 * 指定投稿IDのOGPキャッシュファイルを削除する。
 */
function koto_ogp_purge_post_cache($post_id)
{
    $post_id = (int)$post_id;
    if ($post_id <= 0) {
        return;
    }
    $dir = koto_ogp_get_cache_dir();
    $files = glob($dir . '/post_' . $post_id . '_*.png');
    if (is_array($files)) {
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}

/**
 * 検索・トップOGPキャッシュファイルを削除し、URLキャッシュ世代を更新する。
 */
function koto_ogp_purge_aggregate_caches()
{
    $dir = koto_ogp_get_cache_dir();
    foreach (['/search_*.png', '/top_*.png'] as $pattern) {
        $files = glob($dir . $pattern);
        if (is_array($files)) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }
    koto_ogp_bump_cache_generation();
}

/**
 * 全OGPキャッシュ（任意で remote/ リモートキャッシュ含む）を削除し、世代を更新する。
 */
function koto_ogp_purge_all_caches($include_remote = true)
{
    $dir = koto_ogp_get_cache_dir();
    $deleted = 0;
    $files = glob($dir . '/*.png');
    if (is_array($files)) {
        foreach ($files as $f) {
            if (is_file($f) && @unlink($f)) {
                $deleted++;
            }
        }
    }
    if ($include_remote) {
        $remote_dir = koto_ogp_get_cache_dir('remote');
        $remote_files = glob($remote_dir . '/*');
        if (is_array($remote_files)) {
            foreach ($remote_files as $rf) {
                $base = basename($rf);
                if ($base === 'index.php' || $base === '.htaccess') {
                    continue;
                }
                if (is_file($rf) && @unlink($rf)) {
                    $deleted++;
                }
            }
        }
    }
    koto_ogp_bump_cache_generation();
    return $deleted;
}

/**
 * キャラクター更新時に単体キャッシュ＋検索/トップキャッシュを無効化する（同一リクエスト内1回制限）。
 */
function koto_ogp_on_character_changed($post_id)
{
    static $done = [];
    if (!is_numeric($post_id)) {
        return;
    }
    $post_id = (int)$post_id;
    if ($post_id <= 0 || isset($done[$post_id])) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (get_post_type($post_id) !== 'character') {
        return;
    }
    $done[$post_id] = true;
    koto_ogp_purge_post_cache($post_id);
    koto_ogp_purge_aggregate_caches();
}

/**
 * キャラクター記事の更新（通常保存・ACF/image-setter・メタ更新・ゴミ箱移動・削除）時にOGPキャッシュを削除する。
 */
add_action('save_post_character', 'koto_ogp_on_character_changed');
add_action('acf/save_post', 'koto_ogp_on_character_changed', 99);
foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $koto_ogp_meta_hook) {
    add_action($koto_ogp_meta_hook, function ($meta_id, $object_id, $meta_key) {
        if ($meta_key === '_thumbnail_id' || $meta_key === 'character_image') {
            koto_ogp_on_character_changed($object_id);
        }
    }, 10, 3);
}
add_action('trashed_post', function ($post_id) {
    koto_ogp_purge_post_cache($post_id);
    if (get_post_type($post_id) === 'character') {
        koto_ogp_purge_aggregate_caches();
    }
});
add_action('deleted_post', function ($post_id) {
    koto_ogp_purge_post_cache($post_id);
    if (get_post_type($post_id) === 'character') {
        koto_ogp_purge_aggregate_caches();
    }
});
