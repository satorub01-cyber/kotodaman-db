# コトダマンDB 開発・保守ガイドライン（AI向け絶対規範）

本書は、コトダマンDBテーマ（`cocoon-child-master`）で AI および開発者が遵守すべき**最重要ルール（核）**のみをまとめた常時参照ガイドラインです（15KB以内厳守・OKF対象外）。

> [!IMPORTANT]
> **ドキュメント全体の入口とシステム地図**:
> * ドキュメントの入口・OKF更新方針は **[documents/index.md](documents/index.md)** を参照してください。
> * アーキテクチャ・ファイル責務・変更連動チェックリストは **[documents/codebase-map.md](documents/codebase-map.md)**、`_spec_json`・タイポ/祝福キー仕様は **[documents/spec.md](documents/spec.md)** を参照してください。

---

## 0. 基本動作原則（作業スコープと権限制限）

* **作業スコープ厳守**: 依頼内容に含まれない周辺ファイルのリファクタリング、仕様変更、不要な最適化は行わない。他ファイル修正が必要な場合は事前に確認・提案する。
* **確認と提案の徹底**: 既存仕様が不明な箇所や潜在リスクを見つけた場合は、独断で書き換えずユーザーへ報告・確認する。
* **Gitコミット・プッシュ・デプロイの自律実行禁止**: 作業は「コード生成・編集・指定テスト・閲覧」に留める。`git commit`、`git push`、デプロイコマンドの自律実行は禁止（明示指示がある場合を除く）。
* **実装完了時のコミットメッセージ出力**: プラン（`/plan` 等）後の実装を完了した際の返答には、変更内容を表す適切なコミットメッセージ（簡潔な日本語）を必ず出力する。
* **`//要チェック` 明記と引き継ぎ**: 人間側で後から確定・確認が必要な箇所（仕様、ID、選択肢等）には `//要チェック`（HTML/MDは `<!-- //要チェック -->`）を明記し、**該当箇所がある場合のみ**完了時にチェックリスト形式で報告する。

---

## 1. 本番規模とメモリ上限（256MB）対策

本番環境は **キャラクター数千件・メディア画像数万件・PHPメモリ上限 256MB** のため、全件ロードによるメモリ枯渇を常に防止する。

* **`get_posts(['posts_per_page' => -1])` 禁止**: 大量投稿のループ内での `get_post_meta()` / `get_post_thumbnail_id()` はオブジェクトキャッシュ枯渇を招く。絞り込み条件やメタ値は **SQL（`$wpdb->get_results` や `LEFT JOIN`）で1回に抽出**する。
* **メディア画像の一括取得 & `wp_get_attachment_image_url()` ループ禁止**: 必ず **SQL `LIMIT`（例: 60件）＋ Ajax ページネーション**で分割取得する。
* **巨大な `NOT IN (数万ID)` 回避**: `LIMIT` 厳格適用または `NOT EXISTS` / `LEFT JOIN` サブクエリを用いる。

---

## 2. キャラクター・画像・データ構造の重要原則

* **保護対象記事の除外**: タイトルに「**雛型**」「**雛形**」を含む記事、および特定ID（**`3683`** 等、正本: `lib/mcp/auth/mcp-core.php`）は画像設定・MCP操作等の対象外とする。
* **準備中画像とアイキャッチ連動**: 準備中画像設定キャラは未設定扱いとし未紐付け一覧から除外する（正本: `lib/image-setter/image-setter-ajax.php` / `lib/media-functions.php`）。進化後画像（`character_image`）を設定した際は必ず `set_post_thumbnail()` を連動更新する。
* **歴史的タイポ・共通キーのフォールバック必須**（詳細: [documents/spec.md](documents/spec.md)）:
  * 解放場所: `get_palce`（`get_place` フォールバック） / とくせいターン数: `draw_triat_turn`（`draw_trait_turn` / `turn_count` フォールバック）
  * EX祝福キー: `ex_blessing`（`ex-blessing` 許容）、必要ポイントは祝福・EX祝福ともに `need_blessing_point` を共有。
* **新規フィールド・検索タグ追加**: `_spec_json`（`lib/koto-calc.php`）から検索JSON（`koto-json-reformer.php`）・UI・同期フックまで連動させる（手順: [codebase-map.md#3](documents/codebase-map.md#3-定番タスク別変更連動チェックリストai最速変更手順)）。

---

## 3. ACFフィールドの運用・保守ポリシー

* **ACF構造のAI変更禁止**: `acf-json` や DB の `acf-field` 構造はユーザーがUIで管理するため、AIが勝手に変更しない。PHP側で柔軟に対応する。
* **プログラムからの `update_field()` 鉄則**:
  * ACF PRO の同名サブフィールド競合を防ぐため、フィールド名ではなく **フィールドキー（`field_xxxxxx`）** で操作する（`koto_get_top_level_character_acf_field()` / `koto_convert_acf_rows_to_field_keys()` を通す）。
  * 既存行追記時は未フォーマット取得 `get_field($field_key, $post_id, false)` を使い、前後に `clean_post_cache($post_id)` / `acf_flush_value_cache($post_id)` を実行する。
  * 専用エディタ（`acf-editor.js`）の `beforeunload` 自動保存との競合を防ぐため、Ajax自動入力や遷移時は `window.kotoSkipBeforeUnloadSave = true` を設定する。

---

## 4. UI & マークアップの基本原則

* **サーバーサイド Ajax の徹底**: 大量データ一覧はサーバー側 Ajax（`LIMIT 60`）で分割処理する。
* **インラインスタイルの禁止**: PHP/HTML に `style="..."` や `<style>` を直書きせず、専用CSSファイルへ分離する。
* **サイト基本色**: ベース背景 `#f7ede3`、アクセント `#135e96`。
* **階層チェックボックス（Tri-State）**: 親操作時は配下を全選択し、親状態の再計算は DOM 逆順（ボトムアップ）で行う。

---

## 5. テスト・検証手法

* **テスト報告義務**: テストを実行した際は、回答内に必ず**「テストしました」**（実施内容・コマンド・結果）を明記する。
* **PHP構文チェック**: PHPファイル変更後は必ず `php -l {ファイルパス}` を実行する。
* **ACF自動入力テスト**: **`auto_input` 関連（`lib/acf/auto-input/` や対応表CSV）の変更時のみ**、`php lib/acf/test_auto_input.php`（単発または全件回帰）を実行する。
* **ドキュメント検証**: ドキュメント追加・更新時は `python documents/check-okf.py` で OKF 準拠・リンク切れ・本ファイル15KB制限を確認する。

---

## 6. AIエージェントのドキュメント自律更新ルール

> [!IMPORTANT]
> コード編集は依頼スコープに厳格制限されますが、**ドキュメント群の自律更新・知見蓄積は推奨**されています（運用ルール詳細は [documents/index.md#4](documents/index.md#4-ドキュメント整理更新方針ai自律更新原則)）。

* **15KB以内厳守（肥大化防止）**: 本ファイル（`AGENTS.md`）には核の規範のみを記述し、個別の仕様・一覧・手順は必ず適切な専門ドキュメント（`documents/index.md` 記載の各ファイル）へ記録する。
* **OKF v0.2 準拠**: 新規ドキュメント作成時は `.agents/`・`old-*.md` を除き OKF frontmatter を付与し、[documents/index.md](documents/index.md)（および MCP 系は `documents/mcp/index.md`）を更新する。無効になった旧資料は `old-○○.md` へ改名する。
* **完了報告**: ドキュメントを更新した際は、完了報告に更新したファイル名を明記する。
