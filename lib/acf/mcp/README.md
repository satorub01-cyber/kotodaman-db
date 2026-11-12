# コトダマンDB MCP（Model Context Protocol）モジュール

本ディレクトリ（`lib/acf/mcp/`）は、コトダマンDBのMCPサーバーおよびキャラクター下書き作成基盤の実装コード群です。

## 詳細ドキュメント一覧

MCPのアーキテクチャ、API仕様、クライアント接続設定、運用ガイドライン等の詳細ドキュメントは `documents/mcp/` に集約されています。

| ドキュメント | 対象読者 | 内容 |
|---|---|---|
| **[documents/mcp/README.md](../../../documents/mcp/README.md)** | 全員 | MCP実装状況、全体アーキテクチャ、クライアント接続設定、今後の拡張計画 |
| **[documents/mcp/external-ai-setup.md](../../../documents/mcp/external-ai-setup.md)** | 外部AI利用者 | Muse / Claude / ChatGPT / Gemini CLI 等からの接続手順、設定例 |
| **[documents/mcp/character-draft-guide.md](../../../documents/mcp/character-draft-guide.md)** | AI / 運用者 | スクショ下書き作成手順（省トークン）、入力規則、運用チェックリスト |
| **[documents/mcp/api-reference.md](../../../documents/mcp/api-reference.md)** | 開発者 | MCP 全9ツール＋Resources詳細仕様、OAuth 2.1 認証、REST API仕様 |
| **[documents/mcp/openapi.json](../../../documents/mcp/openapi.json)** | 開発者 | OpenAPI 3.1.0 スキーマ（ChatGPT Actions等用） |
| **[documents/mcp/mcp-field-manual.json](../../../documents/mcp/mcp-field-manual.json)** | AI / 開発者 | フィールド読み取り定義マニュアル |

## モジュール構成

| ファイル | 役割 |
|---|---|
| `server.php` | MCP Streamable HTTP サーバー本体・ローダー（JSON-RPC 2.0 ハンドラー、モジュール集約） |
| `mcp-core.php` | MCP共通定数、雛型保護判定、Idempotency管理、ガベージコレクション、ロール初期化 |
| `mcp-auth.php` | OAuth Bearer / Application Passwords 認証、スコープ判定、権限・承認チェック |
| `mcp-oauth.php` | OAuth 2.1 認可サーバー、動的クライアント登録、認可画面、トークン発行・失効、リフレッシュファミリー |
| `mcp-tools.php` | 9ツール定義・入力引数バリデーション・実行ディスパッチ |
| `mcp-draft.php` | 下書きバリデーション（`koto_mcp_validate_draft`）、既存データ抽出、未解析警告検出、アイキャッチ設定 |
| `mcp-schema.php` | ACFフィールドスキーマ動的生成（`koto_mcp_field_schema`）、マニュアル読み込み |
| `mcp-resources.php` | MCP Resources（ガイド文書、フィールドマニュアル）、MCP Prompts 配信 |
| `mcp-admin.php` | WordPress管理画面「ユーザー > MCP利用申請」UI & アクションハンドラー |
| `post-mcp.php` | REST API エンドポイント（`/mcp/character-draft`）、タクソノミー自動解決、基本ACF保存 |
| `prepare_screenshots.php` | 複数スクリーンショットの展開・トリミング・タイル合成CLIスクリプト |
| `test-mcp-oauth-refresh.php` | OAuthリフレッシュトークン等のテストスクリプト |
