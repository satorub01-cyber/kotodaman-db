<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP ACFフィールドスキーマ管理モジュール
 *
 * ACF JSON定義ファイルからのスキーマ動的生成、フィールドマニュアル読み込み、
 * 自動入力管理フィールド一覧を提供します。
 */

function koto_mcp_field_manuals()
{
    $primary = get_stylesheet_directory() . '/documents/mcp/mcp-field-manual.json';
    $fallback = get_stylesheet_directory() . '/documents/mcp-field-manual.json';
    $path = file_exists($primary) ? $primary : $fallback;
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function koto_mcp_auto_input_managed_fields()
{
    return [
        'waza_group_loop',
        'waza_maltiplier_table_group',
        'sugowaza_condition',
        'sugo_shift_type',
        'sugowaza_group_loop',
        'sugowaza_maltiplier_table_group',
        'koto_shift_type',
        'kotowaza_loop_v2',
        'kotowaza_maltiplier_table_group',
        'first_trait_loop',
        'second_trait_loop',
        'ls_loop',
        'blessing_trait_loop',
        'blessing_ex_trait_loop',
        'miracle_leader_loop',
        'charge_skill_loop',
        'ex_trait_loop',
    ];
}

function koto_mcp_field_schema($compact = false)
{
    $fields = [];
    $excluded_in_compact = $compact ? koto_mcp_auto_input_managed_fields() : [];
    $files = glob(get_stylesheet_directory() . '/acf-json/group_*.json') ?: [];
    foreach ($files as $file) {
        $group = json_decode(file_get_contents($file), true);
        if (!is_array($group)) continue;
        $is_character_group = false;
        foreach (($group['location'] ?? []) as $or_group) {
            foreach ((array) $or_group as $rule) {
                if (($rule['param'] ?? '') === 'post_type' && ($rule['operator'] ?? '==') === '==' && ($rule['value'] ?? '') === 'character') {
                    $is_character_group = true;
                    break 2;
                }
            }
        }
        if (!$is_character_group && !empty($group['location'])) {
            continue;
        }
        foreach (($group['fields'] ?? []) as $field) {
            if (empty($field['name']) || in_array($field['type'], ['tab', 'accordion'], true)) continue;
            if ($compact && in_array($field['name'], $excluded_in_compact, true)) continue;
            if ($compact) {
                $entry = [
                    'label' => $field['label'] ?? $field['name'],
                    'type'  => $field['type'],
                ];
                if (!empty($field['required'])) $entry['required'] = true;
                if (!empty($field['taxonomy'])) $entry['taxonomy'] = $field['taxonomy'];
                if (!empty($field['choices'])) $entry['choices'] = $field['choices'];
                if (isset($field['min']) && $field['min'] !== '' && $field['min'] !== null) $entry['min'] = $field['min'];
                if (isset($field['max']) && $field['max'] !== '' && $field['max'] !== null) $entry['max'] = $field['max'];
                $fields[$field['name']] = $entry;
            } else {
                $fields[$field['name']] = [
                    'label' => $field['label'] ?? $field['name'],
                    'type' => $field['type'],
                    'required' => !empty($field['required']),
                    'taxonomy' => $field['taxonomy'] ?? null,
                    'choices' => $field['choices'] ?? null,
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                    'instructions' => $field['instructions'] ?? '',
                ];
            }
        }
    }
    foreach (koto_mcp_field_manuals() as $name => $manual) {
        if (isset($fields[$name]) && is_array($manual)) $fields[$name]['manual'] = $manual;
    }
    return $fields;
}
