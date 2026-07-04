# e2e-only image: identical to the repo root Dockerfile, except it installs
# require-dev too (the open-telemetry/* packages live there, and are dev-only
# in the real production image). Never used outside tests/e2e/docker-compose.yml
# -- the production Dockerfile at the repo root is untouched by this.
FROM dunglas/frankenphp

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
RUN composer install --no-interaction --no-progress --prefer-dist
# A PSR-18 client for the OTLP exporter -- deliberately NOT part of the repo's
# own composer.json/lock (its absence there is itself load-bearing for a unit test).
# This e2e image needs a real one to actually export over the wire, added
# here in an isolated build step so it never touches the committed lock file.
RUN composer require --no-interaction --no-progress symfony/http-client

# telemetry.* overrides for this e2e run only -- never touches the checked-in
# samples/app/Config/settings.php, which stays telemetry.enabled=false.
COPY tests/e2e/settings.override.php /app/samples/app/Config/settings.php

COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
