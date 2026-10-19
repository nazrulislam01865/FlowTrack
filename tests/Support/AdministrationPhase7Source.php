<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/** Source-contract helper for the Phase 7 administration decomposition. */
final class AdministrationPhase7Source
{
    public static function masterData(): string
    {
        return self::join(array_merge(
            [app_path('Livewire/MasterData/Index.php')],
            self::phpFiles(app_path('Livewire/MasterData/Concerns')),
            self::phpFiles(app_path('Actions/MasterData')),
        ));
    }

    public static function clients(): string
    {
        return self::join(array_merge(
            [app_path('Livewire/Clients/Index.php')],
            self::phpFiles(app_path('Livewire/Clients/Concerns')),
            self::phpFiles(app_path('Actions/Clients')),
        ));
    }

    public static function masterDataView(): string
    {
        return self::join(array_merge(
            [resource_path('views/livewire/master-data/index.blade.php')],
            self::bladeFiles(resource_path('views/livewire/master-data/sections')),
        ));
    }

    public static function clientsView(): string
    {
        return self::join(array_merge(
            [resource_path('views/livewire/clients/index.blade.php')],
            self::bladeFiles(resource_path('views/livewire/clients/sections')),
        ));
    }

    private static function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) return [];
        return collect(File::files($directory))->filter(fn ($file) => $file->getExtension() === 'php')->sortBy(fn ($file) => $file->getFilename())->map(fn ($file) => $file->getPathname())->values()->all();
    }

    private static function bladeFiles(string $directory): array
    {
        if (! is_dir($directory)) return [];
        return collect(File::files($directory))->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))->sortBy(fn ($file) => $file->getFilename())->map(fn ($file) => $file->getPathname())->values()->all();
    }

    private static function join(array $paths): string
    {
        return collect($paths)->filter(fn ($path) => is_file($path))->map(fn ($path) => file_get_contents($path) ?: '')->implode("\n\n");
    }
}
