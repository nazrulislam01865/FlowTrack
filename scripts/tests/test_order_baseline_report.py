"""Contract regression tests for the opt-in Phase 1 report (stdlib only)."""
import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'order-baseline-report.py'
spec = importlib.util.spec_from_file_location('order_baseline_report', SCRIPT)
report = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report)


class BaselineReportTests(unittest.TestCase):
    def test_new_schema_separates_initial_and_livewire_by_component(self):
        initial = {'kind': 'http_request', 'request_kind': 'initial_get', 'order_id': 123,
                   'duration_ms': 50, 'php_cpu_ms': 8, 'db_query_count': 6}
        livewire = {'kind': 'http_request', 'request_kind': 'livewire_update', 'order_id': 123,
                    'duration_ms': 840, 'php_cpu_ms': 500, 'db_query_count': 609, 'request_id': 'abc123',
                    'components': [{'component': 'jobs.order-workflow-section',
                                    'methods': ['loadWorkflowSection']}]}
        span = {'kind': 'service_span', 'span': 'remote_area_match', 'order_id': 123,
                'success': True, 'duration_ms': 4881, 'php_cpu_ms': 730, 'request_id': 'abc123', 'db_query_count': 12}
        result = report.render([initial, livewire, span], 0, 'before')
        self.assertIn('HTTP initial GET - order 123', result)
        self.assertIn('HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 123', result)
        self.assertIn('Service remote_area_match - order 123', result)
        self.assertIn('Workflow Livewire requests with >= 500 SQL queries: **1**', result)
        self.assertIn('abc123', result)
        self.assertIn('remote_area_match (4881.00 ms / 12 SQL)', result)
        self.assertNotIn('**INCOMPLETE:**', result)

    def test_legacy_schema_still_has_coverage_without_false_values(self):
        older = {'kind': 'initial_get', 'order_id': 22, 'duration_ms': 60}
        result = report.render([older], 0, 'legacy')
        self.assertIn('HTTP initial GET - order 22', result)
        self.assertIn('Livewire requests: **0**', result)
        self.assertIn('**INCOMPLETE:**', result)
        self.assertIn('n/a', result)

    def test_empty_run_never_implies_success(self):
        result = report.render([], 0, 'empty')
        self.assertIn('**INCOMPLETE:**', result)
        self.assertIn('No captured events', result)


if __name__ == '__main__':
    unittest.main()
