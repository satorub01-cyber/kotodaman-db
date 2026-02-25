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
    $rarity_term = get_field('rarity', $post_id);
    $rarity = $rarity_term ? $rarity_term->slug : 'none';
    $taxonomy = 'rarity';
    if (!is_numeric($rarity)) {
        $rarity_detail = $rarity;
        $rarity = (int)6;
        // 1. 子タームのIDを取得
        if ($rarity_term && !is_wp_error($rarity_term)) {
            $child_term_id = $rarity_term->term_id;
            // 2. 「6」というスラッグを持つ親タームのIDを取得
            $parent_term = get_term_by('slug', '6', $taxonomy);

            if ($parent_term && !is_wp_error($parent_term)) {
                // 親(6)と子(詳細)の両方を配列にセット
                $term_ids = array((int)$parent_term->term_id, (int)$child_term_id);
                wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
            }
        }
    } else {
        $rarity_detail = 'none';
        $rarity = (int)$rarity;
    }
    // ★追加: Lv120なしフラグ
    $is_no_lv120 = get_field('no_lv120_flag', $post_id);
    if ($rarity <= 5) {
        $is_no_lv120 = true;
    }

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
    // ★追加: コトワザ倍率推定フラグ
    $is_koto_estimate = get_field('koto_magnification_estimate_tf', $post_id);


    // ▼▼▼ 3. 最終ステータスの決定 ▼▼▼

    // A. 「Lv.99 + 超化」の値 (全員共通)
    $val_99_hp_total  = $raw_99_hp + $chouka_hp;
    $val_99_atk_total = $raw_99_atk + $chouka_atk;

    // B. 「Lv.120」の値の決定ロジック
    if ($is_no_lv120) {
        // ★パターン1: 「Lv120なし」フラグがONの場合
        // ソートで不利にならないよう、Lv99の値を代入しておく
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
    $talent_rate = [
        'none' => 0.1,
        'special' => 0.07,
        'legend' => 0.05,
        'grand' => 0.05
    ];

    if (get_field('talent_status_auto_tf', $post_id)) {
        $talent_hp = (int)get_field('talent_hp', $post_id);
        $talent_atk = (int)get_field('talent_atk', $post_id);
    } else {
        $rate = $talent_rate[$rarity] ?? 0;
        $talent_hp = (int)floor($val_99_hp_total * $rate);
        $talent_atk = (int)floor($val_99_atk_total * $rate);
    }
    // 0. 基本情報の初期化
    $data = [
        'id'            => $post_id,
        'name'          => get_the_title($post_id),
        // ★保存用に計算結果を保持しておく
        '_val_99_hp'    => (int)$val_99_hp_total,
        '_val_99_atk'   => (int)$val_99_atk_total,
        '_val_120_hp'   => (int)$val_120_hp_total,
        '_val_120_atk'  => (int)$val_120_atk_total,
        'talent_hp'     => $talent_hp,
        'talent_atk'    => $talent_atk,
        'is_no_lv120'   => (bool)$is_no_lv120,
        'rarity'        => $rarity,
        'rarity_detail' => $rarity_detail,
        'release_date'  => '', // ★追加: 実装日用キー
        'attribute'     => '',
        'sub_attributes' => [],
        'species'       => '',
        'groups'        => [],
        'waza'          => null,
        'sugowaza'      => null,
        'kotowaza'      => [],
        'priority'      => 5,
        'traits'        => [],
        'trait1'        => [],
        'trait2'        => [],
        'blessing'      => [],
        'leader'        => null,
        'EX_skill'      => [],
        'charge_skill'  => [],
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
        'name_ruby'     => '',
        'cv'            => '',
        'acquisition'   => '', //入手場所
        'max_ls_hp'     => 0,
        'max_ls_atk'    => 0,
        'firepower_index' => 0,
        'is_estimate'   => (bool)$is_estimate,
        'is_koto_estimate' => (bool)$is_koto_estimate,
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
        foreach ($terms_group as $g) $data['groups'][] = [
            'slug' => $g->slug,
            'name' => $g->name
        ];
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
        $waza_target = $first_action['waza_target'] ?? '';

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
            } elseif (strpos($waza_target, 'single') !== false) {
                $data['priority'] = 5;
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
    $def_connector = ['い', 'う', 'ん'];
    $def_small_yuyo = ['ゅ', 'ょ'];
    $def_axis_i = ['あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ', 'が', 'ざ', 'だ', 'ば', 'ぱ', 'え', 'け', 'せ', 'て', 'ね', 'へ', 'め', 'れ', 'げ', 'ぜ', 'で', 'べ', 'ぺ', 'す', 'ず'];
    $def_axis_u = ['く', 'す', 'つ', 'ふ', 'ゆ', 'ぐ', 'ず', 'づ', 'ぶ', 'ぷ', 'お', 'こ', 'そ', 'と', 'の', 'ほ', 'も', 'よ', 'ろ', 'ご', 'ぞ', 'ど', 'ぼ', 'ぽ'];
    $def_axis_youon = ['き', 'し', 'ち', 'に', 'ひ', 'み', 'り', 'ぎ', 'じ', 'ぢ', 'び', 'ぴ', 'う', 'ゃ'];

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
                    if (strpos($type, 'attack') !== false || strpos($type, 'command') !== false) {
                        $target_tags[] = 'type_attack'; // 攻撃
                        if (strpos($type, 'attack') !== false) {
                            if ($target === 'single_oppo') {
                                if ($hit_count > 1) {
                                    $target_tags[] = 'type_attack_single_multi'; // 単体連撃
                                } else {
                                    $target_tags[] = 'type_attack_single'; // 単体単発
                                }
                            } elseif ($target === 'all_oppo') {
                                if ($hit_count > 1) {
                                    $target_tags[] = 'type_attack_all_multi'; // 全体連撃
                                } else {
                                    $target_tags[] = 'type_attack_all'; // 全体単発
                                }
                            } elseif ($target === 'random_oppo') {
                                $target_tags[] = 'type_attack_random'; // 乱打
                            }
                        }
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

    $gimmick_list = get_field('gimmick', $post_id);
    if ($gimmick_list && is_array($gimmick_list)) {
        foreach ($gimmick_list as $g_item) {
            // オブジェクトで返ってきた場合
            if (is_object($g_item) && isset($g_item->slug)) {
                $search_tags[] = 'gimmick_' . $g_item->slug;
            }
            // 配列で返ってきた場合（← 今回のエラーの原因はおそらくコレです）
            elseif (is_array($g_item) && isset($g_item['slug'])) {
                $search_tags[] = 'gimmick_' . $g_item['slug'];
            }
            // ID(数値)で返ってきた場合
            elseif (is_numeric($g_item)) {
                $g_term = get_term($g_item);
                if ($g_term && !is_wp_error($g_term)) {
                    $search_tags[] = 'gimmick_' . $g_term->slug;
                }
            }
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
            'type' => $group['multi_cond_type'] ?? 'enemy', // enemy(収束), moji(文字数)
            'rows' => []
        ];

        if (!empty($group['maltiplier_table'])) {
            foreach ($group['maltiplier_table'] as $row) {
                $r = [
                    'rate' => (float)($row['rate'] ?? 0),
                ];
                // DONEtypeが両方の時に対応するために[cond][enemy]と[cond][moji]にわける
                if ($scaling['type'] === 'enemy') {
                    $r['cond']['enemy'] = (int)($row['enemy_count'] ?? 1);
                } elseif ($scaling['type'] === 'moji') {
                    $r['cond']['moji'] = (int)($row['moji_count'] ?? 4);
                } elseif ($scaling['type'] === 'both') {
                    $r['cond']['enemy'] = (int)($row['enemy_count'] ?? 1);
                    $r['cond']['moji'] = (int)($row['moji_count'] ?? 4);
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
            'condition' => _parse_sugo_condition($sugo_cond_raw),
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
                'condition' => _parse_sugo_condition($cond),
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

            if (!empty($var['timelines'])) {
                foreach ($var['timelines'] as $action) {
                    $type   = $action['type'] ?? '';
                    $target = $action['target']['main'] ?? ''; // ★ターゲット判定に使用
                    $amount = (int)($action['value'] ?? 0);

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
                        if (strpos($target, 'oppo') !== false) {
                            $cur_debuff += $amount;
                        }
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
    $t1_name = get_field('first_trait_name', $post_id);
    $t2_name = get_field('second_trait_name', $post_id);
    $t1 = get_field('first_trait_loop', $post_id);
    $t2 = get_field('second_trait_loop', $post_id);
    if (!$t1 && !$t2) $t1 = get_field('trait_group', $post_id);

    $t1_data = $t1 ? _parse_trait_loop_to_data($t1) : [];
    $t2_data = $t2 ? _parse_trait_loop_to_data($t2) : [];

    $data['trait1'] = ['name' => $t1_name, 'contents' => $t1_data];
    $data['trait2'] = ['name' => $t2_name, 'contents' => $t2_data];
    $data['traits'] = array_merge($t1_data, $t2_data);

    // ▼▼▼ 修正: とくせいタグの付与 (give_trait 含む) ▼▼▼
    $collect_trait_tags = function ($traits, &$target_tags) {
        if (empty($traits)) return;
        foreach ($traits as $tr) {
            // 1. 他者付与タグ
            if (isset($tr['whose']) && $tr['whose'] !== 'self') {
                $target_tags[] = 'give_trait';
            }

            // 2. とくせいタイプ別タグ
            $t_type = $tr['type'] ?? '';
            // モードシフトは参照するフィールド名が特殊
            if ($t_type === 'mode_shift') {
                $t_sub = $tr['shift_relation'] ?? '';
                if (strpos($t_sub, 'transform') !== false) {
                    $t_sub = 'transform';
                }
            } else {
                $t_sub  = $tr['sub_type'] ?? '';
            }
            // 共鳴とクリティカル共鳴の区別
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

    // 7. EXスキル
    $data['EX_skill'] = _parse_ex_skill($post_id);
    // 8. チャージスキル
    $data['charge_skill'] = _parse_charge_skill($post_id);

    // ★計算用補正値の生成
    // $data['corrections'] = _calculate_correction_values($data);
    $data['corrections'] = ['details' => []]; // 代わりに空のデータを入れておく

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
    $firepower_index = 0;
    // $firepower_index = _calculate_firepower_index($spec_data);
    // 2. 計算結果を配列（$spec_data）に反映させる！
    $spec_data['firepower_index'] = $firepower_index;
    // ▼▼ソート用に外に出す
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
    if (isset($spec_data['talent_hp'])) {
        update_post_meta($post_id, 'talent_hp', $spec_data['talent_hp']);
        update_post_meta($post_id, 'talent_atk', $spec_data['talent_atk']);
        update_post_meta($post_id, 'max_hp', $spec_data['talent_hp'] + $spec_data['_val_120_hp']);
        update_post_meta($post_id, 'max_atk', $spec_data['talent_atk'] + $spec_data['_val_120_atk']);
    }

    // 属性インデックスの保存 (辞書にないものは 99 にして後ろへ)
    $attr_slug = $spec_data['attribute'] ?? '';
    $attr_idx  = $order_attr[$attr_slug] ?? 99;
    update_post_meta($post_id, '_sort_attr_index', $attr_idx);

    // 種族インデックスの保存
    $species_slug = $spec_data['species'] ?? '';
    $species_idx  = $order_species[$species_slug] ?? 99;
    update_post_meta($post_id, '_sort_species_index', $species_idx);

    // ★実装日 (impl_dateキーで YYYY-MM-DD 形式で保存)
    update_post_meta($post_id, 'impl_date', $spec_data['release_date']);

    // ★フリガナ (空ならタイトルを入れる)
    $ruby_val = $spec_data['name_ruby'] ? $spec_data['name_ruby'] : get_the_title($post_id);
    update_post_meta($post_id, 'name_ruby', $ruby_val);
    // ▲▲並べ替え用ここまで

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
    for ($i = 0; $i <= 4; $i++) {
        $k_key = 'kotowaza_search_tags_' . $i;
        $m_key = '_kotowaza_tags_str_' . $i;
        if (!empty($spec_data[$k_key])) {
            update_post_meta($post_id, $m_key, ' ' . implode(' ', $spec_data[$k_key]) . ' ');
        } else {
            delete_post_meta($post_id, $m_key);
        }
    }

    // ▼▼▼ JSON保存前に不要な検索用タグ配列を削除 (軽量化) ▼▼▼
    $tags_to_remove = [
        'traits',
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
    // TODOspec_json用のバリデーションを作る
    $json_output = wp_slash(json_encode($spec_data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    if ($json_output === false) {
        // ログにエラーを出す（WP_DEBUGがONの場合）
        error_log('JSON Encode Error for Post ' . $post_id . ': ' . json_last_error_msg());
    }
    update_post_meta($post_id, '_spec_json', $json_output);
}

// TODO火力指数計算見直し
// =================================================================
//  【ヘルパー】火力指数 計算ロジック (全条件ON版)
// =================================================================
/*
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
        $timeline = $data['sugowaza']['variations'][0]['timelines'];
    } elseif (!empty($data['waza']) && !empty($data['waza']['variations'][0]['timeline'])) {
        $timeline = $data['waza']['variations'][0]['timelines'];
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
        $target = $action['target']['main'] ?? '';
        $val = (float)($action['value'] ?? 0);
        $amt = (int)$val;

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
*/

// TODO補正値作成見直し
// =================================================================
//  【ヘルパー】補正値計算 & カテゴリ振り分け
// =================================================================
/*
function _calculate_correction_values($data)
{
    $result = [];
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
            $rate_type = $t['rate_type'] ?? '';


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
                    'rate-type' => $t['rate_type'] ?? '',
                    'default'  => true
                ];
            } elseif ($type === 'damage_correction' && $sub === 'oneself') {
                $details[] = [
                    'group'    => 'passive',
                    'category' => 'damage',
                    'label'    => '自身の威力UP',
                    'cond'     => $is_unconditional ? '常時' : $cond_text,
                    'value'    => $val,
                    'rate-type' => 'percentage',
                    'default'  => true
                ];
            } elseif ($type === 'damage_correction' && $sub === 'killer' && !empty($t['target_info'])) {
                $info = $t['target_info'];
                $target_name = '';
                if (!empty($info['species'])) $target_name = implode(',', $info['species']) . 'キラー';
                elseif (!empty($info['attr'])) $target_name = implode(',', $info['attr']) . 'キラー';

                if ($target_name) {
                    $details[] = [
                        'group'    => 'killer',
                        'category' => 'damage',
                        'label'    => $target_name,
                        'cond'     => $cond_text ? $cond_text : '対象の敵',
                        'value'    => $val,
                        'rate-type' => 'percentage',
                        'default'  => true
                    ];
                }
            }
        }
    }

    $result['details'] = $details;
    return $result;
}
*/
// 対象選択フィールドグループをspec_json用に崩す関数
function parse_target_group($grp)
{
    $result = ['type' => '', 'obj' => []];

    // $grp が空、または配列でない、または target_type が空の場合はすぐに返す
    if (empty($grp) || !is_array($grp) || empty($grp['target_type'])) {
        $result['obj'][] = ['slug' => '', 'name' => ''];
        return $result;
    }

    $raw_type = $grp['target_type'];
    $type = is_array($raw_type) ? ($raw_type['value'] ?? '') : $raw_type;

    // typeが空文字の場合も処理を中断する
    if (!$type) {
        $result['obj'][] = ['slug' => '', 'name' => ''];
        return $result;
    }

    $result['type'] = $type;
    if ($type !== 'self' && $type !== 'all') {
        if ($type === 'other') {
            $result['obj'][] = [
                'slug' => '',
                'name' => $grp['target_other'] ?? ''
            ];
        } else {
            $field_key = 'target_' . $type;
            // ここでキーが存在するかチェックを入れる（重要！）
            if (isset($grp[$field_key])) {
                $target_data = $grp[$field_key];
                if (!is_array($target_data)) $target_data = [$target_data];

                foreach ($target_data as $term) {
                    if (is_object($term) && !is_wp_error($term)) {
                        $result['obj'][] = [
                            'slug' => $term->slug,
                            'name' => $term->name
                        ];
                    }
                }
            }
        }
    }
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
        $parsed = [
            'type' => '',
            'sub_type' => '',
            'rate_type' => '',
            'value' => 0,
            'levels' => [],
            'whose' => 'self',
            'super_heal' => 0,
            'limit_break' => 0,
            'turn_count' => 1,
            'resist_status' => '',
            'target_info' => [],
            'per_unit' => false,
            'conditions' => [],
            'crit_rate' => 0,
            'crit_damage' => 0,
        ];

        // --- 基本情報 ---
        $type_raw = $t['trait_type'] ?? '';
        // タイプ
        $type = is_array($type_raw) ? ($type_raw['value'] ?? '') : $type_raw;
        $parsed['type'] = $type;
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
        $parsed['rate_type'] = $t['rate_type'] ?? '';
        // rate_typeの修正
        $rate_type_both = [
            'status',
            'draw_eff',
            'other_traits',
            'after_attack',
            'new_traits'
        ];
        $rate_type_fixed = [
            'core_gimmick',
            'on_play_eff',
        ];
        $trait_sub_type_both = [
            'atk',
            'hp',
            'healing'
        ];
        $trait_sub_type_fixed = [
            'atk_buff',
            'def_buff',
            'support',
            'see_through',
            'reflection'
        ];
        $is_both = false; // フラグを準備
        $is_fixed = false; // フラグを準備

        foreach ($rate_type_both as $type) {
            if (strpos($type_raw, $type) !== false) {
                $is_both = true; // 1つでも見つかったらフラグを立てる
                break;
            }
        }
        foreach ($rate_type_fixed as $type) {
            if (strpos($type_raw, $type) !== false) {
                $is_fixed = true; // 1つでも見つかったらフラグを立てる
                break;
            }
        }

        if (!$is_both) $is_both = in_array($sub, $trait_sub_type_both);
        if (!$is_fixed) $is_fixed = in_array($sub, $trait_sub_type_fixed);
        if (!$is_both) {
            if ($is_fixed) {
                $parsed['rate_type'] = 'fixed';
            } else {
                $parsed['rate_type'] = 'percentage';
            }
        }
        $val = 0;

        // valueの取得
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
                    'point' => (int)$pt,
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
        $parsed['value'] = (float)$val;

        // --- whose_trait ---
        $whose_raw = $t['whose_trait'] ?? 'self';
        if (!empty($whose_raw)) :
            $parsed['whose'] = parse_target_group($whose_raw);
        else :
            $parsed['whose'] = 'self';
        endif;

        // --- タイプ別 詳細パラメータ ---

        // 1. Gimmick
        if ($type === 'gimmick') {
            if (!empty($t['gimmick']) && is_object($t['gimmick'])) {
                if (empty($parsed['sub_type'])) $parsed['sub_type'] = $t['gimmick']->slug;
            }
            if (!empty($t['super_gimmick_healing'])) {
                $parsed['super_heal'] = (int)$t['super_gimmick_healing'];
            }
        }

        // 2. Damage Correction
        elseif ($type === 'damage_correction') {
            // limit_break_rateに値があれば入力＝単体単発にも対応済み
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
                if (!empty($t['resonance_crit_rate'])) {
                    $parsed['crit_rate'] = (float)$t['resonance_crit_rate'];
                    $parsed['sub_type'] = 'crit_resonance';
                }
                if (!empty($t['resonance_crit_damage'])) $parsed['crit_damage'] = (float)$t['resonance_crit_damage'];
            }
            if ($sub === 'see_through' || $sub === 'poke') {
                if (isset($t['limit_break_rate']) && $t['limit_break_rate'] !== '') {
                    $parsed['limit_break'] = (int)$t['limit_break_rate'];
                }
                if (isset($t['turn_count']) && $t['turn_count'] !== '') {
                    $parsed['turn'] = (int)$t['turn_count'];
                }
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
            $parsed['unit_target'] = [
                'type' => '',
                'attr' => [],
                'species' => [],
                'group' => [],
                'other' => ''
            ];
            if (!empty($t['deck_ally_field_group'])) {
                $parsed['unit_target'] = parse_target_group($t['deck_ally_field_group']);
            }
        }

        $targets = $t['target_field_group'] ?? [];
        $parsed['target_info'] = ['type' => '', 'attr' => [], 'species' => [], 'group' => [], 'other' => ''];
        if (!empty($targets)) {
            $parsed['target_info'] = parse_target_group($targets);
        }

        $cond_loop = $t['condition_type_loop'] ?? [];
        $parsed['conditions'] = _parse_trait_condition($cond_loop);

        $data[] = $parsed;
    }
    return $data;
}
function split_str_comma($val)
{
    // 1. 全角を半角に統一
    $val = str_replace('、', ',', $val);
    // 2. カンマで分割して各要素を掃除（カンマがなくても要素1つの配列になる）
    $val_array = array_map('trim', explode(',', $val));
    // 3. 空文字の除去（入力が空だった場合に [] になるようにする）
    $vals = array_filter($val_array, 'strlen');
    $vals = array_map(function ($item) {
        return is_numeric($item) ? (int)$item : $item;
    }, $vals);
    return $vals;
}
// =================================================================
//  【内部ヘルパー】とくせい条件/わざ追加条件解析
// =================================================================
function _parse_trait_condition($cond_data)
{
    if (empty($cond_data) || !is_array($cond_data)) return [];
    $parsed = [];
    foreach ($cond_data as $c) {
        // DONEリーダーとくせいは関数化せずリーダーとくせい解析関数に書く
        $type = $c['condition_type'] ?? '';
        $val  = $c['condition_value'] ?? '';
        $target_conds = ['type' => '', 'attr' => [], 'species' => [], 'group' => [], 'other' => ''];
        $vals = split_str_comma($val);
        $target_cond = ['attr', 'species', 'group', 'other'];
        if (in_array($c['condition_type'], $target_cond)) {
            $target_group = [
                'target_type' => $c['condition_type'] ?? '',
                'target_attr' => $c['condition_attr'] ?? [],
                'target_species' => $c['condition_species'] ?? [],
                'target_group' => $c['condition_group'] ?? [],
                'target_other' => $c['condition_other'] ?? ''
            ];
            $target_conds = parse_target_group($target_group);
        }
        $parsed[] = [
            'type' => $type,
            'val'  => array_values($vals),
            'hp_detail' => $c['hp_cond_detail'] ?? '',
            'cond_target' => $target_conds
        ];
    }

    return $parsed;
}

// すごわざ条件解析
function _parse_sugo_condition($cond_data)
{
    $parsed = [];

    // 1. データが空、または構造が異なる場合のデフォルト設定
    if (empty($cond_data) || !isset($cond_data[0]['sugo_cond_loop'])) {
        return [
            [
                'get_place'  => 'default',
                'need_point' => 0,
                'conditions' => [
                    ['type' => 'char_count', 'values' => [4]]
                ]
            ]
        ];
    }
    foreach ($cond_data as $pattern) {
        $conditions = [];
        if (!empty($pattern['sugo_cond_loop'])) {
            $conditions = [];
            foreach ($pattern['sugo_cond_loop'] as $c) {
                $values = $c['sugo_cond_val'] ? split_str_comma($c['sugo_cond_val']) : [(int)4];
                $conditions[] = [
                    'type' => $c['sugo_cond_type'] ?? 'char_count',
                    'values'  => $values
                ];
            }
        }
        $get_place = $pattern['get_place'] ?? 'default';
        $need_point = $pattern['need_blessing_point'] ?? 0;
        $parsed[] = [
            'get_place' => $get_place,
            'need_point' => $need_point,
            'conditions' => $conditions
        ];
    }
    return $parsed;
}

// =================================================================
//  【内部ヘルパー】わざ解析 (全タイプ対応・完全版)
// =================================================================
function _parse_skill_groups_to_data($groups, $shift_type = 'none')
{
    // ▼ 初期化処理
    $conditions = [[
        'type' => '',
        'val' => [],
        'hp_detail' => '',
        'cond_target' => parse_target_group([])
    ]];

    $bt_field_eff = [[
        'target' => parse_target_group([]),
        'value_type' => 'normal',
        'value' => (int)0,
    ]];

    $timelines = [[
        'type' => '',
        'attack_type' => [], // 配列に変更
        'at_type_target' => parse_target_group([]), // 追加
        'killer_rate' => (float)0,
        'target' => [
            'main' => '',
            'type' => '',
            'obj' => [['slug' => '', 'name' => '']]
        ],
        'color_order' => [],
        'value' => (float)0,
        'value_last' => (float)0,
        'hit_count' => (int)1,
        'is_moji_healing' => false,
        'resist_status' => '',
        'token_id' => '',
        'moji_exhaust' => false,
        'omni_advantage' => false,
        'element' => '',
        'turn_count' => (int)1,
        'pressure_debuff' => [],
        'bt_field_eff' => $bt_field_eff,
        'conditions' => $conditions
    ]];

    $variations = [
        'shift_value' => [], // 配列に変更
        'timelines' => $timelines,
    ];

    // 空の場合は初期化配列を要素に持つ配列を返す
    if (empty($groups) || !is_array($groups)) return [$variations];

    $result = [];

    // ▼ グループごとの解析処理
    foreach ($groups as $g) {
        $variant = [
            'shift_value' => [],
            'timelines'   => []
        ];

        // 1. シフト条件の値を取得（配列として格納）
        $shift_val = [];
        if ($shift_type === 'random') {
            $shift_val[] = (string)($g['random_count'] ?? '');
        } elseif ($shift_type === 'attr') {
            $terms = $g['sugo_shift_attr'] ?? ($g['kotowaza_shift_attr'] ?? null);
            if ($terms && is_array($terms)) {
                foreach ($terms as $t) if (is_object($t)) $shift_val[] = $t->slug;
            }
        } elseif ($shift_type === 'moji') {
            $terms = $g['sugo_shift_moji'] ?? ($g['kotowaza_shift_moji'] ?? null);
            if ($terms && is_array($terms)) {
                foreach ($terms as $t) if (is_object($t)) $shift_val[] = $t->name;
            }
        } elseif ($shift_type === 'attacked') {
            $shift_val[] = (string)($g['sugo_shift_attacked'] ?? ($g['kotowaza_shift_attacked'] ?? ''));
        }
        $variant['shift_value'] = $shift_val;

        // 2. タイムラインの条件を取得
        $cond_data = $g['waza_add_cond_loop'] ?? [];
        $parsed_conditions = _parse_trait_condition($cond_data);
        if (empty($parsed_conditions)) {
            $parsed_conditions = $conditions; // デフォルト構造を維持
        }

        // 3. タイムラインループの解析
        $details = $g['sugo_detail_loop'] ?? ($g['waza_detail_loop'] ?? ($g['kotowaza_detail_loop'] ?? []));

        if (!empty($details)) {
            foreach ($details as $d) {
                // --- 技タイプ ---
                $type_raw = $d['waza_type'] ?? '';
                $type = is_array($type_raw) ? ($type_raw['value'] ?? '') : $type_raw;

                // --- 攻撃タイプ（配列化） ---
                $atk_type_raw = $d['attack_type'] ?? [];
                $attack_type = [];
                if (is_array($atk_type_raw)) {
                    foreach ($atk_type_raw as $at) {
                        $attack_type[] = is_array($at) ? ($at['value'] ?? '') : $at;
                    }
                } elseif ($atk_type_raw) {
                    $attack_type[] = $atk_type_raw;
                }

                // --- 上昇対象 / 連携対象 ---
                $at_type_target = parse_target_group([]);
                if (!empty($d['advantage_target']['target_type'])) {
                    $at_type_target = parse_target_group($d['advantage_target']);
                } elseif ($type === 'coop_attack' && !empty($d['coop_target'])) {
                    // 連携先グループ情報（単一タームでも配列に変換して処理）
                    $dummy_coop = [
                        'target_type' => 'group',
                        'target_group' => is_array($d['coop_target']) ? $d['coop_target'] : [$d['coop_target']]
                    ];
                    $at_type_target = parse_target_group($dummy_coop);
                }

                // --- ターゲットメイン / サブ ---
                $waza_target_main = $d['waza_target'] ?? '';
                $waza_target_type = $d['waza_target_detail'] ?? 'none';

                $parsed_target = parse_target_group([]);
                if ($waza_target_type !== 'none') {
                    $dummy_target_grp = [
                        'target_type'    => $waza_target_type,
                        'target_attr'    => $d['target_detail_attr'] ?? [],
                        'target_species' => $d['target_detail_species'] ?? [],
                        'target_group'   => $d['target_detail_group'] ?? [],
                        'target_other'   => $d['target_detail_other'] ?? ''
                    ];
                    $parsed_target = parse_target_group($dummy_target_grp);
                }

                $target_array = [
                    'main' => $waza_target_main,
                    'type' => $parsed_target['type'],
                    'obj'  => $parsed_target['obj']
                ];

                // --- その他のパラメータ ---
                $color_order = [];
                if (!empty($d['colorfull_attack_attr'])) {
                    foreach ($d['colorfull_attack_attr'] as $c) {
                        if (is_object($c)) $color_order[] = $c->slug;
                    }
                }

                $token_id = '';
                if (!empty($d['related_token'])) {
                    $t_obj = is_array($d['related_token']) ? ($d['related_token'][0] ?? null) : $d['related_token'];
                    if (is_object($t_obj)) $token_id = (string)$t_obj->ID;
                }

                $element = '';
                if (!empty($d['attack_attr']) && is_object($d['attack_attr'])) {
                    $element = $d['attack_attr']->slug;
                }

                $pressure_debuff = [];
                if (!empty($d['pressure_debuff_count'])) {
                    $pressure_debuff = split_str_comma($d['pressure_debuff_count']);
                }

                // --- フィールド効果 ---
                $parsed_bt_field_eff = [];
                if (!empty($d['battle_field_loop'])) {
                    foreach ($d['battle_field_loop'] as $f) {
                        $dummy_bf_grp = [
                            'target_type'    => $f['battle_field_target'] ?? '',
                            'target_attr'    => $f['battle_field_attr'] ?? [],
                            'target_species' => $f['battle_field_species'] ?? [],
                            'target_group'   => $f['battle_field_affiliation'] ?? [],
                            'target_moji'    => $f['battle_field_moji'] ?? [],
                            'target_other'   => ''
                        ];
                        $parsed_bt_field_eff[] = [
                            'target'     => parse_target_group($dummy_bf_grp),
                            'value_type' => $f['battle_field_value_type'] ?? 'normal',
                            'value'      => (int)($f['battle_field_value'] ?? 0)
                        ];
                    }
                }
                if (empty($parsed_bt_field_eff)) {
                    $parsed_bt_field_eff = $bt_field_eff; // デフォルト構造を維持
                }

                // タイムライン追加
                $variant['timelines'][] = [
                    'type'            => $type,
                    'attack_type'     => $attack_type,
                    'at_type_target'  => $at_type_target,
                    'killer_rate'     => (float)($d['advantage_rate'] ?? 0),
                    'target'          => $target_array,
                    'color_order'     => $color_order,
                    'value'           => (float)($d['waza_value'] ?? 0),
                    'value_last'      => (float)($d['waza_value_last'] ?? 0),
                    'hit_count'       => (int)($d['hit_count'] ?? 1),
                    'is_moji_healing' => (bool)($d['is_moji_healing'] ?? false),
                    'resist_status'   => $d['target_status'] ?? '',
                    'token_id'        => $token_id,
                    'moji_exhaust'    => (bool)($d['moji_exhaust'] ?? false),
                    'omni_advantage'  => (bool)($d['omni_advantage'] ?? false),
                    'element'         => $element,
                    'turn_count'      => (int)($d['turn_count'] ?? 1),
                    'pressure_debuff' => $pressure_debuff,
                    'bt_field_eff'    => $parsed_bt_field_eff,
                    'conditions'      => $parsed_conditions
                ];
            }
        }

        // details が空だった場合のフォールバック
        if (empty($variant['timelines'])) {
            $variant['timelines'] = $timelines;
        }

        $result[] = $variant;
    }

    return $result;
}

// lsの効果成形関数
function parse_ls_eff($loop)
{
    $data = [];
    foreach ($loop as $pattern) {
        $eff = [
            'status' => $pattern['ls_status'] ?? '',
            'resist' => $pattern['resist_status'] ?? '',
            'value' => (float)($pattern['rate'] ?? 0),
        ];
        $data[] = $eff;
    }
    return $data;
}

function _parse_leader_skill_data($loop)
{
    $data = [];
    foreach ($loop as $pattern) {
        $parsed = [
            'type' => $pattern['ls_type'] ?? 'fixed',
            'value_raws' => [],
            'conditions' => [],
            'limit_wave' => (int)$pattern['limit_wave_count'] ?? 0,
            'per_unit' => false,
            'main_eff' => ['targets' => [], 'value_raws' => []],
            'exp' => (int)$pattern['exp_magnification'] ?? 0,
            'buff_count' => (int)$pattern['buff_count'] ?? 0,
            'converge_rate' => ['conv_2' => 0, 'conv_1' => 0],
            'turn_count' => (int)$pattern['turn_count'] ?? 0,
        ];
        if ($parsed['type'] === 'per_unit') $parsed['per_unit'] = true;
        $parsed['conditions'] = [
            [
                'type' => '', // 条件タイプ自体が「なし」
                'val'  => [],
                'cond_targets' => [
                    [
                        'total_tf' => false,
                        'need_num' => 0,
                        'type'     => '',
                        'obj'      => []
                    ]
                ]
            ]
        ];
        $conditions = [];
        if (!empty($pattern['ls_cond_pattern_loop'])) :
            foreach ($pattern['ls_cond_pattern_loop'] as $cond_p) {
                if (!empty($cond_p['ls_cond_loop'])) :
                    foreach ($cond_p['ls_cond_loop'] as $cond) {
                        $temp_cond = [
                            'type' => $cond['ls_cond_type'] ?? '',
                            'val' => !empty($cond['ls_cond_val']) ? split_str_comma($cond['ls_cond_val']) : [],
                            'cond_targets' => [],
                        ];
                        switch ($temp_cond['type']):
                            case 'chara_num':
                                $party_conds = [];
                                if (!empty($cond['ls_party_cond_loop'])):
                                    foreach ($cond['ls_party_cond_loop'] as $p_cond) {
                                        $party_details = [
                                            'total_tf' => $p_cond['total_tf'] ?? false,
                                            'need_num' => (int)($p_cond['need_chara_num'] ?? 6),
                                            'type' => '',
                                            'obj' => []
                                        ];
                                        $party_target = !empty($p_cond['target_field_group']) ? parse_target_group($p_cond['target_field_group']) : [];
                                        $party_conds[] = array_merge($party_details, $party_target);
                                    }
                                endif;
                                $temp_cond['cond_targets'] = $party_conds;
                                break;
                            case 'cooperate':
                                if (!empty($cond['cooperate_target_loop'])) {
                                    $party_conds = [];
                                    foreach ($cond['cooperate_target_loop'] as $target) {
                                        $party_details = [
                                            'total_tf' => false,
                                            'need_num' => 0,
                                            'type' => '',
                                            'obj' => []
                                        ];
                                        $party_target = !empty($target) ? parse_target_group($target) : [];
                                        $party_conds[] = array_merge($party_details, $party_target);
                                    }
                                    $temp_cond['cond_targets'] = $party_conds;
                                }
                                break;
                            default:
                                $temp_cond['cond_targets'] = [
                                    [
                                        'total_tf' => false,
                                        'need_num' => 0,
                                        'type' => '',
                                        'obj' => []
                                    ]
                                ];
                        endswitch;
                        $conditions[] = $temp_cond;
                    }
                endif;
            }
        endif;
        $parsed['conditions'] = $conditions;
        $eff_raws = [];
        if ($parsed['per_unit']) {
            if (!empty($pattern['per_unit_loop'])) {
                foreach ($pattern['per_unit_loop'] as $pu) {
                    $pre_eff = [];
                    $targets = [];
                    $targets[] = $pu['target_field_group'] ? parse_target_group($pu['target_field_group']) : [];
                    $values = $pu['ls_status_loop'] ? parse_ls_eff($pu['ls_status_loop']) : [];
                    $pre_eff = [
                        'targets' => $targets,
                        'value_raws' => $values
                    ];
                    $eff_raws[] = $pre_eff;
                }
            }
        } else {
            if (!empty($pattern['ls_status_loop'])) {
                $pre_eff = [];
                $values = $pattern['ls_status_loop'] ? parse_ls_eff($pattern['ls_status_loop']) : [];
                $targets_loop = $pattern['ls_target_chara_loop'] ?? [];
                if (!is_array($targets_loop)) $targets_loop = [$targets_loop];
                $targets = [];
                foreach ($targets_loop as $t) {
                    // $t が配列であり、かつ対象のキーが存在する場合のみ実行
                    if (is_array($t) && isset($t['target_field_group'])) {
                        $targets[] = parse_target_group($t['target_field_group']);
                    }
                }
                $pre_eff = [
                    'targets' => $targets,
                    'value_raws' => $values
                ];
                $eff_raws[] = $pre_eff;
            }
        }
        $parsed['main_eff'] = $eff_raws;
        if ($parsed['type'] === 'converged') {
            $conv_2 = (float)($pattern['converge_rate_2'] ?? 0);
            $conv_1 = (float)($pattern['converge_rate_1'] ?? 0);
            $parsed['converge_rate'] = ['conv_2' => $conv_2, 'conv_1' => $conv_1];
        }
        $data[] = $parsed;
    }
    return $data;
}

// =================================================================
//  【内部ヘルパー】EXスキル解析
// =================================================================
function _parse_ex_skill($post_id)
{
    // ▼ 初期化処理
    $result = [
        'name'            => '',
        'skill_kind'      => '',
        'add_eff'         => [
            'type'       => '',
            'target'     => '',
            'value'      => (int)0,
            'turn_count' => (int)1
        ],
        'search_priority' => parse_target_group([])
    ];

    // スキル名がない場合（EXスキル非所持）は初期状態のまま返す
    $name = get_field('ex_skill_label', $post_id);
    if (empty($name)) {
        return $result;
    }

    // 値の取得と代入
    $result['name']       = (string)$name;
    $result['skill_kind'] = (string)get_field('ex_skill_name', $post_id);

    $result['add_eff']['type']   = (string)get_field('additional_effect', $post_id);
    $result['add_eff']['target'] = (string)get_field('effect_target', $post_id);
    $result['add_eff']['value']  = (int)get_field('effect_value', $post_id);

    $turn_count = get_field('turn_count', $post_id);
    $result['add_eff']['turn_count'] = ($turn_count !== '') ? (int)$turn_count : 1;

    // サーチ優先対象（Groupフィールドはそのまま parse_target_group に渡せるフィールド名構造になっています）
    $priority_group = get_field('search_priority', $post_id);
    if (!empty($priority_group)) {
        $result['search_priority'] = parse_target_group($priority_group);
    }

    return $result;
}


// =================================================================
//  【内部ヘルパー】チャージスキル解析
// =================================================================
function _parse_charge_skill($post_id)
{
    // ▼ 初期化処理
    $effect_init = [
        'type'          => '',
        'target'        => [
            'main' => '',
            'type' => '',
            'obj'  => [['slug' => '', 'name' => '']]
        ],
        'target_status' => [],
        'value'         => (int)0,
        'turn_count'    => (int)1
    ];

    $result = [
        'name'        => '',
        'need_charge' => (int)0,
        'effect'      => [$effect_init]
    ];

    // スキル名がない場合（チャージスキル非所持）は初期状態のまま返す
    $name = get_field('charge_skill_name', $post_id);
    if (empty($name)) {
        return $result;
    }

    $result['name']        = (string)$name;
    $result['need_charge'] = (int)get_field('need_charge', $post_id);

    // リピーターフィールドのループ処理
    $loop = get_field('charge_skill_loop', $post_id);
    if (!empty($loop) && is_array($loop)) {
        $effects = [];
        foreach ($loop as $row) {
            $type        = $row['charge_type'] ?? '';
            $target_main = $row['effect_target'] ?? '';

            // 対象条件の取得（Groupフィールドのサブフィールドキーが共通関数に適合しています）
            $target_cond_group = $row['target_cond_group'] ?? [];
            $parsed_target = parse_target_group($target_cond_group);

            // parse_target_group が空を返した時の保険
            if (empty($parsed_target['type'])) {
                $parsed_target = parse_target_group([]);
            }

            $target_array = [
                'main' => $target_main,
                'type' => $parsed_target['type'],
                'obj'  => $parsed_target['obj']
            ];

            $target_status = $row['target_resistance'] ?? [];

            $value = isset($row['charge_skill_value']) && $row['charge_skill_value'] !== '' ? (int)$row['charge_skill_value'] : 0;
            $turn  = isset($row['effect_turn']) && $row['effect_turn'] !== '' ? (int)$row['effect_turn'] : 1;

            $effects[] = [
                'type'          => $type,
                'target'        => $target_array,
                'target_status' => $target_status,
                'value'         => $value,
                'turn_count'    => $turn
            ];
        }
        $result['effect'] = $effects;
    }

    return $result;
}
