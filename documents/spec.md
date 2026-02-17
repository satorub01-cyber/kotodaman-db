# サイトと各ファイルの構成
- トップページ /
  - page-db-top.php
- 倍率計算ページ /magnification-calc/
  - キャラデータ入力時に、倍率を調査するためのページ
  - 倍率を入れればダメージ計算もできる
  - page-simple-calc.php
- 倍率未調査キャラ一覧 /mgn-blank-charas/
  - page-missing-info.php
- グループ一覧 /affiliation-list/
  - ユーザー向けの検索用
  - page-term-list.php
- イベント一覧 /event-list/
  - ユーザー向けの検索用
  - page-term-list.php
- プライバシーポリシー /policy/
  - SEO対策
- _spec_json確認用ページ（非公開） /json-displayer/
  - fanction.phpの717~730行目
  - 本文にショートコード[debug_koto_json id="1234"]と書く
- 検索結果ページプレビュー（非公開） /character-list/
  - page-character-list.php
  - このページは必要ないかも
- イベント・キャラ一覧（非公開） /event-date/
  - page-event-date-list.php
- 検索バー
  - searchform.php
- 検索結果ページ
  - search-chacter.php　これがページの枠組み
  - chara-list-functions.php　これが表の部分を作る
  - style-character-search.css
- キャラページ
  - single-character.php
  - style-character-detail.css

# その他ファイルの役割
## /lib
- koto-calc.php
  - _spec_json生成ロジック、保存処理
  - 各種search-tags生成、保存処理
  - 基本の火力指数計算処理
- koto-display.php
  - EXスキル以外の、表示用の文言をACFフィールドから生成
- koto-modal-displayer.php
  - 協力を呼び掛けるためのモーダルを出す
- koto-search.php
  - 検索するためのクエリを作成
  - 並べ替え処理
- editor.php
  - 親ターム選択ロジック
- functions.php
  - 雑多な機能

# 命名規則

## spec_json
わざやとくせいの数値は[value]で、条件の値は[val]

## 属性
  - fire,water,wood,light,dark,void,heaven
  - indexは左から1,2,3,4,5,6,7
## 種族
  - god,demon,hero,dragon,beast,spirit,artifact,yokai
  - indexは左から1,2,3,4,5,6,7,8
## 優先度   
  1. フィールド
  2. バフ/デバフ
  3. 回復
  4. 全体攻撃
  5. 単体攻撃
## ギミック
|value|label|
|---|---|
|シールド|shield|
|トゲ |needle|
|チェンジ|change|
|弱体|weakening|
|ウォール|wall|
|ビリビリ|shock|
|ヒール|healing|
|コピー|copy|
|フリーズ|freezing|
|地雷|landmine|
|スマッシュ|smash|
|バルーン|balloon|
- スーパーはsuper_(label)


## 状態異常
|value|label|
|---|---|
|poison|毒|
|sleep|睡眠|
|curse|呪い|
|confusion|混乱|
|pollution|汚染|
|burn|炎上|
|remodel|改造|
|weakness|衰弱|
|mutation|変異|
|erasure|消去|
|all|全て|
## わざタイプ
|value|label|
|---|---|
|attack 攻撃
|colorfull_attack|カラフル攻撃|
|coop_attack|連携攻撃|
|command|号令|
|waza_command|大号令|
|heal|回復|
|atk_buff|攻撃バフ(UP)|
|atk_debuff|攻撃デバフ(DOWN)|
|def_buff|防御バフ(UP)|
|def_debuff|防御デバフ(DOWN)|
|pressure|重圧|
|taunt|ターゲット集中|
|barrier|無敵バリア|
|status_barrier|状態異常バリア|
|battle_field|フィールド|
|token|トークン生成|
|impersonation|ものまね|
## 攻撃タイプ
|value|label|
|---|---|
|normal|通常|
|converged|収束|
|moji|文字数上昇|
|fuku|福上昇|
|target|対象により変化|
## 対象タイプ
|value|label|
|---|---|
|self|自身|
|all|パーティー内全員|
|attr|属性|
|species|種族|
|group|グループ|
|other|その他|

## わざの対象
|value|label|
|---|---|
|single_oppo|敵単体|
|all_oppo|敵全体|
|random_oppo|ランダムな敵|
|all_ally|味方全体|
|limited_ally|味方(条件付き)|
|hand_ally|手札の味方全員|
|limited_hand|手札の味方(条件付き)|
|self|自身|

## 条件タイプ
|value|label|
|---|---|
|none|なし|
|char|文字指定|
|moji_count|文字数条件|
|comb|コンボ数|
|theme|テーマ指定|
|attr|属性指定|
|species|種族指定|
|hpcond|HP条件|
|group|グループ条件|
|raid_battle|大討伐|
|attacked|敵からの攻撃を受けたとき|
|fuku_count|福|
|other|その他|

## target_field_groupの「対象」
|value|label|
|---|---|
|self|自身|
|all|パーティー内全員|
|attr|属性|
|species|種族|
|group|グループ|
|other|その他|

## リーダーとくせいタイプ
|value|label|
|---|---|
|fixed|固定値|
|per_unit|キャラ数に応じた倍率|
|exp_up|プレイヤー経験値UP|
|over_healing|オーバーヒール|
|over_attack|攻撃回数に応じたバフ|
|converged|収束付与|

## リーダーとくせい条件タイプ
|value|label|
|---|---|
|chara_num|キャラ編成指定|
|moji_count|文字数|
|combo|コンボ数|
|theme|テーマ指定|
|moji_contain|含まれる文字指定|
|cooperate|同時攻撃|
|wave_count|WAVEが進むごとに|

## とくせいタイプ
|value|label|
|---|---|
|gimmick|ギミック対策|
|damage_correction|火力系補正|
|status_up|ステータスアップ、状態異常耐性、ダメージ軽減、心眼回避|
|draw_eff|ドロー時効果|
|on_play_eff|実体化時効果|
|core_gimmick|コアギミック|
|after_attack|ダメージ与える系|
|new_traits|新とくせい（応援、共鳴など）|
|mode_shift|モードシフト、変身|
|other_traits|その他|

### 火力補正系
|value|label|
|---|---|
|oneself|自身の威力up|
|killer|キラー|
|break_limit|上限解放|
|single_shot|単体単発|
|week_killer|弱点威力|

### ステータスアップ系
|value|label|
|---|---|
|atk|ATKアップ|
|hp|HPアップ|
|mitigation|ダメージ軽減|
|resistance|状態異常耐性|
|dodge|心眼回避|
|critical_rate|クリティカル率|
|critical_damage|クリティカルダメージ|
|healing_effect|回復効果|

### ドロー系
|value|label|
|---|---|
|atk_buff|攻撃バフ|
|def_buff|防御バフ|
|healling|回復|
|status_healing|状態異常回復|

### 実体化時効果
|value|label|
|---|---|
|atk_buff|攻撃バフ|
|def_buff|防御バフ|

### コアギミック
|value|label|
|---|---|
|healing_core|ヒールコア|
|attack_core|アタックコア|
|attack_buff_core|アタックバフコア|
|super_attack_core|スーパーアタックコア|

### 新とくせい
|value|label|
|---|---|
|support|応援|
|see_through|看破|
|assistance|援護|
|resonance|共鳴|
|crit_resonance|クリティカル共鳴(spec_jsonのみに実装)|

### ダメージ与える系
|value|label|
|---|---|
|reflection|反射|
|counter|わざ反撃|
|sugo_counter|すごわざ反撃|
|corruption|腐敗|

### 変身、モードシフトの種類（relation_ship）
|value|label|
|---|---|
|mode_shift|モードシフト|
|before_transform|変身前|
|after_transform|変身後|

### その他のとくせい
|value|label|
|---|---|
|combo_plus|コンボ＋|
|penetration|バリア貫通|
|over_healing|オーバーヒール|
|exp_up|経験値up|
|pressure_break|重圧の上限解放|
|other|その他|
  
# _spec_jsonの構造
## 概要
  |キー|型|要素数|データの役割|実装済み?|
  |---|---|---|---|---|
  |id|int||キャラの投稿ID||
  |name|string||キャラ名||
  |_val_99_hp/atk|int||lv99時のHP/攻撃力||
  |_val_120_hp/atk|int||lv120時のHP/攻撃力||
  |talent_hp/atk|int||才能開花MAXで増加するHP/攻撃力||
  |is_no_lv120|bool||trueなら昇華なし(低レア、トークンなど)||
  |rarity|str||レアリティ|none,special,legend,grand|
  |release_date|日付||実装日||
  |attribute|str||属性||
  |sub_attributes|str|任意|サブ属性|
  |species|str||種族|
  |groups|連想配列|任意|グループ||
  |┣slug|str||グループのタームスラッグ||
  |┗name|str||グループのタームラベル||
  |waza|json||わざタイムライン||
  |sugowaza|json||すごわざタイムライン||
  |kotowaza|json|5|ことわざタイムライン||
  |priority|int||すごわざ発動の順番|
  |traits|json|任意|とくせい１と２||
  |trait1|json|任意|とくせい１||
  |trait2|json|任意|とくせい２||
  |blessing|json|0~8|祝福とくせい||
  |leader|json||リーダーとくせい||
  |corrections|json|任意|火力指数計算に必要な火力補正の配列||
  |chars|json|1~6|使える文字||
  |buff_counts_board|int|6|すごわざのみと、すごわざ＋各凸での盤面バフ||
  |buff_counts_hand|int|6|すごわざのみと、すごわざ＋各凸での手札バフ||
  |debuff_counts|int|6|すごわざのみと、すごわざ＋各凸でのデバフ||
  |name-ruby|str||キャラ名(並べ替え用)||
  |cv|str||声優名||
  |acquisition|str||ガチャ産かその他||
  |max_ls_hp|int||最大リーダーhp倍率(並べ替え用)||
  |max_ls_atk|int||最大リーダーatk倍率(並べ替え用)||
  |firepower_index|int||すごわざの最大火力指数||
  |is_estimate|bool||すごわざ倍率が未調査ならtrue||
  |is_koto_estimate|bool||ことわざ倍率が一部未調査ならtrue||
  |pre_evo_name|str||進化前の名前||
  |another_image_name|str||絵違いの名前||
  |is_break_fuku|bool||言塊凸破福があればtrue|まだ|
  
## 詳細
### chars
  |キー|型|要素数|データの役割|実装済み?|
  |---|---|---|---|---|
  |val|str||ひらがな||
  |slug|str||タクソノミーのスラッグ||
  |unlock|str||初期かとくせい1/2か祝福とくせいか||
  |attr|str||この文字の属性||
### waza
  |キー|型|要素数|データの役割|実装済み?|
  |---|---|---|---|---|
  |name|str||わざの名前||
  |variations|連想配列|任意|本体（わざのタイムライン）_parse_skill_groups_to_data参照||
  |scaling|連想配列|任意|倍率表の内容|||
#### variations
詳細は_parse_skill_groups_to_data参照  
#### scailing
詳細は$get_scaling_datas参照  
  |キー|型|要素数|データの役割|実装済み?|
  |---|---|---|---|---|
  |type|str||収束か文字数か||
  |row|連想配列|2|倍率とその条件||
  |┣rate|float||倍率||
  |┗cond|連想配列|2|条件||
  |&ensp; ┣enemy|int||敵数||
  |&ensp; ┗moji|int||文字数||


### sugowaza
  |キー|型|要素数|データの役割|未実装?|
  |---|---|---|---|---|
  |name|str||わざの名前||
  |condition|連想配列|or条件の数|すごわざ発動条件_parse_activation_condition参照||
  |shift_type|str||シフト条件の種類||
  |variations|連想配列|任意|本体（すごわざのタイムライン）_parse_skill_groups_to_data参照||
  |scaling|連想配列|任意|倍率表の内容（$get_scaling_datas参照）|||
#### variations
詳細は_parse_skill_groups_to_data参照  
概要はwaza参照
#### scailing
詳細は$get_scaling_datas参照  
概要はwaza参照

### kotowaza
  ['kotowaza'][0]から[4]まである
  |キー|型|要素数|データの役割|未実装?|
  |---|---|---|---|---|
  |level|int||凸数||
  |condition|連想配列|or条件の数|ことわざ発動条件_parse_activation_condition参照||
  |shift_type|str||シフト条件の種類||
  |variations|連想配列|任意|本体（ことわざのタイムライン）_parse_skill_groups_to_data参照||
  |scaling|連想配列|任意|倍率表の内容（$get_scaling_datas参照）|||
#### variations
詳細は_parse_skill_groups_to_data参照  
概要はwaza参照
#### scailing
詳細は$get_scaling_datas参照  
概要はwaza参照  

### traits
連想配列
**削除予定**
### trait1/2
  |キー|型|要素数|データの役割|未実装?|
  |---|---|---|---|---|
  |name|str||とくせい名||
  |contens|連想配列|任意|とくせい内容_parse_leader_skill_data参照||
### blessing
連想配列_parse_leader_skill_data参照
<!-- TODOヘルパー関数を参照させる部分はキーと型、軽い役割だけ表にしておく -->
### leader
連想配列_parse_leader_skill_data参照
### corrections
連想配列_calculate_correction_values参照

## 注釈
- get_character_spec_data内では$dataとして扱われる

# 検索用タグ
## search_tags
  |タグ名|どんなキャラか|実装済み?|
  |---|---|---|
　|char_connector|いうん所持|
  |char_small_yuyo|ゅょ所持|
  |axis_i|い以外のい軸|
  |axis_u|う以外のう軸|
  |axis_youon|ゅょ以外のゃゅょ軸|

## waza/sugo/koto_search_tags(_凸数)
  |タグ名|説明|未実装？|
  |---|---|---|
  |type_(わざタイプのvalue)|||
  |type_attack|なにかしらのダメージを与える行動||
  |attack_type_(攻撃タイプのvalue)|収束、文字数上昇など||
  |type_attack_single|単体単発攻撃||
  |type_attack_single_multi|単体連続攻撃||
  |type_attack_all|全体単発攻撃||
  |type_attack_all_multi|全体連続攻撃||
  |type_attack_random|乱打攻撃||
  |type__omni_advantage|全属性有利||

## trait_search_tags_1/2
  |タグ名|どんなキャラか|未実装?|
  |---|---|---|
  |give_trait|最近増えてる、ドロバフや火力補正の付与||
  |trait_(とくせいタイプのvalue)|||
  |trait_(とくせいタイプのvalue)_(サブタイプのvalue)|||
- 共鳴はresonance_critとresonance_atk


## 注釈
- get_character_spec_data内で$dataの中身として生成
- 保存時に_spec_dataから削除し別枠で保存
- 今後は拡大せず、カスタムテーブルも作成しない

# spec_json以外のメタデータ
## 並べ替え用
|名前|型|役割|
|---|---|---|
|firepower_index||火力指数の、検索ページでの|
|buff_count_board_lv(0~5)|int|盤面バフ|
|buff_count_hand_lv(0~5)|int|手札バフ|
|debuff_count_lv(0~5)|int|デバフ|
|99/120/talent/max_hp/atk|int|hp/atk|
|_sort_attr_index|int|属性|
|_sort_species_index|int|種族|
|impl_date|str(mySQLでのdate型)|実装日|
|name_ruby|str|キャラ名（ACFで作ってるfield）|
|max_ls_hp/atk|str（ACFのfield）|リーダーとくせいのmax倍率|
||||
## サーチタグ
|名前|型|役割|
|---|---|---|
|_waza_tags_str|str|簡易わざ/とくせい検索用|
|_sugo_tags_str|str|簡易わざ/とくせい検索用|
|_kotowaza_tags_str|str|簡易わざ/とくせい検索用|
|_kotowaza_tags_str_(0~4)|str|簡易わざ/とくせい検索用|
|_trait_tags_str_1|str|簡易わざ/とくせい検索用|
|_trait_tags_str_2|str|簡易わざ/とくせい検索用|
|_trait_tags_str_blessing|str|簡易わざ/とくせい検索用|

# 関数の概要
|関数名|引数|型|戻り値|型|副作用|
|---|---|---|---|---|---|
| get_character_spec_data|post_id|int|_spec_json|連想配列||
|$calc_lv120_stat|$val_99|int| $chouka_val|int||
|$collect_skill_tags|ACFの外枠のループ, 追加したい部分|json,配列|なし||わざ、すごわざのサーチタグ生成|
|$get_scaling_data|投稿ID,waza/sugowaza/kotowaza|int,str|状態と倍率をまとめた連想配列|連想配列||
|$calc_skill_buffs|_spec_json['sugowaza']['variations']|左の項目|バフ、デバフの総数|連想配列|なし|
|on_save_character_specs|投稿id|int|||各種メタデータの保存|
|_update_buff_search_meta|投稿id,spec_data|int,spec_data|||バフ検索用のメタデータ保存|
|_calculate_firepower_index|spec_data|spec_data|最大火力指数|int|なし|
|_calc_firepower_timeline|spec_data|spec_data|火力指数自動計算用配列|連想配列|なし| <!-- TODO_calc_firepower_timelineの作成 -->
|_calculate_correction_values|spec_data||火力補正値まとめ|連想配列|なし|
|$get_cond_text||||||
|_parse_trait_loop_to_data|ACFのとくせいループ,祝福か|連想配列,bool|spec_data[trait][contents]|連想配列|なし|
|parse_target_group|対象選択フィールドグループ|連想配列|崩したもの|連想配列|なし|
|_parse_activation_condition|とくせい/わざの発動条件ループ|連想配列|条件の連想配列|連想配列|なし|
|_parse_sugo_condition|ACFのすごわざ発動条件のgroup_loop|連想配列|条件の連想配列|連想配列|なし|
|_parse_leader_skill_data|ACFのリーダーとくせいループ|連想配列||連想配列|なし|
|parse_ls_eff|ACFのls_statusloop|連想配列||連想配列|なし|
|||||||
|||||||
|||||||
|||||||
|||||||
|||||||


# 関数の詳細仕様
## 内部データ管理用
### get_character_spec_data
下記の様々な関数を利用して、spec_jsonを作る
### $collect_skiil_tags
ACFのデータを直接受け取り、そこからわざや攻撃のタイプだけを取り出して検索タグ化する
### $get_scaling_data
投稿IDとワザの種類を受け取り、中でget_fieldして倍率表の内容を網羅する  
戻り値
```
$scaling (
    [type] => enemy/moji(str)
    [rows]=>(
        [rate]=>倍率(float)
        [cond]=>(
            [enemy]=>敵数(int)
            [moji]=>文字数(int)
        )
    )
)
```
### $calc_skill_buffs
_spec_jsonのわざvariationsについてforeachを回して、盤面バフ、手札バフ、デバフの理論上の最大を追加条件や対象を無視して計算する  
デバフについては対象を敵のみに絞っている　 
戻り値  
```
[
    'board' => 盤面int,
    'hand' => 手札int,
    'debuff' => デバフint
]
```
### on_save_charater_specs
spec_dataからsearchtagsを削除したものと、サーチタグと、並べ替え用メタデータを保存する  
サーチタグは半角スペースで囲んで、implodeして一つのstrとして保存
### _calculate_firepower_index
TODOcorrectionsの作り方を見たら戻る
### _calculation_correction_values
TODOspec_dataのとくせいとリーダーとくせいの作り方を見たら戻る
### parse_target_group
ACFの対象選択フィールドグループから以下のような連想配列を作る
otherの場合slugは空白で、nameに文章を入れる
```
$result=[
    [type]=>対象タイプstr
    [obj]=>[ //以下の連想配列の配列
        [slug]=>スラッグstr
        [name]=>名前str
    ]
]
```
### _parse_trait_loop_to_data
ACFのループからとくせいの中身を取り出し、データを解析する。
戻り値
 - 祝福の場合[levels]があり、[value]にはlv1の値を採用
 - [rate_type]が一意に決まる[type],[sub_type]の場合は自動でadjustする
 - target_infoは[target_info][type]によりswitchすることで無駄な取得を避ける
 - 各タイプにおいて、サブタイプによっては値のあるフィールドに値があるかを参照するため、サブタイプの追加に強い
 - ただしnew_traitsはサブタイプで分岐する
戻り値は以下の連想配列の配列
```
[ 
    [type]=>とくせいタイプstr
    [sub_type]=>とくせいサブタイプstr
    [rate_type]=>parcentage/fixed(str)
    [value]=>とくせいの値float
    [levels]=>(　//祝福の場合のみ
        [lv]=>祝福レベルint
        [value]=>とくせいの値float
        [point]=>必要累計ポイント数int
    )
    [super_heal]=>スーパーヒール回復値(int)
    [limit_break]=>上限解放値(int)
    [target_info]=>(
        parse_target_groupの戻り値
    )
    [per_unit]=>キャラ数依存かどうかbool
    [unit_target]=>( //per_unitがtrueの場合のみ
        parse_target_groupの戻り値
    )
    [conditions]=>[　//以下の連想配列の配列
        [type]=>条件タイプstr
        [val]=>[値1,値2,...] //数値は自動でfloatに変換される
        [hp_deatil]=>more/less/just(str)
        [cond_target]=>[
            parse_target_groupの戻り値
        ]
    ]
]

```

### _parse_trait_condition
ACFのとくせいの条件/わざ追加条件ループフィールドを受け取り、jsonを作成  
戻り値は以下の連想配列の配列
```
[
    [type]=>条件タイプstr
    [val]=>[値1,値2,...] //数値は自動でfloatに変換される
    [hp_deatil]=>more/less/just(str)
    [cond_target]=>[
        parse_target_groupの戻り値
    ]
]
```

### _parse_sugo_condition
ACFのすごわざ条件のgroup_loopを受け取り、すごわざ/ことわざ発動条件を解析する

### _parse_leader_skill_data
ACFのリーダーとくせいループを受け取り、jsonを成型する  
戻り値は以下の連想配列の配列
```
[
    [type]=>リーダーとくせいタイプstr
    [conditions]=>[ //以下の連想配列の配列
                [type]=>リーダーとくせい条件タイプstr
                [val]=>[値1,値2,...] //数値は自動でfloatに変換される
                [cond_targets]=>[　//以下の連想配列の配列
                    [type]=>対象タイプstr
                    [obj]=>[ //以下の連想配列の配列
                        [slug]=>スラッグstr
                        [name]=>名前str
                    ]
                    [total_tf]=>編成条件が、「合計〇体」かどうかbool
                    [need_num]=>編成必要数int
                ]
    ]
    [limit_wave]=>上限wave(int)
    [per_unit]=>キャラ数依存かどうかbool
    [main_eff]=>[ //以下の連想配列の配列
        [targets]=>[ //以下の連想配列の配列
            parse_target_groupの戻り値
        ]
        [value_raws]=>[ //以下の連想配列の配列
            [status]=>補正対象値str
            [resist]=>状態異常str
            [value]=>補正値float
        ]
    ]
    [exp]=>経験値補正値float
    [buff_count]=>バフ数int
    [converge_rate]=>[
        conv_2=>二体の時float,
        conv_1=>一体の時float
        ]
]
```

## parse_ls_eff
ACFのループから、リーダーとくせいの補正対象ステータス、はじく状態異常、補正値を成型する
```
$result=[ //以下の連想配列の配列
    [status]=>補正対象ステータスstr
    [resist]=>はじく状態異常str
    [value]=>補正値float
]
```
  
## 検索ロジック用

# 検索機能の実装方針
- _spec_jsonからカスタムテーブルを生成するのではなく、検索用に_spec_jsonのわざ、とくせい部分をフラットな構造に要約する
- サーバー側ではなく、スマホ側で検索させる
- サーチタグたちは現在spec_jsonの外にあるが、全キャラ分の検索用jsonに統合する
- タグと検索用のフラット化したjsonを全キャラ分統合したjsonファイルを作成する
- フラット構造の作り方
  1. 基本的には、情報は捨てない
  2. 階層を浅く
  3. 文字数を減らす
  4. 検索に必要ない情報は捨てる 
- フラット構造を作る前に、UIを検討し、ほしい機能を協力者と相談する
- 紙とペンでjson構造を考える
