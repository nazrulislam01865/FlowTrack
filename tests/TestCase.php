<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function compatibilityCss(string $filename): string
    {
        $map = [
            'flowtrack-attachment-auto-upload.css' => 'css/components/file-upload.css',
            'flowtrack-bulk-order-import.css' => 'css/modules/orders/bulk-import.css',
            'flowtrack-client-logo.css' => 'css/components/client-logo.css',
            'flowtrack-client-validation-focus.css' => 'css/modules/clients/validation-focus.css',
            'flowtrack-create-order.css' => 'css/modules/orders/create.css',
            'flowtrack-dashboard-prototype.css' => 'css/modules/dashboard/prototype.css',
            'flowtrack-documents-archive.css' => 'css/modules/documents/archive.css',
            'flowtrack-inline-editing.css' => 'css/components/inline-editing.css',
            'flowtrack-inquiries.css' => 'css/modules/inquiries/core.css',
            'flowtrack-inquiry-intelligence.css' => 'css/modules/reports/inquiry-intelligence.css',
            'flowtrack-list-filters.css' => 'css/components/list-filters.css',
            'flowtrack-master-colors.css' => 'css/components/runtime-master-colors.css',
            'flowtrack-master-data.css' => 'css/modules/setup/master-data.css',
            'flowtrack-my-work.css' => 'css/modules/work/my-work.css',
            'flowtrack-order-create-products.css' => 'css/modules/orders/create-products.css',
            'flowtrack-order-detail-header.css' => 'css/modules/orders/detail-header.css',
            'flowtrack-order-document-upload.css' => 'css/modules/orders/document-upload.css',
            'flowtrack-order-finance.css' => 'css/modules/orders/finance.css',
            'flowtrack-order-products-detail.css' => 'css/modules/orders/products-detail.css',
            'flowtrack-product-categories.css' => 'css/modules/catalog/product-categories.css',
            'flowtrack-task-detail-attachments.css' => 'css/modules/tasks/detail-attachments.css',
            'flowtrack-user-editor.css' => 'css/modules/admin/user-editor.css',
            'order-detail-prototype.css' => 'css/modules/orders/detail-prototype.css',
            'order-workflow-setup-prototype.css' => 'css/modules/setup/order-workflow.css',
        ];

        $relative = $map[$filename] ?? null;
        $this->assertNotNull($relative, 'Unknown migrated CSS source: '.$filename);
        $path = resource_path($relative);
        $this->assertFileExists($path, 'Missing migrated CSS source: '.$filename);

        return $this->cssSourceWithImports($path);
    }

    private function cssSourceWithImports(string $path, array &$visited = []): string
    {
        $real = realpath($path) ?: $path;
        if (isset($visited[$real])) return '';
        $visited[$real] = true;

        $source = (string) file_get_contents($path);
        return preg_replace_callback(
            '/@import\s+[\"\']([^\"\']+)[\"\']\s*;/',
            function (array $match) use ($path, &$visited): string {
                $import = dirname($path).'/'.$match[1];
                $resolved = realpath($import) ?: $import;
                return is_file($resolved) ? $this->cssSourceWithImports($resolved, $visited) : $match[0];
            },
            $source
        ) ?? $source;
    }

    protected function applicationCss(): string
    {
        return $this->cssSourceWithImports(resource_path('css/app.css'));
    }

    protected function assertLayoutLoadsViteCss(string $entry, string $layout): void
    {
        $this->assertStringContainsString("@vite('".$entry."')", $layout);
    }


    /**
     * Phase 6 keeps the Inquiry route component as a compatibility coordinator.
     * Source-contract tests inspect the complete composed Livewire surface so
     * structural extraction does not weaken their behavior assertions.
     */
    protected function inquiryLivewireSource(): string
    {
        $source = (string) file_get_contents(app_path('Livewire/Inquiries/Index.php'));
        foreach (glob(app_path('Livewire/Inquiries/Concerns/*.php')) ?: [] as $concern) {
            $source .= "\n".(string) file_get_contents($concern);
        }

        return $source;
    }

    /** Reconstruct the Inquiry parent view with its inherited-state sections. */
    protected function inquiryViewSource(): string
    {
        $source = (string) file_get_contents(resource_path('views/livewire/inquiries/index.blade.php'));
        $partials = [
            "        @include('livewire.inquiries.sections.list')" => resource_path('views/livewire/inquiries/sections/list.blade.php'),
            "        @include('livewire.inquiries.sections.create')" => resource_path('views/livewire/inquiries/sections/create.blade.php'),
            "        @include('livewire.inquiries.sections.detail')" => resource_path('views/livewire/inquiries/sections/detail.blade.php'),
        ];

        foreach ($partials as $include => $partial) {
            $source = str_replace($include, rtrim((string) file_get_contents($partial), "\r\n"), $source);
        }

        return $source;
    }



    /**
     * Phase 8 Inquiry service surface.
     *
     * InquiryService is intentionally a thin compatibility facade. Source-
     * contract tests inspect the composed implementation instead of assuming
     * the facade still owns every legacy/read/write method.
     */
    protected function inquiryServiceSource(): string
    {
        $paths = [
            app_path('Services/InquiryService.php'),
            app_path('Services/LegacyInquiryService.php'),
        ];
        foreach (['Services/Inquiries', 'Actions/Inquiries', 'Queries/Inquiries'] as $directory) {
            $files = glob(app_path($directory.'/*.php')) ?: [];
            sort($files);
            array_push($paths, ...$files);
        }

        return collect($paths)
            ->filter(fn ($path) => is_file($path))
            ->map(fn ($path) => (string) file_get_contents($path))
            ->implode("\n\n");
    }

    /** Complete Phase 8 Order service/action/query source surface. */
    protected function jobServiceSource(): string
    {
        $paths = [
            app_path('Services/JobService.php'),
            app_path('Services/LegacyJobService.php'),
            app_path('Services/OrderDetailViewService.php'),
            app_path('Services/OrderWorkflowActionService.php'),
        ];
        foreach (['Services/Orders', 'Actions/Orders', 'Queries/Orders'] as $directory) {
            $files = glob(app_path($directory.'/*.php')) ?: [];
            sort($files);
            array_push($paths, ...$files);
        }

        return collect($paths)
            ->filter(fn ($path) => is_file($path))
            ->map(fn ($path) => (string) file_get_contents($path))
            ->implode("\n\n");
    }

    /**
     * Current Order-detail CSS surface after the monolith/legacy deletion.
     * Some source-preserving rules still live in ordered application modules;
     * this helper follows the runtime cascade rather than an obsolete file path.
     */
    protected function orderDetailCss(): string
    {
        $entries = [
            resource_path('css/application/prelude.css'),
            resource_path('css/app.css'),
            resource_path('css/application/after-core.css'),
            resource_path('css/application/after-dashboard.css'),
            resource_path('css/application/shared-components.css'),
            resource_path('css/modules/orders/detail-prototype.css'),
            resource_path('theme/flowtrack/core.css'),
            resource_path('theme/flowtrack/theme.css'),
        ];

        $source = '';
        foreach ($entries as $entry) {
            if (is_file($entry)) {
                $visited = [];
                $source .= "\n".$this->cssSourceWithImports($entry, $visited);
            }
        }

        return $source;
    }

    /**
     * Cache-Control directive order is not semantically significant and may be
     * normalized by Symfony. Assert the policy rather than an exact string.
     */
    protected function assertCacheControlDirectives($response, array $directives): void
    {
        $header = strtolower((string) $response->headers->get('Cache-Control'));
        $actual = collect(explode(',', $header))
            ->map(fn ($directive) => trim($directive))
            ->filter()
            ->values();

        foreach ($directives as $directive) {
            $this->assertTrue(
                $actual->contains(strtolower(trim((string) $directive))),
                'Missing Cache-Control directive ['.$directive.'] in ['.$header.'].'
            );
        }
    }
}
