FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
RUN mkdir -p /app/data && chmod +x /app/start.sh
CMD ["/app/start.sh"]
