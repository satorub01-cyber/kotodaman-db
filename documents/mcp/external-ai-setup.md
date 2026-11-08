# 外部AIサービス（Muse / Claude / ChatGPT / Gemini）連携・設定ガイド

本書は、本ディレクトリ外にある通常のチャットAI（Muse、Claude Web/Desktop、ChatGPT、Gemini CLI等）からコトダマンDBのMCPサーバーまたはREST APIへ安全に接続し、スクリーンショットからキャラクター下書きを作成する手順をまとめたガイドです。

---

## 1. 外部AIはどうやって「使い方」や「スクショ読み取りルール」を知るのか？

外部のチャットAIはローカルの `AGENTS.md` を直接読めませんが、本MCPサーバーは以下の **4つの仕組み** によって、**接続した外部AIが自動的に使い方・スクリーンショット運用ルール・フィールド規則を取得できる**ようになっています。

| 仕組み | 対応する外部AI | 自動取得される内容 |
|---|---|---|
| **① MCP `initialize` の `instructions`** | Muse / Claude / ChatGPT MCP / Antigravity / Gemini CLI 等の全MCPクライアント | 接続確立時に、スクリーンショットの読み取りルール・最短4ステップ手順・主要フィールド規則・禁止事項がAIのシステムコンテキストへ自動注入されます。 |
| **② `get_field_schema`（および `GET /mcp/schema`）の `workflow_guide` & `manual`** | 全MCPクライアント ＋ ChatGPT Custom GPTs (Actions) | ツール呼び出し1回で、各ACFフィールドの型・必須項目・画面上の読み取り根拠（`manual`）に加え、`workflow_guide`（スクショ処理・ターム自動解決・`preview_hash` の使い方）がJSONで返却されます。 |
| **③ MCP Resources（`resources/list`, `resources/read`）** | リソース参照対応のMCPクライアント | `kotodaman://guide/character-draft`（運用ガイド全文Markdown）および `kotodaman://guide/field-manual`（フィールド定義JSON）をいつでも参照できます。 |
| **④ MCP Prompts（`prompts/list`, `prompts/get`）** | プロンプトメニュー対応のMCPクライアント | `character_draft_from_screenshots` プロンプトを選択するだけで、下書き作成用の指示文がチャット欄に自動セットされます。 |

---

## 2. セキュリティと認証方式（2種類の安全な認証）

外部AIからの接続には、用途に応じて以下の **2種類の認証方式** を利用できます。どちらの方式でも、以下のセキュリティ制限が共通で強制されます。

* **共通セキュリティ制約**:
  1. WordPressの `edit_posts` 権限が必要。
  2. 管理者（`administrator`）・編集者（`editor`）以外のユーザーは、WordPress管理画面「**ユーザー > MCP利用申請**」で承認済み（`_koto_mcp_approved = 1`）である必要がある。
  3. ユーザー単位のレート制限（**60リクエスト/分**）および認証失敗時のIPブルートフォース制限（**5分間に10回失敗でロック**）。
  4. 投稿は常に **`draft`（下書き）** で作成され、公開操作は一切不可。

### 方式A：OAuth 2.1 + PKCE（Muse / Claude Web・アプリ / ChatGPT MCPコネクタ推奨）
* パスワードを外部AIサービス側に預けることなく、ブラウザのWordPressログイン＆同意画面（`/mcp/oauth/authorize`）で安全に認可する方式です。
* アクセストークンは24時間（`expires_in: 86400`）で自動失効し、リフレッシュトークン（30日・ローテーション＆60秒Grace Period＆リプレイ検知付き）で自動更新されます。

### 方式B：WordPress アプリケーションパスワード（ChatGPT Custom GPT Actions / CLIツール推奨）
* OAuthの自動更新に対応していないクライアントや、ChatGPTの「Custom GPTs（マイGPT）」の Actions（API Key認証）向けに、WordPress標準の「アプリケーションパスワード」をHTTPS経由で利用できます。
* **発行手順**:
  1. WordPress管理画面「**ユーザー > プロフィール**」を開く。
  2. 「**アプリケーションパスワード**」欄に任意の名前（例: `ChatGPT-Kotodaman-Draft`）を入力して「新しいアプリケーションパスワードを追加」をクリック。
  3. 表示された24文字のパスワード（スペースは除いてもそのままでも可）を控え、以下の形式で指定します：
     * **HTTP Basic 認証の場合**: ユーザー名に「WordPressのユーザー名」、パスワードに「発行したアプリケーションパスワード」
     * **Bearer トークン（API Key）欄に1つの文字列で入れる場合**: `ユーザー名:アプリケーションパスワード`（またはそれをBase64エンコードした文字列）
* **失効方法**: 万一不要になった場合は、WordPressプロフィール画面の「このパスワードを取り消す」ボタン1つで即座に無効化できます。

---

## 3. 各外部AIサービスの設定手順

### ① Muse / Claude（Web・Desktop・スマホアプリのカスタムコネクタ）

1. Muse または Claude のコネクタ設定画面（「カスタムコネクタを追加」/「Add Custom Connector」）を開く。
2. MCPサーバーURLに以下を入力する：
   ```text
   https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp
   ```
3. 認可画面（`/mcp/oauth/authorize`）へ遷移したら、WordPressにログインし、表示された同意画面で「**許可**」をクリックする。
4. **使い方**:
   * チャット画面にゲームのスクリーンショット（複数枚まとめて添付可）を貼り付け、「**このスクショからコトダマンDBの下書きを作成して**」と送信するだけで、`preview_draft` → `create_draft` が自動実行され、下書きエディタURL（`edit_url`）が返ってきます。

---

### ② ChatGPT（Custom GPTs「マイGPT」の Actions で使う場合）

スマホアプリやWeb版のChatGPTで「スクショを貼るだけで下書きを作る専用GPT」を作成できます。

1. ChatGPTの「**GPTを作成する（Explore GPTs > Create）**」を開く。
2. **「指示（Instructions）」** 欄に、本ページ末尾の「[外部AI用システムプロンプト（コピペ用）](#4-外部ai用システムプロンプトコピペ用)」を貼り付ける。
3. 下部の「**アクションを作成する（Create new action）**」を開き、**スキーマ（Schema）** 欄に [documents/mcp/openapi.json](file:///var/www/html/wp-content/themes/cocoon-child-master/documents/mcp/openapi.json) の内容をそのまま貼り付ける。
4. **「認証（Authentication）」** をクリックし、「**APIキー（API Key）**」を選択：
   * **認証タイプ（Auth Type）**: `Bearer`（または `Basic`）
   * **APIキー**: `Bearer` を選んだ場合は `<WordPressユーザー名>:<アプリケーションパスワード>` を入力して保存する。
5. 公開範囲を「**自分だけ（Only me）**」にして保存する。

---

### ③ Gemini CLI / Antigravity / Claude Code（開発環境・CLI）

アプリケーションパスワードを使って期限切れなしで常時接続する場合：

```bash
# <USER>:<APP_PASSWORD> を指定して登録
agy mcp add --header "Authorization: Bearer <USER>:<APP_PASSWORD>" kotodaman-db https://www.kotodaman-db.com/wp-json/kotodaman/v1/mcp
```

---

## 4. 外部AI用システムプロンプト（コピペ用）

MCP接続では `initialize` の `instructions` で自動伝達されますが、ChatGPTの「マイGPT（Instructions）」やプロジェクトのカスタム指示に設定しておくと、より確実・高速に動作します。

```markdown
あなたは「コトダマンDB」のキャラクター下書き作成アシスタントです。
ユーザーが添付したゲームのスクリーンショット（複数枚可）から情報を正確に読み取り、ツール（MCPまたはActions）を使ってWordPress上に下書き（draft）を作成してください。

## 厳守事項
1. スクリーンショットに存在しない値や読み取れない文字を推測で埋めないこと。不明な箇所は空欄にするかユーザーへ確認すること。
2. 投稿の公開は行わないこと（常に下書き作成のみ）。

## フィールド読み取り規則
- `character_name`: キャラクター名（例: `シンジ(決戦)`）。
- `basic_fields`（必須: `attribute`, `species`, `rarity`）:
  - `name_ruby`: キャラ名のひらがな読みのみ。カッコ `()` / `（）` 内の文字列は含めない（例: `シンジ(決戦)` → `しんじ`）。
  - `attribute`: 属性名（`"火"`, `"水"`, `"木"`, `"光"`, `"闇"`, `"冥"`, `"天"`）。
  - `species`: 種族名（`"神"`, `"魔"`, `"英"`, `"龍"`, `"獣"`, `"霊"`, `"物"`, `"妖"`）。
  - `rarity`: ★6通常キャラは `"なし"`、★6特殊枠は `"スペシャル"` / `"レジェンド"` / `"グランド"`、★5以下は数字（`"5"` 等）。
  - `affiliation`: 所属グループ名の配列（例: `["エヴァンゲリオン"]`）。表記が不明な場合のみ `get_terms(taxonomy: "affiliation", search: "キーワード")` で確認する。
  - `status_auto_tf`: Lv.120のキャラクターの場合は `true`。
  - `hp_chouka`, `atk_chouka`: 【入力要求】コエダマによるHP・ATK超化pt（画面の+〇〇表示または超化欄から読み取って指定）。
  - `lv_99_hp`, `lv_99_atk`（または `lv_120_hp`, `lv_120_atk`）: ステータス基礎値（超化分を含まない基礎値）。
  - `max_ls_hp`, `max_ls_atk`: リーダーとくせいの最大HP・ATK倍率（%の数値）。
  - `available_moji_loop`: 初期使用可能文字のみを `[{"available_moji": "い"}, {"available_moji": "う"}]` 形式で指定する。**ア行（ぁ/ぃ/ぅ/ぇ/ぉ）やヤ行（ゃ/ゅ/ょ）の小文字は大文字と明確に区別して入力すること**。とくせい1・2や祝福で追加される文字は `auto_input` から自動生成されるためここには含めない。
- `auto_input`:
  - `auto_input_waza_name`, `auto_input_waza`（わざ名・わざ説明文）
  - `auto_input_sugowaza_name`, `auto_input_sugowaza_condition`, `auto_input_sugowaza`（すごわざ名・発動条件・すごわざ説明文）
  - `auto_input_trait1`, `auto_input_trait2`（とくせい1・2説明文）
  - `auto_input_leader`（リーダーとくせい説明文）
  - `auto_input_blessing`（祝福説明文）
  - `auto_input_kotowaza`（コトワザ説明文）

## 実行ステップ
1. 添付画像から `character_name`, `basic_fields`, `auto_input` を抽出する。
2. まずプレビュー検証（MCPの場合は `preview_draft`、REST Actionsの場合は `dry_run: true`）を実行し、`preview_hash` と解析件数（`parsed_counts`）を確認する。
3. エラー（`VALIDATION_FAILED`）が出た場合は、該当箇所のみ最大3回まで画像を再確認して修正する。3回で解決しない場合は停止してユーザーに確認する。
4. プレビューが成功したら、本作成（MCPの場合は `create_draft`、REST Actionsの場合は `dry_run: false`）に `preview_hash` と一意の `idempotency_key` のみを渡して下書きを作成する（`basic_fields` や `auto_input` はサーバー側の30分キャッシュから自動復元されるため再送不要）。
5. 返却された `edit_url`（DBエディタURL）と警告の有無をユーザーに提示する。
```
