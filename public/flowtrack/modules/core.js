/* FlowTrack runtime module: core. Build: f400609959067571. */
const phases=['Request Received','Quotation in Progress','Quotation Submitted','Negotiation','Confirmed Order','Artwork','Swatch / Sample','Production','Shipment','Invoice & Payment','Completed'];
const phaseShort=['Request','Quotation','Submitted','Negotiation','Confirmed','Artwork','Swatch','Production','Shipment','Invoice','Completed'];
const clients=[
{id:1,name:'NorthStar Promotions',code:'NS',country:'United States',contact:'Emily Carter',email:'emily@northstarpromo.com',manager:'Chen Mei',jobs:4,outstanding:18400},
{id:2,name:'Brightline Events',code:'BE',country:'United Kingdom',contact:'Daniel Brooks',email:'daniel@brightline.co.uk',manager:'Chen Mei',jobs:3,outstanding:7200},
{id:3,name:'ActiveWear Sports',code:'AS',country:'Australia',contact:'Oliver Smith',email:'oliver@activewear.au',manager:'Li Wei',jobs:3,outstanding:0},
{id:4,name:'Urban Travel Gear',code:'UT',country:'Germany',contact:'Anna Keller',email:'anna@urbangear.de',manager:'Wang Jun',jobs:2,outstanding:29600},
{id:5,name:'Summit Merchandise',code:'SM',country:'Canada',contact:'Liam Martin',email:'liam@summitmerch.ca',manager:'Chen Mei',jobs:2,outstanding:9000},
{id:6,name:'Global Brand Works',code:'GB',country:'Singapore',contact:'Grace Lim',email:'grace@gbw.sg',manager:'Li Wei',jobs:2,outstanding:0},
{id:7,name:'Viva Marketing',code:'VM',country:'Spain',contact:'Lucia Ramos',email:'lucia@viva.es',manager:'Zhao Lin',jobs:1,outstanding:4200},
{id:8,name:'Peak Retail Group',code:'PR',country:'France',contact:'Hugo Bernard',email:'hugo@peakretail.fr',manager:'Wang Jun',jobs:2,outstanding:0},
{id:9,name:'Momentum Schools',code:'MS',country:'New Zealand',contact:'Sophie Wilson',email:'sophie@momentum.nz',manager:'Zhao Lin',jobs:1,outstanding:3500},
{id:10,name:'Apex Hospitality',code:'AH',country:'United Arab Emirates',contact:'Omar Hassan',email:'omar@apexhospitality.ae',manager:'Chen Mei',jobs:2,outstanding:11200}
];
const users=[
{id:1,name:'Li Wei',initials:'LW',role:'Operations Manager',dept:'Management'},
{id:2,name:'Chen Mei',initials:'CM',role:'Sales Manager',dept:'Sales'},
{id:3,name:'Zhang Min',initials:'ZM',role:'Sales Executive',dept:'Sales'},
{id:4,name:'Liu Fang',initials:'LF',role:'Senior Designer',dept:'Design'},
{id:5,name:'Wang Jun',initials:'WJ',role:'Sourcing Coordinator',dept:'Sourcing'},
{id:6,name:'Zhao Lin',initials:'ZL',role:'Production Coordinator',dept:'Production'},
{id:7,name:'Sun Hao',initials:'SH',role:'Quality Inspector',dept:'Quality'},
{id:8,name:'Xu Na',initials:'XN',role:'Shipping Executive',dept:'Shipment'},
{id:9,name:'Yang Lei',initials:'YL',role:'Accounts Officer',dept:'Accounts'},
{id:10,name:'He Ping',initials:'HP',role:'Sample Coordinator',dept:'Sampling'},
{id:11,name:'Tang Rui',initials:'TR',role:'Designer',dept:'Design'},
{id:12,name:'Gao Yu',initials:'GY',role:'Factory Supervisor',dept:'Production'}
];
let jobs=[
{id:'JOB-2026-00125',order:'ORD-2026-00089',title:'10,000 woven lanyards for annual conference',client:1,product:'Woven Lanyard',category:'Lanyards',qty:10000,price:12400,currency:'USD',phase:7,status:'In Progress',health:'At Risk',progress:68,owner:'Chen Mei',coordinator:'Li Wei',delivery:'2026-08-08',priority:'High',next:'Resolve dye colour variation',milestone:'Quality Inspection Passed',attention:true},
{id:'JOB-2026-00124',order:'ORD-2026-00088',title:'Custom navy baseball caps',client:2,product:'Embroidered Cap',category:'Caps',qty:2500,price:16750,currency:'USD',phase:5,status:'Waiting for Client',health:'Needs Attention',progress:43,owner:'Zhang Min',coordinator:'Li Wei',delivery:'2026-08-21',priority:'High',next:'Client artwork approval',milestone:'Artwork Approved',attention:true},
{id:'JOB-2026-00123',order:'ORD-2026-00087',title:'Junior football club jerseys',client:3,product:'Sublimation Jersey',category:'Garments',qty:1200,price:22800,currency:'USD',phase:6,status:'In Progress',health:'On Track',progress:55,owner:'Chen Mei',coordinator:'Zhao Lin',delivery:'2026-09-03',priority:'Medium',next:'Approve second fabric swatch',milestone:'Swatch Approved',attention:false},
{id:'JOB-2026-00122',order:'ORD-2026-00086',title:'Premium carry-on luggage collection',client:4,product:'Hard-shell Luggage',category:'Luggage',qty:800,price:62400,currency:'USD',phase:7,status:'Blocked',health:'Blocked',progress:61,owner:'Zhang Min',coordinator:'Wang Jun',delivery:'2026-08-29',priority:'Critical',next:'Confirm replacement wheel supplier',milestone:'Production Completed',attention:true},
{id:'JOB-2026-00121',order:'ORD-2026-00085',title:'Conference drawstring backpacks',client:5,product:'Drawstring Backpack',category:'Backpacks',qty:5000,price:19500,currency:'USD',phase:8,status:'Ready',health:'On Track',progress:82,owner:'Chen Mei',coordinator:'Xu Na',delivery:'2026-08-04',priority:'High',next:'Complete shipment booking',milestone:'Shipment Completed',attention:false},
{id:'JOB-2026-00120',order:'ORD-2026-00084',title:'Silicone wristbands — 6 colour mix',client:6,product:'Silicone Wristband',category:'Wristbands',qty:20000,price:9800,currency:'USD',phase:9,status:'Waiting for Payment',health:'Needs Attention',progress:91,owner:'Zhang Min',coordinator:'Yang Lei',delivery:'2026-07-18',priority:'Medium',next:'Follow up overdue balance',milestone:'Payment Collected',attention:true},
{id:'JOB-2026-00119',order:'',title:'Hotel staff polo shirts',client:10,product:'Polo Shirt',category:'Garments',qty:1800,price:0,currency:'USD',phase:3,status:'Negotiation',health:'On Track',progress:24,owner:'Chen Mei',coordinator:'Li Wei',delivery:'2026-09-18',priority:'Medium',next:'Revise quotation pricing',milestone:'Client Confirmation Received',attention:false},
{id:'JOB-2026-00118',order:'ORD-2026-00083',title:'Retail launch canvas tote bags',client:8,product:'Canvas Tote Bag',category:'Bags',qty:6000,price:27600,currency:'USD',phase:10,status:'Completed',health:'Completed',progress:100,owner:'Zhang Min',coordinator:'Li Wei',delivery:'2026-07-12',priority:'Medium',next:'Closed',milestone:'Payment Collected',attention:false},
{id:'JOB-2026-00117',order:'ORD-2026-00082',title:'School house-colour wristbands',client:9,product:'Debossed Wristband',category:'Wristbands',qty:7500,price:6900,currency:'USD',phase:8,status:'In Transit',health:'On Track',progress:86,owner:'Chen Mei',coordinator:'Xu Na',delivery:'2026-08-02',priority:'Medium',next:'Monitor DHL delivery',milestone:'Delivery Confirmed',attention:false},
{id:'JOB-2026-00116',order:'',title:'Festival staff badge lanyards',client:7,product:'Printed Lanyard',category:'Lanyards',qty:3500,price:0,currency:'USD',phase:2,status:'Submitted',health:'On Track',progress:18,owner:'Zhang Min',coordinator:'Chen Mei',delivery:'2026-09-02',priority:'Low',next:'Await quotation response',milestone:'Client Confirmation Received',attention:false},
{id:'JOB-2026-00115',order:'ORD-2026-00081',title:'Premium laptop backpacks',client:4,product:'Laptop Backpack',category:'Backpacks',qty:1500,price:47250,currency:'USD',phase:5,status:'Revision Required',health:'At Risk',progress:39,owner:'Chen Mei',coordinator:'Liu Fang',delivery:'2026-08-25',priority:'High',next:'Artwork revision v3',milestone:'Artwork Approved',attention:true},
{id:'JOB-2026-00114',order:'ORD-2026-00080',title:'Running event dry-fit T-shirts',client:3,product:'Dry-fit T-shirt',category:'Garments',qty:4000,price:28800,currency:'USD',phase:7,status:'In Progress',health:'On Track',progress:72,owner:'Zhang Min',coordinator:'Zhao Lin',delivery:'2026-08-16',priority:'High',next:'Complete 75% production update',milestone:'Production Completed',attention:false},
{id:'JOB-2026-00113',order:'',title:'Employee welcome kit quotation',client:1,product:'Welcome Kit',category:'Gift Sets',qty:900,price:0,currency:'USD',phase:1,status:'In Progress',health:'On Track',progress:10,owner:'Chen Mei',coordinator:'Wang Jun',delivery:'2026-10-01',priority:'Low',next:'Collect supplier costs',milestone:'Quotation Submitted',attention:false},
{id:'JOB-2026-00112',order:'ORD-2026-00079',title:'Executive travel duffel bags',client:6,product:'Travel Duffel',category:'Bags',qty:600,price:20400,currency:'USD',phase:9,status:'Partially Paid',health:'On Track',progress:94,owner:'Zhang Min',coordinator:'Yang Lei',delivery:'2026-07-28',priority:'Medium',next:'Collect final 40% payment',milestone:'Payment Collected',attention:false},
{id:'JOB-2026-00111',order:'ORD-2026-00078',title:'Branded marathon caps',client:5,product:'Performance Cap',category:'Caps',qty:3000,price:17100,currency:'USD',phase:6,status:'Waiting for Supplier',health:'Needs Attention',progress:49,owner:'Chen Mei',coordinator:'He Ping',delivery:'2026-08-17',priority:'High',next:'Supplier to resubmit colour swatch',milestone:'Swatch Approved',attention:true},
{id:'JOB-2026-00110',order:'ORD-2026-00077',title:'Resort housekeeping uniforms',client:10,product:'Uniform Set',category:'Garments',qty:1100,price:31900,currency:'USD',phase:7,status:'In Progress',health:'Delayed',progress:63,owner:'Zhang Min',coordinator:'Zhao Lin',delivery:'2026-08-10',priority:'Critical',next:'Recover 3-day production delay',milestone:'Production Completed',attention:true},
{id:'JOB-2026-00109',order:'',title:'Promotional luggage tags',client:8,product:'PVC Luggage Tag',category:'Travel Accessories',qty:9000,price:0,currency:'USD',phase:0,status:'New',health:'On Track',progress:4,owner:'Chen Mei',coordinator:'Li Wei',delivery:'2026-09-30',priority:'Low',next:'Review client specifications',milestone:'Quotation Submitted',attention:false},
{id:'JOB-2026-00108',order:'ORD-2026-00076',title:'Corporate anniversary jackets',client:2,product:'Softshell Jacket',category:'Garments',qty:700,price:24500,currency:'USD',phase:8,status:'Ready to Ship',health:'On Track',progress:84,owner:'Zhang Min',coordinator:'Xu Na',delivery:'2026-08-01',priority:'High',next:'Shipment release approval',milestone:'Shipment Completed',attention:false}
];
jobs.forEach(j=>{j.workflow=j.workflow||'standard';j.createdFromPhase=j.createdFromPhase??0;j.skippedPhases=j.skippedPhases||[];j.startHandling=j.startHandling||'Normal start';j.startReason=j.startReason||'';j.activity=j.activity||[]});
const taskTemplates={
'Quotation':['Review client requirement','Collect supplier / factory costing','Prepare quotation','Internal quotation review','Submit quotation'],
'Order Confirmation':['Upload client confirmation / PO','Confirm final specifications','Lock price and quantity','Confirm delivery target','Assign Job coordinator'],
'Artwork':['Collect artwork requirements','Prepare artwork','Internal artwork review','Submit artwork to client','Record approval / revision'],
'Swatch':['Prepare swatch or sample','Internal swatch review','Arrange courier submission','Record client feedback','Close sample approval'],
'Production':['Confirm materials ready','Start production','Update production milestone','Perform quality inspection','Complete packing'],
'Shipment':['Confirm shipping information','Prepare packing list','Book shipment','Upload shipment documents','Record tracking number'],
'Invoice':['Prepare invoice','Internal invoice review','Submit invoice','Record payment follow-up','Close outstanding balance']
};
const workflowTemplates=[
 {id:'standard',name:'Standard Promotional Products',description:'Full request-to-payment process for new enquiries and custom orders.',active:true,phases:[
  {name:'Request Received',short:'Request',taskPack:'Quotation',allowStart:true,skippable:false,entry:'Job created',exit:'Requirement reviewed',document:'Client Requirement'},
  {name:'Quotation in Progress',short:'Quotation',taskPack:'Quotation',allowStart:true,skippable:true,entry:'Requirement confirmed',exit:'Quotation prepared',document:'Costing Sheet'},
  {name:'Quotation Submitted',short:'Submitted',taskPack:'Quotation',allowStart:true,skippable:true,entry:'Internal review complete',exit:'Quotation sent',document:'Quotation'},
  {name:'Negotiation',short:'Negotiation',taskPack:'Quotation',allowStart:true,skippable:true,entry:'Client feedback received',exit:'Commercial decision',document:'Quotation'},
  {name:'Confirmed Order',short:'Confirmed',taskPack:'Order Confirmation',allowStart:true,skippable:true,approval:true,entry:'Client confirmation received',exit:'Order information locked',document:'Purchase Order'},
  {name:'Artwork',short:'Artwork',taskPack:'Artwork',allowStart:true,skippable:true,approval:true,entry:'Order confirmed',exit:'Artwork approved',document:'Artwork Approval'},
  {name:'Swatch / Sample',short:'Swatch',taskPack:'Swatch',allowStart:true,skippable:true,approval:true,entry:'Artwork approved',exit:'Sample approved or waived',document:'Sample Approval'},
  {name:'Production',short:'Production',taskPack:'Production',allowStart:true,skippable:false,approval:true,entry:'Pre-production approvals complete',exit:'Production and QC complete',document:'Quality Inspection'},
  {name:'Shipment',short:'Shipment',taskPack:'Shipment',allowStart:true,skippable:false,approval:true,entry:'Packing complete',exit:'Shipment released',document:'Shipping Document'},
  {name:'Invoice & Payment',short:'Invoice',taskPack:'Invoice',allowStart:true,skippable:false,approval:true,entry:'Shipment confirmed',exit:'Outstanding balance closed',document:'Invoice'},
  {name:'Completed',short:'Completed',taskPack:'',allowStart:false,skippable:false,approval:true,entry:'Payment complete',exit:'Job closure approved',document:''}
 ]},
 {id:'repeat',name:'Repeat Order',description:'Starts from confirmation or production using approved specifications and artwork from an earlier Job.',active:true,phases:[]},
 {id:'sample',name:'Sample-Only Request',description:'Quotation, artwork and sample approval without bulk production and shipment.',active:true,phases:[]}
];
workflowTemplates[1].phases=workflowTemplates[0].phases.map((p,i)=>({...p,allowStart:i>=4&&i<10,skippable:i<7}));
workflowTemplates[2].phases=workflowTemplates[0].phases.filter((p,i)=>[0,1,2,3,5,6,10].includes(i)).map((p,i)=>({...p,allowStart:i<6}));
let selectedWorkflowId='standard';
const masterData={};
const masterLabels={};
let selectedMasterCategory='';

const statusForPhase=(p,i)=>{if(i===0&&p>7)return 'Completed'; if(i===1&&p>5)return 'Completed'; if(i===2)return p===7?'Blocked':(p===5?'Revision Required':'In Progress'); if(i===3)return p<5?'Not Started':'Waiting for Client'; return p>7?'Completed':'Ready'};
let tasks=[];let taskId=301;
jobs.forEach((j,ji)=>{const pack=j.phase<=3?'Quotation':j.phase===5?'Artwork':j.phase===6?'Swatch':j.phase===7?'Production':j.phase===8?'Shipment':j.phase>=9?'Invoice':'Artwork';taskTemplates[pack].slice(0,4).forEach((title,i)=>{const assignee=users[(ji+i+2)%users.length];tasks.push({id:'TSK-'+taskId++,job:j.id,title,status:statusForPhase(j.phase,i),assignee:assignee.name,dept:assignee.dept,due:new Date(Date.parse(j.delivery)-((4-i)*86400000)).toISOString().slice(0,10),priority:i===2&&j.priority==='Critical'?'Critical':j.priority,progress:i===0?100:i===1?75:i===2?45:10,phase:pack,attention:j.attention&&i===2,checklist:[{text:'Confirm required information',done:i<2},{text:'Upload supporting file',done:i===0},{text:'Add completion comment',done:false}],comments:[{user:'Li Wei',text:i===2&&j.attention?'Please update the blocker and recovery date.':'Task created from '+pack+' Task Pack.',time:'Today, 09:20'}]})})});
const docs=[];const docTypes=['Quotation','Artwork','Artwork Approval','Swatch','Production Document','Quality Inspection','Packing List','Shipping Document','Invoice','Payment Proof'];for(let i=0;i<34;i++){const j=jobs[i%jobs.length];docs.push({id:'DOC-'+(700+i),name:(docTypes[i%docTypes.length]).replaceAll(' ','_')+'_'+j.id.replace('JOB-','')+'_v'+(i%3+1)+'.pdf',type:docTypes[i%docTypes.length],job:j.id,client:j.client,version:'v'+(i%3+1),uploadedBy:users[i%users.length].name,date:'2026-07-'+String(4+(i%24)).padStart(2,'0'),final:i%5===0})}
let notifications=[
{id:1,title:'Artwork approval overdue',text:'JOB-2026-00124 has been waiting for client approval for 3 days.',time:'12 minutes ago',read:false,type:'approval',job:'JOB-2026-00124'},
{id:2,title:'Production blocker reported',text:'Replacement wheel supplier is not confirmed for JOB-2026-00122.',time:'38 minutes ago',read:false,type:'risk',job:'JOB-2026-00122'},
{id:3,title:'You were mentioned',text:'Zhao Lin mentioned you in the production recovery discussion.',time:'1 hour ago',read:false,type:'mention',job:'JOB-2026-00110'},
{id:4,title:'Invoice is overdue',text:'USD 3,500 remains outstanding for JOB-2026-00120.',time:'3 hours ago',read:false,type:'payment',job:'JOB-2026-00120'},
{id:5,title:'Shipment ready for release',text:'JOB-2026-00108 is ready for shipment approval.',time:'5 hours ago',read:false,type:'shipment',job:'JOB-2026-00108'},
{id:6,title:'Swatch revision uploaded',text:'Supplier uploaded the second cap-colour swatch.',time:'Yesterday',read:false,type:'file',job:'JOB-2026-00111'},
{id:7,title:'Payment received',text:'USD 12,240 recorded against JOB-2026-00112.',time:'Yesterday',read:true,type:'payment',job:'JOB-2026-00112'}
];
const translations={en:{workspace:'Workspace',dashboard:'Dashboard',myWork:'My Work',jobs:'Jobs',clients:'Clients',board:'Board',documents:'Documents',reports:'Reports',administration:'Administration',notifications:'Notifications',setup:'Setup',workflowsetup:'Workflow Setup',masterdata:'Master Data',newJob:'New Job'},zh:{workspace:'工作区',dashboard:'管理仪表板',myWork:'我的工作',jobs:'工作订单',clients:'客户',board:'看板',documents:'文件档案',reports:'报告',administration:'管理',notifications:'通知',setup:'系统设置',newJob:'新建工作'}};
let lang='en',currentPage='dashboard',boardMode='jobs',jobFilter={search:'',phase:'',health:'',client:''};
const $=s=>document.querySelector(s),$$=s=>Array.from(document.querySelectorAll(s));
const safeHtml=value=>String(value??'')
 .replaceAll('&','&amp;')
 .replaceAll('<','&lt;')
 .replaceAll('>','&gt;')
 .replaceAll('\"','&quot;')
 .replaceAll("'",'&#039;');
const clientLabels=[];
const clientName=id=>(clients.find(c=>c.id===Number(id))||clientLabels.find(c=>c.id===Number(id))||{}).name||'—';
const userInitials=name=>{const label=String(name||'').trim();const matched=users.find(u=>u.name===label);if(matched?.initials)return matched.initials;const initials=label.split(/\s+/).filter(Boolean).map(part=>part.charAt(0)).join('').slice(0,2);return initials||'FT'};
const currency=(n,c='USD')=>new Intl.NumberFormat('en-US',{style:'currency',currency:c,maximumFractionDigits:0}).format(n||0);
const localDateKey=(value=new Date())=>{const d=value instanceof Date?value:new Date(value),y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');return `${y}-${m}-${day}`};
const today=localDateKey();
function dueClass(d){return d<today?'overdue':d===today?'today':''}
function healthBadge(h,reason=''){const map={'On Track':'b-green','Needs Attention':'b-red','At Risk':'b-red','Delayed':'b-red','Blocked':'b-red','Completed':'b-gray'};return `<span class="badge ${map[h]||'b-gray'} health"${reason?` title="${String(reason).replaceAll('&','&amp;').replaceAll('\"','&quot;')}"`:''}>${h}</span>`}
function statusBadge(s){let c=/Completed|Approved|Paid|Delivered/.test(s)?'b-green':/Blocked|Critical|Overdue|Revision/.test(s)?'b-red':/Waiting|Negotiation|Partially/.test(s)?'b-amber':/In Progress|Submitted|Ready|Transit/.test(s)?'b-blue':'b-gray';return `<span class="badge ${c}">${s}</span>`}

function getWorkflow(id='standard'){const found=workflowTemplates.find(w=>String(w?.id)===String(id));return found||workflowTemplates.find(w=>Array.isArray(w?.phases))||workflowTemplates[0]||{id:null,name:'Unavailable workflow',phases:[]}}
function workflowPhaseList(workflow){return Array.isArray(workflow?.phases)?workflow.phases:workflow?.phases&&typeof workflow.phases==='object'?Object.values(workflow.phases).filter(Boolean):[]}
function phasePackFor(workflowId,phaseIndex){const wf=getWorkflow(workflowId),phase=workflowPhaseList(wf)[Number(phaseIndex)];return phase?phase.taskPack:''}
function phaseNameFor(j,index=j?.phase){const wf=getWorkflow(j?.workflow||'standard'),phase=workflowPhaseList(wf)[Number(index)];return phase?.name||(Array.isArray(phases)?phases[Number(index)]:null)||'—'}
function phaseShortFor(j,index=j?.phase){const wf=getWorkflow(j?.workflow||'standard'),phase=workflowPhaseList(wf)[Number(index)];return phase?.short||phase?.shortName||(Array.isArray(phaseShort)?phaseShort[Number(index)]:null)||'—'}
function milestoneForPhase(workflowId,phaseIndex){const list=workflowPhaseList(getWorkflow(workflowId));const next=list[Math.min(Number(phaseIndex)+1,Math.max(0,list.length-1))];return next?.exit||'Job Completed'}
function nextActionForPhase(workflowId,phaseIndex){const phase=workflowPhaseList(getWorkflow(workflowId))[Number(phaseIndex)];if(!phase)return 'Review Job';const map={'Quotation':'Review client specifications','Order Confirmation':'Confirm order details and upload PO','Artwork':'Prepare or review artwork','Swatch':'Prepare and submit sample','Production':'Update production milestone','Shipment':'Prepare shipment and tracking','Invoice':'Prepare invoice and payment follow-up'};return map[phase.taskPack]||'Review current phase'}
function activateTaskPackForJob(j,phaseIndex,source='Phase activation'){
 const pack=phasePackFor(j.workflow||'standard',phaseIndex);if(!pack||!taskTemplates[pack])return 0;
 const existing=tasks.some(t=>t.job===j.id&&t.phase===pack&&t.activationPhase===phaseIndex);if(existing)return 0;
 taskTemplates[pack].forEach((title,i)=>{const assignee=users[(i+2)%users.length];tasks.unshift({id:'TSK-'+taskId++,job:j.id,title,status:i===0?'Ready':'Not Started',assignee:assignee.name,dept:assignee.dept,due:new Date(Date.parse(j.delivery)-Math.max(1,(10-i))*86400000).toISOString().slice(0,10),priority:j.priority,progress:0,phase:pack,activationPhase:phaseIndex,attention:false,checklist:[{text:'Confirm required information',done:false},{text:'Upload supporting file',done:false},{text:'Add completion comment',done:false}],comments:[{user:'Li Wei',text:`Task created automatically from ${pack} Task Pack. ${source}.`,time:'Just now'}]})});
 return taskTemplates[pack].length;
}
function activeMaster(key){return (masterData[key]||[]).filter(x=>x.status==='Active')}
function showToast(msg){const t=$('#toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500)}
function openOverlay(){ $('#overlay').classList.add('open') }function closeAll(){ $('#overlay').classList.remove('open');$('#jobModal').classList.remove('open');$('#detailDrawer').classList.remove('open') }
function navigate(page){page=typeof page==='string'?page.trim():'';const target=page?document.getElementById('page-'+page):null;if(!target)return false;currentPage=page;$$('.page').forEach(p=>p.classList.remove('active'));target.classList.add('active');$$('[data-page]').forEach(b=>b.classList.toggle('active',b.dataset.page===page));$('#sidebar')?.classList.remove('open');$('#sidebarShade')?.classList.remove('open');renderPage(page);window.scrollTo({top:0,behavior:'smooth'});return true}
function renderPage(page){({dashboard:renderDashboard,mywork:renderMyWork,jobs:renderJobs,clients:renderClients,board:renderBoard,documents:renderDocuments,reports:renderReports,notifications:renderNotifications,workflowsetup:renderWorkflowSetup,taskpacksetup:renderTaskPackSetup,masterdata:renderMasterData,setup:renderSetup,profile:renderProfile}[page]||(()=>{}))()}
function renderDashboard(){
 const active=jobs.filter(j=>j.phase<10).length, atRisk=jobs.filter(j=>['At Risk','Delayed','Blocked'].includes(j.health)).length, overdue=tasks.filter(t=>t.due<today&&t.status!=='Completed').length, approvals=tasks.filter(t=>/Waiting/.test(t.status)).length, shipment=jobs.filter(j=>j.phase===8).length, outstanding=clients.reduce((a,c)=>a+c.outstanding,0);
 const attentionJobs=jobs.filter(j=>j.attention).slice(0,6);const phaseCounts=phaseShort.map((p,i)=>jobs.filter(j=>j.phase===i).length).filter((_,i)=>[0,1,2,3,5,6,7,8,9,10].includes(i));
 $('#page-dashboard').innerHTML=`<div class="page-head"><div><h1>Management Dashboard</h1><p>Tuesday, July 28 · Exceptions, workload and delivery health</p></div><div class="actions"><button class="ghost" data-dashboard-action="export">Export summary</button><button class="primary" data-dashboard-action="create">＋ New Job</button></div></div>
 <div class="metrics">
 ${metric('Active Jobs',active,'Across all phases','jobs')}${metric('Needs Attention',atRisk,'Risk, delay or blocker','risk')}${metric('Overdue Tasks',overdue,'Require immediate update','overdue')}${metric('Blocked Tasks',approvals,'Require resolution','approval')}${metric('Shipping Now',shipment,'Ready or in transit','shipment')}${metric('Outstanding',currency(outstanding),'Open client balance','payment')}
 </div>
 <div class="grid-2"><div class="card section-card"><div class="section-head"><h3>Needs Attention</h3><button class="link-btn" data-go="jobs">View all Jobs</button></div><div class="attention-list">${attentionJobs.map(j=>`<div class="attention-item" data-job="${j.id}"><span class="signal ${j.health==='Blocked'?'red':j.health==='Needs Attention'?'red':'red'}"></span><div><div class="item-title">${j.next}</div><div class="item-meta">${j.id} · ${clientName(j.client)} · ${phaseShort[j.phase]}</div></div>${healthBadge(j.health,j.healthReason)}</div>`).join('')}</div></div>
 <div class="card section-card"><div class="section-head"><h3>Jobs by Phase</h3><button class="link-btn" data-go="board">Open board</button></div><div class="phase-list">${['Quotation','Artwork','Swatch','Production','Shipment','Invoice'].map((p,i)=>{const indexes=[[0,1,2,3],[5],[6],[7],[8],[9]][i];const n=jobs.filter(j=>indexes.includes(j.phase)).length;return `<div class="phase-row"><span>${p}</span><div class="progress"><span style="width:${Math.max(8,n/6*100)}%"></span></div><b>${n}</b></div>`}).join('')}</div></div></div>
 <div class="grid-3"><div class="card section-card"><div class="section-head"><h3>Team Workload</h3><button class="link-btn" data-go="reports">Details</button></div>${['Chen Mei','Liu Fang','Zhao Lin','Xu Na','Yang Lei'].map(n=>{const c=tasks.filter(t=>t.assignee===n&&t.status!=='Completed').length;return `<div class="workload-row"><div class="person"><span class="avatar">${userInitials(n)}</span>${n}</div><div class="progress"><span style="width:${Math.min(100,c*12)}%"></span></div><b>${c}</b></div>`}).join('')}</div>
 <div class="card section-card"><div class="section-head"><h3>Upcoming Deliveries</h3><button class="link-btn" data-go="jobs">View calendar</button></div><table class="mini-table"><thead><tr><th>Job</th><th>Client</th><th>Delivery</th></tr></thead><tbody>${jobs.filter(j=>j.phase<10).sort((a,b)=>a.delivery.localeCompare(b.delivery)).slice(0,6).map(j=>`<tr data-job="${j.id}" style="cursor:pointer"><td><b>${j.id.slice(-5)}</b><div class="muted small truncate" style="max-width:125px">${j.title}</div></td><td>${clientName(j.client).split(' ')[0]}</td><td class="${j.delivery<today?'due overdue':''}">${formatDate(j.delivery)}</td></tr>`).join('')}</tbody></table></div>
 <div class="card section-card"><div class="section-head"><h3>Recent Activity</h3><button class="link-btn" data-go="mywork">View Tasks</button></div>${tasks.slice().reverse().slice(0,5).map(t=>`<div class="activity" data-task="${t.id}" style="cursor:pointer"><span class="avatar">${userInitials(t.assignee||'System')}</span><div><div class="activity-text"><b>Task created</b><br>${t.id} · ${t.title}${t.job?` · ${t.job}`:''}</div><div class="activity-time">${t.createdAt?new Date(t.createdAt).toLocaleString():'Recently created'}</div></div></div>`).join('')||'<div class="empty-state">No recently created tasks.</div>'}</div></div>`;
 bindCommon();$$('[data-dashboard-action="create"]').forEach(b=>b.addEventListener('click',openJobModal));
}
function metric(label,value,foot,filter){return `<div class="card metric" data-metric="${filter}"><div class="metric-label">${label}</div><div class="metric-value">${value}</div><div class="metric-foot">${foot}</div><div class="metric-icon">◎</div></div>`}
function renderJobs(){
 let filtered=jobs.filter(j=>(!jobFilter.search||`${j.id} ${j.title} ${clientName(j.client)} ${j.product}`.toLowerCase().includes(jobFilter.search.toLowerCase()))&&(!jobFilter.phase||String(j.phase)===jobFilter.phase)&&(!jobFilter.health||j.health===jobFilter.health)&&(!jobFilter.client||String(j.client)===jobFilter.client));
 $('#page-jobs').innerHTML=`<div class="page-head"><div><h1>Jobs</h1><p>One continuous record from request to collection</p></div><div class="actions"><button class="ghost" id="jobExport">Export</button><button class="primary" id="jobCreate">＋ New Job</button></div></div>
 <div class="saved-views"><button class="view-chip active" data-saved="all">All active</button><button class="view-chip" data-saved="risk">At risk</button><button class="view-chip" data-saved="shipping">Shipping this week</button><button class="view-chip" data-saved="payment">Unpaid invoices</button><button class="view-chip" data-saved="artwork">Artwork approval</button></div>
 <div class="card toolbar"><input id="jobSearch" class="search-small" placeholder="Search Job ID, client, product…" value="${jobFilter.search}"><select id="jobPhase"><option value="">All phases</option>${phases.map((p,i)=>`<option value="${i}" ${jobFilter.phase===String(i)?'selected':''}>${p}</option>`).join('')}</select><select id="jobHealth"><option value="">All health</option>${['On Track','Needs Attention','At Risk','Delayed','Blocked','Completed'].map(h=>`<option ${jobFilter.health===h?'selected':''}>${h}</option>`).join('')}</select><select id="jobClient"><option value="">All clients</option>${clients.map(c=>`<option value="${c.id}" ${jobFilter.client===String(c.id)?'selected':''}>${c.name}</option>`).join('')}</select><button class="ghost" id="clearJobFilter">Clear</button></div>
 <div class="card table-wrap"><table class="data-table"><thead><tr><th>Job / Order</th><th>Client</th><th>Product</th><th>Phase</th><th>Owner</th><th>Delivery</th><th>Progress</th><th>Health</th><th></th></tr></thead><tbody>${filtered.map(j=>`<tr><td><div class="job-link" data-job="${j.id}">${j.id}</div><div class="muted small">${j.order||'Quotation request'}</div></td><td><b>${clientName(j.client)}</b><div class="muted small">${j.title}</div></td><td>${j.product}<div class="muted small">${j.qty.toLocaleString()} units</div></td><td>${statusBadge(phaseShortFor(j))}<div class="muted small" style="margin-top:4px">${j.status}${(j.createdFromPhase||0)>0?' · Started at '+phaseNameFor(j,j.createdFromPhase):''}</div></td><td><div class="person"><span class="avatar">${userInitials(j.coordinator)}</span>${j.coordinator}</div></td><td class="${j.delivery<today&&j.phase<10?'due overdue':''}">${formatDate(j.delivery)}</td><td style="min-width:120px"><div class="progress"><span style="width:${j.progress}%"></span></div><div class="small muted" style="margin-top:4px">${j.progress}%</div></td><td>${healthBadge(j.health,j.healthReason)}</td><td><button class="mini-btn" data-job="${j.id}">Open</button></td></tr>`).join('')||`<tr><td colspan="9"><div class="empty">No Jobs match the selected filters.</div></td></tr>`}</tbody></table></div>`;
 $('#jobCreate').addEventListener('click',openJobModal);$('#jobExport').addEventListener('click',()=>showToast('Job list export prepared (demo).'));$('#jobSearch').addEventListener('input',e=>{jobFilter.search=e.target.value;renderJobs()});$('#jobPhase').addEventListener('change',e=>{jobFilter.phase=e.target.value;renderJobs()});$('#jobHealth').addEventListener('change',e=>{jobFilter.health=e.target.value;renderJobs()});$('#jobClient').addEventListener('change',e=>{jobFilter.client=e.target.value;renderJobs()});$('#clearJobFilter').addEventListener('click',()=>{jobFilter={search:'',phase:'',health:'',client:''};renderJobs()});$$('[data-saved]').forEach(b=>b.addEventListener('click',()=>{const v=b.dataset.saved;jobFilter={search:'',phase:'',health:'',client:''};if(v==='risk')jobFilter.health='At Risk';if(v==='shipping')jobFilter.phase='8';if(v==='payment')jobFilter.phase='9';if(v==='artwork')jobFilter.phase='5';renderJobs()}));bindCommon();
}
function renderMyWork(){
 const mine=tasks.filter(t=>['Li Wei','Chen Mei','Zhao Lin','Liu Fang','Xu Na'].includes(t.assignee)&&t.status!=='Completed');
 const jobCount=new Set(mine.map(t=>t.job)).size;
 $('#page-mywork').innerHTML=`<div class="page-head"><div><h1>My Work</h1><p>What needs your action now</p></div><div class="actions"><button type="button" class="primary" data-go="jobs">Jobs</button></div></div>
 <div class="card toolbar"><input id="taskSearch" class="search-small" placeholder="Search assigned tasks…"><select id="taskStatus"><option value="">All statuses</option>${['Ready','In Progress','Waiting for Client','Revision Required','Blocked'].map(x=>`<option>${x}</option>`).join('')}</select><select id="taskAssignee"><option value="">Assignee</option>${users.slice(0,10).map(u=>`<option>${u.name}</option>`).join('')}</select></div>
 <div id="myTaskGrid" class="task-grid">${renderTaskCards(mine.slice(0,18))}</div><div id="myWorkLegacySummary" class="ops-status-summary my-work-footer-summary"><span>Showing <b>${mine.length}</b> visible tasks across <b>${jobCount}</b> ${jobCount===1?'job':'jobs'}</span></div>`;
 const rerender=()=>{const q=$('#taskSearch').value.toLowerCase(),s=$('#taskStatus').value,a=$('#taskAssignee').value;const arr=tasks.filter(t=>t.status!=='Completed'&&(!q||`${t.title} ${t.job}`.toLowerCase().includes(q))&&(!s||t.status===s)&&(!a||t.assignee===a));$('#myTaskGrid').innerHTML=renderTaskCards(arr);const jobsCount=new Set(arr.map(t=>t.job)).size;$('#myWorkLegacySummary').innerHTML=`<span>Showing <b>${arr.length}</b> visible tasks across <b>${jobsCount}</b> ${jobsCount===1?'job':'jobs'}</span>`;bindCommon()};$('#taskSearch').addEventListener('input',rerender);$('#taskStatus').addEventListener('change',rerender);$('#taskAssignee').addEventListener('change',rerender);bindCommon();
}
function renderTaskCards(arr){return arr.map(t=>`<div class="card task-card" data-task="${t.id}"><div class="task-top"><span class="badge b-gray">${t.id}</span>${statusBadge(t.status)}</div><h4>${t.title}</h4><p>${t.job} · ${clientName((jobs.find(j=>j.id===t.job)||{}).client)}</p><div style="margin-top:12px"><div class="progress"><span style="width:${t.progress}%"></span></div></div><div class="task-footer"><div class="person"><span class="avatar">${userInitials(t.assignee)}</span>${t.assignee}</div><span class="due ${dueClass(t.due)} small">${formatDate(t.due)}</span></div></div>`).join('')||'<div class="card empty">No tasks found.</div>'}
function renderClients(){
 $('#page-clients').innerHTML=`<div class="page-head"><div><h1>Clients</h1><p>Contacts, active Jobs, documents and outstanding balances</p></div><div class="actions"><button class="primary" id="newClient">＋ New Client</button></div></div><div class="card toolbar"><input id="clientSearch" class="search-small" placeholder="Search client, country or manager…"><select id="clientCountry"><option value="">All countries</option>${Array.from(new Set(clients.map(c=>c.country))).map(c=>`<option>${c}</option>`).join('')}</select></div><div id="clientGrid" class="client-grid">${clientCards(clients)}</div>`;
 const filter=()=>{const q=$('#clientSearch').value.toLowerCase(),country=$('#clientCountry').value;$('#clientGrid').innerHTML=clientCards(clients.filter(c=>(!q||`${c.name} ${c.country} ${c.manager}`.toLowerCase().includes(q))&&(!country||c.country===country)));bindCommon()};$('#clientSearch').addEventListener('input',filter);$('#clientCountry').addEventListener('change',filter);$('#newClient').addEventListener('click',()=>showToast('Client creation form is available in the production build.'));bindCommon();
}
function clientCards(arr){return arr.map(c=>{const cj=jobs.filter(j=>j.client===c.id);return `<div class="card client-card" data-client="${c.id}"><div class="client-head"><div class="company-logo">${c.code}</div><div><h3>${c.name}</h3><div class="small muted">${c.country} · ${c.contact}</div></div></div><div class="client-stats"><div class="client-stat"><b>${cj.filter(j=>j.phase<10).length}</b><span>Active Jobs</span></div><div class="client-stat"><b>${cj.filter(j=>j.attention).length}</b><span>Attention</span></div><div class="client-stat"><b>${currency(c.outstanding).replace('$','')}</b><span>Outstanding</span></div></div><div class="small muted" style="margin-top:12px">Account manager: ${c.manager}</div></div>`}).join('')}
function renderBoard(){
 $('#page-board').innerHTML=`<div class="page-head"><div><h1>Workflow Board</h1><p>Drag cards to update phase or task status</p></div><div class="actions"><button class="ghost" id="boardFilter">Filters</button><button class="primary" id="boardCreate">＋ New Job</button></div></div><div class="board-tabs"><button class="tab-btn ${boardMode==='tasks'?'active':''}" data-boardmode="tasks">Task Board</button><button class="tab-btn ${boardMode==='jobs'?'active':''}" data-boardmode="jobs">Job Phase Board</button></div><div id="kanbanArea"></div>`;
 renderKanban();$$('[data-boardmode]').forEach(b=>b.addEventListener('click',()=>{boardMode=b.dataset.boardmode;renderBoard()}));$('#boardCreate').addEventListener('click',openJobModal);$('#boardFilter').addEventListener('click',()=>showToast('Use Jobs and My Work filters for detailed saved views.'));
}
function renderKanban(){const area=$('#kanbanArea');if(boardMode==='tasks'){const cols=['Ready','In Progress','Waiting for Client','Revision Required','Blocked','Completed'];area.innerHTML=`<div class="kanban">${cols.map(c=>`<div class="kanban-col"><div class="kanban-head"><span>${c}</span><span class="count">${tasks.filter(t=>t.status===c).length}</span></div><div class="kanban-list" data-drop-status="${c}">${tasks.filter(t=>t.status===c).slice(0,12).map(t=>`<article class="kanban-card" draggable="true" data-task="${t.id}"><span class="badge ${t.priority==='Critical'?'b-red':t.priority==='High'?'b-amber':'b-gray'}">${t.priority}</span><div class="kanban-card-title">${t.title}</div><div class="kanban-job">${t.job} · ${t.phase}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(t.assignee)}</span><span class="due ${dueClass(t.due)} small">${formatDate(t.due)}</span></div></article>`).join('')}</div></div>`).join('')}</div>`}else{const cols=[0,1,3,5,6,7,8,9];area.innerHTML=`<div class="kanban" style="grid-template-columns:repeat(8,minmax(230px,1fr))">${cols.map(i=>`<div class="kanban-col"><div class="kanban-head"><span>${phaseShort[i]}</span><span class="count">${jobs.filter(j=>j.phase===i).length}</span></div><div class="kanban-list" data-drop-phase="${i}">${jobs.filter(j=>j.phase===i).map(j=>`<article class="kanban-card" draggable="true" data-job="${j.id}">${healthBadge(j.health,j.healthReason)}<div class="kanban-card-title">${j.title}</div><div class="kanban-job">${j.id} · ${clientName(j.client)}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(j.coordinator)}</span><span class="small">${j.progress}%</span></div></article>`).join('')}</div></div>`).join('')}</div>`}
 let dragged=null;$$('.kanban-card').forEach(c=>{c.addEventListener('dragstart',()=>{dragged=c;c.classList.add('dragging')});c.addEventListener('dragend',()=>c.classList.remove('dragging'))});$$('.kanban-list').forEach(l=>{l.addEventListener('dragover',e=>e.preventDefault());l.addEventListener('drop',()=>{if(!dragged)return;if(boardMode==='tasks'){const t=tasks.find(x=>x.id===dragged.dataset.task);t.status=l.dataset.dropStatus;t.progress=t.status==='Completed'?100:Math.max(t.progress,35)}else{const j=jobs.find(x=>x.id===dragged.dataset.job);const newPhase=Number(l.dataset.dropPhase);if(newPhase>j.phase+1){showToast('Complete required phase controls before skipping ahead.');return}j.phase=newPhase;j.progress=Math.max(j.progress,Math.round(newPhase/10*100))}renderKanban();showToast('Board updated successfully.')})});bindCommon();
}
function renderDocuments(){
 $('#page-documents').innerHTML=`<div class="page-head"><div><h1>Document Archive</h1><p>Files remain connected to their client, Job, phase and version</p></div><div class="actions"><button class="primary" id="uploadBtn">＋ Upload Document</button></div></div><label class="upload-zone" id="uploadZone"><input id="fileInput" type="file" multiple><b>Drop documents here or click to upload</b><div class="muted small" style="margin-top:5px">The document category will be suggested from the selected Job and phase.</div></label><div class="card toolbar"><input id="docSearch" class="search-small" placeholder="Search file name, Job or client…"><select id="docType"><option value="">All categories</option>${Array.from(new Set(docs.map(d=>d.type))).map(t=>`<option>${t}</option>`).join('')}</select><select id="docClient"><option value="">All clients</option>${clients.map(c=>`<option value="${c.id}">${c.name}</option>`).join('')}</select></div><div id="docGrid" class="doc-grid">${docCards(docs)}</div>`;
 const filter=()=>{const q=$('#docSearch').value.toLowerCase(),t=$('#docType').value,c=$('#docClient').value;$('#docGrid').innerHTML=docCards(docs.filter(d=>(!q||`${d.name} ${d.job} ${clientName(d.client)}`.toLowerCase().includes(q))&&(!t||d.type===t)&&(!c||String(d.client)===c)));bindCommon()};$('#docSearch').addEventListener('input',filter);$('#docType').addEventListener('change',filter);$('#docClient').addEventListener('change',filter);$('#uploadBtn').addEventListener('click',()=>$('#fileInput').click());$('#fileInput').addEventListener('change',e=>{Array.from(e.target.files || []).forEach((f,i)=>docs.unshift({id:'DOC-'+(900+docs.length+i),name:f.name,type:'Other',job:jobs[0].id,client:jobs[0].client,version:'v1',uploadedBy:'Li Wei',date:today,final:false}));renderDocuments();showToast(`${e.target.files.length} document(s) added to this demo.`)});bindCommon();
}
function docCards(arr){return arr.map(d=>`<div class="card doc-card" data-doc="${d.id}"><div class="doc-icon">${d.name.endsWith('.pdf')?'PDF':'FILE'}</div><h4 class="truncate">${d.name}</h4><div class="doc-meta">${d.type} ${d.final?'<span class="badge b-green">Final</span>':''}<br>${d.job} · ${clientName(d.client)}<br>${d.version} · ${formatDate(d.date)} · ${d.uploadedBy}</div></div>`).join('')||'<div class="card empty">No documents found.</div>'}
function renderReports(){
 const phaseData=['Quotation','Artwork','Swatch','Production','Shipment','Invoice'].map((p,i)=>({label:p,value:jobs.filter(j=>[[0,1,2,3],[5],[6],[7],[8],[9]][i].includes(j.phase)).length}));const workload=users.slice(1,9).map(u=>({label:u.name,value:tasks.filter(t=>t.assignee===u.name&&t.status!=='Completed').length})).sort((a,b)=>b.value-a.value);
 $('#page-reports').innerHTML=`<div class="page-head"><div><h1>Reports</h1><p>Operational performance, conversion, workload and cash collection</p></div><div class="actions"><button class="ghost" id="reportPdf">Export PDF</button><button class="primary" id="reportExcel">Export Excel</button></div></div><div class="metrics">${metric('Quotation Conversion','61%','Last 90 days','')}${metric('On-time Jobs','78%','Completed deliveries','')}${metric('Task Completion','84%','Within target date','')}${metric('Avg. Artwork Cycle','4.2d','Request to approval','')}${metric('Shipment On Time','91%','Last 30 shipments','')}${metric('Open Receivables',currency(83100),'Across 7 invoices','')}</div><div class="report-grid"><div class="card section-card"><div class="section-head"><h3>Active Jobs by Phase</h3><span class="small muted">Current portfolio</span></div><div class="bars">${phaseData.map(x=>barRow(x.label,x.value,Math.max(...phaseData.map(y=>y.value)))).join('')}</div></div><div class="card section-card"><div class="section-head"><h3>Team Workload</h3><span class="small muted">Open tasks</span></div><div class="bars">${workload.map(x=>barRow(x.label,x.value,Math.max(...workload.map(y=>y.value)))).join('')}</div></div><div class="card section-card"><div class="section-head"><h3>Delivery Performance</h3></div><div class="kpi-list"><div class="kpi"><b>14</b><span>Delivered this month</span></div><div class="kpi"><b>11</b><span>Delivered on time</span></div><div class="kpi"><b>2</b><span>Delayed by supplier</span></div><div class="kpi"><b>1</b><span>Delayed by client approval</span></div></div></div><div class="card section-card"><div class="section-head"><h3>Receivable Ageing</h3></div><div class="bars">${[['Not due',41200],['1–15 days',22300],['16–30 days',12600],['30+ days',7000]].map(x=>barRow(x[0],x[1],41200,currency(x[1]))).join('')}</div></div></div>`;$('#reportPdf').addEventListener('click',()=>showToast('PDF export prepared (demo).'));$('#reportExcel').addEventListener('click',()=>showToast('Excel export prepared (demo).'));
}
function barRow(label,value,max,display=value){return `<div class="bar-row"><span>${label}</span><div class="bar"><span style="width:${value/max*100}%"></span></div><b>${display}</b></div>`}
function renderNotifications(){
 $('#page-notifications').innerHTML=`<div class="page-head"><div><h1>Notifications</h1><p>Grouped reminders, task updates and risk alerts</p></div><div class="actions"><button class="ghost" id="markAllRead">Mark all read</button></div></div><div class="card section-card"><div class="attention-list">${notifications.map(n=>`<div class="attention-item" data-notification="${n.id}" data-job="${n.job}" style="${n.read?'opacity:.62':''}"><span class="signal ${/risk|attention|flag|block/i.test(`${n.type||''} ${n.title||''} ${n.text||''}`)?'red':n.type==='approval'?'amber':'purple'}"></span><div><div class="item-title">${n.title} ${!n.read?'<span class="badge b-blue">New</span>':''}</div><div class="item-meta">${n.text} · ${n.time}</div></div><button class="mini-btn">Open</button></div>`).join('')}</div></div>`;$('#markAllRead').addEventListener('click',()=>{notifications.forEach(n=>n.read=true);renderNotifications();updateBadges();showToast('All notifications marked as read.')});bindCommon();
}
function renderTaskPackSetup(){
 $('#page-taskpacksetup').innerHTML=`<div class="page-head"><div><h1>Task Pack Setup</h1><p>Create reusable task sequences that activate when a Job enters a workflow phase</p></div><div class="actions"><button class="primary" id="addTaskPack">＋ Add Task Pack</button></div></div><div class="config-summary"><div class="card config-stat"><span>Total Task Packs</span><b>${Object.keys(taskTemplates).length}</b></div><div class="card config-stat"><span>Configured Tasks</span><b>${Object.values(taskTemplates).reduce((sum,list)=>sum+list.length,0)}</b></div><div class="card config-stat"><span>Mapped Workflows</span><b>${workflowTemplates.length}</b></div><div class="card config-stat"><span>Activation Rule</span><b style="font-size:15px">Phase Entry</b></div></div><div id="taskPackSetupContent"><div class="pack-list">${Object.entries(taskTemplates).map(([name,list])=>`<div class="card pack"><div class="section-head"><div><h4>${name} Task Pack</h4><div class="small muted">${list.length} predefined tasks</div></div><button class="mini-btn" data-edit-pack="${name}">Edit</button></div><ul class="pack-tasks">${list.map((x,i)=>`<li><span>${x}</span><span class="badge ${i<3?'b-blue':'b-gray'}">${i<3?'Mandatory':'Optional'}</span></li>`).join('')}</ul></div>`).join('')}</div></div>`;
 $('#addTaskPack').addEventListener('click',()=>showToast('A new Task Pack can be added here in the production system.'));
 $$('[data-edit-pack]').forEach(b=>b.addEventListener('click',()=>showToast(`${b.dataset.editPack} Task Pack opened for editing.`)));
}
function renderSetup(){
 $('#page-setup').innerHTML=`<div class="page-head"><div><h1>Administration</h1><p>Users, roles, permissions and notification rules</p></div><div class="actions"><button class="primary" id="saveSetup">Save Changes</button></div></div><div class="config-summary"><div class="card config-stat"><span>Active Users</span><b>${users.length}</b></div><div class="card config-stat"><span>Operational Roles</span><b>${Array.from(new Set(users.map(user=>user.role))).length}</b></div><div class="card config-stat"><span>Departments</span><b>${Array.from(new Set(users.map(user=>user.dept))).length}</b></div><div class="card config-stat"><span>Notification Rules</span><b>5</b></div></div><div class="setup-layout"><div class="card setup-menu"><button class="active" data-setup="users">Users</button><button data-setup="roles">Roles & Permissions</button><button data-setup="notification">Notification Rules</button></div><div id="setupContent"></div></div>`;
 renderSetupContent('users');
 $$('[data-setup]').forEach(b=>b.addEventListener('click',()=>{$$('[data-setup]').forEach(x=>x.classList.remove('active'));b.classList.add('active');renderSetupContent(b.dataset.setup)}));
 $('#saveSetup').addEventListener('click',()=>showToast('Administration settings saved.'));
}
function renderSetupContent(type){
 const el=$('#setupContent');
 if(type==='users'){
  el.innerHTML=`<div class="section-head"><div><h3>Users</h3><div class="small muted">Manage team membership, departments and language.</div></div><button class="primary">＋ Add User</button></div><div class="card table-wrap"><table class="data-table" style="min-width:700px"><thead><tr><th>User</th><th>Role</th><th>Department</th><th>Language</th><th>Open Tasks</th><th>Status</th></tr></thead><tbody>${users.map(u=>`<tr><td><div class="person"><span class="avatar">${u.initials}</span><b>${u.name}</b></div></td><td>${u.role}</td><td>${u.dept}</td><td>${u.language==='zh'?'中文 / English':'English'}</td><td>${tasks.filter(t=>t.assignee===u.name&&t.status!=='Completed').length}</td><td><span class="badge b-green">Active</span></td></tr>`).join('')}</tbody></table></div>`;
 } else if(type==='roles'){
  el.innerHTML=`<div class="section-head"><div><h3>Roles & Permissions</h3><div class="small muted">Permissions can be restricted by module, action, client and Job.</div></div></div><div class="card table-wrap"><table class="role-matrix"><thead><tr><th>Module</th><th>Management</th><th>Sales</th><th>Design</th><th>Production</th><th>Shipment</th><th>Accounts</th></tr></thead><tbody>${['Clients','Jobs','Financial values','Tasks','Documents','Reports','Workflow Setup','Task Pack Setup','Master Data'].map((m,i)=>`<tr><td><b>${m}</b></td>${['Management','Sales','Design','Production','Shipment','Accounts'].map((r,j)=>`<td><input type="checkbox" ${i>=6&&j>0?'':'checked'}></td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
 } else {
  el.innerHTML=`<div class="card section-card"><div class="section-head"><div><h3>Notification Rules</h3><div class="small muted">Grouped reminders reduce noise while keeping exceptions visible.</div></div><button class="primary">＋ Add Rule</button></div><table class="mini-table"><thead><tr><th>Name</th><th>Trigger</th><th>Recipients</th><th>Status</th></tr></thead><tbody>${[['Task due reminder','2 days before due','Assignee'],['Overdue escalation','1 day overdue','Assignee + Manager'],['Shipment deadline','3 days before ship date','Shipment + Manager'],['Invoice due reminder','5 days before due','Accounts + Sales'],['Blocked task escalation','Immediately','Manager + Job owner']].map(x=>`<tr><td><b>${x[0]}</b></td><td>${x[1]}</td><td>${x[2]}</td><td><span class="badge b-green">Active</span></td></tr>`).join('')}</tbody></table></div>`;
 }
}
function renderWorkflowSetup(){
 const selected=getWorkflow(selectedWorkflowId);
 $('#page-workflowsetup').innerHTML=`<div class="page-head"><div><h1>Workflow Setup</h1><p>Define phase sequence, allowed starting stages, Task Packs, documents and skip rules</p></div><div class="actions"><button class="ghost" id="cloneWorkflow">Duplicate</button><button class="primary" id="addWorkflow">＋ New Workflow</button></div></div><div class="config-summary"><div class="card config-stat"><span>Active Templates</span><b>${workflowTemplates.filter(w=>w.active).length}</b></div><div class="card config-stat"><span>Phases in Selected Workflow</span><b>${selected.phases.length}</b></div><div class="card config-stat"><span>Allowed Starting Stages</span><b>${selected.phases.filter(p=>p.allowStart).length}</b></div><div class="card config-stat"><span>Automatic Transitions</span><b>${selected.phases.filter(p=>p.autoAdvance).length}</b></div></div><div class="workflow-layout"><div class="card workflow-list"><div class="sidebar-section" style="padding-top:8px">Workflow Templates</div>${workflowTemplates.map(w=>`<button class="${w.id===selectedWorkflowId?'active':''}" data-workflow-id="${w.id}"><b>${w.name}</b><small>${w.phases.length} phases · ${w.active?'Active':'Inactive'}</small></button>`).join('')}</div><div class="workflow-editor"><div class="card section-card"><div class="section-head"><div><h3>${selected.name}</h3><div class="small muted">${selected.description}</div></div><div class="workflow-toolbar"><button class="ghost" id="editWorkflowInfo">Edit Details</button><button class="primary" id="addWorkflowPhase">＋ Add Phase</button></div></div><div class="source-note"><b>Starting-stage rule</b><div class="small muted" style="margin-top:4px">Only phases marked “Allow Job Start” appear in the New Job form. Earlier phases are recorded as skipped, completed outside the system, or migrated.</div></div><div class="table-wrap" style="margin-top:14px"><table class="phase-config-table"><thead><tr><th>#</th><th>Phase</th><th>Task Pack</th><th>Allow Job Start</th><th>Can Skip</th><th>Required Document</th><th>Entry / Exit</th><th>Actions</th></tr></thead><tbody>${selected.phases.map((p,i)=>`<tr><td><div class="sequence-controls"><button data-phase-up="${i}" ${i===0?'disabled':''}>↑</button><button data-phase-down="${i}" ${i===selected.phases.length-1?'disabled':''}>↓</button></div></td><td><b>${p.name}</b><div class="small muted">Stage ${i+1}</div></td><td>${p.taskPack||'No Task Pack'}</td><td><label class="toggle-chip"><input type="checkbox" data-toggle-start="${i}" ${p.allowStart?'checked':''}> ${p.allowStart?'Allowed':'Not allowed'}</label></td><td><label class="toggle-chip"><input type="checkbox" data-toggle-skip="${i}" ${p.skippable?'checked':''}> ${p.skippable?'Yes':'No'}</label></td><td>${p.document||'—'}</td><td><div><b>In:</b> ${p.entry}</div><div class="small muted"><b>Out:</b> ${p.exit}</div></td><td><div class="row-actions"><button class="mini-btn" data-edit-phase="${i}">Edit</button><button class="mini-btn" data-delete-phase="${i}">Remove</button></div></td></tr>`).join('')}</tbody></table></div></div></div></div>`;
 $$('[data-workflow-id]').forEach(b=>b.addEventListener('click',()=>{selectedWorkflowId=b.dataset.workflowId;renderWorkflowSetup()}));
 $$('[data-toggle-start]').forEach(c=>c.addEventListener('change',()=>{selected.phases[Number(c.dataset.toggleStart)].allowStart=c.checked;renderWorkflowSetup();showToast('Allowed starting stages updated.')}));
 $$('[data-toggle-skip]').forEach(c=>c.addEventListener('change',()=>{selected.phases[Number(c.dataset.toggleSkip)].skippable=c.checked;renderWorkflowSetup();showToast('Phase skip rule updated.')}));
 $$('[data-phase-up]').forEach(b=>b.addEventListener('click',()=>{const i=Number(b.dataset.phaseUp);[selected.phases[i-1],selected.phases[i]]=[selected.phases[i],selected.phases[i-1]];renderWorkflowSetup()}));
 $$('[data-phase-down]').forEach(b=>b.addEventListener('click',()=>{const i=Number(b.dataset.phaseDown);[selected.phases[i+1],selected.phases[i]]=[selected.phases[i],selected.phases[i+1]];renderWorkflowSetup()}));
 $$('[data-edit-phase]').forEach(b=>b.addEventListener('click',()=>openPhaseModal(selected,Number(b.dataset.editPhase))));
 $$('[data-delete-phase]').forEach(b=>b.addEventListener('click',()=>{const i=Number(b.dataset.deletePhase);if(selected.phases.length<=2){showToast('A workflow needs at least two phases.');return}selected.phases.splice(i,1);renderWorkflowSetup();showToast('Phase removed from this demo workflow.')}));
 $('#addWorkflowPhase').addEventListener('click',()=>openPhaseModal(selected,-1));
 $('#editWorkflowInfo').addEventListener('click',()=>showToast('Workflow name, description and activation settings can be edited here.'));
 $('#addWorkflow').addEventListener('click',()=>{const id='workflow-'+(workflowTemplates.length+1);workflowTemplates.push({id,name:'New Workflow Template',description:'Custom workflow template.',active:true,phases:getWorkflow('standard').phases.map(p=>({...p}))});selectedWorkflowId=id;renderWorkflowSetup();showToast('New workflow template created.')});
 $('#cloneWorkflow').addEventListener('click',()=>{const id=selected.id+'-copy-'+workflowTemplates.length;workflowTemplates.push({id,name:selected.name+' Copy',description:selected.description,active:true,phases:selected.phases.map(p=>({...p}))});selectedWorkflowId=id;renderWorkflowSetup();showToast('Workflow duplicated.')});
}
function openPhaseModal(workflow,index){
 const existing=index>=0?workflow.phases[index]:{name:'New Phase',short:'New',taskPack:'',allowStart:true,skippable:true,entry:'Previous phase complete',exit:'Required work complete',document:''};
 $('#jobModal').innerHTML=`<div class="modal-head"><h2>${index>=0?'Edit':'Add'} Workflow Phase</h2><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid"><div class="field"><label>Phase name *</label><input id="phaseName" value="${existing.name}"></div><div class="field"><label>Short label</label><input id="phaseShortName" value="${existing.short}"></div><div class="field"><label>Task Pack</label><select id="phaseTaskPack"><option value="">No Task Pack</option>${Object.keys(taskTemplates).map(p=>`<option ${p===existing.taskPack?'selected':''}>${p}</option>`).join('')}</select></div><div class="field"><label>Required document</label><select id="phaseDocument"><option value="">None</option>${activeMaster('documentCategories').map(d=>`<option ${d.name===existing.document?'selected':''}>${d.name}</option>`).join('')}</select></div><div class="field full"><label>Entry rule</label><input id="phaseEntry" value="${existing.entry}"></div><div class="field full"><label>Exit control</label><input id="phaseExit" value="${existing.exit}"></div></div><label class="check-row"><input id="phaseAllowStart" type="checkbox" ${existing.allowStart?'checked':''}> <span>Allow users to create a Job starting from this phase</span></label><label class="check-row"><input id="phaseCanSkip" type="checkbox" ${existing.skippable?'checked':''}> <span>Allow this phase to be skipped during normal progression</span></label></div><div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="savePhase">Save Phase</button></div>`;
 openOverlay();$('#jobModal').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));
 $('#savePhase').addEventListener('click',()=>{const name=$('#phaseName').value.trim();if(!name){showToast('Please enter a phase name.');return}const value={name,short:$('#phaseShortName').value.trim()||name,taskPack:$('#phaseTaskPack').value,allowStart:$('#phaseAllowStart').checked,skippable:$('#phaseCanSkip').checked,entry:$('#phaseEntry').value.trim(),exit:$('#phaseExit').value.trim(),document:$('#phaseDocument').value};if(index>=0)workflow.phases[index]=value;else workflow.phases.splice(Math.max(0,workflow.phases.length-1),0,value);closeAll();renderWorkflowSetup();showToast('Workflow phase saved.')});
}
function renderMasterData(){
 const rows=masterData[selectedMasterCategory]||[];
 $('#page-masterdata').innerHTML=`<div class="page-head"><div><h1>Master Data</h1><p>Maintain the values used in Jobs, tasks, documents, production and shipment forms</p></div><div class="actions"><button class="ghost" id="masterImport">Import</button><button class="primary" id="addMasterRecord">＋ Add Record</button></div></div><div class="config-summary"><div class="card config-stat"><span>Master Categories</span><b>${Object.keys(masterData).length}</b></div><div class="card config-stat"><span>Total Records</span><b>${Object.values(masterData).reduce((a,x)=>a+x.length,0)}</b></div><div class="card config-stat"><span>Active Records</span><b>${Object.values(masterData).flat().filter(x=>x.status==='Active').length}</b></div><div class="card config-stat"><span>Selected Category</span><b style="font-size:15px">${masterLabels[selectedMasterCategory]}</b></div></div><div class="master-layout"><div class="card master-categories">${Object.entries(masterLabels).map(([key,label])=>`<button class="${key===selectedMasterCategory?'active':''}" data-master-category="${key}"><b>${label}</b><small>${masterData[key].length} records</small></button>`).join('')}</div><div><div class="card section-card"><div class="master-head"><div><h3 style="margin:0 0 4px">${masterLabels[selectedMasterCategory]}</h3><div class="small muted">Active records are available in operational forms and setup rules.</div></div><input id="masterSearch" style="max-width:260px;border:1px solid var(--line);border-radius:8px;padding:9px" placeholder="Search records…"></div><div class="table-wrap"><table class="master-table"><thead><tr><th>Code</th><th>Name</th><th>Description / Use</th><th>Status</th><th>Actions</th></tr></thead><tbody id="masterRows">${masterRowsHtml(rows)}</tbody></table></div></div></div></div>`;
 $$('[data-master-category]').forEach(b=>b.addEventListener('click',()=>{selectedMasterCategory=b.dataset.masterCategory;renderMasterData()}));
 $('#addMasterRecord').addEventListener('click',()=>openMasterModal(selectedMasterCategory,-1));
 $('#masterImport').addEventListener('click',()=>showToast('Excel/CSV import is represented in the production scope.'));
 $('#masterSearch').addEventListener('input',e=>{$('#masterRows').innerHTML=masterRowsHtml(rows.filter(r=>`${r.code} ${r.name} ${r.detail}`.toLowerCase().includes(e.target.value.toLowerCase())));bindMasterRows()});
 bindMasterRows();
}
function masterRowsHtml(rows){return rows.map((r,i)=>`<tr><td><b>${r.code}</b></td><td>${r.name}</td><td>${r.detail}</td><td><span class="badge ${r.status==='Active'?'b-green':'b-gray'}">${r.status}</span></td><td><div class="row-actions"><button class="mini-btn" data-master-edit="${masterData[selectedMasterCategory].indexOf(r)}">Edit</button><button class="mini-btn" data-master-toggle="${masterData[selectedMasterCategory].indexOf(r)}">${r.status==='Active'?'Deactivate':'Activate'}</button></div></td></tr>`).join('')||'<tr><td colspan="5"><div class="empty-state">No records found.</div></td></tr>'}
function bindMasterRows(){
 $$('[data-master-edit]').forEach(b=>b.addEventListener('click',()=>openMasterModal(selectedMasterCategory,Number(b.dataset.masterEdit))));
 $$('[data-master-toggle]').forEach(b=>b.addEventListener('click',()=>{const r=masterData[selectedMasterCategory][Number(b.dataset.masterToggle)];r.status=r.status==='Active'?'Inactive':'Active';renderMasterData();showToast(`${r.name} is now ${r.status}.`)}));
}
function openMasterModal(category,index){
 const existing=index>=0?masterData[category][index]:{code:'',name:'',detail:'',status:'Active'};
 $('#jobModal').innerHTML=`<div class="modal-head"><h2>${index>=0?'Edit':'Add'} ${masterLabels[category]} Record</h2><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid"><div class="field"><label>Code *</label><input id="masterCode" value="${existing.code}" placeholder="Unique short code"></div><div class="field"><label>Status</label><select id="masterStatus"><option ${existing.status==='Active'?'selected':''}>Active</option><option ${existing.status==='Inactive'?'selected':''}>Inactive</option></select></div><div class="field full"><label>Name *</label><input id="masterName" value="${existing.name}"></div><div class="field full"><label>Description / Use</label><textarea id="masterDetail" rows="3">${existing.detail}</textarea></div></div></div><div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveMasterRecord">Save Record</button></div>`;
 openOverlay();$('#jobModal').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));
 $('#saveMasterRecord').addEventListener('click',()=>{const code=$('#masterCode').value.trim(),name=$('#masterName').value.trim();if(!code||!name){showToast('Code and name are required.');return}const value={code,name,detail:$('#masterDetail').value.trim(),status:$('#masterStatus').value};if(index>=0)masterData[category][index]=value;else masterData[category].push(value);closeAll();renderMasterData();showToast('Master-data record saved.')});
}
function renderProfile(){ $('#page-profile').innerHTML=`<div class="page-head"><div><h1>User Profile</h1><p>Loading your account information…</p></div></div><div class="card empty">Loading profile…</div>`; }
function openJobModal(prefillClient){
 const categories=activeMaster('productCategories');
 $('#jobModal').innerHTML=`<div class="modal-head"><h2>Create New Job</h2><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid"><div class="field"><label>Client *</label><select id="newJobClient">${clients.map(c=>`<option value="${c.id}" ${Number(prefillClient)===c.id?'selected':''}>${c.name}</option>`).join('')}</select></div><div class="field"><label>Client contact</label><input id="newJobContact" value="${clients.find(c=>c.id===Number(prefillClient))?.contact||clients[0].contact}"></div><div class="field full"><label>Job title *</label><input id="newJobTitle" placeholder="e.g. 5,000 printed lanyards for conference"></div><div class="field"><label>Product category *</label><select id="newJobCategory">${categories.map(x=>`<option>${x.name}</option>`).join('')}</select></div><div class="field"><label>Product *</label><input id="newJobProduct" list="productSuggestions" placeholder="Product name"><datalist id="productSuggestions">${activeMaster('products').map(p=>`<option value="${p.name}">`).join('')}</datalist></div><div class="field"><label>Quantity *</label><input id="newJobQty" type="number" value="1000"></div><div class="field"><label>Required delivery date *</label><input id="newJobDelivery" type="date" value="2026-09-30"></div><div class="field"><label>Priority</label><select id="newJobPriority">${activeMaster('priorities').map(p=>`<option>${p.name}</option>`).join('')}</select></div><div class="field"><label>Assignee</label><select id="newJobOwner">${users.filter(u=>u.dept==='Sales').map(u=>`<option>${u.name}</option>`).join('')}</select></div><div class="field"><label>Workflow template *</label><select id="newJobWorkflow">${workflowTemplates.filter(w=>w.active).map(w=>`<option value="${w.id}">${w.name}</option>`).join('')}</select><div class="inline-help">The selected workflow controls available starting stages and Task Packs.</div></div><div class="field"><label>Start Job from stage *</label><select id="newJobStartPhase"></select><div class="inline-help">Use this when earlier stages happened outside the system or do not apply.</div></div><div id="skipHandlingWrap" class="field full hidden"><label>How should earlier phases be recorded? *</label><select id="newJobSkipHandling"><option>Completed outside FlowTrack</option><option>Not applicable for this Job</option><option>Migrated from an existing process</option><option>Manager-authorized skip</option></select></div><div id="skipReasonWrap" class="field full hidden"><label>Reason / reference *</label><textarea id="newJobStartReason" rows="2" placeholder="Explain why the Job begins at this stage, and add any external reference."></textarea></div><div class="field full"><label>Request description</label><textarea id="newJobDesc" rows="3" placeholder="Specifications, target price, printing or customization requirements…"></textarea></div></div><div id="jobStagePreview" class="stage-preview"></div></div><div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="secondary" id="saveDraftJob">Save Draft</button><button class="primary" id="createJob">Create</button></div>`;
 openOverlay();$('#jobModal').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));
 const refreshStages=()=>{const wf=getWorkflow($('#newJobWorkflow').value),select=$('#newJobStartPhase');select.innerHTML=wf.phases.map((p,i)=>p.allowStart?`<option value="${i}">${i+1}. ${p.name}</option>`:'').join('');if(!select.options.length)select.innerHTML='<option value="0">No starting stage configured</option>';refreshStagePreview()};
 const refreshStagePreview=()=>{const wf=getWorkflow($('#newJobWorkflow').value),idx=Number($('#newJobStartPhase').value||0),phase=wf.phases[idx],skipped=wf.phases.slice(0,idx);$('#skipHandlingWrap').classList.toggle('hidden',idx===0);$('#skipReasonWrap').classList.toggle('hidden',idx===0);$('#jobStagePreview').innerHTML=`<b>Workflow activation preview</b><div class="stage-preview-grid"><div class="stage-preview-box"><span>Job will start at</span><b>${phase?.name||'—'}</b></div><div class="stage-preview-box"><span>Task Pack to activate</span><b>${phase?.taskPack||'No tasks at this stage'}</b></div></div>${idx>0?`<div class="skip-warning"><b>${skipped.length} earlier phase${skipped.length===1?'':'s'} will not generate tasks.</b><ul>${skipped.map(p=>`<li>${p.name}</li>`).join('')}</ul><div class="small" style="margin-top:6px">The reason and selected handling will be saved in the Job activity history.</div></div>`:`<div class="small muted" style="margin-top:9px">The Job starts normally from the first stage.</div>`}`};
 $('#newJobClient').addEventListener('change',e=>{$('#newJobContact').value=clients.find(c=>c.id===Number(e.target.value)).contact});
 $('#newJobWorkflow').addEventListener('change',refreshStages);$('#newJobStartPhase').addEventListener('change',refreshStagePreview);refreshStages();
 $('#createJob').addEventListener('click',()=>createJob(false));$('#saveDraftJob').addEventListener('click',()=>createJob(true));
}
function createJob(draft){
 const title=$('#newJobTitle').value.trim(),product=$('#newJobProduct').value.trim(),workflowId=$('#newJobWorkflow').value,startPhase=Number($('#newJobStartPhase').value||0),wf=getWorkflow(workflowId),reason=$('#newJobStartReason')?.value.trim()||'',handling=$('#newJobSkipHandling')?.value||'';
 if(!title||!product){showToast('Please enter the Job title and product.');return}
 if(startPhase>0&&!reason){showToast('Please explain why earlier workflow phases are being skipped.');return}
 const nextNum=126+jobs.length,id='JOB-2026-'+String(nextNum).padStart(5,'0'),order=startPhase>=4?'ORD-2026-'+String(90+jobs.length).padStart(5,'0'):'';
 const skippedPhases=Array.from({length:startPhase},(_,i)=>i);
 const j={id,order,title,client:Number($('#newJobClient').value),product,category:$('#newJobCategory').value,qty:Number($('#newJobQty').value),price:0,currency:'USD',workflow:workflowId,phase:startPhase,status:draft?'Draft':(startPhase===0?'New':'In Progress'),health:'On Track',progress:draft?0:Math.min(95,Math.max(4,Math.round(startPhase/Math.max(1,wf.phases.length-1)*100))),owner:$('#newJobOwner').value,coordinator:'Li Wei',delivery:$('#newJobDelivery').value,priority:$('#newJobPriority').value,next:nextActionForPhase(workflowId,startPhase),milestone:milestoneForPhase(workflowId,startPhase),attention:false,skippedPhases,startHandling:startPhase>0?handling:'Normal start',startReason:reason,createdFromPhase:startPhase,activity:[{title:'Job created',text:startPhase>0?`Job created directly at ${wf.phases[startPhase].name}. Earlier phases recorded as: ${handling}. Reason: ${reason}`:'Job created from the first workflow stage.',time:'Just now'}]};
 jobs.unshift(j);let created=0;if(!draft)created=activateTaskPackForJob(j,startPhase,'Job creation');closeAll();showToast(draft?'Draft Job saved.':`Job created at ${wf.phases[startPhase].name}. ${created} tasks activated.`);navigate('jobs');
}
function openJob(id){const j=jobs.find(x=>x.id===id);if(!j)return;const jt=tasks.filter(t=>t.job===id),jd=docs.filter(d=>d.job===id);$('#detailDrawer').innerHTML=`<div class="drawer-head"><div><div class="small muted">${j.id} ${j.order?'· '+j.order:''}</div><h2>${j.title}</h2><div>${healthBadge(j.health,j.healthReason)} ${statusBadge(j.status)}</div></div><button class="close-btn" data-close>×</button></div><div class="drawer-body"><div class="detail-tabs">${['Overview','Workflow','Tasks','Quotation','Artwork','Production','Shipment','Invoice','Documents','Activity'].map((x,i)=>`<button class="detail-tab ${i===0?'active':''}" data-detailtab="${i}">${x}</button>`).join('')}</div><div id="jobDetailPanels">${jobPanels(j,jt,jd)}</div></div>`;openOverlay();$('#detailDrawer').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));$$('[data-detailtab]').forEach(b=>b.addEventListener('click',()=>{$$('[data-detailtab]').forEach(x=>x.classList.remove('active'));b.classList.add('active');$$('.detail-panel').forEach(x=>x.classList.remove('active'));$('#detailPanel'+b.dataset.detailtab).classList.add('active')}));bindCommon();
}
function jobProductItems(j){
 const items=Array.isArray(j.products)&&j.products.length?j.products:[{product:j.product||'Unspecified product',category:j.category||'',qty:Number(j.qty||0)}];
 return items.map(item=>({product:item.product||'Deleted product',category:item.category||'',qty:Number(item.qty||0)}));
}
function jobProductSummary(j){const items=jobProductItems(j);return `<div class="job-products-summary"><div class="job-products-summary-head"><b>Products</b><span class="small muted">${items.length} line${items.length===1?'':'s'} · ${Number(j.qty||0).toLocaleString()} total units</span></div>${items.map(item=>`<div class="job-products-summary-row"><div><b>${item.product}</b>${item.category?`<small>${item.category}</small>`:''}</div><b>${item.qty.toLocaleString()} units</b></div>`).join('')}</div>`}
function jobPanels(j,jt,jd){
 const wf=getWorkflow(j.workflow||'standard'),skipped=j.skippedPhases||[],activity=(j.activity||[]);
 return `<section id="detailPanel0" class="detail-panel active"><div class="summary-grid"><div class="summary-box"><span>Current Phase</span><b>${phaseNameFor(j)}</b></div><div class="summary-box"><span>Next Required Action</span><b>${j.next}</b></div><div class="summary-box"><span>Delivery Deadline</span><b class="${j.delivery<today?'due overdue':''}">${formatDate(j.delivery)}</b></div><div class="summary-box"><span>Workflow Template</span><b>${wf.name}</b></div><div class="summary-box"><span>Job Started At</span><b>${phaseNameFor(j,j.createdFromPhase??0)}</b></div><div class="summary-box"><span>Commercial Value</span><b>${j.price?currency(j.price,j.currency):'Quotation stage'}</b></div></div>${j.requestDescription?`<div class="source-note" style="margin-top:16px"><b>Request description</b><div class="small muted" style="margin-top:5px;white-space:pre-wrap">${safeHtml(j.requestDescription)}</div></div>`:''}${jobProductSummary(j)}${skipped.length?`<div class="source-note"><b>Started after earlier phases</b><div class="small muted" style="margin-top:4px">${skipped.length} phase${skipped.length===1?' was':'s were'} recorded as “${j.startHandling}”. ${j.startReason||''}</div></div>`:''}<div style="margin-top:18px"><div class="section-head"><h3>Overall Progress</h3><b>${j.progress}%</b></div><div class="progress" style="height:10px"><span style="width:${j.progress}%"></span></div></div><div class="card" style="margin-top:18px;padding:14px;background:#f8faff"><b>Latest update</b><div class="muted" style="margin-top:5px">${j.next}. Last updated by ${j.coordinator} today at 10:24.</div></div><div class="section-head" style="margin-top:20px"><h3>Open Tasks</h3><button class="link-btn" data-open-job-tasks>View all</button></div>${jt.filter(t=>t.status!=='Completed').slice(0,5).map(t=>`<div class="attention-item" data-task="${t.id}"><span class="signal ${t.attention?'red':'amber'}"></span><div><div class="item-title">${t.title}</div><div class="item-meta">${t.assignee} · Due ${formatDate(t.due)}</div></div>${statusBadge(t.status)}</div>`).join('')||'<div class="empty-state">No active tasks in this phase.</div>'}</section>
<section id="detailPanel1" class="detail-panel"><div class="timeline">${wf.phases.map((p,i)=>`<div class="step ${skipped.includes(i)?'skipped':i<j.phase?'done':i===j.phase?'current':''}"><div class="step-dot">${skipped.includes(i)?'↷':i<j.phase?'✓':i+1}</div><div class="step-label">${p.name}${skipped.includes(i)?'<br><small>Skipped / external</small>':''}</div></div>`).join('')}</div>${skipped.length?`<div class="skip-warning"><b>Historical phase handling</b><div style="margin-top:5px">${j.startHandling}: ${j.startReason}</div></div>`:''}<div class="card section-card" style="margin-top:14px"><div class="section-head"><h3>Current Phase Controls</h3><div><span class="badge b-blue">${phasePackFor(j.workflow||'standard',j.phase)||'No Task Pack'}</span> <span class="badge ${j.phaseReadiness==='ready'?'b-green':j.phaseReadiness==='blocked'?'b-red':'b-amber'}">${j.phaseReadinessLabel||'In Progress'}</span></div></div><div class="source-note"><b>Phase readiness</b><div class="small muted" style="margin-top:4px">${(j.phaseReadinessDetails?.blockers||[]).join(' ')||'All configured task, document and blocker gates are satisfied.'}</div></div><div class="check-row"><input type="checkbox" disabled ${j.phaseReadinessDetails?.task_gate?.met?'checked':''}> Required tasks completed (${j.phaseReadinessDetails?.task_gate?.completed||0}/${j.phaseReadinessDetails?.task_gate?.required||0})</div>${j.phaseReadinessDetails?.document_gate?.required?`<div class="check-row"><input type="checkbox" disabled ${j.phaseReadinessDetails?.document_gate?.met?'checked':''}> Required document(s) uploaded</div>`:''}<div class="actions" style="margin-top:14px"><button class="secondary" data-open-job-tasks>View Phase Tasks</button>${j.phase<wf.phases.length-1?`<button class="primary" data-next-phase="${j.id}" ${j.phaseReadiness==='ready'?'':'disabled'}>${j.phaseReadiness==='ready'?'Complete Phase & Activate Next Task Pack':'Complete Required Work First'}</button>`:'<span class="badge b-green">Workflow completed</span>'}</div></div></section>
<section id="detailPanel2" class="detail-panel"><div class="section-head"><h3>Job Tasks</h3><button class="primary" data-add-task="${j.id}">＋ Add Task</button></div>${jt.map(t=>`<div class="attention-item" data-task="${t.id}"><span class="signal ${t.attention?'red':'amber'}"></span><div><div class="item-title">${t.title}</div><div class="item-meta">${t.assignee} · ${formatDate(t.due)} · ${t.progress}% · ${t.phase}</div></div>${statusBadge(t.status)}</div>`).join('')||'<div class="empty-state">No tasks have been generated for this Job yet.</div>'}</section>
<section id="detailPanel3" class="detail-panel">${versionTimeline('Quotation',[['QT-'+j.id.slice(-5)+'-V1','Submitted','Jul 10, 2026','$12,900'],['QT-'+j.id.slice(-5)+'-V2','Revised','Jul 13, 2026','$12,400'],['QT-'+j.id.slice(-5)+'-V3',j.phase>3?'Confirmed':'Under negotiation','Jul 16, 2026','$12,400']])}</section>
<section id="detailPanel4" class="detail-panel">${versionTimeline('Artwork',[['Artwork V1','Submitted','Jul 17, 2026','Client feedback'],['Artwork V2','Revision','Jul 19, 2026','Logo enlarged'],['Artwork V3',j.phase>5?'Approved':'Waiting approval','Jul 21, 2026','Final file']])}</section>
<section id="detailPanel5" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Production Unit</span><b>Guangzhou Factory 2</b></div><div class="summary-box"><span>Quantity Ordered</span><b>${j.qty.toLocaleString()}</b></div><div class="summary-box"><span>Quantity Produced</span><b>${Math.round(j.qty*j.progress/100).toLocaleString()}</b></div></div><div style="margin-top:18px"><div class="section-head"><h3>Production Milestones</h3><b>${j.phase>=7?j.progress:0}%</b></div>${['Materials Ready','Production Started','25% Completed','50% Completed','75% Completed','Quality Inspection','Packing Completed'].map((x,i)=>`<div class="check-row"><input type="checkbox" ${j.progress>=(i+1)*12?'checked':''}> <span>${x}</span></div>`).join('')}</div></section>
<section id="detailPanel6" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Method</span><b>Air Freight</b></div><div class="summary-box"><span>Carrier</span><b>DHL Global Forwarding</b></div><div class="summary-box"><span>Tracking</span><b>${j.phase>=8?'DHL-86'+j.id.slice(-5):'Not assigned'}</b></div><div class="summary-box"><span>Planned Ship Date</span><b>${formatDate(new Date(Date.parse(j.delivery)-5*86400000).toISOString().slice(0,10))}</b></div><div class="summary-box"><span>Cartons</span><b>${Math.ceil(j.qty/200)}</b></div><div class="summary-box"><span>Status</span><b>${j.phase>=8?j.status:'Not Started'}</b></div></div></section>
<section id="detailPanel7" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Invoice No.</span><b>${j.phase>=9?'INV-'+j.id.slice(-5):'Not created'}</b></div><div class="summary-box"><span>Invoice Amount</span><b>${j.price?currency(j.price):'—'}</b></div><div class="summary-box"><span>Outstanding</span><b>${j.phase===9?currency(Math.round(j.price*.4)):'—'}</b></div></div><div class="section-head" style="margin-top:20px"><h3>Payment History</h3><button class="primary" data-payment="${j.id}">＋ Record Payment</button></div><table class="mini-table"><thead><tr><th>Date</th><th>Reference</th><th>Amount</th><th>Status</th></tr></thead><tbody><tr><td>Jul 20, 2026</td><td>TT Advance</td><td>${currency(Math.round(j.price*.6))}</td><td><span class="badge b-green">Received</span></td></tr></tbody></table></section>
<section id="detailPanel8" class="detail-panel"><div class="section-head"><h3>Documents</h3><button class="primary" data-upload-job="${j.id}">＋ Upload</button></div><div class="doc-grid" style="grid-template-columns:repeat(2,1fr)">${docCards(jd.slice(0,8))}</div></section>
<section id="detailPanel9" class="detail-panel">${activity.concat(notifications.filter(n=>n.job===j.id)).concat(activity.length?[]:[{title:'Job created',text:'Request received and Job record created.',time:'Jul 8, 2026'}]).map((n,i)=>`<div class="activity"><span class="avatar">${userInitials(users[(i+1)%users.length].name)}</span><div><div class="activity-text"><b>${n.title}</b><br>${n.text}</div><div class="activity-time">${n.time}</div></div></div>`).join('')}</section>`;
}
function versionTimeline(title,items){return `<div class="section-head"><h3>${title} Version History</h3><button class="primary">＋ New Version</button></div>${items.map((x,i)=>`<div class="attention-item"><span class="signal ${i===items.length-1?'amber':'purple'}"></span><div><div class="item-title">${x[0]}</div><div class="item-meta">${x[2]} · ${x[3]}</div></div>${statusBadge(x[1])}</div>`).join('')}`}
function openTask(id){const t=tasks.find(x=>x.id===id),j=jobs.find(x=>x.id===t.job);$('#detailDrawer').innerHTML=`<div class="drawer-head"><div><div class="small muted">${t.id} · ${t.job}</div><h2>${t.title}</h2><div>${statusBadge(t.status)} <span class="badge ${t.priority==='Critical'?'b-red':t.priority==='High'?'b-amber':'b-gray'}">${t.priority}</span></div></div><button class="close-btn" data-close>×</button></div><div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Job</span><b>${j.title}</b></div><div class="summary-box"><span>Assignee</span><b>${t.assignee}</b></div><div class="summary-box"><span>Due Date</span><b class="${dueClass(t.due)}">${formatDate(t.due)}</b></div></div><div class="form-grid" style="margin-top:18px"><div class="field"><label>Status</label><select id="taskUpdateStatus">${['Not Started','Ready','In Progress','Waiting for Client','Waiting for Supplier','Waiting for Internal Review','Revision Required','Blocked','On Hold','Completed'].map(s=>`<option ${s===t.status?'selected':''}>${s}</option>`).join('')}</select></div><div class="field"><label>Assignee</label><select id="taskUpdateAssignee">${users.map(u=>`<option ${u.name===t.assignee?'selected':''}>${u.name}</option>`).join('')}</select></div><div class="field"><label>Due Date</label><input type="date" id="taskUpdateDueDate" value="${t.due.split('T')[0]}"></div><div class="field full"><label>Progress: <b id="progressValue">${t.progress}%</b></label><input id="taskProgress" type="range" min="0" max="100" value="${t.progress}"></div></div><div class="section-head"><h3>Checklist</h3><span class="small muted">${t.checklist.filter(x=>x.done).length}/${t.checklist.length} complete</span></div>${t.checklist.map((c,i)=>`<label class="check-row"><input class="task-check" data-check="${i}" type="checkbox" ${c.done?'checked':''}> <span>${c.text}</span></label>`).join('')}<div class="field" style="margin-top:18px"><label>Update comment</label><textarea id="taskComment" rows="3" placeholder="Add progress, decision, blocker or next action…"></textarea></div><label class="check-row"><input id="taskAttention" type="checkbox" ${t.attention?'checked':''}> <span>Mark as attention needed</span></label><div class="section-head" style="margin-top:18px"><h3>Activity & Comments</h3></div>${t.comments.map(c=>`<div class="comment"><span class="avatar">${userInitials(c.user)}</span><div><b>${c.user}</b><div style="margin-top:4px">${c.text}</div><div class="small muted" style="margin-top:4px">${c.time}</div></div></div>`).join('')}<button class="primary" id="saveTaskUpdate" style="width:100%;margin-top:18px">Save</button></div>`;openOverlay();$('#detailDrawer').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));$('#taskProgress').addEventListener('input',e=>$('#progressValue').textContent=e.target.value+'%');$('#saveTaskUpdate').addEventListener('click',()=>{const status=$('#taskUpdateStatus').value,comment=$('#taskComment').value.trim();if((status==='Blocked'||$('#taskAttention').checked)&&!comment){showToast('Please add a short reason for the blocker or attention flag.');return}t.status=status;t.assignee=$('#taskUpdateAssignee').value;t.due=$('#taskUpdateDueDate').value;t.progress=Number($('#taskProgress').value);if(status==='Completed')t.progress=100;t.attention=$('#taskAttention').checked;$$('.task-check').forEach(c=>t.checklist[Number(c.dataset.check)].done=c.checked);if(comment)t.comments.unshift({user:'Li Wei',text:comment,time:'Just now'});closeAll();renderPage(currentPage);showToast('Task update saved.')})}
function openClient(id){const c=clients.find(x=>x.id===Number(id)),cj=jobs.filter(j=>j.client===c.id);$('#detailDrawer').innerHTML=`<div class="drawer-head"><div class="client-head"><div class="company-logo">${c.code}</div><div><div class="small muted">Client</div><h2>${c.name}</h2><div class="muted">${c.country}</div></div></div><button class="close-btn" data-close>×</button></div><div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Primary Contact</span><b>${c.contact}</b></div><div class="summary-box"><span>Account Manager</span><b>${c.manager}</b></div><div class="summary-box"><span>Outstanding</span><b>${currency(c.outstanding)}</b></div></div><div class="section-head" style="margin-top:20px"><h3>Contact Information</h3></div><div class="card" style="padding:14px"><b>${c.contact}</b><div class="muted" style="margin-top:4px">${c.email}<br>Preferred language: English</div></div><div class="section-head" style="margin-top:20px"><h3>Active Jobs</h3><button class="primary" data-new-client-job="${c.id}">＋ New Job</button></div>${cj.map(j=>`<div class="attention-item" data-job="${j.id}"><span class="signal ${j.attention?'red':'amber'}"></span><div><div class="item-title">${j.title}</div><div class="item-meta">${j.id} · ${phaseShort[j.phase]} · ${j.progress}%</div></div>${healthBadge(j.health,j.healthReason)}</div>`).join('')}</div>`;openOverlay();$('#detailDrawer').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));bindCommon()}
function openDoc(id){const d=docs.find(x=>x.id===id);$('#detailDrawer').innerHTML=`<div class="drawer-head"><div><div class="small muted">${d.type} · ${d.version}</div><h2>${d.name}</h2><div>${d.final?'<span class="badge b-green">Final Document</span>':'<span class="badge b-gray">Working Version</span>'}</div></div><button class="close-btn" data-close>×</button></div><div class="drawer-body"><div class="card" style="height:260px;display:grid;place-items:center;background:#f5f7fa"><div style="text-align:center"><div class="doc-icon" style="margin:auto auto 10px">PDF</div><b>Document preview</b><div class="muted small">Static placeholder in this HTML prototype</div></div></div><div class="summary-grid" style="margin-top:16px"><div class="summary-box"><span>Related Job</span><b>${d.job}</b></div><div class="summary-box"><span>Client</span><b>${clientName(d.client)}</b></div><div class="summary-box"><span>Uploaded</span><b>${formatDate(d.date)}</b></div></div><div class="section-head" style="margin-top:20px"><h3>Version History</h3><button class="primary" id="downloadDoc">Download</button></div>${[3,2,1].map(v=>`<div class="attention-item"><span class="signal purple"></span><div><div class="item-title">${d.name.replace(/v\d/,'v'+v)}</div><div class="item-meta">Version v${v} · ${d.uploadedBy}</div></div>${v===3?'<span class="badge b-green">Current</span>':'<button class="mini-btn">View</button>'}</div>`).join('')}</div>`;openOverlay();$('#detailDrawer').classList.add('open');$$('[data-close]').forEach(b=>b.addEventListener('click',closeAll));$('#downloadDoc').addEventListener('click',()=>showToast('Sample document download simulated.'))}
function bindCommon(){
 const newClientJob=$('[data-new-client-job]');if(newClientJob)newClientJob.addEventListener('click',()=>{const id=newClientJob.dataset.newClientJob;closeAll();setTimeout(()=>openJobModal(id),50)});
 $$('[data-go]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.go)));$$('[data-job]').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();openJob(el.dataset.job)}));$$('[data-task]').forEach(el=>el.addEventListener('click',e=>{if(el.classList.contains('dragging'))return;e.stopPropagation();openTask(el.dataset.task)}));$$('[data-client]').forEach(el=>el.addEventListener('click',()=>openClient(el.dataset.client)));$$('[data-doc]').forEach(el=>el.addEventListener('click',()=>openDoc(el.dataset.doc)));$$('[data-metric]').forEach(m=>m.addEventListener('click',()=>{const f=m.dataset.metric;navigate(f==='done'?'reports':f==='payment'?'jobs':f==='shipment'?'jobs':f==='blocked'?'mywork':f==='overdue'?'mywork':f==='risk'?'jobs':'jobs');if(f==='risk'){jobFilter.health='At Risk';renderJobs()}if(f==='shipment'){jobFilter.phase='8';renderJobs()}if(f==='payment'){jobFilter.phase='9';renderJobs()}}));
 const next=$('[data-next-phase]');if(next)next.addEventListener('click',()=>{const j=jobs.find(x=>x.id===next.dataset.nextPhase),wf=getWorkflow(j.workflow||'standard');if(j.phase<wf.phases.length-1){j.phase++;j.progress=Math.max(j.progress,Math.round(j.phase/Math.max(1,wf.phases.length-1)*100));j.status=j.phase===wf.phases.length-1?'Completed':'In Progress';j.health=j.status==='Completed'?'Completed':'On Track';j.next=nextActionForPhase(j.workflow||'standard',j.phase);j.milestone=milestoneForPhase(j.workflow||'standard',j.phase);const created=activateTaskPackForJob(j,j.phase,'Previous phase completion');j.activity=j.activity||[];j.activity.unshift({title:'Phase activated',text:`${phaseNameFor(j)} started and ${created} tasks were generated from its Task Pack.`,time:'Just now'});showToast(`${phaseNameFor(j)} activated. ${created} tasks created.`);openJob(j.id)}});const viewTasks=$('[data-open-job-tasks]');if(viewTasks)viewTasks.addEventListener('click',()=>{$('[data-detailtab="2"]').click()});
}
function updateBadges(){const unread=notifications.filter(n=>!n.read).length;$('#notificationBadge').textContent=unread;$('#myWorkBadge').textContent=tasks.filter(t=>t.status!=='Completed'&&['Li Wei','Chen Mei'].includes(t.assignee)).length}
function formatDate(d){if(!d)return'—';return new Date(d+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}
function applyLanguage(){lang=lang==='en'?'zh':'en';$$('[data-i18n]').forEach(el=>el.textContent=translations[lang][el.dataset.i18n]||el.textContent);$('#languageBtn').textContent=lang==='en'?'中文':'EN';showToast(lang==='zh'?'界面已切换为简体中文。':'Interface changed to English.')}
$$('.nav-btn,.mobile-bottom button').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.page)));$$('[data-page-jump]').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.pageJump)));$('#quickCreateBtn')?.addEventListener('click',openJobModal);$('#languageBtn').addEventListener('click',applyLanguage);$('#overlay').addEventListener('click',closeAll);$('#mobileMenu').addEventListener('click',()=>{$('#sidebar').classList.add('open');$('#sidebarShade').classList.add('open')});$('#sidebarShade').addEventListener('click',()=>{$('#sidebar').classList.remove('open');$('#sidebarShade').classList.remove('open')});$('#globalSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){jobFilter.search=e.target.value;navigate('jobs')}});

function flowtrackToArray(value) {
        if (Array.isArray(value)) return value;
        if (value == null) return [];

        // Laravel collections/paginators may occasionally be encoded as keyed
        // objects after cache restoration. Normalize them without assuming that
        // Symbol.iterator exists or is callable.
        if (typeof value === 'object' && Array.isArray(value.data)) return value.data;

        const length = typeof value === 'object' && Number.isFinite(Number(value.length))
            ? Math.max(0, Number(value.length))
            : null;
        if (length !== null) {
            const items = [];
            for (let index = 0; index < length; index += 1) {
                if (index in value) items.push(value[index]);
            }
            return items;
        }

        try {
            const iterator = typeof Symbol !== 'undefined' ? value?.[Symbol.iterator] : null;
            if (typeof iterator === 'function') {
                const items = [];
                for (const item of value) items.push(item);
                return items;
            }
        } catch (_) {
            // Fall through to keyed-object normalization.
        }

        if (typeof value === 'object') return Object.values(value);
        return [];
    }

    function flowtrackNormalizeWorkflow(workflow) {
        const source = workflow && typeof workflow === 'object' ? workflow : {};
        return {
            ...source,
            phases: flowtrackToArray(source.phases).filter((phase) => phase && typeof phase === 'object'),
        };
    }

    function flowtrackNormalizeWorkflows(value) {
        return flowtrackToArray(value)
            .filter((workflow) => workflow && typeof workflow === 'object')
            .map(flowtrackNormalizeWorkflow);
    }


    const flowtrackNativeInnerHtml = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
    const flowtrackUnsafeTags = 'script,iframe,object,embed,base,meta[http-equiv],link[rel="import"]';
    const flowtrackUrlAttributes = new Set(['href', 'src', 'xlink:href', 'action', 'formaction', 'poster']);

    function flowtrackSafeUrl(value) {
        const candidate = String(value ?? '').trim();
        if (!candidate || candidate.startsWith('#') || candidate.startsWith('/') || candidate.startsWith('./') || candidate.startsWith('../') || candidate.startsWith('?')) return true;
        if (/^data:image\/(?:png|gif|jpe?g|webp|bmp);/i.test(candidate)) return true;
        try {
            const parsed = new URL(candidate, window.location.origin);
            return ['http:', 'https:', 'mailto:', 'tel:', 'blob:'].includes(parsed.protocol);
        } catch (_) {
            return false;
        }
    }

    function sanitizeFlowtrackHtml(value) {
        if (!flowtrackNativeInnerHtml?.set || !flowtrackNativeInnerHtml?.get) return String(value ?? '');
        const template = document.createElement('template');
        flowtrackNativeInnerHtml.set.call(template, String(value ?? ''));
        template.content.querySelectorAll(flowtrackUnsafeTags).forEach((element) => element.remove());
        template.content.querySelectorAll('*').forEach((element) => {
            flowtrackToArray(element.attributes).forEach((attribute) => {
                const name = attribute.name.toLowerCase();
                const attributeValue = attribute.value;
                if (name.startsWith('on') || name === 'srcdoc') {
                    element.removeAttribute(attribute.name);
                    return;
                }
                if (name === 'style' && /(?:expression\s*\(|javascript\s*:|vbscript\s*:|url\s*\()/i.test(attributeValue)) {
                    element.removeAttribute(attribute.name);
                    return;
                }
                if (flowtrackUrlAttributes.has(name) && !flowtrackSafeUrl(attributeValue)) {
                    element.removeAttribute(attribute.name);
                }
            });
            if (element instanceof HTMLAnchorElement && element.target === '_blank') {
                element.rel = 'noopener noreferrer';
            }
        });
        return flowtrackNativeInnerHtml.get.call(template);
    }

    if (flowtrackNativeInnerHtml?.configurable && !window.__FLOWTRACK_SAFE_HTML_INSTALLED__) {
        Object.defineProperty(Element.prototype, 'innerHTML', {
            configurable: true,
            enumerable: flowtrackNativeInnerHtml.enumerable,
            get() {
                return flowtrackNativeInnerHtml.get.call(this);
            },
            set(value) {
                flowtrackNativeInnerHtml.set.call(this, sanitizeFlowtrackHtml(value));
            },
        });
        window.__FLOWTRACK_SAFE_HTML_INSTALLED__ = true;
    }
    window.FlowtrackSafeHtml = Object.freeze({
        sanitize: sanitizeFlowtrackHtml,
        isSafeUrl: flowtrackSafeUrl,
    });

    function flowtrackInstallClickableDateFields() {
        if (window.__FLOWTRACK_CLICKABLE_DATE_FIELDS__) return;
        window.__FLOWTRACK_CLICKABLE_DATE_FIELDS__ = true;

        
    /* CSS externalized: style. */

        const dateInputFromTarget = (target) => {
            const element = target instanceof Element ? target : null;
            if (!element) return null;

            const direct = element.closest('input[type="date"]');
            if (direct instanceof HTMLInputElement) return direct;

            const label = element.closest('label');
            if (label?.htmlFor) {
                const labelled = document.getElementById(label.htmlFor);
                if (labelled instanceof HTMLInputElement && labelled.type === 'date') return labelled;
            }

            const field = element.closest('.field, .form-group, .input-group, .date-field');
            const input = field?.querySelector?.('input[type="date"]');
            return input instanceof HTMLInputElement ? input : null;
        };

        const openDatePicker = (input) => {
            if (!(input instanceof HTMLInputElement) || input.type !== 'date' || input.disabled || input.readOnly) return;

            try {
                input.focus({ preventScroll: true });
            } catch (_) {
                input.focus();
            }

            if (typeof input.showPicker === 'function') {
                try {
                    input.showPicker();
                } catch (_) {
                    // Browsers can reject showPicker() when there is no active
                    // user gesture. The focused native input still remains usable.
                }
            }
        };

        document.addEventListener('click', (event) => {
            const input = dateInputFromTarget(event.target);
            if (!input) return;

            const element = event.target instanceof Element ? event.target : null;
            if (element && element !== input && element.closest('button,a,select,textarea,[role="button"],[data-close]')) return;

            openDatePicker(input);
        }, true);

        document.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key)) return;
            const input = dateInputFromTarget(event.target);
            if (!input) return;
            event.preventDefault();
            openDatePicker(input);
        }, true);
    }

    flowtrackInstallClickableDateFields();

    function flowtrackIsWorkflowPhaseDocumentTask(task) {
        if (!task || typeof task !== 'object') return false;
        const requirement = task.documentRequirement || task.document_requirement || {};
        const source = String(requirement.source || task.documentRequirementSource || task.document_requirement_source || '').toLowerCase();
        if (source === 'workflow_phase') return true;

        const hasSetupItem = Boolean(task.taskPackItemId || task.task_pack_item_id || task.taskPackItemTitle);
        const hasDocumentRequirement = Boolean(requirement.categoryId || requirement.category_id || task.documentCategoryId || task.document_category_id);
        return !hasSetupItem && hasDocumentRequirement && /^upload\s+/i.test(String(task.title || ''));
    }

    function flowtrackVisibleTaskRows(sourceTasks) {
        return flowtrackToArray(sourceTasks).filter((task) => !flowtrackIsWorkflowPhaseDocumentTask(task));
    }

    function flowtrackNavigateTo(url) {
        const target = String(url ?? '').trim();
        if (!flowtrackSafeUrl(target)) {
            showToast('The requested link was blocked because it is not a safe URL.');
            return false;
        }
        window.location.assign(target);
        return true;
    }

    const flowtrackExtensionRegistry = new Map();
    const flowtrackBaseImplementations = Object.freeze({ openJob, openTask, renderJobs, renderKanban });
    const flowtrackActiveImplementations = { ...flowtrackBaseImplementations };

    function currentFlowtrackImplementation(name) {
        const implementation = flowtrackActiveImplementations[name];
        if (typeof implementation !== 'function') throw new Error(`Missing FlowTrack implementation: ${name}`);
        return implementation;
    }

    function registerFlowtrackExtension(name, stage, implementation) {
        if (typeof implementation !== 'function') throw new TypeError(`FlowTrack extension ${name}:${stage} must be a function.`);
        const stages = flowtrackExtensionRegistry.get(name) || [];
        if (stages.some((entry) => entry.stage === stage)) throw new Error(`Duplicate FlowTrack extension stage: ${name}:${stage}`);
        stages.push({ stage, implementation });
        flowtrackExtensionRegistry.set(name, stages);
        flowtrackActiveImplementations[name] = implementation;
        return implementation;
    }

    const flowtrackDispatchers = Object.freeze({
        openJob: (...args) => currentFlowtrackImplementation('openJob')(...args),
        openTask: (...args) => currentFlowtrackImplementation('openTask')(...args),
        renderJobs: (...args) => currentFlowtrackImplementation('renderJobs')(...args),
        renderKanban: (...args) => currentFlowtrackImplementation('renderKanban')(...args),
    });

    // Install one stable public function per feature. Extension registration only
    // changes the private implementation target, so core globals are never
    // repeatedly overwritten while preserving the exact staged behavior.
    openJob = flowtrackDispatchers.openJob;
    openTask = flowtrackDispatchers.openTask;
    renderJobs = flowtrackDispatchers.renderJobs;
    renderKanban = flowtrackDispatchers.renderKanban;

    window.__FLOWTRACK_EXTENSION_REGISTRY__ = flowtrackExtensionRegistry;
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let currentUser = null;
    let flowtrackBranding = window.FLOWTRACK_BRANDING && typeof window.FLOWTRACK_BRANDING === 'object'
        ? { ...window.FLOWTRACK_BRANDING }
        : {};
    let taskPacks = [];
    let masterCatalog = [];
    let accessRoles = [];
    let accessMembers = [];
    let accessCatalog = { modules: [], actions: [], scopes: [], sensitiveFields: [] };
    let selectedAccessRoleId = null;
    let canViewAccess = false;
    let canManageAccess = false;
    let canCreateUsers = false;
    let canDeleteUsers = false;
    let isFlowAdmin = false;
    let isFlowSuperAdmin = false;
    let capabilities = {};
    let currentSensitiveFields = [];
    let boardWorkflowId = null;
    let boardJobIds = new Set();
    let boardAssigneeUsers = [];
    let boardAccess = { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
    let pendingDocumentUploadJobId = null;
    let summaryMetrics = {};
    let dashboardActivities = [];
    let notificationRules = [];
    let notificationAccess = { authorityView: false, statusEndpoint: '/flowtrack/notifications/status' };
    let realtimePusher = null;
    let realtimeChannel = null;
    let realtimeUserId = null;
    let realtimeRefreshTimer = null;
    let sessionExpiryTimer = null;
    let sessionStatusTimer = null;
    let notificationStatusTimer = null;
    let sessionActivityPending = false;
    let sessionActivityBound = false;
    let sessionVisibilityBound = false;
    let sessionLastTouchSyncAt = 0;
    let sessionCheckNow = null;
    let notificationSignature;
    let notificationUnread = 0;
    let workspaceDataVersion = null;
    let realtimeWorkspaceRefreshTimer = null;
    let realtimeWorkspaceRefreshInFlight = false;
    let bootstrapGeneration = 0;
    let bootstrapAppliedGeneration = 0;


    function applyFlowtrackBranding(branding = flowtrackBranding) {
        flowtrackBranding = branding && typeof branding === 'object' ? { ...branding } : {};
        window.FLOWTRACK_BRANDING = { ...flowtrackBranding };

        const logoUrl = String(flowtrackBranding.logoUrl || '').trim();
        document.querySelectorAll('[data-flowtrack-brand-mark]').forEach((mark) => {
            mark.replaceChildren();
            if (logoUrl && flowtrackSafeUrl(logoUrl)) {
                const image = document.createElement('img');
                image.className = 'flowtrack-brand-image';
                image.src = logoUrl;
                image.alt = 'FlowTrack logo';
                mark.appendChild(image);
                mark.classList.add('has-brand-image');
            } else {
                mark.textContent = 'FT';
                mark.classList.remove('has-brand-image');
            }
        });

        const favicon = document.querySelector('#flowtrackFavicon');
        const faviconUrl = String(flowtrackBranding.faviconUrl || '/favicon.ico').trim();
        if (favicon && flowtrackSafeUrl(faviconUrl)) favicon.setAttribute('href', faviconUrl);
    }

    applyFlowtrackBranding(flowtrackBranding);
    const segmentPagination = { clients: null, jobs: null, tasks: null, documents: null };
    const segmentLoading = new Set();
    const segmentLoadState = { clients: 'none', jobs: 'none', tasks: 'none', documents: 'none' };
    const pageDataLoading = new Map();
    const pageContextLoading = new Map();
    const pageContextState = new Map();
    let pageContextBaseUrl = '/flowtrack/template-api/context';
    let bootstrapSegmentUrls = {};
    let bootstrapSegmentPerPage = 50;

    function invalidateBootstrapLoads() {
        bootstrapGeneration += 1;
    }

    function hasOwnBootstrapField(payload, field) {
        return Boolean(payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, field));
    }

    
    /* CSS externalized: taskFeedbackStyles. */

    
    /* CSS externalized: taskDocumentSelectionStyles. */


    function bindSessionActivityTracking() {
        if (sessionActivityBound) return;
        sessionActivityBound = true;
        const markActive = () => {
            sessionActivityPending = true;
            const now = Date.now();
            const expiresAt = window.FLOWTRACK_SESSION?.expiresAt ? new Date(window.FLOWTRACK_SESSION.expiresAt).getTime() : 0;
            if (typeof sessionCheckNow === 'function' && (now - sessionLastTouchSyncAt > 15000 || (expiresAt && expiresAt - now < 60000))) {
                sessionLastTouchSyncAt = now;
                sessionCheckNow();
            }
        };
        ['click', 'keydown', 'input', 'change', 'pointerdown', 'touchstart', 'scroll'].forEach((eventName) => {
            window.addEventListener(eventName, markActive, { passive: true, capture: true });
        });
    }

    function sessionRedirect(reason = 'expired') {
        if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
        if (sessionStatusTimer) clearInterval(sessionStatusTimer);
        if (notificationStatusTimer) clearInterval(notificationStatusTimer);
        const safeReason = reason === 'replaced' ? 'replaced' : 'expired';
        window.location.replace(`/login?session=${safeReason}`);
    }

    function scheduleSessionGuard(expiresAt = null) {
        const config = window.FLOWTRACK_SESSION || {};
        const resolvedExpiry = expiresAt || config.expiresAt;

        if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
        if (sessionStatusTimer) clearInterval(sessionStatusTimer);

        if (resolvedExpiry) {
            config.expiresAt = resolvedExpiry;
            window.FLOWTRACK_SESSION = config;
            const delay = Math.max(0, new Date(resolvedExpiry).getTime() - Date.now());
            sessionExpiryTimer = setTimeout(() => {
                window.location.replace(config.expiredEndpoint || '/flowtrack/session-expired');
            }, delay + 250);
        }

        if (!config.statusEndpoint) return;
        bindSessionActivityTracking();
        const checkStatus = async () => {
            const activityHeader = sessionActivityPending ? { 'X-FlowTrack-Activity': '1' } : {};
            const sentActivity = sessionActivityPending;
            try {
                const response = await fetch(config.statusEndpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...activityHeader },
                    cache: 'no-store',
                });
                if (response.status === 401) {
                    const payload = await response.json().catch(() => ({}));
                    sessionRedirect(payload.reason || 'expired');
                    return;
                }
                if (!response.ok) return;
                const payload = await response.json();
                if (sentActivity) sessionActivityPending = false;
                const refreshedExpiry = payload.expiresAt || payload.expires_at;
                if (refreshedExpiry && refreshedExpiry !== config.expiresAt) {
                    scheduleSessionGuard(refreshedExpiry);
                }
            } catch (_) {
                // A temporary network outage must not log the user out locally.
            }
        };
        sessionCheckNow = checkStatus;
        checkStatus();
        sessionStatusTimer = setInterval(checkStatus, 30000);
        if (!sessionVisibilityBound) {
            sessionVisibilityBound = true;
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && typeof sessionCheckNow === 'function') sessionCheckNow();
            });
        }
    }

    async function refreshNotificationFeed() {
        const payload = await api('/flowtrack/notifications/feed?limit=50', { cache: 'no-store' });
        replaceArray(notifications, payload?.notifications || []);
        notificationUnread = Number(payload?.unread_count || notifications.filter((item) => !item.read).length);
        notificationSignature = payload?.latest_signature ?? notificationSignature;
        notificationAccess = { ...notificationAccess, authorityView: Boolean(payload?.authority_view) };
        updateBadges();
        if (currentPage === 'notifications') renderNotifications();
        return payload;
    }

    function realtimeRefreshWouldInterruptEditing() {
        const route = typeof flowtrackInitialRoute === 'function' ? flowtrackInitialRoute() : null;
        if (route && ['job-new', 'job-edit', 'client-new', 'client-edit', 'workflow-new', 'workflow-edit', 'task-pack-new', 'task-pack-edit'].includes(route.type)) {
            return true;
        }

        const active = document.activeElement;
        if (!active) return false;
        const tag = String(active.tagName || '').toLowerCase();
        return active.isContentEditable || ['input', 'textarea', 'select'].includes(tag);
    }

    function refreshVisibleSurfaceAfterRealtime(route, previousPage) {
        updateBadges();

        if (route && ['task', 'job', 'client'].includes(route.type) && typeof flowtrackOpenInitialRoute === 'function') {
            flowtrackOpenInitialRoute(route);
            return;
        }

        const page = flowtrackNormalizePageName(previousPage || currentPage);
        if (page && canOpenPage(page) && document.getElementById(`page-${page}`)) {
            renderPage(page);
            applyActionPermissions();
            window.FlowtrackSearchableDropdown?.enhance?.(document.getElementById(`page-${page}`));
        }
    }

    async function refreshWorkspaceDataSilently() {
        if (realtimeWorkspaceRefreshInFlight || document.hidden) return;
        if (realtimeRefreshWouldInterruptEditing()) {
            scheduleWorkspaceDataRefresh(1500);
            return;
        }

        realtimeWorkspaceRefreshInFlight = true;
        const route = typeof flowtrackInitialRoute === 'function' ? flowtrackInitialRoute() : null;
        const previousPage = currentPage;
        try {
            const data = await loadBootstrap({ silent: true });
            if (!data) return;
            await flowtrackEnsurePageData(previousPage, { force: true });
            refreshVisibleSurfaceAfterRealtime(route, previousPage);
        } catch (error) {
            console.warn('FlowTrack workspace background refresh failed.', error);
        } finally {
            realtimeWorkspaceRefreshInFlight = false;
        }
    }

    function scheduleWorkspaceDataRefresh(delay = 450) {
        if (realtimeWorkspaceRefreshTimer) clearTimeout(realtimeWorkspaceRefreshTimer);
        realtimeWorkspaceRefreshTimer = setTimeout(() => void refreshWorkspaceDataSilently(), Math.max(100, Number(delay) || 450));
    }

    async function pollNotificationStatus() {
        if (document.hidden || !notificationAccess?.statusEndpoint) return;

        try {
            const payload = await api(notificationAccess.statusEndpoint, { cache: 'no-store' });
            const nextSignature = payload?.latest_signature ?? null;
            const nextUnread = Number(payload?.unread_count || 0);
            const nextWorkspaceVersion = Number(payload?.workspace_version || 0);

            if (nextWorkspaceVersion > 0) {
                if (workspaceDataVersion === null) {
                    workspaceDataVersion = nextWorkspaceVersion;
                } else if (nextWorkspaceVersion !== workspaceDataVersion) {
                    // Do not advance the local version until loadBootstrap()
                    // succeeds; failed refreshes will therefore retry safely.
                    scheduleWorkspaceDataRefresh();
                }
            }

            if (notificationSignature === undefined) {
                const unreadChanged = nextUnread !== notificationUnread;
                notificationSignature = nextSignature;
                notificationUnread = nextUnread;
                if (!unreadChanged) return;

                await refreshNotificationFeed();
                return;
            }

            if (nextSignature === notificationSignature && nextUnread === notificationUnread) return;

            notificationSignature = nextSignature;
            notificationUnread = nextUnread;
            await refreshNotificationFeed();
        } catch (_) {
            // This small AJAX heartbeat is the reliable fallback when Pusher is
            // absent, misconfigured, delayed, or its queue worker is unavailable.
        }
    }

    function startNotificationPolling() {
        if (notificationStatusTimer) clearInterval(notificationStatusTimer);
        const configured = Number(window.FLOWTRACK_PUSHER?.pollIntervalMs || 5000);
        const interval = Math.max(3000, Math.min(30000, Number.isFinite(configured) ? configured : 5000));
        void pollNotificationStatus();
        notificationStatusTimer = setInterval(() => void pollNotificationStatus(), interval);
    }

    function realtimeNotificationRecord(payload) {
        return {
            id: payload.id || `live-${Date.now()}`,
            title: payload.title || 'Notification',
            text: payload.text || '',
            time: payload.time || 'Just now',
            read: false,
            type: payload.type || 'info',
            job: payload.job || null,
            task: payload.task || null,
            url: payload.url || null,
            event: payload.event || null,
        };
    }

    function scheduleRealtimeRefresh() {
        if (realtimeRefreshTimer) clearTimeout(realtimeRefreshTimer);
        realtimeRefreshTimer = setTimeout(async () => {
            try {
                await refreshNotificationFeed();
            } catch (error) {
                console.warn('FlowTrack realtime notification refresh failed.', error);
            }
        }, 650);
    }

    function receiveRealtimeNotification(payload) {
        invalidateBootstrapLoads();
        const record = realtimeNotificationRecord(payload || {});
        if (!notifications.some((item) => String(item.id) === String(record.id))) {
            notifications.unshift(record);
        }
        updateBadges();
        showToast(`${record.title}${record.text ? `: ${record.text}` : ''}`);
        if (currentPage === 'notifications') renderNotifications();
        window.dispatchEvent(new CustomEvent('flowtrack:realtime-notification', { detail: payload }));
        scheduleRealtimeRefresh();
        scheduleWorkspaceDataRefresh(250);
    }

    function initializeRealtimeNotifications() {
        const config = window.FLOWTRACK_PUSHER || {};
        const userId = Number(currentUser?.id || config.userId || 0);
        if (!config.enabled || !config.key || !userId || typeof window.Pusher !== 'function') return;
        if (realtimePusher && realtimeUserId === userId) return;

        try {
            realtimeChannel?.unbind_all?.();
            realtimePusher?.disconnect?.();

            realtimePusher = new window.Pusher(config.key, {
                cluster: config.cluster || 'ap2',
                forceTLS: true,
                channelAuthorization: {
                    endpoint: config.authEndpoint || '/flowtrack/pusher/auth',
                    transport: 'ajax',
                    headers: {
                        'X-CSRF-TOKEN': config.csrfToken || csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            });
            realtimeUserId = userId;
            realtimeChannel = realtimePusher.subscribe(`private-flowtrack.user.${userId}`);
            realtimeChannel.bind('notification.created', receiveRealtimeNotification);
            realtimeChannel.bind('pusher:subscription_error', (status) => console.warn('FlowTrack Pusher subscription failed.', status));
        } catch (error) {
            console.warn('FlowTrack realtime notifications could not start.', error);
        }
    }

    window.addEventListener(
        'flowtrack:pusher-ready',
        initializeRealtimeNotifications
    );

    const originalRenderClients = renderClients;
    const originalRenderDocuments = renderDocuments;
    const originalRenderSetupContent = renderSetupContent;
    const originalRenderSetup = renderSetup;
    const originalNavigate = navigate;
    const originalOpenJob = flowtrackBaseImplementations.openJob;
    const originalOpenDoc = openDoc;

    function replaceArray(target, values) {
        target.splice(0, target.length);
        flowtrackToArray(values).forEach((value) => target.push(value));
    }


    function flowtrackRecordMatches(left, right) {
        if (!left || !right) return false;
        const leftDbId = Number(left.dbId || left.id || 0);
        const rightDbId = Number(right.dbId || right.id || 0);
        if (leftDbId > 0 && rightDbId > 0 && leftDbId === rightDbId) return true;
        return String(left.id || '').trim() !== '' && String(left.id) === String(right.id || '');
    }

    function flowtrackUpsertRecord(target, record, prepend = true) {
        if (!record || typeof record !== 'object') return null;
        const index = target.findIndex((item) => flowtrackRecordMatches(item, record));
        if (index >= 0) {
            target.splice(index, 1, { ...target[index], ...record });
            return target[index];
        }
        if (prepend) target.unshift(record);
        else target.push(record);
        return record;
    }

    function flowtrackRemoveRecord(target, record) {
        const index = target.findIndex((item) => flowtrackRecordMatches(item, record));
        if (index >= 0) target.splice(index, 1);
    }

    function html(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }



    async function flowtrackCopyIdentifier(value, label = 'ID') {
        const text = String(value || '').trim();
        if (!text) return showToast(`${label} could not be copied.`);
        let copied = false;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                copied = true;
            }
        } catch (_) {}
        if (!copied) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
            textarea.remove();
        }
        showToast(copied ? `${text} copied.` : `Copy failed. Select the ${label} and copy it manually.`);
        return copied;
    }

    function flowtrackCopyIdentifierButton(value, label = 'ID', extraClass = '') {
        const text = String(value || '').trim();
        if (!text) return '';
        return `<button type="button" class="flow-id-copy ${html(extraClass)}" data-flow-copy-id="${html(text)}" data-flow-copy-label="${html(label)}" title="Copy ${html(label)}" aria-label="Copy ${html(label)} ${html(text)}"><span>${html(text)}</span><span class="flow-id-copy-icon" aria-hidden="true">⧉</span></button>`;
    }

    function flowtrackBindCopyIdentifiers(root = document) {
        if (!root?.querySelectorAll) return;
        root.querySelectorAll('[data-flow-copy-id]').forEach((button) => {
            if (button.dataset.flowCopyBound === '1') return;
            button.dataset.flowCopyBound = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                void flowtrackCopyIdentifier(button.dataset.flowCopyId, button.dataset.flowCopyLabel || 'ID');
            });
        });
    }

    function flowtrackUserById(id) {
        const numericId = Number(id || 0);
        if (!numericId) return null;
        return flowtrackToArray(users).find((user) => Number(user?.id) === numericId) || null;
    }

    function flowtrackUserByName(name) {
        const target = String(name || '').trim().toLowerCase();
        if (!target || target === 'unassigned') return null;
        return flowtrackToArray(users).find((user) => String(user?.name || '').trim().toLowerCase() === target) || null;
    }

    function flowtrackResolveTaskAssigneeId(task) {
        if (!task || typeof task !== 'object') return null;
        if (Number(task.assigneeId || 0)) return Number(task.assigneeId);
        if (!task.assigneeOverridden && Number(task.setupAssigneeId || 0)) return Number(task.setupAssigneeId);
        const matched = flowtrackUserByName(task.assignee || task.setupAssignee || '');
        return matched ? Number(matched.id) : null;
    }

    function flowtrackResolveTaskAssigneeName(task, resolvedId = null) {
        const user = flowtrackUserById(resolvedId || flowtrackResolveTaskAssigneeId(task));
        if (user?.name) return user.name;
        if (task?.assignee && String(task.assignee).trim() && String(task.assignee).trim().toLowerCase() !== 'unassigned') return task.assignee;
        if (task?.setupAssignee && String(task.setupAssignee).trim()) return task.setupAssignee;
        return 'Unassigned';
    }

    function flowtrackHydrateTaskAssignee(task) {
        if (!task || typeof task !== 'object') return task;
        const resolvedId = flowtrackResolveTaskAssigneeId(task);
        if (resolvedId && !Number(task.assigneeId || 0)) task.assigneeId = resolvedId;
        const resolvedName = flowtrackResolveTaskAssigneeName(task, resolvedId);
        if (resolvedName && resolvedName !== 'Unassigned') task.assignee = resolvedName;
        return task;
    }

    function flowtrackTaskAssigneeOptions(task, extraUsers = []) {
        const resolvedId = flowtrackResolveTaskAssigneeId(task);
        const resolvedName = flowtrackResolveTaskAssigneeName(task, resolvedId);
        const source = flowtrackToArray(users).concat(flowtrackToArray(extraUsers));

        if (resolvedId && !source.some((user) => Number(user?.id) === Number(resolvedId))) {
            source.push({ id: resolvedId, name: resolvedName, initials: typeof userInitials === 'function' ? userInitials(resolvedName) : '' });
        }

        if (!resolvedId && resolvedName && resolvedName !== 'Unassigned' && !source.some((user) => String(user?.name || '').trim().toLowerCase() === resolvedName.toLowerCase())) {
            source.push({ id: '', name: resolvedName, initials: typeof userInitials === 'function' ? userInitials(resolvedName) : '' });
        }

        return source
            .filter((user) => user && (Number(user.id || 0) || String(user.name || '').trim()))
            .filter((user, index, collection) => {
                if (Number(user.id || 0)) return collection.findIndex((candidate) => Number(candidate?.id) === Number(user.id)) === index;
                const name = String(user.name || '').trim().toLowerCase();
                return collection.findIndex((candidate) => String(candidate?.name || '').trim().toLowerCase() === name) === index;
            })
            .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    }

    function bindFlowtrackMentionInput(input, menu, mentionableUsers, selectedIds) {
        if (!input || !menu) return () => {};
        input.__flowtrackMentionAbortController?.abort();
        const controller = new AbortController();
        input.__flowtrackMentionAbortController = controller;
        const { signal } = controller;

        const close = () => menu.classList.add('hidden');
        const context = () => {
            const cursor = input.selectionStart ?? 0;
            const before = input.value.slice(0, cursor);
            const match = before.match(/(^|\s)@([^@\n]*)$/);
            return match
                ? { cursor, query: match[2].trim().toLowerCase(), start: cursor - match[0].length + match[1].length }
                : null;
        };
        const render = () => {
            const activeContext = context();
            if (!activeContext) return close();
            const matches = mentionableUsers
                .filter((user) => String(user.name || '').toLowerCase().includes(activeContext.query))
                .slice(0, 8);
            if (!matches.length) return close();
            menu.innerHTML = matches.map((user) => `<button type="button" class="mention-option" data-mention-user="${html(user.id)}"><span class="avatar">${html(user.initials || userInitials(user.name))}</span><span><b>${html(user.name)}</b></span></button>`).join('');
            menu.classList.remove('hidden');
        };

        input.addEventListener('input', render, { signal });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') close();
        }, { signal });
        menu.addEventListener('mousedown', (event) => {
            const button = event.target.closest('[data-mention-user]');
            if (!button) return;
            event.preventDefault();
            const user = mentionableUsers.find((item) => Number(item.id) === Number(button.dataset.mentionUser));
            const activeContext = context();
            if (!user || !activeContext) return;
            const insertion = `@${user.name} `;
            input.value = `${input.value.slice(0, activeContext.start)}${insertion}${input.value.slice(activeContext.cursor)}`;
            const nextCursor = activeContext.start + insertion.length;
            input.setSelectionRange(nextCursor, nextCursor);
            input.focus();
            selectedIds.add(Number(user.id));
            close();
        }, { signal });
        document.addEventListener('pointerdown', (event) => {
            if (!menu.contains(event.target) && event.target !== input) close();
        }, { signal, capture: true });

        return () => controller.abort();
    }

    function firstValidationMessage(payload, fallback = 'Something went wrong. Please try again.') {
        const errors = payload?.errors || {};
        const first = Object.values(errors).flat()[0];
        const message = String(first || payload?.message || fallback);

        // Never expose SQL, stack traces, server paths, or framework internals
        // in a user-facing toast. The exception remains available in Laravel's
        // logs while the interface gives a useful recovery action.
        if (/SQLSTATE|unknown column|stack trace|vendor\/laravel|\/app\/Http\//i.test(message)) {
            return 'FlowTrack needs a database update. Ask an administrator to run the pending migrations, then try again.';
        }

        return message;
    }

    const activeMutationRequests = new Map();

    function api(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const isReadRequest = ['GET', 'HEAD', 'OPTIONS'].includes(method);
        const requestKey = `${method}:${String(url)}`;

        // A double click or repeated event binding must not create the same
        // record twice. Every caller receives the authoritative in-flight result.
        if (!isReadRequest && activeMutationRequests.has(requestKey)) {
            return activeMutationRequests.get(requestKey);
        }

        const request = (async () => {
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
                ...(options.headers || {}),
            };

            let body = options.body;
            if (body && !(body instanceof FormData) && typeof body !== 'string') {
                headers['Content-Type'] = 'application/json';
                body = JSON.stringify(body);
            }

            const externalSignal = options.signal;
            const controller = new AbortController();
            const timeoutMs = Math.max(
                5000,
                Number(options.timeoutMs) || (body instanceof FormData ? 120000 : (isReadRequest ? 30000 : 60000)),
            );
            let timedOut = false;
            let retryCount = 0;
            const timeout = setTimeout(() => {
                timedOut = true;
                controller.abort();
            }, timeoutMs);
            const abortFromCaller = () => controller.abort();
            externalSignal?.addEventListener?.('abort', abortFromCaller, { once: true });

            const { timeoutMs: ignoredTimeout, signal: ignoredSignal, ...fetchOptions } = options;
            let response;
            try {
                while (true) {
                    try {
                        response = await fetch(url, {
                            credentials: 'same-origin',
                            ...fetchOptions,
                            headers,
                            body,
                            signal: controller.signal,
                        });
                    } catch (error) {
                        if (!isReadRequest || retryCount >= 1 || error?.name === 'AbortError') throw error;
                        retryCount += 1;
                        await new Promise((resolve) => setTimeout(resolve, 250));
                        continue;
                    }

                    if (isReadRequest && retryCount < 1 && [502, 503, 504].includes(response.status)) {
                        retryCount += 1;
                        await new Promise((resolve) => setTimeout(resolve, 250));
                        continue;
                    }
                    break;
                }
            } catch (error) {
                if (timedOut) {
                    const timeoutError = new Error('The request took too long. Your form is still open; check your connection and try again.');
                    timeoutError.status = 0;
                    throw timeoutError;
                }
                if (error?.name === 'AbortError') throw error;
                const networkError = new Error('FlowTrack could not reach the server. Your changes were not cleared; reconnect and try again.');
                networkError.status = 0;
                throw networkError;
            } finally {
                clearTimeout(timeout);
                externalSignal?.removeEventListener?.('abort', abortFromCaller);
            }

            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json') ? await response.json() : null;

            if (!response.ok) {
                if (response.status === 401 && payload?.reason) {
                    setTimeout(() => sessionRedirect(payload.reason), 0);
                }
                const fallback = response.status === 413
                    ? 'This document is larger than the server upload limit. Use a file up to 20 MB or ask the administrator to apply the supplied PHP/Nginx upload configuration.'
                    : response.status >= 500
                        ? 'FlowTrack could not complete this action. Nothing was cleared; please try again.'
                        : `Request failed (${response.status}).`;
                const error = new Error(firstValidationMessage(payload, fallback));
                error.payload = payload;
                error.status = response.status;
                throw error;
            }

            if (!isReadRequest) {
                // A mutation makes every bootstrap request that started before
                // this response obsolete, preventing a stale UI overwrite.
                invalidateBootstrapLoads();
            }

            return payload;
        })();

        if (!isReadRequest) {
            activeMutationRequests.set(requestKey, request);
            request.finally(() => activeMutationRequests.delete(requestKey)).catch(() => {});
        }

        return request;
    }

    window.renderNotifications = function renderPersistentNotifications() {
        const page = document.querySelector('#page-notifications');
        if (!page) return;

        const rows = notifications.map((notification) => {
            const notificationText = normalizedStatusValue(`${notification.type || ''} ${notification.severity || ''} ${notification.event || ''} ${notification.title || ''} ${notification.text || ''}`);
            const signal = /ATTENTION|FLAG|BLOCK|RISK|OVERDUE|DELAY/.test(notificationText) || notification.severity === 'warning'
                ? 'red'
                : notification.severity === 'success'
                    ? 'green'
                    : 'purple';

            return `<div class="attention-item" data-notification-id="${html(notification.id)}" style="${notification.read ? 'opacity:.62' : ''}">
                <span class="signal ${signal}"></span>
                <div>
                    <div class="item-title">${html(notification.title)} ${notification.read ? '' : '<span class="badge b-blue">New</span>'}</div>
                    <div class="item-meta">${html(notification.text)}${notification.phase ? ` · ${html(notification.phase)}` : ''} · ${html(notification.time)}</div>
                    ${notificationAccess.authorityView && Array.isArray(notification.audience) && notification.audience.length
                        ? `<div class="item-meta" style="margin-top:4px">For: ${html(flowtrackToArray(new Set(flowtrackToArray(notification.audience).map((recipient) => recipient?.name).filter(Boolean))).join(', '))}</div>`
                        : ''}
                </div>
                <button class="mini-btn" type="button">Open</button>
            </div>`;
        }).join('');

        page.innerHTML = `<div class="page-head"><div><h1>Notifications</h1><p>${notificationAccess.authorityView ? 'Workspace-wide' : 'Your'} assignment, Task, Workflow, document and health updates</p></div><div class="actions"><button class="ghost" id="markAllRead" type="button" ${notifications.some((item) => !item.read) ? '' : 'disabled'}>Mark all read</button></div></div>
            <div class="card section-card"><div class="attention-list">${rows || '<div class="empty">You are all caught up.</div>'}</div></div>`;

        document.querySelector('#markAllRead')?.addEventListener('click', async () => {
            try {
                await api('/flowtrack/notifications/read-all', { method: 'PATCH' });
                notifications.forEach((item) => { item.read = true; });
                window.renderNotifications();
                updateBadges();
                showToast('All notifications marked as read.');
            } catch (error) {
                showToast(error.message || 'Unable to mark notifications as read.');
            }
        });

        page.querySelectorAll('[data-notification-id]').forEach((row) => {
            row.addEventListener('click', async () => {
                const notification = notifications.find((item) => String(item.id) === String(row.dataset.notificationId));
                if (!notification) return;

                try {
                    if (!notification.read) {
                        await api(`/flowtrack/notifications/${encodeURIComponent(notification.id)}/read`, { method: 'PATCH' });
                        notification.read = true;
                        updateBadges();
                    }

                    const notificationTask = notification.task_id
                        ? tasks.find((task) => Number(task.dbId) === Number(notification.task_id))
                        : null;
                    if (notificationTask) {
                        openTask(notificationTask.id);
                    } else if (notification.job_id) {
                        const notificationJob = jobs.find((job) => Number(job.dbId) === Number(notification.job_id) || String(job.id) === String(notification.job_id));
                        if (notificationJob) openJob(notificationJob.id);
                        else if (notification.url) flowtrackNavigateTo(notification.url);
                    } else if (notification.url) {
                        flowtrackNavigateTo(notification.url);
                    }
                } catch (error) {
                    showToast(error.message || 'Unable to open the notification.');
                }
            });
        });
    };

    function canFlow(module, action = 'view') {
        if (isFlowAdmin || isFlowSuperAdmin) return true;
        const actions = capabilities?.[module]?.actions || [];
        if (actions.includes(action) || actions.includes('manage')) return true;
        if (action === 'edit_own' && actions.includes('edit_all')) return true;
        if (action === 'assign' && actions.includes('edit_all')) return true;
        return false;
    }

    function canSensitive(field) {
        return isFlowAdmin || isFlowSuperAdmin || currentSensitiveFields.includes(field);
    }

    function isAdministratorRole(role) {
        return ['SUPER_ADMIN', 'ADMIN', 'SYS_ADMIN', 'ADMINISTRATOR', 'SYSTEM_ADMINISTRATOR'].includes(String(role?.code || '').toUpperCase());
    }

    function availableRolesForActor(currentRoleId = null) {
        return accessRoles.filter((role) => {
            if (!role.active && Number(role.id) !== Number(currentRoleId)) return false;
            const code = String(role.code || '').toUpperCase();
            if (code === 'SUPER_ADMIN' && !isFlowSuperAdmin) return false;
            if (isAdministratorRole(role) && !isFlowAdmin && !isFlowSuperAdmin) return false;
            return true;
        });
    }

    function applyCurrentUserIdentity() {
        const initialsText = currentUser?.initials || 'FT';
        document.querySelectorAll('.sidebar-footer .avatar, [data-page-jump="profile"] .avatar, #profileAvatar').forEach((element) => {
            if (currentUser?.avatarUrl && flowtrackSafeUrl(currentUser.avatarUrl)) {
                element.classList.add('has-photo');
                element.style.backgroundImage = `url("${String(currentUser.avatarUrl).replaceAll('"', '%22')}")`;
                element.textContent = initialsText;
            } else {
                element.classList.remove('has-photo');
                element.style.removeProperty('background-image');
                element.textContent = initialsText;
            }
        });
        const footerName = document.querySelector('.sidebar-footer .user-mini > div:last-child > div:first-child');
        if (footerName) footerName.textContent = currentUser?.name || 'FlowTrack User';
        const footerRole = document.querySelector('.sidebar-footer .current-user-role');
        if (footerRole) footerRole.textContent = currentUser?.role || (isFlowSuperAdmin ? 'Super Administrator' : isFlowAdmin ? 'Administrator' : 'Workspace member');
    }

    function acceptCurrentUser(user) {
        currentUser = user || null;
        currentSensitiveFields = currentUser?.sensitiveFields || [];
        isFlowAdmin = Boolean(currentUser?.isAdmin || isFlowAdmin);
        isFlowSuperAdmin = Boolean(currentUser?.isSuperAdmin || isFlowSuperAdmin);
        applyCurrentUserIdentity();
    }

    function applyBootstrap(data) {
        let derivedStateChanged = false;

        if (hasOwnBootstrapField(data, 'currentUser')) acceptCurrentUser(data.currentUser);
        if (hasOwnBootstrapField(data, 'users')) replaceArray(users, data.users);
        if (hasOwnBootstrapField(data, 'boardAssigneeUsers')) {
            replaceArray(boardAssigneeUsers, data.boardAssigneeUsers);
        } else if (hasOwnBootstrapField(data, 'users') && !boardAssigneeUsers.length) {
            replaceArray(boardAssigneeUsers, data.users);
        }
        if (hasOwnBootstrapField(data, 'clients')) replaceArray(clients, data.clients);
        if (hasOwnBootstrapField(data, 'jobs')) replaceArray(jobs, data.jobs);
        if (hasOwnBootstrapField(data, 'boardJobIds')) {
            boardJobIds = new Set(flowtrackToArray(data.boardJobIds).map((id) => Number(id)));
        }
        if (hasOwnBootstrapField(data, 'boardAccess')) {
            boardAccess = data.boardAccess || { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
        }
        if (hasOwnBootstrapField(data, 'tasks')) {
            replaceArray(tasks, data.tasks);
            tasks.forEach(flowtrackHydrateTaskAssignee);
        }
        if (hasOwnBootstrapField(data, 'documents')) replaceArray(docs, data.documents);
        if (hasOwnBootstrapField(data, 'notifications')) replaceArray(notifications, data.notifications);
        if (hasOwnBootstrapField(data, 'activities')) replaceArray(dashboardActivities, data.activities);
        if (hasOwnBootstrapField(data, 'workflows')) {
            replaceArray(workflowTemplates, flowtrackNormalizeWorkflows(data.workflows));
            derivedStateChanged = true;
        }
        if (hasOwnBootstrapField(data, 'taskPacks')) {
            taskPacks = flowtrackToArray(data.taskPacks).filter((pack) => pack && typeof pack === 'object');
            derivedStateChanged = true;
        }
        if (hasOwnBootstrapField(data, 'capabilities') && data.capabilities && typeof data.capabilities === 'object') {
            capabilities = data.capabilities;
        }
        if (hasOwnBootstrapField(data, 'summaryMetrics') && data.summaryMetrics && typeof data.summaryMetrics === 'object' && !Array.isArray(data.summaryMetrics)) {
            summaryMetrics = { ...summaryMetrics, ...data.summaryMetrics };
        }
        if (hasOwnBootstrapField(data, 'notificationRules')) notificationRules = flowtrackToArray(data.notificationRules);
        if (hasOwnBootstrapField(data, 'notificationAccess')) notificationAccess = data.notificationAccess || notificationAccess;
        notificationUnread = notifications.filter((notification) => !notification.read).length;
        const incomingWorkspaceVersion = Number(data.bootstrapVersion || 0);
        if (incomingWorkspaceVersion > 0) workspaceDataVersion = incomingWorkspaceVersion;

        if (hasOwnBootstrapField(data, 'accessControl')) {
            const access = data.accessControl;
            canViewAccess = Boolean(access);
            canManageAccess = Boolean(access?.canManage);
            canCreateUsers = Boolean(access?.canCreateUsers);
            canDeleteUsers = Boolean(access?.canDeleteUsers);
            isFlowAdmin = Boolean(access?.isAdmin || currentUser?.isAdmin);
            isFlowSuperAdmin = Boolean(access?.isSuperAdmin || currentUser?.isSuperAdmin);
            accessRoles = flowtrackToArray(access?.roles);
            accessMembers = flowtrackToArray(access?.members);
            const incomingAccessCatalog = access?.catalog || accessCatalog || {};
            accessCatalog = {
                modules: flowtrackToArray(incomingAccessCatalog.modules),
                actions: flowtrackToArray(incomingAccessCatalog.actions),
                scopes: flowtrackToArray(incomingAccessCatalog.scopes),
                sensitiveFields: flowtrackToArray(incomingAccessCatalog.sensitiveFields),
            };
            if (!selectedAccessRoleId || !accessRoles.some((role) => Number(role.id) === Number(selectedAccessRoleId))) {
                selectedAccessRoleId = accessRoles[0]?.id || null;
            }
        }

        if (hasOwnBootstrapField(data, 'masterDataCatalog') || hasOwnBootstrapField(data, 'masterData')) {
            if (hasOwnBootstrapField(data, 'masterDataCatalog')) masterCatalog = flowtrackToArray(data.masterDataCatalog);
            for (const key of Object.keys(masterLabels)) delete masterLabels[key];
            for (const key of Object.keys(masterData)) delete masterData[key];
            masterCatalog.forEach((section) => {
                masterLabels[section.key] = section.label;
                masterData[section.key] = flowtrackToArray(data.masterData?.[section.key]);
            });
            if (!masterCatalog.some((section) => section.key === selectedMasterCategory)) {
                selectedMasterCategory = masterCatalog[0]?.key || '';
            }
            derivedStateChanged = true;
        }

        if (derivedStateChanged) rebuildTaskPackDerivedState();
        if (workflowTemplates.length) {
            selectedWorkflowId = workflowTemplates[0]?.id ?? selectedWorkflowId;
            if (!boardWorkflowId || !workflowTemplates.some((workflow) => Number(workflow.id) === Number(boardWorkflowId))) {
                boardWorkflowId = workflowTemplates.find((workflow) => workflow.active !== false)?.id
                    ?? workflowTemplates[0]?.id
                    ?? null;
            }
        }

        applyCurrentUserIdentity();
        if (hasOwnBootstrapField(data, 'notifications') || hasOwnBootstrapField(data, 'currentUser')) initializeRealtimeNotifications();
    }

    async function loadBootstrapSegment(url, perPage = 50, generation = bootstrapGeneration, allPages = false, minimumRows = 0) {
        if (!url) return { rows: [], relatedJobs: [], relatedClients: [], pagination: null };
        if (generation !== bootstrapGeneration) return null;

        const rows = [];
        const relatedJobs = [];
        const relatedClients = [];
        let pagination = null;
        let pageNumber = 1;
        const normalizedPerPage = Math.max(20, Number(perPage) || 50);
        const minimumPageCount = Math.max(1, Math.ceil(Math.max(0, Number(minimumRows) || 0) / normalizedPerPage));
        const maxPages = allPages ? 250 : minimumPageCount;

        const requestPageSize = allPages ? Math.max(100, normalizedPerPage) : normalizedPerPage;
        while (pageNumber <= maxPages) {
            const separator = url.includes('?') ? '&' : '?';
            const pageUrl = `${url}${separator}per_page=${encodeURIComponent(requestPageSize)}&page=${encodeURIComponent(pageNumber)}`;
            const page = await api(pageUrl, { cache: 'no-store' });
            if (generation !== bootstrapGeneration) return null;
            if (!page || typeof page !== 'object') {
                throw new Error('FlowTrack returned an invalid data page. Please reload once more.');
            }

            flowtrackToArray(page.data).forEach((record) => flowtrackUpsertRecord(rows, record, false));
            flowtrackToArray(page.relatedJobs).forEach((record) => flowtrackUpsertRecord(relatedJobs, record, false));
            flowtrackToArray(page.relatedClients).forEach((record) => flowtrackUpsertRecord(relatedClients, record, false));
            pagination = page.pagination || null;

            if (!pagination?.hasMorePages || (!allPages && pageNumber >= minimumPageCount)) break;
            pageNumber += 1;
        }

        return { rows, relatedJobs, relatedClients, pagination };
    }

    async function loadBootstrap(options = {}) {
        const silent = Boolean(options?.silent);
        const generation = ++bootstrapGeneration;
        const coreUrl = `/flowtrack/template-api/bootstrap?_flowtrack_generation=${generation}&_=${Date.now()}`;
        if (!silent) flowtrackSetLoadingProgress(6, 'Preparing FlowTrack workspace…');
        const core = await api(coreUrl, { cache: 'no-store' });
        if (generation !== bootstrapGeneration) return null;
        if (!core || typeof core !== 'object') {
            throw new Error('FlowTrack bootstrap data is unavailable. Please reload the page.');
        }

        // Authentication/bootstrap now loads only shared workspace/configuration
        // state. Operational records are fetched only when their page needs them.
        // This prevents login from downloading every visible Job and Task.
        bootstrapSegmentUrls = core.segments || {};
        pageContextBaseUrl = String(core.contextBaseUrl || '/flowtrack/template-api/context');
        bootstrapSegmentPerPage = Math.max(20, Number(bootstrapSegmentUrls.perPage || 50));
        if (!silent) {
            Object.keys(segmentPagination).forEach((segment) => {
                segmentPagination[segment] = null;
                segmentLoadState[segment] = 'none';
            });
            pageDataLoading.clear();
            pageContextLoading.clear();
            pageContextState.clear();
            replaceArray(clientLabels, []);
        }

        // Background bootstrap refreshes must never throw away already-loaded
        // page data. Only a fresh authenticated startup initializes the empty
        // operational collections; subsequent silent refreshes update global
        // shell state in place.
        const data = silent ? core : Object.assign({}, core, {
            clients: [],
            jobs: [],
            tasks: [],
            documents: [],
        });
        if (generation !== bootstrapGeneration) return null;
        if (!silent) flowtrackSetLoadingProgress(82, 'Applying workspace configuration…');
        applyBootstrap(data);
        bootstrapAppliedGeneration = generation;
        if (!silent) flowtrackSetLoadingProgress(90, 'Loading this page…');
        return data;
    }

    function flowtrackSegmentTarget(segment) {
        if (segment === 'clients') return clients;
        if (segment === 'jobs') return jobs;
        if (segment === 'tasks') return tasks;
        if (segment === 'documents') return docs;
        return null;
    }

    async function flowtrackEnsureSegmentData(segment, options = {}) {
        const target = flowtrackSegmentTarget(segment);
        const url = bootstrapSegmentUrls?.[segment];
        if (!target || !url) return;

        const allPages = Boolean(options?.allPages);
        const force = Boolean(options?.force);
        const currentState = segmentLoadState[segment] || 'none';
        if (!force && (currentState === 'all' || (currentState === 'page' && !allPages))) return;
        if (segmentLoading.has(segment)) {
            while (segmentLoading.has(segment)) await new Promise((resolve) => setTimeout(resolve, 20));
            return flowtrackEnsureSegmentData(segment, options);
        }

        segmentLoading.add(segment);
        try {
            // A realtime/background refresh must never shrink a page that already
            // has multiple lazy-loaded pages in memory back to page 1. Preserve
            // the amount of data already visible, while still replacing it with
            // a fresh server snapshot so deleted/changed records stay accurate.
            const loadedRowCount = target.length;
            const refreshAllPages = allPages || (force && currentState === 'all');
            const minimumRows = force && !refreshAllPages ? loadedRowCount : 0;
            const page = await loadBootstrapSegment(
                url,
                bootstrapSegmentPerPage,
                bootstrapGeneration,
                refreshAllPages,
                minimumRows,
            );
            if (!page) return;

            replaceArray(target, page.rows);
            if (segment === 'tasks') target.forEach(flowtrackHydrateTaskAssignee);
            flowtrackToArray(page.relatedJobs).forEach((job) => flowtrackUpsertRecord(jobs, job, false));
            flowtrackToArray(page.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
            segmentPagination[segment] = page.pagination || null;
            segmentLoadState[segment] = refreshAllPages || !page.pagination?.hasMorePages ? 'all' : 'page';
        } finally {
            segmentLoading.delete(segment);
        }
    }

    function flowtrackPageDataRequirements(page) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        if (normalized === 'dashboard') return [{ segment: 'jobs' }, { segment: 'tasks' }];
        if (normalized === 'clients') return [{ segment: 'clients' }];
        if (normalized === 'jobs') return [{ segment: 'clients' }, { segment: 'jobs' }];
        if (normalized === 'board') return [{ segment: 'jobs' }, { segment: 'tasks' }];
        // My Work is an assignment inbox, not a paginated browsing surface.
        // Load the complete visible task set before rendering so counts/cards do
        // not grow several seconds later as auto-pagination catches up.
        if (normalized === 'mywork') return [{ segment: 'tasks', allPages: true }];
        if (normalized === 'documents') return [{ segment: 'documents' }];
        if (normalized === 'reports') return [{ segment: 'jobs' }, { segment: 'tasks' }];
        return [];
    }

    function flowtrackPageNeedsContext(page) {
        return ['dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents', 'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'notifications', 'profile']
            .includes(flowtrackNormalizePageName(page || 'dashboard'));
    }

    async function flowtrackEnsurePageContext(page, options = {}) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        if (!flowtrackPageNeedsContext(normalized)) return;
        const force = Boolean(options?.force);
        if (!force && pageContextState.get(normalized) === 'loaded') return;
        if (pageContextLoading.has(normalized)) return pageContextLoading.get(normalized);

        const request = (async () => {
            const base = String(pageContextBaseUrl || '/flowtrack/template-api/context').replace(/\/$/, '');
            const payload = await api(`${base}/${encodeURIComponent(normalized)}?_=${Date.now()}`, { cache: 'no-store' });
            if (!payload || typeof payload !== 'object') throw new Error('Unable to load this page configuration.');
            applyBootstrap(payload);
            pageContextState.set(normalized, 'loaded');
        })().finally(() => pageContextLoading.delete(normalized));

        pageContextLoading.set(normalized, request);
        return request;
    }

    function flowtrackIsPageDataReady(page) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        if (flowtrackPageNeedsContext(normalized) && pageContextState.get(normalized) !== 'loaded') return false;
        return flowtrackPageDataRequirements(normalized).every(({ segment, allPages }) => {
            const state = segmentLoadState[segment] || 'none';
            return state === 'all' || (!allPages && state === 'page');
        });
    }

    async function flowtrackEnsurePageData(page, options = {}) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        const force = Boolean(options?.force);
        const key = `${normalized}:${force ? 'force' : 'normal'}`;
        if (!force && flowtrackIsPageDataReady(normalized)) return;
        if (pageDataLoading.has(key)) return pageDataLoading.get(key);

        const request = (async () => {
            await flowtrackEnsurePageContext(normalized, { force });
            const requirements = flowtrackPageDataRequirements(normalized);
            for (let attempt = 0; attempt < 2; attempt += 1) {
                // Load page segments in a deterministic order. Task list responses
                // carry bounded related Job records; running Jobs and Tasks in
                // parallel allowed a later Jobs response to erase those related
                // records and made cards appear to vanish until another fetch.
                for (const { segment, allPages } of requirements) {
                    await flowtrackEnsureSegmentData(segment, { allPages, force });
                }
                const segmentsReady = requirements.every(({ segment, allPages }) => {
                    const state = segmentLoadState[segment] || 'none';
                    return state === 'all' || (!allPages && state === 'page');
                });
                if (segmentsReady) break;
            }
            if (requirements.some(({ segment }) => segment === 'tasks') && typeof updateBadges === 'function') {
                updateBadges();
            }
            if (currentPage === normalized) {
                renderPage(normalized);
                applyActionPermissions();
            }
        })().finally(() => pageDataLoading.delete(key));

        pageDataLoading.set(key, request);
        return request;
    }

    const pagePermissions = {
        dashboard: ['dashboard', 'view'],
        mywork: ['tasks', 'view'],
        jobs: ['jobs', 'view'],
        clients: ['clients', 'view'],
        board: ['jobs', 'view'],
        documents: ['documents', 'view'],
        reports: ['reports', 'view'],
        workflowsetup: ['workflow', 'view'],
        taskpacksetup: ['workflow', 'view'],
        masterdata: ['masterdata', 'view'],
        setup: ['users', 'view'],
    };

    function flowtrackNormalizePageName(page) {
        if (typeof page !== 'string') return '';
        return page.trim();
    }

    function canOpenPage(page) {
        const pageName = flowtrackNormalizePageName(page);
        if (!pageName) return false;
        if (['notifications', 'profile'].includes(pageName)) return true;
        if (pageName === 'board') return canFlow('jobs', 'view') || canFlow('tasks', 'view');
        if (pageName === 'setup') return canFlow('users', 'view');
        const requirement = pagePermissions[pageName];
        // Unknown values are not application pages. Reject them instead of
        // treating them as unrestricted pages (e.g. "[object Object]").
        if (!requirement) return false;
        return canFlow(requirement[0], requirement[1]);
    }

    const landingPageOrder = [
        'dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents',
        'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup',
    ];

    function firstAllowedPage(preferredPage = null) {
        const preferred = flowtrackNormalizePageName(preferredPage);
        if (preferred && canOpenPage(preferred)) return preferred;
        return landingPageOrder.find((page) => canOpenPage(page)) || 'notifications';
    }

    function applyNavigationPermissions() {
        document.querySelectorAll('[data-page]').forEach((button) => {
            const allowed = canOpenPage(button.dataset.page);
            button.classList.toggle('hidden', !allowed);
            button.disabled = !allowed;
        });
    }

    function setVisible(selector, visible) {
        document.querySelectorAll(selector).forEach((element) => element.classList.toggle('hidden', !visible));
    }

    function flowtrackEnsurePageProgress() {
        let bar = document.getElementById('flowtrackPageProgress');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'flowtrackPageProgress';
            bar.className = 'flowtrack-page-progress hidden';
            bar.innerHTML = '<div class="flowtrack-page-progress-track"><span></span></div><small>Loading…</small>';
            document.body.appendChild(bar);
        }
        return bar;
    }

    function flowtrackSetLoadingProgress(percent, label = 'Loading workspace…') {
        const value = Math.max(0, Math.min(100, Number(percent) || 0));
        const auth = document.getElementById('authLoadingScreen');
        auth?.querySelector('[data-auth-loading-progress]')?.style.setProperty('width', `${value}%`);
        const authLabel = auth?.querySelector('[data-auth-loading-label]');
        if (authLabel) authLabel.textContent = label;
        const authPct = auth?.querySelector('[data-auth-loading-percent]');
        if (authPct) authPct.textContent = `${Math.round(value)}%`;

        const appVisible = !document.getElementById('appScreen')?.classList.contains('hidden');
        if (appVisible) {
            const bar = flowtrackEnsurePageProgress();
            bar.classList.remove('hidden');
            bar.querySelector('span')?.style.setProperty('width', `${value}%`);
            const small = bar.querySelector('small');
            if (small) small.textContent = `${label} ${Math.round(value)}%`;
        }
    }

    function flowtrackFinishLoadingProgress(label = 'Ready') {
        flowtrackSetLoadingProgress(100, label);
        window.setTimeout(() => document.getElementById('flowtrackPageProgress')?.classList.add('hidden'), 350);
    }

    function flowtrackInlineProgress(label = 'Loading latest data…') {
        return `<div class="flowtrack-inline-progress"><div class="flowtrack-inline-progress-copy"><b>${html(label)}</b><span>Refreshing assigned task setup, due dates and history.</span></div><div class="flowtrack-inline-progress-track"><span></span></div></div>`;
    }

    function applyActionPermissions() {
        applyNavigationPermissions();
        setVisible('#quickCreateBtn,#jobCreate,#boardCreate,[data-dashboard-action="create"]', canFlow('jobs', 'create'));
        setVisible('#newClient', canFlow('clients', 'create'));
        setVisible('#editClientBtn', canFlow('clients', 'edit_own') || canFlow('clients', 'edit_all'));
        setVisible('[data-new-client-job]', canFlow('jobs', 'create'));
        setVisible('[data-next-phase]', canFlow('jobs', 'edit_own') || canFlow('jobs', 'edit_all') || canFlow('jobs', 'override'));
        setVisible('#uploadBtn,[data-upload-job]', canFlow('documents', 'create'));
        setVisible('#addWorkflow,#cloneWorkflow,#addWorkflowPhase', canFlow('workflow', 'create'));
        setVisible('#editWorkflowInfo,[data-edit-phase],[data-phase-up],[data-phase-down],[data-edit-pack-id]', canFlow('workflow', 'edit_all') || canFlow('workflow', 'manage'));
        setVisible('[data-delete-phase]', canFlow('workflow', 'delete'));
        setVisible('#addTaskPack', canFlow('workflow', 'create'));
        setVisible('#addMasterRecord,#masterImport', canFlow('masterdata', 'create'));
        setVisible('[data-master-edit],[data-master-toggle]', canFlow('masterdata', 'edit_all') || canFlow('masterdata', 'manage'));
        setVisible('[data-master-delete]', canFlow('masterdata', 'delete'));
        setVisible('[data-add-task]', canFlow('tasks', 'create'));
        setVisible('#saveTaskUpdate', canFlow('tasks', 'edit_own') || canFlow('tasks', 'edit_all'));
        setVisible('#jobExport,#reportPdf,#reportExcel', canFlow('reports', 'export') || canFlow('jobs', 'export'));
        setVisible('[data-delete-client]', canFlow('clients', 'delete'));
        setVisible('[data-delete-job]', canFlow('jobs', 'delete'));
        setVisible('[data-delete-document]', canFlow('documents', 'delete'));
        setVisible('.client-sensitive-contact', canSensitive('client_contact_details'));
        setVisible('.client-sensitive-financial', canSensitive('invoice_amount'));
    }

    navigate = function (page) {
        const pageName = flowtrackNormalizePageName(page);
        if (!pageName || !canOpenPage(pageName)) {
            showToast('You do not have permission to open this section.');
            window.dispatchEvent(new CustomEvent('flowtrack:navigation-complete', { detail: { page: pageName, ok: false } }));
            return false;
        }

        let target = document.getElementById(`page-${pageName}`);
        if (!target) {
            // Route-level Blade responses intentionally contain one workspace page
            // container. Reuse that container for normal in-app navigation instead
            // of performing a full document reload, which caused the visible flash
            // between every screen. Direct URLs still render through Laravel.
            const routePage = document.querySelector('.content > .page[data-route-page="true"]');
            if (routePage) {
                routePage.id = `page-${pageName}`;
                routePage.dataset.flowtrackScreen = pageName;
                routePage.replaceChildren();
                target = routePage;
                window.FLOWTRACK_ACTIVE_PAGE = pageName;
            }
        }

        if (!target) {
            const route = window.FLOWTRACK_PAGE_ROUTES?.[pageName];
            if (route) {
                window.location.assign(route);
                return true;
            }
            window.dispatchEvent(new CustomEvent('flowtrack:navigation-complete', { detail: { page: pageName, ok: false } }));
            return false;
        }

        const result = originalNavigate(pageName);
        queueMicrotask(() => {
            applyActionPermissions();
            window.FlowtrackSearchableDropdown?.enhance?.(target);
            window.dispatchEvent(new CustomEvent('flowtrack:navigation-complete', { detail: { page: pageName, ok: result !== false } }));
        });
        return result;
    };

    function showApplication(requestedPage = null) {
        $('#loginScreen')?.classList.add('hidden');
        $('#authLoadingScreen')?.classList.add('hidden');
        $('#appScreen')?.classList.remove('hidden');
        updateBadges();
        applyActionPermissions();

        // Promise callbacks receive the resolved bootstrap payload as their first
        // argument. Only an explicit string is a valid requested page.
        const routePage = flowtrackNormalizePageName(window.FLOWTRACK_ACTIVE_PAGE);
        const preferredPage = routePage || flowtrackNormalizePageName(requestedPage) || currentUser?.defaultLandingPage || null;
        navigate(firstAllowedPage(preferredPage));
        scheduleSessionGuard();
        startNotificationPolling();
    }

    function setLoginInlineStatus(message = '') {
        const status = $('#loginInlineStatus');
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-visible', Boolean(message));
    }

    async function logout() {
        const button = $('#logoutBtn');
        const originalText = button?.querySelector('span')?.textContent || 'Log out';
        if (button) {
            button.disabled = true;
            const label = button.querySelector('span');
            if (label) label.textContent = 'Signing out…';
        }

        try {
            if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
            if (sessionStatusTimer) clearInterval(sessionStatusTimer);
            await api('/flowtrack/logout', { method: 'POST' });
            window.FLOWTRACK_AUTHENTICATED = false;
            window.location.replace('/login');
        } catch (error) {
            if (button) {
                button.disabled = false;
                const label = button.querySelector('span');
                if (label) label.textContent = originalText;
            }
            showToast(error.message);
        }
    }

    $('#forgotPasswordLink')?.addEventListener('click', () => {
        setLoginInlineStatus('For a password reset, contact your FlowTrack workspace administrator.');
    });
    $('#contactAdminLink')?.addEventListener('click', () => {
        setLoginInlineStatus('Contact your workspace administrator to request access or reset your account credentials.');
    });
    $$('[data-login-info]').forEach((button) => button.addEventListener('click', () => {
        const topic = button.dataset.loginInfo || 'Help';
        const messages = {
            Help: 'For sign-in help, contact your FlowTrack workspace administrator.',
            Privacy: 'Your sign-in is protected by your organization’s FlowTrack workspace access controls.',
            Terms: 'Workspace access is governed by your organization’s FlowTrack usage policy.',
        };
        setLoginInlineStatus(messages[topic] || messages.Help);
    }));

    // Shared date/time formatter must live in the core module because
    // Operations/My Work can render completion timestamps before the
    // lazily-loaded record/detail modules are requested.
    function flowtrackFormatDateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString([], { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }



    function rebuildTaskPackDerivedState() {
        for (const key of Object.keys(taskTemplates)) delete taskTemplates[key];
        taskPacks.forEach((pack) => {
            taskTemplates[pack.name] = flowtrackToArray(pack.items).map((item) => item.title);
        });

        workflowTemplates.forEach((workflow) => {
            flowtrackToArray(workflow.phases).forEach((phase) => {
                if (!phase?.taskPackId) return;
                const pack = taskPacks.find((item) => Number(item.id) === Number(phase.taskPackId));
                if (!pack) return;
                phase.taskPack = pack.name;
                phase.taskCount = flowtrackToArray(pack.items).length;
            });
        });
    }


/* FLOWTRACK_CSRF_AUTO_RECOVERY */
(function installFlowTrackCsrfRecovery() {
    'use strict';

    if (window.__flowTrackCsrfRecoveryInstalled) {
        return;
    }

    window.__flowTrackCsrfRecoveryInstalled = true;

    const originalFetch = window.fetch.bind(window);
    let refreshPromise = null;

    async function refreshFlowTrackCsrfToken() {
        if (!refreshPromise) {
            refreshPromise = originalFetch('/flowtrack/csrf-token', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(
                        'Unable to refresh CSRF token.'
                    );
                }

                const payload = await response.json();
                const token = String(payload.token || '');

                if (!token) {
                    throw new Error(
                        'Empty CSRF token returned.'
                    );
                }

                const meta = document.querySelector(
                    'meta[name="csrf-token"]'
                );

                if (meta) {
                    meta.setAttribute('content', token);
                }

                if (window.FLOWTRACK_PUSHER) {
                    window.FLOWTRACK_PUSHER.csrfToken = token;
                }

                return token;
            })
            .finally(() => {
                refreshPromise = null;
            });
        }

        return refreshPromise;
    }

    window.refreshFlowTrackCsrfToken =
        refreshFlowTrackCsrfToken;

    window.fetch = async function(input, init = {}) {
        const requestOptions = { ...init };
        const method = String(
            requestOptions.method || 'GET'
        ).toUpperCase();

        const response = await originalFetch(
            input,
            requestOptions
        );

        if (
            response.status !== 419 ||
            ['GET', 'HEAD', 'OPTIONS'].includes(method)
        ) {
            return response;
        }

        try {
            const token =
                await refreshFlowTrackCsrfToken();

            const headers = new Headers(
                requestOptions.headers || {}
            );

            headers.set(
                'X-CSRF-TOKEN',
                token
            );

            headers.set(
                'X-Requested-With',
                'XMLHttpRequest'
            );

            return await originalFetch(
                input,
                {
                    ...requestOptions,
                    headers
                }
            );
        } catch (error) {
            console.error(
                'FlowTrack CSRF recovery failed:',
                error
            );

            return response;
        }
    };
})();
