FROM php:8.5-cli
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends git curl ca-certificates libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
