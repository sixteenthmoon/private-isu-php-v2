#!/bin/sh
set -eu

mkdir -p /dev/shm/php-sessions
chmod 0777 /dev/shm/php-sessions

exec docker-php-entrypoint "$@"
