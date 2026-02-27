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
