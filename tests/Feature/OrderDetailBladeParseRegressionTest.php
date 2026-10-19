<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class OrderDetailBladeParseRegressionTest extends TestCase
{
    public function test_order_products_component_compiles_into_parseable_php(): void
    {
        $path = resource_path('views/components/jobs/order-detail/products.blade.php');
        $compiled = Blade::compileString((string) file_get_contents($path));

        // This detects unclosed Blade @if blocks before @endforeach, including
        // directives accidentally attached to the preceding HTML text.
        $tokens = token_get_all($compiled, TOKEN_PARSE);
        $this->assertNotEmpty($tokens);
    }

    public function test_task_checklist_component_compiles_into_parseable_php(): void
    {
        $path = resource_path('views/components/jobs/task-detail/checklist.blade.php');
        $compiled = Blade::compileString((string) file_get_contents($path));

        $tokens = token_get_all($compiled, TOKEN_PARSE);
        $this->assertNotEmpty($tokens);
    }
}
