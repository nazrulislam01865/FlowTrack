<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemoteAreaMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_area_is_a_visible_master_data_type_with_automatic_code_generation(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $this->assertSame('Remote Areas', MasterDataService::LABELS['remote_area']);
        $this->assertSame('RMA', MasterDataService::CODE_PREFIXES['remote_area']);
        $this->assertSame('RMA-001', $service->nextCode('remote_area'));
        $this->assertSame('masterdata', MasterDataService::permissionModuleForType('remote_area'));
    }

    public function test_remote_area_single_postal_code_matches_orders_without_requiring_legacy_field(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $record = $service->save('remote_area', [
            'code' => 'RMA-001',
            'name' => 'United Kingdom · SW1A 1AA',
            'description' => 'Remote delivery zone',
            'status' => 'active',
            'sort_order' => 1,
            'metadata' => [
                'carrier' => 'UPS',
                'country' => 'United Kingdom',
                'iata_code' => 'gb',
                'postal_code_from' => ' sw1a   1aa ',
                'postal_code_to' => ' sw1a 1aa ',
                'origin_surcharge' => 'No',
                'destination_surcharge' => 'Remote Area Surcharge',
                'extra_charge' => '27.50',
            ],
        ])->fresh();

        $this->assertSame('UPS', $record->remoteAreaCarrier());
        $this->assertSame('United Kingdom', $record->remoteAreaCountry());
        $this->assertSame('GB', $record->remoteAreaIataCode());
        $this->assertSame('SW1A 1AA', $record->remoteAreaPostalCodeFrom());
        $this->assertSame('SW1A 1AA', $record->remoteAreaPostalCodeTo());
        $this->assertSame('Remote Area Surcharge', $record->remoteAreaDestinationSurcharge());
        $this->assertSame(27.5, $record->remoteAreaExtraCharge());
        $this->assertSame('', $record->remoteAreaPostalCode());
        $this->assertSame($record->id, $service->remoteAreaForPostalCode('SW1A1AA')?->id);
        $this->assertSame($record->id, $service->remoteAreaForPostalCode(' sw1a  1aa ')?->id);
    }

    public function test_postal_range_matches_start_middle_and_end_but_not_outside(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $range = $service->save('remote_area', [
            'code' => 'RMA-002',
            'name' => 'United States · 68862–68866',
            'status' => 'active',
            'sort_order' => 2,
            'metadata' => [
                'carrier' => 'UPS',
                'country' => 'United States',
                'iata_code' => 'US',
                'postal_code_from' => '68862',
                'postal_code_to' => '68866',
                'origin_surcharge' => 'Pickup Area Surcharge - Extended',
                'destination_surcharge' => 'Delivery Area Surcharge - Extended',
            ],
        ])->fresh();

        $this->assertSame('68862–68866', $range->remoteAreaLocationLabel());
        $this->assertSame('', $range->remoteAreaPostalCode());
        $this->assertSame($range->id, $service->remoteAreaForPostalCode('68862')?->id);
        $this->assertSame($range->id, $service->remoteAreaForPostalCode('68864')?->id);
        $this->assertSame($range->id, $service->remoteAreaForPostalCode('68866')?->id);
        $this->assertNull($service->remoteAreaForPostalCode('68861'));
        $this->assertNull($service->remoteAreaForPostalCode('68867'));

        $city = $service->save('remote_area', [
            'code' => 'RMA-003',
            'name' => 'Brunei · Kuala Belait',
            'status' => 'active',
            'sort_order' => 3,
            'metadata' => [
                'carrier' => 'UPS',
                'country' => 'Brunei',
                'iata_code' => 'BN',
                'city' => 'Kuala Belait',
                'origin_surcharge' => 'No',
                'destination_surcharge' => 'Extended Area Surcharge',
            ],
        ])->fresh();

        $this->assertSame('Kuala Belait', $city->remoteAreaLocationLabel());
        $this->assertSame('', $city->remoteAreaPostalCode());
    }

    public function test_alphanumeric_postal_range_with_same_shape_is_matchable(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $range = $service->save('remote_area', [
            'code' => 'RMA-004',
            'name' => 'Example · AB100–AB199',
            'status' => 'active',
            'sort_order' => 4,
            'metadata' => [
                'carrier' => 'UPS',
                'country' => 'Example Country',
                'iata_code' => 'EX',
                'postal_code_from' => 'AB100',
                'postal_code_to' => 'AB199',
                'origin_surcharge' => 'No',
                'destination_surcharge' => 'Remote Area Surcharge',
            ],
        ])->fresh();

        $this->assertSame($range->id, $service->remoteAreaForPostalCode('AB150')?->id);
        $this->assertNull($service->remoteAreaForPostalCode('AB200'));
        $this->assertNull($service->remoteAreaForPostalCode('AC150'));
    }

    public function test_mixed_alphanumeric_postal_range_with_same_shape_is_matchable(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $range = $service->save('remote_area', [
            'code' => 'RMA-004A',
            'name' => 'United Kingdom · SW1A1AA–SW1A1AZ',
            'status' => 'active',
            'sort_order' => 4,
            'metadata' => [
                'carrier' => 'UPS',
                'country' => 'United Kingdom',
                'iata_code' => 'GB',
                'postal_code_from' => 'SW1A 1AA',
                'postal_code_to' => 'SW1A 1AZ',
                'origin_surcharge' => 'No',
                'destination_surcharge' => 'Remote Area Surcharge',
            ],
        ])->fresh();

        $this->assertSame($range->id, $service->remoteAreaForPostalCode('SW1A 1AM')?->id);
        $this->assertNull($service->remoteAreaForPostalCode('SW1A 1BA'));
    }

    public function test_descending_alphanumeric_postal_range_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $this->assertTrue($service->postalRangeIsDescending('AB199', 'AB100'));
        $this->assertTrue($service->postalRangeIsDescending('SW1A 1AZ', 'SW1A 1AA'));
        $this->assertFalse($service->postalRangeIsDescending('AB100', 'AB199'));

        try {
            $service->save('remote_area', [
                'code' => 'RMA-004B',
                'name' => 'Invalid descending range',
                'status' => 'active',
                'sort_order' => 4,
                'metadata' => [
                    'carrier' => 'UPS', 'country' => 'Example Country', 'iata_code' => 'EX',
                    'postal_code_from' => 'AB199', 'postal_code_to' => 'AB100',
                    'origin_surcharge' => 'No', 'destination_surcharge' => 'Remote Area Surcharge',
                ],
            ]);
            $this->fail('Expected descending postal range validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remoteAreaPostalCodeTo', $exception->errors());
        }
    }

    public function test_exact_postal_rule_takes_precedence_over_broader_range(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $service->save('remote_area', [
            'code' => 'RMA-005',
            'name' => 'Example · 10000–19999',
            'status' => 'active',
            'sort_order' => 1,
            'metadata' => [
                'carrier' => 'UPS', 'country' => 'Example Country', 'iata_code' => 'EX',
                'postal_code_from' => '10000', 'postal_code_to' => '19999',
                'origin_surcharge' => 'No', 'destination_surcharge' => 'Extended Area Surcharge',
            ],
        ]);

        $exact = $service->save('remote_area', [
            'code' => 'RMA-006',
            'name' => 'Example · 15000',
            'status' => 'active',
            'sort_order' => 99,
            'metadata' => [
                'carrier' => 'UPS', 'country' => 'Example Country', 'iata_code' => 'EX',
                'postal_code_from' => '15000', 'postal_code_to' => '15000',
                'origin_surcharge' => 'No', 'destination_surcharge' => 'Remote Area Surcharge',
            ],
        ])->fresh();

        $this->assertSame($exact->id, $service->remoteAreaForPostalCode('15000')?->id);
    }

    public function test_duplicate_remote_area_rule_is_rejected_but_same_postal_range_can_exist_for_another_country(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $base = [
            'carrier' => 'UPS',
            'country' => 'United States',
            'iata_code' => 'US',
            'postal_code_from' => '90210',
            'postal_code_to' => '90210',
            'origin_surcharge' => 'No',
            'destination_surcharge' => 'Extended Area Surcharge',
        ];
        $service->save('remote_area', ['code' => 'RMA-010', 'name' => 'US · 90210', 'status' => 'active', 'sort_order' => 10, 'metadata' => $base]);

        try {
            $service->save('remote_area', ['code' => 'RMA-011', 'name' => 'Duplicate', 'status' => 'active', 'sort_order' => 11, 'metadata' => $base]);
            $this->fail('Expected duplicate remote area rule validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remoteAreaPostalCodeFrom', $exception->errors());
        }

        $otherCountry = $base;
        $otherCountry['country'] = 'Example Country';
        $otherCountry['iata_code'] = 'EX';
        $record = $service->save('remote_area', ['code' => 'RMA-012', 'name' => 'EX · 90210', 'status' => 'active', 'sort_order' => 12, 'metadata' => $otherCountry]);
        $this->assertNotNull($record->id);
    }

    public function test_remote_area_search_matches_ups_fields(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();

        MasterRecord::create([
            'workspace_id' => $workspaceId,
            'type' => 'remote_area',
            'code' => 'RMA-020',
            'name' => 'Brunei · Kuala Belait',
            'metadata' => [
                'carrier' => 'UPS', 'country' => 'Brunei', 'iata_code' => 'BN', 'city' => 'Kuala Belait',
                'origin_surcharge' => 'No', 'destination_surcharge' => 'Extended Area Surcharge',
            ],
            'status' => 'active',
            'sort_order' => 20,
        ]);

        $this->assertSame(['RMA-020'], $service->list('remote_area', 'Kuala Belait')->pluck('code')->all());
        $this->assertSame(['RMA-020'], $service->list('remote_area', 'BN')->pluck('code')->all());
    }

    public function test_legacy_remote_area_record_remains_readable_and_matchable(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $record = $service->save('remote_area', [
            'code' => 'RMA-030',
            'name' => 'Legacy Island',
            'status' => 'active',
            'sort_order' => 30,
            'metadata' => ['postal_code' => 'AB12 3CD', 'extra_charge' => 12.5],
        ])->fresh();

        $this->assertSame('AB12 3CD', $record->remoteAreaPostalCodeFrom());
        $this->assertSame('AB12 3CD', $record->remoteAreaPostalCode());
        $this->assertSame($record->id, $service->remoteAreaForPostalCode('AB123CD')?->id);
    }
}
