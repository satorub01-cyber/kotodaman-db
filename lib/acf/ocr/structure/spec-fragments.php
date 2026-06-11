<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_build_spec_fragments(array $extracted)
{
    $fields = $extracted['fields'] ?? [];
    $fragment = [];
    $warnings = [];
    $numeric_warning = '倍率/数値は画像本文から安全に確定できないため手入力してください。';

    if (!empty($fields['character_name'][0]['text'])) {
        $fragment['name'] = $fields['character_name'][0]['text'];
    }
    if (!empty($fields['chars'][0]['items'])) {
        $fragment['chars'] = $fields['chars'][0]['items'];
    }
    if (!empty($fields['attribute'][0]['slug'])) {
        $fragment['attribute'] = $fields['attribute'][0]['slug'];
    }
    if (!empty($fields['species'][0]['slug'])) {
        $fragment['species'] = $fields['species'][0]['slug'];
    }
    if (!empty($fields['waza'][0]['text'])) {
        $fragment['waza'] = [
            'name' => $fields['waza_name'][0]['text'] ?? '',
            'raw_text' => $fields['waza'][0]['text'],
            'value' => null,
        ];
        $warnings[] = koto_ocr_warning('waza', 'manual_numeric_required', $numeric_warning);
    }
    if (!empty($fields['sugowaza'][0]['text'])) {
        $fragment['sugowaza'] = [
            'name' => $fields['sugowaza_name'][0]['text'] ?? '',
            'condition' => $fields['sugowaza_condition'][0]['text'] ?? '',
            'raw_text' => $fields['sugowaza'][0]['text'],
            'value' => null,
        ];
        $warnings[] = koto_ocr_warning('sugowaza', 'manual_numeric_required', $numeric_warning);
    }

    foreach (['trait1', 'trait2', 'blessing'] as $field) {
        if (!empty($fields[$field][0]['text'])) {
            $fragment[$field] = ['raw_text' => $fields[$field][0]['text']];
            $warnings[] = koto_ocr_warning($field, 'raw_text_only', 'OCR本文を保存しました。公開前に内容を確認してください。');
        }
    }

    foreach (['leader', 'kotowaza', 'EX_skill', 'charge_skill'] as $field) {
        if (!empty($fields[$field][0]['text'])) {
            $fragment['_ocr_placeholders'][$field] = $fields[$field][0]['text'];
            $warnings[] = koto_ocr_warning($field, 'raw_text_only', '初期実装ではraw textのみ保存します。');
        }
    }

    return ['fragment' => $fragment, 'warnings' => $warnings];
}
