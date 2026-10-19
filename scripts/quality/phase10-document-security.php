#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/../..');
if ($root === false) exit(2);
$baseline = json_decode((string) file_get_contents($root.'/quality/phase10-document-security.json'), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

function p10Read(string $root, string $relative): string {
    $path = $root.'/'.$relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}
function p10TreeHash(string $root, string $relativeDir, string $suffix): string {
    $dir=$root.'/'.$relativeDir; $files=[];
    if (is_dir($dir)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($it as $file) if($file->isFile() && str_ends_with($file->getFilename(),$suffix)) $files[]=$file->getPathname();
    }
    sort($files); $ctx=hash_init('sha256');
    foreach($files as $file){hash_update($ctx,str_replace($root.'/','',$file));hash_update($ctx,"\0");hash_update($ctx,(string)file_get_contents($file));hash_update($ctx,"\0");}
    return hash_final($ctx);
}

$flowtrack = p10Read($root, 'config/flowtrack.php');
$filesystems = p10Read($root, 'config/filesystems.php');
if (!str_contains($flowtrack, "env('FLOWTRACK_DOCUMENT_DISK', 'flowtrack_private')")) $failures[]='New business documents do not default to the private disk.';
if (!str_contains($flowtrack, "env('FLOWTRACK_QUARANTINE_DISK', 'flowtrack_quarantine')")) $failures[]='Quarantine disk is not configured.';
foreach (['flowtrack_private','flowtrack_quarantine'] as $disk) {
    if (!str_contains($filesystems, "'{$disk}' => [")) $failures[]="Filesystem disk {$disk} is missing.";
}
if (preg_match("/'links'\s*=>\s*\[[^\]]*flowtrack-private/s", $filesystems)) $failures[]='Private document storage must never be linked into public/.';

$storage = p10Read($root, 'app/Services/SecureDocumentStorage.php');
$scanner = p10Read($root, 'app/Services/UploadSecurityService.php');
$response = p10Read($root, 'app/Support/StoredFileResponse.php');
foreach (['flowtrack_quarantine','->inspect(', 'writeStream(', 'legacy_document_disks'] as $needle) if (!str_contains($storage,$needle)) $failures[]="SecureDocumentStorage is missing {$needle}.";
foreach (['BLOCKED_EXTENSIONS','ALLOWED_EXTENSIONS','inspectZipContainer','inspectZipCentralDirectory','scanWithClamAv','Executable or script files are not allowed'] as $needle) if (!str_contains($scanner,$needle)) $failures[]="UploadSecurityService is missing {$needle}.";
foreach ($baseline['forced_download_extensions'] as $extension) if (!str_contains($response, "'{$extension}'")) $failures[]="StoredFileResponse does not force {$extension} download.";
if (!str_contains($response, "Content-Security-Policy") || !str_contains($response, "sandbox")) $failures[]='Stored file responses are missing sandbox/no-active-content protection.';

$requiredSecureCallers = [
    'app/Services/DocumentService.php',
    'app/Services/LegacyInquiryService.php',
    'app/Services/ProductDocumentService.php',
    'app/Services/OrderFinanceService.php',
];
foreach ($requiredSecureCallers as $relative) {
    $source=p10Read($root,$relative);
    if (!str_contains($source,'SecureDocumentStorage')) $failures[]="{$relative} bypasses SecureDocumentStorage.";
}
$productController=p10Read($root,'app/Http/Controllers/ProductDocumentController.php');
if (str_contains($productController,"Storage::disk('public')")) $failures[]='Product documents are still directly served from public storage.';
if (!str_contains($productController,'StoredFileResponse::')) $failures[]='Product documents do not use the hardened response path.';
$financeController=p10Read($root,'app/Http/Controllers/FinanceAttachmentController.php');
if (!str_contains($financeController,'authorizeFinanceRecord') || !str_contains($financeController,'StoredFileResponse::')) $failures[]='Finance attachment access is not both authorized and hardened.';

foreach (['app/Console/Commands/MigratePrivateDocuments.php','app/Console/Commands/PurgeDocumentQuarantine.php'] as $relative) if (!is_file($root.'/'.$relative)) $failures[]="{$relative} is missing.";
$console=p10Read($root,'routes/console.php');
if (!str_contains($console,"flowtrack:purge-document-quarantine")) $failures[]='Quarantine retention purge is not scheduled.';

// Phase 10 is security-only: no CSS/RBAC/route redesign. Phase 13 intentionally
// replaces legacy browser-global identifiers in Blade with the namespaced JS bridge;
// when its frozen baseline exists, accept exactly that later-phase Blade tree rather
// than weakening this gate for arbitrary future view changes.
$expectedBladeHash = $baseline['blade_tree_hash'];
$phase13BaselinePath = $root.'/quality/phase13-javascript.json';
if (is_file($phase13BaselinePath)) {
    $phase13Baseline = json_decode((string) file_get_contents($phase13BaselinePath), true, 512, JSON_THROW_ON_ERROR);
    $expectedBladeHash = (string) ($phase13Baseline['blade_tree_hash'] ?? $expectedBladeHash);
}
$phase15Final = is_file($root.'/quality/phase15-release-hardening.json');
if (!$phase15Final && !hash_equals($baseline['css_tree_hash'], p10TreeHash($root,'resources/css','.css'))) $failures[]='CSS changed during Phase 10.';
if (!$phase15Final && !hash_equals($expectedBladeHash, p10TreeHash($root,'resources/views','.blade.php'))) $failures[]='Blade views changed outside the approved Phase 13 JavaScript binding migration.';
if (!hash_equals($baseline['routes_web_hash'], hash_file('sha256',$root.'/routes/web.php'))) $failures[]='Web routes changed during Phase 10; existing download/deep-link routes must stay compatible.';
if (!hash_equals($baseline['access_control_hash'], hash_file('sha256',$root.'/app/Services/AccessControlService.php'))) $failures[]='AccessControlService changed during Phase 10.';

foreach (['tests/Feature/Phase10DocumentSecurityTest.php','tests/Feature/Phase10DocumentAccessAuthorizationTest.php'] as $relative) if (!is_file($root.'/'.$relative)) $failures[]="{$relative} is missing.";

if ($failures) {
    fwrite(STDERR,"Phase 10 document security FAILED:\n"); foreach($failures as $failure) fwrite(STDERR," - {$failure}\n"); exit(1);
}

echo "Phase 10 document security PASS\n";
echo " - new business documents: private disk\n";
echo " - upload path: quarantine -> scan -> private promotion\n";
echo " - legacy document paths: dual-read compatible\n";
echo " - PostScript/EPS/ESP/AI: forced attachment download\n";
echo " - CSS/Blade/routes/RBAC semantics: preserved\n";
