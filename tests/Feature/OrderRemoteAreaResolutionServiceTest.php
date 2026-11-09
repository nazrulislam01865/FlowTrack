<?php

namespace Tests\Feature;

use App\DTOs\Orders\RemoteAreaResolution;
use App\Models\Client;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\Orders\OrderRemoteAreaReadService;
use App\Services\Orders\OrderRemoteAreaResolutionService;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderRemoteAreaResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        config()->set('remote_area_lookup.enabled', false);
        config()->set('remote_area_lookup.order_resolution_writes_enabled', true);
        Cache::flush();
    }

    private function job(?string $postal): FlowJob
    {
        $client = Client::create(['name' => 'RA QA Client', 'code' => 'RA-QA-'.uniqid(), 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'RA QA', 'slug' => 'ra-qa-'.uniqid(), 'is_active' => true]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id, 'sequence' => 1, 'name' => 'Artwork',
            'short_name' => 'Artwork', 'allow_job_start' => true, 'is_active' => true,
        ]);
        return FlowJob::create([
            'job_number' => 'RA-ORDER-'.uniqid(), 'client_id' => $client->id,
            'workflow_id' => $workflow->id, 'workflow_phase_id' => $phase->id,
            'title' => 'Remote Area test Order', 'shipping_postal_code' => $postal,
        ]);
    }

    private function rule(string $postal, string $charge, int $sort = 1): MasterRecord
    {
        return MasterRecord::create([
            'workspace_id' => app(WorkspaceContext::class)->id(),
            'type' => 'remote_area', 'name' => 'Zone '.$postal,
            'code' => 'RMA-'.uniqid(), 'status' => 'active', 'sort_order' => $sort,
            'metadata' => ['postal_code' => $postal, 'extra_charge' => $charge],
        ]);
    }

    public function test_zero_charge_match_keeps_tag_and_leading_zeroes_without_read_sql(): void
    {
        $rule = $this->rule('00500', '0.00');
        $job = $this->job(' 00500 ');
        $resolver = app(OrderRemoteAreaResolutionService::class);
        $resolver->persistForOrder($job);
        $job->refresh();
        $this->assertSame('matched', $job->remote_area_resolution_status);
        $this->assertSame('00500', $job->remote_area_lookup_key);
        $this->assertSame($rule->id, $job->remote_area_rule_id);
        $this->assertSame('0.00', $job->remote_area_charge);

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $display = app(OrderRemoteAreaReadService::class)->forDisplay($job, $resolver->workspaceId());
        $this->assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        $this->assertSame('matched', $display['status']);
        $this->assertSame($rule->id, $display['remoteArea']['id']);
        $this->assertSame(0.0, $display['remoteArea']['extra_charge']);
    }

    public function test_no_match_and_empty_input_are_resolved_without_confusing_zero_and_null(): void
    {
        $resolver = app(OrderRemoteAreaResolutionService::class);
        $noMatch = $this->job('99999');
        $resolver->persistForOrder($noMatch);
        $this->assertSame('no_match', $noMatch->fresh()->remote_area_resolution_status);
        $this->assertSame('0.00', $noMatch->fresh()->remote_area_charge);
        $this->assertNull(app(OrderRemoteAreaReadService::class)
            ->forDisplay($noMatch->fresh(), $resolver->workspaceId())['remoteArea']);

        $empty = $this->job(null);
        $resolver->persistForOrder($empty);
        $this->assertSame('not_applicable', $empty->fresh()->remote_area_resolution_status);
        $this->assertSame('0.00', $empty->fresh()->remote_area_charge);
    }

    public function test_changed_postal_cannot_reuse_previous_tag_or_charge(): void
    {
        $this->rule('12345', '30.00');
        $job = $this->job('12345');
        $resolver = app(OrderRemoteAreaResolutionService::class);
        $resolver->persistForOrder($job);
        $job->refresh();
        $job->shipping_postal_code = '45678';
        $this->assertNull($resolver->validSnapshot($job));
        $this->assertSame('unresolved', app(OrderRemoteAreaReadService::class)
            ->forDisplay($job, $resolver->workspaceId())['status']);
        $attributes = $resolver->attributesForPostal($job, '45678');
        $this->assertSame('no_match', $attributes['remote_area_resolution_status']);
        $job->update(array_merge(['shipping_postal_code' => '45678'], $attributes));
        $this->assertSame('no_match', $job->fresh()->remote_area_resolution_status);
    }

    public function test_unchanged_effective_postal_skips_a_redundant_prepared_resolution(): void
    {
        $this->rule('00123', '15.00');
        $job = $this->job('00123');
        $resolver = app(OrderRemoteAreaResolutionService::class);
        $resolver->persistForOrder($job);
        $job->refresh();

        // A stale candidate from an earlier edit must not replace an already
        // valid snapshot when the effective primary lookup key did not change.
        $candidate = RemoteAreaResolution::resolved($resolver->workspaceId(), '00123', 0, null);
        $this->assertSame([], $resolver->attributesForPostal($job, ' 00123 ', $candidate));
        $this->assertSame('matched', $job->fresh()->remote_area_resolution_status);
        $this->assertSame('15.00', $job->fresh()->remote_area_charge);
    }

    public function test_financial_checkpoint_revalidates_changed_rule_revision(): void
    {
        $rule = $this->rule('01234', '12.50');
        $job = $this->job('01234');
        $resolver = app(OrderRemoteAreaResolutionService::class);
        $resolver->persistForOrder($job);
        $old = (string) $job->fresh()->remote_area_charge;
        $this->assertSame('12.50', $old);

        $metadata = $rule->metadata;
        $metadata['extra_charge'] = '23.00';
        $rule->update(['metadata' => $metadata]);
        Cache::flush();
        // Resolve with a fresh MasterDataService to mimic the next request.
        $freshResolver = new OrderRemoteAreaResolutionService(new \App\Services\MasterDataService());
        $updated = DB::transaction(function () use ($freshResolver, $job): RemoteAreaResolution {
            $locked = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            return $freshResolver->ensureFinanciallyValid($locked);
        });
        $this->assertSame('23.00', $updated->charge);
        $this->assertSame('23.00', $job->fresh()->remote_area_charge);
    }

    public function test_backfill_is_dry_run_safe_and_resumable(): void
    {
        $job = $this->job('50010');
        $this->artisan('flowtrack:remote-area-backfill', ['--dry-run' => true, '--limit' => 1])->assertExitCode(0);
        $this->assertNull($job->fresh()->remote_area_resolution_status);
        $this->artisan('flowtrack:remote-area-backfill', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('no_match', $job->fresh()->remote_area_resolution_status);
        $this->artisan('flowtrack:remote-area-backfill', ['--after-id' => $job->id])->assertExitCode(0);
    }
}
