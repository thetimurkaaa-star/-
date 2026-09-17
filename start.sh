#!/bin/sh
set -eu
mkdir -p /app/data
if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ] && [ -n "${BOT_TOKEN:-}" ]; then
  URL="https://${RAILWAY_PUBLIC_DOMAIN}/"
  if [ -n "${WEBHOOK_SECRET:-}" ]; then
    curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" -d "url=${URL}" -d "secret_token=${WEBHOOK_SECRET}" >/dev/null || true
  else
    curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" -d "url=${URL}" >/dev/null || true
  fi
  if [ -n "${CRON_SECRET:-}" ]; then
    ( while sleep 60; do curl -fsS "${URL}?cron=${CRON_SECRET}" >/dev/null || true; done ) &
  fi
fi
exec php -S 0.0.0.0:${PORT:-8080} -t /app
