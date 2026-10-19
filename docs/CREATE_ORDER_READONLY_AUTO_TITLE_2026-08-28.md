# Create Order - Read-only Automatic Order Title

## Behavior

- Client Reference Number remains required.
- Order Title is visible on Create Order but cannot be edited.
- The title preview updates from the Client Reference Number and selected products.
- The saved title is generated server-side using the same generator as the preview.
- FlowTrack Order Code (`job_number`) remains separate and unchanged.
- Order hand date remains optional.

## Title format

- One product: `FO-333119 – Premium Cotton T-Shirt`
- Multiple products: `FO-333119 – Premium Cotton T-Shirt + 2 more`

## Files changed

- `resources/views/components/jobs/create.blade.php`
- `resources/views/livewire/jobs/index.blade.php`
- `app/Livewire/Jobs/Concerns/BuildsOrderPageData.php`
- `app/DTOs/Orders/OrderCreateData.php`
- `tests/Feature/CreateOrderAutomaticTitleTest.php`
