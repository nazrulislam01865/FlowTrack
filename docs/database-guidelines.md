# FlowTrack database guidelines

- Every operational list is bounded with pagination, cursoring, an explicit parent-ID set, or a small configuration/reference bound.
- Use SQL aggregates/`exists()` instead of collection hydration for counts and presence checks.
- Use `chunkById()`/`lazyById()` for bulk/background work.
- Select and eager-load only fields/relationships required by the rendered read model.
- New indexes must correspond to a measured filter/sort/join shape and be checked with `EXPLAIN`; avoid overlapping duplicate indexes.
- Never edit a historical migration already released. Add a forward migration with a documented rollback.
- Transactions belong at the Action/domain write boundary with audit/notification side effects.
- Foreign-key and safe-delete semantics must be explicit. Do not rely on hidden Blade/Livewire behavior for data integrity.
- Phase 11's reviewed `->get()` inventory is `quality/phase11-query-inventory.json`; moved/new occurrences require reclassification.
