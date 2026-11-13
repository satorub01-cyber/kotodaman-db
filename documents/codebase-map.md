# コトダマンDB コードマップ＆システムアーキテクチャ（AI時短リファレンス）

本書は、コトダマンDB（`cocoon-child-master`）の全体アーキテクチャ、データフロー、ファイル責務、および機能追加時の「変更連動チェックリスト」を網羅したリファレンスマニュアルです。
AIエージェントおよび開発者が、手探りのファイル探索（grep / find の繰り返し）を行わず、最小の手数で目的のコードへ到達・編集できるようにすることを目的としています。

---

## 1. システムアーキテクチャ＆データフロー

コトダマンDBは、**ACF/MCP等によるデータ入力** → **`koto-calc.php` による構造化（`_spec_json` 生成）** → **検索用JSON平坦化 / 詳細画面描画** というパイプラインで構築されています。

### ① 全体データフロー図

```mermaid
flowchart TD
    subgraph DataInput ["1. データ登録・編集レイヤー"]
        ACF_UI["ACF通常編集画面<br/>(wp-admin)"]
        ACF_Custom["DBエディタ画面<br/>(lib/acf/acf-editor.php)"]
        AutoInput["文言自動入力 Ajax<br/>(lib/acf/auto-input/)"]
        MCP_Server["MCPサーバー (AIスクショ入力)<br/>(lib/mcp/)"]
    end

    subgraph DataProcessing ["2. 計算・構造化レイヤー (核)"]
        SaveHook["acf/save_post フック<br/>(on_save_character_specs)"]
        KotoCalc["lib/koto-calc.php<br/>get_character_spec_data()"]
        DamageCalc["lib/koto-damage-rate-calc.php<br/>火力指数計算"]
        SpecJSON[("ポストメタ: _spec_json<br/>(キャラクター全スペックJSON)")]
    end

    subgraph SearchLayer ["3. 検索インデックス・エンジンレイヤー"]
        JsonReformer["lib/character-search/koto-json-reformer.php<br/>koto_format_character_search_row()"]
        SearchJSON[("all_characters_search.json<br/>(平坦化された検索用キャッシュ)")]
        SearchEngine["lib/character-search/search-engine.js<br/>(クライアントサイド高速検索エンジン)"]
        SearchForm["lib/character-search/searchform-content.php<br/>(階層型検索モーダルUI)"]
    end

    subgraph DisplayLayer ["4. フロント表示レイヤー"]
        SingleChara["single-character.php<br/>(キャラ詳細個別ページ)"]
        KotoDisplay["lib/koto-display.php<br/>(カード・すごわざ・祝福HTML生成)"]
        ModalDisplay["lib/koto-modal-displayer.php<br/>(協力者募集モーダル)"]
        DBTop["page-db-top.php<br/>(DBトップページ)"]
    end

    %% データ入力から保存へ
    ACF_UI --> SaveHook
    ACF_Custom --> SaveHook
    AutoInput --> ACF_Custom
    MCP_Server --> ACF_Custom
    MCP_Server --> SaveHook

    %% 保存処理と構造化
    SaveHook --> KotoCalc
    KotoCalc --> DamageCalc
    DamageCalc --> KotoCalc
    KotoCalc -->|保存| SpecJSON

    %% 検索用JSON生成
    SpecJSON --> JsonReformer
    JsonReformer -->|出力・更新| SearchJSON
    SearchJSON --> SearchEngine
    SearchForm --> SearchEngine

    %% 詳細表示
    SpecJSON --> SingleChara
    SpecJSON --> KotoDisplay
    KotoDisplay --> SingleChara
```

---

## 2. ディレクトリ＆ファイル責務マップ

### ① 計算・データ構造化（コアロジック）
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `lib/koto-calc.php` | **最重要ファイル**。`get_character_spec_data($post_id)` によりACFの全データを読み込み、わざ・すごわざ・リーダー・とくせい・祝福・EX祝福・火力指数を解析して構造化配列を返却。`on_save_character_specs()` で `_spec_json` に保存。 | 新規ステータスやタグ追加時は必ずここを修正 |
| `lib/koto-damage-rate-calc.php` | わざ・すごわざの火力指数（ATK倍率・キラー・バフデバフ）を算出する計算エンジン。 | `koto-calc.php` から呼び出し |
| `lib/koto-variables.php` | 属性、種族、状態異常、とくせい検索タグの日本語名マッピング（`koto_get_trait_search_label_map()`）等の定数定義。 | 検索タグ新設時にラベルを追記 |
| `lib/koto-helpers.php` | 全域で使われる共通ユーティリティ（文字列処理、配列整形等）。 | |

### ② キャラクター検索モジュール（`lib/character-search/`）
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `koto-json-reformer.php` | `_spec_json` から検索用JSON（`all_characters_search.json`）の行データ（`waza_t`, `sugo_t`, `trait1_en`/`jp`, `blessing_en`/`jp` 等）へ平坦化・正規化する関数群。 | 検索タグ正規化 `koto_normalize_trait_search_slug()` を含む |
| `search-engine.js` | クライアントサイド検索エンジン。タグ合致判定、アクティブ条件判定、テーブル行HTML生成（`createCharacterRowHtml`）、動的列表示制御（`updateDynamicColumns`）。 | テーブルに列を追加する際はここを修正 |
| `chara-list-functions.php` | 検索結果テーブルの列定義（`koto_get_column_config()`）や、表示項目トグルチェックボックスの生成。 | 新規列追加時はここへ設定追加 |
| `searchform-content.php` | 検索モーダルのHTMLマークアップ（属性、種族、状態異常、階層型とくせいチェックボックス等）。 | チェックボックス追加時はここ |
| `searchform.js` / `.css` | 検索モーダルのUI制御（階層チェックボックスのTri-State連動、タブ切り替え、開閉アニメーション）。 | |
| `koto-search.php` | 検索関連の初期化・スクリプト登録・Ajaxエンドポイント。 | |
| `koto-taxonomy-sync.php` | タクソノミーターム変更時に `_spec_json` 内の該当キーを連動更新するフック。 | |

### ③ ACF自動入力モジュール（`lib/acf/auto-input/`）
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `acf-auto-input-ajax.php` | 自動入力のAjaxエンドポイント（`wp_ajax_koto_auto_input_parse`）。 | 入力受取・レスポンス返却 |
| `acf-auto-input-blessing.php` | 祝福・EX祝福のゲーム内文言を解析し、文字追加・すごわざ条件追加・とくせい行を構築。 | `AUTO_INPUT_GUIDELINES.md` 参照 |
| `acf-auto-input-blessing.php` | 祝福・EX祝福のゲーム内文言を解析し、文字追加・すごわざ条件追加・とくせい行を構築。 | `documents/blessing-token-guide.md` 参照 |
| `acf-auto-input-parsers.php` | 通常わざ・すごわざ・リーダー・通常とくせいの文言パースエンジン。 | 句点分割・丸数字分割・正規表現 |
| `acf-auto-input-helpers.php` | CSV対応表のロード、文言正規化（全角半角、濁点等）、類似度判定。 | |
| `acf-auto-input-wp-acf.php` | 解析結果の配列を ACF PRO の `field_xxxxxx` キー形式に変換し、DB保存・下書き保存を実行。 | サブフィールド競合防止の要 |
| `AUTO_INPUT_GUIDELINES.md` | 自動入力の文言解析仕様、シフトタイプ、CSVマッピング記法マニュアル。 | ルール変更時に必読 |
| `documents/blessing-token-guide.md` | 祝福トークン定義、レアリティ値、所属連動、逆抽出CLIマニュアル。 | トークン追加時に必読 |
| `lib/ゲーム内文言ーACF-対応表.csv` | ゲーム内文言とACFフィールド値のマッピングマスターデータ。 | |

### ④ 専用DBエディタ（`lib/acf/`）
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `acf-editor.php` | コトダマンDB専用のACF編集画面テンプレート。文字入力欄、わざ・すごわざタブ、とくせい・祝福リピーターUIを描画。 | |
| `acf-editor.js` / `.css` | 専用エディタのDOM制御、ショートカット（並べ替え/行追加）、`beforeunload` 自動保存。 | |
| `acf-auto-input.js` | 専用エディタ上の「自動入力」ボタン、文言貼り付けモーダル、Ajax送信・フォーム反映。 | |

### ⑤ MCPサーバー（`lib/mcp/`）
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `loader.php` | MCP全モジュール統一ローダー（`functions.php` から読み込み）。 | `auth/` → `protocol/` → `portal/` |
| `auth/mcp-core.php`, `mcp-auth.php`, `mcp-oauth.php` | コア基盤、Bearer/App Password認証、OAuth 2.1（CIMD / DCR / Static Confidential Client / RFC 8707 `aud` / SEP-835）。 | |
| `protocol/server.php` | Model Context Protocol（MCP）JSON-RPC 2.0 サーバー（`2025-11-25` ネゴシエーション、バッチ、CORS/Origin、エラー変換）。 | 全10ツール配信 |
| `protocol/mcp-tools.php` | MCPツールのディスパッチ（`preview_draft`, `create_draft`, `update_draft`, `validate`, `search_character`, `get_blessing_schema` 等・Annotations・`get_terms` ページング）。 | |
| `protocol/mcp-schema.php` | ACFフィールドスキーマ動的生成、祝福・EX祝福スキーマ＆トークン辞書生成（`koto_mcp_blessing_schema`）。 | |
| `protocol/mcp-draft.php` | 下書き（Draft）の生成・更新・バリデーションロジック、雛型記事保護チェック。 | |
| `protocol/mcp-resources.php` | MCPクライアント向けドキュメント・スキーマ（`kotodaman://guide/...`）の配信。 | |
| `protocol/post-mcp.php` | REST API 全8ルート（`/mcp/openapi.json`, `/mcp/schema`, `/mcp/terms`, `/mcp/characters/search`, `/mcp/draft/preview`, `/mcp/character-draft`, `/mcp/draft/{id}`）。 | |
| `portal/mcp-admin.php` | 管理画面「ユーザー > MCP利用申請」（承認時 `subscriber`→`mcp_user` 昇格・取消時降格、Static Client発行管理）。 | |
| `portal/mcp-collaboration-page.php` | 協力者ポータル共通描画（`collaboration-page.php` テンプレートおよび `[koto_collaborate_portal]` ショートコード）、メール認証付き登録、AI別ガイドJSON（`.css` / `.js`）。 | **一時非公開中**: `collaboration-page.php`（404応答化）およびショートコード・登録受付フック・導線リンクをコメントアウト中（処理本体は残置）。詳細は [documents/mcp/README.md](mcp/README.md) 参照 |
| `tools/prepare_screenshots.php` | スクショ画像の一括トリミング・タイル結合スクリプト（`contact_sheet.jpg` 生成）。 | |
| `tests/test-mcp-*.php` | 権限・OAuthリフレッシュ・互換拡張テストスクリプト群。 | |

### ⑥ フロント表示レイヤー
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `single-character.php` | キャラクター個別詳細ページ。基本情報、わざ・すごわざ・とくせい・祝福カードのレイアウト。 | |
| `lib/koto-display.php` | `_spec_json` を元にすごわざHTML（`get_koto_sugowaza_html`）や祝福カードHTML（`get_koto_blessing_card_html`）を生成。 | 表示系マークアップの集約先 |
| `lib/koto-modal-displayer.php` | 未ログイン閲覧者向けに情報提供・作業協力のお願い（未入力一覧・Discord参加）モーダルをフッター（`wp_footer`）に出力。 | 協力者募集専用モーダル |
| `style-character-detail.css` | キャラクター詳細ページの専用スタイルシート（インラインスタイル禁止）。 | |
| `style-character-search.css` | キャラクター検索画面の専用スタイルシート。 | |

### ⑦ 補助・管理系ツール（`lib/`）
| ファイルパス | 責務・主要関数 | 備考 |
| :--- | :--- | :--- |
| `lib/image-setter/` | キャラクター画像紐付けツール（進化後、進化前、絵違い、アイキャッチ）。Ajaxページネーション `LIMIT 60`。 | |
| `lib/term-setter/` | タクソノミー（所属、グループ等）一括設定ツール。 | |
| `lib/missing-info-characters.php` | 画像や情報が未設定のキャラクターを抽出・一覧表示する管理機能。 | |
| `lib/media-functions.php` | 画像アタッチメントの未紐付け判定、アイキャッチ更新補助。 | |

---

## 3. 定番タスク別「変更連動チェックリスト」（AI最速変更手順）

AIが特定の機能変更や追加を指示された際、手探りせず以下の順序でファイルを編集してください。

### 【タスクA】キャラクターに新規フィールド（ステータス・タクソノミー等）を追加する場合
連動するファイルは **6箇所** です：

```mermaid
flowchart LR
    F1["1. single-character.php<br/>(詳細画面マークアップ)"] --> F2["2. lib/koto-calc.php<br/>(_spec_json生成にキー追加)"]
    F2 --> F3["3. koto-json-reformer.php<br/>(検索JSON行に項目追加)"]
    F3 --> F4["4. chara-list-functions.php<br/>(列定義 config に追加)"]
    F4 --> F5["5. search-engine.js<br/>(filter判定・td描画・動的列)"]
    F5 --> F6["6. koto-taxonomy-sync.php<br/>(ターム変更連動※タクソノミー時)"]
```

1. **詳細画面（`single-character.php`）**:
   - 既存の項目（例: 「実装イベント」）のマークアップ（`<dl class="spec-row"><dt>...</dt><dd>...</dd></dl>`）を踏襲。未設定時は `if (!empty($val)):` で非表示。
2. **スペック生成（`lib/koto-calc.php`）**:
   - `get_character_spec_data($post_id)` の `$data` 配列に初期値キーを追加。
   - ACFから値を取得して格納（ハイフン/アンダースコア両対応）。
3. **検索用JSON整形（`lib/character-search/koto-json-reformer.php`）**:
   - `koto_format_character_search_row($spec)` で `$spec` から取り出し、行配列へ追加。
4. **検索テーブル列定義（`lib/character-search/chara-list-functions.php`）**:
   - `koto_get_column_config()` に列定義を追加（`label`, `class`, `related_filters => ['tx_xxx', 'tx_xxx[]']`, `show => false`）。
5. **検索エンジン＆フィルター（`lib/character-search/search-engine.js`）**:
   - `filter` 判定処理で対象プロパティを評価。
   - `createCharacterRowHtml` の `<td>` に出力（列順序を config と厳密一致）。
   - `updateDynamicColumns()` による自動列表示連動を確認。
6. **タクソノミー同期（`lib/character-search/koto-taxonomy-sync.php`）**:
   - タクソノミーの場合、スラッグ変更時に `_spec_json` も同期更新されるよう配列に追加。

---

### 【タスクB】とくせい・スキルの検索項目（新規タグ・数値分け）を追加する場合
連動するファイルは **5箇所** です：

1. **仕様ドキュメント（`documents/spec.md`）**:
   - 正規化タグ一覧（canonical slug）に新設タグ（プレフィックス抜きのスラッグ。例: `other_combo_plus_1`）を追記。
2. **スペック生成・タグ抽出（`lib/koto-calc.php`）**:
   - とくせい解析ループでサブタイプや数値を判定し、`$target_tags` にタグ（例: `'trait_' . $t_type . '_combo_plus_1'`）を追加。
3. **検索用JSON正規化（`lib/character-search/koto-json-reformer.php`）**:
   - `koto_normalize_trait_search_slug($trait)` で同様に数値を判定し、正規化スラッグ（例: `$canonical_type . '_combo_plus_1'`）を `$slugs[]` に追加。
4. **日本語ラベル定義（`lib/koto-variables.php`）**:
   - `koto_get_trait_search_label_map()` にスラッグと日本語表示名（例: `'other_combo_plus_1' => 'コンボ＋1'`）を追記。
5. **検索フォームUI（`lib/character-search/searchform-content.php`）**:
   - 該当モーダル内の `.tag-children` にチェックボックス（例: `<input type="checkbox" name="tx_trait_tags[]" value="trait_other_combo_plus_1"> コンボ＋1`）を配置。親要素のTri-Stateに自動連動。

---

### 【タスクC】検索結果一覧テーブルに新規列・ソート項目を追加／表示制御する場合
連動するファイルは **3箇所** です：

1. **検索テーブル列定義（`lib/character-search/chara-list-functions.php`）**:
   - `koto_get_column_config()` に列定義を追加（`label`, `class`, `related_filters => ['tx_xxx', ...]`, `show => false` 等）。
   - 必要に応じて表示項目切り替え用チェックボックスの初期状態を設定。
2. **検索エンジン＆テーブル描画（`lib/character-search/search-engine.js`）**:
   - `createCharacterRowHtml` の `<td>` に出力（列の出力順序を `koto_get_column_config()` の定義順と厳密一致させる）。
   - ソート対象にする場合は `sortData()` の比較ロジックに対象キーを追加。
   - `updateDynamicColumns()` による自動列表示連動を確認。
3. **スタイルシート（`style-character-search.css`）**:
   - 追加した列の幅（`width` / `min-width`）、文字揃え（`text-align`）、レスポンシブ時の折り返しをスタイル定義。

---

### 【タスクD】キャラクター詳細ページに新規UIコンポーネント・カードを追加・改修する場合
連動するファイルは **4箇所** です：

1. **スペック生成・データパース（`lib/koto-calc.php`）**:
   - ACFから値を取得し、構造化して `_spec_json` に含める（必要に応じて）。
2. **HTML生成コンポーネント（`lib/koto-display.php`）**:
   - 表示用マークアップを生成するヘルパー関数（例: `get_koto_xxx_html()`）を実装。
3. **詳細ページテンプレート（`single-character.php`）**:
   - 適切なセクション（基本情報、わざ・すごわざ、とくせい、祝福等）に配置。未設定時の `if (!empty($val)):` ガードを徹底。
4. **専用スタイルシート（`style-character-detail.css`）**:
   - カードや要素のスタイルを定義（**インラインスタイル `style="..."` や `<style>` の直接記述は禁止**）。

---

### 【タスクE】火力指数の計算ロジック（新キラー・倍率・バフ）を修正・拡張する場合
連動するファイルは **4箇所** です：

1. **火力計算エンジン（`lib/koto-damage-rate-calc.php`）**:
   - `calculate_character_firepower()` やキラー倍率・バフ計算ロジックに新しい係数や判定を追加。
2. **スペック生成・データ渡し（`lib/koto-calc.php`）**:
   - わざ・すごわざの解析結果から火力計算に必要なパラメータ（属性、キラー、ATK倍率等）を抽出し、計算エンジンに渡す。
   - 結果を `_spec_json` の `"firepower"` キーに格納。
3. **検索用JSON反映（`lib/character-search/koto-json-reformer.php`）**:
   - 検索テーブルで火力指数を表示・ソートする場合は、行データに火力値を平坦化して格納。
4. **詳細画面表示（`lib/koto-display.php` または `single-character.php`）**:
   - 火力指数バッジや詳細内訳の表示を更新。

---

### 【タスクF】管理・補助系ツール（画像紐付け・ターム一括設定等）を改修・新設する場合
連動するファイル・注意点：

1. **Ajaxエンドポイント（`lib/image-setter/image-setter-ajax.php` 等）**:
   - **PHPメモリ上限（256MB）枯渇対策**: `get_posts(['posts_per_page' => -1])` による全件取得は厳禁。SQLの `LIMIT 60` を設けた分割取得と、Ajaxによる追加読み込み（ページネーション）を徹底。
2. **メディア・メタ連動（`lib/media-functions.php`）**:
   - 進化後画像設定時は `_thumbnail_id`（アイキャッチ）も連動更新する。
3. **UI / CSS**:
   - サイト基本色（背景 `#f7ede3`、アクセント `#135e96`）に準拠し、インラインスタイルを避けて専用CSSに記述。

> [!NOTE]
> 文言自動入力（auto-input）の追加・改修は [lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md](lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md)、祝福トークン仕様は [documents/blessing-token-guide.md](documents/blessing-token-guide.md)、MCP下書き運用の拡張は [documents/mcp/character-draft-guide.md](documents/mcp/character-draft-guide.md) を参照してください。

---

## 4. 主要データ構造・キー・歴史的タイポ早見表

### ① `_spec_json` の主要キー構造
```json
{
  "post_id": 1234,
  "name": "キャラクター名",
  "name_ruby": "キャラクターめい",
  "is_break_fuku": false,
  "chars": [
    {
      "char": "あ",
      "row_index": 1,
      "moji_group_cond": false,
      "need_point": 0
    }
  ],
  "waza": { "variations": { "timelines": [ ... ] } },
  "sugowaza": { "variations": { "timelines": [ ... ] } },
  "kotowaza": { ... },
  "first_trait": [ ... ],
  "second_trait": [ ... ],
  "blessing": [ ... ],
  "ex_blessing": [ ... ],
  "leader": {
    "conditions": [
      [ {"target": "attribute", "val": "fire"} ]
    ],
    "effects": [ ... ]
  },
  "search_tags": [ "trait_...", "sugo_..." ],
  "firepower": { ... }
}
```

### ② 歴史的タイポと必須フォールバック
ACFフィールド定義の歴史的経緯により、以下のキー名タイポが存在します。コード上では必ずフォールバックを取得してください。

| 概念 | ACF上のフィールドキー名 | 推奨フォールバック取得コード |
| :--- | :--- | :--- |
| **解放場所** | **`get_palce`**（placeのタイポ） | `$place = $row['get_palce'] ?? ($row['get_place'] ?? 'default');` |
| **とくせいターン数** | **`draw_triat_turn`**（traitのタイポ） | `$turn = $row['draw_triat_turn'] ?? ($row['draw_trait_turn'] ?? ($row['turn_count'] ?? ''));` |

### ③ 祝福・EX祝福のキー名規則
| 区分 | 解放場所キー名 (`get_place`) | とくせいリピーター名 | 必要ポイントフィールド名 |
| :--- | :--- | :--- | :--- |
| **通常祝福** | `blessing` | `blessing_trait_loop` | `need_blessing_point` |
| **EX祝福** | `ex_blessing`（※ハイフン `ex-blessing` も許容） | `blessing_ex_trait_loop` | `need_blessing_point`（**共通**） |

> [!NOTE]
> 祝福トークンの構造・定義一覧やテンプレート連携仕様は [documents/blessing-token-guide.md](documents/blessing-token-guide.md) を参照してください。

---

## 5. 開発・デバッグ・時短用クイック検索コマンド集

AIが手探り探索を避け、最小ターンでコードを特定するためのコマンド集です。

### ① 関数の定義場所を1発で特定
```bash
grep -rn "function 関数名" lib/
```

### ② ACFフィールドキー（`field_xxxxxx`）の定義元JSONを検索
```bash
grep -rn "フィールド名" acf-json/
```

### ③ 特定の検索タグが使われている箇所を横断検索
```bash
grep -rn "canonical_slug" lib/character-search/ documents/spec.md
```

### ④ 構文チェック＆自動入力テスト
```bash
# PHPシンタックスチェック
php -l 対象ファイル.php

# ACF自動入力テスト（※auto_input関連の更新があるときのみ実行）
# 単発テスト
php lib/acf/test_auto_input.php "種別(とくせい等)" "ゲーム内文言"

# 全件回帰テスト
php lib/acf/test_auto_input.php
```

### ⑤ Xdebug接続トラブル時のクイック確認
```bash
# VS Codeのリッスンポート確認（コンテナ内から）
php -r '$fp = @fsockopen("127.0.0.1", 9003); var_dump($fp);'

# Xdebugログ確認
tail -n 30 /tmp/xdebug.log
```

---

## 6. ドキュメント整理方針（役割分担と配置ルール）

テーマ内の各種ドキュメントは、**「常時読み込まれる `AGENTS.md` の肥大化を防ぎ、詳細な知見・仕様・手順は適切な個別 md へ記録する」** 原則に基づいて整理・保守します。

### ① ドキュメント別の責務と配置先一覧

| ドキュメント | 役割・責務 | 主な記録内容 |
| :--- | :--- | :--- |
| **`AGENTS.md`** | **絶対規範・基本原則（核のみ）**<br>※常時参照・15KB以内厳守 | 作業スコープ厳守、Gitコミット自律実行禁止、256MBメモリ枯渇対策クエリ原則、ACF操作の鉄則（`field_xxxxxx` キー更新）、テスト報告義務など、**違反すると即座に障害・クラッシュ・手戻りにつながる最重要ルール**のみ。 |
| **`documents/codebase-map.md`**（本書） | **システム地図・アーキテクチャ・運用索引** | 全体データフロー図、ディレクトリ・ファイル責務マップ、機能追加時の変更連動チェックリスト、クイック逆引きコマンド集、**本ドキュメント整理方針**。 |
| **`documents/mcp/character-draft-guide.md`** | **MCP・AIキャラクター下書き作成運用** | スクリーンショット合成・1枚読み取り手順、`basic_fields`/`auto_input` 入力スキーマ、**祝福の設定優先度（テンプレ ➔ トークン ➔ 並べ替え ➔ 自由入力）**、バリデーションとエラー復旧手順。 |
| **`documents/mcp/kotowaza-guide.md`** | **MCP コトワザ自動入力リファレンス** | `auto_input_kotowaza`（0凸〜4凸 `[{condition, effect}]` 配列）の入力仕様、0凸連番・飛び番禁止ルール、条件/効果文言、`koto_magnification_estimate_tf` の扱い、JSON実例。 |
| **`documents/blessing-token-guide.md`** | **祝福トークン開発・仕様リファレンス** | `koto_get_blessing_token_registry()` のトークン構造定義、**ゲーム内テキスト準拠の `label` 仕様**、新規トークン追加手順、全25種類のギミック耐性（通常・スーパー化）トークン一覧。 |
| **`lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md`** | **文言自動入力アルゴリズム・パース規則** | ゲーム内文言自動入力（auto-input）の分割規則、パーサー処理フロー、`lib/ゲーム内文言ーACF-対応表.csv` のカラム仕様、回帰テスト手順。 |
| **`documents/spec.md`** | **スペックデータ構造・検索タグ定義** | ポストメタ `_spec_json` の完全スキーマ、検索タグ（`trait_...`, `sugo_...`）の命名規則、火力指数パラメータ定義。 |
| **`documents/local-dev.md`** | **開発環境・環境固有設定** | DevContainer / Docker環境のセットアップ、Xdebug等のデバッグ環境設定。 |

### ② 知見・仕様を記録・更新する際の判断フロー（AI自律更新原則）

新機能の追加、仕様変更、歴史的経緯、落とし穴などが判明した際は、以下の基準に従って適切なドキュメントへ記録してください。

1. **個別機能の詳細仕様・対応表・操作手順**:
   * **`AGENTS.md` に直接書いてはいけません**。必ず該当する専門ドキュメント（`documents/mcp/`、`documents/blessing-token-guide.md`、`AUTO_INPUT_GUIDELINES.md` 等）に詳細を記録してください。
2. **システム全体の構造・ファイル連動・検索コマンド**:
   * 本書（`documents/codebase-map.md`）の該当セクションへ追記・更新してください。
3. **`AGENTS.md` へ追記できる例外（核のルールのみ）**:
   * 「全開発・全タスクに横断して影響し、守らないとメモリ枯渇やデータ破壊を引き起こす絶対規範」のみに限定します。
   * 個別仕様の詳細や一覧表は含めず、該当する専門ドキュメントへのリンク（1〜2行）を添えるだけに留めてください（15KB以内のファイルサイズを厳守）。
4. **新しい領域・機能カテゴリを新設する場合**:
   * 必要に応じて `documents/` 配下に新しい Markdown ファイル（例: `documents/xxx-guide.md`）を作成し、本書（`codebase-map.md`）の責務一覧テーブルに登録してください。
