<?php

namespace Database\Seeders\FlowTrack;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowDocumentVersion;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobItem;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\Permission;
use App\Modules\OrderFlow\Data\Models\Role;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use App\Modules\OrderFlow\Data\Models\Workspace;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Notifications\FlowTrackAlert;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class FlowTrackSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::create([
            'name' => 'FlowTrack Demo Workspace',
            'slug' => 'flowtrack-demo',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'USD',
        ]);

        $permissions = collect([
            ['Dashboard', 'dashboard.view', 'Workspace'],
            ['View assigned jobs', 'jobs.view-assigned', 'Jobs'],
            ['View all jobs', 'jobs.view-all', 'Jobs'],
            ['Create jobs', 'jobs.create', 'Jobs'],
            ['Manage all jobs', 'jobs.manage-all', 'Jobs'],
            ['View assigned tasks', 'tasks.view-assigned', 'Tasks'],
            ['View all tasks', 'tasks.view-all', 'Tasks'],
            ['Manage clients', 'clients.manage', 'Clients'],
            ['Manage documents', 'documents.manage', 'Documents'],
            ['Link documents to Jobs and Workflow Phases', 'documents.link', 'Documents'],
            ['View reports', 'reports.view', 'Reports'],
            ['View financial values', 'financials.view', 'Finance'],
            ['Manage workflows', 'workflows.manage', 'Administration'],
            ['Manage master data', 'master-data.manage', 'Administration'],
            ['Manage administration', 'administration.manage', 'Administration'],
        ])->mapWithKeys(function (array $permission) {
            $model = Permission::create(['name' => $permission[0], 'slug' => $permission[1], 'group' => $permission[2]]);
            return [$model->slug => $model];
        });

        $roles = collect([
            'administrator' => ['Administrator', $permissions->keys()->all()],
            'operations-manager' => ['Operations Manager', $permissions->keys()->all()],
            'sales-manager' => ['Sales Manager', ['dashboard.view', 'jobs.view-all', 'jobs.create', 'jobs.manage-all', 'tasks.view-all', 'clients.manage', 'documents.manage', 'reports.view', 'financials.view']],
            'member' => ['Member', ['dashboard.view', 'jobs.view-assigned', 'tasks.view-assigned', 'documents.manage', 'documents.link']],
            'document-uploader-workflow-linker' => ['Document Uploader & Workflow Linker', ['dashboard.view', 'jobs.view-assigned', 'tasks.view-assigned', 'documents.manage', 'documents.link']],
        ])->mapWithKeys(function (array $definition, string $slug) use ($workspace, $permissions) {
            $role = Role::create(['workspace_id' => $workspace->id, 'name' => $definition[0], 'slug' => $slug, 'is_system' => true]);
            $role->permissions()->sync($permissions->only($definition[1])->pluck('id'));
            return [$slug => $role];
        });

        $master = $this->seedMasterData($workspace->id);
        $users = $this->seedUsers($workspace->id, $roles, $master['department']);
        $packs = $this->seedTaskPacks($workspace->id, $users, $master);
        $workflows = $this->seedWorkflows($workspace->id, $packs, $master['document_category']);
        $clients = $this->seedClients($workspace->id, $users);
        $this->seedJobs($workspace->id, $clients, $users, $workflows, $master);
        $this->seedNotifications($users);
    }

    private function seedMasterData(int $workspaceId): array
    {
        $groups = [
            MasterDataType::Department->value => [
                ['MGT', 'Management', 'Operations and leadership'], ['SAL', 'Sales', 'Client and quotation management'],
                ['DES', 'Design', 'Artwork preparation'], ['SRC', 'Sourcing', 'Supplier and sample coordination'],
                ['PRO', 'Production', 'Factory execution and quality'], ['QTY', 'Quality', 'Inspection and compliance'],
                ['SHP', 'Shipment', 'Logistics and delivery'], ['ACC', 'Accounts', 'Invoice and collection'], ['SAM', 'Sampling', 'Sample coordination'],
            ],
            MasterDataType::ProductCategory->value => [
                ['LAN', 'Lanyards', 'Promotional accessories'], ['WRI', 'Wristbands', 'Silicone, fabric and event bands'],
                ['CAP', 'Caps', 'Sports and promotional headwear'], ['GAR', 'Garments', 'Jerseys, T-shirts and uniforms'],
                ['BAG', 'Backpacks & Bags', 'Drawstring, travel and custom bags'], ['LUG', 'Luggage', 'Hard and soft luggage'],
                ['GFT', 'Gift Sets', 'Bundled promotional kits'], ['TRA', 'Travel Accessories', 'Travel tags and accessories'],
            ],
            MasterDataType::Supplier->value => [
                ['SUP-001', 'Guangzhou Textile Works', 'Garments and lanyards'], ['SUP-002', 'Dongguan Cap Factory', 'Caps and embroidery'],
                ['SUP-003', 'Jiangmen Travel Goods', 'Backpacks and luggage'], ['SUP-004', 'Foshan Silicone Products', 'Wristbands'],
            ],
            MasterDataType::ProductionUnit->value => [
                ['PU-01', 'Guangzhou Factory 1', 'Garments and printing'], ['PU-02', 'Guangzhou Factory 2', 'Lanyards and accessories'],
                ['PU-03', 'Jiangmen Luggage Plant', 'Luggage and backpacks'],
            ],
            MasterDataType::ShipmentMethod->value => [
                ['AIR', 'Air Freight', 'Airport-to-airport and door delivery'], ['SEA', 'Sea Freight', 'FCL and LCL'],
                ['EXP', 'Express Courier', 'DHL, FedEx and UPS'], ['ROAD', 'Road Freight', 'Regional delivery'],
            ],
            MasterDataType::Currency->value => [
                ['USD', 'US Dollar', 'Default commercial currency'], ['CNY', 'Chinese Yuan', 'Local sourcing currency'],
                ['GBP', 'British Pound', 'UK clients'], ['EUR', 'Euro', 'European clients'],
            ],
            MasterDataType::DocumentCategory->value => [
                ['REQ', 'Client Requirement', 'References and specifications'], ['QUO', 'Quotation', 'Commercial quotation versions'],
                ['CST', 'Costing Sheet', 'Internal supplier and costing worksheet'], ['PO', 'Purchase Order', 'Client confirmation and PO'],
                ['ART', 'Artwork', 'Working artwork files'], ['APR', 'Artwork Approval', 'Final approved artwork'],
                ['SAM', 'Sample Approval', 'Swatch or sample confirmation'], ['QCI', 'Quality Inspection', 'QC evidence and reports'],
                ['SHP', 'Shipping Document', 'Packing list, AWB and B/L'], ['INV', 'Invoice', 'Invoice and payment documents'],
            ],
            MasterDataType::Priority->value => [
                ['LOW', 'Low', 'Normal monitoring'], ['MED', 'Medium', 'Standard business priority'],
                ['HIG', 'High', 'Close monitoring required'], ['CRI', 'Critical', 'Immediate management attention'],
            ],
            MasterDataType::TaskStatus->value => [
                ['NS', 'Not Started', 'Not yet available'], ['READY', 'Ready', 'Can be started'], ['IP', 'In Progress', 'Work underway'],
                ['WC', 'Waiting for Client', 'External client dependency'], ['WS', 'Waiting for Supplier', 'External supplier dependency'],
                ['WIA', 'Waiting for Internal Approval', 'Internal decision required'], ['REV', 'Revision Required', 'Returned for correction'],
                ['BLK', 'Blocked', 'Cannot continue'], ['HOLD', 'On Hold', 'Paused'], ['CMP', 'Completed', 'Work finished'],
            ],
        ];

        $result = [];
        foreach ($groups as $type => $rows) {
            foreach ($rows as $index => [$code, $name, $description]) {
                $record = MasterRecord::create([
                    'workspace_id' => $workspaceId,
                    'type' => $type,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'sort_order' => $index,
                ]);
                $result[$type][$code] = $record;
            }
        }

        $products = [
            ['PRD-001', 'Woven Lanyard', 'LAN'], ['PRD-002', 'Silicone Wristband', 'WRI'], ['PRD-003', 'Performance Cap', 'CAP'],
            ['PRD-004', 'Dry-fit T-shirt', 'GAR'], ['PRD-005', 'Drawstring Backpack', 'BAG'], ['PRD-006', 'Hard-shell Luggage', 'LUG'],
            ['PRD-007', 'Polo Shirt', 'GAR'], ['PRD-008', 'Canvas Tote Bag', 'BAG'], ['PRD-009', 'Welcome Kit', 'GFT'],
            ['PRD-010', 'PVC Luggage Tag', 'TRA'], ['PRD-011', 'Travel Duffel', 'BAG'], ['PRD-012', 'Uniform Set', 'GAR'],
        ];
        foreach ($products as $index => [$code, $name, $parentCode]) {
            $result[MasterDataType::Product->value][$code] = MasterRecord::create([
                'workspace_id' => $workspaceId,
                'parent_id' => $result[MasterDataType::ProductCategory->value][$parentCode]->id,
                'type' => MasterDataType::Product->value,
                'code' => $code,
                'name' => $name,
                'description' => $result[MasterDataType::ProductCategory->value][$parentCode]->name,
                'sort_order' => $index,
            ]);
        }

        return [
            'department' => $result[MasterDataType::Department->value],
            'product_category' => $result[MasterDataType::ProductCategory->value],
            'product' => $result[MasterDataType::Product->value],
            'currency' => $result[MasterDataType::Currency->value],
            'priority' => $result[MasterDataType::Priority->value],
            'task_status' => $result[MasterDataType::TaskStatus->value],
            'document_category' => $result[MasterDataType::DocumentCategory->value],
        ];
    }

    private function seedUsers(int $workspaceId, $roles, array $departments): array
    {
        $rows = [
            ['Li Wei', 'LW', 'manager@flowtrack.cn', 'Operations Manager', 'MGT', 'operations-manager'],
            ['Chen Mei', 'CM', 'chen.mei@flowtrack.cn', 'Sales Manager', 'SAL', 'sales-manager'],
            ['Zhang Min', 'ZM', 'zhang.min@flowtrack.cn', 'Sales Executive', 'SAL', 'member'],
            ['Liu Fang', 'LF', 'liu.fang@flowtrack.cn', 'Senior Designer', 'DES', 'member'],
            ['Wang Jun', 'WJ', 'wang.jun@flowtrack.cn', 'Sourcing Coordinator', 'SRC', 'member'],
            ['Zhao Lin', 'ZL', 'zhao.lin@flowtrack.cn', 'Production Coordinator', 'PRO', 'member'],
            ['Sun Hao', 'SH', 'sun.hao@flowtrack.cn', 'Quality Inspector', 'QTY', 'member'],
            ['Xu Na', 'XN', 'xu.na@flowtrack.cn', 'Shipping Executive', 'SHP', 'member'],
            ['Yang Lei', 'YL', 'yang.lei@flowtrack.cn', 'Accounts Officer', 'ACC', 'member'],
            ['He Ping', 'HP', 'he.ping@flowtrack.cn', 'Sample Coordinator', 'SAM', 'member'],
            ['Tang Rui', 'TR', 'tang.rui@flowtrack.cn', 'Designer', 'DES', 'member'],
            ['Gao Yu', 'GY', 'gao.yu@flowtrack.cn', 'Factory Supervisor', 'PRO', 'member'],
        ];

        $result = [];
        foreach ($rows as [$name, $initials, $email, $title, $department, $role]) {
            $user = User::create([
                'name' => $name,
                'initials' => $initials,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make('demo123'),
            ]);
            WorkspaceMembership::create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'role_id' => $roles[$role]->id,
                'department_id' => $departments[$department]->id,
                'job_title' => $title,
                'joined_at' => now(),
            ]);
            $result[$name] = $user;
        }

        return $result;
    }

    private function seedTaskPacks(int $workspaceId, array $users, array $master): array
    {
        $definitions = [
            'QUO' => ['Quotation', ['Review client requirement', 'Collect supplier / factory costing', 'Prepare quotation', 'Internal quotation review', 'Submit quotation']],
            'CNF' => ['Order Confirmation', ['Upload client confirmation / PO', 'Confirm final specifications', 'Lock price and quantity', 'Confirm delivery target', 'Assign job coordinator']],
            'ART' => ['Artwork', ['Collect artwork requirements', 'Prepare artwork', 'Internal artwork review', 'Submit artwork to client', 'Record approval / revision']],
            'SAM' => ['Swatch', ['Prepare swatch or sample', 'Internal swatch review', 'Arrange courier submission', 'Record client feedback', 'Close sample approval']],
            'PRO' => ['Production', ['Confirm materials ready', 'Start production', 'Update production milestone', 'Perform quality inspection', 'Complete packing']],
            'SHP' => ['Shipment', ['Confirm shipping information', 'Prepare packing list', 'Book shipment', 'Upload shipment documents', 'Record tracking number']],
            'INV' => ['Invoice', ['Prepare invoice', 'Internal invoice review', 'Submit invoice', 'Record payment follow-up', 'Close outstanding balance']],
        ];
        $assignees = [$users['Zhang Min'], $users['Wang Jun'], $users['Liu Fang'], $users['Zhao Lin'], $users['Yang Lei']];
        $packs = [];

        foreach ($definitions as $code => [$name, $items]) {
            $pack = TaskPack::create(['workspace_id' => $workspaceId, 'code' => $code, 'name' => $name, 'description' => "Reusable {$name} phase tasks"]);
            foreach ($items as $index => $title) {
                TaskPackItem::create([
                    'task_pack_id' => $pack->id,
                    'title' => $title,
                    'default_assignee_id' => $assignees[$index % count($assignees)]->id,
                    'priority_id' => $master['priority'][$index === 2 ? 'HIG' : 'MED']->id,
                    'due_offset_days' => $index + 1,
                    'is_required' => $index < 4,
                    'sort_order' => $index,
                ]);
            }
            $packs[$name] = $pack;
        }

        return $packs;
    }

    private function seedWorkflows(int $workspaceId, array $packs, array $documentCategories): array
    {
        $standard = WorkflowTemplate::create([
            'workspace_id' => $workspaceId,
            'code' => 'STANDARD',
            'name' => 'Standard Promotional Products',
            'description' => 'Full request-to-payment process for new enquiries and custom orders.',
            'is_active' => true,
            'is_default' => true,
        ]);

        $phases = [
            ['Request Received', 'Request', 'Quotation', true, false, false, 'Job created', 'Requirement reviewed', 'REQ'],
            ['Quotation in Progress', 'Quotation', 'Quotation', true, true, false, 'Requirement confirmed', 'Quotation prepared', 'CST'],
            ['Quotation Submitted', 'Submitted', 'Quotation', true, true, false, 'Internal review complete', 'Quotation sent', 'QUO'],
            ['Negotiation', 'Negotiation', 'Quotation', true, true, false, 'Client feedback received', 'Commercial decision', 'QUO'],
            ['Confirmed Order', 'Confirmed', 'Order Confirmation', true, true, true, 'Client confirmation received', 'Order information locked', 'PO'],
            ['Artwork', 'Artwork', 'Artwork', true, true, true, 'Order confirmed', 'Artwork approved', 'APR'],
            ['Swatch / Sample', 'Swatch', 'Swatch', true, true, true, 'Artwork approved', 'Sample approved or waived', 'SAM'],
            ['Production', 'Production', 'Production', true, false, true, 'Pre-production approvals complete', 'Production and QC complete', 'QCI'],
            ['Shipment', 'Shipment', 'Shipment', true, false, true, 'Packing complete', 'Shipment released', 'SHP'],
            ['Invoice & Payment', 'Invoice', 'Invoice', true, false, true, 'Shipment confirmed', 'Outstanding balance closed', 'INV'],
            ['Completed', 'Completed', null, false, false, true, 'Payment complete', 'Job closure approved', null],
        ];

        foreach ($phases as $index => [$name, $short, $pack, $allowStart, $skippable, $approval, $entry, $exit, $document]) {
            WorkflowPhase::create([
                'workflow_template_id' => $standard->id,
                'task_pack_id' => $pack ? $packs[$pack]->id : null,
                'document_category_id' => $document ? $documentCategories[$document]->id : null,
                'name' => $name,
                'short_name' => $short,
                'sequence' => $index + 1,
                'allow_job_start' => $allowStart,
                'is_skippable' => $skippable,
                'requires_approval' => false,
                'entry_condition' => $entry,
                'exit_condition' => $exit,
            ]);
        }

        $repeat = WorkflowTemplate::create([
            'workspace_id' => $workspaceId,
            'code' => 'REPEAT',
            'name' => 'Repeat Order',
            'description' => 'Starts from confirmation or production using approved specifications.',
            'is_active' => true,
        ]);
        foreach ($standard->phases()->get() as $phase) {
            $repeat->phases()->create([
                ...$phase->only(['task_pack_id', 'document_category_id', 'name', 'short_name', 'sequence', 'is_skippable', 'requires_approval', 'auto_advance_on_ready', 'is_active', 'entry_condition', 'exit_condition']),
                'allow_job_start' => $phase->sequence >= 5 && $phase->sequence < 11,
            ]);
        }

        $sample = WorkflowTemplate::create([
            'workspace_id' => $workspaceId,
            'code' => 'SAMPLE',
            'name' => 'Sample-Only Request',
            'description' => 'Quotation, artwork and sample approval without bulk production and shipment.',
            'is_active' => true,
        ]);
        $sequence = 1;
        foreach ($standard->phases()->whereIn('sequence', [1, 2, 3, 4, 6, 7, 11])->get() as $phase) {
            $sample->phases()->create([
                ...$phase->only(['task_pack_id', 'document_category_id', 'name', 'short_name', 'is_skippable', 'requires_approval', 'auto_advance_on_ready', 'is_active', 'entry_condition', 'exit_condition']),
                'sequence' => $sequence++,
                'allow_job_start' => $phase->short_name !== 'Completed',
            ]);
        }

        return ['standard' => $standard, 'repeat' => $repeat, 'sample' => $sample];
    }

    private function seedClients(int $workspaceId, array $users): array
    {
        $rows = [
            ['NS', 'NorthStar Promotions', 'United States', 'Emily Carter', 'emily@northstarpromo.com', 'Chen Mei', 18400],
            ['BE', 'Brightline Events', 'United Kingdom', 'Daniel Brooks', 'daniel@brightline.co.uk', 'Chen Mei', 7200],
            ['AS', 'ActiveWear Sports', 'Australia', 'Oliver Smith', 'oliver@activewear.au', 'Li Wei', 0],
            ['UT', 'Urban Travel Gear', 'Germany', 'Anna Keller', 'anna@urbangear.de', 'Wang Jun', 29600],
            ['SM', 'Summit Merchandise', 'Canada', 'Liam Martin', 'liam@summitmerch.ca', 'Chen Mei', 9000],
            ['GB', 'Global Brand Works', 'Singapore', 'Grace Lim', 'grace@gbw.sg', 'Li Wei', 0],
            ['VM', 'Viva Marketing', 'Spain', 'Lucia Ramos', 'lucia@viva.es', 'Zhao Lin', 4200],
            ['PR', 'Peak Retail Group', 'France', 'Hugo Bernard', 'hugo@peakretail.fr', 'Wang Jun', 0],
            ['MS', 'Momentum Schools', 'New Zealand', 'Sophie Wilson', 'sophie@momentum.nz', 'Zhao Lin', 3500],
            ['AH', 'Apex Hospitality', 'United Arab Emirates', 'Omar Hassan', 'omar@apexhospitality.ae', 'Chen Mei', 11200],
        ];
        $clients = [];
        foreach ($rows as [$code, $name, $country, $contact, $email, $manager, $outstanding]) {
            $clients[$code] = Client::create([
                'workspace_id' => $workspaceId,
                'account_manager_id' => $users[$manager]->id,
                'code' => $code,
                'name' => $name,
                'country' => $country,
                'contact_name' => $contact,
                'email' => $email,
                'outstanding_balance' => $outstanding,
            ]);
        }

        return $clients;
    }

    private function seedJobs(int $workspaceId, array $clients, array $users, array $workflows, array $master): void
    {
        $demoDocument = 'FlowTrack seeded demonstration document. Replace this file with the real uploaded version.';
        Storage::disk('public')->put('flowtrack/demo/placeholder.txt', $demoDocument);

        $rows = [
            ['JOB-2026-00125', 'ORD-2026-00089', '10,000 woven lanyards for annual conference', 'NS', 'PRD-001', 10000, 12400, 8, 'at_risk', 68, 'Chen Mei', 'Li Wei', '2026-08-08', 'HIG', 'Resolve dye colour variation', true],
            ['JOB-2026-00124', 'ORD-2026-00088', 'Custom navy baseball caps', 'BE', 'PRD-003', 2500, 16750, 6, 'needs_attention', 43, 'Zhang Min', 'Li Wei', '2026-08-21', 'HIG', 'Client artwork approval', true],
            ['JOB-2026-00123', 'ORD-2026-00087', 'Junior football club jerseys', 'AS', 'PRD-004', 1200, 22800, 7, 'on_track', 55, 'Chen Mei', 'Zhao Lin', '2026-09-03', 'MED', 'Approve second fabric swatch', false],
            ['JOB-2026-00122', 'ORD-2026-00086', 'Premium carry-on luggage collection', 'UT', 'PRD-006', 800, 62400, 8, 'blocked', 61, 'Zhang Min', 'Wang Jun', '2026-08-29', 'CRI', 'Confirm replacement wheel supplier', true],
            ['JOB-2026-00121', 'ORD-2026-00085', 'Conference drawstring backpacks', 'SM', 'PRD-005', 5000, 19500, 9, 'on_track', 82, 'Chen Mei', 'Xu Na', '2026-08-04', 'HIG', 'Complete shipment booking', false],
            ['JOB-2026-00120', 'ORD-2026-00084', 'Silicone wristbands — 6 colour mix', 'GB', 'PRD-002', 20000, 9800, 10, 'needs_attention', 91, 'Zhang Min', 'Yang Lei', '2026-07-18', 'MED', 'Follow up overdue balance', true],
            ['JOB-2026-00119', null, 'Hotel staff polo shirts', 'AH', 'PRD-007', 1800, 0, 4, 'on_track', 24, 'Chen Mei', 'Li Wei', '2026-09-18', 'MED', 'Revise quotation pricing', false],
            ['JOB-2026-00118', 'ORD-2026-00083', 'Retail launch canvas tote bags', 'PR', 'PRD-008', 6000, 27600, 11, 'completed', 100, 'Zhang Min', 'Li Wei', '2026-07-12', 'MED', 'Closed', false],
            ['JOB-2026-00117', 'ORD-2026-00082', 'School house-colour wristbands', 'MS', 'PRD-002', 7500, 6900, 9, 'on_track', 86, 'Chen Mei', 'Xu Na', '2026-08-02', 'MED', 'Monitor DHL delivery', false],
            ['JOB-2026-00116', null, 'Festival staff badge lanyards', 'VM', 'PRD-001', 3500, 0, 3, 'on_track', 18, 'Zhang Min', 'Chen Mei', '2026-09-02', 'LOW', 'Await quotation response', false],
            ['JOB-2026-00115', 'ORD-2026-00081', 'Premium laptop backpacks', 'UT', 'PRD-005', 1500, 47250, 6, 'at_risk', 39, 'Chen Mei', 'Liu Fang', '2026-08-25', 'HIG', 'Artwork revision v3', true],
            ['JOB-2026-00114', 'ORD-2026-00080', 'Running event dry-fit T-shirts', 'AS', 'PRD-004', 4000, 28800, 8, 'on_track', 72, 'Zhang Min', 'Zhao Lin', '2026-08-16', 'HIG', 'Complete 75% production update', false],
            ['JOB-2026-00113', null, 'Employee welcome kit quotation', 'NS', 'PRD-009', 900, 0, 2, 'on_track', 10, 'Chen Mei', 'Wang Jun', '2026-10-01', 'LOW', 'Collect supplier costs', false],
            ['JOB-2026-00112', 'ORD-2026-00079', 'Executive travel duffel bags', 'GB', 'PRD-011', 600, 20400, 10, 'on_track', 94, 'Zhang Min', 'Yang Lei', '2026-07-28', 'MED', 'Collect final 40% payment', false],
            ['JOB-2026-00111', 'ORD-2026-00078', 'Branded marathon caps', 'SM', 'PRD-003', 3000, 17100, 7, 'needs_attention', 49, 'Chen Mei', 'He Ping', '2026-08-17', 'HIG', 'Supplier to resubmit colour swatch', true],
            ['JOB-2026-00110', 'ORD-2026-00077', 'Resort housekeeping uniforms', 'AH', 'PRD-012', 1100, 31900, 8, 'delayed', 63, 'Zhang Min', 'Zhao Lin', '2026-08-10', 'CRI', 'Recover 3-day production delay', true],
            ['JOB-2026-00109', null, 'Promotional luggage tags', 'PR', 'PRD-010', 9000, 0, 1, 'on_track', 4, 'Chen Mei', 'Li Wei', '2026-09-30', 'LOW', 'Review client specifications', false],
            ['JOB-2026-00108', 'ORD-2026-00076', 'Corporate anniversary jackets', 'BE', 'PRD-004', 700, 24500, 9, 'on_track', 84, 'Zhang Min', 'Xu Na', '2026-08-01', 'HIG', 'Shipment release approval', false],
        ];

        $phases = $workflows['standard']->phases()->get()->keyBy('sequence');
        foreach ($rows as $jobIndex => [$code, $order, $title, $clientCode, $productCode, $qty, $amount, $phaseSequence, $health, $progress, $owner, $coordinator, $delivery, $priority, $next, $attention]) {
            $product = $master['product'][$productCode];
            $phase = $phases[$phaseSequence];
            $job = FlowJob::create([
                'workspace_id' => $workspaceId,
                'created_by' => $users['Li Wei']->id,
                'client_id' => $clients[$clientCode]->id,
                'workflow_template_id' => $workflows['standard']->id,
                'current_phase_id' => $phase->id,
                'started_phase_id' => $phases[1]->id,
                'product_id' => $product->id,
                'product_category_id' => $product->parent_id,
                'currency_id' => $master['currency']['USD']->id,
                'priority_id' => $master['priority'][$priority]->id,
                'owner_id' => $users[$owner]->id,
                'coordinator_id' => $users[$coordinator]->id,
                'code' => $code,
                'order_code' => $order,
                'title' => $title,
                'quantity' => $qty,
                'quoted_amount' => $amount,
                'delivery_date' => $delivery,
                'status' => $phaseSequence === 11 ? 'completed' : 'in_progress',
                'health' => $health,
                'progress' => $progress,
                'next_action' => $next,
                'milestone' => $phase->exit_condition,
                'attention_required' => $attention,
            ]);

            FlowJobItem::create([
                'flow_job_id' => $job->id,
                'product_id' => $product->id,
                'product_category_id' => $product->parent_id,
                'quantity' => $qty,
                'sort_order' => 0,
            ]);

            foreach (collect([$users['Li Wei']->id, $users[$owner]->id, $users[$coordinator]->id])->unique() as $memberId) {
                FlowJobMember::create([
                    'flow_job_id' => $job->id,
                    'user_id' => $memberId,
                    'access_level' => 'manager',
                    'can_manage_tasks' => true,
                    'can_upload_documents' => true,
                    'can_view_financials' => $memberId === $users[$owner]->id,
                ]);
            }

            foreach ($phases as $sequence => $workflowPhase) {
                FlowJobPhaseHistory::create([
                    'flow_job_id' => $job->id,
                    'workflow_phase_id' => $workflowPhase->id,
                    'changed_by' => $users['Li Wei']->id,
                    'status' => $sequence < $phaseSequence ? 'completed' : ($sequence === $phaseSequence ? 'current' : 'pending'),
                    'entered_at' => $sequence <= $phaseSequence ? now()->subDays(max(0, $phaseSequence - $sequence) * 2) : null,
                    'completed_at' => $sequence < $phaseSequence ? now()->subDays(max(0, $phaseSequence - $sequence) * 2 - 1) : null,
                ]);

            }

            $packItems = $phase->taskPack?->items()->get() ?? collect();
            foreach ($packItems as $taskIndex => $item) {
                $statusCodes = $taskIndex === 0 ? 'CMP' : ($taskIndex === 1 ? 'IP' : ($attention && $taskIndex === 2 ? 'BLK' : 'READY'));
                $task = FlowTask::create([
                    'flow_job_id' => $job->id,
                    'workflow_phase_id' => $phase->id,
                    'task_pack_item_id' => $item->id,
                    'created_by' => $users['Li Wei']->id,
                    'assignee_id' => $item->default_assignee_id ?: $users[$coordinator]->id,
                    'task_status_id' => $master['task_status'][$statusCodes]->id,
                    'priority_id' => $master['priority'][$priority]->id,
                    'code' => 'TSK-'.str_pad((string) (301 + $jobIndex * 10 + $taskIndex), 6, '0', STR_PAD_LEFT),
                    'title' => $item->title,
                    'description' => $item->description,
                    'start_rule' => 'When the current workflow phase begins',
                    'due_date' => now()->addDays($taskIndex - 1)->toDateString(),
                    'progress' => [100, 75, 45, 10][$taskIndex] ?? 0,
                    'attention_required' => $attention && $taskIndex === 2,
                    'blocker_reason' => $attention && $taskIndex === 2 ? $next : null,
                    'sort_order' => $taskIndex,
                    'completed_at' => $statusCodes === 'CMP' ? now() : null,
                ]);
                foreach (['Confirm required information', 'Upload supporting file', 'Add completion comment'] as $checkIndex => $label) {
                    $task->checklistItems()->create(['label' => $label, 'is_completed' => $checkIndex < $taskIndex, 'sort_order' => $checkIndex]);
                }
                $task->comments()->create(['user_id' => $users['Li Wei']->id, 'body' => $attention && $taskIndex === 2 ? 'Please update the blocker and recovery date.' : 'Task created from the workflow task pack.']);
            }

            if ($jobIndex < 10) {
                $document = FlowDocument::create([
                    'workspace_id' => $workspaceId,
                    'flow_job_id' => $job->id,
                    'workflow_phase_id' => $phase->id,
                    'task_pack_item_id' => null,
                    'document_category_id' => $phase->document_category_id,
                    'uploaded_by' => $users['Li Wei']->id,
                    'linked_by' => $users['Li Wei']->id,
                    'linked_at' => now(),
                    'code' => 'DOC-'.str_pad((string) (700 + $jobIndex), 6, '0', STR_PAD_LEFT),
                    'name' => str($phase->short_name.'_'.$code)->replace(' ', '_'),
                    'is_final' => $jobIndex % 4 === 0,
                ]);
                FlowDocumentVersion::create([
                    'flow_document_id' => $document->id,
                    'uploaded_by' => $users['Li Wei']->id,
                    'version' => 1,
                    'disk' => 'public',
                    'path' => 'flowtrack/demo/placeholder.txt',
                    'original_name' => $document->name.'_v1.txt',
                    'mime_type' => 'text/plain',
                    'size' => strlen($demoDocument),
                    'notes' => 'Seeded demonstration document.',
                ]);
            }
        }
    }

    private function seedNotifications(array $users): void
    {
        $alerts = [
            ['Artwork approval overdue', 'JOB-2026-00124 has been waiting for client approval for 3 days.', 'approval', 'JOB-2026-00124'],
            ['Production blocker reported', 'Replacement wheel supplier is not confirmed for JOB-2026-00122.', 'risk', 'JOB-2026-00122'],
            ['You were mentioned', 'Zhao Lin mentioned you in the production recovery discussion.', 'mention', 'JOB-2026-00110'],
            ['Invoice is overdue', 'USD 3,500 remains outstanding for JOB-2026-00120.', 'payment', 'JOB-2026-00120'],
            ['Shipment ready for release', 'JOB-2026-00108 is ready for shipment approval.', 'shipment', 'JOB-2026-00108'],
            ['Swatch revision uploaded', 'Supplier uploaded the second cap-colour swatch.', 'file', 'JOB-2026-00111'],
        ];
        foreach ($alerts as [$title, $text, $type, $job]) {
            $users['Li Wei']->notify(new FlowTrackAlert(compact('title', 'text', 'type', 'job')));
        }
    }
}
