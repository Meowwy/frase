#!/usr/bin/env sh

# Run user scripts, if they exist
for f in /var/www/html/.fly/scripts/*.sh; do
    # Bail out this loop if any script exits with non-zero status code.
    # NOTE: -e must come before the filename; `bash "$f" -e` passes it to the script
    # as a positional argument instead and failures pass silently.
    bash -e "$f" || exit 1
done

if [ $# -gt 0 ]; then
    # If we passed a command, run it as root
    exec "$@"
else
    exec supervisord -c /etc/supervisor/supervisord.conf
fi
