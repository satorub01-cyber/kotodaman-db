<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_create_draft(array $draft, array $normalized, array $extracted, Koto_Ocr_Backend_Interface $backend)
{
    $post_id = wp_insert_post([
        'post_title' => $draft['title'],
        'post_type' => 'character',
        'post_status' => 'draft',
    ], true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    $warnings = $draft['warnings'] ?? [];
    update_post_meta($post_id, '_koto_ocr_draft', '1');

    $raw_text = [];
    foreach ($normalized['images'] ?? [] as $image) {
        $raw_text[] = [
            'source_image' => $image['source_image'] ?? '',
            'text' => $image['fullText'] ?? '',
        ];
    }
    update_post_meta($post_id, '_koto_ocr_raw_text', wp_json_encode($raw_text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    update_post_meta($post_id, '_koto_ocr_normalized', wp_json_encode(koto_ocr_lightweight_normalized($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    update_post_meta($post_id, '_koto_ocr_backend', $backend->get_name());
    update_post_meta($post_id, '_koto_ocr_model', $backend->get_model());
    update_post_meta($post_id, '_koto_ocr_generated_at', current_time('mysql'));

    $acf_data = koto_ocr_spec_to_acf_data($draft['spec']);
    $acf_data = koto_ocr_merge_rule_acf_data($acf_data, koto_ocr_apply_existing_auto_input_rules($extracted['fields'] ?? []));
    foreach ($acf_data as $field_name => $value) {
        if (koto_ocr_is_empty_acf_value($value)) {
            continue;
        }
        $saved = koto_ocr_update_acf_or_meta($field_name, $value, $post_id);
        if (!$saved) {
            $warnings[] = koto_ocr_warning($field_name, 'acf_save_failed', $field_name . ' のACF保存に失敗しました。');
        }
    }

    update_post_meta($post_id, '_spec_json', wp_json_encode($draft['spec'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    update_post_meta($post_id, '_koto_ocr_warnings', wp_json_encode(koto_ocr_unique_warnings($warnings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if (koto_ocr_debug_enabled()) {
        update_post_meta($post_id, '_koto_ocr_debug', wp_json_encode([
            'normalized' => $normalized,
            'extracted' => $extracted,
            'draft' => $draft,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return $post_id;
}

function koto_ocr_is_empty_acf_value($value)
{
    if ($value === null || $value === '') return true;
    if (!is_array($value)) return false;
    foreach ($value as $item) {
        if (!koto_ocr_is_empty_acf_value($item)) return false;
    }
    return true;
}

function koto_ocr_update_acf_or_meta($field_name, $value, $post_id)
{
    if (function_exists('update_field')) {
        $field_key = koto_ocr_resolve_acf_field_key($field_name);
        if ($field_key) {
            $result = update_field($field_key, $value, $post_id);
            if ($result !== false) return true;
        }
    }
    if (koto_ocr_allow_meta_fallback()) {
        update_post_meta($post_id, $field_name, $value);
        return true;
    }
    return false;
}

function koto_ocr_resolve_acf_field_key($field_name)
{
    if (!function_exists('acf_get_field')) {
        return '';
    }
    $field = acf_get_field($field_name);
    if ($field && !empty($field['key'])) {
        return $field['key'];
    }
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return '';
    }
    foreach (acf_get_field_groups() as $group) {
        $fields = acf_get_fields($group['key'] ?? $group);
        $key = koto_ocr_find_acf_field_key($fields ?: [], $field_name);
        if ($key) return $key;
    }
    return '';
}

function koto_ocr_find_acf_field_key(array $fields, $field_name)
{
    foreach ($fields as $field) {
        if (($field['name'] ?? '') === $field_name && !empty($field['key'])) {
            return $field['key'];
        }
        foreach (['sub_fields', 'layouts'] as $child_key) {
            if (empty($field[$child_key]) || !is_array($field[$child_key])) continue;
            $children = $field[$child_key];
            if ($child_key === 'layouts') {
                $children = [];
                foreach ($field['layouts'] as $layout) {
                    foreach (($layout['sub_fields'] ?? []) as $sub_field) {
                        $children[] = $sub_field;
                    }
                }
            }
            $found = koto_ocr_find_acf_field_key($children, $field_name);
            if ($found) return $found;
        }
    }
    return '';
}
