<?php

namespace Tests\Feature;

use Tests\TestCase;

class UiComponentLibraryStructureTest extends TestCase
{
    public function test_phase_two_component_contract_files_exist(): void
    {
        $required = [
            'resources/css/components.css',
            'resources/css/components/buttons.css',
            'resources/css/components/badges.css',
            'resources/css/components/forms.css',
            'resources/css/components/dropdowns.css',
            'resources/css/components/cards.css',
            'resources/css/components/tables.css',
            'resources/css/components/tabs.css',
            'resources/css/components/modals.css',
            'resources/css/components/tooltips.css',
            'resources/css/components/pagination.css',
            'resources/css/components/loading.css',
            'resources/css/components/empty-state.css',
            'resources/css/components/validation.css',
            'resources/views/components/ui/button.blade.php',
            'resources/views/components/ui/icon-button.blade.php',
            'resources/views/components/ui/status-badge.blade.php',
            'resources/views/components/ui/page-header.blade.php',
            'resources/views/components/ui/section-header.blade.php',
            'resources/views/components/ui/card.blade.php',
            'resources/views/components/ui/input.blade.php',
            'resources/views/components/ui/textarea.blade.php',
            'resources/views/components/ui/select.blade.php',
            'resources/views/components/ui/remote-select.blade.php',
            'resources/views/components/ui/date-input.blade.php',
            'resources/views/components/ui/modal.blade.php',
            'resources/views/components/ui/table.blade.php',
            'resources/views/components/ui/tabs.blade.php',
            'resources/views/components/ui/pagination.blade.php',
            'resources/views/components/ui/loading.blade.php',
            'resources/views/components/ui/empty-state.blade.php',
            'resources/views/components/ui/validation-message.blade.php',
            'resources/views/components/ui/tooltip.blade.php',
        ];

        foreach ($required as $relative) {
            $this->assertFileExists(base_path($relative), $relative);
        }
    }

    public function test_official_component_views_do_not_embed_style_blocks(): void
    {
        $files = glob(base_path('resources/views/components/ui/*.blade.php')) ?: [];
        $official = [
            'button.blade.php', 'icon-button.blade.php', 'badge.blade.php', 'status-badge.blade.php',
            'page-header.blade.php', 'section-header.blade.php', 'card.blade.php', 'field.blade.php',
            'input.blade.php', 'textarea.blade.php', 'select.blade.php', 'remote-select.blade.php',
            'date-input.blade.php', 'modal.blade.php', 'table.blade.php', 'tabs.blade.php', 'tab.blade.php',
            'pagination.blade.php', 'loading.blade.php', 'empty-state.blade.php',
            'validation-message.blade.php', 'tooltip.blade.php',
        ];

        foreach ($files as $file) {
            if (! in_array(basename($file), $official, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('<style', strtolower($contents), basename($file));
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $contents, basename($file));
        }
    }

    public function test_app_css_composes_component_library_before_application_modules(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertLessThan(
            strpos($css, "@import './application/core.css';"),
            strpos($css, "@import './components.css';")
        );
        $this->assertStringNotContainsString('legacy/', $css);
        $this->assertFileDoesNotExist(base_path('resources/css/flowtrack.css'));
        $this->assertDirectoryDoesNotExist(base_path('resources/css/legacy'));
    }
}
