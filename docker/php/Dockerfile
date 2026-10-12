# syntax=docker/dockerfile:1
FROM php:8.3-fpm-bookworm

ARG UID=1000
ARG GID=1000

# --- system + PHP extensions ------------------------------------------------
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        ldap \
        bcmath \
        gd \
        intl \
        zip \
        exif \
        opcache \
        pcntl \
        redis \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        less \
        postgresql-client \
        ldap-utils \
        # Official PDF templates: qpdf normalises uploads for the FPDI importer,
        # poppler renders page previews and lets tests check placement.
        qpdf \
        poppler-utils \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- unprivileged application user -----------------------------------------
RUN groupmod -o -g ${GID} www-data \
    && usermod  -o -u ${UID} -g ${GID} www-data \
    && mkdir -p /var/www/html /home/www-data/.composer \
    && chown -R www-data:www-data /var/www/html /home/www-data

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-workflow.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV HOME=/home/www-data \
    COMPOSER_HOME=/home/www-data/.composer \
    XDG_CONFIG_HOME=/home/www-data/.config

WORKDIR /var/www/html
USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
