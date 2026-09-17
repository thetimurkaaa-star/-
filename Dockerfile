FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libcurl4-openssl-dev curl \
    && docker-php-ext-install pdo_sqlite curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
RUN chmod +x /app/start.sh
CMD ["/app/start.sh"]
