---
name: auto-input
description: >-
  コトダマンDBのACF自動入力（lib/acf/auto-input/ および lib/ゲーム内文言ーACF-対応表.csv）に
  新規文言パターンを追加・修正・デバッグする手順。自動入力が入らない原因調査、新スキル文言への対応、
  パーサーやヘルパーの改修を行うときに使用する。
---

# ACF自動入力（auto-input）開発・保守手順

新規文言への対応やパース不具合の修正は、影響範囲を最小限に抑えるため**必ず以下の優先順位（Step 1 → Step 2 → Step 3）**で検討・実装すること。

詳細仕様・処理フロー図・回帰テストサンプル集は [AUTO_INPUT_GUIDELINES.md](../../../lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md)、祝福トークン仕様は [blessing-token-guide.md](../../../documents/blessing-token-guide.md) を参照。

---

## 0. 事前確認（対象種別と現状出力の確認）

まず対象となる種別（`わざ` / `とくせい` / `リーダーとくせい` / `すごわざ発動条件` / `コトわざ` / `祝福` / `EX祝福`）を確認し、現状のパース結果をCLIで確認する。

```bash
php lib/acf/test_auto_input.php "<種別>" "<ゲーム内文言>" --test-mode
```

---

## Step 1: 対応表（CSV）のみで対応できるか確認・実装する

最初に [lib/ゲーム内文言ーACF-対応表.csv](../../../lib/ゲーム内文言ーACF-対応表.csv)（カラム: `種別,文言,ACFに入力するJSON`）を確認し、**PHPコードを変更せずCSV行の追加・調整だけで解決できるか**を検討する。

### 確認ポイント
* **既存プレースホルダで表現可能か**:
  * `{$val}`（数値 `[0-9０-９]+`）、`{$attr}`（属性）、`{$species}`（種族）、`{$affiliation}`（所属グループ）、`{$moji}`（文字）
  * `{$at_prefix_...}`（攻撃倍率）、`{$prefix}`（バフ/デバフ段階）、`{$waza_target}`（攻撃対象）、`{$waza_character_target}`（効果対象）
  * `{$gimmick_name*}`（ギミック名）、`{$dot_separated_moji}`（`・` 区切り文字）、`{$dot_camma_val}`
* **CSVテンプレート機能で表現可能か**:
  * **四則演算式**: `"$val*1000"`、`"$val/2"` 等（`koto_resolve_numeric_expressions()` が評価）
  * **複数行展開**: 1文言で複数ACF行を生成する場合は `{"..."}|{"..."}` のパイプ区切りを使用
  * **正規表現許容**: 意図的に `.*?` や `.*` をテンプレート内に記述可能（`koto_generate_regex_pattern()` で復元）
* **注意点**: 前処理（`koto_preprocess_text()`）後の文言と完全一致/前方・後方一致する形になっているか確認する。

CSVのみで安全かつ正確に表現できる場合は、**ここで実装を完了し Step 4（検証）へ進む**。

---

## Step 2: Helper の変数処理・入力事前処理を確認・調整する

CSVのみで対応できない場合、次に [acf-auto-input-helpers.php](../../../lib/acf/auto-input/acf-auto-input-helpers.php) を確認する。

### ① 入力の事前処理（正規化・除外語）
* **`koto_preprocess_text($text, $category)`** / **`koto_get_ignore_texts_by_category($category)`**:
  * 全角半角・記号統一（`%`, `+`, `-`, `|`, `()`, `【】`, `「」`, `・`）、誤記補正。
  * わざカテゴリの句点「。」正規化（`。(?:さらに|また(?:は)?)[、,]?` → `+`、残りの `。` → `\n`）、連撃表記の「の」補完（`〇属性〇回攻撃` → `〇属性の〇回攻撃`）。
  * `、さらに` は効果行分割トークンのため除外文言削除中も一時プレースホルダで保護する。
  * **確認**: 前処理の表記正規化や誤記・ノイズ除去で既存CSVテンプレートに合流できないか。

### ② 正規表現パターン生成の変数制約
* **`koto_generate_regex_pattern($template, &$seen_vars, $match_mode)`**:
  * `val*` は `[0-9０-９]+`、`leader_text*` は `.+?`、`attr*` / `species*` は助詞（`がをにでへ` 等）除外、その他は `[^、。\n×xX%％]+?` でキャプチャされる。
  * **確認**: プレースホルダ命名やキャプチャ禁止文字との衝突でマッチ失敗していないか。

### ③ 変数処理・JSONテンプレート置換
* **`koto_apply_variables_to_json($json_template, $matches, $input_key)`**:
  * プレースホルダ値のタームID変換、マップ変換（`koto_get_attack_map` / `koto_get_buff_prefix_map` 等）、命名規則による特殊加工、リーダー用キー変換（`koto_leader_get_field_map()` / `koto_leader_convert_keys()`）。
  * **確認**: 既存の命名規則変数で代替できないか、または `koto_apply_variables_to_json()` 内の変数変換ルール追加で完結できるか。

Helper の事前処理または変数処理の調整で解決できる場合は、**パーサー本体は変更せず Step 4（検証）へ進む**。

---

## Step 3: Parser の変更・新規 Helper 関数の追加を行う

Step 1（CSV）および Step 2（事前処理・変数処理）でも対応できない**構造的な要件**がある場合にのみ、パーサーの変更や新規ヘルパー関数の追加を行う。

### 対象ファイルと主な担当処理
* **[acf-auto-input-parsers.php](../../../lib/acf/auto-input/acf-auto-input-parsers.php)**:
  * `koto_parse_waza`: シフト判定（`waza_shift_rows`）、丸数字/改行パート分割、複数追加条件ループ（`または`/`もしくは`）、効果分割（`+`/`、さらに`）、不完全行（効果空）除外、属性シフト複製。
  * `koto_parse_trait`: 条件部（前方一致）と効果部（後方一致）の分離、文脈/スコープ変換。
  * `koto_parse_leader_trait`: キャラ数倍率（`per_unit_loop`）と固定値/条件付き（`ls_cond_loop` / `ls_target_chara_loop` / `ls_status_loop`）の構築、直前対象の文脈引き継ぎ。
  * `koto_parse_sugowaza_condition` / `koto_parse_kotowaza`: 発動条件・コトわざのパース。
* **[acf-auto-input-blessing.php](../../../lib/acf/auto-input/acf-auto-input-blessing.php)**:
  * `koto_parse_blessing` / 祝福トークンレジストリ・テンプレート展開（祝福・EX祝福）。
* **[acf-auto-input-wp-acf.php](../../../lib/acf/auto-input/acf-auto-input-wp-acf.php)**:
  * ACF構造自体は変更しない。保存ロジックを触る場合は必ず `koto_get_top_level_character_acf_field()` / `koto_convert_acf_rows_to_field_keys()` による `field_xxxxxx` キー変換とキャッシュクリアを維持する。

---

## Step 4: 検証とドキュメント反映（必須）

変更完了後は以下を必ず実行し、テスト実施と結果を報告する。

1. **PHP構文チェック**（PHPファイルを編集した場合）:
   ```bash
   php -l lib/acf/auto-input/<変更ファイル>.php
   ```
2. **対象文言の単発テスト**:
   ```bash
   php lib/acf/test_auto_input.php "<種別>" "<対象文言>" --test-mode
   ```
3. **全件回帰テスト**:
   ```bash
   php lib/acf/test_auto_input.php
   ```
4. **ドキュメント・回帰テストケースの連動更新**（必要な場合）:
   * 新しい解析ルール・落とし穴を追加した場合は [AUTO_INPUT_GUIDELINES.md](../../../lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md)（および祝福トークン追加時は [blessing-token-guide.md](../../../documents/blessing-token-guide.md)）を更新する。
   * 代表的な新パターンは [lib/acf/test_auto_input.php](../../../lib/acf/test_auto_input.php) の `$tests` 配列にも追加する。
