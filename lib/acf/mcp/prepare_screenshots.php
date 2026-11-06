<?php
/**
 * キャラクタースクリーンショット前処理・コンタクトシート合成スクリプト（トークン削減用）
 *
 * 複数枚の高解像度スマホスクリーンショット（またはZIPファイル）から、
 * 上下の不要なシステムバー／メニューバーをトリミングし、1枚のタイル状画像
 * （contact_sheet.jpg）に結合・縮小します。
 * これにより、AIの read_file 呼び出し回数とVisionトークン消費量を約75〜85%削減します。
 *
 * 【使い方】
 *   php lib/acf/mcp/prepare_screenshots.php <ZIPファイルまたは画像ディレクトリパス> [出力先ディレクトリ] [--test-mode]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$test_mode = in_array('--test-mode', $argv, true) || in_array('--verbose', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static function ($arg) {
    return !in_array($arg, ['--test-mode', '--verbose'], true);
}));

if (empty($args[0])) {
    fwrite(STDERR, "Usage: php lib/acf/mcp/prepare_screenshots.php <zip_or_dir_path> [output_dir] [--test-mode]\n");
    exit(1);
}

$start_time = microtime(true);
$input_path = $args[0];
if (!file_exists($input_path)) {
    fwrite(STDERR, "Error: Input path does not exist: {$input_path}\n");
    exit(1);
}

$output_dir = $args[1] ?? '';
if ($output_dir === '') {
    if (is_dir($input_path)) {
        $output_dir = rtrim($input_path, '/\\');
    } else {
        $base_name = pathinfo($input_path, PATHINFO_FILENAME);
        $output_dir = dirname($input_path) . '/prepared_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base_name);
    }
}

if (!is_dir($output_dir) && !mkdir($output_dir, 0755, true) && !is_dir($output_dir)) {
    fwrite(STDERR, "Error: Failed to create output directory: {$output_dir}\n");
    exit(1);
}

// ZIPファイルの場合は展開する
if (is_file($input_path) && strtolower(pathinfo($input_path, PATHINFO_EXTENSION)) === 'zip') {
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "Error: ZipArchive extension is required to extract ZIP files.\n");
        exit(1);
    }
    $zip = new ZipArchive();
    if ($zip->open($input_path) !== true) {
        fwrite(STDERR, "Error: Failed to open ZIP file: {$input_path}\n");
        exit(1);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false || strpos($entry, '__MACOSX/') === 0 || substr($entry, -1) === '/') {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            continue;
        }
        $safe_base = basename($entry);
        $stream = $zip->getStream($entry);
        if ($stream) {
            file_put_contents($output_dir . '/' . $safe_base, stream_get_contents($stream));
            fclose($stream);
        }
    }
    $zip->close();
    $scan_dir = $output_dir;
} else {
    $scan_dir = is_dir($input_path) ? rtrim($input_path, '/\\') : dirname($input_path);
}

$files = [];
if (is_file($input_path) && strtolower(pathinfo($input_path, PATHINFO_EXTENSION)) !== 'zip') {
    $files[] = $input_path;
} else {
    $entries = scandir($scan_dir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'contact_sheet.jpg') {
            continue;
        }
        $full = $scan_dir . '/' . $entry;
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        if (is_file($full) && in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $files[] = $full;
        }
    }
    natsort($files);
    $files = array_values($files);
}

if (empty($files)) {
    fwrite(STDERR, "Error: No supported image files found.\n");
    exit(1);
}

$count = count($files);
$cols = min(3, $count);
$rows = (int) ceil($count / $cols);
$cell_w = 600;
$cell_h = 1100;

$canvas = imagecreatetruecolor($cols * $cell_w, $rows * $cell_h);
$bg_color = imagecolorallocate($canvas, 24, 24, 24);
$badge_bg = imagecolorallocate($canvas, 19, 94, 150);
$badge_fg = imagecolorallocate($canvas, 255, 255, 255);
imagefill($canvas, 0, 0, $bg_color);

$details = [];
foreach ($files as $idx => $file) {
    $info = @getimagesize($file);
    if (!is_array($info)) {
        continue;
    }
    $mime = $info['mime'] ?? '';
    $src = null;
    if ($mime === 'image/png') {
        $src = @imagecreatefrompng($file);
    } elseif ($mime === 'image/jpeg') {
        $src = @imagecreatefromjpeg($file);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($file);
    }
    if (!$src) {
        continue;
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    // 縦長スマホスクリーンショットの場合は上下の不要なバー（上7%、下8%）をトリミング
    if ($sh > $sw * 1.6) {
        $crop_y = (int) round($sh * 0.07);
        $crop_h = (int) round($sh * 0.85);
    } else {
        $crop_y = 0;
        $crop_h = $sh;
    }

    $col = $idx % $cols;
    $row = (int) floor($idx / $cols);
    $dx = $col * $cell_w;
    $dy = $row * $cell_h;

    imagecopyresampled(
        $canvas,
        $src,
        $dx,
        $dy,
        0,
        $crop_y,
        $cell_w,
        $cell_h,
        $sw,
        $crop_h
    );
    imagedestroy($src);

    // パネル左上にインデックスとファイル名ラベルを描画
    $label = sprintf('#%d %s', $idx + 1, basename($file));
    $label_w = min($cell_w - 8, 12 + strlen($label) * 7);
    imagefilledrectangle($canvas, $dx + 4, $dy + 4, $dx + $label_w, $dy + 24, $badge_bg);
    imagestring($canvas, 3, $dx + 8, $dy + 7, $label, $badge_fg);

    if ($test_mode) {
        $details[] = [
            'index'  => $idx + 1,
            'file'   => basename($file),
            'orig_w' => $sw,
            'orig_h' => $sh,
            'crop_y' => $crop_y,
            'crop_h' => $crop_h,
        ];
    }
}

$contact_sheet_path = rtrim($output_dir, '/\\') . '/contact_sheet.jpg';
imagejpeg($canvas, $contact_sheet_path, 82);
imagedestroy($canvas);

$response = [
    'contact_sheet' => $contact_sheet_path,
    'count'         => $count,
];

if ($test_mode) {
    $response['test_mode'] = true;
    $response['grid'] = ['cols' => $cols, 'rows' => $rows, 'width' => $cols * $cell_w, 'height' => $rows * $cell_h];
    $response['file_size_bytes'] = filesize($contact_sheet_path);
    $response['elapsed_ms'] = round((microtime(true) - $start_time) * 1000, 1);
    $response['panels'] = $details;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo json_encode($response, JSON_UNESCAPED_UNICODE) . "\n";
}
