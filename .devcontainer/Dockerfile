# DesignTrace dev image: official Dolibarr image + dev tooling
ARG DOLIBARR_VERSION=21.0.2
FROM dolibarr/dolibarr:${DOLIBARR_VERSION}

# Basic tools for working inside the container
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip less nano \
    && rm -rf /var/lib/apt/lists/*

# Xdebug for step debugging from VS Code (triggered per request, so normal
# page loads stay fast)
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && { \
        echo "xdebug.mode=debug,develop"; \
        echo "xdebug.start_with_request=trigger"; \
        echo "xdebug.client_host=localhost"; \
        echo "xdebug.client_port=9003"; \
        echo "xdebug.log_level=0"; \
    } > /usr/local/etc/php/conf.d/zz-xdebug.ini

# Composer, for PHP_CodeSniffer and other dev dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
