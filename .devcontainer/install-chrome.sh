#!/usr/bin/env bash
set -euo pipefail

# Generic Google Chrome (headless-ready) installer for Debian/Ubuntu Dev Containers.
# Designed to work both during Dockerfile build and in postCreateCommand.

ARCH="$(dpkg --print-architecture)"
if [ "$ARCH" != "amd64" ]; then
  echo "Error: google-chrome-stable requires amd64 architecture (detected: $ARCH)" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y --no-install-recommends \
  ca-certificates \
  curl \
  gnupg \
  fonts-noto-cjk \
  fonts-noto-color-emoji

mkdir -p /usr/share/keyrings
curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
  | gpg --dearmor --yes -o /usr/share/keyrings/google-linux-signing-keyring.gpg

echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-linux-signing-keyring.gpg] https://dl.google.com/linux/chrome/deb/ stable main" \
  > /etc/apt/sources.list.d/google-chrome.list

apt-get update
apt-get install -y --no-install-recommends google-chrome-stable
rm -f /etc/apt/sources.list.d/google-chrome.list
rm -rf /var/lib/apt/lists/*

# Wrapper so Chrome works cleanly as root and/or without an X/Wayland display.
cat << 'EOF' > /usr/local/bin/google-chrome
#!/usr/bin/env bash
set -euo pipefail

CHROME_BIN="/opt/google/chrome/google-chrome"

for arg in "$@"; do
  case "$arg" in
    --version|--product-version|--help|-h)
      exec "$CHROME_BIN" "$@"
      ;;
  esac
done

extra_args=()
has_arg() {
  local needle="$1"
  shift || true
  for a in "$@"; do
    case "$a" in
      "$needle"|"$needle"=*) return 0 ;;
    esac
  done
  return 1
}

if [ "$(id -u)" -eq 0 ] && ! has_arg "--no-sandbox" "$@"; then
  extra_args+=("--no-sandbox" "--disable-setuid-sandbox")
fi

if ! has_arg "--disable-dev-shm-usage" "$@"; then
  extra_args+=("--disable-dev-shm-usage")
fi

if [ -z "${DISPLAY:-}" ] && [ -z "${WAYLAND_DISPLAY:-}" ] && ! has_arg "--headless" "$@"; then
  extra_args+=("--headless=new" "--disable-gpu")
fi

exec "$CHROME_BIN" "${extra_args[@]}" "$@"
EOF
chmod +x /usr/local/bin/google-chrome

# Idempotent background starter so Antigravity /browser finds DevToolsActivePort automatically.
cat << 'EOF' > /usr/local/bin/ensure-headless-chrome
#!/usr/bin/env bash
set -euo pipefail

CDP_PORT="${CHROME_CDP_PORT:-9222}"
CDP_PROFILE="${CHROME_CDP_PROFILE:-/tmp/chrome-cdp-profile}"
CHROME_CONFIG_DIR="${HOME}/.config/google-chrome"
ACTIVE_PORT_FILE="${CHROME_CONFIG_DIR}/DevToolsActivePort"
LOG_FILE="${CHROME_CDP_LOG:-/tmp/chrome-cdp.log}"

fetch_ws_path() {
  local ws_url
  ws_url="$(curl -fsS "http://127.0.0.1:${CDP_PORT}/json/version" 2>/dev/null \
    | sed -n 's/.*"webSocketDebuggerUrl":[[:space:]]*"\([^"]*\)".*/\1/p' \
    | head -n 1)"
  if [ -z "$ws_url" ]; then
    return 1
  fi
  printf '%s\n' "${ws_url#*://*/}" | sed 's|^|/|'
}

write_active_port() {
  local ws_path="$1"
  mkdir -p "$CHROME_CONFIG_DIR"
  printf '%s\n%s\n' "$CDP_PORT" "$ws_path" > "$ACTIVE_PORT_FILE"
}

if ws_path="$(fetch_ws_path)"; then
  write_active_port "$ws_path"
  exit 0
fi

mkdir -p "$CDP_PROFILE" "$CHROME_CONFIG_DIR"
rm -f "${CDP_PROFILE}/SingletonLock" "${CDP_PROFILE}/SingletonCookie" "${CDP_PROFILE}/SingletonSocket"

if command -v setsid >/dev/null 2>&1; then
  setsid -f google-chrome \
    --remote-debugging-port="$CDP_PORT" \
    --user-data-dir="$CDP_PROFILE" \
    --no-first-run \
    --no-default-browser-check \
    >"$LOG_FILE" 2>&1 </dev/null
else
  nohup google-chrome \
    --remote-debugging-port="$CDP_PORT" \
    --user-data-dir="$CDP_PROFILE" \
    --no-first-run \
    --no-default-browser-check \
    >"$LOG_FILE" 2>&1 </dev/null &
fi

for _ in $(seq 1 50); do
  if ws_path="$(fetch_ws_path)"; then
    write_active_port "$ws_path"
    exit 0
  fi
  sleep 0.2
done

echo "Error: Headless Chrome CDP did not become ready on 127.0.0.1:${CDP_PORT}" >&2
cat "$LOG_FILE" >&2 || true
exit 1
EOF
chmod +x /usr/local/bin/ensure-headless-chrome
