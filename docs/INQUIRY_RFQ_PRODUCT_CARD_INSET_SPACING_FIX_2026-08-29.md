# Inquiry RFQ product card inset spacing fix — 2026-08-29

## Problem

Expanded supplier content in Inquiry Details > RFQ was still rendered edge-to-edge inside each product card. The supplier table header/rows and failure alert visually touched the product card border even though the product cards themselves had spacing from the outer RFQ workspace.

## Change

- Added a reusable `ft-rfq-px-product-body` wrapper for expanded product content.
- Added an inset `ft-rfq-px-table-panel` surface around each supplier table and its pricing note.
- Changed the product failure alert from a full-bleed strip to an inset rounded alert.
- Applied central FlowTrack spacing, radius, surface, border and shadow tokens.
- Preserved responsive supplier-card behavior at narrow container widths.
- No RFQ sending, supplier assignment, quotation, selection or Livewire business logic was changed.

## Result

Expanded supplier content now has a clear visual gutter on every side and no longer touches the product card border.
