#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "$0")/.."

command -v node >/dev/null 2>&1 || { echo "Node.js is required to build FlowTrack assets." >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "npm is required to build FlowTrack assets." >&2; exit 1; }

if [[ -f package-lock.json ]]; then
    npm ci
else
    npm install
fi

npm run build

echo "FlowTrack frontend assets built and verified."
