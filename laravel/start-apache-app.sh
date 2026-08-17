#!/bin/bash
set -e

# optimization step, needs to be run here to access the container's
# environment
php artisan config:cache

# run migrations in isolated mode in case of distributed deployment
php artisan migrate --isolated

# start the queue worker as a background process
# logs will be sent to the laravel log file
php artisan queue:work -n &

# start the apache process in the foreground
apache2-foreground