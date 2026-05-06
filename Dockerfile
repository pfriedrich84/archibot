FROM node:20-trixie-slim AS frontend-build

WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend ./
RUN npm run build


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
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_PATH_PREFIX=laravel \
    WAYFINDER_GENERATE=false
RUN php artisan wayfinder:generate --with-form \
    && npm run build


FROM python:3.12-slim-trixie AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# System deps (sqlite-vec wheel ist prebuilt, wir brauchen nur tini für saubere Signale)
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get dist-upgrade -y \
    && apt-get install -y --no-install-recommends \
        tini \
        curl \
        php-cli \
        php-curl \
        php-mbstring \
        php-sqlite3 \
        php-xml \
        php-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Dependencies (pinned with constraints for supply-chain protection)
COPY pyproject.toml constraints.txt ./
RUN pip install --upgrade pip setuptools wheel \
    && pip install -c constraints.txt \
        "fastapi>=0.121.0,<=0.135.2" \
        "starlette>=0.49.1,<0.50.0" \
        "uvicorn[standard]>=0.31.1,<=0.42.0" \
        "httpx>=0.27.0" \
        "pydantic>=2.9.0,<=2.12.5" \
        "pydantic-settings>=2.5.0" \
        "jinja2>=3.1.4" \
        "python-multipart>=0.0.12,<=0.0.26" \
        "apscheduler>=3.10.4" \
        "structlog>=24.4.0" \
        "sqlite-vec>=0.1.3,<=0.1.7" \
        "mcp[cli]>=1.20.0,<=1.26.0" \
        "pymupdf>=1.24.0,<=1.27.2.2"

# App
COPY app ./app
COPY prompts ./prompts
COPY entrypoint.sh ./
COPY --from=frontend-build /frontend/build ./frontend/build
COPY --from=laravel-vendor /laravel ./laravel
COPY --from=laravel-build /laravel/public/build ./laravel/public/build

# Register console script entry point (deps already installed above)
RUN pip install --no-deps . \
    && chmod +x /app/entrypoint.sh

# Persistent state
RUN mkdir -p /data
VOLUME ["/data"]

EXPOSE 8088 3001

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost:8088/healthz || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["./entrypoint.sh"]
