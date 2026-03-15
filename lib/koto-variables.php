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
