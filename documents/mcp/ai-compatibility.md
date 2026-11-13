# 外部AIツール対応状況一覧

コトダマンDBのMCPサーバー（`/wp-json/kotodaman/v1/mcp`）およびREST API（`/wp-json/kotodaman/v1/mcp/*`）における、各AIツール・クライアントの現在の対応状況一覧です。

## 凡例

* **対応済み**: 現行のサーバー実装で接続・認証・下書き作成が可能
* **一部対応**: 一部の利用形態や設定条件により制限あり
* **未対応**: クライアント側の仕様制約等により利用不可

---

## 1. チャットAI（Web・デスクトップ・モバイルアプリ）

| AIツール | 接続方式 | 対応状況 | 備考 |
|---|---|---|---|
| **Claude** (Web / Desktop / Mobile) | Remote MCP (`2025-11-25` + CIMD / DCR / RFC 8707 `aud` / Annotations) | **対応済み** | カスタムコネクタからOAuth 2.1認証で全10ツールを利用可能 |
| **ChatGPT** (MCPコネクタ / 開発者モード) | Remote MCP (`2025-11-25` + CIMD / DCR / Annotations) | **対応済み** | MCPコネクタでOAuth 2.1認証・全10ツールを利用可能 |
| **ChatGPT** (Custom GPTs / Actions) | REST API (OpenAPI 3.1.0 + Static OAuth / Basic) | **対応済み** | Static OAuth (`client_secret` + 非PKCE可)・全8 RESTルート（OpenAPI 7パス）・`get_terms` ページネーション対応済み |
| **Gemini** (Webアプリ / DevMode) | Remote MCP / OpenAPI | **一部対応** | Developer Modeやカスタム連携環境では接続可。一般向け標準Web画面には外部MCP登録UIがない場合あり |
| **Muse** (Meta AI) | Remote MCP (Streamable HTTP + OAuth 2.1) | **対応済み** | カスタムコネクタ経由で OAuth 接続・下書き作成が可能 |
| **Grok** (`grok.com/connectors`) | Remote MCP (Streamable HTTP + OAuth / Bearer) | **対応済み** | カスタムMCPコネクタとして公開HTTPS経由で接続可能 |
| **Perplexity** (Web / Desktop) | Remote MCP (`2025-11-25` + OAuth / Annotations / `isError`) | **対応済み** | カスタムMCPコネクタ経由でOAuth・Bearer接続が可能 |
| **Le Chat** (Mistral AI / Studio) | Remote MCP (`2025-11-25` + CIMD / OAuth / Annotations) | **対応済み** | カスタムコネクタ経由でOAuth・Bearer接続が可能 |
| **Microsoft Copilot** (個人向けWeb `copilot.microsoft.com`) | — | **未対応** | クライアント側にカスタムリモートMCP / OpenAPI接続機能なし |
| **Microsoft Copilot Studio / M365 Copilot** | Remote MCP / OpenAPI + Static OAuth | **対応済み** | Static OAuth (`client_secret`)・CIMD・Annotations・OpenAPI対応済み |
| **Poe** | — | **未対応** | 汎用外部MCP / OpenAPIコネクタ接続に非対応（独自Bot API形式のみ） |

---

## 2. 開発環境・IDE・AIコーディングアシスタント

| AIツール | 接続方式 | 対応状況 | 備考 |
|---|---|---|---|
| **Antigravity** (`agy`) | Remote MCP (Streamable HTTP + Bearer / OAuth) | **対応済み** | CLI・`mcp_config.json` 経由で全10ツール利用可能 |
| **Claude Code** | Remote MCP (`2025-11-25` + CIMD / DCR / RFC 8707 `aud`) | **対応済み** | `claude mcp add --transport http` + `/mcp` OAuth認証で利用可能 |
| **Gemini CLI** | Remote MCP (Streamable HTTP + OAuth / Bearer) | **対応済み** | `.gemini/settings.json`（`httpUrl`）でOAuth・Bearer接続可能 |
| **GitHub Copilot** (VS Code / CLI) | Remote MCP (`2025-11-25` + CIMD / Annotations / SEP-835) | **対応済み** | `.vscode/mcp.json`（`type: "http"`）+ OAuthで利用可能 |
| **Cursor** | Remote MCP (`2025-11-25` + CIMD / Annotations) | **対応済み** | `mcp.json` 経由でOAuth 2.1フロー自動接続が可能 |
| **Windsurf** | Remote MCP (`2025-11-25` + CIMD / OAuth / Bearer) | **対応済み** | `mcp_config.json` 経由でOAuth・Bearer接続が可能 |
| **Cline** | Remote MCP (`streamableHttp` + Annotations / `isError`) | **対応済み** | `streamableHttp` 指定＋OAuth / Bearerで利用可能 |
| **Roo Code** | Remote MCP (`2025-11-25` + Streamable HTTP + OAuth) | **対応済み** | Streamable HTTP＋OAuth / Bearerで利用可能 |
| **Codex CLI** | Remote MCP (`2025-11-25` + CIMD / OAuth / Bearer) | **対応済み** | `config.toml` + `codex mcp login` で接続可能 |
| **OpenCode** | Remote MCP (`2025-11-25` + CIMD / OAuth / Bearer) | **対応済み** | `opencode.json`（`type: "remote"`）+ `opencode mcp auth` で接続可能 |
| **JetBrains AI Assistant** | Remote MCP (Streamable HTTP + OAuth / Static Bearer) | **対応済み** | AI Assistant MCP設定から HTTP MCP / Bearer で接続可能 |

---

## 3. API・ワークフロー基盤・検証ツール

| ツール / 基盤 | 接続方式 | 対応状況 | 備考 |
|---|---|---|---|
| **Gemini API** (Function Calling / Interactions API) | REST API (OpenAPI 3.1.0) / Remote MCP | **対応済み** | `/mcp/openapi.json` および全8 RESTルート＋Remote MCPに対応済み |
| **Gemini Enterprise** (Vertex AI Agent Builder) | Remote MCP / OpenAPI + Static OAuth | **対応済み** | Static OAuth (`client_secret`)・Remote MCP・Annotations対応済み |
| **Dify** | Remote MCP / OpenAPI 3.1.0 | **対応済み** | MCPツール連携および OpenAPI 3.1.0 全7パスインポートに対応済み |
| **MCP Inspector** (ブラウザ検証ツール) | Remote MCP (Browser CORS + `MCP-Protocol-Version`) | **対応済み** | CORS / `OPTIONS` プリフライト / 露出ヘッダー・OAuthテストに対応済み |
