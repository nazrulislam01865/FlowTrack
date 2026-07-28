<?php

namespace Database\Seeders;

use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Database\Seeders\FlowTrack\SuperAdminSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            FlowTrackSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
