# コトダマンDB MCP ドキュメント

コトダマンDBテーマ（`cocoon-child-master`）における **MCP（Model Context Protocol）サーバーおよびキャラクター下書き自動作成機能** のドキュメント一式です。

---

## ドキュメント構成

| ドキュメント | 対象読者 | 内容 |
|---|---|---|
| **[README.md](README.md)**（本書） | 全員 | 現在のMCP実装状況、全体アーキテクチャ、クライアント接続設定、今後の拡張計画 |
| **[external-ai-setup.md](external-ai-setup.md)** | 外部AI利用者 | ChatGPT / Claude / Gemini / Muse / Copilot / Cursor 等からの接続手順、協力者登録、コピペ用プロンプト |
| **[ai-compatibility.md](ai-compatibility.md)** | 全員 | 各AIツール（ChatGPT / Claude / Gemini / Muse / Copilot / Cursor 等）の現在の対応状況一覧 |
| **[character-draft-guide.md](character-draft-guide.md)** | AIエージェント / 運用者 | スクリーンショットからの下書き作成最短4ステップ（省トークン手順）、フィールド入力規則、禁止事項、運用チェックリスト |
| **[api-reference.md](api-reference.md)** | 開発者 | MCP Streamable HTTP 全10ツール＋Resources/Promptsの詳細仕様、OAuth 2.1（CIMD / Static / DCR）認証、REST API仕様 |
| **[openapi.json](openapi.json)** | ChatGPT Actions 等 | ChatGPTの「Custom GPTs（マイGPT）」のActionsにそのまま貼り付けられるOpenAPI 3.1.0スキーマ |
| **[mcp-field-manual.json](mcp-field-manual.json)** | AI / 開発者 | `get_field_schema` ツールおよび `kotodaman://guide/field-manual` リソースで返却されるAI向けフィールド読み取り定義 |

* 関連ファイル:
  * [lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md](../../lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md): ゲーム内文言パーサーおよびCSV対応表の詳細仕様

---

## 1. 現在のMCP実装状況（ステータス総覧）

ゲームのスクリーンショットからAIが情報を読み取り、WordPress上にキャラクターの下書き（`draft`）を安全・高速・省トークンで作成・更新するための基盤が実装済みです。

### 実装済み機能

* **MCP Streamable HTTP サーバー（[lib/mcp/protocol/server.php](../../lib/mcp/protocol/server.php)）**
  * プロトコルバージョン `2025-11-25`（`2025-06-18` / `2025-03-26` ネゴシエーション、JSON-RPC 2.0 単一＋バッチリクエスト）対応のエンドポイント（`/wp-json/kotodaman/v1/mcp`）。
  * 全10ツール実装済み（MCP Tool `annotations` および `result.isError: true` エラー表現対応）：
    * 参照・検証系（`validate` スコープ）: `get_field_schema`, `get_blessing_schema`, `get_terms`（`limit`/`offset` ページネーション対応）, `search_character`（雛型除外）, `get_draft`（下書き情報取得）, `validate`
    * プレビュー系（`preview` スコープ）: `preview_draft`（30分間のサーバー側入力キャッシュ・部分未解析 `warnings` 付き）
    * 作成・更新系（`create` スコープ）: `create_draft`, `update_draft`（部分更新対応・雛型保護）, `trash_draft`（雛型保護）
  * CORS / `Origin` 検証（`OPTIONS` プリフライト、`MCP-Protocol-Version`・`Mcp-Session-Id` 対応）、未認証 `GET` の `401` + `WWW-Authenticate` 応答（認証済み `GET` は `405`）。
  * セキュリティガード：最大256KBのペイロード制限、文字数上限（キャラ名200文字、基本フィールド2000文字、自動入力4000文字、evidence 16KB）。
  * 雛型・特殊テンプレート記事（ID: `2947`, `2023`, `2637`, `2638`, `3845`, `4603`, `5294`, `3683` およびタイトルに「雛型」「雛形」を含む投稿）の変更・ゴミ箱・取得防止保護。
* **OAuth 2.1 / CIMD / Static Confidential Client / サイト協力者登録機構（[lib/mcp/auth/mcp-oauth.php](../../lib/mcp/auth/mcp-oauth.php), [lib/mcp/portal/mcp-collaboration-page.php](../../lib/mcp/portal/mcp-collaboration-page.php)）**
  * `/.well-known/oauth-protected-resource` および `/.well-known/oauth-authorization-server` によるメタデータディスカバリ（CORS対応・`client_id_metadata_document_supported: true`）。
  * **CIMD (SEP-991)** HTTPSクライアントメタデータURL自動解決（SSRF防止）、**動的クライアント登録 (`/mcp/oauth/register`)**、および管理画面発行の **Static Confidential Clients（`client_secret_basic`/`post`・非PKCEオプトイン可）**。
  * **RFC 8707 `resource` (`aud`) 検証**、**SEP-835 `insufficient_scope` ヘッダー応答**、`scope` 省略時の安全フォールバック（`validate preview` またはクライアント既定スコープ）。
  * HTTPS/ローカルループバック環境での WordPress Application Passwords 認証サポート（ブルートフォース防止付き）。
  * **協力者ポータル `[koto_collaborate_portal]` / `collaboration-page.php`**: メール認証付きセルフ登録（`subscriber` 作成・デフォルトONのMCP申請連動）、AI別接続ガイドUI。管理画面で承認すると `subscriber` → `mcp_user` 昇格、取消で `mcp_user` → `subscriber` 降格＋トークン即失効。
  * トークン管理：アクセストークン（1時間）、リフレッシュトークン（30日・トークンファミリー単位の安全ローテーション＆15秒デバウンス＆明示的失効）、失効エンドポイント（`/mcp/oauth/revoke`）。
  * 期限切れ Transient / Option の自動ガベージコレクション（GC）機構（保留中登録トークン含む）。
  * レート制限（ユーザー単位 60req/min、クライアント登録IP単位 30req/min、登録申請IP 5req/10min）および `idempotency_key` による二重作成防止。
* **DBエディタ（[lib/acf/acf-editor.php](../../lib/acf/acf-editor.php) & [lib/acf/acf-editor.css](../../lib/acf/acf-editor.css)）**
  * MCP作成キャラクターに対する「🤖 AIによるdraft（要確認 / 確認済み）」レビューバナーと未解析警告一覧を表示（AIによる下書きで確認が必要である旨を明示、不要な仮画像表示は排除）。
  * 人間によるワンクリック「確認完了にする」ボタンおよび「公開 / 更新」時の確認フラグ自動解除。
* **基本フィールド保存・自動入力連携・REST API（[lib/mcp/protocol/post-mcp.php](../../lib/mcp/protocol/post-mcp.php)）**
  * `basic_fields`（属性・種族・レア度・文字・ステータス等）のバリデーションとACFフィールドキー（`field_xxxxxx`）変換保存。
  * タクソノミー項目の柔軟な自動解決（タームIDだけでなく日本語名・スラッグ・「火属性」「★6」等の表記ゆれを `koto_mcp_resolve_taxonomy_term_id()` で自動解決）。
  * `name_ruby` のカッコ内文字列自動除去・ひらがな正規化、`available_moji_loop` の初期属性・解放場所自動補完。
  * `auto_input`（わざ・すごわざ・とくせい・リーダー・祝福・コトワザ）を既存のCSV対応表（`lib/ゲーム内文言ーACF-対応表.csv`）および自動入力パーサーでACF構造へ変換。
  * 同一ドメイン（`www.kotodaman-db.com`）画像URLからのアイキャッチ＆進化後画像（`character_image`）自動設定（未指定時は準備中画像を自動セット）。
  * WordPress REST API 直接呼び出しエンドポイント（`POST /wp-json/kotodaman/v1/mcp/character-draft`、Base64 `screenshots` 添付・`dry_run` 対応）。
* **省トークン最適化＆テストモード制御（[lib/mcp/tools/prepare_screenshots.php](../../lib/mcp/tools/prepare_screenshots.php) ほか）**
  * 複数枚のスマホスクリーンショットやZIPから上下の不要バーをトリミングし、1枚の `contact_sheet.jpg` にタイル合成する前処理CLIスクリプト（Visionトークン75〜85%削減）。
  * `preview_draft` → `create_draft` 間のサーバー側キャッシュ復元により、作成時の巨大JSON再送を排除。
  * `test_mode` フラグ（初期値 `false`）により、通常運用時はコンパクトな件数サマリー（`parsed_counts`）のみを返し、デバッグ時（`test_mode: true`）のみ `parsed_acf` 全構造や全67フィールドスキーマを出力。

---

## 2. アーキテクチャとファイル構成

```mermaid
flowchart LR
    subgraph Client["AI / MCPクライアント (Antigravity, Muse等)"]
        IMG["スクリーンショット群 / ZIP"] --> PREP["prepare_screenshots.php<br/>(contact_sheet.jpg 合成)"]
        PREP --> AI["AI Vision 読み取り<br/>(1枚のみ read_file)"]
    end

    subgraph WP["WordPress (cocoon-child-master)"]
        MCP["MCP Server<br/>lib/mcp/protocol/server.php<br/>(/wp-json/kotodaman/v1/mcp)"]
        REST["REST API<br/>lib/mcp/protocol/post-mcp.php<br/>(/wp-json/kotodaman/v1/mcp/character-draft)"]
        PARSER["自動入力パーサー & CSV辞書<br/>lib/acf/auto-input/"]
        ACF["ACF更新 & _spec_json生成<br/>draft投稿 + 監査メタ保存"]
    end

    AI -->|"1. preview_draft"| MCP
    AI -->|"2. create_draft (preview_hash)"| MCP
    AI -.->|"または REST直接呼び出し"| REST
    MCP --> PARSER
    REST --> PARSER
    MCP --> ACF
    REST --> ACF
```

### 関連ファイル一覧

| ファイルパス | 役割 |
|---|---|
| [lib/mcp/loader.php](../../lib/mcp/loader.php) | `functions.php` から呼び出される MCP モジュール全体の統一ローダー |
| [lib/mcp/auth/mcp-core.php](../../lib/mcp/auth/mcp-core.php) | MCP共通定数、雛型保護判定、Idempotency管理、ガベージコレクション、ポータルURL解決、ロール初期化 |
| [lib/mcp/auth/mcp-auth.php](../../lib/mcp/auth/mcp-auth.php) | OAuth Bearer（`aud` 検証 / SEP-835 `insufficient_scope`） / Application Passwords 認証、スコープ判定、権限・承認チェック |
| [lib/mcp/auth/mcp-oauth.php](../../lib/mcp/auth/mcp-oauth.php) | OAuth 2.1 認可サーバー、CIMD (SEP-991)、動的登録 (DCR)、Static Confidential Clients、認可画面、トークン発行・失効、リフレッシュファミリー |
| [lib/mcp/protocol/server.php](../../lib/mcp/protocol/server.php) | MCP Streamable HTTP サーバー本体（`2025-11-25` ネゴシエーション、JSON-RPC 2.0 バッチ、CORS/Origin） |
| [lib/mcp/protocol/mcp-tools.php](../../lib/mcp/protocol/mcp-tools.php) | 全10ツール定義（Tool Annotations、`get_terms` ページネーション）・入力引数バリデーション・実行ディスパッチ |
| [lib/mcp/protocol/mcp-draft.php](../../lib/mcp/protocol/mcp-draft.php) | 下書きバリデーション（`koto_mcp_validate_draft`）、既存データ抽出、未解析警告検出、アイキャッチ設定 |
| [lib/mcp/protocol/mcp-schema.php](../../lib/mcp/protocol/mcp-schema.php) | ACFフィールドスキーマ動的生成（`koto_mcp_field_schema`）、マニュアル読み込み |
| [lib/mcp/protocol/mcp-resources.php](../../lib/mcp/protocol/mcp-resources.php) | MCP Resources（ガイド文書、フィールドマニュアル）、MCP Prompts 配信 |
| [lib/mcp/protocol/post-mcp.php](../../lib/mcp/protocol/post-mcp.php) | REST API 全8ルート（`openapi.json`, `schema`, `blessing-schema`, `terms`, `characters/search`, `draft/preview`, `character-draft`, `draft/{id}`） |
| [lib/mcp/portal/mcp-admin.php](../../lib/mcp/portal/mcp-admin.php) | WordPress管理画面「ユーザー > MCP利用申請」UI（ロール昇格/降格、Static OAuthクライアント管理） |
| [collaboration-page.php](../../collaboration-page.php) | 編集協力・AI連携ポータル用 固定ページテンプレート（テーマ直下・専用レイアウト） |
| [lib/mcp/portal/mcp-collaboration-page.php](../../lib/mcp/portal/mcp-collaboration-page.php) | 協力者ポータル共通描画・ショートコード `[koto_collaborate_portal]`、メール認証付き登録、AI接続ガイドJSON（`.css` / `.js` と連携） |
| [lib/mcp/tools/prepare_screenshots.php](../../lib/mcp/tools/prepare_screenshots.php) | 複数スクリーンショット／ZIPの展開・上下トリミング・タイル合成CLIスクリプト |
| [lib/mcp/tests/test-mcp-compatibility.php](../../lib/mcp/tests/test-mcp-compatibility.php) | 互換拡張（CIMD・Static OAuth・`aud`・`2025-11-25`・REST・登録・広告制御）の自動検証テスト |
| [documents/mcp/mcp-field-manual.json](mcp-field-manual.json) | `get_field_schema` に統合されるフィールド別読み取りガイド定義 |
| [lib/acf/acf-auto-input.php](../../lib/acf/acf-auto-input.php) | 自動入力モジュール（`auto-input`）のローダー |
| [lib/acf/auto-input/acf-auto-input-parsers.php](../../lib/acf/auto-input/acf-auto-input-parsers.php) | わざ・すごわざ・とくせい・リーダー・祝福文言の解析エンジン |
| [lib/acf/auto-input/acf-auto-input-wp-acf.php](../../lib/acf/auto-input/acf-auto-input-wp-acf.php) | ACFフィールドキー変換（`koto_convert_acf_rows_to_field_keys`）および下書き作成・更新処理 |
| [lib/ゲーム内文言ーACF-対応表.csv](../../lib/ゲーム内文言ーACF-対応表.csv) | ゲーム内文言とACF構造のマッピング辞書 |

---

## 3. 接続先とクライアント設定

### エンドポイントURL

MCPのStreamable HTTPエンドポイントは次の形式です。

```text
${KOTO_MCP_BASE_URL}/mcp
```

`KOTO_MCP_BASE_URL` には、サイトURLまたはWordPress REST API名前空間（`/wp-json/kotodaman/v1`）までを指定できます（未指定時は `rest_url('kotodaman/v1')` が使用されます）。

```text
https://kotodaman-db.com/wp-json/kotodaman/v1/mcp
http://localhost:8080/wp-json/kotodaman/v1/mcp
http://kotodaman-db.local/wp-json/kotodaman/v1/mcp
```

> [!IMPORTANT]
> 実際のトークンや認証情報は環境変数またはクライアント設定ファイルで管理し、Gitリポジトリには絶対にコミットしないでください。

### Antigravity 設定例

Antigravity CLI (`agy`) で登録する場合：

```bash
# ローカル (kotodaman-db.local)
agy mcp add --header "Authorization: Bearer <TOKEN>" kotodaman-db http://kotodaman-db.local/wp-json/kotodaman/v1/mcp

# ローカル (localhost:8080)
agy mcp add --header "Authorization: Bearer <TOKEN>" kotodaman-db http://localhost:8080/wp-json/kotodaman/v1/mcp
```

設定ファイル（`~/.gemini/config/mcp_config.json`）へ直接記載する場合：

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

### Muse / OAuth対応クライアント設定

Muse等のカスタムコネクタでは、MCPリソースURIに `${KOTO_MCP_BASE_URL}/mcp` を指定します。OAuthメタデータは以下のURLから自動検出されます：

* 保護リソースメタデータ: `${SITE_URL}/.well-known/oauth-protected-resource`
* 認可サーバーメタデータ: `${SITE_URL}/.well-known/oauth-authorization-server`
* ブラウザ認可画面: `${SITE_URL}/mcp/oauth/authorize`

### 環境変数

| 変数名 | 用途 | 初期値 |
|---|---|---|
| `KOTO_MCP_BASE_URL` | WordPress REST API名前空間のベースURL（`/wp-json/kotodaman/v1` は自動補完） | `rest_url('kotodaman/v1')` |
| `KOTO_MCP_TEST_MODE` | `1` または `true` で常時テスト詳細出力モードを有効化 | `false` |

---

## 4. 今後の拡張候補（ロードマップ）

現在フェーズ1（MCPサーバー・OAuth 2.1・下書き作成／更新／削除・バリデーション・省トークン最適化）が完了しています。今後の拡張候補は以下の通りです。

### 優先度：中
* **未解析文言のレポート強化**: 自動入力パーサーで一部の文言がスキップされた際、該当する原文フレーズを特定して `warnings` に詳細出力する。
* **管理画面でのプレビュー差分表示**: `update_draft` 実行前後に、既存のACF値とAI解析結果の差分をWordPress管理画面上で視覚的に比較できるUI。
* **監査ログの専用一覧UI**: 投稿メタ `_koto_mcp_audit` に保存されている作成履歴・証跡（`evidence`）を管理画面で一覧確認する画面。

### 将来構想
* **Androidエミュレータ / ADB連携による画面自動取得**: 手動スクリーンショット撮影を省き、検証環境から直接画像を収集するパイプライン。
* **代表スクリーンショットによるE2E回帰テスト**: サンプル画像セットと期待ACF JSONペアを用いた自動回帰テスト。
