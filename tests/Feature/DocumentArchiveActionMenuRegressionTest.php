<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentArchiveActionMenuRegressionTest extends TestCase
{
    public function test_document_action_popover_closes_before_livewire_actions_mutate_the_page(): void
    {
        $view = file_get_contents(resource_path('views/livewire/documents/index.blade.php'));

        $this->assertStringContainsString('x-on:click.capture="', $view);
        $this->assertStringContainsString("const item = \$event.target.closest('[role=menuitem]');", $view);
        $this->assertStringContainsString("if (item && \$el.matches(':popover-open'))", $view);
        $this->assertStringContainsString('$el.hidePopover();', $view);
    }
    public function test_document_archive_columns_have_fixed_non_overlapping_layout(): void
    {
        $view = file_get_contents(resource_path('views/livewire/documents/index.blade.php'));
        $css = $this->compatibilityCss('flowtrack-documents-archive.css');

        $this->assertStringContainsString('<colgroup>', $view);
        $this->assertStringContainsString('ft-da-col-task', $view);
        $this->assertStringContainsString('ft-da-col-client', $view);
        $this->assertStringContainsString('min-width:1420px', $css);
        $this->assertStringContainsString('.ft-da-task-link{display:block;width:100%}', $css);
        $this->assertStringContainsString('.ft-da-record-cell a{display:block;flex:1 1 auto}', $css);
    }

    public function test_document_archive_user_type_hints_resolve_to_the_application_user_model(): void
    {
        $methods = ['archivePaginator', 'orderArchiveQuery', 'inquiryArchiveQuery', 'archiveTotals', 'canEditArchiveDocument'];

        foreach ($methods as $method) {
            $parameters = (new \ReflectionMethod(\App\Livewire\Documents\Index::class, $method))->getParameters();
            $userParameter = collect($parameters)->first(fn (\ReflectionParameter $parameter) => $parameter->getName() === 'user');

            $this->assertNotNull($userParameter, $method.' must receive the authenticated application user.');
            $type = $userParameter->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type, $method);
            $this->assertSame(\App\Models\User::class, $type->getName(), $method);
        }
    }

}
