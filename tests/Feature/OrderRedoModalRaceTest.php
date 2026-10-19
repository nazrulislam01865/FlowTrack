<?php

namespace Tests\Feature;

use App\Livewire\Jobs\Concerns\ManagesOrderRedo;
use PHPUnit\Framework\TestCase;

/**
 * Pure state-level regression tests: no database or authentication required.
 * These simulate a Livewire request delivered after the modal was dismissed.
 */
class OrderRedoModalRaceTest extends TestCase
{
    public function test_late_open_click_without_a_selected_order_is_harmless(): void
    {
        $form = new RedoModalRaceHarness;
        $form->openRedoModal();

        self::assertFalse($form->showRedoModal);
        self::assertSame(1, $form->redoStep);
    }

    public function test_late_navigation_and_submit_after_dismissal_are_harmless(): void
    {
        $form = new RedoModalRaceHarness;
        $form->selectedJobId = 42;
        $form->showRedoModal = false;
        $form->redoStep = 3;

        $form->previousRedoStep();
        $form->nextRedoStep();
        $form->createRedoOrder();

        self::assertFalse($form->showRedoModal);
        self::assertSame(3, $form->redoStep);
        self::assertSame([], $form->validationResets);
    }

    public function test_modal_without_a_selected_order_closes_instead_of_returning_422(): void
    {
        foreach (['previousRedoStep', 'nextRedoStep', 'createRedoOrder'] as $method) {
            $form = new RedoModalRaceHarness;
            $form->showRedoModal = true;
            $form->redoStep = 3;
            $form->{$method}();

            self::assertFalse($form->showRedoModal, $method);
            self::assertSame(1, $form->redoStep, $method);
            self::assertCount(1, $form->validationResets, $method);
        }
    }

    public function test_back_still_works_on_an_open_modal_with_selected_order(): void
    {
        $form = new RedoModalRaceHarness;
        $form->showRedoModal = true;
        $form->selectedJobId = 42;
        $form->redoStep = 3;

        $form->previousRedoStep();
        self::assertSame(2, $form->redoStep);
        $form->previousRedoStep();
        $form->previousRedoStep();
        self::assertSame(1, $form->redoStep);
        self::assertTrue($form->showRedoModal);
    }

    public function test_redo_creation_keeps_server_side_access_control(): void
    {
        $path = dirname(__DIR__, 2).'/app/Services/OrderRedoService.php';
        $service = file_get_contents($path);
        self::assertNotFalse($service);
        self::assertStringContainsString('abort_unless($this->canInitiate($actor, $sourceOrder), 403);', $service);
    }
}

class RedoModalRaceHarness
{
    use ManagesOrderRedo;

    public ?int $selectedJobId = null;
    public array $validationResets = [];

    public function resetValidation($fields = null): void
    {
        $this->validationResets[] = $fields;
    }
}
