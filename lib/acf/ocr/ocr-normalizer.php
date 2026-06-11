<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_normalize_payload($payload, $expected_count = 1)
{
    if (!is_array($payload)) {
        return new WP_Error('koto_ocr_invalid_payload', 'OCR payloadが配列ではありません。');
    }

    $warnings = [];
    $raw_images = $payload['images'] ?? null;
    if ($raw_images === null && $expected_count === 1 && (isset($payload['fullText']) || isset($payload['full_text']))) {
        $raw_images = [$payload + ['source_image' => 'image_1']];
    }
    if (!is_array($raw_images)) {
        return new WP_Error('koto_ocr_missing_images', 'OCR JSONにimages配列がありません。');
    }
    if (count($raw_images) !== (int) $expected_count) {
        return new WP_Error('koto_ocr_image_count_mismatch', '送信画像数とOCR結果の件数が一致しません。');
    }

    $images = [];
    foreach (array_values($raw_images) as $index => $image) {
        if (!is_array($image)) {
            $image = [];
        }
        $source_image = sanitize_key($image['source_image'] ?? 'image_' . ($index + 1));
        $full_text = trim((string) ($image['fullText'] ?? $image['full_text'] ?? ''));
        $blocks = [];
        foreach (($image['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = trim((string) ($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $blocks[] = [
                'text' => $text,
                'region' => sanitize_key($block['region'] ?? ''),
                'bbox' => $block['bbox'] ?? ($block['box'] ?? null),
            ];
        }
        if ($full_text === '') {
            $warnings[] = koto_ocr_warning('ocr', 'empty_full_text', $source_image . ' のOCR本文が空です。');
        }
        $images[] = [
            'source_image' => $source_image,
            'screen_type' => koto_ocr_normalize_screen_type($image['screen_type'] ?? ''),
            'fullText' => $full_text,
            'blocks' => $blocks,
        ];
    }

    $has_text = false;
    foreach ($images as $image) {
        if ($image['fullText'] !== '') {
            $has_text = true;
            break;
        }
    }
    if (!$has_text) {
        return new WP_Error('koto_ocr_all_text_empty', '全画像のOCR本文が空です。');
    }

    return ['images' => $images, 'warnings' => $warnings];
}

function koto_ocr_warning($field, $code, $message)
{
    return [
        'field' => (string) $field,
        'code' => (string) $code,
        'message' => (string) $message,
    ];
}

function koto_ocr_lightweight_normalized(array $normalized)
{
    $images = [];
    foreach ($normalized['images'] ?? [] as $image) {
        $blocks = [];
        foreach ($image['blocks'] ?? [] as $block) {
            $blocks[] = [
                'text' => $block['text'] ?? '',
                'region' => $block['region'] ?? '',
            ];
        }
        $images[] = [
            'source_image' => $image['source_image'] ?? '',
            'screen_type' => $image['screen_type'] ?? 'unknown',
            'fullText' => $image['fullText'] ?? '',
            'blocks' => $blocks,
        ];
    }
    return ['images' => $images];
}
