# コトダマンDB MCP（Model Context Protocol）モジュール

本ディレクトリ（`lib/mcp/`）は、コトダマンDBのMCPサーバー、OAuth 2.1認可サーバー、REST API、協力者登録ポータル、およびキャラクター下書き作成基盤の実装コード群です。

## 詳細ドキュメント一覧

MCPのアーキテクチャ、API仕様、クライアント接続設定、運用ガイドライン等の詳細ドキュメントは `documents/mcp/` に集約されています。

| ドキュメント | 対象読者 | 内容 |
|---|---|---|
| **[documents/mcp/README.md](../../documents/mcp/README.md)** | 全員 | MCP実装状況、全体アーキテクチャ、クライアント接続設定、今後の拡張計画 |
| **[documents/mcp/external-ai-setup.md](../../documents/mcp/external-ai-setup.md)** | 外部AI利用者 | ChatGPT / Claude / Gemini / Muse / Copilot / Cursor 等からの接続手順、協力者登録、設定例 |
| **[documents/mcp/ai-compatibility.md](../../documents/mcp/ai-compatibility.md)** | 全員 | 各AIツール別の現在の対応状況一覧 |
| **[documents/mcp/character-draft-guide.md](../../documents/mcp/character-draft-guide.md)** | AI / 運用者 | スクショ下書き作成手順（省トークン）、入力規則、運用チェックリスト |
| **[documents/mcp/api-reference.md](../../documents/mcp/api-reference.md)** | 開発者 | MCP 全10ツール＋Resources/Prompts詳細仕様、OAuth 2.1 (CIMD / Static / DCR) 認証、REST API仕様 |
| **[documents/mcp/openapi.json](../../documents/mcp/openapi.json)** | 開発者 | OpenAPI 3.1.0 スキーマ（ChatGPT Actions / Copilot Studio / Dify 等用） |
| **[documents/mcp/mcp-field-manual.json](../../documents/mcp/mcp-field-manual.json)** | AI / 開発者 | フィールド読み取り定義マニュアル |

## モジュール構成（サブフォルダ分割）

| パス | 役割 |
|---|---|
| `loader.php` | `functions.php` から読み込まれる MCP 全体の統一ローダー（`auth/` → `protocol/` → `portal/`） |
| `auth/mcp-core.php` | MCP共通定数、雛型保護判定、Idempotency管理、ガベージコレクション、ポータルURL解決、ロール初期化 |
| `auth/mcp-auth.php` | OAuth Bearer（`aud` 検証・SEP-835 `insufficient_scope`） / Application Passwords 認証、スコープ判定、動的権限チェック |
| `auth/mcp-oauth.php` | OAuth 2.1 認可サーバー、CIMD (SEP-991)、動的クライアント登録、Static Confidential Clients、認可画面、トークン発行・失効、リフレッシュファミリー |
| `protocol/server.php` | MCP Streamable HTTP サーバー本体（`2025-11-25` ネゴシエーション、JSON-RPC 2.0 バッチ、CORS/Origin） |
| `protocol/mcp-tools.php` | 全10ツール定義（Tool Annotations、`get_terms` ページネーション）・入力引数バリデーション・実行ディスパッチ |
| `protocol/mcp-draft.php` | 下書きバリデーション（`koto_mcp_validate_draft`）、既存データ抽出、未解析警告検出、アイキャッチ設定 |
| `protocol/mcp-schema.php` | ACFフィールドスキーマ動的生成（`koto_mcp_field_schema`）、マニュアル読み込み |
| `protocol/mcp-resources.php` | MCP Resources（ガイド文書、フィールドマニュアル）、MCP Prompts 配信 |
| `protocol/post-mcp.php` | REST API 全8ルート（`openapi.json`, `schema`, `blessing-schema`, `terms`, `characters/search`, `draft/preview`, `character-draft`, `draft/{id}`） |
| `portal/mcp-admin.php` | WordPress管理画面「ユーザー > MCP利用申請」UI（`subscriber` ↔ `mcp_user` ロール昇格/降格、Static OAuth クライアント管理） |
| `portal/mcp-collaboration-page.php` | 固定ページテンプレート [collaboration-page.php](../../collaboration-page.php) および `[koto_collaborate_portal]` 共通描画、メール認証付き登録、AI別ガイドUI（`mcp-collaboration.css` / `.js`） |
| `tools/prepare_screenshots.php` | 複数スクリーンショットの展開・トリミング・タイル合成CLIスクリプト |
| `tests/test-mcp-oauth-refresh.php` | OAuthリフレッシュトークン等のテストスクリプト |
| `tests/test-mcp-permissions.php` | MCP権限・ロール・申請管理テストスクリプト |
| `tests/test-mcp-compatibility.php` | 互換拡張（CIMD・Static OAuth・`aud`・`2025-11-25`・REST・登録・広告制御）テストスクリプト |
