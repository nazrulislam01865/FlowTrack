#!/usr/bin/env bash
# Local-only development runner: both Laravel servers, schedulers and FlowTracker queue worker.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUYER="$(cd "$SCRIPT_DIR/.." && pwd)"
PORTAL=""
MODE="run"
RUN_MODE="all"
PHP_BIN="${PHP_BIN:-php}"
for arg in "$@"; do
    case "$arg" in
        --portal=*) PORTAL="${arg#--portal=}" ;;
        --configure) MODE="configure" ;;
        --check) MODE="check" ;;
        --schedulers-only) RUN_MODE="schedulers" ;;
        --help|-h)
            echo 'Usage: bash scripts/local-supplier-sync.sh [--portal=/absolute/path/to/supplier-portal] [--configure|--check|--schedulers-only]'
            echo 'First run --configure (creates .env backups). Then run without flags to start both apps + schedulers + FlowTracker worker.'
            exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done
if [[ -z "$PORTAL" ]]; then
    if [[ -f "$BUYER/supplier-portal/artisan" ]]; then
        PORTAL="$BUYER/supplier-portal"
    elif [[ -f "$BUYER/../supplier-portal/artisan" ]]; then
        PORTAL="$BUYER/../supplier-portal"
    else
        echo 'Supplier Portal not found. Pass --portal=/path/to/supplier-portal.' >&2
        exit 1
    fi
fi
if [[ ! -f "$BUYER/artisan" || ! -f "$PORTAL/artisan" ]]; then
    echo 'Both directories must be Laravel roots containing artisan.' >&2
    exit 1
fi
PORTAL="$(cd "$PORTAL" && pwd)"
if [[ "$MODE" == 'configure' || "$MODE" == 'check' ]]; then
    python3 "$SCRIPT_DIR/local_bridge_config.py" "$MODE" "$BUYER" "$PORTAL"
    exit 0
fi
python3 "$SCRIPT_DIR/local_bridge_config.py" check "$BUYER" "$PORTAL"
command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "Cannot find PHP binary: $PHP_BIN" >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { echo 'curl is required to check the local servers.' >&2; exit 1; }
for dir in "$BUYER" "$PORTAL"; do
    if [[ ! -f "$dir/vendor/autoload.php" ]]; then
        echo "Missing Composer dependencies in $dir. Run composer install there first." >&2
        exit 1
    fi
done
# Fail instead of Laravel silently switching to 8002 or interfering with running apps.
if [[ "$RUN_MODE" == 'all' ]]; then
for port in 8000 8001; do
    if ! "$PHP_BIN" -r '$s=@stream_socket_server("tcp://127.0.0.1:".$argv[1],$e,$m); if(!$s){exit(1);} fclose($s);' "$port" 2>/dev/null; then
        echo "Port $port is already occupied. Stop the old Laravel server before starting the combined runner." >&2
        exit 1
    fi
done
fi

# Ensure current local .env values are used, even if configuration was cached earlier.
for dir in "$BUYER" "$PORTAL"; do
    if ! (cd "$dir" && "$PHP_BIN" artisan config:clear >/dev/null && "$PHP_BIN" artisan list --raw >/dev/null); then
        echo "Laravel failed to boot in $dir. Check its .env and storage/logs/laravel.log." >&2
        exit 1
    fi
done

PIDS=()
cleanup() {
    trap - EXIT INT TERM
    echo 'Stopping local FlowTracker and Supplier Portal processes...'
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    for pid in "${PIDS[@]}"; do wait "$pid" 2>/dev/null || true; done
}
trap cleanup EXIT INT TERM

start() {
    local dir="$1" label="$2" command="$3" port="${4:-}"
    mkdir -p "$dir/storage/logs"
    local logfile="$dir/storage/logs/local-bridge-${label}.log"
    if [[ "$command" == 'serve' ]]; then
        ( cd "$dir" && exec "$PHP_BIN" artisan serve --host=127.0.0.1 --port="$port" ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'reverb' ]]; then
        ( cd "$dir" && exec "$PHP_BIN" artisan reverb:start --host=127.0.0.1 --port=8080 ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'worker' ]]; then
        # Process supplier/order jobs, but do not send queued customer emails
        # as a side effect of starting a local development environment.
        ( cd "$dir" && exec "$PHP_BIN" artisan queue:work --queue=default,realtime,notifications --sleep=1 --tries=3 --timeout=120 ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'buyer-critical-worker' ]]; then
        ( cd "$dir" && exec "$PHP_BIN" artisan queue:work --queue=supplier-critical --sleep=1 --tries=1 --timeout=45 ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'buyer-brand-worker' ]]; then
        ( cd "$dir" && exec "$PHP_BIN" artisan queue:work --queue=supplier-branding --sleep=1 --tries=1 --timeout=30 ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'buyer-supplier-worker' ]]; then
        # Do not let general notifications/emails block task and order updates.
        ( cd "$dir" && exec "$PHP_BIN" artisan queue:work --queue=supplier-sync --sleep=1 --tries=1 --timeout=120 ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'supplier-rfq-file-worker' ]]; then
        ( cd "$dir" && exec "$PHP_BIN" artisan queue:work --queue=supplier-rfq-files --sleep=1 --tries=3 --timeout=45 ) >> "$logfile" 2>&1 &
    elif [[ "$command" == 'supplier-delivery-worker' ]]; then
        # Large quotation attachments cannot block interactive order/image refresh jobs.
        ( cd "$dir" && exec "$PHP_BIN" artisan queue:work --queue=supplier-deliveries --sleep=1 --tries=1 --timeout=900 ) >> "$logfile" 2>&1 &
    else
        ( cd "$dir" && exec "$PHP_BIN" artisan schedule:work ) >> "$logfile" 2>&1 &
    fi
    PIDS+=("$!")
    echo "$label log: $logfile"
}
if [[ "$RUN_MODE" == 'all' ]]; then
    start "$BUYER" 'flowtracker-web' serve 8000
    start "$PORTAL" 'supplier-web' serve 8001
fi
start "$BUYER" 'flowtracker-scheduler' scheduler
start "$PORTAL" 'supplier-scheduler' scheduler
start "$BUYER" 'flowtracker-worker' worker
start "$BUYER" 'flowtracker-supplier-critical-worker' buyer-critical-worker
start "$BUYER" 'flowtracker-supplier-brand-worker' buyer-brand-worker
start "$BUYER" 'flowtracker-supplier-worker' buyer-supplier-worker
start "$PORTAL" 'supplier-worker' worker
start "$PORTAL" 'supplier-quotation-worker' supplier-delivery-worker
start "$PORTAL" 'supplier-rfq-file-worker' supplier-rfq-file-worker
# Reuse an existing local Reverb service, otherwise start the configured one.
# This check avoids a duplicate WebSocket process on port 8080.
if "$PHP_BIN" -r '$s=@stream_socket_server("tcp://127.0.0.1:8080",$e,$m); if(!$s){exit(1);} fclose($s);' 2>/dev/null; then
    start "$BUYER" 'flowtracker-reverb' reverb
else
    echo 'Existing service detected on Reverb port 8080; verify it uses this FlowTracker APP_KEY.'
fi

# Health-check only servers that this runner started. With --schedulers-only,
# websites run in separate terminals and may legitimately be offline: workers
# must stay alive so the database outboxes can retry when connectivity returns.
if [[ "$RUN_MODE" == 'all' ]]; then
    for port in 8000 8001; do
        ready=0
        for attempt in 1 2 3 4 5 6 7 8 9 10; do
            if curl -fsS --max-time 2 "http://127.0.0.1:${port}/up" >/dev/null 2>&1; then
                ready=1
                break
            fi
            sleep 1
        done
        if [[ "$ready" != 1 ]]; then
            echo "Server on port $port did not become ready. Inspect the local-bridge web logs." >&2
            exit 1
        fi
    done
fi
printf '\nFlowTracker:      http://127.0.0.1:8000\nSupplier Portal: http://127.0.0.1:8001\n'
echo 'Both schedulers, queue workers and Reverb (or existing port 8080 service) have been started. Signed deliveries are event-driven; retries remain automatic.'
if [[ "$RUN_MODE" == 'schedulers' ]]; then
    echo 'Web servers are managed separately; start FlowTracker on 8000 and Supplier Portal on 8001 in their own terminals.'
    echo 'Background workers will keep running if either web server is temporarily unavailable.'
fi
echo 'Leave this terminal open; press Ctrl+C to stop the processes started by this runner.'
while true; do
    for pid in "${PIDS[@]}"; do
        if ! kill -0 "$pid" 2>/dev/null; then
            echo 'A local service exited unexpectedly. Check storage/logs/local-bridge-*.log.' >&2
            exit 1
        fi
    done
    sleep 3
done
