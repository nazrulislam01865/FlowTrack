# FlowTrack Phase 14 load-test report

## Status

**Execution status: pending on a production-like environment.** The source archive does not contain a running dependency-complete FlowTrack deployment, representative MySQL dataset, Redis service or 25-75 distinct load-test accounts. No latency numbers are fabricated in this report.

## Scenario

`tests/Load/phase14-flowtrack.k6.js` exercises authenticated read traffic for:

- Dashboard (heavy read model)
- Orders list
- Inquiries list
- My Task

The script performs a real CSRF-protected login and uses a distinct FlowTrack account per VU because the application intentionally enforces one active session per account.

Profiles:

| Profile | Concurrency |
| --- | --- |
| smoke | 2 VUs |
| expected | ramp 10 -> 25 VUs |
| headroom | ramp 25 -> 50 -> 75 VUs |

Release thresholds:

- HTTP failure rate < 1%
- checks > 99%
- standard page p95 < 500 ms
- dashboard/heavy p95 < 1,000 ms

## Run

```bash
k6 run \
  -e FLOWTRACK_BASE_URL=https://flowtrack.example.com \
  -e FLOWTRACK_LOAD_PROFILE=expected \
  -e FLOWTRACK_LOAD_USERS_FILE=./tests/Load/users.production.json \
  tests/Load/phase14-flowtrack.k6.js
```

Then repeat with `FLOWTRACK_LOAD_PROFILE=headroom`.

## Evidence to record before Phase 14 runtime acceptance

Record the k6 summary, application p95/query metrics, Redis memory/latency, queue depth/delay, MySQL connection count/slow-query log and Reverb reconnect/error rate. Phase 14 runtime acceptance is achieved only after both expected and headroom profiles satisfy the thresholds on representative infrastructure.
