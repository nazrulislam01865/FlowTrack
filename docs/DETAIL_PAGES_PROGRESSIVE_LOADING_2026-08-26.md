# Detail Pages Progressive Loading — 2026-08-26

## Scope

Progressive inner-section loading has been added to the major FlowTrack detail surfaces without deferring the whole page component. This preserves route context, permissions, header actions, and direct-link navigation while reducing unnecessary initial database work.

## Covered detail pages

- Order Details
  - Core header/status/summary remains immediate.
  - Products, workflow/task data, attachments, and activity hydrate progressively.
  - Non-overview tabs remain demand-driven.
- Task Details
  - Task identity and essential properties remain immediate.
  - Checklist, attachments, and activity/comments load progressively.
- Inquiry Details
  - Header, description, essential properties, and minimum current-task context remain immediate.
  - Products, complete taskflow, attachments, and activity load progressively.
  - Deep-linked tasks force the required taskflow state so navigation remains reliable.
- Product Details
  - Product identity/classification remains immediate.
  - Pricing, options/shipping urgency data, and certificates/documents load progressively.
- Client Details
  - Client shell and summary remain immediate.
  - Address data hydrates progressively.
  - Contacts, orders, documents, and activity continue to load only when their respective tabs are requested.

## Architecture

The shared viewport trigger lives in:

`resources/views/components/ui/progressive-section-loader.blade.php`

Order-specific readiness and hydration logic is isolated in:

`app/Livewire/Jobs/Concerns/ManagesDetailProgressiveLoading.php`

Other modules keep their own small readiness flags and section loaders in their existing Livewire concerns/components instead of introducing a cross-domain monolith.

## Safety rule

The entire Create/Detail component must not be deferred when its mount logic depends on the original request URL. Only expensive inner sections are deferred/progressive.

The project quality check in `scripts/quality/progressive-page-loading.php` protects this rule.

## Validation

- Progressive page loading quality policy: PASS
- Modified PHP source syntax: PASS
- Whole detail pages remain immediate and route-safe
- Heavy inner queries are gated by section readiness
