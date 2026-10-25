#!/bin/bash
# Runs on Railway before each new deployment goes live (see railway.json).
set -euo pipefail

php artisan migrate --force

# Demo products, categories and sellers. Only for a test server, and only once: it resets the demo stock.
# Set SEED_DEMO_DATA=true for the first deployment, then remove the variable.
if [ "${SEED_DEMO_DATA:-}" = "true" ]; then
  php artisan db:seed --force
fi

# Delivery times for the next days. Safe to repeat: it creates each window once.
php artisan slots:generate
