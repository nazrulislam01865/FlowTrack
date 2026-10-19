# Order Summary Report — 2026-08-25

Implemented from the approved `preview (6).html` design without changing the page structure or report columns.

## Navigation

Report → Order Summary

Routes:
- `GET /order-summary-report` → `order-summary.report`
- `GET /order-summary-report/export` → `order-summary.export`

Both use existing Report/Order permissions. Excel export additionally requires `reports.export`.

## Main files

- `app/Services/OrderSummaryReportService.php`
  - permission-scoped Order query
  - filters and quick counts
  - workflow/date field mapping
  - single-sheet XLSX export
- `app/Livewire/Reports/OrderSummary.php`
  - filter state, quick filters and pagination
- `resources/views/livewire/reports/order-summary.blade.php`
  - approved report UI
- `resources/css/modules/reports/order-summary.css`
  - approved report styling, namespaced to the report
- `app/Http/Controllers/OrderSummaryReportController.php`
- `app/Http/Controllers/OrderSummaryExportController.php`
- `resources/views/pages/order-summary-report.blade.php`
- `resources/views/layouts/partials/sidebar.blade.php`
- `routes/web.php`

## Field mapping

| Report field | FlowTrack source |
| --- | --- |
| Supplier | Active Order-item suppliers; falls back to legacy Order supplier |
| Warehouse | `flow_jobs.warehouse` |
| Order No. | Reference Order number; falls back to FlowTrack Order number |
| Received Date | `received_date`; falls back to created date |
| Urgent or Not | Shipment urgency selection |
| Quantity | Sum of active Order-item quantities; falls back to Order quantity |
| Material | Active Order-item product/material names |
| ERP Approval Date | Client approval workflow event; falls back to Client ERP/Approval task completion |
| Special Orders | Supplier instruction |
| Sample/Swatch Sent Date | Sample-required workflow event / Sample task start |
| Sample/Swatch Confirmed Date | Sample Approval task completion |
| Revise / Sample Confirm Date | Latest artwork revision event; otherwise sample confirmation |
| Supplier Delivery Date | Estimated delivery date; falls back to requested delivery date |
| Supplier Reply | Supplier-reply activity metadata/description when recorded; otherwise Awaiting reply |

## Excel

The Download Excel action exports the full currently filtered result to one `.xlsx` worksheet. It uses the same 14 columns, row-state shading, yellow Supplier Reply cells, wrapped headers, freeze panes, filtering and landscape print setup.

## Cache/build

No database migration is required. Source CSS is imported by the final theme composition root, and the current compiled theme asset is updated in the packaged project so it works immediately.
