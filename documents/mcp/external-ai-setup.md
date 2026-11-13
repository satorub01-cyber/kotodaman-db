# 外部AIサービス（ChatGPT / Claude / Gemini / Muse / Copilot 等）連携・設定ガイド

本書は、外部のチャットAI・コーディングエージェント・ワークフロー基盤（ChatGPT、Claude、Gemini、Muse、GitHub Copilot、Copilot Studio、Grok、Perplexity、Mistral Le Chat、Cursor、Windsurf、Cline、Roo Code、Codex CLI、OpenCode、Antigravity、JetBrains、Dify、MCP Inspector 等）からコトダマンDBのMCPサーバーまたはREST APIへ安全に接続し、スクリーンショットからキャラクター下書きを作成する手順をまとめたガイドです。

---

## 1. サイト上でのアカウント登録・AI連携ポータル

協力者向けのユーザー登録およびAI別設定案内は、固定ページテンプレート **[collaboration-page.php](../../collaboration-page.php)**（または任意固定ページへのショートコード `[koto_collaborate_portal]` 配置、[lib/mcp/portal/mcp-collaboration-page.php](../../lib/mcp/portal/mcp-collaboration-page.php)）で提供されています。固定ページ作成時にテンプレート「**編集協力・AI連携ポータル (collaboration-page)**」を選択すると、URL自動検出と専用レイアウトで表示されます。

### 新規登録と承認フロー
1. 登録フォームからユーザー名・メールアドレス・表示名・パスワード（8文字以上）を入力して送信します（デフォルトで「MCP利用も同時に申請する」がONになっています）。
2. 入力したメールアドレス宛に24時間有効の認証リンクが送信されます（リンクを開くまでユーザーアカウントは作成されません）。
3. メール内の認証リンクをクリックすると、最小権限の `subscriber` ユーザーとして登録が完了し、MCP利用申請フラグ（`_koto_mcp_requested = 1`）と管理者通知が自動処理されます。
4. 管理者が WordPress管理画面「**ユーザー > MCP利用申請**（`users.php?page=koto-mcp-approvals`）」で承認すると、`subscriber` → `mcp_user` ロールへ昇格し、登録メールアドレス宛に承認通知が送信されます（取消時は `mcp_user` → `subscriber` へ降格・トークン即失効）。

> [!NOTE]
> **広告表示制御について**: サイト上の広告非表示特典は「管理者（`administrator`）・編集者（`editor`）」および「公開済み記事・キャラクター投稿が1件以上あるユーザー」にのみ適用されます。新規登録直後のユーザーや公開実績のない `mcp_user` には通常通り広告が表示されます。

---

## 2. 外部AIはどうやって「使い方」や「スクショ読み取りルール」を知るのか？

外部AIはローカルの `AGENTS.md` を直接読めませんが、本MCPサーバーは以下の **4つの仕組み** により、接続した外部AIが自動的に使い方・スクリーンショット運用ルール・フィールド規則を取得できるようになっています。

| 仕組み | 対応する外部AI | 自動取得される内容 |
|---|---|---|
| **① MCP `initialize` の `instructions`** | 全MCPクライアント | 接続確立時に、スクリーンショットの読み取りルール・最短4ステップ手順・主要フィールド規則・禁止事項がAIのシステムコンテキストへ自動注入されます。 |
| **② `get_field_schema`（および `GET /mcp/schema`）の `workflow_guide` & `manual`** | 全MCPクライアント ＋ REST/OpenAPIクライアント | ツール呼び出し1回で、各ACFフィールドの型・必須項目・画面上の読み取り根拠（`manual`）に加え、`workflow_guide` がJSONで返却されます。 |
| **③ MCP Resources（`resources/list`, `resources/read`）** | リソース参照対応のMCPクライアント | `kotodaman://guide/character-draft`（運用ガイド全文Markdown）、`kotodaman://guide/character-entry`（イベント・コラボ・紐付けストーリー・ひらがな・雛形基準）、`kotodaman://guide/kotowaza`（コトワザガイド）、`kotodaman://guide/field-manual`（フィールド定義JSON）を参照できます。 |
| **④ MCP Prompts（`prompts/list`, `prompts/get`）** | プロンプトメニュー対応のMCPクライアント | `character_draft_from_screenshots` プロンプトを選択するだけで、下書き作成用の指示文がチャット欄に自動セットされます。 |

さらに、全10ツールに MCP `annotations`（`readOnlyHint` / `destructiveHint` / `idempotentHint` 等）が付与されているため、ChatGPT / Claude / Cursor 等で参照系ツール（`get_field_schema`, `search_character`, `validate` 等）と更新系ツール（`create_draft`, `update_draft`, `trash_draft`）をクライアント側で適切に区別できます。

---

## 3. セキュリティと認証方式（3種類の認証）

すべての方式で以下が強制されます：
* WordPressの `edit_posts` 権限（または `mcp_user` ロール＋管理者承認 `_koto_mcp_approved = 1`）。
* ユーザー単位のレート制限（**60リクエスト/分**）、ログイン/認証失敗時のIP単位ブルートフォース制限。
* 投稿作成・更新対象は常に **`draft`（下書き）のみ**。保護対象雛型・特殊記事は除外・拒否。

### 方式A：OAuth 2.1 + PKCE（CIMD / DCR 自動検出 — 最推奨）
* ChatGPT MCPコネクタ、Claude、Gemini CLI、GitHub Copilot、Cursor、Windsurf、Cline、Roo Code、Codex CLI、OpenCode、Grok、Perplexity、Mistral Le Chat、Muse 等の標準方式です。
* **CIMD (Client ID Metadata Document / SEP-991)** と **動的クライアント登録 (DCR / `/mcp/oauth/register`)**、さらに **RFC 8707 `resource` (`aud`) バインディング** および **SEP-835 `insufficient_scope`** に対応しています。
* ブラウザのWordPressログイン＆同意画面（`/mcp/oauth/authorize`）で認可するため、クライアント側にパスワードを保存せずに済みます。

### 方式B：Static OAuth Confidential Client（事前登録クライアント — ChatGPT Actions / Copilot Studio / Gemini Enterprise / Dify 向け）
* 固定の `client_id` + `client_secret` を要求するツール（Custom GPT Actions、Copilot Studio、Gemini Enterprise、Dify 等）向けです。
* 管理画面「**ユーザー > MCP利用申請**」下部の「**事前登録OAuthクライアント（Static Confidential Clients）管理**」から発行・削除・有効/無効切替できます。
* `client_secret_basic` / `client_secret_post` 認証に対応し、必要なクライアントのみ「PKCE必須を緩和（Confidential Client非PKCE許可）」をオプトイン可能です。

### 方式C：WordPress アプリケーションパスワード（CLI / 簡易REST連携向け）
* WordPress管理画面「**ユーザー > プロフィール > アプリケーションパスワード**」から発行した24文字のパスワードを HTTPS 経由の Basic 認証（`Authorization: Basic <base64(user:pass)>`）または Bearer トークン形式（`Authorization: Bearer <user>:<pass>`）で送信します。

---

## 4. 主要AIツール別 接続手順

* **共通 MCP Server URL**: `https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp`
* **共通 OpenAPI 3.1.0 スキーマ URL**: `https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp/openapi.json`

> [!NOTE]
> ローカル環境では `https://www.kotodaman-db.com` を実際の開発ホスト（例: `http://localhost:8080` や `http://kotodaman-db.local`）に読み替えてください。

### 4.1 チャットAI（Web・デスクトップ・モバイル）

#### ChatGPT（MCPコネクタ / Apps・Developer Mode）
1. ChatGPT の設定 →「コネクタ（Apps / Developer Mode）」からカスタムMCPコネクタを追加します。
2. MCP Server URL に `https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp` を入力し、認証方式を **OAuth** に設定します（CIMD・DCR自動対応）。
3. 表示されるOAuthログイン・同意画面でサインインします。

#### ChatGPT（Custom GPTs / Actions）
1. 管理者に Static OAuth クライアント（`client_id`, `client_secret`）の発行を依頼し、Custom GPT 編集画面の Callback URL を管理画面へ登録してもらいます。
2. GPT Builder →「Configure」→「Actions」→「Import from URL」へ `https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp/openapi.json` を入力します。
3. Authentication で **OAuth**（Authorization URL: `/wp-json/kotodaman/v1/mcp/oauth/authorize` / Token URL: `/wp-json/kotodaman/v1/mcp/oauth/token` / Scope: `validate preview create`）または Basic API Key（アプリケーションパスワード）を設定します。

#### Claude（Web / Desktop / iOS / Android）
1. Claude「設定」→「コネクタ（Connectors / Integrations）」→「カスタムコネクタを追加」を開きます。
2. リモートMCP URLに `https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp` を入力して保存し、OAuth画面でログイン・承認します。

#### Gemini（Web / Developer Mode）
* カスタムMCP・OpenAPIツール対応環境では、MCP URL（`/wp-json/kotodaman/v1/mcp`）または OpenAPI URL（`/wp-json/kotodaman/v1/mcp/openapi.json`）を指定します（一般向け標準Web画面に外部MCP設定欄がない環境では後述の Gemini CLI を利用してください）。

#### Muse / Grok / Perplexity / Mistral Le Chat
1. 各サービスのカスタムMCPコネクタ（Integrations / Connectors）設定画面を開きます。
2. HTTP / Streamable HTTP エンドポイントに `https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp` を登録し、OAuth認証フロー（または対応クライアントではアプリケーションパスワードヘッダー）を完了します。

#### Microsoft Copilot Studio（Agent Builder）
1. 管理者に Static OAuth クライアントを発行してもらい、Power Platform のリダイレクトURIを登録します。
2. Copilot Studio の「ツール（Tools）」から Model Context Protocol または OpenAPI REST API を追加し、MCP URL / OpenAPI URL と Static OAuth 情報を入力します。

> [!WARNING]
> **非対応ツールについて**: 個人向けWeb版 **Microsoft Copilot**（`copilot.microsoft.com`）および **Poe** には、外部の汎用カスタムMCPサーバーやカスタムOAuth連携を接続する機能が存在しないため対応していません。VS Code の GitHub Copilot または Copilot Studio、あるいは ChatGPT / Claude / Gemini CLI 等をご利用ください。

---

### 4.2 開発環境・IDE・CLIエージェント

#### Antigravity（IDE / Agent CLI）
```bash
agy mcp add --header "Authorization: Bearer <USER>:<APP_PASSWORD>" kotodaman-db https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp
```
設定ファイル（`~/.gemini/antigravity/mcp_config.json`）の場合：
```json
{
  "mcpServers": {
    "kotodaman-db": {
      "serverUrl": "https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64(username:app_password)>"
      }
    }
  }
}
```

#### Claude Code
```bash
claude mcp add --transport http kotodaman-db https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp
```
追加後、Claude Code 内で `/mcp` → `kotodaman-db` →「Authenticate」を実行します。

#### Gemini CLI
`~/.gemini/settings.json`：
```json
{
  "mcpServers": {
    "kotodaman-db": {
      "httpUrl": "https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"
    }
  }
}
```

#### GitHub Copilot（VS Code）
`.vscode/mcp.json`：
```json
{
  "servers": {
    "kotodaman-db": {
      "type": "http",
      "url": "https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"
    }
  }
}
```

#### Cursor / Windsurf / Cline / Roo Code / Codex CLI / OpenCode / JetBrains IDE
* **Cursor** (`~/.cursor/mcp.json`): `{"mcpServers":{"kotodaman-db":{"url":"https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"}}}`
* **Windsurf** (`~/.codeium/windsurf/mcp_config.json`): `{"mcpServers":{"kotodaman-db":{"serverUrl":"https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"}}}`
* **Cline / Roo Code** (`streamableHttp`): `{"mcpServers":{"kotodaman-db":{"type":"streamableHttp","url":"https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"}}}`
* **Codex CLI** (`~/.codex/config.toml`): `[mcp_servers.kotodaman_db]` 配下に `url = "https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"` を記載し、`codex mcp login kotodaman-db` を実行
* **OpenCode** (`opencode.json`): `type: "remote"`, `url: "https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"`
* **JetBrains AI Assistant**: Settings → Tools → AI Assistant → Model Context Protocol から JSON `{"mcpServers":{"kotodaman-db":{"type":"http","url":"https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp"}}}` を追加

---

### 4.3 API・ワークフロー基盤・検証ツール

* **Gemini API / Gemini Enterprise (Vertex AI)**: Remote MCP（`/wp-json/kotodaman/v1/mcp`）または OpenAPI Tool（`/wp-json/kotodaman/v1/mcp/openapi.json` ＋ Static OAuth / Bearer）で連携。
* **Dify**: Dify「ツール > MCP」へ HTTP MCP URL を設定、またはカスタムツールへ `openapi.json` をインポート。
* **MCP Inspector（ブラウザ動作確認）**: `npx @modelcontextprotocol/inspector` を起動し、Streamable HTTP に `/wp-json/kotodaman/v1/mcp` を入力して接続・Quick OAuth Flow を確認可能（ブラウザ向けCORS・`MCP-Protocol-Version`・`Mcp-Session-Id` ヘッダー対応済み）。

---

## 5. 初回テスト用・外部AI向けコピペプロンプト

接続後、以下のプロンプトをそのまま外部AIへ入力し、ゲームのスクリーンショットを添付して実行してください。

```text
あなたは「コトダマンDB」のキャラクターデータ登録アシスタントです。
まず `get_field_schema` と `get_blessing_schema` と `get_terms` で利用可能なフィールド・祝福トークン・タクソノミーを確認し、`search_character` で重複キャラがないか確認してください。
次に、私が添付したスクリーンショットから読み取れる事実のみを使ってキャラクター情報を整理し、`preview_draft` で検証とサマリー作成を行ってください。
エラーや未解析の項目がなければ、私に確認を取ってから `create_draft` で下書きを作成してください。
```
