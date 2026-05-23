FROM composer:2 AS laravel-vendor

WORKDIR /laravel
COPY laravel/composer.json laravel/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-req=php+
COPY laravel ./
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
RUN composer dump-autoload --optimize --no-dev --no-interaction


FROM node:20-trixie-slim AS laravel-build

WORKDIR /laravel
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        php-cli \
        php-mbstring \
        php-xml \
        php-sqlite3 \
    && rm -rf /var/lib/apt/lists/*
COPY laravel/package.json laravel/package-lock.json ./
RUN npm ci
COPY laravel ./
COPY --from=laravel-vendor /laravel/vendor ./vendor
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views bootstrap/cache
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    WAYFINDER_GENERATE=false
RUN php artisan wayfinder:generate --with-form \
    && npm run build


FROM python:3.12-slim-trixie AS base

ARG ARCHIBOT_GIT_SHA=""
ARG ARCHIBOT_GIT_REF=""

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    ARCHIBOT_GIT_SHA=${ARCHIBOT_GIT_SHA} \
    ARCHIBOT_GIT_REF=${ARCHIBOT_GIT_REF} \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# System deps for the Laravel/Svelte web app plus Python worker/MCP runtime.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get dist-upgrade -y \
    && apt-get install -y --no-install-recommends \
        tini \
        curl \
        php-cli \
        php-curl \
        php-mbstring \
        php-pgsql \
        php-sqlite3 \
        php-xml \
        php-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Python dependencies are kept for CLI workers and MCP runtime.
COPY pyproject.toml constraints.txt ./
RUN pip install --upgrade pip setuptools wheel \
    && pip install -c constraints.txt \
        "httpx>=0.27.0" \
        "pydantic>=2.9.0,<=2.12.5" \
        "pydantic-settings>=2.5.0,<=2.14.0" \
        "apscheduler>=3.10.4" \
        "dramatiq[rabbitmq]>=1.17.0,<2.0.0" \
        "sqlalchemy>=2.0.0,<3.0.0" \
        "psycopg[binary]>=3.2.0,<4.0.0" \
        "pgvector>=0.3.0,<1.0.0" \
        "structlog>=24.4.0,<=25.5.0" \
        "sqlite-vec>=0.1.3,<=0.1.9" \
        "mcp[cli]>=1.20.0,<=1.27.0" \
        "pymupdf>=1.24.0,<=1.27.2.3"

COPY app ./app
COPY prompts ./prompts
COPY entrypoint.sh ./
COPY --from=laravel-vendor /laravel ./laravel
COPY --from=laravel-build /laravel/public/build ./laravel/public/build

RUN pip install --no-deps . \
    && chmod +x /app/entrypoint.sh \
    && mkdir -p /data /app/laravel/storage/framework/cache /app/laravel/storage/framework/sessions /app/laravel/storage/framework/views /app/laravel/bootstrap/cache \
    && chown -R www-data:www-data /app/laravel/storage /app/laravel/bootstrap/cache

VOLUME ["/data"]

EXPOSE 8088 3001

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost:${GUI_PORT:-8088}/healthz || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["./entrypoint.sh"]
