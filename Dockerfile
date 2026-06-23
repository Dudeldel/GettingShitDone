# Backend service — Laravel + Octane (Swoole) for Railway.
# Pin the PHP tag deliberately; an implicit bump can break the swoole pecl compile.
FROM php:8.3-cli AS app

# System deps + PHP extensions Laravel needs, plus Swoole for Octane.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libbrotli-dev \
    && docker-php-ext-install pdo_mysql bcmath zip mbstring \
    && yes '' | pecl install swoole \
    && docker-php-ext-enable swoole \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first for layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# App source, then finalize autoloader + package discovery.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

COPY deploy/railway/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV OCTANE_SERVER=swoole
# Railway injects $PORT at runtime; 8000 is the local default.
EXPOSE 8000
CMD ["/usr/local/bin/entrypoint.sh"]
