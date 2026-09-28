#!/bin/sh
set -eu

# For the current split-container local environment; rerun after container restart.
Z1ERP_SCHEDULER_CONTAINER=${Z1ERP_SCHEDULER_CONTAINER:-z1erp-web}
docker exec --detach --workdir /workspace "$Z1ERP_SCHEDULER_CONTAINER" sh -lc 'mkdir -p storage/framework storage/logs; exec flock -n storage/framework/local-scheduler.lock php artisan schedule:work >> storage/logs/local-scheduler.log 2>&1'
