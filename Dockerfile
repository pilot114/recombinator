FROM php:8.4-cli

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
