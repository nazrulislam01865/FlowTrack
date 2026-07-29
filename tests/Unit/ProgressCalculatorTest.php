<?php

use App\Modules\OrderFlow\Business\Services\ProgressCalculator;

it('derives task progress from task status', function (string $status, int $progress) {
    expect(app(ProgressCalculator::class)->forTaskStatus($status))->toBe($progress);
})->with([
    ['NS', 0],
    ['READY', 10],
    ['REV', 35],
    ['IP', 50],
    ['WC', 65],
    ['CMP', 100],
]);

it('uses a dynamic task status name when the immutable code is auto-generated', function () {
    $status = new \App\Modules\OrderFlow\Data\Models\MasterRecord([
        'code' => 'TST-999',
        'name' => 'Completed',
    ]);

    expect(app(ProgressCalculator::class)->forTaskStatus($status))->toBe(100);
});
