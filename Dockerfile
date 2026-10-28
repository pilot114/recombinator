FROM dunglas/frankenphp:php8.4-alpine

# в Debian-варианте образа десятки HIGH/CRITICAL без исправлений, в alpine — единицы,
# а то, что уже исправлено в репозитории, подтягиваем apk upgrade
RUN apk upgrade --no-cache \
    && apk add --no-cache git unzip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
