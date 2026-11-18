---
okf_version: "0.2"
title: コトダマンDB MCP ドキュメント索引
description: documents/mcp 配下のガイド・仕様書・JSON定義ファイルの一覧と役割案内。
---

# コトダマンDB MCP ドキュメント索引

MCP（Model Context Protocol）サーバー、OAuth 2.1、REST API、およびキャラクター下書き登録運用に関するドキュメント一覧です。全体の索引は [../index.md](../index.md) を参照してください。

## Markdown ドキュメント

| ドキュメント | `type` | `status` | MCP Resource | 要約（`description`） |
| :--- | :---: | :---: | :--- | :--- |
| [README.md](README.md) | `overview` | `stable` | — | MCPサーバーの実装ステータス、アーキテクチャ図、エンドポイント接続設定および拡張ロードマップ。 |
| [api-reference.md](api-reference.md) | `spec` | `stable` | — | MCP Streamable HTTP 全10ツール・Resources/Prompts、OAuth 2.1 認証、および WordPress REST API の技術仕様。 |
| [character-draft-guide.md](character-draft-guide.md) | `guide` | `stable` | `kotodaman://guide/character-draft` | スクリーンショット読み取りからキャラクター下書き作成・更新までの最短4ステップとフィールド入力規則。 |
| [character-entry-guide.md](character-entry-guide.md) | `guide` | `stable` | `kotodaman://guide/character-entry` | イベント・コラボ階層基準、周年紐付けストーリー対応表、`name_ruby` 表記ルール、雛形タイトル規約。 |
| [kotowaza-guide.md](kotowaza-guide.md) | `guide` | `stable` | `kotodaman://guide/kotowaza` | コトワザ（0凸〜4凸）の入力スキーマ、発動条件・効果文言ルール、`koto_magnification_estimate_tf` の扱いと実例。 |
| [external-ai-setup.md](external-ai-setup.md) | `guide` | `stable` | — | ChatGPT / Claude / Gemini / Muse / IDE エージェント等から MCP・REST API へ接続するための設定手順と協力者登録フロー。 |
| [ai-compatibility.md](ai-compatibility.md) | `overview` | `stable` | — | チャットAI・IDE・ワークフロー基盤ごとの MCP / OAuth 2.1 / OpenAPI 接続対応状況マトリクス。 |

## 配信・生成用 JSON 資料（OKF対象外）

| ファイル | 用途 | 参照元 |
| :--- | :--- | :--- |
| [mcp-field-manual.json](mcp-field-manual.json) | `get_field_schema` / `kotodaman://guide/field-manual` 用のフィールド別読み取り定義 | [mcp-schema.php](../../lib/mcp/protocol/mcp-schema.php) |
| [openapi.json](openapi.json) | ChatGPT Actions / Copilot Studio 等向けの OpenAPI 3.1.0 スキーマ | [post-mcp.php](../../lib/mcp/protocol/post-mcp.php) |
