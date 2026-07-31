<?php

namespace Database\Seeders;

use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Seeds the complete demo workspace, including the test Super Admin
        // account documented in README.md and SUPER_ADMIN_TEST_CREDENTIALS.md.
        $this->call(FlowTrackSeeder::class);
    }
}
