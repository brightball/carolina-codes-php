FROM php:8.5-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY carolina.php router.php server.php ./
ENV PORT=8080
EXPOSE 8080
CMD ["php", "server.php"]
