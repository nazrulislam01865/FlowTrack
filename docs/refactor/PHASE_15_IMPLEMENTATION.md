# Phase 15 implementation — CI/CD, observability, legacy deletion and release hardening

Phase 15 turns the Phase 0–14 architecture into enforceable release policy rather than adding new business behavior.

## Implemented

- GitHub Actions pipeline for architecture, clean dependency installation, Pint, PHPUnit, Vite build, bundle budgets, Composer/npm audits, optional authenticated visual/browser regression, and release reproducibility.
- Phase 15 static gate rejecting new Blade style blocks, unrestricted mass assignment, broad legacy browser globals, new/growing compatibility CSS, new Legacy service callers, generated CSS delivery, and unsafe Phase 11 list-read debt.
- frontend bundle budgets and approved-baseline presence checking.
- Redis-backed rolling operational metrics for HTTP p95/error rate, query time/slow queries, memory, cache hit rate, queue delay/failures, and Reverb reconnect/error state, with scheduled threshold alerts.
- removal of proven-dead welcome/duplicate CSS, deprecated browser compatibility aliases, split-CSS generator, and generated four-chunk CSS delivery.
- source/build hash release manifest tooling and clean-checkout CI release job.
- final dependency hardening moves Bulk Order Import from the vulnerable npm-registry `xlsx@0.18.5` build to the authoritative SheetJS 0.20.3 tarball, still bundled by Vite.

## Deliberately retained compatibility owners

Legacy Job/Inquiry/Dashboard implementations and the active compatibility CSS are not dead. They remain frozen in `quality/phase15-legacy-exceptions.json`; new call sites/files or growth are rejected. Deletion is allowed only after caller search plus runtime/visual regression proves safety.

## Runtime acceptance still required outside this archive

This environment does not contain Composer/npm installed dependencies or approved authenticated visual screenshots. Therefore full PHPUnit/Pint/Vite/audit/visual/load/restore acceptance is delegated to the new dependency-complete CI/production-like release gate and must not be reported as locally passed here.
