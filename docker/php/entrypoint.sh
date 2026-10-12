#!/bin/sh
set -e

cd /var/www/html

# Make sure the writable trees exist even on a fresh clone.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public storage/app/private bootstrap/cache 2>/dev/null || true

exec "$@"
