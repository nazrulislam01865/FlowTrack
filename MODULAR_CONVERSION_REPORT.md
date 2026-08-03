# FlowTracker modular conversion report

Date: 2026-08-03

## Goal

Convert FlowTracker toward the maintainability pattern used by the supplied Hisebghor project while preserving FlowTracker's existing UI, routes, API contracts and business rules.

## Hisebghor pattern used as reference

Hisebghor keeps Laravel pages conventional: route-specific controllers, reusable Services, Blade pages, and responsibility-scoped JavaScript under `resources/js/pages/`. It does not depend on one browser-side application renderer for every page.

## FlowTracker before

- Authenticated UI mounted thirteen empty Livewire/`wire:ignore` screen shells at once.
- The actual page DOM and much of the UI lifecycle came from a roughly 783 KB JavaScript source file.
- A roughly 2,800-line `TemplateApiController` mixed reads, clients, jobs, tasks, documents and presenter helpers.
- Several API controllers still carried `Template*` compatibility naming.
- The build generated public compatibility scripts from the application-sized source.

## FlowTracker after

### Frontend

- Every main URL has a real route-level Laravel page controller.
- The workspace mounts only the active page container.
- The fake Livewire screen shell and Livewire frontend bridge are removed.
- Canonical JavaScript source is split by responsibility under `resources/js/flowtrack/modules/`.
- A small route-aware loader lazy-loads only the modules needed by the active screen/record.
- Generated public JavaScript is treated as build output, not source code.
- Existing CSS modules, classes, IDs, responsive rules and runtime parity markers are retained.

### Backend

- Read/query operations: `Api/ReadApiController.php`
- Client mutations: `Api/ClientApiController.php`
- Job mutations: `Api/JobApiController.php`
- Task mutations: `Api/TaskApiController.php`
- Document mutations: `Api/DocumentApiController.php`
- Configuration/workflow/access/profile/branding/session endpoints have dedicated API controllers.
- Shared response/presenter helpers live in `Api/BaseApiController.php` instead of a multi-purpose controller.
- Shared page response/session/branding setup lives in `Services/FlowTrack/PageResponseService.php`.
- Existing OrderFlow Actions/Services remain the source of business logic and authorization.

### Routes

All named route declarations from the supplied FlowTracker project are retained. Route URLs/API contracts remain in place; page handlers were replaced with explicit page controllers.

## Design parity

No visual redesign was performed. The FlowTracker CSS source is byte-for-byte unchanged from the supplied project. Existing page IDs, board/detail selectors, overlays, classes and responsive rules therefore keep the same visual contract.

## Validation performed in the conversion environment

- PHP syntax check across application/config/database/routes/tests.
- JavaScript syntax check across canonical and generated runtime files.
- JSON validation for package/build manifests.
- Named-route declaration comparison against the supplied FlowTracker archive.
- UI/runtime parity-marker verification with `scripts/verify-assets.mjs`.
- Generated runtime completeness verification.

Laravel feature tests could not be executed in the conversion sandbox because the uploaded project does not contain `vendor/`. Vite could not be reinstalled from the sandbox's package registry, so the unchanged existing CSS build was preserved. On a normal development/deployment machine, run the verification commands in the README after `composer install` and `npm ci`.
