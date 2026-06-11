<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_build_draft_spec(array $normalized, array $extracted, array $fragment_result)
{
    $fragment = $fragment_result['fragment'] ?? [];
    $fields = $extracted['fields'] ?? [];
    $spec = $fragment;
    $warnings = array_merge($normalized['warnings'] ?? [], $fragment_result['warnings'] ?? []);

    foreach ($fields as $field => $items) {
        foreach ($items as $item) {
            if (!empty($item['text']) && !in_array($field, ['character_name', 'chars'], true)) {
                if (isset($spec['_ocr_placeholders'][$field]) && !is_array($spec['_ocr_placeholders'][$field])) {
                    $spec['_ocr_placeholders'][$field] = [[
                        'source_image' => '',
                        'text' => (string) $spec['_ocr_placeholders'][$field],
                    ]];
                }
                $spec['_ocr_placeholders'][$field][] = [
                    'source_image' => $item['source_image'] ?? '',
                    'text' => $item['text'],
                ];
            }
        }
    }

    $spec['_ocr_placeholders']['classifications'] = $extracted['classifications'] ?? [];
    $title = trim((string) ($spec['name'] ?? ''));
    if ($title === '') {
        $title = 'OCR入力 ' . current_time('Y-m-d H:i');
        $warnings[] = koto_ocr_warning('character_name', 'missing_name', 'キャラ名を安全に確定できなかったため仮タイトルで下書きを作成しました。');
    }
    $warnings[] = koto_ocr_warning('draft', 'review_required', 'OCR下書きです。公開前に必須項目と数値を確認してください。');

    return ['spec' => $spec, 'title' => $title, 'warnings' => koto_ocr_unique_warnings($warnings)];
}

function koto_ocr_unique_warnings(array $warnings)
{
    $seen = [];
    $unique = [];
    foreach ($warnings as $warning) {
        if (!is_array($warning)) continue;
        $key = ($warning['field'] ?? '') . '|' . ($warning['code'] ?? '') . '|' . ($warning['message'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $warning;
    }
    return $unique;
}
