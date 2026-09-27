FROM php:8.5-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl gd zip bcmath pcntl @composer && apt-get update && apt-get install -y --no-install-recommends unzip git default-mysql-client && rm -rf /var/lib/apt/lists/*
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/craft.ini
WORKDIR /app
