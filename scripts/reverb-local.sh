#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

cat <<'INFO'
FlowTrack local Reverb server
- Laravel app: start separately with `php artisan serve`
- Queue worker: start separately with `./scripts/queue-worker.sh`
- Vite: run `npm run dev` (or `npm run build` once)
- Reverb: this terminal listens on 127.0.0.1:8080
INFO

exec php artisan reverb:start --host=127.0.0.1 --port=8080 --debug
