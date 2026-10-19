<?php

namespace Tests\Unit;

use App\Services\MyWorkService;
use App\Services\OrderListPrototypeService;
use PHPUnit\Framework\TestCase;

class StageCardFilterPerformanceTest extends TestCase
{
    public function test_stage_services_define_short_lived_card_caches(): void
    {
        $orders = new \ReflectionClass(OrderListPrototypeService::class);
        $myWork = new \ReflectionClass(MyWorkService::class);

        $this->assertSame(15, $orders->getConstant('STAGE_COUNT_CACHE_TTL_SECONDS'));
        $this->assertSame(15, $myWork->getConstant('STAGE_CARD_CACHE_TTL_SECONDS'));
    }
}
