#!/usr/bin/env python3
"""Release E read-only performance/acceptance gate for Order Details.

Does not mutate the application, infer browser behavior from server logs, or
claim production sign-off when comparable samples or manual checks are missing.
"""
import argparse
import importlib.util
import json
from collections import defaultdict
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    'flowtrack_order_baseline', Path(__file__).with_name('order-baseline-report.py')
)
baseline = importlib.util.module_from_spec(spec)
spec.loader.exec_module(baseline)

SECTIONS = ('initial', 'products', 'workflow', 'attachments', 'activity')
METHODS = {
    'jobs.order-products-section': ('products', 'loadProductsSection'),
    'jobs.order-workflow-section': ('workflow', 'loadWorkflowSection'),
    'jobs.order-attachments-section': ('attachments', 'loadAttachmentsSection'),
    'jobs.order-activity-section': ('activity', 'loadActivitySection'),
}
MANUAL_GATES = {
    'automated_regressions': 'Laravel, Python and JS regression suites completed',
    'vite_build_and_audit': 'Frontend build and npm audit reviewed',
    'workflow_repair_and_advance': 'Workflow repair, auto-advance, holds and evidence rules verified',
    'supplier_and_concurrency': 'Supplier callbacks, retry/reconnect, concurrent task actions verified',
    'same_order_refresh': 'Same-order task, document, product and activity updates refresh correctly',
    'unrelated_order_no_refresh': 'Changes to a different order cause zero Order Details refreshes',
    'permission_revocation': 'Membership removal reauthorizes and does not expose protected data',
    'lazy_and_fallback': 'Unloaded sections stay lazy; unknown events and reconnect fall back safely',
    'ui_parity': 'Order Details visuals and actions remain unchanged across devices and roles',
    'rollback_drill': 'Feature-flag rollback and pre-release code restoration rehearsed',
}


def identify_request(event):
    """Classify only unambiguous HTTP requests (never double-count bundled Livewire)."""
    kind = baseline.request_kind(event)
    if kind == 'initial_get':
        return 'initial'
    if kind != 'livewire_update':
        return None
    components = event.get('components') or []
    if not isinstance(components, list) or len(components) != 1 or not isinstance(components[0], dict):
        return None
    component = components[0]
    match = METHODS.get(component.get('component'))
    if not match or match[1] not in (component.get('methods') or []):
        return None
    return match[0]


def manual_result(checklist):
    if checklist is None:
        return 'INCOMPLETE', ['No manual/browser acceptance attestation provided.']
    if not isinstance(checklist, dict) or not all(
        isinstance(checklist.get(field), str) and checklist[field].strip()
        for field in ('environment', 'reviewer', 'tested_at')
    ):
        return 'INCOMPLETE', ['Manual attestation requires environment, reviewer and tested_at.']
    checks = checklist.get('checks')
    if not isinstance(checks, dict):
        return 'INCOMPLETE', ['Missing manual checks map.']
    failures, missing = [], []
    for key, description in MANUAL_GATES.items():
        result = checks.get(key)
        if not isinstance(result, dict) or result.get('passed') is None or not str(result.get('evidence') or '').strip():
            missing.append(f'{key}: {description} (result or evidence missing)')
        elif result.get('passed') is not True:
            failures.append(f'{key}: {description} (reported failure)')
    return ('FAIL' if failures else 'INCOMPLETE' if missing else 'PASS'), failures + missing


def evaluate(events, before_run, after_run, order_ids, manual=None,
             minimum=5, p95_tolerance=20.0, db_tolerance=20.0, min_orders=3, invalid_records=0):
    if before_run == after_run:
        raise ValueError('Before and after run IDs must differ.')
    order_ids = list(dict.fromkeys(int(x) for x in order_ids))
    if minimum < 1 or min_orders < 1 or p95_tolerance < 0 or db_tolerance < 0:
        raise ValueError('Sample counts must be positive and tolerances nonnegative.')

    grouped = defaultdict(list)
    errors = [f"Invalid JSONL records: {invalid_records}."] if invalid_records else []
    for item in events:
        if item.get('run_id') not in (before_run, after_run):
            continue
        order_id = item.get('order_id')
        if order_id not in order_ids:
            continue
        if (item.get('success') is False or
                isinstance(item.get('status'), int) and item['status'] >= 400):
            errors.append(f"Failed {item.get('kind')} event on order {order_id} (run {item.get('run_id')}).")
        section = identify_request(item)
        if section is not None:
            grouped[(item['run_id'], order_id, section)].append(item)

    rows, missing, failures = [], [], list(errors)
    for order_id in order_ids:
        for section in SECTIONS:
            b = grouped[(before_run, order_id, section)]
            a = grouped[(after_run, order_id, section)]
            query_b = baseline.percentile([v.get('db_query_count') for v in b], 50)
            query_a = baseline.percentile([v.get('db_query_count') for v in a], 50)
            db_b = baseline.percentile([v.get('db_time_ms') for v in b], 50)
            db_a = baseline.percentile([v.get('db_time_ms') for v in a], 50)
            p95_b = baseline.percentile([v.get('duration_ms') for v in b], 95)
            p95_a = baseline.percentile([v.get('duration_ms') for v in a], 95)
            is_complete = len(b) >= minimum and len(a) >= minimum
            if not is_complete:
                status = 'INCOMPLETE'
                missing.append(f'Order {order_id} / {section}: only {len(b)} before / {len(a)} after samples (min {minimum}).')
            else:
                before_users = {v.get('user_id') for v in b}
                after_users = {v.get('user_id') for v in a}
                if len(before_users) != 1 or before_users != after_users or None in before_users:
                    status = 'INCOMPLETE'
                    missing.append(f'Order {order_id} / {section}: baseline users differ or are unknown.')
                elif None in (query_b, query_a, db_b, db_a, p95_b, p95_a):
                    status = 'INCOMPLETE'
                    missing.append(f'Order {order_id} / {section}: query/DB/latency fields missing.')
                else:
                    bad = []
                    if query_a > query_b:
                        bad.append(f'SQL p50 {query_b:.2f} -> {query_a:.2f}')
                    if db_a > db_b * (1 + db_tolerance / 100):
                        bad.append(f'DB p50 {db_b:.2f} -> {db_a:.2f} ms')
                    if p95_a > p95_b * (1 + p95_tolerance / 100):
                        bad.append(f'latency p95 {p95_b:.2f} -> {p95_a:.2f} ms')
                    if section == 'workflow' and any(
                        isinstance(v.get('db_query_count'), (int, float)) and v['db_query_count'] >= 500
                        for v in a
                    ):
                        bad.append('workflow request exceeded 499 SQL queries')
                    status = 'FAIL' if bad else 'PASS'
                    if bad:
                        failures.append(f"Order {order_id} / {section}: {', '.join(bad)}.")
            delta = 'n/a' if query_b is None or query_a is None else f'{query_a - query_b:+.2f}'
            rows.append('| ' + ' | '.join([
                str(order_id), section, str(len(b)), str(len(a)),
                baseline.fmt(query_b), baseline.fmt(query_a), delta,
                baseline.fmt(db_b), baseline.fmt(db_a),
                baseline.fmt(p95_b), baseline.fmt(p95_a), status,
            ]) + ' |')
    if len(order_ids) < min_orders:
        missing.append(f'Only {len(order_ids)} representative order(s); require at least {min_orders} distinct orders.')
    performance = 'FAIL' if failures else 'INCOMPLETE' if missing else 'PASS'
    manual_status, manual_issues = manual_result(manual)
    overall = 'FAIL' if 'FAIL' in (performance, manual_status) else 'INCOMPLETE' if 'INCOMPLETE' in (performance, manual_status) else 'PASS'

    report = [
        '# FlowTracker Order Details - Release E verification gate', '',
        f'Before: `{before_run}` / After: `{after_run}`. Required samples: {minimum} per order/section/run.',
        f'Distinct representative orders: {len(order_ids)} (required {min_orders}).',
        f'Allowances: SQL p50 no regression; DB p50 +{db_tolerance:g}%; request p95 +{p95_tolerance:g}%.',
        '', f'**Performance gate: {performance}**  ', f'**Manual/functional gate: {manual_status}**  ',
        f'**Overall release gate: {overall}**', '',
        '| Order | Request | B0 n | B1 n | SQL p50 B0 | SQL p50 B1 | SQL delta | DB p50 B0 ms | DB p50 B1 ms | p95 B0 ms | p95 B1 ms | Result |',
        '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|',
        *rows,
    ]
    if failures:
        report += ['', '## Performance failures', *[f'- {v}' for v in failures]]
    if missing:
        report += ['', '## Missing or mismatched performance evidence', *[f'- {v}' for v in missing]]
    if manual_issues:
        report += ['', '## Manual checks requiring attention', *[f'- {v}' for v in manual_issues]]
    report += [
        '', '## Limits of this gate',
        '- Server baseline logs cannot verify browser repaint, authorization changes, supplier delivery, or realtime request suppression. Manual checks are separately required.',
        '- Run IDs and matching users are checked, but workflow state, cache temperature, hardware, and data snapshots must be held equivalent by the tester.',
        '- p95 with small samples is noisy. A reported regression needs investigation and repeat measurements; do not automatically waive it.',
        '- This tool is read-only. A PASS is a recorded release gate, not proof that production has been deployed or monitored.',
        '',
    ]
    return '\n'.join(report), overall


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--before', required=True)
    parser.add_argument('--after', required=True)
    parser.add_argument('--orders', type=int, nargs='+', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--manual', type=Path, help='Completed staging acceptance attestation JSON')
    parser.add_argument('--min-samples', type=int, default=5)
    parser.add_argument('--max-db-regression-percent', type=float, default=20)
    parser.add_argument('--max-p95-regression-percent', type=float, default=20)
    args = parser.parse_args()
    if not args.input.is_file():
        parser.error('Input baseline JSONL does not exist.')
    if args.manual and not args.manual.is_file():
        parser.error('Manual attestation file does not exist.')
    try:
        manual = json.loads(args.manual.read_text(encoding='utf-8')) if args.manual else None
        events, invalid = baseline.load(args.input, '')
        report, status = evaluate(
            events, args.before, args.after, args.orders, manual, args.min_samples,
            args.max_p95_regression_percent, args.max_db_regression_percent,
            invalid_records=invalid,
        )
    except (ValueError, TypeError, json.JSONDecodeError) as exc:
        parser.error(str(exc))
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(report, encoding='utf-8')
    print(f'Wrote {args.output}; Release E {status}')
    return 0 if status == 'PASS' else 1 if status == 'FAIL' else 2


if __name__ == '__main__':
    raise SystemExit(main())
