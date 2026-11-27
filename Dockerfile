FROM php:8.5-apache AS base

# GD + EXIF: limpeza das imagens (remove o GPS, corrige rotação, reduz tamanho).
# zip: Composer. O SQLite (pdo_sqlite) já vem na imagem oficial do PHP.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install zip gd exif \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP: fotos de até 5 MB cada, até 6 por envio (40 MB) e sem expor a versão do PHP.
RUN printf "upload_max_filesize=6M\npost_max_size=40M\nexpose_php=Off\n" > /usr/local/etc/php/conf.d/cerebro.ini \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Apache:
# - aponta para o public/ do Laravel
# - escuta na 8000 (porta alta = roda sem root; o túnel e o n8n continuam em http://cerebro:8000)
# - mod_rewrite para as rotas do Laravel e o .htaccess (que repassa o "Authorization: Bearer" do n8n)
# - não mostra versão do Apache
# - no máximo 10 processos PHP (cabe nos 512 MB do compose)
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's!Listen 80$!Listen 8000!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:8000>!' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /app/public>\n    AllowOverride All\n    Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\nServerName cerebro\n<IfModule mpm_prefork_module>\n    StartServers 2\n    MinSpareServers 2\n    MaxSpareServers 4\n    MaxRequestWorkers 10\n    MaxConnectionsPerChild 500\n</IfModule>\n' \
        > /etc/apache2/conf-available/cerebro.conf \
    && a2enconf cerebro \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

WORKDIR /app

# Dependências primeiro (camada em cache enquanto o composer.json não mudar).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p database/data storage/app/public storage/framework/cache/data storage/framework/sessions \
                storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /app

# Roda sem root (o Apache numa porta alta não precisa de root).
USER www-data

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/up') === false ? 1 : 0);"

CMD ["sh", "docker/start.sh"]