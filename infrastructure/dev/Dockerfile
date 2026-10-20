FROM php:8.5-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libsodium-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_sqlite intl mbstring sodium \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
RUN php -r 'foreach (["pdo_sqlite","sodium","intl","mbstring","openssl","Phar"] as $ext) { if (!extension_loaded($ext)) { exit(1); } }'
EXPOSE 8088
CMD ["php", "tools/start-local.php"]
