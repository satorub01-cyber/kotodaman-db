<?php
// =================================================================
//  計算用データ構造生成関数 (JSON作成用・完全統合版)
// =================================================================

/**
 * キャラクターのスペック情報（計算用・JSON用）をすべて取得する関数
 * ロジックをここに集約します。
 */
function get_character_spec_data($post_id)
{
    // ▼▼▼ 1. ステータス計算ロジック (Lv.120算出) ▼▼▼
    $calc_lv120_stat = function ($val_99, $chouka_val) {
        $val_99 = (int)$val_99;
        $chouka_val = (int)$chouka_val;
        if ($val_99 <= 0) return 0;
        // Lv.1推定 -> Lv.99成長 -> Lv.22相当成長 -> 昇華ボーナス
        $val_1 = ceil($val_99 / 2);
        $diff_total = $val_99 - $val_1;
        $growth_22 = ceil($diff_total * (21 / 98));
        $shouka_bonus = ceil($chouka_val * 0.1);
        return $val_99 + $chouka_val + $growth_22 + $shouka_bonus;
    };

    // ▼▼▼ 2. ACFフィールド値の取得 ▼▼▼

    // ★追加: Lv120なしフラグ
    $is_no_lv120 = get_field('no_lv120_flag', $post_id);

    // 手動フラグ (計算せず数値を直接入れるか)
    $is_manual_120 = get_field('status_auto_tf', $post_id);

    // Lv.99 (基本値)
    $raw_99_hp  = (int)get_field('lv_99_hp', $post_id);
    $raw_99_atk = (int)get_field('lv_99_atk', $post_id);

    // 超化値
    $chouka_hp  = get_field('hp_chouka', $post_id);
    if ($chouka_hp === '' || $chouka_hp === null) $chouka_hp = 1000;
    $chouka_hp = (int)$chouka_hp;

    $chouka_atk = get_field('atk_chouka', $post_id);
    if ($chouka_atk === '' || $chouka_atk === null) $chouka_atk = 500;
    $chouka_atk = (int)$chouka_atk;

    // 手動入力値
    $manual_120_hp  = (int)get_field('lv_120_hp', $post_id);
    $manual_120_atk = (int)get_field('lv_120_atk', $post_id);

    // ★追加: 倍率推定フラグ
    $is_estimate = get_field('magnification_estimate_tf', $post_id);


    // ▼▼▼ 3. 最終ステータスの決定 ▼▼▼

    // A. 「Lv.99 + 超化」の値 (全員共通)
    $val_99_hp_total  = $raw_99_hp + $chouka_hp;
    $val_99_atk_total = $raw_99_atk + $chouka_atk;

    // B. 「Lv.120」の値の決定ロジック
    if ($is_no_lv120) {
        // ★パターン1: 「Lv120なし」フラグがONの場合
        // ソートで不利にならないよう、Lv99(最強状態)の値を代入しておく
        $val_120_hp_total  = $val_99_hp_total;
        $val_120_atk_total = $val_99_atk_total;
    } elseif ($is_manual_120) {
        // ★パターン2: 手動入力モードの場合
        $val_120_hp_total  = $manual_120_hp;
        $val_120_atk_total = $manual_120_atk;
    } else {
        // ★パターン3: 自動計算モード (通常)
        $val_120_hp_total  = $calc_lv120_stat($raw_99_hp, $chouka_hp);
        $val_120_atk_total = $calc_lv120_stat($raw_99_atk, $chouka_atk);
    }
    $talent_hp = 0;
    $talent_atk = 0;
    $rarity_term = get_field('rarity', $post_id);
    $rarity = $rarity_term ? $rarity_term->slug : 'none';
    $talent_rate = [
        'none' => 0.1,
        'special' => 0.07,
        'legend' => 0.05,
        'grand' => 0.05
    ];

    if (get_field('talent_status_auto_tf', $post_id)) {
        $talent_hp = get_field('talent_hp', $post_id);
        $talent_atk = get_field('talent_atk', $post_id);
    } else {
        $rate = $talent_rate[$rarity] ?? 0;
        $talent_hp = floor($val_99_hp_total * $rate);
        $talent_atk = floor($val_99_atk_total * $rate);
    }
    // 0. 基本情報の初期化
    $data = [
        'id'            => $post_id,
        'name'          => get_the_title($post_id),
        // ★保存用に計算結果を保持しておく
        '_val_99_hp'    => $val_99_hp_total,
        '_val_99_atk'   => $val_99_atk_total,
        '_val_120_hp'   => $val_120_hp_total,
        '_val_120_atk'  => $val_120_atk_total,
        'talent_hp'     => $talent_hp,
        'talent_atk'    => $talent_atk,
        'is_no_lv120'   => (bool)$is_no_lv120,
        'rarity'        => $rarity,
        'release_date'  => '', // ★追加: 実装日用キー
        'attribute'     => '',
        'sub_attributes' => [],
        'species'       => '',
        'groups'        => [],
        'waza'          => null,
        'sugowaza'      => null,
        'kotowaza'      => [],
        'priority'      => 4,
        'traits'        => [],
        'blessing'      => [],
        'leader'        => null,
        'corrections'   => [],
        'search_tags'   => [],
        'waza_search_tags' => [], // ★追加
        'sugo_search_tags' => [], // ★追加
        'kotowaza_search_tags' => [], // ★追加
        'kotowaza_search_tags_0' => [], // ★追加
        'kotowaza_search_tags_1' => [], // ★追加
        'kotowaza_search_tags_2' => [], // ★追加
        'kotowaza_search_tags_3' => [], // ★追加
        'kotowaza_search_tags_4' => [], // ★追加
        'kotowaza_search_tags_5' => [], // ★追加
        'trait_search_tags_1' => [], // ★追加
        'trait_search_tags_2' => [], // ★追加
        'trait_search_tags_blessing' => [], // ★追加
        'chars'         => [],
        'buff_counts_board'   => array_fill(0, 6, 0),
        'buff_counts_hand'   => array_fill(0, 6, 0),
        'debuff_counts' => array_fill(0, 6, 0),
        'name-ruby'     => '',
        'cv'            => '',
        'acquisition'   => '', //入手場所
        'max_ls_hp'     => 0,
        'max_ls_atk'    => 0,
        'firepower_index' => 0,
        'is_estimate'   => (bool)$is_estimate,
        'pre_evo_name'  => '', // ★追加
        'another_image_name' => '', // ★追加
    ];
    $data['name_ruby'] = get_field('name_ruby', $post_id) ?: '';
    $data['cv'] = get_field('voice_actor', $post_id) ?: '';
    $data['acquisition'] = get_field('get_place', $post_id) ?: '';
    $data['max_ls_hp'] = get_field('max_ls_hp', $post_id) ?: '';
    $data['max_ls_atk'] = get_field('max_ls_atk', $post_id) ?: '';
    $data['acquisition'] = $data['acquisition'] === 'gacha' ? 'ガチャ' : 'その他';
    $data['pre_evo_name'] = get_field('pre_evo_name', $post_id) ?: '';
    $data['another_image_name'] = get_field('another_image_name', $post_id) ?: '';

    // ★追加: 実装日の取得
    // ※ 'impl_date' の部分は、実際のACFフィールド名(スラッグ)に書き換えてください！
    $date_raw = get_field('実装月（わかれば実装日）', $post_id);
    if ($date_raw) {
        // スラッシュをハイフンに変換して保存
        $data['release_date'] = str_replace('/', '-', $date_raw);
    } else {
        $data['release_date'] = get_the_date('Y-m-d', $post_id);
    }

    // 1. タクソノミー情報の取得
    $terms_attr = get_the_terms($post_id, 'attribute');
    if ($terms_attr && !is_wp_error($terms_attr)) $data['attribute'] = $terms_attr[0]->slug;

    $terms_species = get_the_terms($post_id, 'species');
    if ($terms_species && !is_wp_error($terms_species)) $data['species'] = $terms_species[0]->slug;

    $terms_group = get_the_terms($post_id, 'affiliation');
    if ($terms_group && !is_wp_error($terms_group)) {
        foreach ($terms_group as $g) $data['groups'][] = $g->slug;
    }

    // ★サブ属性の判定ロジック（保存フックから移動）
    // メイン属性以外を持っているかチェック
    $collected_sub_attrs = [];
    $moji_rows = get_field('available_moji_loop', $post_id);
    if ($moji_rows) {
        foreach ($moji_rows as $row) {
            $attr_obj = $row['moji_attr'] ?? null;
            if ($attr_obj && isset($attr_obj->slug)) {
                // メイン属性と異なる場合のみサブ属性として扱う
                if ($attr_obj->slug !== $data['attribute']) {
                    $collected_sub_attrs[] = $attr_obj->slug;
                }
            }
        }
    }
    // 重複を削除して格納
    $data['sub_attributes'] = array_values(array_unique($collected_sub_attrs));


    // ★行動順の判定 (すごわざの第1動作のみ参照)
    $sugo_groups = get_field('sugowaza_group_loop', $post_id);

    if ($sugo_groups && is_array($sugo_groups)) {
        $first_group = $sugo_groups[0];
        $details = $first_group['sugo_detail_loop'] ?? [];

        if (!empty($details) && is_array($details)) {
            $first_action = $details[0];
            $type_raw = $first_action['waza_type'] ?? '';
            $type = is_array($type_raw) ? ($type_raw['value'] ?? '') : $type_raw;

            if ($type === 'battle_field') {
                $data['priority'] = 1;
            } elseif (strpos($type, 'buff') !== false || strpos($type, 'debuff') !== false) {
                $data['priority'] = 2;
            } elseif (strpos($type, 'heal') !== false) {
                $data['priority'] = 3;
            } else {
                $data['priority'] = 4;
            }
        }
    }

    // =================================================================
    // 2. 文字情報の取得・検索用タグ生成
    // =================================================================
    $search_tags = [];
    $chars_detail = [];

    // ACFの文字ループから情報を取得
    $moji_rows = get_field('available_moji_loop', $post_id);

    if ($moji_rows && is_array($moji_rows)) {
        foreach ($moji_rows as $row) {
            $unlock_place = $row['unlock_place'] ?? 'normal';
            if (empty($unlock_place)) $unlock_place = 'normal';

            // ★修正: 属性の取得ロジックを強化 (ID, オブジェクト, 配列に対応)
            $attr_slug = '';
            $raw_attr = $row['moji_attr'] ?? null;

            if (is_object($raw_attr) && isset($raw_attr->slug)) {
                $attr_slug = $raw_attr->slug; // オブジェクトの場合
            } elseif (is_array($raw_attr) && isset($raw_attr['slug'])) {
                $attr_slug = $raw_attr['slug']; // 配列の場合
            } elseif (is_numeric($raw_attr)) {
                $t = get_term($raw_attr); // IDの場合
                if ($t && !is_wp_error($t)) $attr_slug = $t->slug;
            }

            $mojis = $row['available_moji'] ?? [];
            if ($mojis && is_array($mojis)) {
                foreach ($mojis as $m_obj) {
                    // $m_obj がIDだけで返ってくる場合の対策も追加
                    $m_val = '';
                    $m_slug = '';

                    if (is_object($m_obj)) {
                        $m_val = $m_obj->name;
                        $m_slug = $m_obj->slug;
                    } elseif (is_numeric($m_obj)) {
                        $term = get_term($m_obj);
                        if ($term && !is_wp_error($term)) {
                            $m_val = $term->name;
                            $m_slug = $term->slug;
                        }
                    }

                    if ($m_val) {
                        $chars_detail[] = [
                            'val'    => $m_val,
                            'slug'   => $m_slug,
                            'unlock' => $unlock_place,
                            'attr'   => $attr_slug // ★属性を含めることで区別する
                        ];
                    }
                }
            }
        }
    }
    // ACFがない場合のフォールバック
    else {
        $terms_moji = get_the_terms($post_id, 'available_moji');
        if ($terms_moji && !is_wp_error($terms_moji)) {
            foreach ($terms_moji as $t) {
                $chars_detail[] = [
                    'val'    => $t->name,
                    'slug'   => $t->slug,
                    'unlock' => 'normal',
                    'attr'   => ''
                ];
            }
        }
    }

    // 重複対策 (属性が違えば別物として残るようになります)
    $chars_detail = array_map("unserialize", array_unique(array_map("serialize", $chars_detail)));
    $chars_detail = array_values($chars_detail);

    $data['chars'] = $chars_detail;


    // --- 検索タグ生成 (構造変更に合わせて修正) ---

    // 文字定義
    $def_connector = ['い', 'ぃ', 'う', 'ぅ', 'ん'];
    $def_small_yuyo = ['ゅ', 'ょ'];
    $def_axis_i = ['あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ', 'が', 'ざ', 'だ', 'ば', 'ぱ', 'え', 'け', 'せ', 'て', 'ね', 'へ', 'め', 'れ', 'げ', 'ぜ', 'で', 'べ', 'ぺ', 'す', 'ず'];
    $def_axis_u = ['く', 'す', 'つ', 'ふ', 'ゆ', 'ぐ', 'ず', 'づ', 'ぶ', 'ぷ', 'お', 'こ', 'そ', 'と', 'の', 'ほ', 'も', 'よ', 'ろ', 'ご', 'ぞ', 'ど', 'ぼ', 'ぽ'];
    $def_axis_youon = ['き', 'し', 'ち', 'に', 'ひ', 'み', 'り', 'ぎ', 'じ', 'ぢ', 'び', 'ぴ', 'う', 'ぅ'];

    // 取得した文字リストを回してタグ付け
    foreach ($data['chars'] as $char_item) {
        $char = $char_item['val'];     // 文字
        $unlock = $char_item['unlock']; // 解放条件

        // 1. 文字自体のタグ
        // ※「通常の文字」と「特殊解放文字」を区別してタグ検索したい場合は
        //   ここで if ($unlock === 'normal') などの条件分岐を入れることも可能です。
        //   現状は「そのキャラが使える文字」としてすべてタグ化します。

        if (in_array($char, $def_connector)) $search_tags[] = 'char_connector';
        if (in_array($char, $def_small_yuyo)) $search_tags[] = 'char_small_yuyo';
        if (in_array($char, $def_axis_i)) $search_tags[] = 'axis_i';
        if (in_array($char, $def_axis_u)) $search_tags[] = 'axis_u';
        if (in_array($char, $def_axis_youon)) $search_tags[] = 'axis_youon';
    }

    // ▼▼▼ 追加: わざ・すごわざのタイプ別タグ付与 ▼▼▼
    // ★修正: 格納先の配列を参照渡しで受け取るように変更
    $collect_skill_tags = function ($groups, &$target_tags) {
        if (!$groups) return;
        foreach ($groups as $g) {
            $details = $g['sugo_detail_loop'] ?? ($g['waza_detail_loop'] ?? ($g['kotowaza_detail_loop'] ?? []));
            if ($details && is_array($details)) {
                foreach ($details as $d) {
                    $type_raw = $d['waza_type'] ?? '';
                    $type = is_array($type_raw) ? ($type_raw['value'] ?? '') : $type_raw;
                    $attack_type_raw = $d['attack_type'] ?? '';

                    // 配列化して統一的に扱う
                    $attack_types_list = [];
                    if (is_array($attack_type_raw)) {
                        // ['value' => 'xxx', 'label' => 'xxx'] の形式（単一）かチェック
                        if (isset($attack_type_raw['value'])) {
                            $attack_types_list[] = $attack_type_raw['value'];
                        } else {
                            // 複数選択の配列とみなす
                            foreach ($attack_type_raw as $at_item) {
                                // 要素がさらに配列の場合 (Both形式の複数選択)
                                if (is_array($at_item) && isset($at_item['value'])) {
                                    $attack_types_list[] = $at_item['value'];
                                } else {
                                    $attack_types_list[] = $at_item;
                                }
                            }
                        }
                    } elseif ($attack_type_raw) {
                        $attack_types_list[] = $attack_type_raw;
                    }
                    $omni_advantage = $d['omni_advantage'] ?? false;

                    $target = $d['waza_target'] ?? '';
                    if (strpos($target, 'limited') !== false) {
                        $target_type = $d['waza_target_detail'] ?? '';
                        if ($target_type === 'other') $target_tags[] = 'waza_target_other';
                        $raw_details = $d['target_detail_' . $target_type] ?? null;
                        if ($raw_details) {
                            $terms = is_array($raw_details) ? $raw_details : [$raw_details];
                            foreach ($terms as $t) {
                                if (is_object($t) && isset($t->slug)) {
                                    $target_tags[] = 'waza_target_' . $target_type . '_' . $t->slug;
                                }
                            }
                        }
                    }
                    $hit_count = (int)($d['hit_count'] ?? 1);

                    // ★追加: 生のタイプもタグとして保存
                    if ($type) $target_tags[] = 'type_' . $type;

                    // attack_type は複数ある可能性があるのでループ
                    foreach ($attack_types_list as $at_val) {
                        if ($at_val) $target_tags[] = 'attack_type_' . $at_val;
                    }
                    if ($omni_advantage) $target_tags[] = 'type_omni_advantage';

                    // タイプに応じたタグ追加
                    if (strpos($type, 'attack') !== false || $type === 'command') $target_tags[] = 'type_attack'; // 攻撃
                    if (strpos($type, 'buff') !== false) $target_tags[] = 'type_buff';     // バフ
                    if (strpos($type, 'debuff') !== false) $target_tags[] = 'type_debuff'; // デバフ
                    if ($target === 'single_oppo' && $hit_count === 1) {
                        $target_tags[] = 'type_single_attack'; // 単体攻撃
                    } else if ($target === 'single_oppo' && $hit_count > 1) {
                        $target_tags[] = 'type_multi_hit_single'; // 単体多段攻撃
                    } else if ($target === 'all_oppo' && $hit_count === 1) {
                        $target_tags[] = 'type_all_attack'; // 全体攻撃
                    } else if ($target === 'all_oppo' && $hit_count > 1) {
                        $target_tags[] = 'type_multi_hit_all'; // 全体多段攻撃
                    } elseif ($target === 'ramdom_oppo' && $hit_count >= 1) {
                        $target_tags[] = 'type_random_malti_attack'; // ランダム多段攻撃 
                    }
                }
            }
        }
    };
    // ★修正: それぞれ別の配列に格納
    $collect_skill_tags(get_field('sugowaza_group_loop', $post_id), $data['sugo_search_tags']);
    $collect_skill_tags(get_field('waza_group_loop', $post_id), $data['waza_search_tags']);

    $koto_loops_tags = get_field('kotowaza_loop_v2', $post_id);
    if ($koto_loops_tags && is_array($koto_loops_tags)) {
        foreach ($koto_loops_tags as $idx => $k_row) {
            if ($idx >= 0 && $idx <= 5) {
                $target_key = 'kotowaza_search_tags_' . $idx;
                $collect_skill_tags($k_row['kotowaza_group_loop'], $data[$target_key]);
                $data[$target_key] = array_values(array_unique($data[$target_key]));
                $data['kotowaza_search_tags'] = array_merge($data['kotowaza_search_tags'], $data[$target_key]);
            }
        }
    }

    // 重複排除
    $data['sugo_search_tags'] = array_values(array_unique($data['sugo_search_tags']));
    $data['waza_search_tags'] = array_values(array_unique($data['waza_search_tags']));
    $data['kotowaza_search_tags'] = array_values(array_unique($data['kotowaza_search_tags']));
    if ($data['attribute']) $search_tags[] = 'attr_' . $data['attribute'];
    if ($data['species']) $search_tags[] = 'species_' . $data['species'];
    foreach ($data['groups'] as $gs) $search_tags[] = 'aff_' . $gs;

    // サブ属性タグ
    foreach ($data['sub_attributes'] as $sub) $search_tags[] = 'sub_attr_' . $sub;

    $gimmick_list = get_field('gimmick', $post_id);
    if ($gimmick_list) {
        foreach ($gimmick_list as $g_id) {
            $g_term = get_term($g_id);
            if ($g_term && !is_wp_error($g_term)) $search_tags[] = 'gimmick_' . $g_term->slug;
        }
    }
    $data['search_tags'] = array_values(array_unique($search_tags));

    // 3. スキル・とくせい情報の取得

    // ▼ ヘルパー: 倍率表グループを取得して整形する関数
    $get_scaling_data = function ($post_id, $field_prefix) {
        // ACFグループ名: waza_maltiplier_table_group / sugowaza_maltiplier_table_group / kotowaza_maltiplier_table_group
        $group = get_field("{$field_prefix}_maltiplier_table_group", $post_id);

        if (empty($group['use_maltiplier_table'])) return null;

        $scaling = [
            'type' => $group['multi_cond_type'] ?? 'enemy', // enemy(収束), moji(文字数), target(対象)
            'rows' => []
        ];

        if (!empty($group['maltiplier_table'])) {
            foreach ($group['maltiplier_table'] as $row) {
                $r = [
                    'rate' => (float)($row['rate'] ?? 0),
                    'is_buffed' => !empty($row['advantage_tf']),
                ];

                if ($scaling['type'] === 'enemy') {
                    $r['condition'] = (int)($row['enemy_count'] ?? 1);
                } elseif ($scaling['type'] === 'moji') {
                    $r['condition'] = (int)($row['moji_count'] ?? 4);
                } elseif ($scaling['type'] === 'target') {
                    $r['target_cond'] = [
                        'type' => $row['target_type'] ?? 'self',
                    ];
                }

                $scaling['rows'][] = $r;
            }
        }
        return $scaling;
    };


    // わざ
    $waza_groups = get_field('waza_group_loop', $post_id);
    if ($waza_groups) {
        $data['waza'] = [
            'name' => get_field('waza_name', $post_id),
            'variations' => _parse_skill_groups_to_data($waza_groups, 'none'),
            'scaling' => $get_scaling_data($post_id, 'waza')
        ];
    }

    // すごわざ
    $sugo_groups = get_field('sugowaza_group_loop', $post_id);
    $sugo_cond_raw = get_field('sugowaza_condition', $post_id);
    $sugo_shift = get_field('sugo_shift_type', $post_id) ?: 'none';

    if ($sugo_groups) {
        $data['sugowaza'] = [
            'name' => get_field('sugowaza_name', $post_id),
            'condition' => _parse_activation_condition($sugo_cond_raw),
            'shift_type' => $sugo_shift,
            'variations' => _parse_skill_groups_to_data($sugo_groups, $sugo_shift),
            'scaling' => $get_scaling_data($post_id, 'sugowaza')
        ];
    }

    // コトワザ
    $koto_loops = get_field('kotowaza_loop_v2', $post_id);
    $koto_shift_common = get_field('koto_shift_type', $post_id) ?: 'none';

    if ($koto_loops) {
        foreach ($koto_loops as $index => $row) {
            $grp = $row['kotowaza_group_loop'] ?? null;
            $cond = $row['kotowaza_condition'] ?? null;

            $data['kotowaza'][] = [
                'level'     => $index,
                'condition' => _parse_activation_condition($cond),
                'shift_type' => $koto_shift_common,
                'variations' => _parse_skill_groups_to_data($grp, $koto_shift_common),
                // ★修正: コトワザ用の倍率表を取得
                'scaling'    => $get_scaling_data($post_id, 'kotowaza')
            ];
        }
    }

    // ▼▼▼ 修正: バフ・デバフ総数計算ロジック (盤面/手札 分け) ▼▼▼
    $calc_skill_buffs = function ($variations) {
        $max_board = 0; // 盤面バフ最大値
        $max_hand  = 0; // 手札バフ最大値
        $max_debuff = 0; // デバフ最大値

        if (empty($variations)) return ['board' => 0, 'hand' => 0, 'debuff' => 0];

        foreach ($variations as $var) {
            $cur_board = 0;
            $cur_hand  = 0;
            $cur_debuff = 0;

            if (!empty($var['timeline'])) {
                foreach ($var['timeline'] as $action) {
                    $type   = $action['type'] ?? '';
                    $target = $action['target'] ?? ''; // ★ターゲット判定に使用
                    $amount = (int)($action['amount'] ?? 0);

                    // A. バフ判定
                    if (strpos($type, 'buff') !== false && strpos($type, 'debuff') === false) {
                        // 手札バフかどうか判定
                        if (strpos($target, 'hand') !== false) {
                            $cur_hand += $amount;
                        } else {
                            // それ以外は盤面(自身・味方)バフとしてカウント
                            $cur_board += $amount;
                        }
                    }

                    // B. デバフ判定
                    if (strpos($type, 'debuff') !== false) {
                        $cur_debuff += $amount;
                    }
                }
            }
            // 各パターンの最大値を保持
            if ($cur_board > $max_board)   $max_board = $cur_board;
            if ($cur_hand > $max_hand)     $max_hand = $cur_hand;
            if ($cur_debuff > $max_debuff) $max_debuff = $cur_debuff;
        }
        return ['board' => $max_board, 'hand' => $max_hand, 'debuff' => $max_debuff];
    };

    // 1. Lv0 (すごわざのみ)
    $sugo_b_board = 0;
    $sugo_b_hand  = 0;
    $sugo_d       = 0;

    if (!empty($data['sugowaza']['variations'])) {
        $res = $calc_skill_buffs($data['sugowaza']['variations']);
        $sugo_b_board = $res['board'];
        $sugo_b_hand  = $res['hand'];
        $sugo_d       = $res['debuff'];
    }

    $data['buff_counts_board'][0] = $sugo_b_board;
    $data['buff_counts_hand'][0]  = $sugo_b_hand;
    $data['debuff_counts'][0]     = $sugo_d;

    // 2. Lv1～Lv5 (すごわざ + 対応するコトワザ単体)
    for ($i = 1; $i <= 5; $i++) {
        // ベースはすごわざの値
        $cur_board = $sugo_b_board;
        $cur_hand  = $sugo_b_hand;
        $cur_d     = $sugo_d;

        // 該当するコトワザを加算
        $koto_idx = $i - 1;
        if (isset($data['kotowaza'][$koto_idx])) {
            $koto_item = $data['kotowaza'][$koto_idx];
            if (!empty($koto_item['variations'])) {
                $res = $calc_skill_buffs($koto_item['variations']);
                $cur_board += $res['board'];
                $cur_hand  += $res['hand'];
                $cur_d     += $res['debuff'];
            }
        }

        $data['buff_counts_board'][$i] = $cur_board;
        $data['buff_counts_hand'][$i]  = $cur_hand;
        $data['debuff_counts'][$i]     = $cur_d;
    }

    // 4. とくせい
    $t1 = get_field('first_trait_loop', $post_id);
    $t2 = get_field('second_trait_loop', $post_id);
    if (!$t1 && !$t2) $t1 = get_field('trait_group', $post_id);

    $t1_data = $t1 ? _parse_trait_loop_to_data($t1) : [];
    $t2_data = $t2 ? _parse_trait_loop_to_data($t2) : [];

    if ($t1) $data['traits'] = array_merge($data['traits'], _parse_trait_loop_to_data($t1));
    if ($t2) $data['traits'] = array_merge($data['traits'], _parse_trait_loop_to_data($t2));

    // ▼▼▼ 修正: とくせいタグの付与 (give_trait 含む) ▼▼▼
    $collect_trait_tags = function ($traits, &$target_tags) {
        if (empty($traits)) return;
        foreach ($traits as $tr) {
            // 1. 他者付与タグ
            if (isset($tr['whose']) && $tr['whose'] !== 'oneself') {
                $target_tags[] = 'give_trait';
            }

            // 2. とくせいタイプ別タグ
            $t_type = $tr['type'] ?? '';
            if ($t_type === 'mode_shift') {
                $t_sub = $tr['shift_relation'] ?? '';
                if (strpos($t_sub, 'transform') !== false) {
                    $t_sub = 'transform';
                }
            } else {
                $t_sub  = $tr['sub_type'] ?? '';
            }
            if ($t_sub === 'resonance') {
                if (!empty($tr['crit_rate'])) {
                    $t_sub = 'resonance_crit';
                } else {
                    $t_sub = 'resonance_atk';
                }
            }
            if (!empty($t_type)) {
                $target_tags[] = 'trait_' . $t_type;
            }
            if (!empty($t_sub) && is_string($t_sub)) {
                $target_tags[] = 'trait_' . $t_type . '_' . $t_sub;
            }
        }
    };

    // それぞれタグ収集
    $collect_trait_tags($t1_data, $data['trait_search_tags_1']);
    $collect_trait_tags($t2_data, $data['trait_search_tags_2']);

    // 重複排除
    $data['trait_search_tags_1'] = array_values(array_unique($data['trait_search_tags_1']));
    $data['trait_search_tags_2'] = array_values(array_unique($data['trait_search_tags_2']));

    // タグの重複排除とキーの振り直し
    $data['search_tags'] = array_values(array_unique($data['search_tags']));

    // 5. 祝福
    $blessing = get_field('blessing_trait_loop', $post_id);
    if ($blessing) $data['blessing'] = _parse_trait_loop_to_data($blessing, true);

    // 祝福とくせいタグ収集
    if (!empty($data['blessing'])) {
        $collect_trait_tags($data['blessing'], $data['trait_search_tags_blessing']);
        $data['trait_search_tags_blessing'] = array_values(array_unique($data['trait_search_tags_blessing']));
    }

    // 6. リーダー
    $ls_loop = get_field('ls_loop', $post_id);
    if ($ls_loop) $data['leader'] = _parse_leader_skill_data($ls_loop);

    // ★計算用補正値の生成
    $data['corrections'] = _calculate_correction_values($data);

    return $data;
}


// =================================================================
//  ★保存処理: JSON保存・火力指数の計算と保存のみ行う
// =================================================================
add_action('acf/save_post', 'on_save_character_specs', 20);

function on_save_character_specs($post_id)
{
    if (get_post_type($post_id) !== 'character') return;
    if (wp_is_post_revision($post_id)) return;

    $spec_data = get_character_spec_data($post_id);
    // ★追加: 属性・種族のカスタムソート用インデックス保存
    // 指定の順番定義
    $order_attr = [
        'fire'   => 1, // 火
        'water'  => 2, // 水
        'wood'   => 3, // 木
        'light'  => 4, // 光
        'dark'   => 5, // 闇
        'void'   => 6, // 冥
        'heaven' => 7, // 天
    ];
    $order_species = [
        'god'      => 1, // 神
        'demon'    => 2, // 魔
        'hero'     => 3, // 英
        'dragon'   => 4, // 龍
        'beast'    => 5, // 獣
        'spirit'   => 6, // 霊
        'artifact' => 7, // 物
        'yokai'    => 8, // 妖
    ];
    // 1. 火力指数を計算
    $firepower_index = _calculate_firepower_index($spec_data);
    // 2. 計算結果を配列（$spec_data）に反映させる！
    $spec_data['firepower_index'] = $firepower_index;
    // 1. 火力指数
    update_post_meta($post_id, 'firepower_index', $firepower_index);

    // 2. バフ・デバフ数 (Lv0～Lv5)
    for ($i = 0; $i <= 5; $i++) {
        // 盤面バフ (Board)
        update_post_meta($post_id, "buff_count_board_lv{$i}", $spec_data['buff_counts_board'][$i]);
        // 手札バフ (Hand)
        update_post_meta($post_id, "buff_count_hand_lv{$i}", $spec_data['buff_counts_hand'][$i]);
        // デバフはそのまま
        update_post_meta($post_id, "debuff_count_lv{$i}", $spec_data['debuff_counts'][$i]);
    }

    // 3. ソート用メタデータの保存 (修正: 正しいキーを使用)
    // $spec_data['hp'] は存在しないため、計算済みの _val_99_hp 等を使用するか、
    // すでに下のブロックで保存されているため、ここの記述は削除します。
    // update_post_meta($post_id, 'hp', $spec_data['hp']); // 削除
    // update_post_meta($post_id, 'atk', $spec_data['atk']); // 削除

    // 2. 新しい計算データの保存 (裏側でデータを蓄積)
    // Lv.99 (超化込み)
    if (isset($spec_data['_val_99_hp'])) {
        update_post_meta($post_id, '99_hp',  $spec_data['_val_99_hp']);
        update_post_meta($post_id, '99_atk', $spec_data['_val_99_atk']);
    }

    // Lv.120 (最終値)
    if (isset($spec_data['_val_120_hp'])) {
        update_post_meta($post_id, '120_hp',  $spec_data['_val_120_hp']);
        update_post_meta($post_id, '120_atk', $spec_data['_val_120_atk']);
    }

    // ★追加: Lv120なしフラグの保存
    if (!empty($spec_data['is_no_lv120'])) {
        update_post_meta($post_id, 'is_no_lv120', '1');
    } else {
        delete_post_meta($post_id, 'is_no_lv120'); // ない場合は消す
    }

    // 属性インデックスの保存 (辞書にないものは 99 にして後ろへ)
    $attr_slug = $spec_data['attribute'] ?? '';
    $attr_idx  = $order_attr[$attr_slug] ?? 99;
    update_post_meta($post_id, '_sort_attr_index', $attr_idx);

    // 種族インデックスの保存
    $species_slug = $spec_data['species'] ?? '';
    $species_idx  = $order_species[$species_slug] ?? 99;
    update_post_meta($post_id, '_sort_species_index', $species_idx);

    // ★実装日 (impl_dateキーで YYYY/MM/DD 形式で保存)
    update_post_meta($post_id, 'impl_date', $spec_data['release_date']);

    // ★フリガナ (空ならタイトルを入れる)
    $ruby_val = $spec_data['name_ruby'] ? $spec_data['name_ruby'] : get_the_title($post_id);
    update_post_meta($post_id, 'name_ruby', $ruby_val);

    // サブ属性検索用
    if (!empty($spec_data['sub_attributes'])) {
        update_post_meta($post_id, '_search_sub_attributes', $spec_data['sub_attributes']);
    } else {
        delete_post_meta($post_id, '_search_sub_attributes');
    }

    // ★修正: 検索用タグ文字列の保存
    // 1. 全結合タグ (既存の検索機能用: 共通 + わざ + すごわざ)
    $all_tags = array_merge(
        $spec_data['search_tags'] ?? [],
        $spec_data['waza_search_tags'] ?? [],
        $spec_data['sugo_search_tags'] ?? [],
        $spec_data['kotowaza_search_tags'] ?? [],
        $spec_data['trait_search_tags_1'] ?? [],
        $spec_data['trait_search_tags_2'] ?? [],
        $spec_data['trait_search_tags_blessing'] ?? []
    );
    $all_tags = array_values(array_unique($all_tags));

    if (!empty($all_tags)) {
        $tags_str = ' ' . implode(' ', $all_tags) . ' ';
        update_post_meta($post_id, '_search_tags_str', $tags_str);
    } else {
        delete_post_meta($post_id, '_search_tags_str');
    }

    // 2. わざ専用タグ
    if (!empty($spec_data['waza_search_tags'])) {
        update_post_meta($post_id, '_waza_tags_str', ' ' . implode(' ', $spec_data['waza_search_tags']) . ' ');
    } else {
        delete_post_meta($post_id, '_waza_tags_str');
    }

    // 3. すごわざ専用タグ
    if (!empty($spec_data['sugo_search_tags'])) {
        update_post_meta($post_id, '_sugo_tags_str', ' ' . implode(' ', $spec_data['sugo_search_tags']) . ' ');
    } else {
        delete_post_meta($post_id, '_sugo_tags_str');
    }

    // 4. とくせい1専用タグ
    if (!empty($spec_data['trait_search_tags_1'])) {
        update_post_meta($post_id, '_trait_tags_str_1', ' ' . implode(' ', $spec_data['trait_search_tags_1']) . ' ');
    } else {
        delete_post_meta($post_id, '_trait_tags_str_1');
    }

    // 5. とくせい2専用タグ
    if (!empty($spec_data['trait_search_tags_2'])) {
        update_post_meta($post_id, '_trait_tags_str_2', ' ' . implode(' ', $spec_data['trait_search_tags_2']) . ' ');
    } else {
        delete_post_meta($post_id, '_trait_tags_str_2');
    }

    // 6. 祝福とくせい専用タグ
    if (!empty($spec_data['trait_search_tags_blessing'])) {
        update_post_meta($post_id, '_trait_tags_str_blessing', ' ' . implode(' ', $spec_data['trait_search_tags_blessing']) . ' ');
    } else {
        delete_post_meta($post_id, '_trait_tags_str_blessing');
    }

    // 7. コトワザ専用タグ (全体 + 凸別)
    if (!empty($spec_data['kotowaza_search_tags'])) {
        update_post_meta($post_id, '_kotowaza_tags_str', ' ' . implode(' ', $spec_data['kotowaza_search_tags']) . ' ');
    } else {
        delete_post_meta($post_id, '_kotowaza_tags_str');
    }
    for ($i = 0; $i <= 5; $i++) {
        $k_key = 'kotowaza_search_tags_' . $i;
        $m_key = '_kotowaza_tags_str_' . $i;
        if (!empty($spec_data[$k_key])) {
            update_post_meta($post_id, $m_key, ' ' . implode(' ', $spec_data[$k_key]) . ' ');
        } else {
            delete_post_meta($post_id, $m_key);
        }
    }

    // ★追加: バフ検索用メタデータの更新
    _update_buff_search_meta($post_id, $spec_data);

    // ▼▼▼ JSON保存前に不要な検索用タグ配列を削除 (軽量化) ▼▼▼
    $tags_to_remove = [
        'search_tags',
        'waza_search_tags',
        'sugo_search_tags',
        'kotowaza_search_tags',
        'trait_search_tags_1',
        'trait_search_tags_2',
        'trait_search_tags_blessing'
    ];
    // コトワザの凸別タグも削除
    for ($i = 0; $i <= 5; $i++) {
        $tags_to_remove[] = 'kotowaza_search_tags_' . $i;
    }

    foreach ($tags_to_remove as $tag_key) {
        if (isset($spec_data[$tag_key])) {
            unset($spec_data[$tag_key]);
        }
    }
    // ▲▲▲ 削除ここまで ▲▲▲

    // 修正後（エラーを無視して無理やりエンコードし、エラーメッセージを確認する）
    $json_output = wp_slash(json_encode($spec_data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    if ($json_output === false) {
        // ログにエラーを出す（WP_DEBUGがONの場合）
        error_log('JSON Encode Error for Post ' . $post_id . ': ' . json_last_error_msg());
    }
    update_post_meta($post_id, '_spec_json', $json_output);
}


// =================================================================
//  【ヘルパー】火力指数 計算ロジック (全条件ON版)
// =================================================================
function _calculate_firepower_index($data)
{
    // 1. 基礎ATK (計算済みのLv120数値を優先使用)
    $base_atk = (float)($data['_val_120_atk'] ?? $data['atk'] ?? 0);
    // 2. 静的補正 (とくせいでデフォルトONのもの)
    $corr_atk = 0;
    $corr_dmg = 0;
    if (!empty($data['corrections']['details'])) {
        foreach ($data['corrections']['details'] as $detail) {
            // defaultがtrueのものだけ合算
            if (!empty($detail['default']) && $detail['default'] === true) {
                if (($detail['category'] ?? '') === 'atk') $corr_atk += (float)$detail['value'];
                elseif (($detail['category'] ?? '') === 'damage') $corr_dmg += (float)$detail['value'];
            }
        }
    }

    // 3. スキル選択 (すごわざ優先 > わざ)
    $timeline = [];
    // 3. スキル選択 (すごわざの最初のバリエーションを優先 > わざ)
    $timeline = [];
    if (!empty($data['sugowaza']) && !empty($data['sugowaza']['variations'][0]['timeline'])) {
        $timeline = $data['sugowaza']['variations'][0]['timeline'];
    } elseif (!empty($data['waza']) && !empty($data['waza']['variations'][0]['timeline'])) {
        $timeline = $data['waza']['variations'][0]['timeline'];
    }
    if (empty($timeline)) return 0;

    // 4. タイムライン計算
    $total_damage = 0;
    $buff_atk = 0;     // バフ段階
    $debuff_def = 0;   // デバフ段階

    $factor_atk = 1 + ($corr_atk / 100);
    $factor_dmg = 1 + ($corr_dmg / 100);

    foreach ($timeline as $action) {
        // ★条件(cond)の有無に関わらず、すべて加算する (全発動前提)

        $type = $action['type'] ?? '';
        $target = $action['target'] ?? '';
        $val = (float)($action['value'] ?? 0);
        $amt = (int)($action['amount'] ?? 0);

        // バフ・デバフの蓄積
        if (strpos($type, 'buff') !== false || $type === 'battle_field') {
            // 攻撃バフ (敵以外)
            if ((strpos($type, 'atk_buff') !== false || $type === 'battle_field') && strpos($target, 'oppo') === false) {
                $buff_atk += $amt;
            }
            // 防御デバフ
            if (strpos($type, 'def_debuff') !== false) {
                $debuff_def += $amt;
            }
        }

        // 攻撃ダメージ計算
        if (strpos($type, 'attack') !== false || $type === 'command') {
            $hits = !empty($action['hit_count']) ? (int)$action['hit_count'] : 1;
            $last = !empty($action['value_last']) ? (float)$action['value_last'] : 0;

            // 倍率計算 (連撃対応)
            // DONEカラフル攻撃の挙動がおかしい
            // わざ保存側でhitcountを作った。
            $mag = $val * $hits;
            if ($last > 0 && $hits > 1) {
                $mag = ($val * ($hits - 1)) + $last;
            }

            // 補正適用
            $rate_buff = 1 + ($buff_atk * 0.25);
            $rate_debuff = 1 + ($debuff_def * 0.1);

            // Step計算
            $step_dmg = $base_atk * $factor_atk * $factor_dmg * $rate_buff * $rate_debuff * $mag;
            $total_damage += $step_dmg;
        }
    }

    return floor($total_damage);
}


// =================================================================
//  【ヘルパー】補正値計算 & カテゴリ振り分け
// =================================================================
function _calculate_correction_values($data)
{
    $result = [
        'leader_atk_max' => 0,
        'leader_hp_max'  => 0,
        'self_buff'      => 0,
    ];
    $details = [];

    $get_cond_text = function ($cond_loop, $is_leader = false) {
        if (empty($cond_loop)) return '';
        $parts = [];
        foreach ($cond_loop as $c) {
            $type = $is_leader ? ($c['ls_cond_type'] ?? ($c['type'] ?? '')) : ($c['condition_type'] ?? ($c['type'] ?? ''));
            $val  = $is_leader ? ($c['ls_cond_val'] ?? ($c['val'] ?? '')) : ($c['condition_value'] ?? ($c['val'] ?? ''));

            if (is_array($type)) $type = $type['value'] ?? '';
            if (is_array($val))  $val  = implode(',', $val);

            if ($type === 'hpcond' || ($is_leader && $type === 'hp')) {
                $detail = $c['hp_cond_detail'] ?? ($c['detail'] ?? 'more');
                $mark = ($detail === 'less') ? '以下' : '以上';
                if ($val) $parts[] = "HP{$val}%{$mark}";
            } elseif ($type === 'comb' || $type === 'combo') {
                if ($val) $parts[] = "{$val}コンボ";
            } elseif ($type === 'moji_count') {
                if ($val) $parts[] = "{$val}文字";
            } elseif ($type === 'char') {
                if ($val) $parts[] = "文字:{$val}";
            } elseif ($type === 'theme') {
                if ($val) $parts[] = "テーマ:{$val}";
            } elseif ($type === 'field') $parts[] = "フィールド中"; //TODOフィールドか単体単発か上限解放の火力指数計算がおかしい
            elseif ($type === 'deck_attr' || $type === 'attr') $parts[] = "デッキ条件";
            elseif ($type === 'deck_species' || $type === 'species') $parts[] = "デッキ条件";
            elseif ($type === 'attacked') $parts[] = "被ダメージ";
        }
        return implode(' & ', $parts);
    };

    if (!empty($data['leader'])) {
        $max_atk = 0;
        foreach ($data['leader'] as $pattern) {
            $current_atk = 0;
            $current_hp = 0;
            if (!empty($pattern['corrections'])) {
                foreach ($pattern['corrections'] as $c) {
                    if ($c['param'] === 'atk') $current_atk += (float)$c['value'];
                }
            }
            if ($current_atk > $max_atk) $max_atk = $current_atk;
        }
        $result['leader_atk_max'] = $max_atk;

        foreach ($data['leader'] as $idx => $pattern) {
            $p_atk = 0;
            $raw_conds = [];
            if (!empty($pattern['conditions_raw'])) {
                foreach ($pattern['conditions_raw'] as $cr) {
                    if (!empty($cr['ls_cond_loop'])) $raw_conds = array_merge($raw_conds, $cr['ls_cond_loop']);
                }
            }
            $cond_text = $get_cond_text($raw_conds, true);
            if (!$cond_text) $cond_text = "無条件";

            if (!empty($pattern['corrections'])) {
                foreach ($pattern['corrections'] as $c) {
                    if ($c['param'] === 'atk') $p_atk += (float)$c['value'];
                }
            }
            if ($p_atk > 0) {
                $details[] = [
                    'group'    => 'leader',
                    'category' => 'atk',
                    'label'    => "Lとくせい",
                    'cond'     => $cond_text,
                    'value'    => $p_atk,
                    'default'  => false
                ];
            }
        }
    }

    if (!empty($data['traits'])) {
        foreach ($data['traits'] as $t) {
            $type = $t['type'] ?? '';
            $sub  = $t['sub_type'] ?? '';
            $val  = (float)($t['value'] ?? 0);

            if ($val <= 0) continue;

            $cond_loop = $t['conditions'] ?? [];
            $cond_text = $get_cond_text($cond_loop, false);
            $is_unconditional = (empty($cond_text) || $cond_text === '');

            if ($type === 'status_up' && $sub === 'atk') {
                if ($is_unconditional) $result['self_buff'] += $val;
                $details[] = [
                    'group'    => 'passive',
                    'category' => 'atk',
                    'label'    => 'とくせい: ATK UP',
                    'cond'     => $is_unconditional ? '常時' : $cond_text,
                    'value'    => $val,
                    'default'  => true
                ];
            } elseif ($type === 'damage_correction' && $sub === 'oneself') {
                $details[] = [
                    'group'    => 'passive',
                    'category' => 'damage',
                    'label'    => '自身の威力UP',
                    'cond'     => $is_unconditional ? '常時' : $cond_text,
                    'value'    => $val,
                    'default'  => true
                ];
            } elseif ($type === 'damage_correction' && $sub === 'killer' && !empty($t['target_info'])) {
                $info = $t['target_info'];
                $target_name = '';
                if (!empty($info['species'])) $target_name = implode(',', $info['species']) . 'キラー';
                elseif (!empty($info['attr'])) $target_name = implode(',', $info['attr']) . 'キラー';
                elseif (!empty($info['condition'])) $target_name = '状態異常キラー';

                if ($target_name) {
                    $details[] = [
                        'group'    => 'killer',
                        'category' => 'damage',
                        'label'    => $target_name,
                        'cond'     => $cond_text ? $cond_text : '対象の敵',
                        'value'    => $val,
                        'default'  => false
                    ];
                }
            }
        }
    }

    $result['details'] = $details;
    return $result;
}

// =================================================================
//  【内部ヘルパー】とくせい解析 (決定版：通常とくせいはLvなし)
// =================================================================
function _parse_trait_loop_to_data($trait_loop, $is_blessing = false)
{
    if (empty($trait_loop) || !is_array($trait_loop)) return [];

    $data = [];
    foreach ($trait_loop as $t) {
        $parsed = [];

        // --- 基本情報 ---
        $type_raw = $t['trait_type'] ?? '';
        $type = is_array($type_raw) ? ($type_raw['value'] ?? '') : $type_raw;
        $parsed['type'] = $type;

        $val = 0;

        // --- A. 祝福とくせいの場合 (levels配列を作成) ---
        if ($is_blessing) {
            $parsed['levels'] = []; // 祝福のみlevelsを持つ

            // 1. ポイントパターンの決定
            $pt_pattern = $t['pt_pattern'] ?? 'default';
            $points = [];

            if ($pt_pattern === 'default') {
                $points = [1, 2, 3, 4, 5, 7, 9, 12, 15, 20];
            } elseif ($pt_pattern === 'single') {
                $raw_pt = $t['need_point'] ?? '';
                $points = [$raw_pt];
            } elseif ($pt_pattern === 'csv') {
                $raw_pt = $t['need_point'] ?? '';
                $points = $raw_pt !== '' ? array_map('trim', explode(',', $raw_pt)) : [];
            }

            // 2. 値パターンの決定と計算
            $values = [];
            $lv_count = count($points);

            if ($pt_pattern === 'single') {
                $v = $t['blessing_value'] ?? 0;
                $values[] = (float)$v;
            } else {
                $calc_type = $t['blessing_level_value'] ?? 'csv';

                if ($calc_type === 'min_max') {
                    $min = (float)($t['min_value'] ?? 0);
                    $max = (float)($t['max_value'] ?? 0);
                    $gaps = $lv_count - 1;

                    if ($gaps > 0) {
                        $diff = $max - $min;
                        if ($diff >= 0 && ($diff * 100) % ($gaps * 100) == 0) {
                            $step = $diff / $gaps;
                            for ($k = 0; $k < $lv_count; $k++) $values[] = $min + ($step * $k);
                        } else {
                            $cur = $min;
                            $base_step = floor($diff / $gaps);
                            $remainder = (int)$diff % $gaps;

                            $values[] = $cur;
                            for ($k = 0; $k < $gaps; $k++) {
                                $step = $base_step + ($k < $remainder ? 1 : 0);
                                $cur += $step;
                                $values[] = $cur;
                            }
                        }
                    } else {
                        $values[] = $min;
                    }
                } else {
                    $raw_val = $t['blessing_value'] ?? '';
                    if ($raw_val === '200!') {
                        $raw_val = '200,225,250,275,300,330,360,390,420,450';
                    }
                    $val_arr = $raw_val !== '' ? array_map('trim', explode(',', $raw_val)) : [];
                    foreach ($val_arr as $v) $values[] = (float)$v;
                }
            }

            // 3. データ結合
            foreach ($points as $k => $pt) {
                $v = isset($values[$k]) ? $values[$k] : 0;
                $lv_num = ($pt_pattern === 'single') ? 1 : ($k + 1);

                $parsed['levels'][] = [
                    'lv'    => $lv_num,
                    'value' => $v,
                    'point' => (int)$pt
                ];
            }

            // 代表値は Lv1 の値 (前回のご指定通り)
            if (!empty($parsed['levels'])) {
                $first = $parsed['levels'][0];
                $val = $first['value'];
            }
        }
        // --- B. 通常とくせいの場合 (levelsは作らない) ---
        else {
            if (isset($t['trait_rate']) && $t['trait_rate'] !== '') $val = (float)$t['trait_rate'];
            elseif (isset($t['value']) && $t['value'] !== '') $val = (float)$t['value'];
        }

        $parsed['value'] = $val;


        // --- サブタイプ ---
        $sub = '';
        if ($type && isset($t[$type])) {
            $sub_raw = $t[$type];
            if (is_object($sub_raw)) {
                $sub = $sub_raw->slug;
            } elseif (is_array($sub_raw)) {
                $sub = $sub_raw['value'] ?? ($sub_raw[0] ?? '');
            } else {
                $sub = $sub_raw;
            }
        }
        $parsed['sub_type'] = $sub;

        // --- whose_trait ---
        $whose_raw = $t['whose_trait'] ?? 'oneself';
        $whose = '';
        if (is_array($whose_raw)) {
            $whose = $whose_raw['value'] ?? ($whose_raw[0] ?? 'oneself');
        } elseif (is_object($whose_raw)) {
            $whose = $whose_raw->slug;
        } else {
            $whose = $whose_raw;
        }
        if (empty($whose)) $whose = 'oneself';
        $parsed['whose'] = $whose;


        // --- タイプ別 詳細パラメータ ---

        // 1. Gimmick
        if ($type === 'gimmick') {
            if (!empty($t['gimmick']) && is_object($t['gimmick'])) {
                $parsed['gimmick_slug'] = $t['gimmick']->slug;
                if (empty($parsed['sub_type'])) $parsed['sub_type'] = $t['gimmick']->slug;
            }
            if (!empty($t['super_gimmick_healing'])) {
                $parsed['super_heal'] = (int)$t['super_gimmick_healing'];
            }
        }

        // 2. Damage Correction
        elseif ($type === 'damage_correction') {
            if (isset($t['limit_break_rate']) && $t['limit_break_rate'] !== '') {
                $parsed['limit_break'] = (int)$t['limit_break_rate'];
            }
        }

        // 3. Status Up
        elseif ($type === 'status_up') {
            if ($sub === 'resistance' && !empty($t['resistance'])) {
                $parsed['resist_status'] = $t['resistance'];
            }
        }

        // 4. Draw/On Play Eff
        elseif ($type === 'draw_eff' || $type === 'on_play_eff') {
            if (isset($t['turn_count']) && $t['turn_count'] !== '') {
                $parsed['turn'] = (int)$t['turn_count'];
            }
            if ($sub === 'status_healing' && !empty($t['resistance'])) {
                $parsed['heal_status'] = $t['resistance'];
            }
        }

        // 5. Core Gimmick
        elseif ($type === 'core_gimmick') {
            $core_type = $t['core_gimmick'] ?? '';
            if (empty($parsed['sub_type'])) $parsed['sub_type'] = $core_type;

            if ($core_type === 'super_attack_core') {
                $parsed['super_combos'] = [
                    '1st' => (int)($t['need_combo_first'] ?? 0),
                    '2nd' => (int)($t['need_combo_second'] ?? 0),
                    '3rd' => (int)($t['need_combo_third'] ?? 0),
                    '4th' => (int)($t['need_combo_forth'] ?? 0)
                ];
            } else {
                if (!empty($t['need_combo'])) {
                    $parsed['need_combo'] = (int)$t['need_combo'];
                }
            }
        }

        // 6. After Attack
        elseif ($type === 'after_attack') {
            if (isset($t['turn_count']) && $t['turn_count'] !== '') {
                $parsed['turn'] = (int)$t['turn_count'];
            }
        }

        // 7. New Traits
        elseif ($type === 'new_traits') {
            if ($sub === 'resonance') {
                if (isset($t['limit_break_rate'])) $parsed['limit_break'] = (int)$t['limit_break_rate'];
                if (!empty($t['resonance_crit_rate'])) $parsed['crit_rate'] = (float)$t['resonance_crit_rate'];
                if (!empty($t['resonance_crit_damage'])) $parsed['crit_damage'] = (float)$t['resonance_crit_damage'];
            }
            if ($sub === 'see_through' && isset($t['limit_break_rate'])) {
                $parsed['limit_break'] = (int)$t['limit_break_rate'];
            }
        }

        // 8. Mode Shift
        elseif ($type === 'mode_shift') {
            $parsed['shift_relation'] = $t['relation_ship'] ?? '';
            $forms = $t['related_form'] ?? [];
            if ($forms) {
                $parsed['related_ids'] = [];
                foreach ($forms as $f) {
                    if (is_object($f)) $parsed['related_ids'][] = $f->ID;
                }
            }
        }

        // 9. Other Traits
        elseif ($type === 'other_traits') {
            if ($sub === 'other') {
                $parsed['other_text'] = $t['other_text'] ?? '';
            }
            if (isset($t['limit_break_rate']) && $t['limit_break_rate'] !== '') {
                $parsed['limit_break'] = (int)$t['limit_break_rate'];
            }
        }

        // --- 共通: per_unit, targets, conditions は既存通り ---
        if (!empty($t['per_unit_tf'])) {
            $parsed['per_unit'] = true;
            $parsed['unit_target'] = [];
            if (!empty($t['deck_ally_field_group'])) {
                $grp = $t['deck_ally_field_group'];
                if (!empty($grp['target_attr'])) {
                    foreach ($grp['target_attr'] as $term) if (is_object($term)) $parsed['unit_target']['attr'][] = $term->slug;
                }
                if (!empty($grp['target_species'])) {
                    foreach ($grp['target_species'] as $term) if (is_object($term)) $parsed['unit_target']['species'][] = $term->slug;
                }
            }
        }

        $targets = $t['target_field_group'] ?? [];
        if (!empty($targets)) {
            $parsed['target_info'] = [
                'type' => '',
                'attr' => [],
                'species' => [],
                'group' => [],
                'condition' => ''
            ];
            $tgt_type = $targets['target_type'] ?? '';
            $parsed['target_info']['type'] = is_array($tgt_type) ? ($tgt_type['value'] ?? '') : $tgt_type;

            if (!empty($targets['target_attr']) && is_array($targets['target_attr'])) {
                foreach ($targets['target_attr'] as $term) if (is_object($term)) $parsed['target_info']['attr'][] = $term->slug;
            }
            if (!empty($targets['target_species']) && is_array($targets['target_species'])) {
                foreach ($targets['target_species'] as $term) if (is_object($term)) $parsed['target_info']['species'][] = $term->slug;
            }
            if (!empty($targets['target_group']) && is_array($targets['target_group'])) {
                foreach ($targets['target_group'] as $term) if (is_object($term)) $parsed['target_info']['group'][] = $term->slug;
            }

            $tgt_stat = $targets['target_status'] ?? '';
            $parsed['target_info']['condition'] = is_array($tgt_stat) ? ($tgt_stat['value'] ?? '') : $tgt_stat;
        }

        $cond_loop = $t['condition_type_loop'] ?? [];
        $parsed['conditions'] = _parse_activation_condition($cond_loop);

        $data[] = $parsed;
    }
    return $data;
}

// =================================================================
//  ★追加: バフ検索用メタデータ保存関数
// =================================================================
function _update_buff_search_meta($post_id, $data)
{
    $attributes = ['fire', 'water', 'wood', 'light', 'dark', 'heaven', 'void'];

    // 最大バフ段階を保持する配列 (初期値0)
    $skill_buffs = array_fill_keys($attributes, 0); // わざ・すごわざ・コトワザ
    $trait_buffs = array_fill_keys($attributes, 0); // 実体化時とくせい

    // --- 1. スキル (Waza, Sugo, Koto) の解析 ---
    $skill_sources = [];
    if (!empty($data['waza']['variations'])) $skill_sources[] = $data['waza']['variations'];
    if (!empty($data['sugowaza']['variations'])) $skill_sources[] = $data['sugowaza']['variations'];
    if (!empty($data['kotowaza'])) {
        foreach ($data['kotowaza'] as $k) {
            if (!empty($k['variations'])) $skill_sources[] = $k['variations'];
        }
    }

    foreach ($skill_sources as $variations) {
        foreach ($variations as $var) {
            if (empty($var['timeline'])) continue;
            foreach ($var['timeline'] as $action) {
                // ATKバフのみ対象 (typeに 'atk_buff' を含む)
                if (strpos($action['type'], 'atk_buff') === false) continue;

                $amount = (int)($action['amount'] ?? 0);
                if ($amount <= 0) continue;

                $targets = [];
                $tgt_key = $action['target'] ?? '';
                $detail  = $action['target_detail'] ?? [];

                // 対象属性の判定
                if (in_array($tgt_key, ['all_ally', 'hand_ally', 'limited_hand', 'limited_ally'])) {
                    if (!empty($detail['attr'])) {
                        // 特定属性指定がある場合
                        $targets = $detail['attr'];
                    } else {
                        // 属性指定がない場合（種族指定や無条件）は「全属性」として扱う
                        $targets = $attributes;
                    }
                } elseif ($tgt_key === 'self' || $tgt_key === 'oneself') {
                    // 自身のみ -> 自身の属性
                    if (!empty($data['attribute'])) $targets = [$data['attribute']];
                }

                // 各属性ごとに最大値を更新
                foreach ($targets as $attr) {
                    if (isset($skill_buffs[$attr]) && $amount > $skill_buffs[$attr]) {
                        $skill_buffs[$attr] = $amount;
                    }
                }
            }
        }
    }

    // --- 2. とくせい (実体化時 ATKバフ) の解析 ---
    if (!empty($data['traits'])) {
        foreach ($data['traits'] as $t) {
            if (($t['type'] ?? '') === 'on_play_eff' && ($t['sub_type'] ?? '') === 'atk_buff') {
                $val = (int)($t['value'] ?? 0);
                if ($val <= 0) continue;

                $targets = [];
                $info = $t['target_info'] ?? [];

                if (!empty($info['attr'])) {
                    $targets = $info['attr'];
                } else {
                    // 属性指定なし
                    if (($t['whose'] ?? '') === 'oneself') {
                        if (!empty($data['attribute'])) $targets = [$data['attribute']];
                    } else {
                        // 味方全体など
                        $targets = $attributes;
                    }
                }

                foreach ($targets as $attr) {
                    if (isset($trait_buffs[$attr]) && $val > $trait_buffs[$attr]) {
                        $trait_buffs[$attr] = $val;
                    }
                }
            }
        }
    }

    // --- 3. メタデータへの保存 ---
    // キー形式: _sb_skill_{attr} / _sb_trait_{attr}
    foreach ($attributes as $attr) {
        // スキル
        $key_s = "_sb_skill_{$attr}";
        if ($skill_buffs[$attr] > 0) update_post_meta($post_id, $key_s, $skill_buffs[$attr]);
        else delete_post_meta($post_id, $key_s);

        // とくせい
        $key_t = "_sb_trait_{$attr}";
        if ($trait_buffs[$attr] > 0) update_post_meta($post_id, $key_t, $trait_buffs[$attr]);
        else delete_post_meta($post_id, $key_t);
    }
}

// =================================================================
//  【内部ヘルパー】条件解析
// =================================================================
function _parse_activation_condition($cond_data)
{
    if (empty($cond_data) || !is_array($cond_data)) return [];

    $parsed = [];
    $is_sugo_pattern = isset($cond_data[0]['sugo_cond_loop']);

    if ($is_sugo_pattern) {
        foreach ($cond_data as $pattern) {
            if (!empty($pattern['sugo_cond_loop'])) {
                foreach ($pattern['sugo_cond_loop'] as $c) {
                    $parsed[] = [
                        'type' => $c['sugo_cond_type'],
                        'val'  => $c['sugo_cond_val'],
                        'condition_type' => $c['sugo_cond_type'],
                        'condition_value' => $c['sugo_cond_val']
                    ];
                }
            }
        }
    } else {
        foreach ($cond_data as $c) {
            $type = $c['condition_type'] ?? ($c['ls_cond_type'] ?? ($c['type'] ?? ''));
            $val  = $c['condition_value'] ?? ($c['ls_cond_val'] ?? ($c['val'] ?? ''));

            if (is_array($type)) $type = $type['value'] ?? '';
            if (is_array($val))  $val  = implode(',', $val);

            $parsed[] = [
                'type' => $type,
                'val'  => $val,
                'condition_type' => $type,
                'condition_value' => $val,
                'detail' => $c['hp_cond_detail'] ?? ($c['detail'] ?? '')
            ];
        }
    }
    return $parsed;
}

// =================================================================
//  【内部ヘルパー】スキル解析 (全タイプ対応・完全版)
// =================================================================
function _parse_skill_groups_to_data($groups, $shift_type = 'none')
{
    if (empty($groups)) return [];

    $variations = [];

    foreach ($groups as $g) {
        $variant = [];

        // 1. シフト条件の値を取得
        $shift_val = null;
        if ($shift_type === 'random') {
            $shift_val = $g['random_count'] ?? '';
        } elseif ($shift_type === 'attr') {
            $terms = $g['sugo_shift_attr'] ?? ($g['kotowaza_shift_attr'] ?? null);
            if ($terms && is_array($terms)) {
                $slugs = [];
                foreach ($terms as $t) if (is_object($t)) $slugs[] = $t->slug;
                $shift_val = $slugs;
            }
        } elseif ($shift_type === 'moji') {
            $terms = $g['sugo_shift_moji'] ?? ($g['kotowaza_shift_moji'] ?? null);
            if ($terms && is_array($terms)) {
                $chars = [];
                foreach ($terms as $t) if (is_object($t)) $chars[] = $t->name;
                $shift_val = $chars;
            }
        } elseif ($shift_type === 'attacked') {
            $shift_val = $g['sugo_shift_attacked'] ?? ($g['kotowaza_shift_attacked'] ?? 0);
        }
        $variant['shift_value'] = $shift_val;

        // 2. タイムライン解析
        $timeline = [];
        $details = $g['sugo_detail_loop'] ?? ($g['waza_detail_loop'] ?? ($g['kotowaza_detail_loop'] ?? []));

        // グループ全体の追加条件
        $cond_type = $g['condition_type'] ?? 'none';
        $cond_val  = $g['condition_value'] ?? null;
        $cond_detail = [];
        if ($cond_type === 'attr' && !empty($g['condition_attr'])) {
            $cond_detail['attr'] = wp_list_pluck($g['condition_attr'], 'slug');
        } elseif ($cond_type === 'group' && !empty($g['condition_affiliation'])) {
            $cond_detail['group'] = wp_list_pluck($g['condition_affiliation'], 'slug');
        }

        if (!empty($details)) {
            foreach ($details as $d) {
                $action = [];

                // --- 基本タイプ ---
                $type_raw = $d['waza_type'] ?? '';
                $action['type'] = is_array($type_raw) ? ($type_raw['value'] ?? '') : $type_raw;

                // --- ターゲット ---
                $target_raw = $d['waza_target'] ?? '';
                $action['target'] = is_array($target_raw) ? ($target_raw['value'] ?? '') : $target_raw;

                // --- ターゲット詳細 ---
                if (!empty($d['waza_target_detail']) && $d['waza_target_detail'] !== 'none') {
                    $action['target_detail'] = [
                        'type' => $d['waza_target_detail'],
                        'attr' => !empty($d['target_detail_attr']) ? wp_list_pluck($d['target_detail_attr'], 'slug') : [],
                        'species' => !empty($d['target_detail_species']) ? wp_list_pluck($d['target_detail_species'], 'slug') : [],
                        'group' => !empty($d['target_detail_group']) ? wp_list_pluck($d['target_detail_group'], 'slug') : [],
                    ];
                }

                // --- 数値・倍率 ---
                $action['value'] = isset($d['waza_value']) && $d['waza_value'] !== '' ? (float)$d['waza_value'] : 0;
                $action['value_last'] = isset($d['waza_value_last']) ? (float)$d['waza_value_last'] : 0;
                $action['hit_count'] = isset($d['hit_count']) ? (int)$d['hit_count'] : 1;

                // --- 特殊項目 ---

                // A. ターン数
                if (isset($d['turn_count']) && $d['turn_count'] !== '') {
                    $action['turn'] = (int)$d['turn_count'];
                }

                // B. バフ/デバフ量
                if (strpos($action['type'], 'buff') !== false || strpos($action['type'], 'debuff') !== false) {
                    $action['amount'] = (int)$action['value'];
                }

                // C. フィールド (battle_field)
                if ($action['type'] === 'battle_field' && !empty($d['battle_field_loop'])) {
                    $fields = [];
                    foreach ($d['battle_field_loop'] as $f) {
                        $fields[] = [
                            'target' => $f['battle_field_target'] ?? '',
                            'attr'   => !empty($f['battle_field_attr']) ? wp_list_pluck($f['battle_field_attr'], 'slug') : [],
                            'species' => !empty($f['battle_field_species']) ? wp_list_pluck($f['battle_field_species'], 'slug') : [],
                            'group'  => !empty($f['battle_field_affiliation']) ? wp_list_pluck($f['battle_field_affiliation'], 'slug') : [],
                            'moji'   => !empty($f['battle_field_moji']) ? wp_list_pluck($f['battle_field_moji'], 'name') : [],
                            'value'  => (float)($f['battle_field_value'] ?? 0)
                        ];
                    }
                    $action['fields'] = $fields;
                    if (!empty($fields[0]['value'])) {
                        $action['value'] = $fields[0]['value'];
                        $action['amount'] = (int)$fields[0]['value'];
                    }
                }

                // D. 重圧
                if ($action['type'] === 'pressure') {
                    $action['debuff_count'] = isset($d['pressure_debuff_count']) ? $d['pressure_debuff_count'] : '';
                }

                // E. 状態異常バリア
                if ($action['type'] === 'status_barrier') {
                    $action['barrier_status'] = $d['target_status'] ?? 'all';
                }

                // F. トークン
                if ($action['type'] === 'token' && !empty($d['related_token'])) {
                    $token_obj = is_array($d['related_token']) ? ($d['related_token'][0] ?? null) : $d['related_token'];
                    if ($token_obj && is_object($token_obj)) {
                        $action['token_id'] = $token_obj->ID;
                        $action['token_name'] = $token_obj->post_title;
                    }
                }

                // G. 攻撃タイプ・属性・連携
                if (strpos($action['type'], 'attack') !== false || $action['type'] === 'command') {
                    $atk_type_raw = $d['attack_type'] ?? 'normal';
                    $atk_type_val = is_array($atk_type_raw) ? ($atk_type_raw['value'] ?? 'normal') : $atk_type_raw;
                    if (is_array($atk_type_val)) $atk_type_val = $atk_type_val[0] ?? 'normal';
                    $action['attack_type'] = $atk_type_val;

                    // 攻撃属性
                    $action['element'] = '';
                    $el = $d['attack_attr'] ?? null;
                    if (is_object($el)) $action['element'] = $el->slug;

                    // 連携対象 (coop_target)
                    if ($action['type'] === 'coop_attack') {
                        $ct = $d['coop_target'] ?? null;
                        if (is_object($ct)) $action['coop_target'] = $ct->slug;
                    }

                    // ★ I. 単体単発攻撃フラグ (is_single_shot)
                    // 条件: 攻撃系(attack/coop) かつ 敵単体(single_oppo) かつ (連携攻撃 OR Hit数1)
                    if (strpos($action['type'], 'attack') !== false && $action['target'] === 'single_oppo') {
                        // 連携攻撃はHit数設定がなくても単発扱い
                        if ($action['type'] === 'coop_attack' || $action['hit_count'] === 1) {
                            $action['is_single_shot'] = true;
                        }
                    }
                }

                // H. カラフル攻撃・全属性有利
                if ($action['type'] === 'colorfull_attack') {
                    $action['color_sequence'] = !empty($d['colorfull_attack_attr']) ? wp_list_pluck($d['colorfull_attack_attr'], 'slug') : [];
                    $action['hit_count'] = count($action['color_sequence']);
                }
                $action['omni_advantage'] = !empty($d['omni_advantage']); // 全属性有利


                // --- 条件オブジェクト ---
                if ($cond_type !== 'none') {
                    $action['cond'] = [
                        'type' => $cond_type,
                        'val' => $cond_val,
                        'detail' => $cond_detail
                    ];
                } else {
                    $action['cond'] = null;
                }

                $timeline[] = $action;
            }
        }
        $variant['timeline'] = $timeline;
        $variations[] = $variant;
    }

    return $variations;
}

function _parse_leader_skill_data($loop)
{
    $data = [];
    foreach ($loop as $pattern) {
        $p = [
            'type' => $pattern['ls_type'] ?? 'fixed',
            'corrections' => [],
            'conditions_raw' => $pattern['ls_cond_pattern_loop'] ?? []
        ];
        if (!empty($pattern['ls_status_loop'])) {
            foreach ($pattern['ls_status_loop'] as $stat) {
                $p['corrections'][] = [
                    'param' => $stat['ls_status'],
                    'value' => (float) ($stat['rate'] ?? 0)
                ];
            }
        }
        $data[] = $p;
    }
    return $data;
}
