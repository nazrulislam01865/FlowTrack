<?php

namespace App\DTOs\Dashboard;

final readonly class DashboardFilterData
{
    public function __construct(
        public int $clientId = 0,
        public int $departmentId = 0,
        public int $rangeDays = 7,
        public string $search = '',
    ) {
    }
}
