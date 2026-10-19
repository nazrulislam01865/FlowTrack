<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CancelledOrdersPrototypeImplementationTest extends TestCase
{
    public function test_cancelled_orders_page_matches_the_approved_prototype_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/orders/cancelled-orders.blade.php');
        $routes = file_get_contents($root.'/routes/web.php');
        $sidebar = file_get_contents($root.'/resources/views/layouts/partials/sidebar.blade.php');
        $service = file_get_contents($root.'/app/Services/CancelledOrderService.php');
        $theme = file_get_contents($root.'/resources/theme/flowtrack/theme.css');

        foreach ([
            'Cancelled Orders',
            'Back to active orders',
            'Export cancelled orders',
            'Cancelled orders are kept as historical records.',
            'TOTAL CANCELLED',
            'CANCELLED THIS MONTH',
            'RESTORABLE',
            'Cancelled order history',
            'Historical records only',
            'Search order, reference, client or product',
            'All clients',
            'All last stages',
            'All reasons',
            'Cancelled by anyone',
            'Sorted by latest cancellation',
            'CLIENT &amp; PRODUCT',
            'CANCELLATION REASON',
            'CANCELLED BY / DATE',
            'ORDER OWNER',
        ] as $needle) {
            self::assertStringContainsString($needle, $view);
        }

        self::assertStringContainsString("name('orders.cancelled')", $routes);
        self::assertStringContainsString("name('orders.cancelled.export')", $routes);
        self::assertStringContainsString('label="Cancelled Orders"', $sidebar);
        self::assertStringContainsString('CancelledOrderService::class', $sidebar);
        self::assertStringContainsString('public const PER_PAGE = 6;', $service);
        self::assertStringContainsString("LOWER(TRIM(COALESCE(flow_jobs.status, ''))) = 'cancelled'", $service);
        self::assertStringContainsString('new Xlsx($book)', $service);
        self::assertStringContainsString('metrics($user, $filters)', file_get_contents($root.'/app/Livewire/Orders/CancelledOrders.php'));
        self::assertStringContainsString('filteredQuery($user, $filters)->reorder()', $service);
        self::assertStringContainsString('cleanReasonText', $service);
        self::assertStringContainsString('<colgroup>', $view);
        self::assertStringContainsString('ft-cancelled-person-copy', $view);
        self::assertStringContainsString(':src="$row[\'cancelled_by_image_url\']"', $view);
        self::assertStringContainsString(':src="$row[\'owner_image_url\']"', $view);
        self::assertStringContainsString('<x-ui.avatar', $view);
        self::assertStringContainsString('min-width: 1490px', $theme);
        self::assertStringContainsString('.ft-cancelled-orders-page', $theme);
    }
}
