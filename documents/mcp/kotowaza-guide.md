# コトワザ自動入力（`auto_input_kotowaza`）MCP クライアントガイド

本書は、MCP クライアント（Claude / ChatGPT / Cursor / Gemini 等）および REST API（`POST /wp-json/kotodaman/v1/mcp/character-draft`）からキャラクターの **コトワザ（0凸〜4凸）** を登録・更新するための詳細仕様と実戦例をまとめたガイドです。

> [!TIP]
> 本ガイドは MCP Resource **`kotodaman://guide/kotowaza`** としても配信されています。全体フロー・`basic_fields` の共通ルールは [character-draft-guide.md](character-draft-guide.md)（`kotodaman://guide/character-draft`）を合わせて参照してください。

---

## 1. 基本方針（`auto_input` の優先）

* コトワザを登録する際は、内部 ACF フィールド（`kotowaza_loop_v2`, `koto_shift_type`, `kotowaza_maltiplier_table_group`）を直接組み立てず、必ず **`auto_input.auto_input_kotowaza`** にゲーム内説明文を渡してください。
* サーバー側の自動入力パーサー（`koto_parse_kotowaza()`）が、`condition`（発動条件）と `effect`（効果）を既存の CSV 辞書（`lib/ゲーム内文言ーACF-対応表.csv`）および条件/わざパーサーで ACF 構造へ自動変換します。

---

## 2. 入力フォーマット仕様（0凸〜4凸）

### ① 推奨フォーマット（正規配列）

`auto_input.auto_input_kotowaza` には、**`condition` と `effect` を持つオブジェクトの配列** を `0凸` 〜 `4凸` の順で指定します（**最大 5 件**）。

```json
{
  "auto_input": {
    "auto_input_kotowaza": [
      {
        "condition": "発動条件テキスト（0凸 / 未覚醒）",
        "effect": "効果テキスト（0凸 / 未覚醒）"
      },
      {
        "condition": "発動条件テキスト（1凸）",
        "effect": "効果テキスト（1凸）"
      },
      {
        "condition": "発動条件テキスト（2凸）",
        "effect": "効果テキスト（2凸）"
      },
      {
        "condition": "発動条件テキスト（3凸）",
        "effect": "効果テキスト（3凸）"
      },
      {
        "condition": "発動条件テキスト（4凸 / 完凸）",
        "effect": "効果テキスト（4凸 / 完凸）"
      }
    ]
  }
}
```

### ② 互換許容フォーマット（サーバー側で正規化）

MCP クライアントや Actions のスキーマ制約により純配列を渡しにくい場合に備え、以下の形式もサーバー側（`koto_normalize_kotowaza_input()`）で自動的に正規配列へ正規化して受理します。

* **オブジェクトラッパー**: `{"levels": [ ... ]}` または `{"kotowaza": [ ... ]}`
* **インデックスマップ**: `{"0": {"condition": "...", "effect": "..."}, "1": {...}}` または `{"0凸": {...}, "1凸": {...}}`
* **単一オブジェクト（0凸 1件のみの場合）**: `{"condition": "...", "effect": "..."}`
* **JSON 文字列**: 上記いずれかをシリアライズした文字列（例: `"[{\"condition\":\"...\",\"effect\":\"...\"}]"`）

### ③ 件数制限と「飛び番禁止・0凸連番」ルール（重要）

> [!IMPORTANT]
> **必ず `0凸` から途切れなく連番で入力してください。**
> * 現行パーサーは `condition`・`effect` が**ともに空**の要素をスキップして詰める仕様であり、表示・計算側は保存された配列のインデックス `0〜4` をそのまま `0凸〜4凸` として参照します。
> * したがって、`0凸` と `4凸` だけを指定して中間の `1凸〜3凸` を空要素にする（飛び番にする）と、2番目の要素が `1凸` として保存・表示されてしまいます。
> * 0凸〜4凸のすべてが判明していない場合や途中の凸が抜けて読み取れない場合は、勝手に順番を詰めずユーザーへ確認してください。

* **最大件数**: **5 件（`0凸〜4凸`）** までです。6 件以上を渡すと `INVALID_PARAMS` または `VALIDATION_FAILED`（`items_limit_exceeded`）エラーになります。
* **文字数上限**: `auto_input_kotowaza` 全体（配列を JSON 化した文字列長含む）で **4000 文字以内**。

---

## 3. `condition`（発動条件）の記述ルール

`condition` は内部的に `すごわざ発動条件` パーサー（`koto_parse_sugowaza_condition()`）で解析されます。

1. **原則はスクリーンショットの文言をそのまま忠実に転記する**
   * 全角括弧や句読点、記号揺れは前処理で自動正規化されます。
2. **OR 条件（「いずれかを満たすとき」）は丸数字 `①` `②` で区切る**
   * 例: `"①「い,う」を含む4文字以上のことば②10コンボ以上"`
3. **AND 条件（「すべてを満たすとき」）は1つのパート内に続けて書く**
   * 例: `"「か」からはじまる4文字以上のことば"`
4. **短縮表記への補足**
   * 既存エディタ互換の短縮記号（`m`=文字, `c`=コンボ, `t`=のことば, `s`=からはじまる, `e`=でおわる, `i`=を含む）もパーサー上は解釈可能ですが、MCP クライアントでは誤変換を避けるため画面記載の日本語をそのまま渡すことを推奨します。

---

## 4. `effect`（効果）の記述ルール

`effect` は内部的に `わざ` パーサー（`koto_parse_waza()`）で解析されます。

1. **複数行の効果は丸数字 `①` `②` で区切る**
   * `①` `②` ごとに `kotowaza_group_loop` の親行が作成されます。
   * 同一親行内で効果を連結したい場合は `+`、`、さらに`、`。さらに` を使用します（例: `"敵単体に超絶強力な火属性攻撃+自身のATKを2段階UP"`）。
2. **条件付き効果（追加効果条件）**
   * 文頭に条件が付く文言（例: `"火属性を4体以上編成時、..."` 等、CSV の `わざ条件` に一致する接頭辞）も自動で条件付き効果として解析されます。
3. **シフト指定（`koto_shift_type`）**
   * 効果の先頭にシフト指定文言が含まれる場合、投稿の **`koto_shift_type`** が自動設定されます。
4. **倍率テーブル（`kotowaza_maltiplier_table_group`）**
   * 現行仕様上、0凸〜4凸のいずれかの `effect` から文字数・敵数連動の倍率テーブルが解析された場合、最後に検出された1件が投稿共通の **`kotowaza_maltiplier_table_group`** として設定されます（凸ごとの個別倍率テーブルは保持されません）。

---

## 5. `koto_magnification_estimate_tf`（「要推定」フラグ）の使い分け

`basic_fields.koto_magnification_estimate_tf`（真偽値 `boolean`）は、**コトワザの効果倍率に未確定・推定の値が含まれるか** を表します。

| 状況 | `koto_magnification_estimate_tf` の指定値 |
|---|---|
| スクリーンショットの文言（「超絶強力」「爆絶強力」等）のみから作成し、実倍率が確定していない場合 | **`true`** |
| 実倍率が検証・確定済みで推定を含まない場合 | `false` または省略 |

> [!NOTE]
> 通常の `magnification_estimate_tf`（わざ・すごわざ用）は MCP 経由の作成ではサーバー側で強制 `true` になりますが、`koto_magnification_estimate_tf`（コトワザ用）は強制上書きされません。推定値を含む場合は明示的に `"koto_magnification_estimate_tf": true` を指定してください。

---

## 6. 実戦 JSON サンプル

### サンプル1: 0凸〜4凸で発動条件緩和＋複数効果を含む構成（推奨配列）

```json
{
  "character_name": "サンプルキャラクター",
  "basic_fields": {
    "name_ruby": "さんぷるきゃらくたー",
    "attribute": "火",
    "species": "神",
    "rarity": "スペシャル",
    "koto_magnification_estimate_tf": true
  },
  "auto_input": {
    "auto_input_kotowaza": [
      {
        "condition": "「あ,い」を含む5文字以上のことば",
        "effect": "敵全体に超絶強力な火属性攻撃"
      },
      {
        "condition": "「あ,い」を含む4文字以上のことば",
        "effect": "敵全体に超絶強力な火属性攻撃"
      },
      {
        "condition": "「あ,い」を含む4文字以上のことば",
        "effect": "①味方全体のATKを2段階UP②敵全体に超絶強力な火属性攻撃"
      },
      {
        "condition": "①「あ,い」を含む4文字以上のことば②10コンボ以上",
        "effect": "①味方全体のATKを2段階UP②敵全体に爆絶強力な火属性攻撃"
      },
      {
        "condition": "①「あ,い」を含む4文字以上のことば②8コンボ以上",
        "effect": "①味方全体のATKを3段階UP②敵全体に爆絶強力な火属性攻撃+HPを回復"
      }
    ]
  }
}
```

### サンプル2: ラッパー形式（Actions 等で配列直指定しにくい場合）

```json
{
  "auto_input": {
    "auto_input_kotowaza": {
      "levels": [
        {
          "condition": "4文字以上のことば",
          "effect": "敵単体に強力な水属性2回攻撃"
        }
      ]
    }
  }
}
```

---

## 7. エラー・警告発生時のルール

* **`VALIDATION_FAILED`（`reason: "unparsed_text"`）**:
  * 指定した全凸の `condition` と `effect` が1件も有効に解析できなかった場合に返却されます。スクショの文字起こし（全角・記号・誤字脱字）を見直してください。
* **`warnings`（部分未解析警告）**:
  * 一部の凸や一部のフレーズだけが解析できなかった場合、`preview_draft` / `create_draft` / REST `dry_run` の `warnings` に以下のように該当箇所付きで通知されます：
    * 例: `auto_input_kotowaza[2](2凸).condition の一部の文言を解析できませんでした: ...`
    * 例: `auto_input_kotowaza[3](3凸).effect の一部の効果文言を解析できませんでした: ...`
* **【禁止事項】推測による文言改変や `kotowaza_loop_v2` 直接指定は禁止**:
  * 未知の表現で警告が出た場合でも、意味を変えて別の定型文に勝手に書き換えたり、内部 ACF 構造を推測して直接渡したりしないでください。最大 3 回まで原文の転記ミスを確認し、解消しない場合は警告内容を添えてユーザーに報告してください。
* **既存下書き更新（`update_draft`）時の注意**:
  * `auto_input_kotowaza` を含む `update_draft` 時は、`kotowaza_loop_v2` が置換更新（`replace_existing: true` 既定）されます。更新前の内容を確認したい場合は事前に `get_draft` で状態を確認してください。
