<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArtworkHandoffOverviewAndAddressBookUxTest extends TestCase
{
    public function test_address_book_is_in_administration_and_editor_keeps_list_visible(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));
        $view = file_get_contents(resource_path('views/livewire/artwork-address-book/index.blade.php'));

        $this->assertGreaterThan(strpos($sidebar, '<span>Administration</span>'), strpos($sidebar, 'route="artwork-address-book.index"'));
        $this->assertSame(1, substr_count($sidebar, 'route="artwork-address-book.index"'));
        $this->assertStringContainsString('ft-address-book-page__layout--editing', $view);
        $this->assertStringContainsString('wire:submit="save"', $view);
        $this->assertStringContainsString('wire:click="edit(', $view);
        $this->assertStringContainsString('wire:model.live.debounce.350ms="search"', $view);
    }

    public function test_artwork_handoff_embeds_overview_and_includes_qr_pdf_and_images(): void
    {
        $service = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $email = file_get_contents(resource_path('views/emails/orders/workflow-handoff.blade.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('if ($key === self::ARTWORK_HANDOFF)', $service);
        $this->assertStringContainsString('artworkOverviewForEmail(', $service);
        $this->assertStringContainsString('Document::CATEGORY_ARTWORK_TRACKING_PDF', $service);
        $this->assertStringContainsString('->where(\'version\', $version)', $service);
        $this->assertStringContainsString('$attachments = array_merge($attachments, $viewData[\'overviewAttachments\']);', $service);
        $this->assertStringContainsString('temporarySignedRoute(', $service);
        $this->assertStringContainsString("->middleware(['signed', 'throttle:60,1'])", $routes);
        $this->assertStringContainsString('@if($handoffType === \'artwork\')', $email);
        $this->assertStringContainsString('{!! $overviewHtml !!}', $email);
        $this->assertStringContainsString('Confirmed artwork PDF with tracking QR code', $email);
    }
}
