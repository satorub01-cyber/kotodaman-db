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

/**
 * 祝福・EX祝福の入力JSON Schema、制約、トークン一覧、テンプレート一覧をまとめた構造化データを返却する。
 *
 * @param bool $include_gimmicks ギミックトークンを含めるかどうか（デフォルト: true）。
 * @return array<string, mixed>
 */
function koto_mcp_blessing_schema($include_gimmicks = true)
{
    $schema = [
        'type' => 'object',
        'description' => 'auto_input_blessing および auto_input_ex_blessing の入力形式',
        'oneOf' => [
            [
                'title' => '新形式（items配列形式：推奨）',
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'maxItems' => 8,
                        'description' => 'トークンまたは自由入力の配列（並び順通りにACF行が生成されます）。最大8項目まで。',
                        'items' => [
                            'oneOf' => [
                                [
                                    'title' => 'トークン指定',
                                    'type' => 'object',
                                    'required' => ['type', 'token'],
                                    'properties' => [
                                        'type'   => ['type' => 'string', 'enum' => ['token']],
                                        'token'  => ['type' => 'string', 'description' => '利用可能トークンキー（例: hp_up, attr_killer, gimmick_change 等）'],
                                        'target' => ['type' => 'string', 'description' => '対象の属性名（火、水等）または種族名（神、魔等）。target_typeがattr/speciesの時必要'],
                                    ],
                                ],
                                [
                                    'title' => '自由入力（非レベル制とくせい文言）',
                                    'type' => 'object',
                                    'required' => ['type', 'text'],
                                    'properties' => [
                                        'type'  => ['type' => 'string', 'enum' => ['free']],
                                        'point' => ['type' => ['integer', 'string'], 'default' => 20, 'description' => '必要祝福ポイント（未指定時20）'],
                                        'text'  => ['type' => 'string', 'description' => 'とくせい説明文（文字追加や追加すごわざ条件も自動解決）'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'title' => '旧形式（template＋rows形式：後方互換）',
                'type' => 'object',
                'properties' => [
                    'template'     => ['type' => 'string', 'description' => 'テンプレートキー（例: spe2_atkup）'],
                    'attrs'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'テンプレート展開時の対象属性名リスト'],
                    'species'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'テンプレート展開時の対象種族名リスト'],
                    'affiliations' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '所属グループ名リスト'],
                    'rows'         => [
                        'type' => 'array',
                        'description' => '追加の自由入力行',
                        'items' => [
                            'type' => 'object',
                            'required' => ['text'],
                            'properties' => [
                                'point' => ['type' => ['integer', 'string'], 'default' => 20],
                                'text'  => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $limits = [
        'max_items'                => 8,
        'default_point_for_single' => 20,
        'description'              => '祝福（通常祝福）およびEX祝福はそれぞれ最大8項目（MAX 8）まで設定可能です。8項目を超えるとVALIDATION_FAILEDとなります。',
    ];

    // トークン一覧の収集
    $registry = function_exists('koto_get_blessing_token_registry') ? koto_get_blessing_token_registry() : [];
    $tokens = [];
    foreach ($registry as $t_key => $def) {
        $is_gimmick = !empty($def['is_gimmick']);
        $is_super = !empty($def['is_super']);

        if (!$include_gimmicks && ($is_gimmick || $is_super)) {
            continue;
        }

        $ttype = $def['trait_type'] ?? '';
        $su = $def['status_up'] ?? '';
        $dc = $def['damage_correction'] ?? '';
        $de = $def['draw_eff'] ?? '';
        $target_type = $def['target_type'] ?? 'self';
        $pt_pattern = ($def['pt_pattern'] ?? 'default') === 'default' ? 'level' : 'single';

        $category = 'other';
        if ($is_super) {
            $category = 'super_gimmick';
        } elseif ($is_gimmick) {
            $category = 'gimmick';
        } elseif ($ttype === 'status_up') {
            $category = in_array($su, ['hp', 'atk'], true) ? 'status_up' : ($su === 'mitigation' ? 'mitigation' : 'status_up');
        } elseif ($ttype === 'damage_correction') {
            $category = 'damage_correction';
        } elseif ($ttype === 'draw_eff') {
            $category = 'draw_eff';
        }

        $desc = '';
        if ($t_key === 'hp_up') {
            $desc = '自身のHPアップ（★6: 200!, ★5: 100~350。ラベルは代表表記、ゲーム内表示はレベルで変わる）';
        } elseif ($t_key === 'atk_up') {
            $desc = '自身のATKアップ（★6: 50!, ★5: 25~70。ラベルは代表表記、ゲーム内表示はレベルで変わる）';
        } elseif ($t_key === 'attr_killer') {
            $desc = '属性キラー（★6: 10~20%, ★5: 5~15%）。targetに属性名を指定';
        } elseif ($t_key === 'species_killer') {
            $desc = '種族キラー（★6: 15~25%, ★5: 10~20%）。targetに種族名を指定';
        } elseif ($t_key === 'attr_mitigation') {
            $desc = '属性軽減（★6: 10~20%, ★5: 5~15%）。targetに属性名を指定';
        } elseif ($t_key === 'dodge') {
            $desc = '回避率UP（★6: 1~10%, ★5: 4~12%）';
        } elseif ($t_key === 'attr_30_killer') {
            $desc = '非レベル制30%キラー（20pt）。targetに属性名を指定';
        } elseif ($t_key === 'speci_35_killer') {
            $desc = '非レベル制35%キラー（20pt）。targetに種族名を指定';
        } elseif ($t_key === 'group_draw_buff') {
            $desc = '所属グループATKドローバフ（キャラ所属連動、20pt）';
        } elseif ($t_key === 'atk_up_per_unit') {
            $desc = 'ユニット数依存ATKアップ（20pt）';
        } elseif ($t_key === 'comb_plus') {
            $desc = 'コンボ＋1（20pt）';
        } elseif ($t_key === 'first_aid') {
            $desc = '応急手当（ドロー時HP回復。★6: 200~650, ★5: 200~650）';
        } elseif ($t_key === 'healing_up') {
            $desc = '回復効果アップ（★6: 10~20%, ★5: 5~15%）';
        } elseif ($is_super) {
            $desc = sprintf('%s（黄色背景アイコン、200pt）', $def['label'] ?? 'スーパーギミック耐性');
        } elseif ($is_gimmick) {
            $desc = sprintf('%s（200pt）', $def['label'] ?? 'ギミック耐性');
        }

        $tokens[] = [
            'key'           => (string) $t_key,
            'label'         => (string) ($def['label'] ?? $t_key),
            'category'      => $category,
            'target_type'   => $target_type,
            'point_pattern' => $pt_pattern,
            'description'   => $desc,
            'is_gimmick'    => $is_gimmick,
            'is_super'      => $is_super,
        ];
    }

    // テンプレート一覧の収集
    $choices = function_exists('koto_get_blessing_template_choices') ? koto_get_blessing_template_choices() : [];
    $templates = [];
    foreach ($choices as $tpl_k => $tpl_label) {
        $tokens_in_tpl = [];
        if (preg_match('/\{([^}]+)\}/', $tpl_label, $m)) {
            $tokens_in_tpl = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        }
        $clean_label = trim(preg_replace('/\s*\([^)]*\{[^}]*\}[^)]*\)/', '', $tpl_label));

        $attr_count = 0;
        $species_count = 0;
        foreach ($tokens_in_tpl as $tok) {
            if (strpos($tok, 'attr_') === 0) {
                $attr_count++;
            }
            if (strpos($tok, 'species_') === 0) {
                $species_count++;
            }
        }

        $templates[] = [
            'key'              => (string) $tpl_k,
            'label'            => $clean_label,
            'tokens'           => $tokens_in_tpl,
            'required_targets' => [
                'attrs'   => $attr_count,
                'species' => $species_count,
            ],
            'raw_definition'   => $tpl_label,
        ];
    }

    return [
        'schema'    => $schema,
        'limits'    => $limits,
        'tokens'    => $tokens,
        'templates' => $templates,
    ];
}
