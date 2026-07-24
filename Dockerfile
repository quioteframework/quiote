FROM dunglas/frankenphp

# PHP extensions required by Quiote (see composer.json): ext-dom, ext-intl,
# ext-xsl (config XSL transformations), lib-libxml, ext-spl/reflection/pcre
# ship with PHP by default.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libxslt1-dev \
    && docker-php-ext-install intl xsl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app/
RUN composer install --no-interaction --no-progress --prefer-dist --no-dev

# Preload the Quiote\* core classes into OPcache once at PHP process startup
# so every FrankenPHP worker starts warm instead of re-autoloading on its
# first request. See etc/opcache/preload.php.
RUN { \
        echo "opcache.preload=/app/etc/opcache/preload.php"; \
        echo "opcache.preload_user=root"; \
    } > "$PHP_INI_DIR/conf.d/preload.ini"

COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
