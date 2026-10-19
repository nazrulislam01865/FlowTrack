<?php

namespace App\Services;

use App\DTOs\Orders\OrderCreateData;
use App\Models\Client;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Models\WorkflowTemplate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BulkOrderImportService
{
    private const ALIASES = [
        'client_id' => ['clientid', 'clientcode'],
        'ref' => ['clientreferencenumber', 'clientreference', 'referenceorderno', 'referenceorder', 'orderno'],
        'is_repeat' => ['repeatorderyesno', 'repeatorder', 'isrepeatorder', 'repeatedorder'],
        'repeat_order_no' => ['repeatorderno', 'repeatordernumber', 'previousreferencenumber'],
        'description' => ['requestdescription', 'orderdescription', 'description'],
        'shipping_address' => ['shippingaddress', 'deliveryaddress'],
        'shipping_phone' => ['phonenumberwithcountrycode', 'shippingphonewithcountrycode', 'shippingphonenumber', 'shippingphone', 'phonenumber'],
        'shipping_postal_code' => ['postalcode', 'shippingpostalcode', 'shippingzipcode', 'zipcode', 'zip'],
        'product_id' => ['productid', 'productcode', 'sku'],
        'product_quantity' => ['productquantity', 'quantity', 'qty'],
        'customer_delivery' => ['orderhanddate', 'customerrequesteddeliverydate', 'customerrequireddeliverydate', 'requireddeliverydate', 'deliverydate'],
        'estimated_delivery' => ['estimateddeliverydate'],
        'production_urgency' => ['productionurgency', 'orderproductionurgency'],
        'shipment_urgency' => ['shipmenturgency', 'shipmenturgent', 'ordershipmenturgency'],
        'notes' => ['notes', 'ordernotes'],
    ];

    public function uploadOptions(User $actor): array
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'create'), 403);

        return [];
    }

    public function prepareUpload(UploadedFile $file, User $actor, ?string $displayFilename = null, ?string $sourceFingerprint = null): array
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'create'), 403);
        if ($file->getSize() > 20 * 1024 * 1024) throw new RuntimeException('File exceeds the 20 MB limit.');

        $parsed = app(SpreadsheetRowReader::class)->read($file);
        if (count($parsed['rows']) > 10000) throw new RuntimeException('The file contains more than 10,000 data rows.');

        $token = (string) Str::uuid();
        $payload = [
            'user_id' => (int) $actor->id,
            'workspace_id' => app(SetupContext::class)->workspaceId(),
            'filename' => $displayFilename ?: $file->getClientOriginalName(),
            'fingerprint' => $sourceFingerprint ?: hash_file('sha256', $file->getRealPath()),
            'header_row' => $parsed['header_row'],
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk('local')->put($this->tempPath($actor, $token), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'token' => $token,
            'filename' => $payload['filename'],
            'fingerprint' => $payload['fingerprint'],
            'header_row' => $payload['header_row'],
            'row_count' => count($payload['rows']),
        ];
    }

    public function validateToken(string $token, array $config, User $actor): array
    {
        $source = $this->loadToken($token, $actor);
        $config = $this->normalizeConfig($config, $actor);
        $rows = collect($source['rows'])->map(fn (array $row) => $this->mapRow($row))->values();

        $referenceCounts = $rows->pluck('ref')->map(fn ($value) => trim((string) $value))->filter()->countBy();
        $clientMaps = $this->clientMaps($actor);
        $productMaps = $this->productMaps();
        // All import rows use the existing Product catalogue. Eager-load only
        // relevant client price records once, rather than once per CSV line.
        $pricedProducts = $rows->map(fn (array $row) => $this->lookupProduct((string) ($row['product_id'] ?? ''), $productMaps))
            ->filter()->unique('id')->values();
        $pricedClientIds = $rows->map(fn (array $row) => $this->lookupClient((string) ($row['client_id'] ?? ''), $clientMaps)?->id)
            ->filter()->unique()->values()->all();
        if ($pricedProducts->isNotEmpty()) {
            (new \Illuminate\Database\Eloquent\Collection($pricedProducts->all()))
                ->load(['clientPrices' => fn ($query) => $query->whereIn('client_id', $pricedClientIds)]);
        }
        $phoneCountryCodes = $this->activePhoneCountryCodes();
        $productionUrgencyMap = $this->urgencyMap('production_urgency');
        $shipmentUrgencyMap = $this->urgencyMap('shipment_urgency');
        $templates = $this->workflowTemplates();
        $existingByReference = $this->existingJobsByReferences($rows->pluck('ref')->map(fn ($value) => trim((string) $value))->filter()->unique()->all());

        $validated = $rows->map(function (array $row) use ($actor, $config, $referenceCounts, $clientMaps, $productMaps, $phoneCountryCodes, $productionUrgencyMap, $shipmentUrgencyMap, $templates, $existingByReference): array {
            $errors = [];
            $warnings = [];
            $action = 'create';

            foreach (['client_id', 'ref', 'is_repeat', 'repeat_order_no', 'description', 'shipping_address', 'shipping_phone', 'shipping_postal_code', 'product_id', 'product_quantity', 'customer_delivery', 'estimated_delivery', 'production_urgency', 'shipment_urgency', 'notes'] as $field) {
                $row[$field] = trim((string) ($row[$field] ?? ''));
            }

            $row['title'] = '';

            if ($row['client_id'] === '') $errors[] = 'Client ID is required';
            if ($row['ref'] === '') $errors[] = 'Client Reference Number is required';
            if ($row['shipping_address'] === '') $errors[] = 'Shipping Address is required';
            if ($row['shipping_postal_code'] === '') $errors[] = 'Postal Code is required';
            if ($row['product_id'] === '') $errors[] = 'Product ID is required';
            if (mb_strlen($row['ref']) > 255) $errors[] = 'Client Reference Number must be 255 characters or fewer';
            if (mb_strlen($row['repeat_order_no']) > 255) $errors[] = 'Previous Reference Number must be 255 characters or fewer';
            if (mb_strlen($row['description']) > 10000) $errors[] = 'Request Description is too long';
            if (mb_strlen($row['shipping_address']) > 2000) $errors[] = 'Shipping Address must be 2,000 characters or fewer';
            if (mb_strlen($row['shipping_postal_code']) > 30) $errors[] = 'Postal Code must be 30 characters or fewer';
            if (mb_strlen($row['notes']) > 10000) $errors[] = 'Notes must be 10,000 characters or fewer';

            $phone = $this->resolveShippingPhone($row['shipping_phone'], $phoneCountryCodes);
            if ($phone['error']) $errors[] = $phone['error'];
            $row['shipping_phone_country_code_resolved'] = $phone['country_code'];
            $row['shipping_phone_resolved'] = $phone['phone'];

            $repeatRaw = $row['is_repeat'];
            if ($repeatRaw === '') {
                $row['is_repeat'] = 'No';
                $row['is_repeat_resolved'] = false;
            } elseif (!$this->isAcceptedBoolean($repeatRaw)) {
                $errors[] = 'Repeat Order? must be Yes or No';
                $row['is_repeat_resolved'] = false;
            } else {
                $row['is_repeat_resolved'] = $this->normalizeBoolean($repeatRaw);
                $row['is_repeat'] = $row['is_repeat_resolved'] ? 'Yes' : 'No';
            }
            if ($row['is_repeat_resolved'] && $row['repeat_order_no'] === '') {
                $errors[] = 'Previous Reference Number is required when Repeat Order? is Yes';
            }
            if (!$row['is_repeat_resolved'] && $row['repeat_order_no'] !== '') {
                $warnings[] = 'Previous Reference Number is ignored because Repeat Order? is No';
            }

            $client = $row['client_id'] !== '' ? $this->lookupClient($row['client_id'], $clientMaps) : null;
            if ($row['client_id'] !== '' && !$client) $errors[] = 'Client ID does not match an active visible client';
            $row['client_resolved_id'] = $client?->id;
            $row['client_resolved_label'] = $client ? $client->code.' · '.$client->name : 'Unresolved';

            $row['product_resolved_id'] = null;
            $row['product_resolved_name'] = null;
            $row['product_resolved_category'] = null;
            $row['product_quantity_resolved'] = 0;
            $row['product_supplier_resolved_id'] = null;
            $row['product_supplier_resolved_name'] = null;
            $row['product_unit_price_resolved'] = null;
            $product = null;
            if ($row['product_id'] !== '') {
                $product = $this->lookupProduct($row['product_id'], $productMaps);
                if (!$product || !$product->parent || $product->parent->status !== 'active') {
                    $errors[] = 'Product ID does not match an active Product with an active Product Category';
                } else {
                    $row['product_resolved_id'] = (int) $product->id;
                    $row['product_resolved_name'] = (string) $product->name;
                    $row['product_resolved_category'] = (string) $product->parent->name;
                }
            }

            if ($row['product_quantity'] !== '') {
                $quantity = filter_var($row['product_quantity'], FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 1 || $quantity > 999999999) {
                    $errors[] = 'Product Quantity must be a whole number between 1 and 999999999';
                } elseif ($row['product_id'] === '') {
                    $errors[] = 'Product ID is required when Product Quantity is provided';
                } else {
                    $row['product_quantity_resolved'] = (int) $quantity;
                }
            } elseif ($row['product_id'] !== '') {
                $row['product_quantity_resolved'] = 1;
            }

            // Create Order no longer accepts a manually typed title. Bulk import
            // follows the same rule: derive the human-readable title from the
            // required Client Reference Number and the resolved Product name.
            // FlowTrack's generated job_number / Order Code remains separate.
            if ($row['ref'] !== '' && $row['product_resolved_name']) {
                $row['title'] = OrderCreateData::generateTitle($row['ref'], [[
                    'product' => $row['product_resolved_name'],
                ]]);
            }

            // Bulk import must mirror Create Order: once the Product is resolved,
            // derive its active default Supplier and quantity-based Product Master
            // price instead of persisting an unlinked supplier and a 0.00 price.
            // The spreadsheet intentionally does not carry supplier/price columns;
            // Product Master remains authoritative for both values.
            if ($product && (int) $row['product_quantity_resolved'] > 0) {
                $supplierId = $product->productSupplierId();
                $supplier = $supplierId
                    ? ($productMaps['active_suppliers_by_id'][(int) $supplierId] ?? null)
                    : null;

                if ($supplier) {
                    $row['product_supplier_resolved_id'] = (int) $supplier->id;
                    $row['product_supplier_resolved_name'] = (string) $supplier->name;
                }

                $basePrice = $product->productPriceForQuantity((int) $row['product_quantity_resolved'], (int) $row['client_resolved_id']);
                $row['product_unit_price_resolved'] = $basePrice !== null
                    ? round((float) $basePrice, 2)
                    : null;
            }

            $row['customer_delivery_normalized'] = $row['customer_delivery'] === '' ? null : $this->normalizeDate($row['customer_delivery']);
            if ($row['customer_delivery'] !== '' && !$row['customer_delivery_normalized']) {
                $errors[] = 'Invalid Order Hand Date';
            }
            $row['estimated_delivery_normalized'] = $row['estimated_delivery'] === '' ? null : $this->normalizeDate($row['estimated_delivery']);
            if ($row['estimated_delivery'] !== '' && !$row['estimated_delivery_normalized']) {
                $errors[] = 'Invalid Estimated Delivery Date';
            }

            $production = $this->resolveUrgency($row['production_urgency'], $productionUrgencyMap);
            if ($production['error']) $errors[] = 'Production Urgency '.$production['error'];
            $row['production_urgency_resolved'] = $production['label'];
            $row['production_urgency_ids'] = $production['id'] ? [$production['id']] : [];

            $shipment = $this->resolveUrgency($row['shipment_urgency'], $shipmentUrgencyMap);
            if ($shipment['error']) $errors[] = 'Shipment Urgency '.$shipment['error'];
            $row['shipment_urgency_resolved'] = $shipment['label'];
            $row['shipment_urgency_ids'] = $shipment['id'] ? [$shipment['id']] : [];

            // Client ID is mandatory. Client-specific Order workflows are applied
            // automatically; when a client has no specific workflow the review
            // step asks the user to choose one of the valid Order workflows.
            $row['workflow_resolved_id'] = null;
            $row['workflow_phase_id'] = null;
            $row['workflow_resolved_label'] = $client ? 'Resolving workflow' : 'Waiting for client';
            $row['workflow_selection_source'] = null;
            $row['workflow_requires_selection'] = false;
            $row['workflow_manual_selected_id'] = null;
            $row['workflow_options'] = [];
            $resolvedWorkflow = null;

            if ($client) {
                $resolvedWorkflow = $this->resolveClientOrderWorkflow($templates, (int) $client->id);

                if ($resolvedWorkflow) {
                    $row['workflow_resolved_id'] = $resolvedWorkflow['workflow']->id;
                    $row['workflow_phase_id'] = $resolvedWorkflow['phase']->id;
                    $row['workflow_resolved_label'] = $resolvedWorkflow['workflow']->name;
                    $row['workflow_selection_source'] = 'client';
                } else {
                    $rowNumber = (int) ($row['row'] ?? 0);
                    $manualWorkflowId = (int) ($config['manual_workflows'][$rowNumber] ?? 0);
                    $workflowOptions = $this->availableOrderWorkflowOptions($templates, (int) $client->id);
                    $row['workflow_options'] = $workflowOptions;
                    $row['workflow_manual_selected_id'] = $manualWorkflowId ?: null;

                    if ($manualWorkflowId) {
                        $manualWorkflow = $this->resolveManualOrderWorkflow($templates, (int) $client->id, $manualWorkflowId);
                        if ($manualWorkflow) {
                            $resolvedWorkflow = $manualWorkflow;
                            $row['workflow_resolved_id'] = $manualWorkflow['workflow']->id;
                            $row['workflow_phase_id'] = $manualWorkflow['phase']->id;
                            $row['workflow_resolved_label'] = $manualWorkflow['workflow']->name;
                            $row['workflow_selection_source'] = 'manual';
                        } else {
                            $errors[] = 'The selected Order workflow is not available for this client';
                            $row['workflow_resolved_label'] = 'Select workflow';
                            $row['workflow_requires_selection'] = true;
                        }
                    } elseif ($workflowOptions === []) {
                        $errors[] = 'No active Order workflow is available for this client. Configure one in Workflow Setup before importing.';
                        $row['workflow_resolved_label'] = 'No workflow available';
                    } else {
                        $errors[] = 'Select an Order workflow for this client';
                        $row['workflow_resolved_label'] = 'Select workflow';
                        $row['workflow_requires_selection'] = true;
                    }
                }
            }

            if ($row['ref'] !== '' && ($referenceCounts[$row['ref']] ?? 0) > 1) $warnings[] = 'Duplicate Client Reference Number in this file';
            $referenceExisting = $row['ref'] !== '' ? ($existingByReference[$row['ref']] ?? collect()) : collect();

            if ($referenceExisting->isNotEmpty()) {
                if ($config['duplicate_policy'] === 'skip') {
                    $action = 'skip';
                    $row['existing_job_id'] = $referenceExisting->first()->id;
                    $warnings[] = 'Reference already exists; this row will be skipped';
                } elseif ($config['duplicate_policy'] === 'update') {
                    if ($referenceExisting->count() > 1) {
                        $errors[] = 'Multiple existing orders use this reference; update cannot choose one safely';
                    } elseif (!$this->canUpdateExisting($actor, $referenceExisting->first())) {
                        $errors[] = 'You do not have permission to update the existing order with this reference';
                    } else {
                        $action = 'update';
                        $row['existing_job_id'] = $referenceExisting->first()->id;
                        $warnings[] = 'Reference already exists; the matching order will be updated and its workflow snapshot will be preserved';
                    }
                } else {
                    $warnings[] = 'Reference already exists; a separate order will be created';
                }
            }

            // Legacy priority remains stable. Production and shipment urgency are
            // stored in their dedicated master-data backed fields.
            $row['priority_resolved'] = 'Medium';
            $row['import_profile_resolved'] = $resolvedWorkflow
                ? ($row['workflow_selection_source'] === 'manual' ? 'CLIENT_MANUAL' : 'CLIENT_AUTO')
                : null;
            $row['action'] = $action;
            $row['errors'] = array_values(array_unique($errors));
            $row['warnings'] = array_values(array_unique($warnings));
            $row['status'] = $row['errors'] !== [] ? 'error' : ($row['warnings'] !== [] ? 'warning' : 'ready');

            return $row;
        })->all();

        $counts = [
            'total' => count($validated),
            'ready' => collect($validated)->where('status', 'ready')->count(),
            'warnings' => collect($validated)->where('status', 'warning')->count(),
            'errors' => collect($validated)->where('status', 'error')->count(),
            'importable' => collect($validated)->where('status', '!=', 'error')->where('action', '!=', 'skip')->count(),
            'skippable' => collect($validated)->where('status', '!=', 'error')->where('action', 'skip')->count(),
            'workflow_selection_required' => collect($validated)->where('workflow_requires_selection', true)->count(),
        ];

        return [
            'token' => $token,
            'filename' => $source['filename'],
            'fingerprint' => $source['fingerprint'],
            'header_row' => $source['header_row'],
            'workflow_label' => 'Client Order workflow',
            'counts' => $counts,
            'rows' => $validated,
        ];
    }

    public function import(string $token, array $config, User $actor): array
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'create'), 403);
        $source = $this->loadToken($token, $actor);
        $validated = $this->validateToken($token, $config, $actor);
        $config = $this->normalizeConfig($config, $actor);

        $importId = DB::table('bulk_order_imports')->insertGetId([
            'workspace_id' => app(SetupContext::class)->workspaceId(),
            'import_number' => 'PENDING-'.Str::uuid(),
            'user_id' => $actor->id,
            'profile' => 'CLIENT_AUTO',
            'default_client_id' => null,
            'default_supplier_id' => null,
            'duplicate_policy' => $config['duplicate_policy'],
            'original_filename' => $source['filename'],
            'file_fingerprint' => $source['fingerprint'],
            'total_rows' => count($validated['rows']),
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $importNumber = 'IMP-'.app(WorkspaceSettingsService::class)->localNow()->format('Y').'-'.str_pad((string) $importId, 4, '0', STR_PAD_LEFT);
        DB::table('bulk_order_imports')->where('id', $importId)->update(['import_number' => $importNumber]);

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $results = [];

        foreach ($validated['rows'] as $row) {
            $status = 'failed';
            $message = '';
            $jobId = null;

            if ($row['errors'] !== []) {
                $counts['failed']++;
                $message = implode(' · ', $row['errors']);
            } elseif ($row['action'] === 'skip') {
                $status = 'skipped';
                $counts['skipped']++;
                $jobId = $row['existing_job_id'] ?? null;
                $message = implode(' · ', $row['warnings']) ?: 'Skipped by duplicate policy';
            } else {
                try {
                    if ($row['action'] === 'update') {
                        $job = $this->updateExisting($row, $actor, $importNumber);
                        $status = 'updated';
                        $counts['updated']++;
                    } else {
                        $job = $this->createOrder($row, $actor, $importNumber);
                        $status = 'created';
                        $counts['created']++;
                    }
                    $jobId = $job->id;
                    $message = $job->displayOrderNumber();
                } catch (Throwable $exception) {
                    report($exception);
                    $counts['failed']++;
                    $message = trim($exception->getMessage()) ?: 'The order could not be imported.';
                }
            }

            DB::table('bulk_order_import_rows')->insert([
                'bulk_order_import_id' => $importId,
                'source_row_number' => (int) ($row['row'] ?? 0),
                'source_row_id' => null,
                'reference_order_no' => blank($row['ref'] ?? null) ? null : $row['ref'],
                'flow_job_id' => $jobId,
                'status' => $status,
                'message' => Str::limit($message, 1000, ''),
                'payload' => json_encode($this->auditPayload($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $results[] = [
                'row' => $row['row'],
                'reference' => $row['ref'],
                'status' => $status,
                'message' => $message,
                'job_id' => $jobId,
            ];
        }

        DB::table('bulk_order_imports')->where('id', $importId)->update([
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'skipped_count' => $counts['skipped'],
            'failed_count' => $counts['failed'],
            'status' => $counts['failed'] > 0 ? 'completed_with_issues' : 'completed',
            'updated_at' => now(),
        ]);

        return [
            'import_id' => $importId,
            'import_number' => $importNumber,
            'counts' => $counts,
            'results' => $results,
            'fingerprint' => $source['fingerprint'],
        ];
    }

    private function createOrder(array $row, User $actor, string $importNumber): FlowJob
    {
        if (blank($row['client_resolved_id'] ?? null)) {
            throw new RuntimeException('Client ID is required before this order can be imported.');
        }
        if (blank($row['ref'] ?? null)) {
            throw new RuntimeException('Client Reference Number is required before this order can be imported.');
        }
        if (blank($row['product_resolved_id'] ?? null)) {
            throw new RuntimeException('Product ID is required before this order can be imported.');
        }
        if (blank($row['workflow_resolved_id'] ?? null) || blank($row['workflow_phase_id'] ?? null)) {
            throw new RuntimeException('An Order workflow must be resolved before this order can be imported.');
        }
        if (blank($row['shipping_address'] ?? null)) {
            throw new RuntimeException('Shipping Address is required before this order can be imported.');
        }
        if (blank($row['shipping_postal_code'] ?? null)) {
            throw new RuntimeException('Postal Code is required before this order can be imported.');
        }

        $items = [];
        if (filled($row['product_resolved_id'] ?? null)) {
            $items[] = [
                'product_id' => (int) $row['product_resolved_id'],
                'supplier_id' => filled($row['product_supplier_resolved_id'] ?? null)
                    ? (int) $row['product_supplier_resolved_id']
                    : null,
                'product' => $row['product_resolved_name'],
                'category' => $row['product_resolved_category'],
                'quantity' => max(1, (int) ($row['product_quantity_resolved'] ?? 1)),
                'unit_price' => $row['product_unit_price_resolved'] ?? 0,
                'notes' => null,
            ];
        }

        return app(JobService::class)->create([
            'order_number' => $row['ref'],
            'is_repeat_order' => (bool) ($row['is_repeat_resolved'] ?? false),
            'repeat_order_number' => ($row['is_repeat_resolved'] ?? false) ? $row['repeat_order_no'] : null,
            'client_id' => $row['client_resolved_id'],
            'workflow_id' => $row['workflow_resolved_id'],
            'workflow_phase_id' => $row['workflow_phase_id'],
            'owner_id' => $actor->id,
            'coordinator_id' => $actor->id,
            'title' => $row['title'],
            'product' => $row['product_resolved_name'] ?? null,
            'category' => $row['product_resolved_category'] ?? null,
            'quantity' => (int) ($row['product_quantity_resolved'] ?? 0),
            'items' => $items,
            'delivery_date' => $row['customer_delivery_normalized'],
            'estimated_delivery_date' => $row['estimated_delivery_normalized'],
            'priority' => $row['priority_resolved'],
            'production_urgency_ids' => $row['production_urgency_ids'],
            'shipment_urgency_ids' => $row['shipment_urgency_ids'],
            'description' => blank($row['description']) ? null : $row['description'],
            'shipping_address' => $row['shipping_address'],
            'shipping_phone_country_code' => $row['shipping_phone_country_code_resolved'] ?? null,
            'shipping_phone' => $row['shipping_phone_resolved'] ?? null,
            'shipping_postal_code' => $row['shipping_postal_code'],
            'shipping_source_address_id' => null,
            'notes' => blank($row['notes']) ? null : $row['notes'],
            'received_date' => null,
            'supplier_id' => null,
            'warehouse' => null,
            'supplier_instruction' => null,
            'source_row_id' => null,
            'import_profile' => $row['import_profile_resolved'] ?? null,
            'bulk_import_id' => $importNumber,
            'draft' => false,
        ], $actor);
    }

    private function updateExisting(array $row, User $actor, string $importNumber): FlowJob
    {
        $job = FlowJob::query()->findOrFail((int) $row['existing_job_id']);
        abort_unless($this->canUpdateExisting($actor, $job), 403);

        DB::transaction(function () use ($job, $row, $actor, $importNumber): void {
            $job->update([
                'order_number' => $row['ref'],
                'is_repeat_order' => (bool) ($row['is_repeat_resolved'] ?? false),
                'repeat_order_number' => ($row['is_repeat_resolved'] ?? false) ? $row['repeat_order_no'] : null,
                'client_id' => $row['client_resolved_id'],
                'title' => $row['title'],
                'product' => $row['product_resolved_name'] ?? null,
                'category' => $row['product_resolved_category'] ?? null,
                'quantity' => (int) ($row['product_quantity_resolved'] ?? 0),
                'priority' => $row['priority_resolved'],
                'production_urgency_ids' => $row['production_urgency_ids'],
                'shipment_urgency_ids' => $row['shipment_urgency_ids'],
                'delivery_date' => $row['customer_delivery_normalized'],
                'estimated_delivery_date' => $row['estimated_delivery_normalized'],
                'description' => app(RichTextService::class)->normalize($row['description'], 10000, 'description'),
                'shipping_address' => $row['shipping_address'],
                'shipping_phone_country_code' => $row['shipping_phone_country_code_resolved'] ?? null,
                'shipping_phone' => $row['shipping_phone_resolved'] ?? null,
                'shipping_postal_code' => $row['shipping_postal_code'],
                'shipping_source_address_id' => null,
                'notes' => blank($row['notes']) ? null : trim((string) $row['notes']),
                'import_profile' => $row['import_profile_resolved'] ?? null,
                'bulk_import_id' => $importNumber,
            ]);

            $job->items()->delete();
            if (filled($row['product_resolved_id'] ?? null)) {
                $job->items()->create([
                    'catalog_product_id' => (int) $row['product_resolved_id'],
                    'supplier_id' => filled($row['product_supplier_resolved_id'] ?? null)
                        ? (int) $row['product_supplier_resolved_id']
                        : null,
                    'product_name' => $row['product_resolved_name'],
                    'category_name' => $row['product_resolved_category'],
                    'quantity' => max(1, (int) ($row['product_quantity_resolved'] ?? 1)),
                    'unit_price' => round(max(0, (float) ($row['product_unit_price_resolved'] ?? 0)), 2),
                    'notes' => null,
                    'updated_by' => $actor->id,
                    'sort_order' => 0,
                ]);
            }

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.bulk_import_updated',
                'description' => 'Order updated by bulk import '.$importNumber,
            ]);
        });

        return $job->refresh();
    }

    private function normalizeConfig(array $config, User $actor): array
    {
        $policy = strtolower(trim((string) ($config['duplicate_policy'] ?? 'skip')));
        if (!in_array($policy, ['skip', 'update', 'separate'], true)) throw new RuntimeException('Invalid duplicate reference policy.');

        $manualWorkflows = [];
        foreach (($config['manual_workflows'] ?? []) as $rowNumber => $workflowId) {
            if (!is_numeric($rowNumber) || !filled($workflowId) || !is_numeric($workflowId)) continue;
            $manualWorkflows[(int) $rowNumber] = (int) $workflowId;
        }

        return [
            'duplicate_policy' => $policy,
            'manual_workflows' => $manualWorkflows,
        ];
    }

    private function mapRow(array $source): array
    {
        $normalized = [];
        foreach ($source as $key => $value) {
            if ($key === '__source_row') continue;
            $normalized[$this->normalizeKey((string) $key)] = $value;
        }

        $row = ['row' => (int) ($source['__source_row'] ?? 0)];
        foreach (self::ALIASES as $target => $aliases) {
            $row[$target] = '';
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $normalized)) {
                    $row[$target] = $normalized[$alias];
                    break;
                }
            }
        }
        return $row;
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim($value)) ?? '');
    }

    private function isAcceptedBoolean(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', 'y', 'true', '1', 'no', 'n', 'false', '0'], true);
    }

    private function normalizeBoolean(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', 'y', 'true', '1'], true);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        $text = trim((string) $value);
        if ($text === '') return null;

        if (is_numeric($text)) {
            $serial = (float) $text;
            if ($serial >= 1 && $serial <= 2958465) {
                $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
                return $base->modify('+'.(int) floor($serial).' days')->format('Y-m-d');
            }
        }

        foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'n/j/Y', 'd/m/Y', 'j/n/Y', 'm-d-Y', 'n-j-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** @return array{by_id:array<int,Client>,by_code:array<string,Client>,by_name:array<string,Client>} */
    private function clientMaps(User $actor): array
    {
        $clients = app(ClientService::class)->referenceQuery($actor, 'bulk-order-import')->where('is_active', true)->get(['id', 'code', 'name']);
        return [
            'by_id' => $clients->keyBy('id')->all(),
            'by_code' => $clients->keyBy(fn ($client) => strtoupper(trim((string) $client->code)))->all(),
            'by_name' => $clients->keyBy(fn ($client) => mb_strtolower(trim((string) $client->name)))->all(),
        ];
    }

    private function lookupClient(string $value, array $maps): ?Client
    {
        if (ctype_digit($value) && isset($maps['by_id'][(int) $value])) return $maps['by_id'][(int) $value];
        $code = strtoupper($value);
        if (isset($maps['by_code'][$code])) return $maps['by_code'][$code];
        return $maps['by_name'][mb_strtolower($value)] ?? null;
    }

    /**
     * @return array{
     *   by_id:array<int,MasterRecord>,
     *   by_code:array<string,MasterRecord>,
     *   by_display:array<string,MasterRecord>,
     *   by_reference:array<string,MasterRecord>,
     *   active_suppliers_by_id:array<int,MasterRecord>
     * }
     */
    private function productMaps(): array
    {
        $products = app(ProductCatalogService::class)->activeProductsQuery()
            ->with('parent:id,type,name,status')
            ->get(['id', 'type', 'parent_id', 'code', 'name', 'metadata', 'status']);

        $defaultSupplierIds = $products
            ->map(fn (MasterRecord $product) => $product->productSupplierId())
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $activeSuppliers = $defaultSupplierIds->isEmpty()
            ? collect()
            : MasterRecord::query()
                ->forWorkspace(app(SetupContext::class)->workspaceId())
                ->ofType('supplier')
                ->active()
                ->whereIn('id', $defaultSupplierIds->all())
                ->get(['id', 'name', 'code', 'status'])
                ->keyBy('id');

        $byReference = [];
        foreach ($products as $product) {
            $reference = strtoupper(trim($product->productReferenceCode()));
            if ($reference !== '') $byReference[$reference] = $product;
        }

        return [
            'by_id' => $products->keyBy('id')->all(),
            'by_code' => $products->filter(fn ($product) => filled($product->code))->keyBy(fn ($product) => strtoupper(trim((string) $product->code)))->all(),
            'by_display' => $products->keyBy(fn ($product) => strtoupper($product->productDisplayCode()))->all(),
            'by_reference' => $byReference,
            'active_suppliers_by_id' => $activeSuppliers->all(),
        ];
    }

    private function lookupProduct(string $value, array $maps): ?MasterRecord
    {
        $value = trim($value);
        if ($value === '') return null;
        if (ctype_digit($value) && isset($maps['by_id'][(int) $value])) return $maps['by_id'][(int) $value];

        $key = strtoupper($value);
        return $maps['by_display'][$key]
            ?? $maps['by_code'][$key]
            ?? $maps['by_reference'][$key]
            ?? null;
    }

    /** @return array<int,string> */
    private function activePhoneCountryCodes(): array
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('phone_country_code')
            ->active()
            ->pluck('name')
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => preg_match('/^\+[0-9]{1,4}$/', $value) === 1)
            ->unique()
            ->sortByDesc(fn (string $value) => strlen($value))
            ->values()
            ->all();
    }

    /** @return array{country_code:?string,phone:?string,error:?string} */
    private function resolveShippingPhone(string $value, array $activeCodes): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['country_code' => null, 'phone' => null, 'error' => null];
        }

        if (!str_starts_with($value, '+')) {
            return [
                'country_code' => null,
                'phone' => null,
                'error' => 'Phone Number must include an international country code, for example +880 1712345678',
            ];
        }

        $countryCode = null;
        foreach ($activeCodes as $code) {
            if (str_starts_with($value, $code)) {
                $countryCode = $code;
                break;
            }
        }

        if ($countryCode === null) {
            return [
                'country_code' => null,
                'phone' => null,
                'error' => 'Phone Number country code is not active in Phone Country Code Master Data',
            ];
        }

        $phone = ltrim(trim(substr($value, strlen($countryCode))), " \t-");
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($phone === '' || strlen($phoneDigits) < 5 || preg_match('/^[0-9()\s.\-]{5,40}$/', $phone) !== 1) {
            return [
                'country_code' => $countryCode,
                'phone' => null,
                'error' => 'Phone Number must contain a valid phone number after the country code',
            ];
        }

        return ['country_code' => $countryCode, 'phone' => $phone, 'error' => null];
    }

    /** @return array<string,MasterRecord> */
    private function urgencyMap(string $type): array
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType($type)
            ->active()
            ->get(['id', 'code', 'name'])
            ->keyBy(fn (MasterRecord $record) => mb_strtolower(trim((string) $record->name)))
            ->all();
    }

    /** @return array{label:string,id:?int,error:?string} */
    private function resolveUrgency(string $value, array $map): array
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $value)) ?? $value));
        if ($normalized === '' || $normalized === 'normal') return ['label' => 'Normal', 'id' => null, 'error' => null];

        $labels = ['urgent' => 'Urgent', 'super urgent' => 'Super Urgent'];
        if (!isset($labels[$normalized])) {
            return ['label' => trim($value), 'id' => null, 'error' => 'must be Normal, Urgent or Super Urgent'];
        }

        $record = $map[$normalized] ?? null;
        if (!$record) {
            return ['label' => $labels[$normalized], 'id' => null, 'error' => $labels[$normalized].' is not active in Master Data'];
        }

        return ['label' => $labels[$normalized], 'id' => (int) $record->id, 'error' => null];
    }

    private function workflowTemplates(): Collection
    {
        return WorkflowTemplate::query()
            ->where('workspace_id', app(SetupContext::class)->workspaceId())
            ->where('is_active', true)
            ->with([
                'clients:id',
                'phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
            ])
            ->get();
    }

    /**
     * Resolve only a client-specific Order workflow. Generic all-client Order
     * workflows are intentionally left for explicit user selection in Bulk Import.
     *
     * @return array{workflow:WorkflowTemplate,phase:mixed}|null
     */
    private function resolveClientOrderWorkflow(Collection $templates, int $clientId): ?array
    {
        $workflows = $templates
            ->filter(fn (WorkflowTemplate $workflow) => $workflow->applies_to === 'orders'
                && $workflow->client_availability === 'specific'
                && $workflow->clients->contains('id', $clientId))
            ->sortBy(fn (WorkflowTemplate $workflow) => [
                $workflow->is_default ? 0 : 1,
                mb_strtolower($workflow->name),
            ]);

        foreach ($workflows as $workflow) {
            $phase = $this->startPhase($workflow);
            if ($phase) return compact('workflow', 'phase');
        }

        return null;
    }

    /** @return array{workflow:WorkflowTemplate,phase:mixed}|null */
    private function resolveManualOrderWorkflow(Collection $templates, int $clientId, int $workflowId): ?array
    {
        /** @var WorkflowTemplate|null $workflow */
        $workflow = $templates->first(fn (WorkflowTemplate $candidate) => (int) $candidate->id === $workflowId);
        if (!$workflow || !$this->workflowAvailableForClient($workflow, $clientId)) return null;

        $phase = $this->startPhase($workflow);
        return $phase ? compact('workflow', 'phase') : null;
    }

    /** @return array<int,array{id:int,name:string,client_specific:bool,is_default:bool}> */
    private function availableOrderWorkflowOptions(Collection $templates, int $clientId): array
    {
        return $templates
            ->filter(fn (WorkflowTemplate $workflow) => $this->workflowAvailableForClient($workflow, $clientId) && $this->startPhase($workflow))
            ->sortBy(fn (WorkflowTemplate $workflow) => [
                $workflow->client_availability === 'specific' ? 0 : 1,
                $workflow->is_default ? 0 : 1,
                mb_strtolower($workflow->name),
            ])
            ->map(fn (WorkflowTemplate $workflow) => [
                'id' => (int) $workflow->id,
                'name' => (string) $workflow->name,
                'client_specific' => $workflow->client_availability === 'specific',
                'is_default' => (bool) $workflow->is_default,
            ])
            ->values()
            ->all();
    }

    private function workflowAvailableForClient(WorkflowTemplate $workflow, ?int $clientId): bool
    {
        if ($workflow->applies_to !== 'orders') return false;
        if (! app(OrderWorkflowSetupService::class)->isReadyForOrderCreation((int) $workflow->id)) return false;
        if ($workflow->client_availability === 'all') return true;

        return $clientId
            && $workflow->client_availability === 'specific'
            && $workflow->clients->contains('id', $clientId);
    }

    private function startPhase(WorkflowTemplate $workflow): mixed
    {
        return $workflow->phases->first(fn ($phase) => $phase->is_active && $phase->allow_job_start);
    }

    /** @return array<string,Collection<int,FlowJob>> */
    private function existingJobsByReferences(array $references): array
    {
        $map = [];
        foreach (array_chunk($references, 400) as $chunk) {
            FlowJob::query()->whereIn('order_number', $chunk)->orderByDesc('id')->get()
                ->groupBy('order_number')
                ->each(function (Collection $jobs, string $reference) use (&$map): void { $map[$reference] = $jobs; });
        }
        return $map;
    }

    /** @return array<string,FlowJob> */
    private function existingJobsBySourceIds(array $sourceIds): array
    {
        $map = [];
        foreach (array_chunk($sourceIds, 400) as $chunk) {
            FlowJob::withTrashed()->whereIn('source_row_id', $chunk)->get()->each(function (FlowJob $job) use (&$map): void {
                if ($job->source_row_id !== null) $map[$job->source_row_id] = $job;
            });
        }
        return $map;
    }

    private function canUpdateExisting(User $actor, FlowJob $job): bool
    {
        if ($job->trashed()) return false;
        return app(AccessControlService::class)->canEditJob($actor, $job);
    }

    private function loadToken(string $token, User $actor): array
    {
        if (!Str::isUuid($token)) throw new RuntimeException('The import session is invalid. Upload the file again.');
        $path = $this->tempPath($actor, $token);
        if (!Storage::disk('local')->exists($path)) throw new RuntimeException('The import session expired. Upload the file again.');
        $source = json_decode(Storage::disk('local')->get($path), true);
        if (!is_array($source) || (int) ($source['user_id'] ?? 0) !== (int) $actor->id || (int) ($source['workspace_id'] ?? 0) !== app(SetupContext::class)->workspaceId()) {
            throw new RuntimeException('The import session is invalid. Upload the file again.');
        }
        return $source;
    }

    private function tempPath(User $actor, string $token): string
    {
        return 'bulk-order-imports/tmp/'.$actor->id.'/'.$token.'.json';
    }

    private function auditPayload(array $row): array
    {
        return collect($row)->except([
            'errors', 'warnings', 'status', 'existing_job_id',
            'client_resolved_label', 'workflow_resolved_label',
            'workflow_options', 'workflow_requires_selection', 'workflow_manual_selected_id', 'workflow_selection_source',
        ])->all();
    }
}
