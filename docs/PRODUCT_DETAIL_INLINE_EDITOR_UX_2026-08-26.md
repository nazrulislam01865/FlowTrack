# Product detail inline editor UX - 2026-08-26

Order Details and Inquiry Details now use a clean row-level inline editor for Products & quantities.

- The normal product table remains compact and no longer shows scattered pencil icons.
- Edit product is opened from the row action menu.
- The editor expands directly below the selected row instead of squeezing inputs into table cells.
- Order editing groups supplier, quantity, unit price and notes into one save operation.
- Inquiry editing groups category, product, quantity, unit price and notes into one explicit Save/Cancel flow.
- Searchable category/product/supplier controls reuse the existing FlowTrack search-select component.
- Validation messages render directly beneath the related field.
- Opening Add another product closes an active editor, and opening an editor closes the add-product panel.
- The shared editor shell is `resources/views/components/catalog/detail-product-inline-editor.blade.php`.
