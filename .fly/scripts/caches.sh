#!/usr/bin/env bash

# Only config:cache runs at boot. It bakes env() values into bootstrap/cache/config.php,
# and Fly secrets (APP_KEY, OPENAI_API_KEY, ...) only exist on the running machine --
# caching config at build time would freeze them as null.
#
# route:cache, view:cache and event:cache are derived purely from source code and are
# built into the image instead (see Dockerfile step 4).
/usr/bin/php /var/www/html/artisan config:cache --no-ansi -q
