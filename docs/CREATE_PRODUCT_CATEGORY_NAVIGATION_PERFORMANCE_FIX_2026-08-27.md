# Create Product Category navigation performance fix — 2026-08-27

## Symptom

Using **Product → Create Product Category** could leave the previous page visible for a noticeable period before the category form appeared. A refresh was not required; the delay happened while Livewire `wire:navigate` waited for the destination response.

## Root cause

The direct category-create route performed `ProductTaxonomyService::synchronizeLegacyTaxonomy()` before opening the editor, and `openCategoryEditor()` performed the same synchronization again.

That synchronizer is intentionally heavyweight legacy-maintenance logic. It loads Product Categories, loads the full Product catalogue, groups products, normalizes main-category metadata, creates missing taxonomy rows and can save changed products/categories. Running it twice in a normal page-navigation request made the form wait for catalogue-wide work that the form did not need.

## Fix

- Removed full legacy taxonomy synchronization from the direct **Create Product Category** navigation path.
- Removed full synchronization from `openCategoryEditor()` so create/view/edit category forms remain lightweight.
- Explicitly keep `recordsReady = false` for direct creation so the Product Category hierarchy/list is not hydrated behind the form.
- Reuse the existing editor-only render branch, which loads only active Main Category records for a Product Category form.
- Legacy taxonomy synchronization remains available in the existing maintenance-sensitive paths; it is simply no longer part of opening the category editor.

## Safety

No category save rules, permissions, taxonomy relationships, list pagination, Product logic, supplier logic, or visual design were changed.

A source-level regression test verifies that direct category creation and `openCategoryEditor()` no longer invoke the full synchronizer and that editor-only parent loading remains in place.
