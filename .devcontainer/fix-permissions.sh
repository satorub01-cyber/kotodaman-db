#!/usr/bin/env bash
set -euo pipefail

WP_ROOT="/var/www/html"
THEME_DIR="$WP_ROOT/wp-content/themes/cocoon-child-master"

if [ ! -d "$THEME_DIR" ]; then
  exit 0
fi

HOST_UID="$(stat -c '%u' "$THEME_DIR")"
HOST_GID="$(stat -c '%g' "$THEME_DIR")"

# 1. Apache (www-data) をホストユーザーのグループに参加させ、umask 000 を保証する
if [ "$HOST_GID" != "0" ] && command -v usermod >/dev/null 2>&1; then
  if ! getent group "$HOST_GID" >/dev/null 2>&1; then
    groupadd -g "$HOST_GID" hostgroup 2>/dev/null || true
  fi
  usermod -aG "$HOST_GID" www-data 2>/dev/null || true
fi

if [ -f /etc/apache2/envvars ] && ! grep -qxF 'umask 000' /etc/apache2/envvars; then
  echo 'umask 000' >> /etc/apache2/envvars
  apache2ctl graceful >/dev/null 2>&1 || true
fi

# 2. ワークスペース（子テーマ）内で root や www-data 所有になったファイルをホストユーザー所有に戻す
if [ "$HOST_UID" != "0" ]; then
  find "$THEME_DIR" -xdev \( ! -uid "$HOST_UID" -o ! -gid "$HOST_GID" \) \
    -exec chown -h "${HOST_UID}:${HOST_GID}" {} + 2>/dev/null || true
fi

# 3. Dev Containers CLI がホスト側からロックファイル等を書き込めるよう .devcontainer の権限を保証
chmod -R u+rwX,go+rX "$THEME_DIR/.devcontainer" 2>/dev/null || true
if [ -d "$THEME_DIR/.devcontainer/local" ]; then
  chmod 775 "$THEME_DIR/.devcontainer/local" 2>/dev/null || true
fi

# 4. WordPress UI (www-data) から書き込み・一時ファイル作成・リネームが発生するテーマ内ディレクトリ・JSON の書き込み権限を保証
for writable_dir in "$THEME_DIR/acf-json" "$THEME_DIR/lib" "$THEME_DIR/lib/character-search"; do
  if [ -d "$writable_dir" ]; then
    chmod 777 "$writable_dir" 2>/dev/null || true
  fi
done

for writable_file in \
  "$THEME_DIR"/acf-json/*.json \
  "$THEME_DIR/lib/missing-info.json" \
  "$THEME_DIR/lib/character-search/all_characters_search.json"; do
  if [ -f "$writable_file" ]; then
    chmod 666 "$writable_file" 2>/dev/null || true
  fi
done

# 5. WordPress 本体側（uploads, plugins, upgrade, languages, mu-plugins, .htaccess）の www-data 権限を保証
for wp_dir in \
  "$WP_ROOT/wp-content/upgrade" \
  "$WP_ROOT/wp-content/uploads" \
  "$WP_ROOT/wp-content/plugins" \
  "$WP_ROOT/wp-content/languages" \
  "$WP_ROOT/wp-content/mu-plugins"; do
  if [ -d "$wp_dir" ]; then
    chown -R www-data:www-data "$wp_dir" 2>/dev/null || true
    chmod -R u+rwX,g+rwX "$wp_dir" 2>/dev/null || true
  fi
done

if [ -f "$WP_ROOT/.htaccess" ]; then
  chown www-data:www-data "$WP_ROOT/.htaccess" 2>/dev/null || true
  chmod 666 "$WP_ROOT/.htaccess" 2>/dev/null || true
fi
