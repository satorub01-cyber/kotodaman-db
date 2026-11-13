# MCPサーバー・OAuth 2.1・REST API 開発者リファレンス

本書は、コトダマンDBのMCPサーバー（[lib/mcp/protocol/server.php](../../lib/mcp/protocol/server.php)）および直接呼び出し用REST API（[lib/mcp/protocol/post-mcp.php](../../lib/mcp/protocol/post-mcp.php)）の技術仕様書です。

---

## 1. MCP Streamable HTTP サーバー仕様

* **エンドポイント**: `POST /wp-json/kotodaman/v1/mcp`（`OPTIONS` CORSプリフライト、未認証 `GET` の `401` + `WWW-Authenticate` 返却、認証済み `GET` の `405` 応答にも対応）
* **プロトコル**: JSON-RPC 2.0（単一リクエストおよび配列バッチリクエスト対応） / MCP Protocol Version `2025-11-25`（ネゴシエーション対応: `2025-11-25`, `2025-06-18`, `2025-03-26`、`MCP-Protocol-Version` ヘッダー対応）
* **CORS / Origin 検証**: ブラウザ系MCPクライアント向けに `Access-Control-Allow-Origin` / `Access-Control-Allow-Methods` / `Access-Control-Allow-Headers`（`MCP-Protocol-Version`, `Mcp-Session-Id` 等）・`Access-Control-Expose-Headers`（`WWW-Authenticate`, `Mcp-Session-Id`, `MCP-Protocol-Version`）を返却。不正な `Origin` によるリクエストは HTTP `403` で拒否。
* **対応メソッド**:
  * `initialize`: サーバー情報（`name: "kotodaman-db"`, `version: "1.1.0"`）、`capabilities`（`tools`, `resources`, `prompts`）、ネゴシエーション済み `protocolVersion`、およびAIへ自動注入される運用手順（`instructions`）を返却
  * `notifications/initialized`（および `notifications/*`）: HTTP `202 Accepted` を返却
  * `ping`: 空オブジェクト `{}` を返却
  * `tools/list`: 利用可能な全10ツールのスキーマ定義および MCP Tool `annotations`（`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`）を返却
  * `tools/call`: 指定されたツールを実行（要 `Authorization: Bearer` または `Basic` 認証）。入力・バリデーション・保護対象等のドメインエラー時は JSON-RPC `result.isError: true` で返却し、AIが自動リトライ・自己修復しやすい形式で応答
  * `resources/list` / `resources/read`: 運用ガイド（`kotodaman://guide/character-draft`）およびフィールド読み取り定義（`kotodaman://guide/field-manual`）を取得（`resources/read` は要認証）
  * `prompts/list` / `prompts/get`: 下書き作成用プロンプトテンプレート（`character_draft_from_screenshots`）を取得
* **レート制限**: 認証ユーザー単位で **1分あたり60リクエスト** まで（超過時はエラーコード `RATE_LIMITED` / HTTP `429`）。認証失敗時はIP単位でブルートフォース制限を実施。

---

## 2. MCPツール一覧（全10ツール）

### ツールと必要スコープ・Annotations対応表

| ツール名 | 必要OAuthスコープ | Annotations (`readOnly` / `destructive` / `idempotent`) | 概要 |
|---|---|---|---|
| `get_field_schema` | `validate` | `true` / `false` / `true` | ACFフィールド定義、必須項目、自動入力キー一覧、[documents/mcp/mcp-field-manual.json](mcp-field-manual.json) の説明、および `workflow_guide` を取得 |
| `get_blessing_schema` | `validate` | `true` / `false` / `true` | 祝福（`auto_input_blessing`）およびEX祝福（`auto_input_ex_blessing`）の入力スキーマ、利用可能トークン一覧、テンプレート構成一覧を取得 |
| `get_terms` | `validate` | `true` / `false` / `true` | 指定タクソノミー（単一・カンマ区切り・`"all"`）のターム一覧（`id`, `name`, `slug`）をページネーション付き（`limit`, `offset`）で取得 |
| `search_character` | `validate` | `true` / `false` / `true` | 既存のキャラクター投稿（`publish`, `draft`, `pending`, `private`）を名前で検索（最大20件、保護対象の雛型記事は自動除外） |
| `get_draft` | `validate` | `true` / `false` / `true` | 既存下書きの基本データ（`basic_fields`）、祝福（`blessing`）、EX祝福（`ex_blessing`）、タイトル、ステータス、エディタURL、MCPレビューメタデータを取得 |
| `validate` | `validate` | `true` / `false` / `true` | 保存せずに下書きデータを検証し、`preview_hash` と `parsed_counts` を返却（30分キャッシュ保存） |
| `preview_draft` | `preview` | `true` / `false` / `true` | 保存せずに下書きデータを検証・解析し、`preview_hash`、`parsed_counts`、`warnings` を返却（30分キャッシュ保存） |
| `create_draft` | `create` | `false` / `false` / `true` | 新規キャラクター下書き（`draft`）を作成。直前の `preview_hash` と `idempotency_key` のみで実行可能 |
| `update_draft` | `create` | `false` / `false` / `true` | 既存のキャラクター下書き（`draft` のみ）を更新。`post_id`, `preview_hash`, `idempotency_key` で実行可能（一部フィールドの部分更新にも対応） |
| `trash_draft` | `create` | `false` / `true` / `true` | 既存のキャラクター下書き（`draft` のみ）をゴミ箱へ移動（保護対象雛型は除外） |

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
      "auto_input_ex_blessing",
      "auto_input_kotowaza"
    ]
  }
  ```

#### ② `get_blessing_schema`
* **引数**:
  * `include_gimmicks` (`boolean`, 任意, 初期値 `true`): ギミック耐性トークン（通常・スーパー化）を含めるかどうか。
* **レスポンス**:
  ```json
  {
    "schema": {
      "type": "object",
      "description": "auto_input_blessing および auto_input_ex_blessing の入力形式",
      "oneOf": [ ...新形式 items 配列 および 旧形式 template+rows のスキーマ... ]
    },
    "limits": {
      "max_items": 8,
      "default_point_for_single": 20,
      "description": "祝福およびEX祝福はそれぞれ最大8項目（MAX 8）まで設定可能。"
    },
    "tokens": [
      {
        "key": "hp_up",
        "label": "HPアップ",
        "category": "status_up",
        "target_type": "self",
        "point_pattern": "level",
        "description": "自身のHPアップ（★6: 200!, ★5: 100~350）"
      },
      {
        "key": "attr_killer",
        "label": "属性キラー",
        "category": "damage_correction",
        "target_type": "attr",
        "point_pattern": "level",
        "description": "属性キラー（★6: 10~20%, ★5: 5~15%）。targetに属性名を指定（例: 火）"
      }
    ],
    "templates": [
      {
        "key": "spe2_atkup",
        "label": "ガチャ標準（種族K2+ATKUP）",
        "tokens": ["hp_up", "attr_killer1", "species_killer1", "attr_mitigation1", "species_killer2", "atk_up"],
        "required_targets": { "attrs": 2, "species": 2 }
      }
    ]
  }
  ```

#### ③ `get_terms`
* **引数**:
  * `taxonomy` (`string`, **必須**): タクソノミー名。単一指定（例: `"affiliation"`）、カンマ区切り複数指定（例: `"attribute,species,rarity"`）、または `"all"`（`attribute`, `species`, `rarity`, `affiliation`, `event`, `gimmick`, `available_moji`, `suitable_quest` の8種一括）に対応。
  * `search` (`string`, 任意): ターム名・スラッグの部分一致検索キーワード。
  * `limit` (`integer`, 任意, 初期値 `200`, 最大 `500`): 各タクソノミーで返す最大件数。
  * `offset` (`integer`, 任意, 初期値 `0`): ページネーション開始位置。
  * `test_mode` (`boolean`, 任意)
* **レスポンス**:
  * 単一指定時: `{"taxonomy": "attribute", "terms": [...], "total": 6, "limit": 200, "offset": 0, "has_more": false, "next_offset": null}`
  * 複数または `"all"` 指定時: `{"taxonomies": {"attribute": [...], ...}, "pagination": {"attribute": {"total": 6, "returned": 6, "has_more": false, "next_offset": null}, ...}, "limit": 200, "offset": 0}`

#### ④ `search_character`
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

#### ⑤ `get_draft`
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
      "hp_chouka": 500,
      "atk_chouka": 250,
      "available_moji_loop": [
        { "available_moji": "さ", "unlock_place": "default" }
      ]
    },
    "computed": {
      "lv_120_hp": 2500,
      "lv_120_atk": 1100,
      "talent_hp": 200,
      "talent_atk": 100,
      "waza_name": "わざ名",
      "sugowaza_name": "すごわざ名",
      "first_trait_name": null,
      "second_trait_name": null,
      "release_date": "2026-11-08"
    },
    "blessing": [
      { "type": "token", "token": "hp_up", "point": 20, "label": "HPアップ", "text": "自身のHP200!UP" },
      { "type": "token", "token": "attr_killer", "target": "火", "point": 20, "label": "属性キラー", "text": "火属性キラー20%" }
    ],
    "ex_blessing": [
      { "type": "token", "token": "atk_up", "point": 20, "label": "ATKアップ", "text": "自身のATK50!UP" }
    ],
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

#### ⑥ `validate` / ⑦ `preview_draft`
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

#### ⑧ `create_draft` / ⑨ `update_draft`
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

#### ⑩ `trash_draft`
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

## 3. OAuth 2.1 / PKCE・CIMD・Static Confidential Client・サイト登録仕様

### エンドポイント一覧

| 用途 | メソッド & パス | 説明 |
|---|---|---|
| 保護リソースメタデータ | `GET /.well-known/oauth-protected-resource` | リソースURI（`/wp-json/kotodaman/v1/mcp`）、認可サーバーURL、対応スコープ（`validate`, `preview`, `create`）を返却（CORS対応） |
| 認可サーバーメタデータ | `GET /.well-known/oauth-authorization-server` | issuer、各OAuthエンドポイント、`client_id_metadata_document_supported: true`、`client_secret_basic` / `client_secret_post` / `none`、PKCE (`S256`) 対応情報を返却 |
| 動的クライアント登録 (DCR) | `POST /wp-json/kotodaman/v1/mcp/oauth/register` | `client_name`, `redirect_uris` を受け取り Public Client `client_id`（`koto_...`、有効期限1年）を発行。IPあたり30回/分のレート制限あり |
| CIMD (SEP-991) | — | HTTPS URL形式の `client_id` を自動的にフェッチ・検証（SSRF防止・64KB上限・5秒タイムアウト・短時間キャッシュ）しクライアント登録不要で認可 |
| Static Confidential Clients | WordPress管理画面 UI | 管理画面「ユーザー > MCP利用申請」から `client_id` (`koto_sc_...`) ＋ `client_secret` を事前発行（ChatGPT Actions / Copilot Studio / Gemini Enterprise / Dify 向け、`allow_no_pkce` オプトイン可） |
| ブラウザ認可エンドポイント | `GET / POST /mcp/oauth/authorize`（および `/wp-json/kotodaman/v1/mcp/oauth/authorize`） | 未ログイン時は認可ページ内でWordPressログイン（協力者登録ページへの導線リンク付き）。Public ClientはPKCE (`S256`) 必須、Static Confidential Clientのみ `allow_no_pkce` オプトイン可。RFC 8707 `resource`（`aud`）を認可コードに束縛 |
| トークンエンドポイント | `POST /wp-json/kotodaman/v1/mcp/oauth/token` | `authorization_code` または `refresh_token` グラントでトークンを発行。Confidential Clientの `client_secret_basic` / `client_secret_post` 検証および `resource` (`aud`) 検証（`invalid_target`）を実施 |
| トークン失効エンドポイント | `POST /wp-json/kotodaman/v1/mcp/oauth/revoke` | アクセストークンまたはリフレッシュトークン（および紐づくトークンファミリー全体）を即時失効 |

### セキュリティ設計のポイント
1. **`redirect_uri` & `resource`（RFC 8707 `aud`）の厳格検証**:
   * `redirect_uri` はフラグメント（`#`）を拒否し、`https` またはローカルループバック `http` のみ許可。登録済みURIと完全一致必須。
   * トークンの `aud` クレームにサーバー正規リソースURI（`koto_mcp_server_url()`）またはRESTベースURIを束縛し、不一致リソース用トークンは `401 token_audience_mismatch` で拒否。
2. **CIMD（SEP-991）のSSRF防御**:
   * `client_id` がHTTPS URLの場合、`localhost` / プライベートIP / リンクローカルIP（`169.254.169.254` 等）への解決を拒否し、メタデータJSON内の `client_id` とリクエスト `client_id` の完全一致、`redirect_uris` の妥当性を検証します。
3. **SEP-835 `insufficient_scope` 対応**:
   * 認可済みトークンでも必要スコープ（例: `create`）が不足している場合、HTTP `403` と共に `WWW-Authenticate: Bearer realm="...", error="insufficient_scope", scope="<required>"` を返却し、クライアント側の段階的スコープ再認可を促します。
4. **サイト協力者登録とロール昇格・降格 / 広告表示ルール**:
   * `[koto_collaborate_portal]`（[lib/mcp/portal/mcp-collaboration-page.php](../../lib/mcp/portal/mcp-collaboration-page.php)）でのセルフ登録は、ハニーポット＋IPレート制限（5回/10分）＋24時間有効メール認証リンクを経て最小権限の `subscriber` として作成されます。
   * 管理画面で承認すると `subscriber` → `mcp_user` ロールへ自動昇格し、権限取消・無効化時は `mcp_user` → `subscriber` へ自動降格（`administrator`/`editor` 等は保護）＋トークン即時失効となります。
   * 広告非表示制御（[functions.php](../../functions.php)）は `administrator` / `editor` または「公開済み記事・キャラクター投稿が1件以上あるユーザー」にのみ適用され、新規 `subscriber` や公開実績のない `mcp_user` には通常通り広告が表示されます。
5. **`scope` 省略時の安全フォールバックとクライアント既定値**:
   * OAuthリクエストで `scope` が省略された場合は安全な既定値 `validate preview`（読み取り・検証のみ）、またはクライアント個別設定（`default_scope`）を適用します。
6. **リフレッシュトークン・ローテーションと永続化・安全救済**:
   * **有効期限**: アクセストークン 1時間（3,600秒）、リフレッシュトークン 30日（2,592,000秒）。
   * **direct DB ストレージ ＆ HMAC署名 ＋ SQLアトミック排他ロック ＋ 15秒デバウンス ＋ 非破壊安全ローテーション** により、複数クライアントからの並行実行でも接続切れを起こしません。

---

## 4. WordPress REST API & OpenAPI 3.1.0 仕様

MCPクライアントを介さず、OpenAPI 3.1.0（[documents/mcp/openapi.json](openapi.json)）や REST API（OAuth 2.1 Bearer / Application Passwords Basic）で直接呼び出せるエンドポイント群です（実装: [lib/mcp/protocol/post-mcp.php](../../lib/mcp/protocol/post-mcp.php)）。すべての権限不足レスポンスには `WWW-Authenticate` ヘッダーが付与されます。

### エンドポイント一覧（全8ルート・OpenAPI 7パス）

| メソッド & パス | 必要スコープ | 対応MCPツール | 主な用途 |
|---|---|---|---|
| `GET /wp-json/kotodaman/v1/mcp/openapi.json` | 公開（不要） | — | サーバー実URLを動的注入した OpenAPI 3.1.0 スキーマJSONを返却 |
| `GET /wp-json/kotodaman/v1/mcp/schema` | `validate` | `get_field_schema` | フィールド定義・手動読取ルール・`workflow_guide` を取得 |
| `GET /wp-json/kotodaman/v1/mcp/blessing-schema` | `validate` | `get_blessing_schema` | 祝福・EX祝福スキーマと利用可能トークン一覧を取得 |
| `GET /wp-json/kotodaman/v1/mcp/terms` | `validate` | `get_terms` | タクソノミーターム一覧を取得（`taxonomy`, `search`, `limit`, `offset` 対応） |
| `GET /wp-json/kotodaman/v1/mcp/characters/search` | `validate` | `search_character` | 既存キャラクターを名前で検索（`name`, 雛型自動除外） |
| `GET /wp-json/kotodaman/v1/mcp/draft/{post_id}` | `validate` | `get_draft` | 既存下書きデータを取得 |
| `POST /wp-json/kotodaman/v1/mcp/draft/preview` | `preview` | `preview_draft` | 保存せずバリデーション＆自動入力解析し `preview_hash` と `warnings` を返却 |
| `POST /wp-json/kotodaman/v1/mcp/character-draft` | `create` | `create_draft` 等 | 新規キャラクター下書き（`draft`）を作成（`dry_run: true`, `screenshots` Base64画像添付対応） |
| `PATCH / PUT /wp-json/kotodaman/v1/mcp/draft/{post_id}` | `create` | `update_draft` | 既存の下書き（`draft`）を更新（`preview_hash` 検証＋雛型保護） |
| `DELETE /wp-json/kotodaman/v1/mcp/draft/{post_id}` | `create` | `trash_draft` | 既存の下書き（`draft`）をゴミ箱へ移動（雛型保護） |

* **`screenshots`**（`POST /mcp/character-draft`）: PNG / JPEG / WebP の Base64 Data URI 配列（1枚最大10MB、最大20枚）。途中でエラーが発生した場合は作成途中の添付ファイルと下書き投稿をロールバック削除します。
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
