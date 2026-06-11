<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_extract_fields(array $normalized)
{
    $fields = [];
    $classifications = [];

    foreach ($normalized['images'] ?? [] as $image) {
        $classification = koto_ocr_classify_image($image);
        $type = $classification['screen_type'];
        $source = $image['source_image'] ?? '';
        $text = trim((string) ($image['fullText'] ?? ''));
        $classifications[] = ['source_image' => $source] + $classification;

        if ($text === '') {
            continue;
        }

        if ($type === 'main') {
            $name = koto_ocr_extract_name($image);
            if ($name !== '') {
                $fields['character_name'][] = ['source_image' => $source, 'text' => $name];
            }
            $chars = koto_ocr_extract_chars($text);
            if (!empty($chars)) {
                $fields['chars'][] = ['source_image' => $source, 'text' => implode('・', $chars), 'items' => $chars];
            }
        } elseif ($type === 'waza') {
            $fields['waza'][] = ['source_image' => $source, 'text' => $text];
            $name = koto_ocr_extract_skill_name($image, ['わざ名', '技名', '名称']);
            if ($name !== '') $fields['waza_name'][] = ['source_image' => $source, 'text' => $name];
            koto_ocr_append_basic_terms($fields, $source, $text);
        } elseif ($type === 'sugowaza') {
            $fields['sugowaza'][] = ['source_image' => $source, 'text' => $text];
            $name = koto_ocr_extract_skill_name($image, ['すごわざ名', '名称']);
            if ($name !== '') $fields['sugowaza_name'][] = ['source_image' => $source, 'text' => $name];
            $condition = koto_ocr_extract_trigger_text($image);
            if ($condition !== '') $fields['sugowaza_condition'][] = ['source_image' => $source, 'text' => $condition];
            $chars = koto_ocr_extract_quoted_chars($condition);
            if (!empty($chars)) $fields['chars'][] = ['source_image' => $source, 'text' => implode('・', $chars), 'items' => $chars];
            koto_ocr_append_basic_terms($fields, $source, $text);
        } elseif ($type === 'trait') {
            koto_ocr_append_split_traits($fields, $source, $text);
            koto_ocr_append_basic_terms($fields, $source, $text);
        } elseif ($type === 'blessing') {
            $fields['blessing'][] = ['source_image' => $source, 'text' => $text];
            $chars = koto_ocr_extract_quoted_chars($text);
            if (!empty($chars)) $fields['chars'][] = ['source_image' => $source, 'text' => implode('・', $chars), 'items' => $chars];
        } elseif (in_array($type, ['leader', 'kotowaza', 'EX_skill', 'charge_skill'], true)) {
            $fields[$type][] = ['source_image' => $source, 'text' => $text];
            koto_ocr_append_basic_terms($fields, $source, $text);
        }
    }

    return ['fields' => $fields, 'classifications' => $classifications];
}

function koto_ocr_extract_name(array $image)
{
    foreach ($image['blocks'] ?? [] as $block) {
        if (($block['region'] ?? '') === 'main_name_text' && trim((string) ($block['text'] ?? '')) !== '') {
            return koto_ocr_clean_name($block['text']);
        }
    }
    $lines = preg_split('/\R/u', (string) ($image['fullText'] ?? ''));
    foreach (array_slice($lines ?: [], 0, 6) as $line) {
        $line = koto_ocr_clean_name($line);
        if ($line !== '' && !preg_match('/(Lv|HP|ATK|属性|種族|文字|レア|満福|CV|わざ|とくせい)/u', $line)) {
            return $line;
        }
    }
    return '';
}

function koto_ocr_clean_name($name)
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $name = preg_replace('/^(名前|キャラ名)[:：\s]*/u', '', $name);
    return trim($name);
}

function koto_ocr_extract_chars($text)
{
    if (preg_match('/(?:文字|もじ|使用文字)[:：\s]*([^\r\n]+)/u', $text, $m)) {
        $raw = preg_split('/[・,、\s]+/u', trim($m[1]));
        return array_values(array_filter(array_map(function ($item) {
            return trim($item, '「」[]()（） ');
        }, $raw)));
    }
    return [];
}

function koto_ocr_extract_labeled_line($text, array $labels)
{
    foreach ($labels as $label) {
        if (preg_match('/' . preg_quote($label, '/') . '[:：\s]*([^\r\n]+)/u', $text, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

function koto_ocr_extract_skill_name(array $image, array $labels)
{
    $text = (string) ($image['fullText'] ?? '');
    $labeled = koto_ocr_extract_labeled_line($text, $labels);
    if ($labeled !== '') return $labeled;

    $body_text = '';
    foreach ($image['blocks'] ?? [] as $block) {
        if (($block['region'] ?? '') === 'modal_body') {
            $body_text = trim((string) ($block['text'] ?? ''));
            break;
        }
    }
    if ($body_text !== '' && mb_strpos($text, $body_text) !== false) {
        $prefix = trim(mb_substr($text, 0, mb_strpos($text, $body_text)));
        $prefix = preg_replace('/[\s　]+$/u', '', $prefix);
        if ($prefix !== '') return $prefix;
    }

    if (preg_match('/^([^\s　]+(?:・[^\s　]+)?)/u', trim($text), $m)) {
        return trim($m[1]);
    }
    return '';
}

function koto_ocr_extract_trigger_text(array $image)
{
    foreach ($image['blocks'] ?? [] as $block) {
        if (($block['region'] ?? '') === 'modal_trigger' && trim((string) ($block['text'] ?? '')) !== '') {
            return trim((string) $block['text']);
        }
    }
    return koto_ocr_extract_labeled_line((string) ($image['fullText'] ?? ''), ['発動条件', '条件']);
}

function koto_ocr_extract_quoted_chars($text)
{
    preg_match_all('/[「\"]([^」\"]+)[」\"]/u', (string) $text, $matches);
    $chars = [];
    foreach ($matches[1] ?? [] as $match) {
        foreach (preg_split('/[・,、\s]+/u', $match) ?: [] as $char) {
            $char = trim($char);
            if ($char !== '' && mb_strlen($char) <= 2) $chars[] = $char;
        }
    }
    return array_values(array_unique($chars));
}

function koto_ocr_append_basic_terms(array &$fields, $source, $text)
{
    $attributes = [
        'fire' => '火属性',
        'water' => '水属性',
        'wood' => '木属性',
        'light' => '光属性',
        'dark' => '闇属性',
    ];
    foreach ($attributes as $slug => $label) {
        if (mb_strpos((string) $text, $label) !== false) {
            $fields['attribute'][] = ['source_image' => $source, 'text' => $label, 'slug' => $slug];
            break;
        }
    }

    $species = [
        'dragon' => '龍種族',
        'god' => '神種族',
        'demon' => '魔種族',
        'beast' => '獣種族',
        'artifact' => '物種族',
        'hero' => '英種族',
        'spirit' => '霊種族',
    ];
    foreach ($species as $slug => $label) {
        if (mb_strpos((string) $text, $label) !== false) {
            $fields['species'][] = ['source_image' => $source, 'text' => $label, 'slug' => $slug];
            break;
        }
    }
}

function koto_ocr_append_split_traits(array &$fields, $source, $text)
{
    // とくせい本文中の①②は同一とくせい内の効果番号なので、分割せず画面単位で割り当てる。
    if (!isset($fields['trait1'])) {
        $fields['trait1'][] = ['source_image' => $source, 'text' => $text];
    } else {
        $fields['trait2'][] = ['source_image' => $source, 'text' => $text];
    }
}
