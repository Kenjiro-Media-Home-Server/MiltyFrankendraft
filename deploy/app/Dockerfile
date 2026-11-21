FROM caddy:2.11-builder AS caddy-builder

RUN xcaddy build \
    --with github.com/baldinof/caddy-supervisor

FROM php:8.2-fpm-alpine AS base

COPY --from=caddy-builder /usr/bin/caddy /usr/bin/caddy
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY deploy/app/php/php.ini /usr/local/etc/php/conf.d/php.ini
COPY deploy/app/caddy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN caddy validate --config=/etc/caddy/Caddyfile

WORKDIR /code

RUN addgroup -g 1000 -S app \
  && adduser -u 1000 -S app -G app \
  && chown app:app /code

# Draft storage lives outside the web root. Creating it here with the right owner matters:
# Docker seeds a fresh named volume from the image's directory, so a volume mounted at this
# path inherits app:app instead of coming up root-owned and unwritable.
RUN mkdir -p /srv/drafts && chown app:app /srv/drafts

RUN apk --update --no-cache add \
    supervisor \
    curl \
    curl-dev \
    libpng-dev \
    zlib

USER app

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

FROM base AS development

FROM base AS production

COPY --chown=app:app . /code

RUN composer install --no-dev --optimize-autoloader