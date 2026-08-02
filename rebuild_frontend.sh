#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")"
./scripts/frontend-install-and-build.sh
php artisan optimize:clear
echo "FlowTrack frontend rebuild completed."
