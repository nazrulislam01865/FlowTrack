# Inquiry RFQ failure message deduplication — 2026-08-29

The RFQ product workspace now uses one product-scoped failure alert as the human-readable error message.

- The generic Inquiry page banner ignores the `rfqDelivery` error key. Other validation errors still render normally.
- The redesigned RFQ workspace no longer repeats the raw delivery exception.
- A failed supplier row shows only the `Failed` invitation-status badge; no explanatory sentence is rendered beneath it.
- The product-level alert remains the single descriptive failure message and the existing Retry action remains available.
- Both RFQ presenters return `null` for failed-status detail text so future consumers follow the same contract.

No database schema, RFQ sending service, retry behavior, or quotation behavior was changed.
