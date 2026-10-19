<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderInquiryLinkTest extends TestCase
{
    public function test_create_order_inquiry_link_supports_multiple_remote_selections_atomically(): void
    {
        $create = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $field = file_get_contents(resource_path('views/components/jobs/create/inquiry-link.blade.php'));
        $index = file_get_contents(resource_path('views/livewire/jobs/index.blade.php'));
        $state = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $builder = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $filters = file_get_contents(app_path('Services/FilterOptionService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/FilterOptionController.php'));
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));
        $jobs = file_get_contents(app_path('Services/LegacyJobService.php'));

        $this->assertStringContainsString('<x-jobs.create.inquiry-link', $create);
        $this->assertStringContainsString(':selected-inquiries="$selectedCreateInquiries"', $create);
        $this->assertStringContainsString('property="createInquiryId"', $field);
        $this->assertStringContainsString('type="inquiries"', $field);
        $this->assertStringContainsString('context="create-job"', $field);
        $this->assertStringContainsString('Search and Link Inquiry', $field);
        $this->assertStringContainsString('Search by inquiry number or title', $field);
        $this->assertStringContainsString('Add another inquiry', $field);
        $this->assertStringContainsString('>Change</button>', $field);
        $this->assertStringContainsString('removeCreateInquiry', $field);
        $this->assertStringContainsString("'exclude_ids' => \$excludeIds", $field);
        $this->assertStringContainsString(':infinite-scroll="true"', $field);
        $this->assertStringContainsString('x-on:flowtrack-create-inquiry-picker-open.window="editing = true"', $field);
        $this->assertStringNotContainsString("querySelector('.ft-search-select__trigger')?.click()", $field);

        $this->assertStringContainsString(':selected-create-inquiries=', $index);
        $this->assertStringContainsString('public array $createInquiryIds = [];', $state);
        $this->assertStringContainsString('public array $createInquirySelections = [];', $state);
        $this->assertStringContainsString('public int $createInquirySelectorVersion = 0;', $state);

        $this->assertStringContainsString("if (\$property === 'createInquiryId')", $creation);
        $this->assertStringContainsString("'createInquiryIds' => ['array','max:100']", $creation);
        $this->assertStringContainsString("'createInquiryIds.*' => ['integer','distinct']", $creation);
        $this->assertStringContainsString('public function removeCreateInquiry', $creation);
        $this->assertStringContainsString('One or more selected Inquiries are already linked', $creation);

        $this->assertStringContainsString("'selectedCreateInquiries'", $builder);
        $this->assertStringContainsString("'createInquirySelectorVersion'", $builder);
        $this->assertStringContainsString('$createInquiryFilterOptions = collect();', $builder);
        $this->assertStringContainsString("'exclude_ids'", $controller);
        $this->assertStringContainsString('inquiriesByIds', $filters);
        $this->assertStringContainsString("whereDoesntHave('linkedOrders')", $filters);

        $this->assertStringContainsString("'source_inquiry_ids'", $dto);
        $this->assertStringContainsString('foreach ($createInquiryIds as $createInquiryId)', $jobs);
        $this->assertStringContainsString('attachInquiryToOrder', $jobs);
        $this->assertStringContainsString('lockForUpdate()', $jobs);
    }

    public function test_create_order_inquiry_picker_uses_scroll_paging_without_eager_loading(): void
    {
        $field = file_get_contents(resource_path('views/components/jobs/create/inquiry-link.blade.php'));
        $sharedSelect = file_get_contents(resource_path('views/components/ui/search-select.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/create.css'));
        $sharedFilterCss = file_get_contents(resource_path('css/modules/application/10-shared-filters-order-overview.css'));

        $this->assertStringContainsString("'infiniteScroll' => false", $sharedSelect);
        $this->assertStringContainsString('x-on:scroll.passive=', $sharedSelect);
        $this->assertStringContainsString('loadMore()', $sharedSelect);
        $this->assertStringContainsString('Scroll to load more', $sharedSelect);
        $this->assertStringContainsString('ft-search-select__list--infinite', $sharedSelect);
        $this->assertStringContainsString('ft-search-select__scroll-status', $sharedSelect);
        $this->assertStringContainsString('.ft-remote-filter-list.ft-search-select__list--infinite', $sharedFilterCss);
        $this->assertStringContainsString('max-height: 9rem;', $sharedFilterCss);
        $this->assertStringContainsString('overscroll-behavior: contain;', $sharedFilterCss);

        // The selected count stays on the left while the Add action is aligned
        // to the far right, matching the requested Create Order layout.
        $this->assertStringContainsString('margin-left:auto;', $css);
        $this->assertStringContainsString('class="ft-create-inquiry-count"', $field);
    }
}
