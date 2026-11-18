---
okf_version: "0.2"
title: コトダマンDB ドキュメント索引（OKF v0.2）
description: リポジトリ内の全ドキュメントの入口。必要な資料だけを選んで開くための種別・状態・1行要約一覧。
---

# コトダマンDB ドキュメント索引

本リポジトリのドキュメントは **Open Knowledge Format (OKF) v0.2** に軽量準拠しています。
探索時はまず本索引（または下位の [mcp/index.md](mcp/index.md)）を確認し、目的に合うファイルだけを開いてください。

---

## 1. OKF メタデータ語彙表

各対象ドキュメントの冒頭 YAML frontmatter には以下のキーを付与しています（必須項目: `type`、推奨項目: `title` / `description` / `tags` / `status` / `resource`）。

### `type` 語彙
| `type` | 用途 |
| :--- | :--- |
| `map` | システム全体の構成図・ファイル責務・変更連動チェックリスト |
| `spec` | データ構造・検索スキーマ・API / プロトコル等の技術仕様 |
| `guide` | 開発・運用・設定・登録の実務手順およびガイドライン |
| `overview` | サブシステムやツール群の現状総覧・対応状況マトリクス |
| `note` | 補助メモ・記録（原則として現行仕様の正本は他の `type` を参照） |

### `status` 語彙
| `status` | 意味 |
| :--- | :--- |
| `stable` | 現行運用中の確定ドキュメント |
| `draft` | 作成途中、または初期メモが混在し部分更新中のドキュメント |
| `deprecated` | 旧資料・別ファイルへ役割移行済み |

---

## 2. ドキュメント一覧

### 全体・システムコア（`documents/`）
| ドキュメント | `type` | `status` | 要約（`description`） |
| :--- | :---: | :---: | :--- |
| [codebase-map.md](codebase-map.md) | `map` | `stable` | テーマ全体のアーキテクチャ、ファイル責務一覧、機能追加時の変更連動チェックリスト、ドキュメント整理方針。 |
| [spec.md](spec.md) | `spec` | `draft` | `_spec_json` スキーマ、`all_characters_search.json` の trait 検索 canonical slug 定義、火力計算・解析関数仕様メモ。 |
| [blessing-token-guide.md](blessing-token-guide.md) | `guide` | `stable` | 祝福・EX祝福トークンレジストリの構造定義、`label` 仕様、ギミック耐性トークン一覧および新規追加手順。 |
| [local-dev.md](local-dev.md) | `guide` | `stable` | DevContainer を用いたローカル WordPress・MariaDB・Xdebug・ヘッドレス Chrome 環境の構築と運用手順。 |

### MCP 連携・外部AI関連（[mcp/index.md](mcp/index.md)）
| ドキュメント | `type` | `status` | 要約（`description`） |
| :--- | :---: | :---: | :--- |
| [mcp/README.md](mcp/README.md) | `overview` | `stable` | MCPサーバーの実装ステータス、アーキテクチャ図、エンドポイント接続設定および拡張ロードマップ。 |
| [mcp/api-reference.md](mcp/api-reference.md) | `spec` | `stable` | MCP Streamable HTTP 全10ツール・Resources/Prompts、OAuth 2.1 認証、および WordPress REST API の技術仕様。 |
| [mcp/character-draft-guide.md](mcp/character-draft-guide.md) | `guide` | `stable` | スクリーンショット読み取りからキャラクター下書き作成・更新までの最短4ステップとフィールド入力規則。（`kotodaman://guide/character-draft`） |
| [mcp/character-entry-guide.md](mcp/character-entry-guide.md) | `guide` | `stable` | イベント・コラボ階層基準、周年紐付けストーリー対応表、`name_ruby` 表記ルール、雛形タイトル規約。（`kotodaman://guide/character-entry`） |
| [mcp/kotowaza-guide.md](mcp/kotowaza-guide.md) | `guide` | `stable` | コトワザ（0凸〜4凸）の入力スキーマ、発動条件・効果文言ルール、`koto_magnification_estimate_tf` の扱いと実例。（`kotodaman://guide/kotowaza`） |
| [mcp/external-ai-setup.md](mcp/external-ai-setup.md) | `guide` | `stable` | ChatGPT / Claude / Gemini / Muse / IDE エージェント等から MCP・REST API へ接続するための設定手順と協力者登録フロー。 |
| [mcp/ai-compatibility.md](mcp/ai-compatibility.md) | `overview` | `stable` | チャットAI・IDE・ワークフロー基盤ごとの MCP / OAuth 2.1 / OpenAPI 接続対応状況マトリクス。 |

### モジュール配下（`lib/`）
| ドキュメント | `type` | `status` | 要約（`description`） |
| :--- | :---: | :---: | :--- |
| [AUTO_INPUT_GUIDELINES.md](../lib/acf/auto-input/AUTO_INPUT_GUIDELINES.md) | `guide` | `stable` | ゲーム内スキル文言の分割規則、パーサー処理フロー、CSV対応表の記法および回帰テスト手順。 |
| [lib/mcp/README.md](../lib/mcp/README.md) | `overview` | `stable` | `lib/mcp` 配下のファイル構成・責務一覧および詳細ドキュメントへの案内。 |

---

## 3. 対象外・例外ファイル

* **旧資料（`old-○○.md`・歴史的メモとして保持）**:
  * [old-README.md](old-README.md): 初期開発時の構想・TODOメモ（入口は本ファイルへ移行）
  * [old-planning.md](old-planning.md): 初期ロードマップ・宣伝相談用 Marp スライド（Marp frontmatter 保持のためそのまま）
  * [old-other.md](old-other.md): 初期の企画・仕様メモ
  * [old-対応表作成済み項目.md](../lib/old-%E5%AF%BE%E5%BF%9C%E8%A1%A8%E4%BD%9C%E6%88%90%E6%B8%88%E3%81%BF%E9%A0%85%E7%9B%AE.md): 自動入力初期開発時の手書きメモ
* **エージェント設定・スキル定義**:
  * [AGENTS.md](../AGENTS.md): 常時読み込まれる開発・保守の絶対規範（15KB以内厳守）
  * `.agents/skills/`: Antigravity スキル定義ファイル群（固有スキーマのため OKF 対象外）
* **生成・配信用 JSON 資料**:
  * [mcp/mcp-field-manual.json](mcp/mcp-field-manual.json), [mcp/openapi.json](mcp/openapi.json), `documents/acf-json-parsed/`
* **ローカル専用（`.gitignore` 管理）**:
  * `documents/presentations/`, `documents/official-documents-for-AI/`
* **`log.md` について**:
  * 変更履歴は Git で管理しているため作成しません。

---

## 4. ドキュメント整理・更新方針（AI自律更新原則）

テーマ内の各種ドキュメントは、**「常時読み込まれる `AGENTS.md` の肥大化（15KB上限）を防ぎ、詳細な知見・仕様・手順は適切な個別 md へ記録し、OKF v0.2 軽量準拠で一覧管理する」** 原則で保守します。

1. **個別機能の詳細仕様・対応表・操作手順**:
   * **`AGENTS.md` に直接書かず**、必ず該当する専門ドキュメント（`documents/mcp/`、`documents/blessing-token-guide.md`、`AUTO_INPUT_GUIDELINES.md`、`documents/spec.md` 等）へ記録してください。
2. **システム全体の構造・ファイル連動・探索コマンド**:
   * [codebase-map.md](codebase-map.md) の該当セクションを更新してください。
3. **ファイル固有の実装経緯・制約・一時非公開等の NOTE**:
   * 該当 PHP ファイル内のコメント（DocBlock / インラインコメント）としてコード直近に記録し、ドキュメントとの乖離を防いでください。
4. **`AGENTS.md` へ追記できる例外（核のルールのみ）**:
   * 全タスク横断で守らないとメモリ枯渇・データ破壊・作業逸脱を引き起こす絶対規範のみとし、ファイルサイズ 15KB 以内を厳守してください。
5. **新規ドキュメント作成・旧資料整理（OKF 準拠）**:
   * `.agents/`・`AGENTS.md`・`old-*.md`・JSON 資料を除き、冒頭に OKF v0.2 準拠の YAML frontmatter（必須: `type`, `title`, `description`, `tags`, `status`。MCPリソース配信対象は `resource` も付与）を記載してください。
   * 追加・更新時は本索引（および MCP 系は [mcp/index.md](mcp/index.md)）を更新し、下記コマンドで検証してください。
   * 有効でなくなった初期メモや旧資料は `old-○○.md` に改名し、上記「3. 対象外・例外ファイル」へ記録してください。

## 5. 準拠チェック

```bash
python documents/check-okf.py
```
