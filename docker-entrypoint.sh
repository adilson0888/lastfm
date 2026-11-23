#!/bin/sh
set -e

# Ensure the MusicBrainz tag cache directory exists and is writable by Apache,
# then hand over to the standard apache entrypoint (which runs apache2-foreground).
CACHE_DIR="${CACHE_DIR:-/opt/lastfm/cache}"
mkdir -p "$CACHE_DIR"
chown -R www-data:www-data "$CACHE_DIR"

exec docker-php-entrypoint "$@"
