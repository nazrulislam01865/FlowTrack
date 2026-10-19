#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
exec php artisan queue:work \
    --queue=realtime,notifications,emails,default \
    --sleep=1 \
    --tries=3 \
    --timeout=90 \
    --max-time=3600
