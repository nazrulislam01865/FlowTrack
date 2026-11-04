#!/usr/bin/env python3
"""Compare matched Order Details baseline runs without treating unmatched samples as speedups.

Usage:
    python3 scripts/order-release-ab-compare.py \
        --input storage/logs/order-details-baseline.jsonl \
        --before ab_before --after ab_blocker_on \
        --output storage/logs/order-release-ab-comparison.md
"""
import argparse
import importlib.util
from collections import defaultdict
from pathlib import Path

base_script = Path(__file__).with_name('order-baseline-report.py')
spec = importlib.util.spec_from_file_location('flowtrack_baseline_report', base_script)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


def comparison(events, before_run, after_run, minimum=5):
    def groups(run_id):
        found = defaultdict(list)
        for event in events:
            if event.get('run_id') != run_id:
                continue
            if base.request_kind(event) is None and event.get('kind') != 'service_span':
                continue
            key = (base.group_key(event), event.get('order_id'))
            found[key].append(event)
        return found

    before, after = groups(before_run), groups(after_run)
    rows = [
        '# FlowTracker - Release A/B matched-run comparison', '',
        f'Before: `{before_run}`; after: `{after_run}`. Minimum observations per side: **{minimum}**.',
        '',
        '| Request or span / Order | Before n | After n | SQL p50 before | SQL p50 after | DB ms p50 before | DB ms p50 after | Latency p95 before | Latency p95 after | Coverage |',
        '|---|---:|---:|---:|---:|---:|---:|---:|---:|---|',
    ]
    matched = 0
    incomplete = 0
    for key in sorted(set(before) | set(after), key=lambda k: (k[0], str(k[1]))):
        b, a = before.get(key, []), after.get(key, [])
        if len(b) >= minimum and len(a) >= minimum:
            status = 'MATCHED'
            matched += 1
        else:
            status = 'INCOMPLETE'
            incomplete += 1
        def q(data, field, percentile):
            return base.fmt(base.percentile([e.get(field) for e in data], percentile))
        name = f'{key[0]} / {key[1] if key[1] is not None else "unclassified"}'
        rows.append('| ' + ' | '.join([
            name.replace('|', '/'), str(len(b)), str(len(a)),
            q(b, 'db_query_count', 50), q(a, 'db_query_count', 50),
            q(b, 'db_time_ms', 50), q(a, 'db_time_ms', 50),
            q(b, 'duration_ms', 95), q(a, 'duration_ms', 95), status,
        ]) + ' |')
    if not before or not after:
        rows += ['', '**NO COMPARABLE DATA:** one or both requested runs are missing.']
    if incomplete:
        rows += ['', f'**NOT SIGNED OFF:** {incomplete} action/order groups have fewer than {minimum} observations on either side.']
    if not matched:
        rows += ['', '**NOT SIGNED OFF:** no adequately sampled matching request/order groups.']
    rows += ['', 'Interpretation: compare only identical order IDs, access roles, workflow states, cache state, and cold/warm conditions. SQL and latency values alone cannot prove correctness.']
    return '\n'.join(rows) + '\n', bool(matched) and incomplete == 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--before', required=True)
    parser.add_argument('--after', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--min-samples', type=int, default=5)
    args = parser.parse_args()
    if args.before == args.after or args.min_samples < 1:
        parser.error('Use distinct run IDs and a positive minimum sample count.')
    if not args.input.is_file():
        parser.error('Baseline file does not exist.')
    events, invalid = base.load(args.input, '')
    result, complete = comparison(events, args.before, args.after, args.min_samples)
    if invalid:
        result += f'\nWarning: {invalid} invalid JSONL records were ignored.\n'
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(result, encoding='utf-8')
    print(f'Wrote {args.output}; coverage {"complete" if complete else "incomplete"}')
    return 0 if complete and invalid == 0 else 2


if __name__ == '__main__':
    raise SystemExit(main())
