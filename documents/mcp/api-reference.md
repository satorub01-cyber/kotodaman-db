# MCPサーバー・OAuth 2.1・REST API 開発者リファレンス

本書は、コトダマンDBのMCPサーバー（[lib/acf/mcp/server.php](file:///var/www/html/wp-content/themes/cocoon-child-master/lib/acf/mcp/server.php)）および直接呼び出し用REST API（[lib/acf/mcp/post-mcp.php](file:///var/www/html/wp-content/themes/cocoon-child-master/lib/acf/mcp/post-mcp.php)）の技術仕様書です。

---

## 1. MCP Streamable HTTP サーバー仕様

* **エンドポイント**: `POST /wp-json/kotodaman/v1/mcp`
* **プロトコル**: JSON-RPC 2.0 / MCP Protocol Version `2025-03-26`
* **対応メソッド**:
  * `initialize`: サーバー情報（`name: "kotodaman-db"`, `version: "1.1.0"`）、`capabilities`（`tools`, `resources`, `prompts`）、およびAIへ自動注入される運用手順（`instructions`）を返却
  * `notifications/initialized`（および `notifications/*`）: HTTP `202 Accepted` を返却
  * `ping`: 空オブジェクト `{}` を返却
  * `tools/list`: 利用可能な全8ツールのスキーマ定義を返却
  * `tools/call`: 指定されたツールを実行（要 `Authorization: Bearer` または `Basic` 認証）
  * `resources/list` / `resources/read`: 運用ガイド（`kotodaman://guide/character-draft`）およびフィールド読み取り定義（`kotodaman://guide/field-manual`）を取得（`resources/read` は要認証）
  * `prompts/list` / `prompts/get`: 下書き作成用プロンプトテンプレート（`character_draft_from_screenshots`）を取得
* **レート制限**: 認証ユーザー単位で **1分あたり60リクエスト** まで（超過時はエラーコード `RATE_LIMITED` / HTTP `429`）。認証失敗時はIP単位で **5分間に10回まで**（ブルートフォース防止）。

---

## 2. MCPツール一覧（全9ツール）

### ツールと必要スコープの対応表

| ツール名 | 必要OAuthスコープ | 概要 |
|---|---|---|
| `get_field_schema` | `validate` | ACFフィールド定義、必須項目、自動入力キー一覧、[documents/mcp/mcp-field-manual.json](file:///var/www/html/wp-content/themes/cocoon-child-master/documents/mcp/mcp-field-manual.json) の説明、および `workflow_guide` を取得 |
| `get_terms` | `validate` | 指定タクソノミー（単一・カンマ区切り・`"all"`）のターム一覧（`id`, `name`, `slug`）を取得 |
| `search_character` | `validate` | 既存のキャラクター投稿（`publish`, `draft`, `pending`, `private`）を名前で検索（最大20件、保護対象の雛型記事は自動除外） |
| `get_draft` | `validate` | 既存下書きの基本データ（`basic_fields`）、タイトル、ステータス、エディタURL、MCPレビューメタデータを取得 |
| `validate` | `validate` | 保存せずに下書きデータを検証し、`preview_hash` と `parsed_counts` を返却（30分キャッシュ保存） |
| `preview_draft` | `preview` | 保存せずに下書きデータを検証・解析し、`preview_hash`、`parsed_counts`、`warnings` を返却（30分キャッシュ保存） |
| `create_draft` | `create` | 新規キャラクター下書き（`draft`）を作成。直前の `preview_hash` と `idempotency_key` のみで実行可能 |
| `update_draft` | `create` | 既存のキャラクター下書き（`draft` のみ）を更新。`post_id`, `preview_hash`, `idempotency_key` で実行可能（一部フィールドの部分更新にも対応） |
| `trash_draft` | `create` | 既存のキャラクター下書き（`draft` のみ）をゴミ箱へ移動（保護対象雛型は除外） |

---

### 各ツールの引数・レスポンス詳細

#### ① `get_field_schema`
* **引数**:
  * `test_mode` (`boolean`, 任意, 初期値 `false`): `false` の場合は `auto_input` 管理下の内部リピーター等を除外した `basic_fields` 向けコンパクト定義を返却。`true` の場合は全67フィールドの完全定義を返却。
* **レスポンス**:
  ```json
  {
    "fields": {
      "name_ruby": {
        "label": "名前ルビ（ひらがな）",
        "type": "text",
        "manual": {
          "description": "キャラ名のひらがな読みを指定する。カッコ `()` / `（）` 内の文字列は含めない（例: 「シンジ(決戦)」なら「しんじ」）。",
          "source": "スクリーンショットのキャラクター名欄"
        }
      }
    },
    "required_for_create": ["attribute", "species", "rarity"],
    "auto_input_keys": [
      "auto_input_waza_name",
      "auto_input_waza",
      "auto_input_sugowaza_name",
      "auto_input_sugowaza_condition",
      "auto_input_sugowaza",
      "auto_input_trait1",
      "auto_input_trait2",
      "auto_input_leader",
      "auto_input_blessing",
      "auto_input_kotowaza"
    ]
  }
  ```

#### ② `get_terms`
* **引数**:
  * `taxonomy` (`string`, **必須**): タクソノミー名。単一指定（例: `"affiliation"`）、カンマ区切り複数指定（例: `"attribute,species,rarity"`）、または `"all"`（`attribute`, `species`, `rarity`, `affiliation`, `event`, `gimmick`, `available_moji`, `suitable_quest` の8種一括）に対応。
  * `search` (`string`, 任意): ターム名・スラッグの部分一致検索キーワード。
  * `test_mode` (`boolean`, 任意)
* **レスポンス**:
  * 単一指定時: `{"taxonomy": "attribute", "terms": [{"id": 3, "name": "水", "slug": "water"}, ...]}`
  * 複数または `"all"` 指定時: `{"taxonomies": {"attribute": [...], "species": [...]}}`

#### ③ `search_character`
* **引数**:
  * `name` (`string`, **必須**): 検索するキャラクター名キーワード（最大200文字）。
* **保護仕様**:
  * 雛型記事（ID: `2947`, `2023`, `2637`, `2638`, `3845`, `4603`, `5294`, `3683` およびタイトルに「雛型」「雛形」を含む投稿）は結果から自動除外されます。
* **レスポンス**:
  ```json
  {
    "matches": [
      { "id": 1234, "title": "サンプルキャラ", "status": "draft" }
    ]
  }
  ```

#### ④ `get_draft`
* **引数**:
  * `post_id` (`integer`, **必須**): 取得対象のキャラクター投稿ID。
* **保護仕様**:
  * 雛型記事は取得不可（`VALIDATION_FAILED` / `reason: protected_template` / HTTP 403）。
* **レスポンス**:
  ```json
  {
    "post_id": 1234,
    "character_name": "サンプルキャラ",
    "status": "draft",
    "edit_url": "http://localhost:8080/wp-admin/admin.php?page=koto-acf-editor&edit_post_id=1234",
    "basic_fields": {
      "name_ruby": "さんぷるきゃら",
      "attribute": "水",
      "species": "神",
      "rarity": "なし",
      "available_moji_loop": [
        { "available_moji": "さ", "unlock_place": "default" }
      ]
    },
    "mcp_meta": {
      "generated": true,
      "needs_review": true,
      "screenshot_urls": ["https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png"],
      "audit": {
        "user_id": 1,
        "content_hash": "a1b2c3...",
        "created_at": "2026-11-07 12:00:00"
      }
    }
  }
  ```

#### ⑤ `validate` / ⑥ `preview_draft`
* **引数**:
  * `character_name` (`string`, 任意 ※`post_id` 未指定の新規作成時は必須、最大200文字)
  * `post_id` (`integer`, 任意): 既存下書きの部分更新時に指定。指定時は既存の `character_name` や `attribute`, `species`, `rarity` が自動継承されます。
  * `basic_fields` (`object`, 任意): 各文字列値は最大2,000文字。新規作成時は `attribute`, `species`, `rarity` が必須。
  * `auto_input` (`object`, 任意): 各自動入力キーとゲーム内文言の文字列マップ（各値最大4,000文字）。
  * `screenshot_urls` (`array<string>`, 任意, 最大20件)
  * `evidence` (`object`, 任意, 最大16KB): 読み取り根拠等の監査メタデータ。
  * `test_mode` (`boolean`, 任意, 初期値 `false`): `true` の場合のみ `parsed_acf` 全構造をレスポンスに含める。
* **サーバー内部動作**:
  * バリデーション成功時、ユーザーIDと `content_hash`（SHA-256）をキーとするTransient（`koto_mcp_prev_{user_id}_{hash}`）に入力一式を **30分間キャッシュ** します。
  * 部分未解析（丸数字区分や `+` 区切りで一部のみ解析失敗した場合）を検出し、`warnings` 配列に警告メッセージを返却します。
* **レスポンス**:
  ```json
  {
    "valid": true,
    "content_hash": "a1b2c3...",
    "preview_hash": "a1b2c3...",
    "parsed_counts": {
      "auto_input_waza_name": 1,
      "auto_input_waza": 1,
      "auto_input_trait1": 3
    },
    "warnings": []
  }
  ```

#### ⑦ `create_draft` / ⑧ `update_draft`
* **引数**:
  * `preview_hash` (`string`, **必須**): 直前の `preview_draft`（または `validate`）で返却されたハッシュ値。
  * `idempotency_key` (`string`, **必須**, 1〜255文字): 二重作成・二重更新を防ぐ一意キー。
  * `post_id` (`integer`, **`update_draft` 時のみ必須**): 更新対象の下書き投稿ID（`post_status === 'draft'` のみ許可、雛型記事は保護され拒否）。
  * `character_name`, `basic_fields`, `auto_input`, `screenshot_urls`, `evidence` (すべて任意): 省略された場合、サーバー側の30分キャッシュ（`preview_hash`）から自動復元されます。明示的に渡された場合はハッシュ一致検証（`PREVIEW_HASH_MISMATCH` 判定）が行われます。
  * `test_mode` (`boolean`, 任意, 初期値 `false`)
* **レスポンス**:
  ```json
  {
    "post_id": 1234,
    "status": "draft",
    "edit_url": "http://localhost:8080/wp-admin/admin.php?page=koto-acf-editor&edit_post_id=1234",
    "content_hash": "a1b2c3...",
    "screenshot_urls": [
      "https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png"
    ],
    "warnings": []
  }
  ```

#### ⑨ `trash_draft`
* **引数**:
  * `post_id` (`integer`, **必須**): ゴミ箱へ移動するキャラクター下書きの投稿ID（`post_type === 'character'` かつ `post_status === 'draft'` かつ `delete_post` 権限がある場合のみ実行可）。
* **レスポンス**:
  ```json
  {
    "post_id": 1234,
    "status": "trash"
  }
  ```

---

### バリデーション＆エラーコード仕様

`validate` / `preview_draft` / `create_draft` / `update_draft` における主なエラーコードは以下の通りです。

| エラーコード | 発生条件 | 詳細データ (`detail`) / 理由 (`reason`) |
|---|---|---|
| `INVALID_PARAMS` | ツール引数の型不正・必須引数欠落 | `required_empty`, `invalid_type` |
| `VALIDATION_FAILED` | `basic_fields` または `auto_input` の検証失敗 | `missing_fields` / `invalid_fields` 配列内に以下の `reason` を返却：<br>・`required_empty`: 必須項目（`attribute`, `species`, `rarity` 等）が空<br>・`unknown_term`: 存在しないタクソノミーターム<br>・`invalid_choice`: ACF選択肢（`choices`）外の値<br>・`invalid_number` / `below_min` / `above_max`: 数値型・範囲エラー<br>・`invalid_boolean`: 真偽値型エラー<br>・`unparsed_text`: `auto_input` の文言が1件もACF化できなかった |
| `PREVIEW_HASH_MISMATCH` | `preview_hash` が現在の入力内容のハッシュと不一致 | 入力を変更した場合は再度 `preview_draft` が必要 |
| `IDEMPOTENCY_CONFLICT` | 同一 `idempotency_key` が異なるパラメータで再利用された | HTTP `409 Conflict` |
| `IDEMPOTENCY_IN_PROGRESS` | 同一 `idempotency_key` のリクエストが現在処理中（120秒ロック） | HTTP `409 Conflict` |
| `RATE_LIMITED` | 1分あたり60リクエストを超過 | HTTP `429 Too Many Requests` |

---

## 3. OAuth 2.1 / PKCE 認可・セキュリティ仕様

### エンドポイント一覧

| 用途 | メソッド & パス | 説明 |
|---|---|---|
| 保護リソースメタデータ | `GET /.well-known/oauth-protected-resource` | リソースURI（`/wp-json/kotodaman/v1/mcp`）、認可サーバーURL、対応スコープ（`validate`, `preview`, `create`）を返却 |
| 認可サーバーメタデータ | `GET /.well-known/oauth-authorization-server` | issuer、各OAuthエンドポイントURL、PKCE (`S256`) 対応情報を返却 |
| 動的クライアント登録 | `POST /wp-json/kotodaman/v1/mcp/oauth/register` | `client_name`, `redirect_uris` を受け取り `client_id`（`koto_...`、有効期限1年）を発行。IPあたり30回/分のレート制限あり |
| ブラウザ認可エンドポイント | `GET / POST /mcp/oauth/authorize` | 未ログイン時は認可ページ内でWordPressログインを行い、ログイン後もOAuthパラメータを保持。PKCE（`code_challenge` + `code_challenge_method=S256`）必須。認可コード（有効期限5分）を発行 |
| トークンエンドポイント | `POST /wp-json/kotodaman/v1/mcp/oauth/token` | `authorization_code` または `refresh_token` グラントでトークンを発行 |
| トークン失効エンドポイント | `POST /wp-json/kotodaman/v1/mcp/oauth/revoke` | アクセストークンまたはリフレッシュトークン（および紐づくトークンファミリー全体）を即時失効 |

### セキュリティ設計のポイント
1. **`redirect_uri` の厳格検証**:
   * フラグメント（`#`）を含むURIは拒否。
   * スキームは `https`、またはローカル開発環境（`localhost`, `127.0.0.1`, `[::1]`, サイト自身のホスト）の `http` のみ許可。
   * 認可時・トークン交換時ともに、クライアント登録時の `redirect_uris` と完全一致しない限り認可コード・トークンを発行しません。
2. **ユーザー権限と管理画面承認フロー**:
   * `MCP利用者`（`mcp_user`）ロールまたは `edit_posts` 権限を持たないユーザーは利用不可（`403 Forbidden`）。
   * `administrator`（管理者）、`editor`（編集者）、`MCP利用者`ロールは即時認可可能。
   * それ以外のロール（投稿者 `author` 等）が認可画面へアクセスすると、ユーザーメタ `_koto_mcp_requested = 1` が記録され保留状態となります。管理者がWordPress管理画面「**ユーザー > MCP利用申請**（`users.php?page=koto-mcp-approvals`）」で承認すると `_koto_mcp_approved = 1` が付与され、利用可能になります。
3. **`create` スコープの明示同意画面（CSRF / クリックジャッキング対策）**:
   * 下書きの作成・更新・削除が可能な `create` スコープを含む認可リクエスト時は、`X-Frame-Options: DENY` / `Content-Security-Policy: frame-ancestors 'none'` およびパラメータ束縛済みWordPress Nonce付きの同意確認画面を表示し、ユーザーの明示的な「許可」POSTを必須としています。
4. **未ログイン時の保護**:
   * 認可ページ内のログインフォームは、既存の `wp-login.php` 専用CAPTCHAを自動移植せず、ログイン試行をIP/ユーザー名単位で5回・15分に制限します。CAPTCHAを認可ページへ統合する場合は、認証プラグイン側の専用フック対応が必要です。
5. **リフレッシュトークン・ローテーションとリプレイ検知**:
   * アクセストークンは有効期限1時間（SHA-256ハッシュ化してTransient保存）、リフレッシュトークンは有効期限30日（SHA-256ハッシュ化してOption保存）。
   * リフレッシュトークン使用時は毎回新しいリフレッシュトークンへローテーション（旧トークンは `status = 'rotated'` に更新）されます。
   * ローテーション済みの旧リフレッシュトークンが再利用（リプレイ）された場合、漏洩とみなして同一 `family_id` に属する全トークンを一括失効（`koto_mcp_revoke_refresh_family`）させます。

---

## 4. WordPress REST API 直接呼び出し仕様

MCPクライアントを介さず、WordPress標準のREST API認証（Application Passwords等）で直接下書きを作成するエンドポイントです（実装: [lib/acf/mcp/post-mcp.php](file:///var/www/html/wp-content/themes/cocoon-child-master/lib/acf/mcp/post-mcp.php)）。

### エンドポイント
```text
POST /wp-json/kotodaman/v1/mcp/character-draft
```
* **必要権限**: `edit_posts`（`screenshots` 配列でBase64画像を添付する場合は加えて `upload_files` 権限が必要）。

### リクエストボディ例
```json
{
  "character_name": "サンプルキャラ",
  "basic_fields": {
    "name_ruby": "さんぷるきゃら",
    "attribute": "火",
    "species": "神",
    "rarity": "なし",
    "available_moji_loop": [
      { "available_moji": "さ" }
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
    "https://www.kotodaman-db.com/wp-content/uploads/2026/02/sample.png"
  ],
  "dry_run": false,
  "test_mode": false
}
```

* **`screenshots`**: PNG / JPEG / WebP の Base64 Data URI 配列（1枚最大10MB、最大20枚）。アップロード途中でエラーが発生した場合は、作成途中の添付ファイルと下書き投稿をすべてロールバック削除します。
* **`dry_run`**: `true` の場合、投稿を作成せずに自動入力パーサーの解析のみを実行し、`parsed_counts`（`test_mode: true` 時は `parsed_acf`）を返します。

---

## 5. 保存される投稿メタデータと変更時の原則

### MCPが下書き投稿へ保存するメタデータ

| メタキー | 内容 |
|---|---|
| `_koto_mcp_generated` | MCP経由で作成・更新されたことを示すフラグ（`1`） |
| `_koto_mcp_needs_review` | 人間による確認が必要であることを示すフラグ（`1`） |
| `_koto_mcp_audit` | 監査情報（`user_id`, `content_hash`, `evidence`, `created_at`） |
| `_koto_mcp_screenshot_urls` | 指定された `screenshot_urls`（未指定時は準備中画像URL）の配列 |
| `_spec_json` | `do_action('acf/save_post', $post_id)` 連動により `on_save_character_specs()` が自動生成するキャラクタースペックJSON |

### 開発・保守時の原則
1. **基本データ（`basic_fields`）と自動入力データ（`auto_input`）を混ぜない**。
2. **自動公開機能を追加しない**（公開は必ず人間がDBエディタまたは管理画面で確認して行う）。
3. **既存ACFパーサーを再利用する**: MCP側に独自の文言解析ロジックを複製せず、`koto_build_acf_data_from_inputs()` と `lib/ゲーム内文言ーACF-対応表.csv` に一元化する。
4. **ACF更新時は必ずフィールドキー（`field_xxxxxx`）へ変換する**: `koto_get_top_level_character_acf_field()` および `koto_convert_acf_rows_to_field_keys()` を通して保存し、同名サブフィールドとのキャッシュ衝突を防止する。
5. **外部画像取得時のSSRF防止**: `koto_mcp_set_featured_image_from_url()` では許可ホスト（`www.kotodaman-db.com`）、プロトコル（`http`/`https`）、ファイルサイズ（10MB以下）、MIMEタイプ（PNG/JPEG/WebP/GIF）、タイムアウト（15秒）の制限を厳守する。
