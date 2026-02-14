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
- 属性
  - fire,water,wood,light,dark,void,heaven
- 種族
  - god,demon,hero,dragon,beast,spirit,artifact,yokai
- 優先度   
  1. フィールド
  2. バフ/デバフ
  3. 回復
  4. 全体攻撃
  5. 単体攻撃
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
  |groups|str|任意|グループ|いろいろ|
  |waza|json||わざタイムライン||
  |sugowaza|json||すごわざタイムライン||
  |kotowaza|json|5|ことわざタイムライン||
  |priority|int||すごわざ発動の順番|
  |traits|json|任意|とくせい１と２||
  |blessing|json|0~8|祝福とくせい||
  |leader|json||リーダーとくせい||
  |corrections|json|任意|火力指数計算に必要な火力補正の配列||
  |chars|json|1~6|使える文字||
  |buff_counts_board|int|5|各凸での盤面バフ||
  |buff_counts_hand|int|5|各凸での手札バフ||
  |debuff_counts|int|5|各凸でのデバフ||
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
  |variations|連想配列|任意|本体（わざのタイムライン）||
  |scaling|連想配列|任意|倍率表の内容（$get_scaling_datas参照）|||

### わざ・すごわざ・ことわざ
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
  |||
  |||
  |||

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
  ||||

## 注釈
- get_character_spec_data内で$dataの中身として生成
- 保存時に_spec_dataから削除し別枠で保存
- 今後は拡大せず、カスタムテーブルも作成しない




# 関数の概要
|関数名|引数|型|戻り値|型|副作用|
|---|---|---|---|---|---|
| get_character_spec_data|post_id|int|_spec_json|連想配列||
|$calc_lv120_stat|$val_99|int| $chouka_val|int||
|$collect_skill_tags|ACFの外枠のループ, 追加したい部分|json,配列|なし||わざ、すごわざのサーチタグ生成|
|$get_scaling_data|投稿ID,waza/sugowaza/kotowaza|int,str|状態と倍率をまとめた連想配列|連想配列||
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
        [rate]=>倍率(int)
        [condition]=>条件(int)
    )
)
```

## 検索ロジック用

# 検索機能の実装方針
- _spec_jsonからカスタムテーブルを生成するのではなく、_spec_jsonを直で検索する
- サーバー側ではなく、スマホ側で検索させる
- サーチタグたちは現在spec_jsonの外にあるが、中に入れる
