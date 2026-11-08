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
| `auto_input_blessing` | 祝福とくせい | 祝福画面のテキスト |
| `auto_input_leader` | リーダーとくせい | リーダーとくせいの説明文 |
| `auto_input_kotowaza` | コトワザ | コトワザの説明文 |

### ④ 画像（`screenshot_urls`）の仕様
* `screenshot_urls` を省略して `create_draft` を実行した場合、準備中画像（`https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png`）が自動的にセットされます。
* `screenshot_urls` に `https://www.kotodaman-db.com/...`（同一ドメイン）の画像URLを指定した場合、既存メディアの検索またはダウンロードが行われ、投稿のアイキャッチ（`_thumbnail_id`）および進化後画像（`character_image`）に自動設定されます。あわせてURL一覧が投稿メタ `_koto_mcp_screenshot_urls` に記録されます。

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
