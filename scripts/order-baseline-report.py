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


def request_kind(entry):
    """Read both the corrected event schema and Oct 15 legacy baseline logs."""
    kind = entry.get('kind')
    if kind == 'http_request':
        return entry.get('request_kind') or ('livewire_update' if entry.get('components') else 'initial_get')
    return kind if kind in ('initial_get', 'livewire_update') else None


def group_key(entry):
    subtype = request_kind(entry)
    if subtype == 'initial_get':
        return 'HTTP initial GET'
    if subtype == 'livewire_update':
        components = []
        for component in entry.get('components') or []:
            if not isinstance(component, dict):
                continue
            component_name = str(component.get('component') or 'unknown')
            methods = ','.join(str(m) for m in (component.get('methods') or [])) or 'render'
            components.append(f'{component_name}::{methods}')
        return 'HTTP Livewire ' + ' / '.join(components or ['unclassified'])
    if entry.get('kind') == 'service_span':
        return 'Service ' + str(entry.get('span') or 'unknown')
    return str(entry.get('kind') or 'unknown')


def render(events, rejected, run_id):
    sections = ['# FlowTracker Order Details - Phase 1 Baseline', '']
    sections.append(f'Run: `{run_id or "all"}`. Samples captured: **{len(events)}**. Invalid JSONL records: **{rejected}**.')
    sections += ['', '## Server performance', '', '| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |', '|---|---:|---:|---:|---:|---:|---:|---:|']
    grouped = defaultdict(list)
    for event in events:
        grouped[(group_key(event), event.get('order_id'))].append(event)
    for (kind, order_id), rows in sorted(grouped.items(), key=lambda pair: (str(pair[0][0]), str(pair[0][1]))):
        value = lambda key: [row.get(key) for row in rows]
        name = f'{kind} - order {order_id}' if order_id is not None else kind
        sections.append('| ' + ' | '.join([name.replace('|', '/'), str(len(rows)), fmt(percentile(value('duration_ms'), 50)),
            fmt(percentile(value('duration_ms'), 95)), fmt(percentile(value('php_cpu_ms'), 50)), fmt(percentile(value('db_query_count'), 50)),
            fmt(percentile(value('db_time_ms'), 50)), fmt(percentile(value('peak_php_memory_mb'), 95))]) + ' |')
    if not grouped:
        sections.append('| No captured events | 0 | n/a | n/a | n/a | n/a | n/a | n/a |')

    http = [e for e in events if request_kind(e) is not None]
    initial = [e for e in http if request_kind(e) == 'initial_get']
    livewire = [e for e in http if request_kind(e) == 'livewire_update']
    high_query = [e for e in livewire if isinstance(e.get('db_query_count'), (int, float))
                  and e['db_query_count'] >= 500
                  and any(c.get('component') == 'jobs.order-workflow-section'
                          for c in (e.get('components') or []) if isinstance(c, dict))]
    spans = [e for e in events if e.get('kind') == 'service_span']
    sections += ['', '## Baseline capture coverage', '',
                 f'Initial Order Details requests: **{len(initial)}**; Livewire requests: **{len(livewire)}**; '
                 f'service spans: **{len(spans)}**.',
                 f'Workflow Livewire requests with >= 500 SQL queries: **{len(high_query)}**.']
    if not initial or not livewire:
        sections.append('**INCOMPLETE:** initial GET and Livewire updates must both be present before Phase 1 sign-off.')
    if not spans:
        sections.append('**INCOMPLETE:** no service spans captured. Check the deployed code revision and diagnostic flag.')
    if high_query:
        sections.append('**INVESTIGATE:** >= 500-query Workflow requests occurred; compare their service spans and SQL fingerprints.')
    if high_query:
        span_by_request = defaultdict(list)
        for span in spans:
            if span.get('request_id'):
                span_by_request[span['request_id']].append(span)
        sections += ['', '### High-query Workflow request correlation', '',
                     '| Request ID | Order ID | SQL count | Duration (ms) | Related diagnostic spans |',
                     '|---|---:|---:|---:|---|']
        for event in high_query[:30]:
            request_id = event.get('request_id')
            related = span_by_request.get(request_id, []) if request_id else []
            linked = ', '.join(f"{span.get('span', '?')} ({fmt(span.get('duration_ms'))} ms / "                                f"{span.get('db_query_count', 'n/a')} SQL)" for span in related) or 'n/a'
            sections.append('| ' + ' | '.join([str(request_id or 'legacy/no ID'),
                str(event.get('order_id') or 'n/a'), str(event.get('db_query_count', 'n/a')),
                fmt(event.get('duration_ms')), linked.replace('|', '/')]) + ' |')
        if len(high_query) > 30:
            sections.append(f'Only first 30 of {len(high_query)} anomalous requests shown. Inspect JSONL for the rest.')
    sections += ['', '## Instrumented service spans (diagnostic only)', '',
                 '| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |',
                 '|---|---:|---:|---:|---:|---:|---:|---:|']
    span_groups = defaultdict(list)
    for event in spans:
        span_groups[event.get('span') or 'unknown'].append(event)
    for span, rows in sorted(span_groups.items()):
        sections.append('| ' + ' | '.join([str(span).replace('|', '/'), str(len(rows)),
            fmt(percentile([e.get('duration_ms') for e in rows], 50)),
            fmt(percentile([e.get('duration_ms') for e in rows], 95)),
            fmt(percentile([e.get('php_cpu_ms') for e in rows], 50)),
            fmt(percentile([e.get('db_query_count') for e in rows], 50)),
            fmt(percentile([e.get('db_time_ms') for e in rows], 50)),
            str(sum(e.get('success') is False for e in rows))]) + ' |')
    if not span_groups:
        sections.append('| No captured spans | 0 | n/a | n/a | n/a | n/a | n/a | 0 |')

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
        '- PHP CPU time uses process-local getrusage() and may be unavailable; it excludes SQL server, queue workers and browser CPU.',
        '- Service spans can overlap/nest within HTTP requests; never add their totals to request duration or request CPU.',
        '- Old JSONL request kinds initial_get/livewire_update are supported; new schema uses kind=http_request and request_kind.',
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
