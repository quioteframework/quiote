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

COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
