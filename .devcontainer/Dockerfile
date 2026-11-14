FROM wordpress:7.1-php8.2-apache

# WP-CLIでのZIPファイル展開に必要なunzipをインストール
RUN apt-get update && apt-get install -y unzip \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Xdebugのインストールと有効化を追加
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# アップロード上限・メモリ上限・実行時間制限の引き上げ
RUN printf "upload_max_filesize = 128M\npost_max_size = 128M\nmemory_limit = 256M\nmax_execution_time = 300\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

# Antigravity /browser 用のヘッドレス Google Chrome と日本語フォントを導入（amd64 環境のみ）
COPY .devcontainer/install-chrome.sh /tmp/install-chrome.sh
RUN if [ "$(dpkg --print-architecture)" = "amd64" ]; then \
        bash /tmp/install-chrome.sh; \
    else \
        echo "[devcontainer] Skipping Google Chrome installation on unsupported arch: $(dpkg --print-architecture)"; \
    fi \
    && rm -f /tmp/install-chrome.sh
