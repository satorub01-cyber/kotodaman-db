# キャラクター下書き作成・運用ガイド（MCP）

本書は、1キャラクター分のスクリーンショットからAIエージェント（またはMCPクライアント）を使ってWordPress上にキャラクターの下書き（`draft`）を作成・更新する際の運用手順、フィールド入力規則、および注意点をまとめたガイドです。

---

## 1. データの分類と基本方針

スクリーンショットから読み取った情報は、次の2種類に分けてMCPツール（`preview_draft` / `create_draft` 等）またはREST APIへ渡します。

| 種類 | 対象データの例 | 保存・変換の仕組み |
|---|---|---|
| **`basic_fields`** | 読み（ルビ）、属性、種族、レア度、所属、初期使用可能文字、Lv.99/120ステータス、リーダー倍率 | ACFのトップレベルフィールドへ直接保存（ターム名・スラッグはサーバー側でタームIDへ自動解決） |
| **`auto_input`** | わざ名・わざ、すごわざ名・発動条件・すごわざ、とくせい1・2、祝福、リーダーとくせい、コトワザ | 既存のCSV対応表（`lib/ゲーム内文言ーACF-対応表.csv`）と自動入力パーサーを通してACFリピーター／グループ構造へ自動変換 |

* 投稿は常にWordPressの **`draft`（下書き）** として作成されます。自動公開は一切行いません。
* AIが画面から読み取れない値や不確かな値を推測して埋めることは禁止です（空欄にするか人間に確認を求めます）。

---

## 2. 最短・省トークン運用フロー（AI向け4ステップ）

AIエージェントが画像から下書きを作成する際は、往復ターン数とトークン消費を最小化するため、以下の **4ステップ** を厳守してください。

### ステップ1：スクリーンショットの一括前処理＆1枚読み取り（Visionトークン75〜85%削減）
* ユーザーからZIPファイルまたは複数枚のスクリーンショットが渡されたら、**個別の高解像度PNGを1枚ずつ `read_file` してはいけません**。
* まず以下の前処理CLIスクリプトを実行し、上下の不要なスマホステータスバー／ナビバー（縦長画像の上7%・下8%）をトリミングしてタイル状に1枚へ合成した `contact_sheet.jpg` を生成します：
  ```bash
  php lib/acf/mcp/prepare_screenshots.php <ZIPまたは画像ディレクトリパス> [出力先ディレクトリ]
  ```
* 出力された **`contact_sheet.jpg` 1枚だけ** を `read_file` で読み取り、全画面の情報を1ターンで抽出します。

### ステップ2：ペイロード構築（ターム自動解決の活用）
* 既存キャラクターとの重複が懸念される場合は、必要に応じて `search_character(name: "キャラ名")` で事前確認します。
* `basic_fields` のタクソノミー項目（`attribute`, `species`, `rarity`, `affiliation`, `available_moji_loop` 等）は、タームIDだけでなく**日本語名やスラッグでもサーバー側で自動解決**されます。
  * 例: `"attribute": "水"`, `"species": "神"`, `"rarity": "なし"`, `"affiliation": ["エヴァンゲリオン"]`, `"available_moji_loop": [{"available_moji": "い"}]`
* そのため、毎回 `get_terms` を連発する必要はありません。未知の所属グループ（`affiliation`）やイベント（`event`）がDBに存在するか確認が必要な場合のみ、`get_terms(taxonomy: "affiliation", search: "キーワード")` で絞り込み検索してください（全件取得は避ける）。
* **入手方法（`get_place`）と福突破（`fuku_break`）の決定**:
  * スクリーンショットのみでは入手方法（ガチャか降臨・配布か）を確定できない場合が多いため、**基本的には `get_place` は下書き作成前にユーザーへ聞いて確認してください**。
  * ガチャキャラなら: `"get_place": "gacha"`（`fuku_break: false`）。
  * 降臨・配布・交換所キャラなら: `"get_place": "other"`。このとき言塊突破対象キャラであれば `"fuku_break": true` を指定します。
* **予想倍率フラグ（`magnification_estimate_tf`）**:
  * AIによる下書き作成時は、**必ず `"magnification_estimate_tf": true`** を指定してください（未指定時もサーバー側で自動的に `true` が適用されます）。

### ステップ3：`preview_draft` の一括実行（事前単発テストの省略）
* 事前に `php lib/acf/test_auto_input.php` を文言ごとに個別実行してターンを消費せず、抽出した `character_name`, `basic_fields`, `auto_input` をまとめて一度で MCP ツール `preview_draft` に渡します。
* 通常モード（`test_mode: false`、初期値）では、巨大な `parsed_acf` の代わりに **`parsed_counts`（各キーの解析件数）と `preview_hash`（30分間有効なサーバー側キャッシュキー）** がコンパクトに返ります。

### ステップ4：`create_draft` / `update_draft` の最小引数実行（重複送信の排除）
* `preview_draft` 成功後、`create_draft` には **`preview_hash` と `idempotency_key` の2項目だけ** を渡します（サーバー側の30分キャッシュから `character_name`, `basic_fields`, `auto_input`, `screenshot_urls`, `evidence` が自動復元されるため、長いJSONを再出力する必要はありません）：
  ```json
  {
    "preview_hash": "<preview_draftで返却されたpreview_hash>",
    "idempotency_key": "char-draft-<一意のキー>"
  }
  ```
* 既存下書きを更新する `update_draft` の場合は、上記に `"post_id": <投稿ID>` を加えた3項目だけを渡します。
* 返却された `edit_url`（専用DBエディタURL）をユーザーへ提示し、人間が最終確認・公開を行います。

---

## 3. フィールド別入力規則

### ① `character_name`（必須）
* キャラクター投稿のタイトルになります（例: `"シンジ(決戦)"`）。

### ② `basic_fields`（基本データ）
ACFのトップレベルフィールド名をキーにしたオブジェクトです。作成時は **`attribute`、`species`、`rarity` の3項目が必須** です。また、**HPとATKの超化（`hp_chouka`, `atk_chouka`）も含めることが要求** されます。

| フィールド名 | 型・指定形式 | 入力ルール・注意点 |
|---|---|---|
| `name_ruby` | 文字列 | キャラ名のひらがな読み。**カッコ `()` / `（）` 内の文字列は含めない**（例: `シンジ(決戦)` → `"しんじ"`）。万一カッコ付きやカタカナで渡された場合もサーバー側で自動除去・ひらがな変換されます。 |
| `attribute` | 文字列 / 数値 / 配列 | 属性（`"火"`, `"水"`, `"木"`, `"光"`, `"闇"`, `"冥"`, `"天"` またはタームID）。`[3]` のような配列・単一値どちらでも可。「火属性」のような表記も自動解決されます。 |
| `species` | 文字列 / 数値 / 配列 | 種族（`"神"`, `"魔"`, `"英"`, `"龍"`, `"獣"`, `"霊"`, `"物"`, `"妖"` またはタームID）。 |
| `rarity` | 文字列 / 数値 / 配列 | **★6の場合**: 子ターム（通常の★6は `"なし"`、特殊枠は `"スペシャル"`, `"レジェンド"`, `"グランド"`）またはそのタームIDを指定。<br>**★5以下の場合**: レア度の数字（例: `"5"` または `"星5"`）またはそのタームIDを指定。 |
| `affiliation` | 配列（文字列 / 数値） | 所属グループ（例: `["エヴァンゲリオン"]`）。複数指定可。 |
| `available_moji_loop` | 配列（オブジェクト） | **初期使用可能文字のみ** を指定（例: `[{"available_moji": "い"}, {"available_moji": "う"}]`）。<br>※**【最重要】ア行（ぁ/ぃ/ぅ/ぇ/ぉ）やヤ行（ゃ/ゅ/ょ）の小文字（捨て仮名・拗音）は大文字（あ/い/う/え/お/や/ゆ/よ）と明確に区別して入力してください**。コトダマンDBでは「ゆ」と「ゅ」、「あ」と「ぁ」などは独立した別文字タームとして扱われます。<br>※とくせい1・2や祝福で追加される文字は `auto_input` 側で自動生成・追記されるため、ここには含めません。<br>※`moji_attr`（文字属性）と `unlock_place`（`"default"`）は省略するとサーバー側でメイン属性と `"default"` が自動補完されます。 |
| `status_auto_tf` | 真偽値 (`boolean`) | **Lv.120のキャラクターの場合は `true`** に設定します（Lv.99の場合は省略または `false`）。 |
| `hp_chouka` / `atk_chouka` | 数値 (`integer`) | **【入力要求】コエダマによるHP超化pt・ATK超化pt**。スクリーンショットのHP/ATK横にある「+〇〇」表示またはステータス超化欄から読み取って `basic_fields` に必ず含めてください（例: `500`, `250`）。※旧キー `plus_hp` / `plus_atk` もエイリアス対応。 |
| `lv_99_hp` / `lv_99_atk` | 数値 (`integer`) | Lv.99時のHP・ATK基礎値（超化分を含まない基礎値）。※旧キー `99_hp` / `99_atk` もエイリアス対応。 |
| `lv_120_hp` / `lv_120_atk` | 数値 (`integer`) | Lv.120時のHP・ATK基礎値。※旧キー `120_hp` / `120_atk` もエイリアス対応。 |
| `max_ls_hp` / `max_ls_atk` | 数値 (`number`) | リーダーとくせいの最大HP倍率・最大ATK倍率（%の数値）。 |
| `voice_actor` | 文字列 | 声優名（例: `"梶原岳人"`）。※旧キー `cv` もエイリアス対応。 |
| `pre_evo_name` | 文字列 | 進化前のキャラクター名。進化前がない場合は省略。 |
| `another_image_name` | 文字列 | 絵違いモードシフト時の名前。絵違いがない場合は省略。 |
| `実装月（わかれば実装日）` | 文字列 (`YYYY-MM-DD`) | キャラクターの実装年月日（例: `"2026-11-08"`）。※英名エイリアス `implement_date` も指定可能。 |
| `talent_hp` / `talent_atk` | 数値 (`integer`) | **【原則入力不要】** 才能開花HP・ATK。サーバー側でレアリティとLv.99ステータスから自動計算されます。手動で特定の値を固定したい場合を除き、基本的にクライアントからは入力・指定しないでください（未指定のままにする）。 |
| `magnification_estimate_tf` | 真偽値 (`boolean`) | **【AI必須: 必ず true】** 予想倍率フラグ。AIによる下書き作成時は必ず `true` を指定してください（省略時もサーバー側で自動的に `true` が強制適用されます）。 |
| `get_place` | 文字列 (`select`) | **入手方法**。**ガチャキャラなら `"gacha"`、そうでない（降臨・配布・交換所等）なら `"other"`** を指定。<br>※【重要指示】入手方法は画像だけでは判別できないことが多いため、**基本的にはユーザーに質問して確認すること**。 |
| `fuku_break` | 真偽値 (`boolean`) | **言塊突破（福突破）の有無**。**`get_place` が `"other"` かつ言塊突破対象なら必ず `true`** に設定する。ガチャキャラの場合は通常 `false`。 |
| `quest` | 配列（文字列 / 数値） | 適正クエスト（例: `["破滅級〇〇"]`）。該当する既存タームがある場合のみ指定（不明時は省略または空配列 `[]`）。 |
| `same_character` | 配列（数値） | 変身前・変身後、モードシフト、絵違い、トークンなどの同一キャラクター関連投稿ID配列（例: `[1234, 5678]`）。表示順に並べる（該当なし時は省略または空配列 `[]`）。 |

> [!TIP]
> フィールドの選択肢や説明を確認したい場合は、MCPツール `get_field_schema` を呼び出してください。[documents/mcp/mcp-field-manual.json](file:///var/www/html/wp-content/themes/cocoon-child-master/documents/mcp/mcp-field-manual.json) に記載された人間向けガイドも `manual` プロパティとして一緒に返却されます。

### ③ `auto_input`（ゲーム内文言の自動変換）
ゲーム内のスキル・とくせい説明文をそのまま文字列として渡します。

| キー名 | 対象項目 | 備考 |
|---|---|---|
| `auto_input_waza_name` | わざ名 | わざの名称テキスト |
| `auto_input_waza` | わざ効果文 | ゲーム内のわざ説明文 |
| `auto_input_sugowaza_name` | すごわざ名 | すごわざの名称テキスト |
| `auto_input_sugowaza_condition` | すごわざ発動条件 | 例: `"「い,う」を含む4文字以上のことば〜"` 等 |
| `auto_input_sugowaza` | すごわざ効果文 | ゲーム内のすごわざ説明文 |
| `auto_input_trait1` | とくせい1 | とくせい1の説明文（文字追加・ギミック耐性・各種補正を含む） |
| `auto_input_trait2` | とくせい2 | とくせい2の説明文 |
| `auto_input_blessing` | 祝福とくせい | 祝福のデータ（下記「③-B. 祝福・EX祝福の自動入力仕様」参照） |
| `auto_input_ex_blessing` | EX祝福とくせい | EX祝福のデータ（構造は `auto_input_blessing` と同一） |
| `auto_input_leader` | リーダーとくせい | リーダーとくせいの説明文 |
| `auto_input_kotowaza` | コトワザ | コトワザの説明文 |

#### ③-B. 祝福（`auto_input_blessing`）・EX祝福（`auto_input_ex_blessing`）の自動入力仕様

> [!IMPORTANT]
> **上限項目数**: 祝福・EX祝福はゲーム仕様上、それぞれ **最大8項目（MAX 8）** に制限されます。8項目を超えて指定した場合は `VALIDATION_FAILED`（`items_limit_exceeded`）となります。

祝福の自動入力には、**新形式（`items` 配列形式：推奨）** と **旧形式（`template` ＋ `rows` 形式）** の2種類があり、いずれも構造化オブジェクト形式（またはテキスト記法）で指定します。

##### 1. 新形式（`items` 配列：トークンと自由入力の混在並び順指定）
トークン方式と自由入力方式をユーザーやAIが任意の順序で並べて登録できます。指定した配列の順序通りにACF行データが構築されます。

```json
{
  "items": [
    { "type": "token", "token": "hp_up" },
    { "type": "token", "token": "attr_killer", "target": "火" },
    { "type": "token", "token": "species_killer", "target": "神" },
    { "type": "token", "token": "attr_mitigation", "target": "火" },
    { "type": "free", "point": "20", "text": "ドロー時に味方のATKを少し強化" },
    { "type": "token", "token": "atk_up" }
  ]
}
```

* **トークン項目（`"type": "token"`）**:
  * `token`: トークン識別子（下表参照）。
  * `target`: 属性・種族が必要なトークンの場合、対象となる属性名（`"火"`, `"水"` 等）または種族名（`"神"`, `"魔"` 等）を指定（サーバー側でタームIDへ自動解決）。
* **自由入力項目（`"type": "free"`）**:
  * `point`: 必要祝福ポイント（例: `"20"`）。
  * `text`: ゲーム内のとくせい文言。

###### 利用可能な祝福トークン一覧
| トークン識別子 | 名称 | 対象（`target`） | 説明 |
|---|---|---|---|
| `hp_up` | HPアップ | 不要 | 自身のHPアップ（レベル制CSV） |
| `atk_up` | ATKアップ | 不要 | 自身のATKアップ（レベル制CSV） |
| `attr_killer` | 属性キラー | 属性（例: `"火"`） | 等差数列型キラー（★6: 10~20%, ★5: 5~15%） |
| `species_killer` | 種族キラー | 種族（例: `"神"`） | 等差数列型キラー（★6: 15~25%, ★5: 10~20%） |
| `attr_mitigation` | 属性軽減 | 属性（例: `"火"`） | 等差数列型軽減（★6: 10~20%, ★5: 5~15%） |
| `dodge` | 回避率UP | 不要 | 回避率UP（★6: 1~10%, ★5: 4~12%） |
| `attr_30_killer` | 属性キラー30% | 属性（例: `"木"`） | 非レベル制30%キラー |
| `group_draw_buff` | グループドローバフ | 省略（キャラ所属連動） | グループATKバフ |
| `atk_up_per_unit` | ATKアップ(ユニット数依存) | 不要 | ユニット数依存ATKアップ |
| `comb_plus` | コンボ＋ | 不要 | コンボ＋1（20pt） |
| `gimmick_{slug}` | ギミック耐性 | 不要 | ギミック耐性解放（例: `gimmick_change`, `gimmick_needle` 等。省略形 `change` や日本語名 `チェンジガード` も指定可） |
| `gimmick_super_{slug}` | ギミック・スーパー化 | 不要 | スーパーギミック耐性解放（例: `gimmick_super_change`, `gimmick_super_needle`。省略形 `super_change` や日本語名 `チェンジガード・スーパー化` も指定可。ゲーム内では黄色背景アイコン） |

##### 2. 旧形式（`template` ＋ `rows` 形式：後方互換）
```json
{
  "template": "spe2_atkup",
  "attrs": ["火", "水"],
  "species": ["神", "魔"],
  "rows": [
    { "point": "20", "text": "ドロー時に味方のATKを少し強化" },
    { "point": "50", "text": "文字変換に「あ」を追加する" }
  ]
}
```

* **`template`（タイプ1）**:
  * `lib/ゲーム内文言ーACF-対応表.csv` の種別 `祝福テンプレート` に登録されたテンプレート名を指定します（使用しない場合は `""` または省略）。
  * テンプレート定義内の属性・種族キラーや属性軽減は、何番目のパラメータを適用するか明示するため **インデックス必須（例: `attr_killer1`, `species_killer1`, `species_killer2` 等）** となっています。
* **`attrs` / `species`（タイプ1のキラー対象配列）**:
  * **そのキャラクター自身の属性・種族ではなく、祝福のキラー対象（および属性軽減対象）となる属性・種族を配列形式で指定**します（テンプレートの `attr_killer1`, `attr_killer2` ... の番号順に対応）。
* **レアリティ（★5）連動によるキラー数値の自動補正**:
  * `basic_fields.rarity` が `"5"`（星5）の場合、テンプレートのキラー効果値が自動的に **`-5` 補正** されます（属性キラー: `10~20%` → `5~15%`、種族キラー: `15~25%` → `10~20%`、属性軽減: `10~20%` → `5~15%`）。
* **`rows`（タイプ2の個別行配列）**:
  * 1要素につき `{"point": "<必要祝福pt>", "text": "<とくせい文言>"}` を指定します。
  * サーバー側で自動的に `pt_pattern = "single"`（非レベル制）および `need_point` が設定され、文字追加（`available_moji_loop` の `unlock_need_point`）やすごわざ条件追加（`sugowaza_condition` の `get_palce = "blessing" | "ex_blessing"`, `need_blessing_point`）も自動で振り分けられます。
* **保存順序**:
  * 旧形式でタイプ1とタイプ2の両方を指定した場合、必ず **タイプ1 → タイプ2** の順で保存されます。
* **タイプ1・タイプ2の判定ルールと `update_draft` での追記（`replace_existing: false`）**:
  1. `get_place` が `"gacha"`（ガチャキャラ）の場合：原則として新形式 `items` またはタイプ1を使用します。
  2. `get_place` が `"other"`（降臨・配布等）の場合：原則として新形式 `items` またはタイプ2（`rows`）を使用します。
  3. **両方を持つキャラクターでユーザーから事前指定がない場合**：
     * まず **タイプ1のみ** を `auto_input_blessing` に指定して下書きを作成（`create_draft`）し、**タイプ2の入力有無をユーザーへ確認**してください。
     * ユーザー確認後に `update_draft` でタイプ2を追加する際は、既存のタイプ1行を上書きせず末尾に追記するため、必ず **`"replace_existing": false`** を指定してください（`preview_draft` 実行時にも `"replace_existing": false` を含めます）。

##### 祝福アイコンの視覚的識別法則（AI読み取りガイド）

スクリーンショット上の祝福アイコンは、**「ベースの形状」** と **「付加マーク（左下・右下バッジ）」** の組み合わせによって正確に判別できます。

| アイコン要素 | 視覚的特徴（見た目） | 判定される祝福項目 |
|---|---|---|
| **属性ベース** | **葉っぱ型**（火/水/木/光/闇/冥/天の属性色・シンボル） | 属性関連効果（属性キラー・属性軽減等） |
| **種族ベース** | **文字表記**（「神」「魔」「英」「龍」「獣」「霊」「物」「妖」） | 種族関連効果（種族キラー等） |
| **HPアイコン** | **青いハート** | HPアップ（`hp_up`） |
| **ATKアイコン** | **黄色い剣**（＋**赤上矢印**） | ATK基礎値UP（`atk_up` / `atk_up_per_unit`）またはグループドローバフ（`group_draw_buff`） |
| **軽減マーク** | **左下に青いシールド（盾マーク）** | 被ダメージ軽減（葉っぱ＋左下青盾 ＝ `attr_mitigation`） |
| **キラーマーク** | **右下に黄色いギザギザ** | キラー（葉っぱ＋右下黄色ギザ ＝ `attr_killer` / `attr_30_killer`、文字＋右下黄色ギザ ＝ `species_killer`） |
| **コンボマーク** | **Combo +1 等のコンボ表記** | コンボ＋（`comb_plus`） |

> [!TIP]
> * **属性キラー vs 属性軽減**: いずれも葉っぱ型ですが、**右下に黄色いギザギザ**がある場合は「キラー」、**左下に青いシールド**がある場合は「軽減（mitigation）」です。
> * **ATK基礎値UP vs グループドローバフ**: いずれも黄色い剣に赤上矢印ですが、所属アイコンの表示や「味方のATK付与」文言を伴う場合は `group_draw_buff`、自身の基礎ステータス向上の場合は `atk_up` です。

##### 祝福アイコンの並び順 ↔ テンプレート種別（`template`）対応表

スクリーンショット内の祝福スロットに並んでいるアイコンの形状・マーク順から、合致するテンプレート名を特定します。

| テンプレート名 (`template`) | CSV定義 (`祝福テンプレート`) | スクリーンショット上のアイコン並び順・見分け方 |
|---|---|---|
| `only_core` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1}` | **【基本4枠のみ】**<br>青ハート（HP） → 葉っぱ＋黄色ギザ（属性K1） → 種族文字＋黄色ギザ（種族K1） → 葉っぱ＋青盾（属性軽減1） の4スロット構成。 合うテンプレがないときにこれをカスタムしてください。|
| `spe2_atkup` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,species_killer2,atk_up}` | **【ガチャ標準（種族K2＋ATKUP）】**<br>基本4枠（青ハート → 属性K1 → 種族K1 → 属性軽減1） → **種族文字＋黄色ギザ（種族K2）** → **黄色剣＋赤上矢印（ATK UP）**。最もポピュラーな構成。 |
| `attr30_atk_up` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,attr_30_killer2,atk_up}` | **【属性キラー30%＋ATKUP】**<br>基本4枠 → **葉っぱ＋黄色ギザ（属性K2/30%）** → **黄色剣＋赤上矢印（ATK UP）**。 |
| `spe2_attr30` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,species_killer2,attr_30_killer2}` | **【種族K2＋属性キラー30%】**<br>基本4枠 → **種族文字＋黄色ギザ（種族K2）** → **葉っぱ＋黄色ギザ（属性K2/30%）**。ATK UPの代わりに属性30%キラー。 |
| `comb_plus` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,attr_killer2,comb_plus}` | **【属性K2＋コンボ+1】**<br>基本4枠 → **葉っぱ＋黄色ギザ（属性K2）** → **Combo+1 アイコン**。 |
| `colab_special` | `{attr_killer1,attr_killer2,attr_killer3,species_killer1,group_draw_buff,attr_30_killer4}` | **【コラボスペシャル】**<br>**HPハート・軽減盾がなく**、葉っぱ＋黄色ギザ（属性K1〜3）×3 → 種族文字＋黄色ギザ（種族K1） → 黄色剣＋赤上矢印（グループバフ） → 葉っぱ＋黄色ギザ（属性K4/30%）。 |
| `original_special` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,species_killer2,group_draw_buff}` | **【オリジナルスペシャル】**<br>基本4枠 → **種族文字＋黄色ギザ（種族K2）** → **黄色剣＋赤上矢印（グループドローバフ）**。 |
| `twin_attr_atk_up` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,attr_killer2,atk_up}` | **【W属性（属性K2＋ATKUP）】**<br>基本4枠 → **葉っぱ＋黄色ギザ（属性K2）** → **黄色剣＋赤上矢印（ATK UP）**。 |
| `twin_attr_attr30` | `{hp_up,attr_killer1,attr_killer2,species_killer1,species_killer2,attr_30_killer2}` | **【W属性・W種族】**<br>青ハート（HP） → **葉っぱ＋黄色ギザ（属性K1〜2）×2** → **種族文字＋黄色ギザ（種族K1〜2）×2** → **葉っぱ＋黄色ギザ（属性K2/30%）**。（軽減盾なし） |
| `spe2_perunit` | `{hp_up,attr_killer1,species_killer1,attr_mitigation1,species_killer2,atk_up_per_unit}` | **【種族K2＋ユニット依存ATKUP】**<br>基本4枠 → **種族文字＋黄色ギザ（種族K2）** → **黄色剣＋赤上矢印（ユニット数依存ATK UP）**。 |

### ④ 画像（`screenshot_urls`）の仕様
* `screenshot_urls` を省略して `create_draft` を実行した場合、準備中画像（`https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png`）が自動的にセットされます。
* `screenshot_urls` に `https://www.kotodaman-db.com/...`（同一ドメイン）の画像URLを指定した場合、既存メディアの検索またはダウンロードが行われ、投稿のアイキャッチ（`_thumbnail_id`）および進化後画像（`character_image`）に自動設定されます。あわせてURL一覧が投稿メタ `_koto_mcp_screenshot_urls` に記録されます。

### ⑤ 下書き取得（`get_draft`）による保存内容の確認
下書きを作成・更新した後、`get_draft(post_id: <投稿ID>)` を呼び出すことで、意図通りにデータが保存されているかを確認できます。

* **「未設定」と「返ってきていない」の明確な区別**:
  * 定義された各フィールドについて、値が未設定の場合はキーを省略せず明示的に `null`（真偽値は `false`、配列は `[]`）として返却されます。
* **日付の正規化照合**:
  * 実装日フィールド（`実装月（わかれば実装日）` / `implement_date`）は送信時と同じ `YYYY-MM-DD` 形式（未設定時は `null`）に正規化されて返却されます。
* **自動計算値・解析値の確認**:
  * `basic_fields` 内に `voice_actor`（`cv`）、`pre_evo_name`、`another_image_name`、`waza_name`、`sugowaza_name`、`first_trait_name`、`second_trait_name`、`lv_120_hp`、`lv_120_atk`、`talent_hp`、`talent_atk` が含まれます。
  * また、トップレベルに `computed` オブジェクトが付与され、サーバー側で自動計算されたLv.120ステータスや才能開花ステータス、自動入力から抽出されたスキル名を直接確認できます。

---

## 4. テストフラグ（`test_mode`）による出力切り替え

通常運用時のトークン消費を抑えつつ、パーサー改修時やデバッグ時には詳細な解析結果を確認できるよう、初期値 `false` の `test_mode` フラグで出力を制御しています。

| 対象 | 通常モード（`test_mode: false` / デフォルト） | テストモード（`test_mode: true` / `--test-mode`） |
|---|---|---|
| `preview_draft` / `validate` | `parsed_counts`（各キーの解析行数）と `preview_hash` のみ返却 | 上記に加え、`parsed_acf` 全構造を返却、文字変換デバッグログ出力 |
| `create_draft` / `update_draft` | `post_id`, `status`, `edit_url`, `content_hash`, `screenshot_urls` を返却 | 上記に加え、`parsed_acf` 全構造を返却 |
| `get_field_schema` | `basic_fields` 対象フィールドのみ（`null` 属性を省いたコンパクト定義） | 自動入力管理下の内部Repeater等を含む全67フィールドの完全定義を返却 |
| `php lib/acf/test_auto_input.php` | カテゴリ別 PASS/FAIL 集計（5行）のみ出力 | `--test-mode`（または `--verbose`）で全ケースの整形JSONとデバッグログを出力 |
| `php lib/acf/mcp/prepare_screenshots.php` | `{"contact_sheet":"...","count":N}` の1行JSONのみ出力 | `--test-mode` でグリッド寸法、ファイルサイズ、処理時間、各パネルのトリミング座標を出力 |

---

## 5. AIクライアントの禁止事項とエラー対処

### 禁止事項
1. **推測入力の禁止**: スクリーンショットに存在しない値を推測して埋めてはいけません。
2. **雛型・特殊テンプレート記事の操作禁止**: 雛型記事（ID: `2947`, `2023`, `2637`, `2638`, `3845`, `4603`, `5294`, `3683` およびタイトルに「雛型」「雛形」を含む投稿）は更新・取得・ゴミ箱移動の対象外です（保護されておりMCP経由の操作は拒否されます）。
3. **個別高解像度画像の複数回読み取り禁止**: 個別のPNGを何枚も `read_file` せず、`prepare_screenshots.php` で生成した `contact_sheet.jpg` を1回だけ読み取ってください。
4. **事前単発CLIテストの連発禁止**: 下書き作成フローの中で `php lib/acf/test_auto_input.php` を文言ごとに何回も実行してターン数を浪費しないでください。
5. **ペイロードの重複送信禁止**: `create_draft` / `update_draft` 呼び出し時に `preview_draft` と同じ `basic_fields` / `auto_input` を再送せず、`preview_hash` と `idempotency_key`（更新時は＋`post_id`）のみを渡してください。
6. **公開操作の禁止**: 投稿の公開（`publish`）や公開相当の処理を試みてはいけません。公開は必ず人間がDBエディタまたは管理画面で確認して行ってください。

### エラー発生時のリカバリ手順
* **`VALIDATION_FAILED` を受けた場合**:
  * エラー詳細（`missing_fields` / `invalid_fields` の `field` と `reason`）を確認し、**最大3回まで**該当項目だけを読み直して修正します。
  * `reason: "unparsed_text"` の場合は、`auto_input` に渡した文言にOCR誤読（文字化けや不要な改行・記号）がないか確認します。
  * 3回読み直しても解決しない場合は処理を停止し、人間に確認を求めてください。
* **`PREVIEW_HASH_MISMATCH` を受けた場合**:
  * 無視して再利用せず、入力を固定して再度 `preview_draft` を実行し、新しい `preview_hash` を取得してください。

---

## 6. 運用・開発チェックリスト

### 利用者・AI運用チェックリスト
- [ ] 1キャラクター分のスクリーンショットが揃っている（複数枚の場合は `prepare_screenshots.php` で合成済み）
- [ ] `character_name` が正確である
- [ ] `basic_fields` に必須項目（`attribute`, `species`, `rarity`）と基本データを入れた（`name_ruby` にカッコ内文字列を含めていない、ア行・ヤ行の小文字を大文字と区別して入力した、HP・ATK超化 `hp_chouka`, `atk_chouka` を設定した）
- [ ] わざ・すごわざ・とくせい等のゲーム内文言を `auto_input` へ入れた
- [ ] `preview_draft`（またはREST APIの `dry_run: true`）を実行してエラーがないことを確認した
- [ ] `create_draft` を `preview_hash` + `idempotency_key` で実行した
- [ ] 返却された `edit_url`（DBエディタ画面）を開き、人間が最終確認を行ってから公開した

### 開発者チェックリスト
- [ ] PHP構文チェック（`php -l`）を実行した
- [ ] 自動入力ロジックやCSV対応表を変更した場合、`php lib/acf/test_auto_input.php` で全件回帰テストがPASSすることを確認した
- [ ] 基本データ（`basic_fields`）と自動入力データ（`auto_input`）の責務を分離している
- [ ] 権限チェック（OAuthスコープ、`edit_posts`、管理者承認）を維持している
- [ ] 投稿を意図せず `publish` にしていない
