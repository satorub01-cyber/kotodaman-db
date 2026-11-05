# MCPキャラクター下書き作成

`POST /wp-json/kotodaman/v1/mcp/character-draft` へ、1キャラクター分のスクリーンショットをAI側で読み取った結果を送ると、キャラクターの下書きを作成できます。

```json
{
  "character_name": "例：サンプルキャラ",
  "basic_fields": {
    "name_ruby": "さい",
    "max_ls_hp": 1000
  },
  "auto_input": {
    "auto_input_waza_name": "わざ名",
    "auto_input_waza": "敵単体に強力な火属性攻撃"
  },
  "screenshots": [
    {
      "name": "基本情報",
      "data": "data:image/png;base64,..."
    }
  ],
  "screenshot_urls": [
    "https://example.com/path/to/high-quality-character-image.png"
  ]
}
```

`basic_fields` は属性・種族・文字・ステータスなど、ACFへ直接保存する値です。`auto_input` はわざ・すごわざ・とくせい等のゲーム内文言で、既存のCSVパーサーを通してACFへ変換します。投稿は常に `draft` で作成され、公開は行いません。

`screenshot_urls` は後から用意する高画質画像のURLを貼る場所です。現在はURLを取得せず、下書きの `_koto_mcp_screenshot_urls` メタデータへ記録します。

`dry_run: true` を付けると投稿を作成せず、自動入力の解析結果だけを確認できます。認証はWordPress REST APIの通常の認証（推奨: Application Passwords）を使用します。
