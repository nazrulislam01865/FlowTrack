# FlowTracker - Release A/B matched-run comparison

Before: `AB_B0`; after: `AB_B1`. Minimum observations per side: **5**.

| Request or span / Order | Before n | After n | SQL p50 before | SQL p50 after | DB ms p50 before | DB ms p50 after | Latency p95 before | Latency p95 after | Coverage |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| HTTP Livewire jobs.index::render / jobs.order-workflow-section::render / 333 | 0 | 1 | n/a | 81.00 | n/a | 111.42 | n/a | 529.04 | INCOMPLETE |
| HTTP Livewire jobs.order-activity-section::loadActivitySection / 308 | 0 | 1 | n/a | 16.00 | n/a | 28.94 | n/a | 46.00 | INCOMPLETE |
| HTTP Livewire jobs.order-activity-section::loadActivitySection / 310 | 0 | 2 | n/a | 16.00 | n/a | 16.95 | n/a | 37.34 | INCOMPLETE |
| HTTP Livewire jobs.order-activity-section::loadActivitySection / 333 | 4 | 8 | 19.00 | 19.00 | 20.46 | 17.93 | 46.90 | 41.19 | INCOMPLETE |
| HTTP Livewire jobs.order-activity-section::loadActivitySection / 334 | 0 | 3 | n/a | 19.00 | n/a | 17.49 | n/a | 42.12 | INCOMPLETE |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection / 308 | 0 | 1 | n/a | 14.00 | n/a | 9.69 | n/a | 19.21 | INCOMPLETE |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection / 310 | 0 | 2 | n/a | 14.00 | n/a | 14.12 | n/a | 33.10 | INCOMPLETE |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection / 333 | 5 | 8 | 17.00 | 17.00 | 15.32 | 16.77 | 38.36 | 37.10 | MATCHED |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection / 334 | 0 | 3 | n/a | 16.00 | n/a | 12.41 | n/a | 39.02 | INCOMPLETE |
| HTTP Livewire jobs.order-products-section::loadProductsSection / 308 | 0 | 1 | n/a | 7.00 | n/a | 39.88 | n/a | 50.45 | INCOMPLETE |
| HTTP Livewire jobs.order-products-section::loadProductsSection / 310 | 0 | 2 | n/a | 7.00 | n/a | 75.78 | n/a | 204.37 | INCOMPLETE |
| HTTP Livewire jobs.order-products-section::loadProductsSection / 333 | 13 | 9 | 11.00 | 11.00 | 8.47 | 10.03 | 42.25 | 54.59 | MATCHED |
| HTTP Livewire jobs.order-products-section::loadProductsSection / 334 | 0 | 3 | n/a | 11.00 | n/a | 10.11 | n/a | 117.15 | INCOMPLETE |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection / 308 | 0 | 1 | n/a | 70.00 | n/a | 67.64 | n/a | 144.36 | INCOMPLETE |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection / 310 | 0 | 2 | n/a | 84.50 | n/a | 149.94 | n/a | 586.07 | INCOMPLETE |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection / 333 | 13 | 8 | 98.00 | 75.00 | 97.39 | 85.73 | 279.44 | 358.30 | MATCHED |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection / 334 | 0 | 3 | n/a | 70.00 | n/a | 83.89 | n/a | 272.87 | INCOMPLETE |
| HTTP initial GET / 308 | 0 | 2 | n/a | 26.00 | n/a | 20.54 | n/a | 90.75 | INCOMPLETE |
| HTTP initial GET / 310 | 0 | 3 | n/a | 27.00 | n/a | 25.18 | n/a | 512.53 | INCOMPLETE |
| HTTP initial GET / 333 | 13 | 9 | 30.00 | 30.00 | 24.21 | 27.13 | 155.09 | 264.36 | MATCHED |
| HTTP initial GET / 334 | 0 | 4 | n/a | 28.00 | n/a | 24.47 | n/a | 316.80 | INCOMPLETE |
| Service artwork_repair / 308 | 0 | 1 | n/a | 12.00 | n/a | 10.72 | n/a | 18.44 | INCOMPLETE |
| Service artwork_repair / 310 | 0 | 2 | n/a | 12.00 | n/a | 25.62 | n/a | 73.77 | INCOMPLETE |
| Service artwork_repair / 333 | 13 | 8 | 14.00 | 14.00 | 15.17 | 17.22 | 30.73 | 39.58 | MATCHED |
| Service artwork_repair / 334 | 0 | 3 | n/a | 12.00 | n/a | 20.79 | n/a | 34.36 | INCOMPLETE |
| Service remote_area_match / 308 | 0 | 3 | n/a | 2.00 | n/a | 1.38 | n/a | 4.78 | INCOMPLETE |
| Service remote_area_match / 310 | 0 | 5 | n/a | 2.00 | n/a | 1.36 | n/a | 15.35 | INCOMPLETE |
| Service remote_area_match / 333 | 26 | 19 | 2.00 | 2.00 | 1.40 | 1.39 | 5.78 | 19.05 | MATCHED |
| Service remote_area_match / 334 | 0 | 7 | n/a | 2.00 | n/a | 1.42 | n/a | 13.44 | INCOMPLETE |
| Service workflow_auto_advance / 308 | 0 | 1 | n/a | 9.00 | n/a | 7.40 | n/a | 12.04 | INCOMPLETE |
| Service workflow_auto_advance / 310 | 0 | 2 | n/a | 20.50 | n/a | 30.77 | n/a | 94.15 | INCOMPLETE |
| Service workflow_auto_advance / 333 | 13 | 8 | 35.00 | 12.00 | 31.71 | 13.44 | 58.83 | 71.66 | MATCHED |
| Service workflow_auto_advance / 334 | 0 | 3 | n/a | 12.00 | n/a | 13.67 | n/a | 20.73 | INCOMPLETE |
| Service workflow_detail_context / 308 | 0 | 1 | n/a | 2.00 | n/a | 0.94 | n/a | 9.10 | INCOMPLETE |
| Service workflow_detail_context / 310 | 0 | 2 | n/a | 2.00 | n/a | 1.72 | n/a | 44.16 | INCOMPLETE |
| Service workflow_detail_context / 333 | 13 | 9 | 2.00 | 2.00 | 1.10 | 1.39 | 20.25 | 32.15 | MATCHED |
| Service workflow_detail_context / 334 | 0 | 3 | n/a | 2.00 | n/a | 1.42 | n/a | 37.43 | INCOMPLETE |
| Service workflow_load_relations / 308 | 0 | 1 | n/a | 23.00 | n/a | 22.29 | n/a | 32.86 | INCOMPLETE |
| Service workflow_load_relations / 310 | 0 | 2 | n/a | 24.00 | n/a | 29.30 | n/a | 84.17 | INCOMPLETE |
| Service workflow_load_relations / 333 | 13 | 9 | 23.00 | 23.00 | 23.10 | 28.04 | 72.85 | 110.47 | MATCHED |
| Service workflow_load_relations / 334 | 0 | 3 | n/a | 20.00 | n/a | 22.92 | n/a | 44.79 | INCOMPLETE |
| Service workflow_sync / 308 | 0 | 1 | n/a | 15.00 | n/a | 18.73 | n/a | 29.38 | INCOMPLETE |
| Service workflow_sync / 310 | 0 | 2 | n/a | 15.00 | n/a | 49.54 | n/a | 108.86 | INCOMPLETE |
| Service workflow_sync / 333 | 13 | 8 | 15.00 | 15.00 | 15.60 | 23.58 | 39.43 | 49.95 | MATCHED |
| Service workflow_sync / 334 | 0 | 3 | n/a | 15.00 | n/a | 19.03 | n/a | 50.59 | INCOMPLETE |

**NOT SIGNED OFF:** 35 action/order groups have fewer than 5 observations on either side.

Interpretation: compare only identical order IDs, access roles, workflow states, cache state, and cold/warm conditions. SQL and latency values alone cannot prove correctness.
