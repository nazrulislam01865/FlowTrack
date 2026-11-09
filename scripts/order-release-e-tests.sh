#!/bin/sh
# Run targeted Release A-E regression tests without touching production data.
set -eu

for test in \
  OrderWorkflowReleaseABFastPathTest \
  OrderWorkflowPhase4MaintenanceOptimizationTest \
  OrderWorkflowPhase5SummaryExistsOptimizationTest \
  OrderWorkflowSectionQueryDeduplicationPerformanceTest \
  OrderWorkflowBatchSyncPerformanceTest \
  SupplierTaskPhaseAdvanceAuthorityTest \
  OrderWorkflowStageAdvanceReplayGuardTest \
  OrderWorkflowSummaryReadModelPerformanceTest \
  OrderWorkflowSampleBranchRegressionTest \
  OrderDetailsReleaseCDSummaryOptimizationTest \
  OrderPhase3RealtimeContractTest \
  OrderOverviewSummaryRealtimeRegressionTest \
  OrderDetailsFullIsolationPerformanceTest \
  OrderRedoImplementationTest
do
  printf '\n=== %s ===\n' "$test"
  php artisan test "--filter=$test"
done

node tests/JavaScript/order-phase3-routing.mjs
node tests/JavaScript/order-phase3-transport.mjs
python3 -m unittest discover -s scripts/tests
printf '\nRelease E targeted test commands completed successfully.\n'
