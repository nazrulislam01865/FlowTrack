# Dashboard stage card -> Orders table filter parity

Dashboard workflow-stage cards now carry the active Today / 7 days / 30 days period into the Orders table. The deep link also preserves the Dashboard Client and Team scope.

The Dashboard counts use operational activity (`flow_jobs.updated_at`), so dashboard-origin Orders deep links use the same field instead of the Orders page's normal created-date semantics. This keeps the card count and resulting table population aligned.
