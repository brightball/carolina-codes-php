FROM php:8.5-cli
# pdo_pgsql and pcntl are compiled here. The base image already contains gcc
# and docker-php-ext-install pulls in $PHPIZE_DEPS; both are removed so the
# runtime layer keeps libpq but not the compiler toolchain.
# opcache.enable_cli cannot be flipped with ini_set, so it is set in conf.d
# before the process starts. server.php also passes the same -d flags.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl; \
    apt-get install -y --no-install-recommends libpq5; \
    apt-mark manual libpq5; \
    apt-get purge -y --auto-remove libpq-dev gcc $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.jit=disable'; \
      echo 'opcache.jit_buffer_size=0'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.file_update_protection=0'; \
    } > /usr/local/etc/php/conf.d/opcache-cli.ini
WORKDIR /app
COPY carolina.php router.php server.php ./
ENV PORT=8080
EXPOSE 8080
CMD ["php", "server.php"]
