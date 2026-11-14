# Official Docker Hub linux/amd64 manifests resolved 2026-11-07.
FROM node:24.13.0-bookworm-slim@sha256:46feb5752989c05b8606e6323fbbc3db667d14ade1c24f5d0d44d9ca9909d607 AS node
FROM composer:2.8.12@sha256:0d264a0f1e5be23ba363447768df7b30c33d542711ea12e37770ed7b13bf4eaa AS composer
FROM mysql@sha256:72c368d898861f3b3a07af0e6d690400f44f048799ed46822783d9224cc2c86d AS mysql84
FROM php:8.4.27-cli-bookworm@sha256:5d3eebbd478bc102494c539d43be98a885984b33f929c6081f1c59033a878d6a
ENV DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1 MAKEFLAGS=-j2
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=mysql84 /usr/bin/mysql /usr/local/bin/mysql84
COPY --from=mysql84 /usr/bin/mysqldump /usr/local/bin/mysql84-mysqldump
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl git python3 rsync unzip zip openssl default-mysql-client \
    libicu-dev libzip-dev libonig-dev libsqlite3-dev libxml2-dev \
    libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libdbus-1-3 \
    libxkbcommon0 libx11-6 libxcomposite1 libxdamage1 libxext6 libxfixes3 libxrandr2 \
    libgbm1 libpango-1.0-0 libcairo2 libasound2 fonts-liberation fonts-noto-color-emoji \
    && docker-php-ext-install -j2 pdo_mysql pdo_sqlite intl zip mbstring bcmath pcntl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && mkdir -p /opt/runtime-evidence \
    && dpkg-query -W > /opt/runtime-evidence/debian-packages.tsv \
    && php -v > /opt/runtime-evidence/php.txt && php -m >> /opt/runtime-evidence/php.txt \
    && php --ri redis > /opt/runtime-evidence/redis.txt \
    && node --version > /opt/runtime-evidence/node.txt && npm --version >> /opt/runtime-evidence/node.txt \
    && composer --version > /opt/runtime-evidence/composer.txt \
    && rsync --version > /opt/runtime-evidence/rsync.txt \
    && mysql --version > /opt/runtime-evidence/mysql-client.txt \
    && mysqldump --version >> /opt/runtime-evidence/mysql-client.txt \
    && mysql84 --version >> /opt/runtime-evidence/mysql-client.txt \
    && mysql84-mysqldump --version >> /opt/runtime-evidence/mysql-client.txt \
    && python3 --version > /opt/runtime-evidence/python.txt \
    && test "$(php -r 'echo PHP_VERSION;')" = 8.4.27 \
    && test "$(node --version)" = v24.13.0 \
    && test "$(php -r 'echo phpversion("redis");')" = 6.3.0 \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
WORKDIR /workspace
# Test orchestration uses --user 1000:1000; installation remains root in setup only.
RUN groupadd --gid 1000 qa && useradd --uid 1000 --gid 1000 --create-home --shell /bin/bash qa \
    && id qa > /opt/runtime-evidence/qa-user.txt \
    && stat -c '%a %u %g %n' /usr/local/bin/php /usr/local/bin/node /usr/local/bin/composer /usr/bin/rsync /home/qa > /opt/runtime-evidence/runtime-modes.txt
