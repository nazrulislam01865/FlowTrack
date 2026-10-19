#!/usr/bin/env python3
"""Summarize opt-in FlowTracker Order Details baseline JSONL, never invent data.

Usage:
  python3 scripts/order-baseline-report.py --input storage/logs/order-details-baseline.jsonl \
    --run-id before --output /tmp/order-baseline-before.md
"""
import argparse
import json
import math
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from statistics import median

METRICS = ('duration_ms', 'db_query_count', 'db_time_ms', 'peak_php_memory_mb',
           'queue_delay_ms', 'request_bytes', 'response_bytes')


def percentile(values, p):
    values = sorted(float(v) for v in values if isinstance(v, (int, float)) and not isinstance(v, bool))
    if not values:
        return None
    index = (len(values) - 1) * p / 100
    lo, hi = math.floor(index), math.ceil(index)
    return values[lo] + (values[hi] - values[lo]) * (index - lo)


def fmt(number):
    return 'n/a' if number is None else f'{number:.2f}'


def iso(s):
    try:
        return datetime.fromisoformat(s.replace('Z', '+00:00'))
    except (AttributeError, ValueError):
        return None


def load(path, run_id):
    events = []
    rejected = 0
    for line in path.read_text().splitlines():
        try:
            item = json.loads(line)
            if not isinstance(item, dict):
                rejected += 1
                continue
            if run_id and item.get('run_id') != run_id:
                continue
            events.append(item)
        except json.JSONDecodeError:
            rejected += 1
    return events, rejected


def group_key(entry):
    kind = entry.get('kind') or 'unknown'
    if kind == 'http_request':
        subtype = entry.get('kind')
        if entry.get('components'):
            subtype = ' / '.join(','.join((component.get('methods') or ['render'])) for component in entry['components'])
            subtype = 'livewire ' + subtype
        else:
            subtype = entry.get('route', 'initial GET') if entry.get('status') != 0 else 'GET failed'
        return f'HTTP {subtype}'
    return str(kind)


def render(events, rejected, run_id):
    sections = ['# FlowTracker Order Details - Phase 1 Baseline', '']
    sections.append(f'Run: `{run_id or "all"}`. Samples captured: **{len(events)}**. Invalid JSONL records: **{rejected}**.')
    sections += ['', '## Server performance', '', '| Action | n | Duration P50 (ms) | Duration P95 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |', '|---|---:|---:|---:|---:|---:|---:|']
    grouped = defaultdict(list)
    for event in events:
        grouped[(group_key(event), event.get('order_id'))].append(event)
    for (kind, order_id), rows in sorted(grouped.items(), key=lambda pair: (str(pair[0][0]), str(pair[0][1]))):
        value = lambda key: [row.get(key) for row in rows]
        name = f'{kind} - order {order_id}' if order_id is not None else kind
        sections.append('| ' + ' | '.join([name.replace('|', '/'), str(len(rows)), fmt(percentile(value('duration_ms'), 50)),
            fmt(percentile(value('duration_ms'), 95)), fmt(percentile(value('db_query_count'), 50)),
            fmt(percentile(value('db_time_ms'), 50)), fmt(percentile(value('peak_php_memory_mb'), 95))]) + ' |')
    if not grouped:
        sections.append('| No captured events | 0 | n/a | n/a | n/a | n/a | n/a |')

    queue_delays = [e.get('queue_delay_ms') for e in events if e.get('kind') == 'supplier_queue_started']
    sections += ['', '## Supplier queue and delivery', '',
                 f'Queue delay P50/P95 (ms): **{fmt(percentile(queue_delays,50))} / {fmt(percentile(queue_delays,95))}**.']
    marks = defaultdict(list)
    for event in events:
        if event.get('kind') == 'supplier_pending' and (stamp := iso(event.get('timestamp'))) is not None:
            marks[event.get('order_id')].append(stamp)
    end_to_end = []
    for event in events:
        if event.get('kind') != 'supplier_delivery_attempt' or event.get('applied') is not True:
            continue
        finish = iso(event.get('timestamp'))
        if finish is None:
            continue
        candidates = [mark for mark in marks.get(event.get('order_id'), []) if mark <= finish]
        if candidates:
            end_to_end.append((finish - max(candidates)).total_seconds() * 1000)
    sections += [f'Observed most-recent pending mark to applied delivery P50/P95 (ms): **{fmt(percentile(end_to_end,50))} / {fmt(percentile(end_to_end,95))}** (matched {len(end_to_end)} deliveries).',
        'Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.',
        '', '## Slowest SQL template fingerprints (no SQL text or bindings)', '',
        '| Hash | Count | Total DB time (ms) |', '|---|---:|---:|']
    shapes = defaultdict(lambda: [0, 0.0])
    for e in events:
        for row in e.get('top_query_shapes', []):
            shapes[row.get('hash', '?')][0] += int(row.get('count', 0))
            shapes[row.get('hash', '?')][1] += float(row.get('time_ms', 0))
    for shape, (count, elapsed) in sorted(shapes.items(), key=lambda item: -item[1][1])[:20]:
        sections.append(f'| `{shape}` | {count} | {elapsed:.2f} |')
    if not shapes:
        sections.append('| No HTTP query-shape samples | 0 | n/a |')
    failures = [e for e in events if e.get('success') is False or (isinstance(e.get('status'), int) and e['status'] >= 400)]
    sections += ['', f'**Failed actions/requests recorded:** {len(failures)}.',
        '', '## Interpretation notes', '',
        '- Request timings begin in the web performance middleware; they exclude some PHP bootstrap work. Browser navigation entries cover end-to-end GET.',
        '- Supplier POST handling is timed independently of the web middleware; no request payloads or authentication secrets are recorded.',
        '- `task_status_mutation` can be nested inside `task_workflow_action`; do not sum these durations.',
        '- Peak PHP memory is a process/request memory high-water mark (not precise per-component allocation).',
        '- Browser visual readiness/FCP/layout shift measurements are exported separately with `__flowOrderBaseline.print()`; they are not inferred from server logs.',
        '- A server with no configured database, queue worker, signed supplier portal or authenticated browser cannot supply live measurements.',
        '- Repeat all scenarios in the same environment, logged-in user, browser, and data set. Record at least five repeats per order size, warm and cold separately.', '']
    return '\n'.join(sections)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--run-id', default='')
    args = parser.parse_args()
    if not args.input.is_file():
        parser.error('JSONL file is missing. Enable instrumentation and execute test sessions first.')
    events, invalid = load(args.input, args.run_id)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(render(events, invalid, args.run_id), encoding='utf-8')
    print(f'Wrote {args.output} ({len(events)} events)')


if __name__ == '__main__':
    main()
