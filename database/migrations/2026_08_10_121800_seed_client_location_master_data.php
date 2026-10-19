<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'preferred_currency')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('preferred_currency', 40)->default('USD')->change();
            });
        }

        if (!Schema::hasTable('master_records') || !Schema::hasTable('workspaces')) return;

        $countries = [
            ['US', 'United States', '🇺🇸', true],
            ['CN', 'China', '🇨🇳', false],
            ['BD', 'Bangladesh', '🇧🇩', false],
            ['GB', 'United Kingdom', '🇬🇧', false],
            ['CA', 'Canada', '🇨🇦', false],
            ['AU', 'Australia', '🇦🇺', false],
            ['DE', 'Germany', '🇩🇪', false],
            ['FR', 'France', '🇫🇷', false],
            ['ES', 'Spain', '🇪🇸', false],
            ['IT', 'Italy', '🇮🇹', false],
            ['NL', 'Netherlands', '🇳🇱', false],
            ['AE', 'United Arab Emirates', '🇦🇪', false],
            ['SA', 'Saudi Arabia', '🇸🇦', false],
            ['IN', 'India', '🇮🇳', false],
            ['JP', 'Japan', '🇯🇵', false],
            ['KR', 'South Korea', '🇰🇷', false],
            ['SG', 'Singapore', '🇸🇬', false],
            ['VN', 'Vietnam', '🇻🇳', false],
        ];

        $states = [
            ['US-AL', 'Alabama (AL)'], ['US-AK', 'Alaska (AK)'], ['US-AZ', 'Arizona (AZ)'], ['US-AR', 'Arkansas (AR)'],
            ['US-CA', 'California (CA)'], ['US-CO', 'Colorado (CO)'], ['US-CT', 'Connecticut (CT)'], ['US-DE', 'Delaware (DE)'],
            ['US-FL', 'Florida (FL)'], ['US-GA', 'Georgia (GA)'], ['US-HI', 'Hawaii (HI)'], ['US-ID', 'Idaho (ID)'],
            ['US-IL', 'Illinois (IL)'], ['US-IN', 'Indiana (IN)'], ['US-IA', 'Iowa (IA)'], ['US-KS', 'Kansas (KS)'],
            ['US-KY', 'Kentucky (KY)'], ['US-LA', 'Louisiana (LA)'], ['US-ME', 'Maine (ME)'], ['US-MD', 'Maryland (MD)'],
            ['US-MA', 'Massachusetts (MA)'], ['US-MI', 'Michigan (MI)'], ['US-MN', 'Minnesota (MN)'], ['US-MS', 'Mississippi (MS)'],
            ['US-MO', 'Missouri (MO)'], ['US-MT', 'Montana (MT)'], ['US-NE', 'Nebraska (NE)'], ['US-NV', 'Nevada (NV)'],
            ['US-NH', 'New Hampshire (NH)'], ['US-NJ', 'New Jersey (NJ)'], ['US-NM', 'New Mexico (NM)'], ['US-NY', 'New York (NY)'],
            ['US-NC', 'North Carolina (NC)'], ['US-ND', 'North Dakota (ND)'], ['US-OH', 'Ohio (OH)'], ['US-OK', 'Oklahoma (OK)'],
            ['US-OR', 'Oregon (OR)'], ['US-PA', 'Pennsylvania (PA)'], ['US-RI', 'Rhode Island (RI)'], ['US-SC', 'South Carolina (SC)'],
            ['US-SD', 'South Dakota (SD)'], ['US-TN', 'Tennessee (TN)'], ['US-TX', 'Texas (TX)'], ['US-UT', 'Utah (UT)'],
            ['US-VT', 'Vermont (VT)'], ['US-VA', 'Virginia (VA)'], ['US-WA', 'Washington (WA)'], ['US-WV', 'West Virginia (WV)'],
            ['US-WI', 'Wisconsin (WI)'], ['US-WY', 'Wyoming (WY)'],
        ];

        $currencies = [
            ['USD', 'US Dollar', true], ['CNY', 'Chinese Yuan', false], ['BDT', 'Bangladeshi Taka', false], ['EUR', 'Euro', false],
            ['GBP', 'British Pound', false], ['CAD', 'Canadian Dollar', false], ['AUD', 'Australian Dollar', false], ['AED', 'UAE Dirham', false],
        ];

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            $workspaceId = (int) $workspaceId;
            $now = now();

            $hasCountries = DB::table('master_records')->where('workspace_id', $workspaceId)->where('type', 'country')->exists();
            if (!$hasCountries) {
                foreach ($countries as $index => [$code, $name, $flag, $isDefault]) {
                    DB::table('master_records')->insert([
                        'workspace_id' => $workspaceId,
                        'parent_id' => null,
                        'type' => 'country',
                        'code' => $code,
                        'name' => $name,
                        'description' => 'Available for client addresses.',
                        'metadata' => json_encode(['flag' => $flag, 'is_default' => $isDefault, 'seeded_by' => 'client_master_data_v1'], JSON_UNESCAPED_UNICODE),
                        'status' => 'active',
                        'sort_order' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                }
            }

            $usId = DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'country')
                ->where(function ($query) {
                    $query->where('code', 'US')->orWhere('name', 'United States');
                })
                ->whereNull('deleted_at')
                ->value('id');

            $hasStates = DB::table('master_records')->where('workspace_id', $workspaceId)->where('type', 'state')->exists();
            if (!$hasStates && $usId) {
                foreach ($states as $index => [$code, $name]) {
                    DB::table('master_records')->insert([
                        'workspace_id' => $workspaceId,
                        'parent_id' => $usId,
                        'type' => 'state',
                        'code' => $code,
                        'name' => $name,
                        'description' => 'State / region for United States client addresses.',
                        'metadata' => json_encode(['seeded_by' => 'client_master_data_v1']),
                        'status' => 'active',
                        'sort_order' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                }
            }

            $hasCurrencies = DB::table('master_records')->where('workspace_id', $workspaceId)->where('type', 'currency')->exists();
            if (!$hasCurrencies) {
                foreach ($currencies as $index => [$code, $name, $isDefault]) {
                    DB::table('master_records')->insert([
                        'workspace_id' => $workspaceId,
                        'parent_id' => null,
                        'type' => 'currency',
                        'code' => $code,
                        'name' => $name,
                        'description' => 'Available as a client preferred currency.',
                        'metadata' => json_encode(['is_default' => $isDefault, 'seeded_by' => 'client_master_data_v1']),
                        'status' => 'active',
                        'sort_order' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Do not remove master-data values or shrink preferred_currency here.
        // They become user-managed business data as soon as this migration runs.
    }
};
