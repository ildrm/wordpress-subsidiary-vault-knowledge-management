FROM php@sha256:819080ed413631f6ec564184dd9acd08d9425bd863b9f8b5876b5ea2147d55e7
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libpq-dev libsodium-dev libicu-dev libonig-dev \
    && docker-php-ext-install -j2 pdo_sqlite pdo_pgsql intl mbstring sodium \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
RUN php -r 'foreach (["pdo_sqlite","pdo_pgsql","curl","sodium","intl","mbstring","openssl","Phar","fileinfo"] as $ext) { if (!extension_loaded($ext)) { exit(1); } }'
EXPOSE 8088
CMD ["php", "tools/start-local.php"]
