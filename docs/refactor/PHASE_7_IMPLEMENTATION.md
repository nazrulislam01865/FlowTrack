# Phase 7 implementation — Master Data, Clients and setup screens

Date: 2026-08-22  
Source: Phase 6 refactored FlowTrack archive

## Scope

Phase 7 decomposes the remaining high-complexity administration UI boundaries while preserving current behavior. It does not redesign screens, change route names, alter permission semantics, modify CSS, change the database schema, or decompose the underlying domain services that remain scheduled for Phase 8.

The compatibility strategy is the same as Phases 5–6: keep the existing Livewire public contract, move orchestration into focused concerns, and place user-initiated write/deletion operations behind transport-independent Actions that delegate to the proven services.

## Master Data decomposition

`app/Livewire/MasterData/Index.php` is reduced from 3,184 lines to approximately 308 lines and remains the public Livewire state coordinator.

The implementation is separated under `app/Livewire/MasterData/Concerns` into navigation, generic editing, page-data composition, product list/filtering, product assets/options, product bulk actions, product record actions, taxonomy creation, category list/lazy loading, category selection/deletion and category editor responsibilities.

The original 108 Master Data methods and their signatures are preserved.

Focused application write/deletion boundaries live under `app/Actions/MasterData`:

- `SaveMasterRecordAction`
- `ToggleMasterRecordAction`
- `UpdateMasterColorAction`
- `DeleteMasterRecordAction`
- `DeleteProductCategoriesAction`

Destructive Master Data behavior is no longer executed directly from Livewire. The Actions delegate to the unchanged `MasterDataService` and `ProductCategoryDeletionService`, preserving parent/child guards, task-pack references, workflow references, system-flag protection, product image cleanup and taxonomy unassignment behavior.

## Client decomposition

`app/Livewire/Clients/Index.php` is reduced from 1,331 lines to approximately 205 lines and remains the existing Livewire state coordinator.

Focused concerns now own:

- list/filter state
- profile validation
- client creation
- contacts
- shipping/billing addresses
- detail navigation
- edit lifecycle
- archive/restore/permanent-delete lifecycle
- page-data composition

The original 75 Client methods and signatures are preserved.

Focused Client Actions live under `app/Actions/Clients`:

- `SaveClientProfileAction`
- `ReplaceClientContactsAction`
- `ArchiveClientAction`
- `RestoreClientAction`
- `PermanentlyDeleteClientAction`

`SaveClientProfileAction` reproduces the existing office-address formatting, Master Data country fallback, contact replacement, shipping-address defaulting, account-manager assignment checks and logo replacement/removal behavior. Client Livewire no longer performs direct persistence writes.

## Shared setup primitives

Reusable setup Blade primitives were added under `resources/views/components/setup`:

- `page-header.blade.php`
- `list.blade.php`
- `editor-panel.blade.php`
- `editor-modal.blade.php`
- `pagination.blade.php`
- `safe-delete-modal.blade.php`
- `color-picker.blade.php`

Workflow Setup and Task Pack Setup now actively share page/list/editor/safe-delete primitives. Workflow phase editing uses the shared editor modal. Workflow Setup, Order Workflow Setup and Master Data use the shared color-picker contract while runtime colors continue to come from `MasterColor`.

No Phase 7 CSS file was changed; the shared components emit the existing approved classes and therefore reuse the current styling.

## Setup Actions

Workflow, Task Pack and Order Workflow user-initiated writes now cross `app/Actions/Setup` boundaries. These Actions delegate to the unchanged services and preserve their existing transactions, safe-delete snapshots, default-workflow promotion, protected Order-stage rules, Task Pack mappings and Order synchronization.

The setup Action set includes workflow save/default/toggle/delete/phase save/move/delete, Order phase save+publish, Task Pack save/toggle/delete/item save/move/delete, full workflow-definition save and Order Workflow save operations.

## Safe-delete/reference integrity

Phase 7 does not introduce new cascade rules. Existing proven service/database semantics remain authoritative:

| Operation | Preserved behavior |
| --- | --- |
| Master Data parent delete | rejected while child records exist |
| Master Data referenced value delete | rejected when protected references exist; deactivate instead |
| Product category hard delete | categories are removed child-first; affected products are unassigned, not deleted |
| Workflow delete | reusable setup is removed; linked operational Jobs/Tasks are protected through existing snapshot behavior |
| Task Pack delete | reusable pack is removed; setup phases are unassigned; existing Job task data is protected |
| Client archive | client is hidden from active lists but restorable |
| Client permanent delete | profile-owned data is removed/anonymized while historical operational records remain linked |

`tests/Feature/Phase7AdministrationDeletionIntegrityTest.php` adds direct Action-level coverage for parent/child protection, taxonomy product unassignment, Task Pack phase unassignment and Client historical-reference preservation. Existing `SafeSetupDeletionTest` and Client lifecycle tests continue to cover the deeper service behavior.

## Protected compatibility boundaries

The Phase 7 quality gate protects these unchanged boundaries by SHA-256:

- `MasterDataService`
- `ClientService`
- `WorkflowService`
- `TaskPackService`
- `OrderWorkflowSetupService`
- `ProductCategoryDeletionService`
- `AccessControlService`
- `MasterColor`
- `routes/web.php`

The database migration tree and all `resources/css` source files are also protected unchanged.

## Quality gate

Run:

```bash
npm run quality:phase7
```

The gate runs the complete Phase 0–6 chain and then verifies Phase 7 structure, method compatibility, Action boundaries, shared setup primitives, runtime color wiring, protected service hashes, unchanged migrations/CSS and deletion/reference-integrity coverage.

## Validation performed in the standalone archive

- `npm run quality:phase7`: PASS.
- All Phase 0–7 source/architecture gates: PASS.
- Master Data original method signatures: 108/108 preserved.
- Client original method signatures: 75/75 preserved.
- Setup screen original method signatures: preserved.
- CSS tree: unchanged from Phase 6.
- Migration tree: unchanged from Phase 6.
- Protected service/route boundaries: unchanged from Phase 6.

The supplied archive does not contain `vendor/` or `node_modules/`, and Composer is unavailable in this sandbox. Therefore full PHPUnit runtime execution, Pint and a production Vite build remain mandatory in the normal dependency-complete development/deployment environment before release.

## Explicitly not part of Phase 7

- broad service decomposition/DTO program (Phase 8)
- authorization/workspace architecture changes (Phase 9)
- upload/document security changes (Phase 10)
- query/index performance program (Phase 11)
- CSS legacy deletion or visual redesign
