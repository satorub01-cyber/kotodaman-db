<?php
/**
 * コトダマンDB OGPモジュール - 画像解決 & GD合成エンジン
 *
 * 6:5画像ソース解決、安全なリモート画像キャッシュ、GDによる1200×630 / 1280×720合成と右下ロゴ描画を行います。
 */

if (!defined('ABSPATH')) exit;

// =================================================================
// 3. 画像解決（6:5優先・ローカル直接読込・安全なリモート取得）
// =================================================================

/**
 * ローカルパスが wp-content/uploads 配下の許可された実ファイルであることを検証して正規化パスを返す。
 */
function koto_ogp_validate_local_upload_file($candidate_path, $basedir)
{
    if (!is_string($candidate_path) || $candidate_path === '' || str_contains($candidate_path, '://') || str_contains($candidate_path, "\0")) {
        return '';
    }
    $real_base = realpath($basedir);
    $real_full = realpath($candidate_path);
    if ($real_base === false || $real_full === false) {
        return '';
    }
    $prefix = rtrim($real_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($real_full, $prefix) || !is_file($real_full) || !is_readable($real_full)) {
        return '';
    }
    $ext = strtolower(pathinfo($real_full, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
        return '';
    }
    return $real_full;
}

/**
 * URLまたはローカルパスをローカル wp-content/uploads 配下の安全なファイルパスへ変換する（存在すれば返す）。
 */
function koto_ogp_url_to_local_upload_path($url)
{
    if (!is_scalar($url)) {
        return '';
    }
    $url = trim((string)$url);
    if ($url === '' || str_contains($url, "\0")) {
        return '';
    }

    $uploads = wp_upload_dir();
    $basedir = rtrim((string)($uploads['basedir'] ?? ''), '/');
    if ($basedir === '') {
        return '';
    }

    // スキームを含まない直接パスの場合は basedir 配下かを realpath で厳格検証
    if (!str_contains($url, '://')) {
        $direct = koto_ogp_validate_local_upload_file($url, $basedir);
        if ($direct !== '') {
            return $direct;
        }
    } elseif (!preg_match('#^https?://#i', $url)) {
        // http/https 以外のストリームラッパー（phar://, file://, php:// 等）は拒否
        return '';
    }

    $parsed = wp_parse_url($url);
    $path = $parsed['path'] ?? '';
    if ($path === '') {
        return '';
    }

    $base_path = (string)(wp_parse_url((string)($uploads['baseurl'] ?? ''), PHP_URL_PATH) ?: '/wp-content/uploads');
    $base_path = rtrim($base_path, '/');

    $rel = '';
    if ($base_path !== '' && str_starts_with($path, $base_path . '/')) {
        $rel = substr($path, strlen($base_path));
    } elseif (preg_match('#/wp-content/uploads(/.*)$#', $path, $m)) {
        $rel = $m[1];
    }
    if ($rel === '') {
        return '';
    }

    foreach ([$rel, koto_ogp_fully_decode($rel)] as $candidate_rel) {
        if (strpos($candidate_rel, '..') !== false) {
            continue;
        }
        $validated = koto_ogp_validate_local_upload_file($basedir . $candidate_rel, $basedir);
        if ($validated !== '') {
            return $validated;
        }
    }
    return '';
}

/**
 * 非ASCII文字やゼロ幅スペースを含むURLをパスセグメント単位で正しくパーセントエンコードする。
 */
function koto_ogp_encode_url_for_request($url)
{
    $parts = wp_parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }
    $path = $parts['path'] ?? '/';
    $segments = explode('/', $path);
    $encoded_segments = array_map(function ($seg) {
        if ($seg === '') return '';
        return rawurlencode(rawurldecode($seg));
    }, $segments);

    $rebuilt = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= implode('/', $encoded_segments);
    if (!empty($parts['query'])) {
        $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
}

/**
 * 許可されたホストのリモート画像を取得してローカルキャッシュパスを返す（SSRF・メモリ枯渇防止）。
 */
function koto_ogp_fetch_remote_image_cached($url)
{
    if (!is_scalar($url)) {
        return '';
    }
    $url = trim((string)$url);
    if ($url === '' || !apply_filters('koto_ogp_allow_remote_fetch', true, $url)) {
        return '';
    }
    $parts = wp_parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return '';
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return '';
    }
    $site_port = (int)wp_parse_url(home_url(), PHP_URL_PORT);
    $allowed_ports = array_values(array_unique(array_filter([80, 443, $site_port])));
    if (!empty($parts['port']) && !in_array((int)$parts['port'], $allowed_ports, true)) {
        return '';
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    $site_host = strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));
    $allowed_hosts = apply_filters('koto_ogp_allowed_image_hosts', array_values(array_unique(array_filter([
        'www.kotodaman-db.com',
        'kotodaman-db.com',
        $site_host,
    ]))));
    if (!in_array($host, $allowed_hosts, true)) {
        return '';
    }

    $remote_dir = koto_ogp_get_cache_dir('remote');
    $ext = strtolower(pathinfo((string)($parts['path'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
        $ext = 'img';
    }
    $cache_file = $remote_dir . '/' . hash('sha256', $url) . '.' . $ext;
    if (file_exists($cache_file) && filesize($cache_file) > 0) {
        return $cache_file;
    }

    $request_url = koto_ogp_encode_url_for_request($url);
    $res = wp_remote_get($request_url, [
        'timeout'             => 5,
        'redirection'         => 0,
        'limit_response_size' => 10 * 1024 * 1024,
        'user-agent'          => 'KotodamanDB-OGP/1.0',
    ]);
    if (is_wp_error($res) || (int)wp_remote_retrieve_response_code($res) !== 200) {
        return '';
    }
    $body = wp_remote_retrieve_body($res);
    if (!is_string($body) || strlen($body) < 64 || strlen($body) > 10 * 1024 * 1024) {
        return '';
    }
    if (function_exists('getimagesizefromstring')) {
        $img_info = @getimagesizefromstring($body);
        $img_w = (int)($img_info[0] ?? 0);
        $img_h = (int)($img_info[1] ?? 0);
        $img_type = (int)($img_info[2] ?? 0);
        if (
            !$img_info
            || $img_w <= 0 || $img_h <= 0
            || $img_w > 4096 || $img_h > 4096
            || ($img_w * $img_h) > 16777216
            || !in_array($img_type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)
        ) {
            return '';
        }
    }
    if (@file_put_contents($cache_file, $body, LOCK_EX) === false) {
        return '';
    }
    return $cache_file;
}

/**
 * ローカルファイルからGDイメージリソースを作成する（ピクセルフラッド/メモリ枯渇防止付き）。
 */
function koto_ogp_create_gd_from_file($file_path)
{
    if (!is_string($file_path) || $file_path === '' || str_contains($file_path, '://') || !file_exists($file_path) || !is_readable($file_path)) {
        return null;
    }
    $info = @getimagesize($file_path);
    if (!$info || empty($info[2])) {
        return null;
    }
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    if ($w <= 0 || $h <= 0 || $w > 4096 || $h > 4096 || ($w * $h) > 16777216) {
        return null;
    }
    switch ($info[2]) {
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($file_path) ?: null;
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($file_path) ?: null;
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($file_path) ?: null) : null;
        case IMAGETYPE_GIF:
            return @imagecreatefromgif($file_path) ?: null;
        default:
            return null;
    }
}

/**
 * キャラデータまたは投稿IDから 6:5 優先の画像URL/パス候補配列を返す。
 * WPの150×150正方形クロップ(-150x150)を回避して元画像（6:5）を優先する。
 */
function koto_ogp_resolve_character_image_candidates($char_or_id, $prefer_local_db = true)
{
    $candidates = [];
    $post_id = is_array($char_or_id) ? (int)($char_or_id['id'] ?? 0) : (is_scalar($char_or_id) ? (int)$char_or_id : 0);
    $thumb_url = is_array($char_or_id) && is_scalar($char_or_id['thumb_url'] ?? null) ? trim((string)$char_or_id['thumb_url']) : '';

    if ($prefer_local_db && $post_id > 0 && koto_ogp_is_public_post($post_id)) {
        $att_id = (int)get_post_thumbnail_id($post_id);
        if ($att_id <= 0 && get_post_type($post_id) === 'character') {
            $att_id = (int)get_post_meta($post_id, 'character_image', true);
        }
        if ($att_id > 0 && get_post_type($att_id) === 'attachment') {
            foreach (['medium_large', 'medium', 'large', 'full'] as $size) {
                $src = wp_get_attachment_image_src($att_id, $size);
                if (!empty($src[0])) {
                    $candidates[] = $src[0];
                }
            }
            $attached_file = get_attached_file($att_id);
            if ($attached_file && file_exists($attached_file)) {
                $candidates[] = $attached_file;
            }
        }
    }

    if ($thumb_url !== '') {
        // -150x150.png のような正方形サムネ接尾辞を除去した元サイズ(6:5)URLを先頭に置く
        $un_cropped = preg_replace('/-\d+x\d+(?=\.(?:png|jpe?g|webp|gif)(?:\?.*)?$)/i', '', $thumb_url);
        if ($un_cropped && $un_cropped !== $thumb_url) {
            $candidates[] = $un_cropped;
        }
        $candidates[] = $thumb_url;
    }

    return array_values(array_unique(array_filter($candidates)));
}

/**
 * 画像候補（ローカルパスまたはURL配列）から最初の有効なGDイメージを読み込む。
 */
function koto_ogp_load_gd_from_candidates(array $candidates)
{
    foreach ($candidates as $cand) {
        $local_path = koto_ogp_url_to_local_upload_path($cand);
        if ($local_path !== '') {
            $img = koto_ogp_create_gd_from_file($local_path);
            if ($img) {
                return $img;
            }
        }
        if (preg_match('#^https?://#i', (string)$cand)) {
            $cached = koto_ogp_fetch_remote_image_cached($cand);
            if ($cached !== '') {
                $img = koto_ogp_create_gd_from_file($cached);
                if ($img) {
                    return $img;
                }
            }
        }
    }
    return null;
}

/**
 * サイトロゴ（DB_logo.png）のGDリソースを取得する。
 */
function koto_ogp_load_logo_gd()
{
    $uploads = wp_upload_dir();
    $basedir = rtrim((string)($uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads'), '/');
    $candidates = [];
    if (file_exists($basedir . KOTO_OGP_LOGO_RESIZED_PATH)) {
        $candidates[] = $basedir . KOTO_OGP_LOGO_RESIZED_PATH;
    }
    if (file_exists($basedir . KOTO_OGP_LOGO_RELATIVE_PATH)) {
        $candidates[] = $basedir . KOTO_OGP_LOGO_RELATIVE_PATH;
    }
    $candidates[] = KOTO_OGP_LOGO_REMOTE_URL;
    return koto_ogp_load_gd_from_candidates($candidates);
}

// =================================================================
// 4. GD画像合成エンジン（1200×630 / 1280×720）
// =================================================================

/**
 * ベースキャンバス（指定背景色）を生成する。
 */
function koto_ogp_create_base_canvas($width, $height)
{
    $canvas = imagecreatetruecolor($width, $height);
    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);
    $bg = imagecolorallocate($canvas, KOTO_OGP_BG_RGB[0], KOTO_OGP_BG_RGB[1], KOTO_OGP_BG_RGB[2]);
    imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $bg);
    return $canvas;
}

/**
 * 画像を指定矩形に cover（はみ出しトリミング）で描画する。
 */
function koto_ogp_draw_image_cover($dst, $src, $dx, $dy, $dw, $dh)
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) {
        return;
    }
    $src_ratio = $sw / $sh;
    $dst_ratio = $dw / $dh;

    if ($src_ratio > $dst_ratio) {
        $crop_h = $sh;
        $crop_w = (int)round($sh * $dst_ratio);
        $sx = (int)floor(($sw - $crop_w) / 2);
        $sy = 0;
    } else {
        $crop_w = $sw;
        $crop_h = (int)round($sw / $dst_ratio);
        $sx = 0;
        $sy = (int)floor(($sh - $crop_h) / 2);
    }

    imagecopyresampled($dst, $src, (int)$dx, (int)$dy, $sx, $sy, (int)$dw, (int)$dh, max(1, $crop_w), max(1, $crop_h));
}

/**
 * 画像を指定矩形内にアスペクト比維持（contain）で描画する。
 */
function koto_ogp_draw_image_contain($dst, $src, $dx, $dy, $dw, $dh)
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) {
        return [0, 0, 0, 0];
    }
    $scale = min($dw / $sw, $dh / $sh);
    $nw = max(1, (int)round($sw * $scale));
    $nh = max(1, (int)round($sh * $scale));
    $nx = (int)round($dx + ($dw - $nw) / 2);
    $ny = (int)round($dy + ($dh - $nh) / 2);

    imagecopyresampled($dst, $src, $nx, $ny, 0, 0, $nw, $nh, $sw, $sh);
    return [$nx, $ny, $nw, $nh];
}

/**
 * 角丸矩形を描画する（GD標準関数のみで構成）。
 */
function koto_ogp_fill_rounded_rect($img, $x1, $y1, $x2, $y2, $radius, $color)
{
    $r = max(0, min($radius, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2)));
    if ($r === 0) {
        imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
        return;
    }
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

/**
 * 元画像をソフトぼかし＋クリーム色オーバーレイの背景として描画する。
 */
function koto_ogp_apply_blurred_backdrop($canvas, $src, $width, $height)
{
    $small_w = max(80, (int)round($width / 4));
    $small_h = max(42, (int)round($height / 4));
    $small = imagecreatetruecolor($small_w, $small_h);
    imagealphablending($small, true);
    koto_ogp_draw_image_cover($small, $src, 0, 0, $small_w, $small_h);
    if (function_exists('imagefilter')) {
        for ($i = 0; $i < 10; $i++) {
            @imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
    }
    imagecopyresampled($canvas, $small, 0, 0, 0, 0, $width, $height, $small_w, $small_h);
    imagedestroy($small);

    // サイト基調色 #f7ede3 を半透明オーバーレイして柔らかい統一感を出す
    $veil = imagecolorallocatealpha($canvas, KOTO_OGP_BG_RGB[0], KOTO_OGP_BG_RGB[1], KOTO_OGP_BG_RGB[2], 58);
    imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $veil);
}

/**
 * 属性ごとのフォールバック背景色を取得する（画像取得失敗時用）。
 */
function koto_ogp_get_attr_fallback_rgb($attr_id)
{
    $palette = [
        1 => [226, 98, 79],   // 火
        2 => [74, 144, 226],  // 水
        3 => [86, 178, 102],  // 木
        4 => [235, 192, 68],  // 光
        5 => [138, 92, 184],  // 闇
        6 => [84, 65, 128],   // 冥
        7 => [82, 196, 224],  // 天
        8 => [224, 118, 178], // 虹
    ];
    return $palette[(int)$attr_id] ?? KOTO_OGP_ACCENT_RGB;
}

/**
 * 画像がない/読み込めないキャラ向けのフォールバックタイルを描画する。
 */
function koto_ogp_draw_fallback_tile($canvas, $char, $x, $y, $w, $h)
{
    $rgb = koto_ogp_get_attr_fallback_rgb($char['attr'] ?? 0);
    $fill = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
    $accent = imagecolorallocatealpha($canvas, 255, 255, 255, 95);
    imagefilledrectangle($canvas, $x, $y, $x + $w - 1, $y + $h - 1, $fill);
    imagefilledellipse($canvas, (int)($x + $w / 2), (int)($y + $h / 2), (int)($h * 0.6), (int)($h * 0.6), $accent);
}

/**
 * 単体キャラ用の1200×630（または指定サイズ）キャンバスを描画する。
 */
function koto_ogp_render_single_canvas($char_or_id, $width, $height, array $options = [])
{
    $canvas = koto_ogp_create_base_canvas($width, $height);
    $prefer_db = !isset($options['source']) || $options['source'] !== 'test';
    $candidates = is_string($char_or_id) ? [$char_or_id] : koto_ogp_resolve_character_image_candidates($char_or_id, $prefer_db);
    $src = koto_ogp_load_gd_from_candidates($candidates);
    $bg_style = $options['bg'] ?? 'blur';

    if ($src) {
        if ($bg_style === 'blur') {
            koto_ogp_apply_blurred_backdrop($canvas, $src, $width, $height);
        }

        $pad_x = (int)round($width * 0.06);
        $pad_y = (int)round($height * 0.06);
        $box_w = $width - $pad_x * 2;
        $box_h = $height - $pad_y * 2;

        // 先に実画像フィット領域を算出して薄いドロップシャドウ＋白枠を描く
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($box_w / max(1, $sw), $box_h / max(1, $sh));
        $nw = max(1, (int)round($sw * $scale));
        $nh = max(1, (int)round($sh * $scale));
        $nx = (int)round($pad_x + ($box_w - $nw) / 2);
        $ny = (int)round($pad_y + ($box_h - $nh) / 2);

        $shadow = imagecolorallocatealpha($canvas, 40, 28, 18, 92);
        $border = imagecolorallocatealpha($canvas, 255, 255, 255, 18);
        imagefilledrectangle($canvas, $nx - 2, $ny, $nx + $nw + 8, $ny + $nh + 10, $shadow);
        imagefilledrectangle($canvas, $nx - 6, $ny - 6, $nx + $nw + 6, $ny + $nh + 6, $border);

        imagecopyresampled($canvas, $src, $nx, $ny, 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($src);
    } else {
        $logo = koto_ogp_load_logo_gd();
        if ($logo) {
            koto_ogp_draw_image_contain($canvas, $logo, (int)($width * 0.2), (int)($height * 0.2), (int)($width * 0.6), (int)($height * 0.6));
            imagedestroy($logo);
            $options['skip_corner_logo'] = true;
        }
    }

    if (empty($options['skip_corner_logo']) && !empty($options['show_logo'])) {
        koto_ogp_overlay_logo($canvas, $width, $height);
    }

    return $canvas;
}

/**
 * タイル配置用の矩形リストを算出する（キャラ画像 6:5 比率最適化）。
 */
function koto_ogp_compute_tile_rects($count, $width, $height)
{
    $rects = [];
    if ($count >= 6) {
        // 3×2 全面グリッド（1200×630 で 1セル ≈ 396×312 ≒ 6:5 と極めて近い）
        $cols = 3;
        $rows = 2;
        $gap = 6;
        $cell_w = ($width - $gap * ($cols - 1)) / $cols;
        $cell_h = ($height - $gap * ($rows - 1)) / $rows;
        for ($i = 0; $i < 6; $i++) {
            $r = intdiv($i, $cols);
            $c = $i % $cols;
            $rects[] = [
                'x'    => (int)round($c * ($cell_w + $gap)),
                'y'    => (int)round($r * ($cell_h + $gap)),
                'w'    => (int)round($cell_w),
                'h'    => (int)round($cell_h),
                'mode' => 'cover',
            ];
        }
        return $rects;
    }

    if ($count === 5) {
        // 上段3枚・下段2枚中央揃え（すべて 6:5 カード）
        $gap = 14;
        $margin_y = 20;
        $cell_h = (int)floor(($height - $margin_y * 2 - $gap) / 2);
        $cell_w = (int)round($cell_h * 1.2);
        $max_w_3 = (int)floor(($width - 40 - $gap * 2) / 3);
        if ($cell_w > $max_w_3) {
            $cell_w = $max_w_3;
            $cell_h = (int)round($cell_w / 1.2);
        }
        $total_h = $cell_h * 2 + $gap;
        $top_y = (int)round(($height - $total_h) / 2);

        $row1_w = $cell_w * 3 + $gap * 2;
        $row1_x = (int)round(($width - $row1_w) / 2);
        for ($c = 0; $c < 3; $c++) {
            $rects[] = ['x' => $row1_x + $c * ($cell_w + $gap), 'y' => $top_y, 'w' => $cell_w, 'h' => $cell_h, 'mode' => 'cover'];
        }
        $row2_w = $cell_w * 2 + $gap;
        $row2_x = (int)round(($width - $row2_w) / 2);
        for ($c = 0; $c < 2; $c++) {
            $rects[] = ['x' => $row2_x + $c * ($cell_w + $gap), 'y' => $top_y + $cell_h + $gap, 'w' => $cell_w, 'h' => $cell_h, 'mode' => 'cover'];
        }
        return $rects;
    }

    if ($count === 4) {
        // 2×2 中央揃え（6:5 維持）
        $gap = 18;
        $margin_y = 20;
        $cell_h = (int)floor(($height - $margin_y * 2 - $gap) / 2);
        $cell_w = (int)round($cell_h * 1.2);
        $total_w = $cell_w * 2 + $gap;
        $total_h = $cell_h * 2 + $gap;
        $start_x = (int)round(($width - $total_w) / 2);
        $start_y = (int)round(($height - $total_h) / 2);
        for ($i = 0; $i < 4; $i++) {
            $r = intdiv($i, 2);
            $c = $i % 2;
            $rects[] = [
                'x'    => $start_x + $c * ($cell_w + $gap),
                'y'    => $start_y + $r * ($cell_h + $gap),
                'w'    => $cell_w,
                'h'    => $cell_h,
                'mode' => 'cover',
            ];
        }
        return $rects;
    }

    if ($count === 3) {
        // 横3枚並び（6:5 維持）
        $gap = 18;
        $margin_x = 24;
        $cell_w = (int)floor(($width - $margin_x * 2 - $gap * 2) / 3);
        $cell_h = (int)round($cell_w / 1.2);
        $total_w = $cell_w * 3 + $gap * 2;
        $start_x = (int)round(($width - $total_w) / 2);
        $start_y = (int)round(($height - $cell_h) / 2);
        for ($c = 0; $c < 3; $c++) {
            $rects[] = [
                'x'    => $start_x + $c * ($cell_w + $gap),
                'y'    => $start_y,
                'w'    => $cell_w,
                'h'    => $cell_h,
                'mode' => 'cover',
            ];
        }
        return $rects;
    }

    if ($count === 2) {
        // 横2枚並び（6:5 維持）
        $gap = 24;
        $margin_x = 32;
        $cell_w = (int)floor(($width - $margin_x * 2 - $gap) / 2);
        $cell_h = (int)round($cell_w / 1.2);
        $max_h = $height - 64;
        if ($cell_h > $max_h) {
            $cell_h = $max_h;
            $cell_w = (int)round($cell_h * 1.2);
        }
        $total_w = $cell_w * 2 + $gap;
        $start_x = (int)round(($width - $total_w) / 2);
        $start_y = (int)round(($height - $cell_h) / 2);
        for ($c = 0; $c < 2; $c++) {
            $rects[] = [
                'x'    => $start_x + $c * ($cell_w + $gap),
                'y'    => $start_y,
                'w'    => $cell_w,
                'h'    => $cell_h,
                'mode' => 'cover',
            ];
        }
        return $rects;
    }

    return $rects;
}

/**
 * 複数キャラクター（0〜6体）のタイルサムネイルキャンバスを生成する。
 */
function koto_ogp_render_tile_canvas(array $characters, $width, $height, array $options = [])
{
    $chars = array_slice(array_values($characters), 0, KOTO_OGP_MAX_TILES);
    $count = count($chars);

    if ($count === 1) {
        return koto_ogp_render_single_canvas($chars[0], $width, $height, $options);
    }

    $canvas = koto_ogp_create_base_canvas($width, $height);
    if ($count === 0) {
        $logo = koto_ogp_load_logo_gd();
        if ($logo) {
            koto_ogp_draw_image_contain($canvas, $logo, (int)($width * 0.2), (int)($height * 0.2), (int)($width * 0.6), (int)($height * 0.6));
            imagedestroy($logo);
        }
        return $canvas;
    }

    $prefer_db = !isset($options['source']) || $options['source'] !== 'test';
    $bg_style = $options['bg'] ?? 'blur';
    $rects = koto_ogp_compute_tile_rects($count, $width, $height);
    $shadow = imagecolorallocatealpha($canvas, 35, 25, 15, 96);

    // 2〜5体のときは余白背景に最初に見つかったキャラ画像のぼかしを適用（描画ループ前に処理して即破棄）
    if ($count < 6 && $bg_style === 'blur') {
        foreach ($chars as $c) {
            $cands = koto_ogp_resolve_character_image_candidates($c, $prefer_db);
            $bg_img = koto_ogp_load_gd_from_candidates($cands);
            if ($bg_img) {
                koto_ogp_apply_blurred_backdrop($canvas, $bg_img, $width, $height);
                imagedestroy($bg_img);
                break;
            }
        }
    }

    foreach ($rects as $idx => $rect) {
        $cands = koto_ogp_resolve_character_image_candidates($chars[$idx], $prefer_db);
        $img = koto_ogp_load_gd_from_candidates($cands);

        if ($count < 6) {
            imagefilledrectangle($canvas, $rect['x'] + 4, $rect['y'] + 4, $rect['x'] + $rect['w'] + 4, $rect['y'] + $rect['h'] + 4, $shadow);
        }
        if ($img) {
            koto_ogp_draw_image_cover($canvas, $img, $rect['x'], $rect['y'], $rect['w'], $rect['h']);
            imagedestroy($img);
        } else {
            koto_ogp_draw_fallback_tile($canvas, $chars[$idx], $rect['x'], $rect['y'], $rect['w'], $rect['h']);
        }
    }

    if (!empty($options['show_logo'])) {
        koto_ogp_overlay_logo($canvas, $width, $height);
    }

    return $canvas;
}

/**
 * キャンバスの右下にサイトロゴを透過合成する。
 */
function koto_ogp_overlay_logo($canvas, $width, $height)
{
    $logo = koto_ogp_load_logo_gd();
    if (!$logo) {
        return;
    }

    $lw = imagesx($logo);
    $lh = imagesy($logo);
    if ($lw <= 0 || $lh <= 0) {
        imagedestroy($logo);
        return;
    }

    $target_w = (int)round($width * 0.25);
    $target_h = max(1, (int)round($target_w * ($lh / $lw)));
    $margin = 18;

    $dx = $width - $margin - $target_w;
    $dy = $height - $margin - $target_h;

    // アルファチャネルを保ったまま縮小コピー
    $resized = imagecreatetruecolor($target_w, $target_h);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefilledrectangle($resized, 0, 0, $target_w - 1, $target_h - 1, $transparent);
    imagecopyresampled($resized, $logo, 0, 0, 0, 0, $target_w, $target_h, $lw, $lh);

    imagealphablending($canvas, true);
    imagecopy($canvas, $resized, $dx, $dy, 0, 0, $target_w, $target_h);

    imagedestroy($resized);
    imagedestroy($logo);
}
