---
name: add-ai-guide
description: >-
  コトダマンDBのMCP連携用AIガイド（入力規則・フィールドマニュアル・新規ガイド文書）を
  追加または更新する手順。ユーザーが「AIガイドに追加して」「AIガイドを作成して」
  「MCPガイドを更新して」等と依頼したときに使用する。
---

# コトダマンDB AIガイド追加・更新手順

外部AIおよび開発エージェントが参照するMCP用ガイドを追加・更新する際は、以下の手順で関連ファイルを漏れなく連動させること。

## 1. 実仕様の事前確認
* ガイドに記載する仕様（区切り文字、フォールバック、バリデーション、上限値など）を、推測せずコードベースから直接確認する。
  * 例: `lib/koto-calc.php`、`lib/mcp/`、`lib/acf/auto-input/`

## 2. ガイド本体・マニュアルの更新／新規作成
目的に応じて適切なファイルを更新（または `documents/mcp/` 配下に新規作成）する。

* **登録基準・表記ルール（イベント階層、ひらがな、`voice_actor` 等）**:
  * [documents/mcp/character-entry-guide.md](../../../documents/mcp/character-entry-guide.md)（Resource: `kotodaman://guide/character-entry`）
* **下書き作成フロー・`basic_fields` / `auto_input` 全体規則**:
  * [documents/mcp/character-draft-guide.md](../../../documents/mcp/character-draft-guide.md)（Resource: `kotodaman://guide/character-draft`）
* **フィールド個別説明（`get_field_schema` 返却用）**:
  * [documents/mcp/mcp-field-manual.json](../../../documents/mcp/mcp-field-manual.json)（Resource: `kotodaman://guide/field-manual`）
* **新規トピックの独立ガイド**:
  * `documents/mcp/<topic>-guide.md` として新規作成し、冒頭に MCP Resource URI（`kotodaman://guide/<topic>`）と全体ガイドへのリンクを記載する。

## 3. MCPサーバー配信定義の連動（`lib/mcp/protocol/mcp-resources.php`）
* **新規ガイドを作成した場合**:
  1. `koto_mcp_resources_list()` に新規 Resource（`uri`, `name`, `description`, `mimeType`）を追加する。
  2. `koto_mcp_read_resource($uri)` に該当ファイルを読み込んで返却する分岐を追加する。
* **既存ガイドに重要な基本ルールを追記した場合**:
  * 必要に応じて `koto_mcp_instructions_text()` および `koto_mcp_workflow_guide_data()` 内の要約・該当フィールド説明も整合させる。

## 4. 索引ドキュメントの更新
* 新規ガイドを追加した場合や既存ガイドの役割が増えた場合は、以下の索引・マップにも反映する。
  * [documents/mcp/README.md](../../../documents/mcp/README.md)
  * [documents/codebase-map.md](../../../documents/codebase-map.md)

## 5. 検証
* `documents/mcp/mcp-field-manual.json` を編集した場合:
  ```bash
  php -r 'json_decode(file_get_contents("documents/mcp/mcp-field-manual.json"), false, 512, JSON_THROW_ON_ERROR); echo "JSON OK\n";'
  ```
* `lib/mcp/protocol/mcp-resources.php` を編集した場合:
  ```bash
  php -l lib/mcp/protocol/mcp-resources.php
  ```
