# コトダマンDB MCP連携ガイド

## 1. この機能の目的

この機能は、1キャラクター分のスクリーンショットをAIで読み取り、WordPress上にキャラクターの下書きを作成するための入口です。

現在の構成では、AIまたはMCPクライアントがスクリーンショットから情報を読み取り、次の2種類に分けてREST APIへ送信します。

| 種類 | 例 | 保存方法 |
|---|---|---|
| `basic_fields` | 属性、種族、使用可能文字、ステータス、倍率 | ACFフィールドへ直接保存 |
| `auto_input` | わざ、すごわざ、とくせい、リーダーとくせい、祝福 | 既存のCSV対応表・自動入力パーサーでACFへ変換 |

投稿は常にWordPressの`draft`として作成されます。現時点で自動公開は行いません。

## 2. 現在できること

- キャラクター投稿を新規作成する
- 投稿タイトルを設定する
- トップレベルのACFフィールドへ基本データを直接保存する
- わざ・とくせい等のゲーム内文言を既存パーサーでACFデータへ変換する
- AI側で読み取ったスクリーンショットを投稿のメディアとして添付する
- 後から用意する高画質画像のURLを投稿メタへ保存する
- `dry_run`で投稿を作成せず、自動入力の解析結果を確認する
- 未知のACFフィールドや解析できなかった入力を警告として返す
- 作成した下書きのIDとDBエディタURLを返す

## 3. 基本的な運用フロー（最短・省トークン手順）

### AIエージェント向け最短4ステップ操作ガイド

1. **スクリーンショットの一括前処理＆1枚読み取り（Visionトークン75〜85%削減）**:
   - ユーザーからZIPファイルまたは複数枚のスクリーンショットが渡されたら、個別の高解像度PNGを1枚ずつ `read_file` してはいけません。
   - まず以下の前処理スクリプトを実行し、上下の不要なスマホステータスバー／ナビバーをトリミングしてタイル状に1枚へ合成した `contact_sheet.jpg` を生成します：
     ```bash
     php lib/acf/mcp/prepare_screenshots.php <ZIPまたは画像ディレクトリパス> [出力先ディレクトリ]
     ```
   - 出力された `contact_sheet.jpg` **1枚だけ** を `read_file` で読み取り、全画面の情報を1ターンで抽出します。
2. **ペイロード構築（ターム自動解決の活用）**:
   - `basic_fields` のタクソノミー項目（`attribute`, `species`, `rarity`, `affiliation`, `available_moji_loop` 等）は、タームIDだけでなく日本語名やスラッグ（例: `"attribute": "水"`, `"species": "神"`, `"rarity": "星6"` または `"なし"`, `"affiliation": ["エヴァンゲリオン"]`, `"available_moji_loop": [{"available_moji": "い"}]`）でもサーバー側で自動解決されます。
   - 所属グループ（`affiliation`）やイベント（`event`）がDBに存在するか確認が必要な場合のみ、`get_terms(taxonomy: "affiliation", search: "キーワード")` で絞り込み検索します（全件取得は避ける）。
3. **`preview_draft` の一括実行（単発テストの省略）**:
   - 事前に `php lib/acf/test_auto_input.php` を文言ごとに個別実行してターンを消費せず、抽出した全フィールドをまとめて MCP ツール `preview_draft` に渡します。
   - 通常モード（`test_mode: false`、初期値）では、巨大な `parsed_acf` の代わりに `parsed_counts`（各キーの解析件数）と `preview_hash` がコンパクトに返ります。
4. **`create_draft` の最小引数実行（重複送信の排除）**:
   - `preview_draft` 成功後、`create_draft` には **`preview_hash` と `idempotency_key` の2項目だけ** を渡します（サーバー側の30分キャッシュから `character_name`, `basic_fields`, `auto_input` が自動復元されるため、長いJSONを再出力する必要はありません）：
     ```json
     {
       "preview_hash": "<preview_draftで返却されたpreview_hash>",
       "idempotency_key": "char-draft-<一意のキー>"
     }
     ```
   - 返却された `edit_url` をユーザーへ提示し、人間が最終確認・公開を行います。

AIが不確かな値を推測した場合は、空欄にするか警告対象にし、推測値を確定値として保存しない運用が推奨です。

### テストフラグ（`test_mode`）による詳細ログ出力の切り替え

通常運用時のトークン消費を抑えつつ、テスト・デバッグ時には詳細な解析結果を確認できるよう、初期値 `false` の `test_mode` フラグで出力を制御しています。

- **MCPツール（`get_field_schema`, `validate`, `preview_draft`, `create_draft`, `update_draft`） / REST API**:
  - 通常時（`test_mode: false`）: `parsed_counts` のみ返却、`get_field_schema` は `basic_fields` 対象項目のみコンパクト返却、文字変換デバッグログ非出力。
  - テスト時（`"test_mode": true`）: `parsed_acf` 全構造を返却、`get_field_schema` で内部Repeater含む全67フィールドを返却、デバッグログ出力を有効化。
- **CLIスクリプト**:
  - `php lib/acf/test_auto_input.php`: 通常時はカテゴリ別PASS/FAIL集計（5行）のみ出力。`--test-mode`（または `--verbose`）付与時に全37ケースの整形JSONとデバッグログを出力。
  - `php lib/acf/mcp/prepare_screenshots.php`: 通常時は1行JSONのみ出力。`--test-mode` 付与時に各パネルの元サイズ・クロップ座標・処理時間を出力。

## 4. REST API

### エンドポイント

```text
POST /wp-json/kotodaman/v1/mcp/character-draft
```

WordPressのREST API認証を使用します。外部MCPから接続する場合は、WordPressのApplication Passwordsなど、通常の認証機構を使用してください。匿名アクセスは許可されず、`edit_posts`権限が必要です。

### リクエスト例

```json
{
  "character_name": "サンプルキャラ",
  "basic_fields": {
    "name_ruby": "さんぷるきゃら",
    "available_moji_loop": [
      {
        "available_moji": "さ"
      }
    ]
  },
  "auto_input": {
    "auto_input_waza_name": "サンプルアタック",
    "auto_input_waza": "敵単体に強力な火属性攻撃",
    "auto_input_trait1": "火属性の敵に対して攻撃力が上昇"
  },
  "screenshots": [
    {
      "name": "基本情報",
      "data": "data:image/png;base64,..."
    }
  ],
  "screenshot_urls": [
    "https://example.com/character/high-quality.png"
  ],
  "dry_run": true
}
```

### 主な入力項目

#### `character_name`

必須の文字列です。新規`character`投稿のタイトルになります。

#### `basic_fields`

ACFのトップレベルフィールド名をキーにしたオブジェクトです。ACF JSONで定義されているトップレベルフィールドだけが保存対象です。未知のフィールドは保存せず、レスポンスの`warnings`に記録します。

RepeaterやGroupの複雑な内部形式をAIが直接組み立てるのではなく、既存のACF定義・既存データの形式を確認してから追加するのが安全です。属性・種族などのターム系フィールドは、サイトで使用している値形式（通常はタームID配列）に合わせます。

- **`name_ruby`**: キャラクター名のひらがな読みを指定します。**`()` / `（）` 内の文字列は含めません**（例: `シンジ(決戦)` の場合は `しんじ`）。万一カッコ付きで渡された場合もサーバー側で自動除去されます。
- **`status_auto_tf`**: Lv.120のキャラクターの場合は `true` に設定します。
- **`rarity`**: ★6の場合は子ターム（「なし」「スペシャル」「レジェンド」「グランド」のいずれか、通常の★6は「なし」のタームID）を指定します。★5以下の場合はレア度の数字（例: `5`）に対応するタームIDを指定します。

#### `auto_input`

既存の自動入力欄に対応するキーと、ゲーム内文言のオブジェクトです。主なキーは次のとおりです。

| キー | 用途 |
|---|---|
| `auto_input_waza_name` | わざ名 |
| `auto_input_waza` | わざ本文 |
| `auto_input_sugowaza_name` | すごわざ名 |
| `auto_input_sugowaza_condition` | すごわざ発動条件 |
| `auto_input_sugowaza` | すごわざ本文 |
| `auto_input_trait1` / `auto_input_trait2` | とくせい |
| `auto_input_blessing` | 祝福 |
| `auto_input_leader` | リーダーとくせい |
| `auto_input_kotowaza` | コトわざ |

これらは`lib/ゲーム内文言ーACF-対応表.csv`を使って解析されます。対応表にない表現は自動的に正しく変換できない可能性があるため、レスポンスと下書きの両方を確認します。

#### `screenshots`

AI側で読み取った画像を投稿へ添付する場合に使用します。各要素は`data`にPNG、JPEG、またはWebPのdata URIを指定します。1枚あたり10MB、最大20枚です。

画像をAPIへ送らず、AI側の一時入力としてだけ使うこともできます。

#### `screenshot_urls`

後から用意する高画質画像のURLを指定する欄です。現在の実装ではURLを取得せず、次の投稿メタへ保存します。

```text
_koto_mcp_screenshot_urls
```

将来、URLからメディアを取得してACFの画像フィールドや本文へ登録する機能を追加できます。

#### `dry_run`

`true`の場合、投稿を作成せずに自動入力の解析だけを実行します。初回運用では必ず`dry_run`で確認することを推奨します。

### レスポンス

成功時には、作成した投稿ID、下書き状態、DBエディタURL、添付画像ID、処理した入力キー、警告が返ります。

```json
{
  "post_id": 1234,
  "post_status": "draft",
  "edit_url": "https://example.com/wp-admin/admin.php?page=koto-acf-editor&edit_post_id=1234",
  "screenshot_ids": [5678],
  "screenshot_urls": ["https://example.com/character/high-quality.png"],
  "basic_fields": ["name_ruby"],
  "auto_input": ["auto_input_waza"],
  "warnings": []
}
```

## 5. MCPクライアント側の役割

現在のブランチにあるのはWordPress側のREST APIです。Claude Code、Antigravity、その他のMCPクライアントから利用する場合は、次の処理を行うMCPサーバーまたは接続設定が別途必要です。

- スクリーンショットを受け取る
- 画像から文字・数値・選択肢を読み取る
- 基本データと自動入力データを分類する
- `dry_run`を呼び出す
- 解析結果を確認してから本作成を呼び出す
- 返却された下書きURLと警告を利用者へ提示する

ゲーム画面の取得を自動化する場合も、まずはエミュレータの画面操作、スクリーンショット取得、OCRを分離して実装します。ゲームを自由操作させる処理を最初から必須にせず、手動で取得した画像を入力する運用から始めると検証しやすくなります。

## 6. 開発者向け構成

### WordPress側

- `lib/acf/mcp/server.php`
  - MCP Streamable HTTPサーバー（`get_field_schema`, `get_terms`, `search_character`, `validate`, `preview_draft`, `create_draft`, `update_draft`, `trash_draft`）
  - OAuth 2.1 / PKCE認可、レート制限、Idempotency-Key管理、プレビューキャッシュ、`test_mode` 制御
- `lib/acf/mcp/post-mcp.php`
  - RESTルート登録（`/wp-json/kotodaman/v1/mcp/character-draft`）
  - タクソノミー名／スラッグ／表記ゆれのタームID自動解決（`koto_mcp_resolve_taxonomy_term_id`）
  - `available_moji_loop` の正規化と初期属性補完
  - 基本ACF値の直接保存・自動入力パーサー呼び出し・スクリーンショット保存
- `lib/acf/mcp/prepare_screenshots.php`
  - 複数スクリーンショット・ZIPの展開、上下バーのトリミング、タイル状コンタクトシート（`contact_sheet.jpg`）合成CLIスクリプト
- `lib/acf/auto-input/acf-auto-input-parsers.php`
  - わざ・とくせい等の文言解析
- `lib/acf/auto-input/acf-auto-input-wp-acf.php`
  - 解析結果のACF更新
  - キャラクター下書き作成
- `lib/ゲーム内文言ーACF-対応表.csv`
  - 文言とACF値の対応表
- `acf-json/`
  - ACFフィールド定義

`lib/acf/acf-auto-input.php`からMCP連携ファイルを読み込んでいます。関数名を追加する場合は、既存の関数名と衝突しない`koto_mcp_`接頭辞を使用します。

### 変更時の原則

1. 基本データと自動入力データを混ぜない。
2. 自動公開を追加しない。公開機能が必要になっても、明示的な別操作にする。
3. 未知のフィールドや未解析文言は捨てず、警告として返す。
4. 既存ACFパーサーを再利用し、MCP側に同じ解析ロジックを複製しない。
5. 新しい入力キーを追加したら、パーサーの振り分けとCSV対応表を同時に更新する。
6. 画像URLを取得する機能を追加する場合は、許可ドメイン、ファイルサイズ、MIMEタイプ、タイムアウトを制限する。
7. 投稿作成・ACF更新・画像保存に失敗した場合は、成功レスポンスに見せかけず明示的なエラーを返す。

## 7. 今後の拡張候補

### 優先度高

- `screenshot_urls`から画像を取得し、WordPressメディアへ登録する
- AIの解析結果と既存ACF値の差分プレビューを返す
- 既存キャラクター検索と更新エンドポイントを追加する
- 必須フィールド、ターム、数値範囲のサーバー側バリデーションを追加する
- 解析元スクリーンショットと入力値の対応をログへ残す

### 優先度中

- ACF定義からAI向けのフィールドスキーマを自動生成する
- パーサーの未解析文言を一覧化する
- 画像URLやメディアIDをACFの画像フィールドへ反映する
- 下書きに「AI生成」「要確認」などの管理用メタデータを付ける
- MCPツールを`preview`、`create_draft`、`update_draft`、`validate`に分割する

### 将来候補

- AndroidエミュレータとADBを使った画面取得
- OCR結果の信頼度に応じた人間確認フロー
- 複数キャラクターのバッチ処理
- 変更履歴・ロールバック
- テスト用の代表スクリーンショットと期待ACF JSONによる回帰テスト

## 8. セキュリティと運用上の注意

- REST APIを匿名公開しない。
- Application Passwordsなどの認証情報をリポジトリへ保存しない。
- MCP側でAPI URL、認証情報、投稿IDをログへ出す場合はマスクする。
- 画像data URIは大きくなりやすいため、必要以上に送信しない。
- 下書き作成後は必ず`warnings`を確認する。
- AIが推測した値は、ゲーム画面で確認できるまで公開しない。
- 画像URL取得を追加する場合、SSRF対策として外部URLを無制限に取得しない。
- WordPress本番環境で試す前に、テスト用投稿タイプまたはステージング環境で検証する。

## 9. 最小チェックリスト

### 利用者

- [ ] 1キャラクター分の画像が揃っている
- [ ] キャラクター名が正しい
- [ ] 基本データを`basic_fields`へ入れた
- [ ] わざ・とくせい文言を`auto_input`へ入れた
- [ ] まず`dry_run`を実行した
- [ ] `warnings`が空または内容を確認した
- [ ] 下書き画面でACFを確認した
- [ ] 公開前に画像とゲーム内情報を再確認した

### 開発者

- [ ] PHP構文チェックを実行した
- [ ] 既存の自動入力処理を壊していない
- [ ] 権限チェックを維持した
- [ ] 未知の入力を警告またはエラーとして扱った
- [ ] 投稿を意図せず公開していない
- [ ] 新しい入力キーの対応表とテストを更新した
