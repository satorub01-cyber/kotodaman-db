<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_allowed_screen_types()
{
    return ['main', 'waza', 'sugowaza', 'trait', 'blessing', 'leader', 'kotowaza', 'EX_skill', 'charge_skill', 'unknown'];
}

function koto_ocr_normalize_screen_type($screen_type)
{
    $screen_type = (string) $screen_type;
    return in_array($screen_type, koto_ocr_allowed_screen_types(), true) ? $screen_type : 'unknown';
}

function koto_ocr_classify_image(array $image)
{
    $vlm_type = koto_ocr_normalize_screen_type($image['screen_type'] ?? '');
    if ($vlm_type !== 'unknown') {
        return ['screen_type' => $vlm_type, 'confidence' => 0.8, 'reason' => 'vlm_screen_type'];
    }

    // 初期実装ではVLMのscreen_typeを採用し、不明な場合だけ強いキーワードで補完する。
    $text = (string) ($image['fullText'] ?? '');
    $rules = [
        'blessing' => ['祝福とくせい', '祝福特性'],
        'sugowaza' => ['すごわざ'],
        'leader' => ['リーダーとくせい', 'リーダー特性'],
        'kotowaza' => ['コトわざ'],
        'EX_skill' => ['EXスキル', 'EX skill'],
        'charge_skill' => ['チャージ'],
        'trait' => ['とくせい', '特性'],
        'waza' => ['わざ'],
    ];
    foreach ($rules as $type => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_stripos($text, $keyword) !== false) {
                return ['screen_type' => $type, 'confidence' => 0.55, 'reason' => 'keyword:' . $keyword];
            }
        }
    }
    return ['screen_type' => 'unknown', 'confidence' => 0.1, 'reason' => 'no_strong_keyword'];
}
