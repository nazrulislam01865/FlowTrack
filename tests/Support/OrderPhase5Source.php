<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Source-contract helper for the Phase 5 Order decomposition.
 *
 * Existing regression tests intentionally inspect implementation source. After
 * Phase 5 the same implementation is spread across the compatibility
 * coordinator, focused concerns, Actions/Queries and stable Blade partials.
 * This helper preserves those assertions without pretending the old monolith
 * still owns the behavior.
 */
final class OrderPhase5Source
{
    public static function livewire(): string
    {
        return self::joinFiles(array_merge(
            [app_path('Livewire/Jobs/Index.php')],
            self::phpFiles(app_path('Livewire/Jobs/Concerns')),
            self::phpFiles(app_path('Actions/Orders')),
            self::phpFiles(app_path('Queries/Orders')),
        ));
    }

    public static function createProductsView(): string
    {
        return self::joinFiles(array_merge(
            [resource_path('views/components/jobs/create-products.blade.php')],
            self::bladeFiles(resource_path('views/components/jobs/create')),
        ));
    }

    public static function detailDocumentsView(): string
    {
        return self::joinFiles(array_merge(
            [resource_path('views/components/jobs/detail-documents.blade.php')],
            self::bladeFiles(resource_path('views/components/jobs/documents')),
        ));
    }

    /** Reconstruct the decomposed Order detail Blade surface. */
    public static function detailView(): string
    {
        return self::joinFiles(array_merge(
            [
                resource_path('views/components/jobs/detail.blade.php'),
                resource_path('views/components/jobs/detail-overview.blade.php'),
            ],
            self::bladeFiles(resource_path('views/components/jobs/order-detail')),
        ));
    }

    public static function taskDetailView(): string
    {
        return self::joinFiles(array_merge(
            [resource_path('views/components/jobs/task-detail.blade.php')],
            self::bladeFiles(resource_path('views/components/jobs/task-detail')),
        ));
    }

    public static function prototypeListView(): string
    {
        return self::joinFiles(array_merge(
            [resource_path('views/components/orders/prototype-list.blade.php')],
            self::bladeFiles(resource_path('views/components/orders/list')),
        ));
    }

    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) return [];

        return collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => $file->getPathname())
            ->values()
            ->all();
    }

    private static function bladeFiles(string $directory): array
    {
        if (!is_dir($directory)) return [];

        return collect(File::files($directory))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => $file->getPathname())
            ->values()
            ->all();
    }

    private static function joinFiles(array $paths): string
    {
        return collect($paths)
            ->filter(fn ($path) => is_file($path))
            ->map(fn ($path) => file_get_contents($path) ?: '')
            ->implode("\n\n");
    }
}
