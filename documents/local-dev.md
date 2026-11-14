# ローカル開発環境

このリポジトリは WordPress の子テーマ（`cocoon-child-master`）のため、ローカルで動作させるには WordPress 本体、MariaDB、Cocoon 親テーマ、ローカル用の初期化コードが必要です。`.devcontainer` を使用すると、これらをまとめて起動し、最小限のダミーデータも自動投入します。

## 作成されるもの

- `http://localhost:8080` で動く WordPress（`wordpress:7.1-php8.2-apache`、日本語ロケール `ja`、PHP メモリ上限 `256M`）
- MariaDB（`mariadb:11.4`）
- `wp-content/themes/cocoon-master` に配置される Cocoon 親テーマ
- `wp-content/themes/cocoon-child-master` にマウントされるこのリポジトリ
- ACF プラグイン（既定: **ACF free** / `local-dev/plugin/advanced-custom-fields-pro.zip` を配置した場合は **ACF PRO** を自動優先）
- `wordpress-importer` プラグイン
- 足りない投稿タイプや taxonomy を登録するローカル用 mu-plugin（`local-dev/plugin/kotodaman-local-runtime.php`）
- ダミーの `character` 投稿、ターム、固定ページ（`local-dev/seed/seed.php`）
- `lib/character-search/all_characters_search.json`
- WP-CLI、Node.js（LTS）、Xdebug（`127.0.0.1:9003` / trigger モード）、ヘッドレス Google Chrome（`amd64` 環境のみ）

## 初回起動

1. このリポジトリを Dev Container で開きます（`.devcontainer/devcontainer.json` を使用）。
   - ※ 後述の「個人用カスタマイズ設定の分離」により `.devcontainer/local/devcontainer.json` を作成している場合は、ご自身のプロファイル（`kotodaman-db (local)`）を選択してください。
2. `postCreateCommand` の bootstrap 完了を待ちます。
3. `http://localhost:8080` を開きます。
4. 管理画面に入る場合は `http://localhost:8080/wp-admin` へアクセスし、以下でログインします。
   - ユーザー名: `admin`
   - パスワード: `admin`

## 手動で bootstrap を再実行する

コンテナ内でセットアップをやり直したい場合は、以下を実行します。

```bash
bash .devcontainer/bootstrap.sh
```

## 検索JSONだけ再生成する

```bash
wp eval 'koto_generate_search_json_all();' --path=/var/www/html --allow-root
```

## ACF Free / ACF PRO の切り替え

- **既定（ACF Free）**: 特別な準備は不要で、`advanced-custom-fields`（無料版）が自動インストールされます。そのため本番と同じ ACF PRO 専用 UI は再現されず、ローカル用ダミーデータでは `_spec_json` や検索用 meta を直接書き込みます。
- **ACF PRO を使う場合（任意）**: `.gitignore` 対象の `local-dev/plugin/advanced-custom-fields-pro.zip` を配置すると、`bootstrap.sh` が自動的に ACF PRO を優先インストールします。ライセンスキーを使う場合はホスト側環境変数 `ACF_LICENSE_KEY` を設定してください（未設定時は空文字として扱われます）。

## Xdebug デバッグ設定

`.devcontainer/xdebug.ini` が PHP にマウントされており、`xdebug.mode = debug`、`xdebug.start_with_request = trigger`、`127.0.0.1:9003` で待ち受ける構成になっています。
VS Code / エディタ側でコンテナ内のパス（`/var/www/html/wp-content/themes/cocoon-child-master`）をワークスペースルートにマッピングする `.vscode/launch.json`（`.gitignore` 対象）を用意するとステップ実行が可能です。

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": {
        "/var/www/html/wp-content/themes/cocoon-child-master": "${workspaceRoot}"
      }
    }
  ]
}
```

## 個人用カスタマイズ設定の分離（Git を汚さない運用）

共有の `.devcontainer/devcontainer.json` には個人固有のバインドマウントや拡張機能・デプロイ設定を含めず、必要な場合は `.gitignore` 対象の領域に分離します。

- **Dev Container 個人プロファイル（`.devcontainer/local/devcontainer.json`）**: `.devcontainer/local/` は `.gitignore` 対象です。`"dockerComposeFile": ["../docker-compose.yml"]` を指定することでコンテナ本体・DB 構成を共有したまま、個人のマウント設定や拡張機能を追加できます。
- **エディタ固有設定（`.vscode/`）**: `launch.json`、`settings.json`（例: `.devcontainer/acf-pro-stubs-master` の参照パス）、SFTP 設定等。
- **ローカルデータ・プラグイン（`local-dev/`・`.devcontainer/acf-pro-stubs-master/`）**: `.gitignore` により各自の環境にのみ保持されます。

## 補足

- ローカル用 runtime plugin は `local-dev/plugin/kotodaman-local-runtime.php` にあり、bootstrap 時に `mu-plugins` へコピーされます。
- ダミーデータ投入スクリプトは `local-dev/seed/seed.php` にあります。
- 作業ツリーに `lib/character-search/all_characters_search.json` が既にある場合、bootstrap はそれを保持し、再生成しません。
- ポート公開は `docker-compose.yml` の `8080:80` に一元化されています（`devcontainer.json` 側での重複 `forwardPorts` 指定は行いません）。

## コンテナ内 `/browser`（ヘッドレス Chrome）構成と他コンテナへの流用

この Dev Container では `.devcontainer/install-chrome.sh` を用いて、コンテナ内に **Google Chrome 公式安定版（`google-chrome-stable`）** と **日本語・絵文字フォント（`fonts-noto-cjk`, `fonts-noto-color-emoji`）** を導入しています（`amd64` 環境のみ実行され、Apple Silicon Mac 等の非 `amd64` 環境ではビルド時に自動でスキップされます）。あわせて以下の仕組みにより、コンテナ再起動後もそのまま `/browser`（`browser` サブエージェント）が動作します：

- `/usr/local/bin/google-chrome`: `root` 実行時の `--no-sandbox`、ヘッドレス環境向けの `--headless=new` / `--disable-dev-shm-usage` を自動補完するラッパー
- `/usr/local/bin/ensure-headless-chrome`: CDP ポート `9222` でヘッドレス Chrome を冪等に起動し、`$HOME/.config/google-chrome/DevToolsActivePort` を生成するヘルパー（`devcontainer.json` の `postStartCommand` からコマンド存在時のみ自動実行）
- `docker-compose.yml` の `shm_size: "1gb"` による共有メモリ拡張

### 別の Dev Container プロジェクトへ使いまわす手順

1. `.devcontainer/install-chrome.sh` を対象プロジェクトの `.devcontainer/` にコピーします（Debian / Ubuntu の `amd64` 環境向け）。
2. `Dockerfile` を使う場合は以下を追記します（ビルドコンテキストがリポジトリルートの場合）：
   ```dockerfile
   COPY .devcontainer/install-chrome.sh /tmp/install-chrome.sh
   RUN if [ "$(dpkg --print-architecture)" = "amd64" ]; then bash /tmp/install-chrome.sh; fi && rm -f /tmp/install-chrome.sh
   ```
   `Dockerfile` を持たない `devcontainer.json`（`"image"` 直指定）の場合は、`postCreateCommand` で `sudo bash .devcontainer/install-chrome.sh` を実行してください。
3. `devcontainer.json` に `"postStartCommand": "if command -v ensure-headless-chrome >/dev/null 2>&1; then ensure-headless-chrome; fi"` を追加します（既存コマンドがある場合は `&&` で連結）。
4. Docker Compose 構成なら `shm_size: "1gb"`（単体 `devcontainer.json` なら `"runArgs": ["--shm-size=1gb"]`）を指定すると、重いページ描画時のタブクラッシュを防げます。
