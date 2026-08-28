#!/usr/bin/env bash

# Migrations cannot move to a Fly [deploy] release_command: that runs in a temporary
# machine which, per Fly's docs, has "no volumes attached". Our SQLite database lives on
# the storage_dir volume, so a release machine would migrate a throwaway file instead.
# It therefore stays at boot, after 00_storage_init.sh has ensured the volume is set up.
/usr/bin/php /var/www/html/artisan migrate --force
