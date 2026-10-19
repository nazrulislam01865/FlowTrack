# Create Order Inquiry selector scroll runtime fix — 2026-09-03

## Problem

The Create Order **Search and Link Inquiry** selector displayed the first five remote Inquiry options and the "Scroll to load more" hint, but the option list itself did not scroll. As a result, the `scroll` listener never fired and the next remote page was never requested.

## Root cause

The reusable search-select component correctly added `ft-search-select__list--infinite` with a component-layer `max-height`. A later, unlayered shared application rule for `.ft-remote-filter-list` set `max-height: none`. Unlayered normal CSS outranks layered normal CSS, so the infinite selector expanded to fit all initial rows instead of becoming a scroll container.

## Fix

- Added an unlayered, explicit `.ft-remote-filter-list.ft-search-select__list--infinite` sizing rule after the legacy shared filter rule.
- Restored a bounded 9rem option viewport with `overflow-y:auto`, `overscroll-behavior:contain`, and stable scrollbar gutter.
- Moved infinite-scroll status text inside the actual scrolling list so wheel/touch input over the hint still targets the scroll owner.
- Kept the existing remote paging behavior: no Inquiry query on Create Order page load; page one is fetched only when the search selector is opened; additional bounded pages are fetched only near the bottom of the list.
- Existing non-infinite search selects retain their manual Load more behavior.

No database migration is required.
