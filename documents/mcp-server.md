# コトダマンDB MCPサーバー

## 接続先

MCPのStreamable HTTPエンドポイントは次の形式です。

```text
${KOTO_MCP_BASE_URL}/mcp
```

`KOTO_MCP_BASE_URL`には、ドメインだけ、またはWordPress REST APIの名前空間までを指定できます。

```text
https://kotodaman-db.com/wp-json/kotodaman/v1
http://localhost:8080
```

実際のURLや認証情報は環境変数・クライアント設定で管理し、リポジトリには保存しません。

## Antigravity設定例

AntigravityがOAuth対応の`serverUrl`形式を受け付ける場合の例です。

```json
{
  "mcpServers": {
    "kotodaman-db": {
      "serverUrl": "${KOTO_MCP_BASE_URL}/mcp"
    }
  }
}
```

初回接続時はOAuth認可画面へ移動します。管理者・編集者は即時認可、それ以外のユーザーは申請状態になります。管理者はWordPress管理画面の「ユーザー > MCP利用申請」から承認します。

## Muse設定

Museのカスタムコネクタで、MCPリソースURIに次を指定します。

```text
${KOTO_MCP_BASE_URL}/mcp
```

OAuthメタデータは次のURLから取得できます。

```text
${SITE_URL}/.well-known/oauth-protected-resource
${SITE_URL}/.well-known/oauth-authorization-server
```

## ツールの使い分け

1. `get_field_schema`でACFフィールドと人間向けマニュアルを取得する。
2. `get_terms`で属性・種族・レアリティ等の既存タームIDを取得する。
3. `search_character`で同名候補を確認する。
4. `validate`で保存せず型・必須・ターム・自動入力を検証する。
5. `preview_draft`で解析結果と`content_hash`を取得する。
6. `create_draft`または`update_draft`へ、同じ入力と`preview_hash`を渡す。
7. 返却された下書きを人間が確認する。

`create_draft`と`update_draft`には`idempotency_key`が必須です。同じユーザー・同じキーの再実行は同じ結果を返し、二重作成を防ぎます。

## AIクライアントの禁止事項

- スクリーンショットに存在しない値を推測して埋めない。
- ターム名を直接送らず、`get_terms`で取得したIDだけを送る。
- `VALIDATION_FAILED`を受けた場合は最大3回まで該当項目だけを読み直す。
- 3回読み直しても不明な場合は処理を停止し、人間に確認を求める。
- `PREVIEW_HASH_MISMATCH`を無視して再利用しない。入力を固定して再度プレビューする。
- 投稿公開や公開相当の処理を試みない。

## 環境変数

| 変数 | 用途 |
|---|---|
| `KOTO_MCP_BASE_URL` | WordPress REST API名前空間のベースURL |

ローカルは`http://localhost:8080`、本番は`https://kotodaman-db.com`を設定できます。サーバーが必要な`/wp-json/kotodaman/v1`を補完します。OAuthトークンはWordPressデータベースの一時データへ保存され、ログやレスポンス以外へ出力しません。

## 画像

画像URLを省略した場合、準備中画像として次のURLを自動設定します。

```text
https://www.kotodaman-db.com/wp-content/uploads/2026/02/無題51_20260205005357.png
```

URLを指定した場合も、現在は`www.kotodaman-db.com`の同一ドメインだけを取得対象とします。取得できた画像は投稿のアイキャッチへ設定し、URL一覧は`_koto_mcp_screenshot_urls`へ保存します。

## 人間向けマニュアルの追加

`documents/mcp-field-manual.json`へフィールド名をキーとして説明を追加します。

```json
{
  "field_name": {
    "description": "画面上のどこを根拠にするか、値の読み取り規則",
    "source": "スクリーンショットの項目名"
  }
}
```

`get_field_schema`のレスポンスへ自動的に含まれるため、AIクライアントは入力前に取得して参照できます。既存のACF定義は`acf-json/`から読み込まれます。

## 実装範囲と今後の拡張

サーバー本体は`lib/acf/mcp/server.php`にあり、既存のCSVパーサーとACF更新処理を再利用します。次の拡張候補は、監査ログの専用テーブル化、未解析文言の詳細報告、管理画面でのプレビュー差分表示、OAuth同意画面の専用UIです。
