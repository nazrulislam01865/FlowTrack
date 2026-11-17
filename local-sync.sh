#!/usr/bin/env bash
# Convenience entry point when working from Supplier Portal instead of FlowTracker.
set -euo pipefail
PORTAL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUYER=""
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --buyer=*) BUYER="${arg#--buyer=}" ;;
        *) ARGS+=("$arg") ;;
    esac
done
if [[ -z "$BUYER" ]]; then
    if [[ -f "$PORTAL/../artisan" ]]; then
        BUYER="$PORTAL/.."
    elif [[ -f "$PORTAL/../FlowTracker/artisan" ]]; then
        BUYER="$PORTAL/../FlowTracker"
    else
        echo 'FlowTracker not found. Run: bash local-sync.sh --buyer=/path/to/FlowTracker --configure' >&2
        exit 1
    fi
fi
if [[ ! -f "$BUYER/scripts/local-supplier-sync.sh" ]]; then
    echo 'Install the matching FlowTracker update first (missing scripts/local-supplier-sync.sh).' >&2
    exit 1
fi
exec bash "$BUYER/scripts/local-supplier-sync.sh" "--portal=$PORTAL" "${ARGS[@]}"
