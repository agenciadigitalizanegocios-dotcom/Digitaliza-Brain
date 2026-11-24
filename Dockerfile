FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev libsqlite3-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install zip pdo_sqlite gd exif \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Fotos de até 5 MB (o padrão do PHP é 2 MB).
RUN printf "upload_max_filesize=6M\npost_max_size=8M\n" > /usr/local/etc/php/conf.d/uploads.ini

# Atende várias requisições ao mesmo tempo (painel + n8n).
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8000
CMD ["sh", "docker/start.sh"]
