#!/bin/sh
set -eu

# Railpack provides FrankenPHP and /Caddyfile, rooted at /app/public.
# Keep migrations in Railway's pre-deploy step, then serve concurrent requests.
# Four bounded PHP threads let preview I/O overlap on the 1 CPU web service.
export FRANKENPHP_CONFIG="${FRANKENPHP_CONFIG:-num_threads 4}"

php artisan optimize --no-interaction
php artisan inertia:start-ssr --no-interaction &
exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile
