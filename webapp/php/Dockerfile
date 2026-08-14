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
RUN { echo 'opcache.validate_timestamps=0'; echo 'opcache.jit_buffer_size=0'; } > /usr/local/etc/php/conf.d/zz-isucon-opcache.ini

# セッションのmemcached接続をPHP-FPM worker単位で使い回し、毎リクエストの接続確立を省く。
RUN { echo 'memcached.sess_persistent=On'; } > /usr/local/etc/php/conf.d/zz-isucon-memcached-persistent.ini

# 同一セッションIDへの並行リクエストが起きないシナリオ構成（benchmarker/cli.go, scenario.go確認済み）
# のため、セッションロックの取得・解放ラウンドトリップは不要なコスト。無効化する。
RUN { echo 'memcached.sess_locking=Off'; } > /usr/local/etc/php/conf.d/zz-isucon-memcached-nolock.ini

# benchmark lifecycle is reset by GET /initialize, so probabilistic file-session GC
# only scans the growing tmpfs directory on the request path.
RUN { echo 'session.save_handler=files'; echo 'session.save_path=/dev/shm/php-sessions'; echo 'session.gc_probability=0'; } > /usr/local/etc/php/conf.d/zz-isucon-session-files.ini

# app is capped at one CPU; keep I/O overlap while reducing excess runnable workers.
RUN { echo '[www]'; echo 'pm = static'; echo 'pm.max_children=2'; } > /usr/local/etc/php-fpm.d/zz-isucon-www.conf

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN curl -sS https://getcomposer.org/installer | php \
  && mv composer.phar /usr/local/bin/composer

COPY ./composer.json /var/www/html
COPY ./composer.lock /var/www/html
WORKDIR /var/www/html

RUN composer install --no-dev --optimize-autoloader

COPY . /var/www/html

RUN chmod +x /var/www/html/isucon-entrypoint.sh
ENTRYPOINT ["/var/www/html/isucon-entrypoint.sh"]
CMD ["php-fpm"]
