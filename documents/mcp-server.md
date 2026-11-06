# コトダマンDB MCPサーバー

## 接続先

MCPのStreamable HTTPエンドポイントは次の形式です。

```text
${KOTO_MCP_BASE_URL}/mcp
```

`KOTO_MCP_BASE_URL`には、ドメインだけ、またはWordPress REST APIの名前空間までを指定できます。

```text
https://kotodaman-db.com/wp-json/kotodaman/v1
http://localhost:8080
```

実際のURLや認証情報は環境変数・クライアント設定で管理し、リポジトリには保存しません。

## Antigravity設定例

Antigravity CLI (`agy`) を使って HTTP MCP サーバーを登録する場合：

```bash
# ローカル (kotodaman-db.local)
agy mcp add --header "Authorization: Bearer <TOKEN>" kotodaman-db http://kotodaman-db.local/wp-json/kotodaman/v1/mcp

# ローカル (localhost:8080)
agy mcp add --header "Authorization: Bearer <TOKEN>" kotodaman-db http://localhost:8080/wp-json/kotodaman/v1/mcp
```

設定ファイル (`~/.gemini/config/mcp_config.json`) へ直接記載する場合：

```json
{
  "mcpServers": {
    "kotodaman-db": {
      "serverUrl": "http://kotodaman-db.local/wp-json/kotodaman/v1/mcp",
      "headers": {
        "Authorization": "Bearer <TOKEN>"
      }
    }
  }
}
```

OAuth対応の認可画面を利用できるクライアントでは、認可エンドポイント `${SITE_URL}/mcp/oauth/authorize` へ移動します。管理者・編集者は即時認可、それ以外のユーザーは申請状態になります。管理者はWordPress管理画面の「ユーザー > MCP利用申請」から承認します。

`create` スコープ（下書き作成・更新・削除）を要求する場合は、クライアント名・リダイレクトURI・要求スコープを表示した同意画面で明示承認が必要です。`redirect_uri` はクライアント登録時の値と完全一致しないと認可コードを発行しません。

## Muse設定

Museのカスタムコネクタで、MCPリソースURIに次を指定します。

```text
${KOTO_MCP_BASE_URL}/mcp
```

OAuthメタデータは次のURLから取得できます。

```text
${SITE_URL}/.well-known/oauth-protected-resource
${SITE_URL}/.well-known/oauth-authorization-server
```

MCP実行時にトークンが無効・期限切れ・欠落している場合、サーバーは `401 Unauthorized` と `WWW-Authenticate` ヘッダーを返します。クライアントはその内容に従って再認可・再取得を行ってください。

## ツールの使い分け（最短・省トークン手順）

1. **画像の前処理（ローカル/CLI利用時）**:
   - 複数枚のスクリーンショットまたはZIPがある場合は、`php lib/acf/mcp/prepare_screenshots.php <zip_or_dir>` を実行して生成された `contact_sheet.jpg` **1枚だけ** を `read_file` で読み取る（個別PNGを何枚も読み込まない）。
2. **ターム解決とスキーマ確認**:
   - `attribute`（例: `"水"` / `"water"` / `3`）、`species`（例: `"神"` / `"god"` / `7`）、`rarity`（例: `"星6"` / `"なし"` / `"スペシャル"` / `"レジェンド"` / `"グランド"` / `"5"`）、`affiliation`（例: `["エヴァンゲリオン"]`）、`available_moji_loop`（例: `[{"available_moji": "い"}]`）は、タームIDだけでなく日本語名・スラッグでもサーバー側で自動解決される。
   - グループ名やイベント名の表記を確認したい場合は `get_terms(taxonomy: "affiliation", search: "キーワード")` またはカンマ区切り一括取得 `get_terms(taxonomy: "attribute,species,rarity")` を使用する。
   - 不明なフィールド仕様がある場合のみ `get_field_schema` を呼ぶ。
3. **`preview_draft` の一括実行**:
   - 個別に `test_auto_input.php` を何回も実行せず、`character_name`, `basic_fields`, `auto_input` を組み立てて一度で `preview_draft` に渡す。
   - デフォルト（`test_mode: false`）ではトークン節約のため `parsed_counts`（各自動入力キーの解析行数）と `preview_hash`（`content_hash`）のみを返す。詳細な `parsed_acf` 全文を確認したいテスト時のみ `test_mode: true` を指定する。
4. **`create_draft` / `update_draft` の実行**:
   - 直前に `preview_draft` を実行済みの場合、`create_draft` には **`preview_hash` と `idempotency_key` のみ**（`update_draft` は加えて `post_id`）を渡せばよい。サーバー側キャッシュ（30分有効）から `character_name`, `basic_fields`, `auto_input` が自動復元されるため、巨大なJSONを再送する必要はない。
5. **人間による確認**:
   - 返却された `edit_url` を提示し、人間が確認する。

`create_draft`と`update_draft`には`idempotency_key`が必須です。同じユーザー・同じキーの再実行は同じ結果を返し、二重作成を防ぎます。

## テストフラグ（`test_mode`）の仕様

すべての主要MCPツール（`get_field_schema`, `get_terms`, `validate`, `preview_draft`, `create_draft`, `update_draft`）、REST API（`/wp-json/kotodaman/v1/mcp/character-draft`）、およびCLIテストスクリプト（`lib/acf/test_auto_input.php`, `lib/acf/mcp/prepare_screenshots.php`）は、初期値 `false` のテストフラグで出力粒度を制御します。

| モード | 指定方法 | 挙動 |
|---|---|---|
| **通常モード（省トークン・初期値）** | 指定なし（`test_mode: false`） | `preview_draft` / `validate` で巨大な `parsed_acf` を省略し `parsed_counts` のみ返却。`get_field_schema` で自動入力対象ループや `null` 属性を除外したコンパクト定義を返却。デバッグ `error_log` を抑制 |
| **テストモード（詳細出力）** | MCP引数 `"test_mode": true`、CLI引数 `--test-mode`（または `--verbose`）、環境変数 `KOTO_MCP_TEST_MODE=1` | `parsed_acf` 全構造、全67フィールドの完全スキーマ、画像クロップ詳細座標、文字変換デバッグログを出力 |

## AIクライアントの禁止事項

- スクリーンショットに存在しない値を推測して埋めない。
- 高解像度の個別スクリーンショットを何枚も直接 `read_file` せず、`prepare_screenshots.php` で合成した `contact_sheet.jpg` を1回だけ読み取る。
- `preview_draft` の前に `php lib/acf/test_auto_input.php` を文言ごとに個別実行してターン数を浪費しない。
- `create_draft` 呼び出し時に `preview_draft` と同じ `basic_fields` / `auto_input` を重複送信しない（`preview_hash` と `idempotency_key` のみ送る）。
- `VALIDATION_FAILED`を受けた場合は最大3回まで該当項目だけを読み直す。
- 3回読み直しても不明な場合は処理を停止し、人間に確認を求める。
- `PREVIEW_HASH_MISMATCH`を無視して再利用しない。入力を固定して再度プレビューする。
- 投稿公開や公開相当の処理を試みない。

## 環境変数

| 変数 | 用途 |
|---|---|
| `KOTO_MCP_BASE_URL` | WordPress REST API名前空間のベースURL |
| `KOTO_MCP_TEST_MODE` | `1` または `true` で常時テスト詳細出力モードを有効化（初期値 `false`） |

ローカルは`http://localhost:8080`、本番は`https://kotodaman-db.com`を設定できます。サーバーが必要な`/wp-json/kotodaman/v1`を補完します。OAuthトークンはWordPressデータベースの一時データへ保存され、リフレッシュトークンはクライアントIDとトークンファミリーに紐づけてローテーションされます。

## 画像

画像URLを省略した場合、準備中画像として次のURLを自動設定します。

```text
https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png
```

URLを指定した場合も、現在は`www.kotodaman-db.com`の同一ドメインだけを取得対象とします。取得できた画像は投稿のアイキャッチへ設定し、URL一覧は`_koto_mcp_screenshot_urls`へ保存します。

## 人間向けマニュアルの追加

`documents/mcp-field-manual.json`へフィールド名をキーとして説明を追加します。

```json
{
  "field_name": {
    "description": "画面上のどこを根拠にするか、値の読み取り規則",
    "source": "スクリーンショットの項目名"
  }
}
```

`get_field_schema`のレスポンスへ自動的に含まれるため、AIクライアントは入力前に取得して参照できます。既存のACF定義は`acf-json/`から読み込まれます。

## 実装範囲と今後の拡張

サーバー本体は`lib/acf/mcp/server.php`にあり、既存のCSVパーサーとACF更新処理を再利用します。次の拡張候補は、監査ログの専用テーブル化、未解析文言の詳細報告、管理画面でのプレビュー差分表示、OAuth同意画面の専用UIです。
