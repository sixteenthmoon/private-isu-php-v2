# syntax=docker/dockerfile:1
FROM php:8.3-fpm-bookworm

RUN \
  --mount=type=cache,target=/var/lib/apt,sharing=locked \
  --mount=type=cache,target=/var/cache/apt,sharing=locked \
  apt-get update -qq && apt-get install -y unzip libmemcached-dev zlib1g-dev libssl-dev

RUN docker-php-ext-install pdo pdo_mysql

RUN pecl install memcached \
  && docker-php-ext-enable memcached

# コードはCOPYでイメージへ焼き込まれ稼働中は不変（bind mountではない）ため、
# opcacheの毎リクエストstatによる変更検知は無駄なコスト。無効化する。
RUN { echo 'opcache.validate_timestamps=0'; } > /usr/local/etc/php/conf.d/zz-isucon-opcache.ini

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN curl -sS https://getcomposer.org/installer | php \
  && mv composer.phar /usr/local/bin/composer

COPY ./composer.json /var/www/html
COPY ./composer.lock /var/www/html
WORKDIR /var/www/html

RUN composer install --no-dev

COPY . /var/www/html
