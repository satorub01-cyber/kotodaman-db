<?php
/**
 * Plugin Name: Kotodaman Local Runtime
 * Description: Local-only content model and helper behavior for the kotodaman-db child theme.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ローカル開発環境では Web (www-data) / CLI (root) / WSL ホストユーザー間の権限競合を防ぐ
@umask(0000);

add_action('init', function () {
    register_post_type('character', [
        'labels' => [
            'name' => 'Characters',
            'singular_name' => 'Character',
        ],
        'public' => true,
        'show_in_rest' => true,
        'has_archive' => true,
        'rewrite' => ['slug' => 'character'],
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'menu_icon' => 'dashicons-universal-access-alt',
    ]);

    $taxonomies = [
        'attribute' => ['slug' => 'attribute', 'hierarchical' => false, 'label' => '属性'],
        'species' => ['slug' => 'species', 'hierarchical' => false, 'label' => '種族'],
        'affiliation' => ['slug' => 'affiliation', 'hierarchical' => true, 'label' => 'グループ'],
        'event' => ['slug' => 'event', 'hierarchical' => true, 'label' => 'イベント'],
        'gimmick' => ['slug' => 'gimmick', 'hierarchical' => true, 'label' => 'ギミック'],
        'rarity' => ['slug' => 'rarity', 'hierarchical' => true, 'label' => 'レアリティ'],
        'available_moji' => ['slug' => 'available-moji', 'hierarchical' => false, 'label' => '使用可能文字'],
    ];

    foreach ($taxonomies as $taxonomy => $config) {
        register_taxonomy($taxonomy, ['character'], [
            'labels' => [
                'name' => $config['label'],
                'singular_name' => $config['label'],
            ],
            'public' => true,
            'show_in_rest' => true,
            'hierarchical' => $config['hierarchical'],
            'rewrite' => ['slug' => $config['slug']],
        ]);
    }
}, 0);

add_filter('option_blogdescription', function ($description) {
    if ($description !== '') {
        return $description;
    }

    return 'Local WordPress environment for the Kotodaman database theme.';
});

if (!function_exists('kotodaman_local_get_term_stub')) {
    function kotodaman_local_get_term_stub($taxonomy, $slug, $fallback_name = '')
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term;
        }

        return (object) [
            'slug' => $slug,
            'name' => $fallback_name !== '' ? $fallback_name : $slug,
        ];
    }
}

if (!function_exists('kotodaman_local_build_target_terms')) {
    function kotodaman_local_build_target_terms($type, $objects)
    {
        $objects = is_array($objects) ? $objects : [];
        $terms = [];

        foreach ($objects as $object) {
            $slug = trim((string) ($object['slug'] ?? ''));
            $name = trim((string) ($object['name'] ?? $slug));

            if ($type === 'attr') {
                $terms[] = kotodaman_local_get_term_stub('attribute', $slug, $name);
            } elseif ($type === 'species') {
                $terms[] = kotodaman_local_get_term_stub('species', $slug, $name);
            } elseif ($type === 'group') {
                $terms[] = kotodaman_local_get_term_stub('affiliation', $slug, $name);
            }
        }

        return array_values(array_filter($terms));
    }
}

if (!function_exists('kotodaman_local_build_advantage_target')) {
    function kotodaman_local_build_advantage_target($target)
    {
        $type = $target['type'] ?? '';
        if ($type === '') {
            return null;
        }

        return [
            'target_type' => $type,
            'target_attr' => $type === 'attr' ? kotodaman_local_build_target_terms('attr', $target['obj'] ?? []) : [],
            'target_species' => $type === 'species' ? kotodaman_local_build_target_terms('species', $target['obj'] ?? []) : [],
            'target_group' => $type === 'group' ? kotodaman_local_build_target_terms('group', $target['obj'] ?? []) : [],
            'target_other' => $type === 'other' ? trim((string) (($target['obj'][0]['name'] ?? ''))) : '',
        ];
    }
}

if (!function_exists('kotodaman_local_build_skill_detail_from_timeline')) {
    function kotodaman_local_build_skill_detail_from_timeline($timeline)
    {
        $target = $timeline['target'] ?? [];
        $target_type = $target['type'] ?? '';
        $target_objects = $target['obj'] ?? [];
        $element_slug = trim((string) ($timeline['element'] ?? ''));
        $attack_attr = $element_slug !== '' ? kotodaman_local_get_term_stub('attribute', $element_slug) : null;

        $detail = [
            'waza_type' => $timeline['type'] ?? '',
            'waza_target' => $target['main'] ?? '',
            'waza_target_detail' => $target_type !== '' ? $target_type : 'none',
            'target_detail_attr' => $target_type === 'attr' ? kotodaman_local_build_target_terms('attr', $target_objects) : [],
            'target_detail_species' => $target_type === 'species' ? kotodaman_local_build_target_terms('species', $target_objects) : [],
            'target_detail_group' => $target_type === 'group' ? kotodaman_local_build_target_terms('group', $target_objects) : [],
            'target_detail_other' => $target_type === 'other' ? trim((string) (($target_objects[0]['name'] ?? ''))) : '',
            'waza_value' => (float) ($timeline['value'] ?? 0),
            'waza_value_last' => (float) ($timeline['value_last'] ?? 0),
            'hit_count' => (int) ($timeline['hit_count'] ?? 1),
            'turn_count' => (int) ($timeline['turn_count'] ?? 1),
            'attack_attr' => $attack_attr,
            'attack_type' => is_array($timeline['attack_type'] ?? null) ? $timeline['attack_type'] : [],
            'target_status' => $timeline['resist_status'] ?? '',
            'pressure_debuff_count' => !empty($timeline['pressure_debuff']) ? implode(',', (array) $timeline['pressure_debuff']) : '',
            'omni_advantage' => !empty($timeline['omni_advantage']),
            'is_moji_healing' => !empty($timeline['is_moji_healing']),
            'moji_exhaust' => !empty($timeline['moji_exhaust']),
            'battle_field_loop' => [],
        ];

        if (!empty($timeline['color_order']) && is_array($timeline['color_order'])) {
            $detail['colorfull_attack_attr'] = array_values(array_filter(array_map(function ($slug) {
                return kotodaman_local_get_term_stub('attribute', $slug);
            }, $timeline['color_order'])));
        }

        if (!empty($timeline['at_type_target']) && is_array($timeline['at_type_target'])) {
            $detail['advantage_target'] = kotodaman_local_build_advantage_target($timeline['at_type_target']);
        }

        if (!empty($timeline['killer_rate'])) {
            $detail['advantage_rate'] = (float) $timeline['killer_rate'];
        }

        return $detail;
    }
}

if (!function_exists('kotodaman_local_build_skill_groups_from_spec')) {
    function kotodaman_local_build_skill_groups_from_spec($variations, $skill_type = 'sugo', $shift_type = 'none')
    {
        if (empty($variations) || !is_array($variations)) {
            return null;
        }

        $groups = [];
        foreach ($variations as $variation) {
            $group = [
                'waza_add_cond_loop' => [],
            ];

            if ($skill_type === 'waza') {
                $group['waza_detail_loop'] = [];
            } elseif ($skill_type === 'kotowaza') {
                $group['kotowaza_detail_loop'] = [];
            } else {
                $group['sugo_detail_loop'] = [];
            }

            if ($shift_type === 'attr' && !empty($variation['shift_value'])) {
                $group_key = $skill_type === 'kotowaza' ? 'kotowaza_shift_attr' : 'sugo_shift_attr';
                $group[$group_key] = array_values(array_filter(array_map(function ($slug) {
                    return kotodaman_local_get_term_stub('attribute', $slug);
                }, (array) $variation['shift_value'])));
            }
            if ($shift_type === 'moji' && !empty($variation['shift_value'])) {
                $group_key = $skill_type === 'kotowaza' ? 'kotowaza_shift_moji' : 'sugo_shift_moji';
                $group[$group_key] = array_map(function ($value) {
                    return (object) ['name' => $value];
                }, (array) $variation['shift_value']);
            }
            if ($shift_type === 'attacked' && !empty($variation['shift_value'][0])) {
                $group_key = $skill_type === 'kotowaza' ? 'kotowaza_shift_attacked' : 'sugo_shift_attacked';
                $group[$group_key] = $variation['shift_value'][0];
            }
            if ($shift_type === 'random' && !empty($variation['shift_value'][0])) {
                $group['random_count'] = $variation['shift_value'][0];
            }

            foreach (($variation['timelines'] ?? []) as $timeline) {
                $detail = kotodaman_local_build_skill_detail_from_timeline($timeline);
                if ($skill_type === 'waza') {
                    $group['waza_detail_loop'][] = $detail;
                } elseif ($skill_type === 'kotowaza') {
                    $group['kotowaza_detail_loop'][] = $detail;
                } else {
                    $group['sugo_detail_loop'][] = $detail;
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }
}

if (!function_exists('kotodaman_local_build_sugowaza_conditions_from_spec')) {
    function kotodaman_local_build_sugowaza_conditions_from_spec($condition_patterns)
    {
        if (empty($condition_patterns) || !is_array($condition_patterns)) {
            return null;
        }

        $patterns = [];
        foreach ($condition_patterns as $pattern) {
            $row = [
                'get_palce' => $pattern['get_place'] ?? 'default',
                'need_blessing_point' => $pattern['need_point'] ?? '',
                'sugo_cond_loop' => [],
            ];

            foreach (($pattern['conditions'] ?? []) as $condition) {
                $values = isset($condition['values']) && is_array($condition['values'])
                    ? implode(',', $condition['values'])
                    : '';

                $row['sugo_cond_loop'][] = [
                    'sugo_cond_type' => $condition['type'] ?? '',
                    'sugo_cond_val' => $values,
                ];
            }

            $patterns[] = $row;
        }

        return $patterns;
    }
}
