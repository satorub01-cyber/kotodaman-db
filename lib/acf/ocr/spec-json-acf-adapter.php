<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_spec_to_acf_data(array $spec)
{
    $acf = [];
    if (!empty($spec['name'])) {
        $acf['character_name'] = $spec['name'];
    }
    if (!empty($spec['chars']) && is_array($spec['chars'])) {
        $rows = [];
        foreach ($spec['chars'] as $char) {
            $term = get_term_by('name', (string) $char, 'available_moji');
            if ($term && !is_wp_error($term)) {
                $rows[] = ['available_moji' => [$term->term_id]];
            }
        }
        if (!empty($rows)) {
            $acf['available_moji_loop'] = $rows;
        }
    }
    foreach (['attribute', 'species'] as $taxonomy) {
        if (!empty($spec[$taxonomy])) {
            $term = get_term_by('slug', (string) $spec[$taxonomy], $taxonomy);
            if ($term && !is_wp_error($term)) {
                $acf[$taxonomy] = $term->term_id;
            }
        }
    }
    if (!empty($spec['waza'])) {
        if (!empty($spec['waza']['name'])) $acf['waza_name'] = $spec['waza']['name'];
        if (!empty($spec['waza']['raw_text'])) $acf['waza_group_loop'] = [['description' => $spec['waza']['raw_text']]];
    }
    if (!empty($spec['sugowaza'])) {
        if (!empty($spec['sugowaza']['name'])) $acf['sugowaza_name'] = $spec['sugowaza']['name'];
        if (!empty($spec['sugowaza']['condition'])) $acf['sugowaza_condition'] = $spec['sugowaza']['condition'];
        if (!empty($spec['sugowaza']['raw_text'])) $acf['sugowaza_group_loop'] = [['description' => $spec['sugowaza']['raw_text']]];
    }
    foreach (['trait1' => 'first_trait_loop', 'trait2' => 'second_trait_loop', 'blessing' => 'blessing_trait_loop'] as $spec_key => $acf_key) {
        if (!empty($spec[$spec_key]['raw_text'])) {
            $acf[$acf_key] = [['description' => $spec[$spec_key]['raw_text']]];
        }
    }
    return $acf;
}

function koto_ocr_apply_existing_auto_input_rules(array $fields)
{
    if (!function_exists('koto_build_acf_data_from_inputs')) {
        return [];
    }
    $inputs = [];
    if (!empty($fields['trait1'][0]['text'])) $inputs['auto_input_trait1'] = $fields['trait1'][0]['text'];
    if (!empty($fields['trait2'][0]['text'])) $inputs['auto_input_trait2'] = $fields['trait2'][0]['text'];
    if (!empty($fields['blessing'][0]['text'])) $inputs['auto_input_blessing'] = $fields['blessing'][0]['text'];
    if (empty($inputs)) {
        return [];
    }
    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';
    $grouped_csv = koto_group_csv_by_type(koto_load_csv_dictionary($csv_path));
    return koto_build_acf_data_from_inputs($inputs, $grouped_csv);
}

function koto_ocr_merge_rule_acf_data(array $acf, array $rule_data)
{
    $map = [
        'auto_input_trait1' => 'first_trait_loop',
        'auto_input_trait2' => 'second_trait_loop',
        'auto_input_blessing' => 'blessing_trait_loop',
    ];
    foreach ($map as $input_key => $acf_key) {
        if (!empty($rule_data[$input_key])) {
            // 既存CSVルールがACF行を返せた場合はOCR fragmentより優先する。
            $acf[$acf_key] = $rule_data[$input_key];
        }
    }
    return $acf;
}
