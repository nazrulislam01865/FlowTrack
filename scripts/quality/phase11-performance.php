#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/../..');
if ($root === false) exit(2);
$baseline = json_decode((string) file_get_contents($root.'/quality/phase11-performance.json'), true, 512, JSON_THROW_ON_ERROR);
$inventory = json_decode((string) file_get_contents($root.'/quality/phase11-query-inventory.json'), true, 512, JSON_THROW_ON_ERROR);
$failures=[];

function p11Read(string $root,string $relative):string{$p=$root.'/'.$relative;return is_file($p)?(string)file_get_contents($p):'';}

// Every application ->get() occurrence must be explicitly classified.
$current=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app',FilesystemIterator::SKIP_DOTS));
foreach($it as $file){
    if(!$file->isFile()||$file->getExtension()!=='php') continue;
    $relative=str_replace($root.'/','',$file->getPathname());
    $lines=file($file->getPathname(),FILE_IGNORE_NEW_LINES)?:[];
    foreach($lines as $i=>$line){
        $count=preg_match_all('/->get\s*\(/',$line);
        for($n=0;$n<$count;$n++) $current[]=$relative.':'.($i+1);
    }
}
sort($current);
$recorded=[];
foreach(($inventory['entries']??[]) as $entry) $recorded[]=(string)$entry['file'].':'.(int)$entry['line'];
sort($recorded);
if($current!==$recorded) $failures[]='Phase 11 query inventory no longer matches every app ->get() occurrence; review and classify the changed reads.';
if((int)($inventory['unsafe_unbounded_count']??-1)!==0) $failures[]='Query inventory contains unsafe-unbounded operational hydration.';
if(count($recorded)!==(int)($baseline['inventory_occurrences']??0)) $failures[]='Reviewed query inventory occurrence count changed unexpectedly.';

// High-traffic operational screens must remain paginated/bounded.
$requiredPagination=[
    'app/Services/OrderListPrototypeService.php'=>['->paginate(', '->select(['],
    'app/Services/LegacyInquiryService.php'=>['->paginate(max(1, min(50, $perPage))'],
    'app/Services/MyWorkService.php'=>['$groupsQuery->paginate(', '->whereIn(\'tasks.flow_job_id\', $jobIds)'],
    'app/Livewire/Documents/Index.php'=>['->paginate(max(10, min(100, $this->perPage))'],
    'app/Services/ClientService.php'=>['->paginate($perPage)'],
    'app/Services/MasterDataService.php'=>['->paginate($perPage'],
    'app/Services/NotificationService.php'=>['->paginate($perPage'],
    'app/Services/FilterOptionService.php'=>['MAX_PER_PAGE = 20','->limit($limit)'],
];
foreach($requiredPagination as $relative=>$needles){$s=p11Read($root,$relative);foreach($needles as $needle)if(!str_contains($s,$needle))$failures[]="$relative lost bounded operational-read contract: $needle";}

$migration=p11Read($root,'database/migrations/2026_08_22_180000_add_phase11_performance_indexes.php');
foreach(($baseline['required_indexes']??[]) as $index) if(!str_contains($migration,"'{$index}'")) $failures[]="Missing Phase 11 index {$index}.";
if(substr_count($migration,'->index(')<count($baseline['required_indexes']??[])) $failures[]='Phase 11 index migration does not define the expected index set.';

$config=p11Read($root,'config/performance.php');
foreach(($baseline['budgets']??[]) as $key=>$value){if(!str_contains($config,"'{$key}'"))$failures[]="Performance budget {$key} is missing.";}
$provider=p11Read($root,'app/Providers/AppServiceProvider.php');
if(!str_contains($provider,'Model::preventLazyLoading()')||!str_contains($provider,'handleLazyLoadingViolationUsing'))$failures[]='Development/test N+1 detection is not enabled.';
$console=p11Read($root,'routes/console.php');
if(!str_contains($console,'flowtrack:performance:explain')||!str_contains($console,'EXPLAIN'))$failures[]='Repeatable EXPLAIN command is missing.';

foreach(['tests/Feature/Phase11OperationalListBoundsTest.php','tests/Feature/Phase11PerformanceIndexesTest.php','docs/refactor/PHASE_11_BENCHMARK.md','docs/performance-guidelines.md'] as $relative) if(!is_file($root.'/'.$relative))$failures[]="$relative is missing.";

// All pre-Phase-11 migrations remain immutable; only the isolated performance migration is new.
$snapshot=json_decode((string)file_get_contents($root.'/quality/pre-phase11-migration-hashes.json'),true,512,JSON_THROW_ON_ERROR);
foreach(($snapshot['files']??[]) as $filename=>$hash){$path=$root.'/database/migrations/'.$filename;if(!is_file($path)||!hash_equals((string)$hash,hash_file('sha256',$path)))$failures[]='Existing migration changed: '.$filename;}

if($failures){fwrite(STDERR,"Phase 11 performance architecture FAILED:\n");foreach($failures as $f)fwrite(STDERR," - $f\n");exit(1);}

echo "Phase 11 performance architecture PASS\n";
echo " - reviewed ->get() occurrences: ".count($recorded)."\n";
echo " - unsafe operational full-table hydration: 0\n";
echo " - new composite indexes: ".count($baseline['required_indexes']??[])."\n";
echo " - high-traffic lists: paginated/bounded\n";
echo " - local/testing lazy-load detection: enabled\n";
