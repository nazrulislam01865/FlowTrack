<?php

namespace Tests\Feature;

use Tests\TestCase;

class SearchPasteSupportTest extends TestCase
{
    public function test_search_inputs_normalize_external_clipboard_text_and_emit_input(): void
    {
        $script = file_get_contents(resource_path('js/components/list-filters.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("document.addEventListener('paste'", $script);
        $this->assertStringContainsString("target.type === 'search'", $script);
        $this->assertStringContainsString("target.getAttribute('role') === 'searchbox'", $script);
        $this->assertStringContainsString("placeholder.startsWith('search')", $script);
        $this->assertStringContainsString("replace(/[\\u200B-\\u200D\\uFEFF]/g, '')", $script);
        $this->assertStringContainsString("input.setRangeText", $script);
        $this->assertStringContainsString("input.dispatchEvent(inputEvent)", $script);
        $this->assertStringContainsString('resources/js/app.js', $layout);
        $this->assertStringNotContainsString('/js/flowtrack-list-filters.js', $layout);
    }
}
