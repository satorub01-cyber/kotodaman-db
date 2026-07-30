<?php
// =================================================================
//  ★マスター設定：属性と種族の数値マッピング
// =================================================================
function koto_get_attr_num()
{
    return [
        'fire'   => 1,
        'water'  => 2,
        'wood'   => 3,
        'light'  => 4,
        'dark'   => 5,
        'void'   => 6,
        'heaven' => 7,
        'rainbow' => 8,
    ];
}

function koto_get_species_num()
{
    return [
        'god'      => 1,
        'demon'    => 2,
        'hero'     => 3,
        'dragon'   => 4,
        'beast'    => 5,
        'spirit'   => 6,
        'artifact' => 7,
        'yokai'    => 8,
    ];
}
function koto_get_attr_map()
{
    return [
        '火' => 'fire',
        '水' => 'water',
        '木' => 'wood',
        '光' => 'light',
        '闇' => 'dark',
        '冥' => 'void',
        '天' => 'heaven',
        '虹' => 'rainbow',
    ];
}

function koto_get_species_map()
{
    return [
        '神' => 'god',
        '魔' => 'demon',
        '英' => 'hero',
        '龍' => 'dragon',
        '獣' => 'beast',
        '霊' => 'spirit',
        '物' => 'artifact',
        '妖' => 'yokai',
    ];
}

function koto_get_attack_map_definitions()
{
    return [
        'blast' => [
            'name' => 'ブラスト',
            'strong' => '1.5',
            'very_strong' => '3.75',
            'super_strong' => '5.1',
            'most_strong' => '7.5',
        ],
        'storm' => [
            'name' => 'ストーム',
            'strong' => '1.8',
            'very_strong' => '3',
            'super_strong' => '3.6',
            'most_strong' => '4.8',
        ],
        'lance' => [
            'name' => 'ランス',
            'strong' => '3.75',
            'very_strong' => '4.25',
            'super_strong' => '4.75',
            'most_strong' => '',
        ],
        'claw' => [
            'name' => 'クロー',
            'strong' => '3.5',
            'very_strong' => '4.75',
            'super_strong' => '5.25',
            'most_strong' => '',
        ],
        'slash' => [
            'name' => 'スラッシュ',
            'strong' => '3',
            'very_strong' => '5',
            'super_strong' => '6',
            'most_strong' => '11',
        ],
        'shot' => [
            'name' => 'ショット',
            'strong' => '2.5',
            'very_strong' => '6.25',
            'super_strong' => '8',
            'most_strong' => '14',
        ],
        'blow' => [
            'name' => 'ブロー',
            'strong' => '2',
            'very_strong' => '8.5',
            'super_strong' => '10',
            'most_strong' => '16',
        ],
        'blade' => [
            'name' => 'ブレイド',
            'strong' => ['4', '0.65', '1.05'],
            'very_strong' => ['6', '0.7', '1.9'],
            'super_strong' => ['8', '0.75', '2.35'],
            'most_strong' => ['10', '1.3', ''],
        ],
        'knuckle' => [
            'name' => 'ナックル',
            'strong' => ['3', '1', '1.3'],
            'very_strong' => ['', '', ''],
            'super_strong' => ['5', '1.2', '2.2'],
            'most_strong' => ['', '', ''],
        ],
        'blaster' => [
            'name' => 'ブラスター',
            'strong' => ['5', '0.65', '0.9'],
            'very_strong' => ['7', '0.75', '1.1'],
            'super_strong' => ['9', '0.85', '1.3'],
            'most_strong' => ['12', '1.05', ''],
        ],
        'rush' => [
            'name' => 'ラッシュ',
            'strong' => ['4', '0.6', '1.2'],
            'very_strong' => ['', '', ''],
            'super_strong' => ['6', '1.1', '2.5'],
            'most_strong' => ['12', '0.9', '1.1'],
        ],
    ];
}

// =================================================================
//  ★マスター設定：攻撃種別のマッピング
// =================================================================
/**   @param string $key
 *    @return array
 **/
function koto_get_attack_map($key)
{
    $maps = koto_get_attack_map_definitions();
    return isset($maps[$key]) ? $maps[$key] : [];
}

function koto_get_attack_blast_map()
{
    return koto_get_attack_map('blast');
}

function koto_get_attack_storm_map()
{
    return koto_get_attack_map('storm');
}

function koto_get_attack_lance_map()
{
    return koto_get_attack_map('lance');
}

function koto_get_attack_claw_map()
{
    return koto_get_attack_map('claw');
}

function koto_get_attack_slash_map()
{
    return koto_get_attack_map('slash');
}

function koto_get_attack_shot_map()
{
    return koto_get_attack_map('shot');
}

function koto_get_attack_blow_map()
{
    return koto_get_attack_map('blow');
}

function koto_get_attack_blade_map()
{
    return koto_get_attack_map('blade');
}

function koto_get_attack_knuckle_map()
{
    return koto_get_attack_map('knuckle');
}

function koto_get_attack_blaster_map()
{
    return koto_get_attack_map('blaster');
}

function koto_get_attack_rush_map()
{
    return koto_get_attack_map('rush');
}

function koto_get_event_map()
{
    // グループとイベントの「slug => name」の変換辞書を作成
    $event_terms = get_terms(['taxonomy' => 'event', 'hide_empty' => false]);
    $event_map = [];
    if (!is_wp_error($event_terms)) foreach ($event_terms as $t) $event_map[$t->slug] = $t->name;
    return $event_map;
}

function koto_get_group_map()
{
    $group_terms = get_terms(['taxonomy' => 'affiliation', 'hide_empty' => false]);
    $group_map = [];
    if (!is_wp_error($group_terms)) foreach ($group_terms as $t) $group_map[$t->slug] = $t->name;
    return $group_map;
}
function koto_get_suitable_quest_map()
{
    $quest_terms = get_terms(['taxonomy' => 'suitable_quest', 'hide_empty' => false]);
    $quest_map = [];
    if (!is_wp_error($quest_terms)) foreach ($quest_terms as $t) $quest_map[$t->slug] = $t->name;
    return $quest_map;
}

function koto_get_status_map()
{
    $status_map = [
        'poison' => '毒',
        'sleep' => '睡眠',
        'curse' => '呪い',
        'confusion' => '混乱',
        'pollution' => '汚染',
        'burn' => '炎上',
        'remodel' => '改造',
        'weakness' => '衰弱',
        'mutation' => '変異',
        'erasure' => '消去',
        'all' => '全て'
    ];
    return $status_map;
}

function koto_get_buff_prefix_map()
{
    return [
        '少し' => 1,
        '' => 2,
        '大きく' => 3,
        '超大きく' => 4,
        '超絶大きく' => 5,
    ];
}

function koto_get_trait_search_label_map()
{
    return [
        // ----- 特性付与（検索UI外の自動付与タグ） -----
        'give_trait' => '特性付与',

        // ----- 火力補正 -----
        'damage_correction' => '火力補正',
        'damage_correction_oneself' => '自身の威力up',
        'damage_correction_killer' => 'キラー',
        'damage_correction_break_limit' => '自身の上限解放',
        'damage_correction_single_shot' => '単体単発補正',
        'damage_correction_week_killer' => '弱点キラー',

        // ----- ステータス・クリティカル補正 -----
        'status_up' => 'ステータス・クリティカル補正',
        'status_up_atk' => 'ATKUP',
        'status_up_hp' => 'HPUP',
        'status_up_critical_rate' => 'クリティカル率',
        'status_up_critical_damage' => 'クリティカルダメージ',
        'status_up_resistance' => '状態異常耐性',
        'status_up_healing_effect' => '回復効果UP',
        'status_up_mitigation' => 'ダメージ軽減',
        'status_up_dodge' => '心眼回避',

        // ----- ドロー時効果 -----
        'draw_eff' => 'ドロー時効果',
        'draw_eff_atk_buff' => '攻撃バフ',
        'draw_eff_def_buff' => '防御バフ',
        'draw_eff_healing' => '回復',
        'draw_eff_status_healing' => '状態異常回復',

        // ----- 実体時効果 -----
        'on_play_eff' => '実体時効果',
        'on_play_eff_atk_buff' => '攻撃バフ',
        'on_play_eff_def_buff' => '防御バフ',

        // ----- 新とくせい -----
        'new_traits' => '新とくせい',
        'new_traits_support' => '応援',
        'new_traits_see_through' => '看破',
        'new_traits_assistance' => '援護',
        'new_traits_resonance_atk' => '共鳴',
        'new_traits_resonance_crit' => 'クリティカル共鳴',
        'new_traits_poke' => '牽制',

        // ----- 特殊な攻撃バフ -----
        'unique_buff' => '特殊な攻撃バフ',
        'unique_buff_gimmick_count' => 'ギミックカウントATKバフ',
        'unique_buff_block_break' => 'ブロック破壊時ATKバフ',
        'unique_buff_passed_turn' => 'ターン経過ATKバフ',

        // ----- 反撃・腐敗など -----
        'after_attack' => '反撃・腐敗など',
        'after_attack_counter' => 'わざ反撃',
        'after_attack_sugo_counter' => 'すごわざ反撃',
        'after_attack_absolute_counter' => '確定反撃', // ← HTMLにあり、元マップになかった追加分
        'after_attack_corruption' => '腐敗',
        'after_attack_reflection' => 'ダメージ反射',

        // ----- モードシフト・変身 -----
        'mode_shift' => 'モードシフト・変身',
        'mode_shift_mode_shift' => 'モードシフト',
        'mode_shift_transform' => '変身',

        // ----- その他 -----
        'other' => 'その他',
        'other_combo_plus' => 'コンボ＋',
        'other_penetration' => 'バリア貫通',
        'other_over_healing' => 'オーバーヒール',
        'other_exp_up' => '経験値UP',
        'other_pressure_break' => '重圧の上限解放',
        'other_kokusen' => '黒閃', // ← HTMLにあり、元マップになかった追加分
        'other_kyouwa' => '協和', // ← HTMLにあり、元マップになかった追加分
        'other_other' => 'その他の固有とくせい',
    ];
}

function koto_moji_axis_map()
{
    return [
        'axis_i' => 'い軸',
        'axis_u' => 'う軸',
        'axis_youon' => 'やゆよ軸',
        'char_small_yuyo' => '小文字ゆよ',
        'char_connector' => 'つなぎ文字',
    ];
}
