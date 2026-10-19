<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_track_order_entry_point(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Just checking an order?')
            ->assertSee('Track your order')
            ->assertSee(route('order.track'));
    }

    public function test_tracking_lookup_page_renders_successfully(): void
    {
        $this->get(route('order.track'))
            ->assertOk()
            ->assertSee('Track your order')
            ->assertSee('Order number')
            ->assertSee('Reference number')
            ->assertSee('placeholder="e.g. FO-337118 or ORDER-00942"', false)
            ->assertSee('tab-btn-order')
            ->assertSee('tab-btn-reference')
            ->assertSee('Have a QR code?');
    }

    public function test_invalid_lookup_returns_anti_enumeration_error(): void
    {
        $this->post(route('order.track.lookup'), [
            'identifier' => 'FO-999999',
            'email' => 'nobody@example.com',
            'identifier_type' => 'order_number',
        ])
            ->assertSessionHasErrors(['lookup' => 'We could not find a matching order. Check your number and email address.']);
    }

    public function test_valid_lookup_by_order_number(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Acme Corp', 'CLI-ACME', 'client@acme.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-337118',
            'order_number' => 'NP-2026-0148',
            'title' => 'Custom Gloves',
            'category' => 'Apparel',
            'quantity' => 100,
            'status' => 'active',
        ]);

        $this->assertNotEmpty($job->tracking_token);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => 'FO-337118',
            'email' => 'client@acme.com',
            'identifier_type' => 'order_number',
        ]);

        $response->assertRedirect(route('order.track.show', ['token' => $job->tracking_token]));
    }

    public function test_valid_lookup_by_reference_number(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Beta Corp', 'CLI-BETA', 'orders@betacorp.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-556677',
            'order_number' => 'REF-XYZ-999',
            'title' => 'Tote Bags',
            'category' => 'Bags',
            'quantity' => 200,
            'status' => 'active',
        ]);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => 'REF-XYZ-999',
            'email' => 'orders@betacorp.com',
            'identifier_type' => 'reference_number',
        ]);

        $response->assertRedirect(route('order.track.show', ['token' => $job->tracking_token]));
    }

    public function test_valid_lookup_by_client_contact_email(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Delta Corp', 'CLI-DELTA', 'headquarters@delta.com');

        // Add a secondary contact with their own email
        \App\Models\ClientContact::create([
            'client_id' => $client->id,
            'name' => 'Purchasing Manager',
            'email' => 'procurement@delta.com',
            'is_primary' => false,
        ]);

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-DELTA-01',
            'order_number' => 'PO-DELTA-2026',
            'title' => 'Branded Lanyards',
            'status' => 'active',
        ]);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => 'PO-DELTA-2026',
            'email' => 'procurement@delta.com',
            'identifier_type' => 'reference_number',
        ]);

        $response->assertRedirect(route('order.track.show', ['token' => $job->tracking_token]));
    }

    public function test_lookup_is_case_insensitive_and_trims_whitespace(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Epsilon LLC', 'CLI-EPS', 'epsilon.orders@gmail.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-EPS-100',
            'order_number' => 'REF-EPS-100',
            'title' => 'Custom Mugs',
            'status' => 'active',
        ]);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => '  FO-EPS-100  ',
            'email' => '  EPSILON.ORDERS@GMAIL.COM  ',
            'identifier_type' => 'order_number',
        ]);

        $response->assertRedirect(route('order.track.show', ['token' => $job->tracking_token]));
    }

    public function test_lookup_fails_when_order_number_matches_but_email_is_wrong(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Zeta Co', 'CLI-ZETA', 'zeta@realdomain.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-ZETA-01',
            'order_number' => 'REF-ZETA-01',
            'title' => 'Zeta Pens',
            'status' => 'active',
        ]);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => 'FO-ZETA-01',
            'email' => 'wrong.email@otherdomain.com',
            'identifier_type' => 'order_number',
        ]);

        $response->assertSessionHasErrors([
            'lookup' => 'We could not find a matching order. Check your number and email address.',
        ]);
    }

    public function test_lookup_fails_when_email_matches_but_order_number_is_wrong(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Eta Co', 'CLI-ETA', 'eta@realdomain.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-ETA-01',
            'order_number' => 'REF-ETA-01',
            'title' => 'Eta Notebooks',
            'status' => 'active',
        ]);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => 'FO-NON-EXISTENT',
            'email' => 'eta@realdomain.com',
            'identifier_type' => 'order_number',
        ]);

        $response->assertSessionHasErrors([
            'lookup' => 'We could not find a matching order. Check your number and email address.',
        ]);
    }

    public function test_tracking_dashboard_renders_7_stages_and_3_metrics(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Lee Client Pte', 'CLI-LEE', 'orders@leeclient.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-337118',
            'order_number' => 'NP-2026-0148',
            'title' => 'Non-slip Adult Gloves',
            'category' => 'Sampling',
            'quantity' => 60,
            'status' => 'active',
        ]);

        $response = $this->get(route('order.track.show', ['token' => $job->tracking_token]));

        $response->assertOk()
            ->assertSee('Order FO-337118')
            ->assertSee('NP-2026-0148')
            ->assertSee('Sample order')
            ->assertSee('New Order')
            ->assertSee('Artwork')
            ->assertSee('Production')
            ->assertSee('QC')
            ->assertSee('Shipment')
            ->assertSee('Billing')
            ->assertSee('Payment')
            ->assertSee('STAGE 1')
            ->assertSee('STAGE 7')
            ->assertSee('Delivery')
            ->assertSee('Billing')
            ->assertSee('Payment')
            ->assertSee('Order updates')
            ->assertSee('Track another order');
    }

    private function setupJobDependencies(string $name, string $code, string $email): array
    {
        $client = Client::create([
            'name' => $name,
            'code' => $code,
            'email' => $email,
            'is_active' => true,
        ]);

        $workflow = \App\Models\Workflow::create([
            'name' => 'Default Workflow',
            'slug' => 'default-workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $phase = \App\Models\WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Production',
            'short_name' => 'Production',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        return [$client, $workflow, $phase];
    }

    public function test_invalid_token_redirects_to_lookup(): void
    {
        $this->get(route('order.track.show', ['token' => 'invalid-token-12345']))
            ->assertRedirect(route('order.track'))
            ->assertSessionHasErrors('lookup');
    }

    public function test_tracking_pdf_is_unavailable_before_artwork_confirmation(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        [$client, $workflow, $phase] = $this->setupJobDependencies('Acme QR', 'CLI-AQR', 'qr@acme.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-QR-999',
            'order_number' => 'REF-QR-999',
            'title' => 'Test QR Order',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('orders.qr.download', ['job' => $job->id]))
            ->assertNotFound();
    }

    public function test_generated_artwork_tracking_pdf_can_be_downloaded(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        [$client, $workflow, $phase] = $this->setupJobDependencies('Acme QR PDF', 'CLI-AQRP', 'qrpdf@acme.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-QR-PDF-999',
            'order_number' => 'REF-QR-PDF-999',
            'title' => 'Test QR PDF Order',
            'status' => 'active',
        ]);

        $diskName = (string) config('flowtrack.document_disk', 'flowtrack_private');
        Storage::fake($diskName);
        $path = 'flowtrack/documents/'.$job->id.'/generated/test-confirmed-artwork-tracking.pdf';
        Storage::disk($diskName)->put($path, "%PDF-1.4\n% test\n");

        Document::create([
            'document_number' => 'DOC-TRACK-'.$job->id.'-V1',
            'flow_job_id' => $job->id,
            'client_id' => $client->id,
            'uploaded_by' => $user->id,
            'category' => Document::CATEGORY_ARTWORK_TRACKING_PDF,
            'name' => 'test-confirmed-artwork-tracking.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 16,
            'version' => 1,
            'is_final' => true,
        ]);

        $response = $this->actingAs($user)->get(route('orders.qr.download', ['job' => $job->id]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_disambiguation_view_renders_when_multiple_orders_match(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Repeat Client', 'CLI-REP', 'repeat@client.com');

        $job1 = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-MULTI-001',
            'order_number' => 'SAME-REF-001',
            'title' => 'Batch 1',
            'status' => 'active',
        ]);

        $job2 = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-MULTI-002',
            'order_number' => 'SAME-REF-001',
            'title' => 'Batch 2',
            'status' => 'active',
        ]);

        $response = $this->post(route('order.track.lookup'), [
            'identifier' => 'SAME-REF-001',
            'email' => 'repeat@client.com',
            'identifier_type' => 'reference_number',
        ]);

        $response->assertOk()
            ->assertSee('Matching orders found')
            ->assertSee('FO-MULTI-001')
            ->assertSee('FO-MULTI-002');
    }

    public function test_customer_tracking_redacts_sensitive_internal_fields(): void
    {
        [$client, $workflow, $phase] = $this->setupJobDependencies('Private Client', 'CLI-PRIV', 'private@client.com');

        $job = FlowJob::create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'client_id' => $client->id,
            'job_number' => 'FO-PRIV-001',
            'order_number' => 'REF-PRIV-001',
            'title' => 'Confidential Order',
            'notes' => 'SECRET_INTERNAL_NOTE_DO_NOT_LEAK',
            'shipping_address' => '123 Classified St, Floor 9',
            'status' => 'active',
        ]);

        $response = $this->get(route('order.track.show', ['token' => $job->tracking_token]));

        $response->assertOk()
            ->assertDontSee('SECRET_INTERNAL_NOTE_DO_NOT_LEAK')
            ->assertDontSee('123 Classified St');
    }

    public function test_every_stage_synchronizes_identically_with_order_stage_resolver(): void
    {
        [$client, $workflow] = [
            Client::create(['name' => 'Sync Client', 'code' => 'CLI-SYNC', 'email' => 'sync@client.com', 'is_active' => true]),
            \App\Models\Workflow::create(['name' => 'Sync Workflow', 'slug' => 'sync-wf-' . uniqid(), 'is_active' => true]),
        ];

        $trackingService = app(\App\Services\OrderTrackingService::class);

        $stageDefinitions = [
            1 => ['name' => 'New Order', 'phase' => 'Order Intake'],
            2 => ['name' => 'Artwork', 'phase' => 'Artwork Preparation'],
            3 => ['name' => 'Production', 'phase' => 'Factory Production'],
            4 => ['name' => 'QC', 'phase' => 'Quality Control'],
            5 => ['name' => 'Shipment', 'phase' => 'Courier Shipment'],
            6 => ['name' => 'Billing', 'phase' => 'Invoice Billing'],
            7 => ['name' => 'Payment', 'phase' => 'Payment Settlement'],
        ];

        foreach ($stageDefinitions as $expectedStageNum => $def) {
            $phase = \App\Models\WorkflowPhase::create([
                'workflow_id' => $workflow->id,
                'sequence' => 10 + $expectedStageNum, // non-standard historical sequence
                'name' => $def['phase'],
                'short_name' => $def['name'],
                'is_active' => true,
            ]);

            $job = FlowJob::create([
                'workflow_id' => $workflow->id,
                'workflow_phase_id' => $phase->id,
                'client_id' => $client->id,
                'job_number' => "FO-SYNC-00{$expectedStageNum}",
                'order_number' => "REF-SYNC-00{$expectedStageNum}",
                'title' => "Stage {$expectedStageNum} Product",
                'status' => 'active',
            ]);

            $resolvedStageNum = $trackingService->determineCurrentStageNumber($job);

            $this->assertSame(
                $expectedStageNum,
                $resolvedStageNum,
                "Stage synchronization failed for {$def['name']}: expected stage {$expectedStageNum}, got {$resolvedStageNum}"
            );

            // Also verify that the customer dashboard renders this exact stage as the current step
            $response = $this->get(route('order.track.show', ['token' => $job->tracking_token]));
            $response->assertOk()
                ->assertSee("STAGE {$expectedStageNum}");
        }
    }
}
