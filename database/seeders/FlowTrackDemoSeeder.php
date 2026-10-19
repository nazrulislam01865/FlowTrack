<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Document;
use App\Models\Department;
use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\FlowJobMember;
use App\Models\FlowJobPhaseHistory;
use App\Models\FlowTaskChecklistItem;
use App\Models\FlowTaskComment;
use App\Models\FlowNotification;
use App\Models\MasterValue;
use App\Models\NotificationRule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Services\AccessControlService;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackTask;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FlowTrackDemoSeeder extends Seeder
{
    public function run(): void
    {
        $departments = collect([
            ['MGT','Management','Operations and leadership'],['SAL','Sales','Client and quotation management'],
            ['DES','Design','Artwork preparation'],['SRC','Sourcing','Supplier and sample coordination'],
            ['PRO','Production','Factory execution and QC'],['QUA','Quality','Quality inspection'],
            ['SHP','Shipment','Logistics and delivery'],['ACC','Accounts','Invoice and collection'],['SAM','Sampling','Samples and swatches'],
        ])->mapWithKeys(function ($d) {
            $model = Department::updateOrCreate(['code'=>$d[0]], ['name'=>$d[1],'description'=>$d[2],'is_active'=>true]);
            return [$d[0] => $model];
        });

        $roles = collect([
            ['operations-manager','Operations Manager'],['sales-manager','Sales Manager'],['sales-executive','Sales Executive'],
            ['designer','Designer'],['sourcing','Sourcing Coordinator'],['production','Production Coordinator'],
            ['quality','Quality Inspector'],['shipment','Shipping Executive'],['accounts','Accounts Officer'],['sampling','Sample Coordinator'],
        ])->mapWithKeys(fn ($r) => [$r[0] => Role::updateOrCreate(['slug'=>$r[0]], ['name'=>$r[1]])]);

        foreach (['dashboard.view','jobs.view','jobs.create','jobs.update','inquiries.view','inquiries.create','inquiries.update','tasks.view','tasks.update','clients.view','documents.view','reports.view','notifications.view','workflow.manage','master.manage','users.manage'] as $slug) {
            [$module] = explode('.', $slug, 2);
            Permission::firstOrCreate(['slug'=>$slug], ['module'=>$module,'name'=>ucwords(str_replace(['.','-'],' ',$slug))]);
        }
        foreach ($roles as $role) {
            $role->permissions()->sync(Permission::whereNotIn('slug', ['workflow.manage','master.manage','users.manage'])->pluck('id'));
            $isManager = $role->slug === 'operations-manager';
            foreach (AccessControlService::MODULES as $module => $meta) {
                $allowed = match ($module) {
                    'dashboard','jobs','inquiries','tasks','documents','document_archive','notifications' => true,
                    'clients','reports' => in_array($role->slug, ['operations-manager','sales-manager','sales-executive','accounts'], true),
                    'quotation' => in_array($role->slug, ['operations-manager','sales-manager','sales-executive','sourcing'], true),
                    'artwork' => in_array($role->slug, ['operations-manager','designer'], true),
                    'sample' => in_array($role->slug, ['operations-manager','sourcing','sampling'], true),
                    'production' => in_array($role->slug, ['operations-manager','production','quality','sourcing'], true),
                    'shipment' => in_array($role->slug, ['operations-manager','shipment'], true),
                    'invoice' => in_array($role->slug, ['operations-manager','accounts'], true),
                    default => false,
                };
                $actions = $allowed ? ['view'] : [];
                if ($allowed && in_array($module, ['jobs','inquiries','tasks','documents','document_archive','quotation','artwork','sample','production','shipment','invoice'], true)) $actions[] = 'create';
                if ($allowed && in_array($module, ['inquiries','tasks','documents','document_archive','quotation','artwork','sample','production','shipment','invoice'], true)) $actions[] = 'edit_own';
                if ($allowed && in_array($module, ['documents','document_archive'], true)) $actions[] = 'link';
                if ($allowed && $module === 'reports') $actions[] = 'export';
                if ($isManager && $allowed) $actions = array_values(array_unique(array_merge($actions, ['edit_all','assign','export'])));
                RoleModuleAccess::updateOrCreate(
                    ['role_id' => $role->id, 'module_code' => $module],
                    ['record_scope' => $allowed ? ($isManager ? 'all_records' : 'assigned_jobs') : 'none', 'actions' => array_values(array_unique($actions))]
                );
            }
        }

        $people = [
            ['Li Wei','li.wei@flowtrack.local','operations-manager','MGT'],['Chen Mei','chen.mei@flowtrack.local','sales-manager','SAL'],
            ['Zhang Min','zhang.min@flowtrack.local','sales-executive','SAL'],['Liu Fang','liu.fang@flowtrack.local','designer','DES'],
            ['Wang Jun','wang.jun@flowtrack.local','sourcing','SRC'],['Zhao Lin','zhao.lin@flowtrack.local','production','PRO'],
            ['Sun Hao','sun.hao@flowtrack.local','quality','QUA'],['Xu Na','xu.na@flowtrack.local','shipment','SHP'],
            ['Yang Lei','yang.lei@flowtrack.local','accounts','ACC'],['He Ping','he.ping@flowtrack.local','sampling','SAM'],
            ['Tang Rui','tang.rui@flowtrack.local','designer','DES'],['Gao Yu','gao.yu@flowtrack.local','production','PRO'],
        ];
        $users = collect($people)->mapWithKeys(function ($p) use ($roles, $departments) {
            $u = User::updateOrCreate(['email'=>$p[1]], [
                'name'=>$p[0], 'password'=>Hash::make('password'), 'role_id'=>$roles[$p[2]]->id,
                'department_id'=>$departments[$p[3]]->id, 'is_active'=>true, 'locale'=>'en', 'email_verified_at'=>now(),
            ]);
            $u->roles()->syncWithoutDetaching([$u->role_id]);
            WorkspaceMembership::updateOrCreate(['workspace_id'=>1,'user_id'=>$u->id], ['role_id'=>$u->role_id,'department_id'=>$u->department_id,'job_title'=>$roles[$p[2]]->name,'status'=>'active','joined_at'=>$u->created_at ?: now()]);
            return [$p[0] => $u];
        });

        $packDefinitions = [
            'Quotation'=>['Review client requirement','Collect supplier / factory costing','Prepare quotation','Internal quotation review','Submit quotation'],
            'Order Confirmation'=>['Upload client confirmation / PO','Confirm final specifications','Lock price and quantity','Confirm delivery target','Assign Job coordinator'],
            'Artwork'=>['Collect artwork requirements','Prepare artwork','Internal artwork review','Submit artwork to client','Record approval / revision'],
            'Swatch'=>['Prepare swatch or sample','Internal swatch review','Arrange courier submission','Record client feedback','Close sample approval'],
            'Production'=>['Confirm materials ready','Start production','Update production milestone','Perform quality inspection','Complete packing'],
            'Shipment'=>['Review or update shipment details','Add tracking number & print courier label','Dispatch shipment'],
            'Invoice'=>['Prepare invoice','Internal invoice review','Submit invoice','Record payment follow-up','Close outstanding balance'],
        ];
        $packs = collect();
        foreach ($packDefinitions as $name => $items) {
            $pack = TaskPack::updateOrCreate(['slug'=>str($name)->slug()], ['name'=>$name,'description'=>$name.' phase task pack','is_active'=>true]);
            $packs[$name] = $pack;
            foreach ($items as $i => $title) {
                TaskPackTask::updateOrCreate(['task_pack_id'=>$pack->id,'sequence'=>$i+1], ['title'=>$title,'is_required'=>$i<3]);
            }
        }

        $workflow = Workflow::updateOrCreate(['slug'=>'standard-promotional-products'], [
            'name'=>'Standard Promotional Products','description'=>'Full request-to-payment process for new enquiries and custom orders.','is_active'=>true,
        ]);
        $phaseDefinitions = [
            ['Request Received','Request','Quotation',1,0,0,'Client Requirement','Job created','Requirement reviewed'],
            ['Quotation in Progress','Quotation','Quotation',1,1,0,'Costing Sheet','Requirement confirmed','Quotation prepared'],
            ['Quotation Submitted','Submitted','Quotation',1,1,0,'Quotation','Internal review complete','Quotation sent'],
            ['Negotiation','Negotiation','Quotation',1,1,0,'Quotation','Client feedback received','Commercial decision'],
            ['Confirmed Order','Confirmed','Order Confirmation',1,1,1,'Purchase Order','Client confirmation received','Order information locked'],
            ['Artwork','Artwork','Artwork',1,1,1,'Artwork Approval','Order confirmed','Artwork approved'],
            ['Swatch / Sample','Swatch','Swatch',1,1,1,'Sample Approval','Artwork approved','Sample approved or waived'],
            ['Production','Production','Production',1,0,1,'Quality Inspection','Pre-production approvals complete','Production and QC complete'],
            ['Shipment','Shipment','Shipment',1,0,1,'Shipping Document','Packing complete','Shipment released'],
            ['Invoice & Payment','Invoice','Invoice',1,0,1,'Invoice','Shipment confirmed','Outstanding balance closed'],
            ['Completed','Completed',null,0,0,1,null,'Payment complete','Job closure approved'],
        ];
        $phases = collect();
        foreach ($phaseDefinitions as $i => $p) {
            $phase = WorkflowPhase::updateOrCreate(['workflow_id'=>$workflow->id,'sequence'=>$i+1], [
                'name'=>$p[0],'short_name'=>$p[1],'task_pack_id'=>$p[2] ? $packs[$p[2]]->id : null,
                'allow_job_start'=>(bool)$p[3],'can_skip'=>(bool)$p[4],'requires_approval'=>(bool)$p[5],
                'required_document'=>$p[6],'entry_rule'=>$p[7],'exit_rule'=>$p[8],
            ]);
            $phases[$i] = $phase;
        }

        $masterGroups = [
            'product_categories'=>[['LAN','Lanyards','Promotional accessories'],['WRI','Wristbands','Silicone, fabric and event bands'],['CAP','Caps','Sports and promotional headwear'],['GAR','Garments','Jerseys, T-shirts and uniforms'],['BAG','Backpacks & Bags','Drawstring, travel and custom bags'],['LUG','Luggage','Hard and soft luggage'],['GFT','Gift Sets','Bundled promotional kits']],
            'products'=>[['PRD-001','Woven Lanyard','Lanyards · Custom woven'],['PRD-002','Silicone Wristband','Wristbands · Debossed/printed'],['PRD-003','Performance Cap','Caps · Embroidery'],['PRD-004','Dry-fit T-shirt','Garments · Sublimation'],['PRD-005','Drawstring Backpack','Backpacks & Bags'],['PRD-006','Hard-shell Cabin Luggage','Luggage · Custom mould']],
            'shipment_methods'=>[['AIR','Air Freight','Airport-to-airport / door delivery'],['SEA','Sea Freight','FCL and LCL'],['EXP','Express Courier','DHL, FedEx, UPS']],
            'currencies'=>[['USD','US Dollar','Default commercial currency'],['CNY','Chinese Yuan','Local sourcing and factory cost'],['GBP','British Pound','UK clients'],['EUR','Euro','European clients']],
            'document_categories'=>[['REQ','Client Requirement','References and specifications'],['QUO','Quotation','Commercial quotation versions'],['ART','Artwork','Working artwork files'],['APR','Artwork Approval','Final approved artwork'],['SAM','Sample Approval','Swatch or sample confirmation'],['QCI','Quality Inspection','QC evidence and reports'],['SHP','Shipping Document','Packing list, AWB and B/L'],['INV','Invoice','Invoice and payment documents']],
            'priorities'=>[['LOW','Low','Normal monitoring'],['MED','Medium','Standard business priority'],['HIG','High','Close monitoring required'],['CRI','Critical','Immediate management attention']],
            'task_statuses'=>[['READY','Ready','Can be started'],['IP','In Progress','Work underway'],['WC','Waiting for Client','External client dependency'],['WS','Waiting for Supplier','External supplier dependency'],['REV','Revision Required','Returned for correction'],['BLK','Blocked','Cannot continue'],['CMP','Completed','Work finished']],
            'inquiry_task_statuses'=>[['IST-005','Not Started','Task has not started yet'],['IST-006','Ready','Task is ready to be worked'],['IST-007','In Progress','Task is actively being worked'],['IST-008','In Review','Task is being reviewed'],['IST-009','Waiting','Task is waiting and requires attention'],['IST-010','Completed','Task is completed'],['IST-011','Cancelled','Task is cancelled'],['IST-012','Blocked','Task is blocked and requires attention']],
        ];
        foreach ($masterGroups as $group => $rows) {
            foreach ($rows as $r) MasterValue::updateOrCreate(['group_key'=>$group,'code'=>$r[0]], ['name'=>$r[1],'description'=>$r[2],'is_active'=>true]);
        }

        $clientRows = [
            ['NorthStar Promotions','NS','United States','Emily Carter','emily@northstarpromo.com','Chen Mei',18400],
            ['Brightline Events','BE','United Kingdom','Daniel Brooks','daniel@brightline.co.uk','Chen Mei',7200],
            ['ActiveWear Sports','AS','Australia','Oliver Smith','oliver@activewear.au','Li Wei',0],
            ['Urban Travel Gear','UT','Germany','Anna Keller','anna@urbangear.de','Wang Jun',29600],
            ['Summit Merchandise','SM','Canada','Liam Martin','liam@summitmerch.ca','Chen Mei',9000],
            ['Global Brand Works','GB','Singapore','Grace Lim','grace@gbw.sg','Li Wei',0],
            ['Viva Marketing','VM','Spain','Lucia Ramos','lucia@viva.es','Zhao Lin',4200],
            ['Peak Retail Group','PR','France','Hugo Bernard','hugo@peakretail.fr','Wang Jun',0],
            ['Momentum Schools','MS','New Zealand','Sophie Wilson','sophie@momentum.nz','Zhao Lin',3500],
            ['Apex Hospitality','AH','United Arab Emirates','Omar Hassan','omar@apexhospitality.ae','Chen Mei',11200],
        ];
        $clients = collect($clientRows)->mapWithKeys(function ($c) use ($users) {
            $m=Client::updateOrCreate(['code'=>$c[1]], ['name'=>$c[0],'country'=>$c[2],'contact_name'=>$c[3],'email'=>$c[4],'account_manager_id'=>$users[$c[5]]->id,'outstanding_balance'=>$c[6],'is_active'=>true]);
            return [$c[0]=>$m];
        });

        $jobRows = [
            ['JOB-2026-00125','ORD-2026-00089','10,000 woven lanyards for annual conference','NorthStar Promotions','Woven Lanyard','Lanyards',10000,12400,7,'In Progress','At Risk',68,'Chen Mei','Li Wei','2026-08-08','High','Resolve dye colour variation',1],
            ['JOB-2026-00124','ORD-2026-00088','Custom navy baseball caps','Brightline Events','Embroidered Cap','Caps',2500,16750,5,'Waiting for Client','Needs Attention',43,'Zhang Min','Li Wei','2026-08-21','High','Client artwork approval',1],
            ['JOB-2026-00123','ORD-2026-00087','Junior football club jerseys','ActiveWear Sports','Sublimation Jersey','Garments',1200,22800,6,'In Progress','On Track',55,'Chen Mei','Zhao Lin','2026-09-03','Medium','Approve second fabric swatch',0],
            ['JOB-2026-00122','ORD-2026-00086','Premium carry-on luggage collection','Urban Travel Gear','Hard-shell Luggage','Luggage',800,62400,7,'Blocked','Blocked',61,'Zhang Min','Wang Jun','2026-08-29','Critical','Confirm replacement wheel supplier',1],
            ['JOB-2026-00121','ORD-2026-00085','Conference drawstring backpacks','Summit Merchandise','Drawstring Backpack','Backpacks',5000,19500,8,'Ready','On Track',82,'Chen Mei','Xu Na','2026-08-04','High','Complete shipment booking',0],
            ['JOB-2026-00120','ORD-2026-00084','Silicone wristbands — 6 colour mix','Global Brand Works','Silicone Wristband','Wristbands',20000,9800,9,'Waiting for Payment','Needs Attention',91,'Zhang Min','Yang Lei','2026-07-18','Medium','Follow up overdue balance',1],
            ['JOB-2026-00119',null,'Hotel staff polo shirts','Apex Hospitality','Polo Shirt','Garments',1800,0,3,'Negotiation','On Track',24,'Chen Mei','Li Wei','2026-09-18','Medium','Revise quotation pricing',0],
            ['JOB-2026-00118','ORD-2026-00083','Retail launch canvas tote bags','Peak Retail Group','Canvas Tote Bag','Bags',6000,27600,10,'Completed','Completed',100,'Zhang Min','Li Wei','2026-07-12','Medium','Closed',0],
            ['JOB-2026-00117','ORD-2026-00082','School house-colour wristbands','Momentum Schools','Debossed Wristband','Wristbands',7500,6900,8,'In Transit','On Track',86,'Chen Mei','Xu Na','2026-08-02','Medium','Monitor DHL delivery',0],
            ['JOB-2026-00116',null,'Festival staff badge lanyards','Viva Marketing','Printed Lanyard','Lanyards',3500,0,2,'Submitted','On Track',18,'Zhang Min','Chen Mei','2026-09-02','Low','Await quotation response',0],
            ['JOB-2026-00115','ORD-2026-00081','Premium laptop backpacks','Urban Travel Gear','Laptop Backpack','Backpacks',1500,47250,5,'Revision Required','At Risk',39,'Chen Mei','Liu Fang','2026-08-25','High','Artwork revision v3',1],
            ['JOB-2026-00114','ORD-2026-00080','Running event dry-fit T-shirts','ActiveWear Sports','Dry-fit T-shirt','Garments',4000,28800,7,'In Progress','On Track',72,'Zhang Min','Zhao Lin','2026-08-16','High','Complete 75% production update',0],
            ['JOB-2026-00113',null,'Employee welcome kit quotation','NorthStar Promotions','Welcome Kit','Gift Sets',900,0,1,'In Progress','On Track',10,'Chen Mei','Wang Jun','2026-10-01','Low','Collect supplier costs',0],
            ['JOB-2026-00112','ORD-2026-00079','Executive travel duffel bags','Global Brand Works','Travel Duffel','Bags',600,20400,9,'Partially Paid','On Track',94,'Zhang Min','Yang Lei','2026-07-28','Medium','Collect final 40% payment',0],
            ['JOB-2026-00111','ORD-2026-00078','Branded marathon caps','Summit Merchandise','Performance Cap','Caps',3000,17100,6,'Waiting for Supplier','Needs Attention',49,'Chen Mei','He Ping','2026-08-17','High','Supplier to resubmit colour swatch',1],
            ['JOB-2026-00110','ORD-2026-00077','Resort housekeeping uniforms','Apex Hospitality','Uniform Set','Garments',1100,31900,7,'In Progress','Delayed',63,'Zhang Min','Zhao Lin','2026-08-10','Critical','Recover 3-day production delay',1],
            ['JOB-2026-00109',null,'Promotional luggage tags','Peak Retail Group','PVC Luggage Tag','Travel Accessories',9000,0,0,'New','On Track',4,'Chen Mei','Li Wei','2026-09-30','Low','Review client specifications',0],
            ['JOB-2026-00108','ORD-2026-00076','Corporate anniversary jackets','Brightline Events','Softshell Jacket','Garments',700,24500,8,'Ready to Ship','On Track',84,'Zhang Min','Xu Na','2026-08-01','High','Shipment release approval',0],
        ];
        $createdJobs = collect();
        foreach ($jobRows as $r) {
            $job=FlowJob::updateOrCreate(['job_number'=>$r[0]], [
                'order_number'=>$r[1], 'title'=>$r[2], 'client_id'=>$clients[$r[3]]->id, 'product'=>$r[4], 'category'=>$r[5],
                'quantity'=>$r[6], 'commercial_value'=>$r[7], 'currency'=>'USD', 'workflow_id'=>$workflow->id,
                'workflow_phase_id'=>$phases[$r[8]]->id, 'status'=>$r[9], 'health'=>$r[10], 'progress'=>$r[11],
                'owner_id'=>$users[$r[12]]->id, 'coordinator_id'=>$users[$r[13]]->id, 'delivery_date'=>$r[14],
                'priority'=>$r[15], 'next_action'=>$r[16], 'needs_attention'=>(bool)$r[17], 'completed_at'=>$r[9]==='Completed'?now()->subDays(20):null,
            ]);
            $createdJobs[]=$job;
        }

        $assignees = $users->values();
        foreach ($createdJobs as $ji => $job) {
            $pack = $job->phase->taskPack;
            if (!$pack) continue;
            foreach ($pack->templates()->take(4)->get() as $i => $template) {
                $assignee=$assignees[($ji+$i+2)%$assignees->count()];
                $status = $i === 0 ? 'Completed' : ($i === 1 ? 'In Progress' : 'Ready');
                if ($i === 2 && in_array($job->status, ['Blocked','Waiting for Supplier','Waiting for Client','Revision Required'], true)) {
                    $status = $job->status;
                }
                Task::updateOrCreate(['task_number'=>'TSK-'.(301+$ji*4+$i)], [
                    'flow_job_id'=>$job->id,'workflow_phase_id'=>$job->workflow_phase_id,'task_pack_task_id'=>$template->id,
                    'assignee_id'=>$assignee->id,'title'=>$template->title,'status'=>$status,'priority'=>$job->priority,
                    'progress'=>$status==='Completed'?100:($i===1?75:($i===2?45:10)),
                    'due_date'=>optional($job->delivery_date)->copy()->subDays(max(1,4-$i)),
                    'needs_attention'=>$job->needs_attention&&$i===2,'attention_reason'=>$job->needs_attention&&$i===2?$job->next_action:null,
                    'completed_at'=>$status==='Completed'?now()->subDay():null,
                ]);
            }
        }


        // Board support data follows the table structure supplied in flowtrack(1).sql:
        // job items, team membership, phase history, checklist items and comments.
        foreach ($createdJobs as $ji => $job) {
            FlowJobItem::updateOrCreate(
                ['flow_job_id'=>$job->id,'sort_order'=>0],
                ['product_name'=>$job->product,'category_name'=>$job->category,'quantity'=>$job->quantity]
            );
            if ($ji % 4 === 2) {
                FlowJobItem::updateOrCreate(
                    ['flow_job_id'=>$job->id,'sort_order'=>1],
                    ['product_name'=>'Companion '.$job->product,'category_name'=>$job->category,'quantity'=>max(1,(int) round($job->quantity * .3))]
                );
            }

            $jobTasks = Task::where('flow_job_id',$job->id)->with('assignee')->get();
            $memberIds = collect([$job->owner_id,$job->coordinator_id])->merge($jobTasks->pluck('assignee_id'))->filter()->unique();
            foreach ($memberIds as $memberId) {
                FlowJobMember::updateOrCreate(
                    ['flow_job_id'=>$job->id,'user_id'=>$memberId],
                    ['access_level'=>$memberId===$job->owner_id?'lead':'member','can_manage_tasks'=>$memberId===$job->owner_id,'can_upload_documents'=>true,'can_view_financials'=>$memberId===$job->owner_id]
                );
            }

            FlowJobPhaseHistory::updateOrCreate(
                ['flow_job_id'=>$job->id,'workflow_phase_id'=>$job->workflow_phase_id,'status'=>'active'],
                ['changed_by'=>$job->coordinator_id,'phase_owner_id'=>$job->coordinator_id,'target_date'=>$job->delivery_date,'health_override'=>$job->health,'entered_at'=>now()->subDays(($ji%5)+1)]
            );

            foreach ($jobTasks as $ti => $task) {
                // Checklist rows are user-managed. Task Pack generation must not
                // invent default checklist items.
                FlowTaskComment::firstOrCreate(
                    ['flow_task_id'=>$task->id,'body'=>'Task created from the configured phase Task Pack.'],
                    ['user_id'=>$job->coordinator_id]
                );
                if ($task->needs_attention || str_starts_with($task->status,'Waiting for ')) {
                    FlowTaskComment::firstOrCreate(
                        ['flow_task_id'=>$task->id,'body'=>$task->attention_reason ?: 'Waiting dependency requires an update.'],
                        ['user_id'=>$task->assignee_id ?: $job->coordinator_id]
                    );
                }
            }
        }


        $docTypes=['Quotation','Artwork','Artwork Approval','Sample Approval','Quality Inspection','Shipping Document','Invoice'];
        foreach ($createdJobs->take(14) as $i=>$job) {
            $type=$docTypes[$i % count($docTypes)];
            $documentTask = Task::where('flow_job_id',$job->id)->whereNull('completed_at')->orderBy('id')->first() ?: Task::where('flow_job_id',$job->id)->orderBy('id')->first();
            Document::firstOrCreate(['document_number'=>'DOC-'.(700+$i)], [
                'flow_job_id'=>$job->id,'client_id'=>$job->client_id,'task_id'=>$documentTask?->id,'uploaded_by'=>$job->coordinator_id,'category'=>$type,
                'name'=>str_replace(' ','_',$type).'_'.$job->job_number.'_v1.pdf','path'=>'demo/'.str_replace(' ','_',strtolower($type)).'_'.$job->id.'.pdf',
                'mime_type'=>'application/pdf','size'=>128000+($i*5000),'version'=>1,'is_final'=>$i%5===0,
            ]);
            Activity::firstOrCreate(['subject_type'=>FlowJob::class,'subject_id'=>$job->id,'event'=>'job.seeded'],[
                'user_id'=>$job->coordinator_id,'description'=>'Job record created and current workflow phase activated.','meta'=>['source'=>'demo-seed'],
            ]);
        }

        $recipient = User::where('is_super_admin', true)->first() ?: $users['Li Wei'];
        $notificationRows = [
            ['approval','Artwork approval overdue','JOB-2026-00124 has been waiting for client approval for 3 days.','JOB-2026-00124'],
            ['risk','Production blocker reported','Replacement wheel supplier is not confirmed for JOB-2026-00122.','JOB-2026-00122'],
            ['mention','You were mentioned','Zhao Lin mentioned you in the production recovery discussion.','JOB-2026-00110'],
            ['payment','Invoice is overdue','USD 3,500 remains outstanding for JOB-2026-00120.','JOB-2026-00120'],
            ['shipment','Shipment ready for release','JOB-2026-00108 is ready for shipment approval.','JOB-2026-00108'],
            ['file','Swatch revision uploaded','Supplier uploaded the second cap-colour swatch.','JOB-2026-00111'],
        ];
        foreach ($notificationRows as $n) {
            $job=FlowJob::where('job_number',$n[3])->first();
            FlowNotification::firstOrCreate(['user_id'=>$recipient->id,'title'=>$n[1]], ['flow_job_id'=>$job?->id,'type'=>$n[0],'message'=>$n[2]]);
        }

        foreach ([
            ['Task due reminder','2 days before due','Assignee'],['Overdue escalation','1 day overdue','Assignee + Manager'],
            ['Approval reminder','24 hours pending','Approver'],['Shipment deadline','3 days before ship date','Shipment + Manager'],
            ['Invoice due reminder','5 days before due','Accounts + Sales'],['Blocked task escalation','Immediately','Manager + Job owner'],
        ] as $rule) NotificationRule::firstOrCreate(['name'=>$rule[0]],['trigger'=>$rule[1],'recipients'=>$rule[2],'is_active'=>true]);
    }
}
