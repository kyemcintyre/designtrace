# DesignTrace dev image: official Dolibarr image + dev tooling
ARG DOLIBARR_VERSION=24.0.2-php8.2
FROM dolibarr/dolibarr:${DOLIBARR_VERSION}

# Older Dolibarr images (21.x) are based on Debian 11 (bullseye), which is end of
# life and has moved to archive.debian.org. Point apt there so this step and
# the devcontainer features (Node, GitHub CLI) can install packages. Newer
# images skip this step.
RUN . /etc/os-release \
    && if [ "$VERSION_CODENAME" = "bullseye" ]; then \
        rm -f /etc/apt/sources.list.d/*; \
        { \
            echo "deb http://archive.debian.org/debian bullseye main"; \
            echo "deb http://archive.debian.org/debian-security bullseye-security main"; \
        } > /etc/apt/sources.list; \
        echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi

# Basic tools for working inside the container
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip less nano \
    && rm -rf /var/lib/apt/lists/*

# Xdebug for step debugging from VS Code (triggered per request, so normal
# page loads stay fast). install-php-extensions brings in the compilers
# that the slim PHP base image leaves out, then removes them again.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug \
    && { \
        echo "xdebug.mode=debug,develop"; \
        echo "xdebug.start_with_request=trigger"; \
        echo "xdebug.client_host=localhost"; \
        echo "xdebug.client_port=9003"; \
        echo "xdebug.log_level=0"; \
    } > /usr/local/etc/php/conf.d/zz-xdebug.ini

# Composer, for PHP_CodeSniffer and other dev dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
