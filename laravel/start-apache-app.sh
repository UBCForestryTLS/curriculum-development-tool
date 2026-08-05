#!/bin/bash

php artisan migrate --isolated

# TODO: Create log file for queue and redirect output to docker?
# start the queue worker as a background process
php artisan queue:work &

# start the apache process in the foreground
apache2-foreground