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

function koto_get_trait_search_label_map()
{
    return [
        'give_trait' => '特性付与',
        'damage_correction' => '火力補正',
        'damage_correction_oneself' => '自身の威力up',
        'damage_correction_killer' => 'キラー',
        'damage_correction_break_limit' => '自身の上限解放',
        'damage_correction_single_shot' => '単体単発補正',
        'damage_correction_week_killer' => '弱点キラー',
        'status_up' => 'ステータス・クリティカル補正',
        'status_up_atk' => 'ATKUP',
        'status_up_hp' => 'HPUP',
        'status_up_critical_rate' => 'クリティカル率',
        'status_up_critical_damage' => 'クリティカルダメージ',
        'status_up_resistance' => '状態異常耐性',
        'status_up_healing_effect' => '回復効果UP',
        'status_up_mitigation' => 'ダメージ軽減',
        'status_up_dodge' => '心眼回避',
        'draw_eff' => 'ドロー時効果',
        'draw_eff_atk_buff' => '攻撃バフ',
        'draw_eff_def_buff' => '防御バフ',
        'draw_eff_healing' => '回復',
        'draw_eff_status_healing' => '状態異常回復',
        'on_play_eff' => '実体時効果',
        'on_play_eff_atk_buff' => '攻撃バフ',
        'on_play_eff_def_buff' => '防御バフ',
        'new_traits' => '新とくせい',
        'new_traits_support' => '応援',
        'new_traits_see_through' => '看破',
        'new_traits_assistance' => '援護',
        'new_traits_resonance_atk' => '共鳴',
        'new_traits_resonance_crit' => 'クリティカル共鳴',
        'new_traits_poke' => '牽制',
        'after_attack' => '反撃・腐敗など',
        'after_attack_counter' => 'わざ反撃',
        'after_attack_sugo_counter' => 'すごわざ反撃',
        'after_attack_corruption' => '腐敗',
        'after_attack_reflection' => 'ダメージ反射',
        'mode_shift' => 'モードシフト・変身',
        'mode_shift_mode_shift' => 'モードシフト',
        'mode_shift_transform' => '変身',
        'other' => 'その他',
        'other_combo_plus' => 'コンボ＋',
        'other_penetration' => 'バリア貫通',
        'other_over_healing' => 'オーバーヒール',
        'other_exp_up' => '経験値UP',
        'other_pressure_break' => '重圧の上限解放',
        'other_other' => 'その他の固有とくせい',
    ];
}
