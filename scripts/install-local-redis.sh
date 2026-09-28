#!/bin/sh
set -eu

# The normal Sail image includes phpredis. This prepares the split PHP Alpine container.
Z1ERP_REDIS_CONTAINER=${Z1ERP_REDIS_CONTAINER:-z1erp-web}
docker exec "$Z1ERP_REDIS_CONTAINER" sh -lc '
    if php -r "exit(extension_loaded(\"redis\") ? 0 : 1);"; then
        exit 0
    fi
    apk add --no-cache --virtual .z1erp-redis-build $PHPIZE_DEPS
    pecl install redis
    docker-php-ext-enable redis
    apk del .z1erp-redis-build
    php -r "exit(extension_loaded(\"redis\") ? 0 : 1);"
'
