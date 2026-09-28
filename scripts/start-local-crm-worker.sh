#!/usr/bin/env sh
set -eu

# For the current split-container local environment; rerun after container restart.
Z1ERP_WORKER_CONTAINER=${Z1ERP_WORKER_CONTAINER:-z1erp-web}
docker exec --detach --workdir /workspace "$Z1ERP_WORKER_CONTAINER" sh -lc 'mkdir -p storage/framework storage/logs; exec flock -n storage/framework/local-crm-worker.lock php artisan queue:work redis --queue=default --tries=3 --sleep=3 >> storage/logs/local-crm-worker.log 2>&1'
