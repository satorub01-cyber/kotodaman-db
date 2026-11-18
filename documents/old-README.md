# コトダマンDB ドキュメント案内

> [!NOTE]
> コトダマンDB（`cocoon-child-master`）の全体アーキテクチャ、ファイル責務一覧、および機能追加時の連動チェックリストは **[codebase-map.md](codebase-map.md)** を参照してください。
> 本書（`README.md`）は初期開発時のメモ・構想録です。

### 主要ドキュメント一覧
* **全体地図・アーキテクチャ**: [codebase-map.md](codebase-map.md)
* **AI・開発者向け開発ガイドライン**: [../AGENTS.md](../AGENTS.md)
* **MCP（Model Context Protocol）**: [mcp/README.md](mcp/README.md)
* **祝福トークン作成ガイド**: [blessing-token-guide.md](blessing-token-guide.md)
* **自動入力仕様・回帰テスト**: [../lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md](../lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md)

---

## 開発メモ（初期構想）

koto-calc : データづくり
koto-search : 検索ロジック＝クエリづくり
chara-list-functions : 検索結果画面作成
searchform : 検索バーの機能、見た目
page-character-list : 検索結果仮組

# _spec_json取得プログラム
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
レジェダセット

TODO表示ロジックのspec_json参照化
TODO祝福等のプリセット機能
TODO右側キャラの行内容のサマリー

### 自動入力アルゴリズム
1. CSVファイルを「種別」列に応じて分けて
2. 種別ごとにCSVからACFをとってくる関数
3. ゲーム内文言をCSVに合わせて分割、削除する文言を削除
4. 種別ごとの文言に合わせて２の関数を再起的に呼び出す関数
5. 種別ごとに３の関数を呼び出してすべてをACF化する関数（行内容と入力欄を見てどの繰り返しフィールドか判断）
6. 4で作ったACFで記事を作る関数と、既存キャラに追加する関数

### 種別ごとの再起呼び出し方法
#### とくせい
1. 丸数字で分割
2. とくせい条件の文言がなくなるまでとくせい条件を呼び出す
3. とくせいの文言からとくせい条件を呼び出す

#### わざ
#### すごわざ条件

### 種別
- とくせい
- とくせいの条件
- わざ
- わざ追加条件
- すごわざ条件
- 祝福
- リーダーとくせい
- リーダーとくせい条件

実装日を〇年〇月〇日の入力を自動変換する
とくせいの自動入力で、追加文字の濁点

/ 行を上に追加
// 行を並べ替え
のショートカットを追加

# ACFのjsonから取得すべきキー
- key 
- name
- label
- title
- choices
  - 最初の１項目のみ
- sub_fields
- type
  - typeがtab,acordionなら無視
- taxonomy

# auto-inputで追加すべき箇所
入力欄のHTML
acf-editor.php:829

JavaScriptの送信データ
acf-auto-input.js:48

通常フィールドの処理・入力キーの種別振り分け
acf-auto-input-parsers.php:677
acf-auto-input-parsers.php:700

新しいパーサー関数の追加と振り分け
acf-auto-input-parsers.php:627

入力キーと保存先ACFフィールドの対応
acf-auto-input-wp-acf.php:72

入力キー固有の特殊変換が必要な場合
acf-auto-input-helpers.php:223
