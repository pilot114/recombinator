FROM dunglas/frankenphp:php8.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY frankenphp/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
