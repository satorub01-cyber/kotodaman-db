koto-calc : データづくり
koto-search : 検索ロジック＝クエリづくり
chara-list-functions : 検索結果画面作成
page-character-list : 検索結果仮組
_spec_json取得プログラム
$raw_data = get_post_meta($post_id, '_spec_json', true);
$spec_data = null;
if (is_string($raw_data)) {
    $spec_data = json_decode($raw_data, true);
} elseif (is_array($raw_data)) {
    $spec_data = $raw_data;
}

// ★追加: データがない場合はその場で計算して取得する（フォールバック）
if (empty($spec_data) && function_exists('get_character_spec_data')) {
    $spec_data = get_character_spec_data($post_id);
}

_firepower_detail[
    [attack][0]=>(
        [mgn] => 10000
        [buff] => 0
        [debuff] => 0
    )
    [attack][1]=>(
        [mgn] => 10000
        [buff] => 2
        [debuff] => 1
    )
    [killer][0] => (
        [cond] => ただ
        [rate] => 10
    )
    [killer][1] => (
        [cond] => fire
        [rate] => 20
    )
]

Excelの列や行の入れ替えctrl+X→shift+ctrl++