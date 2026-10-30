<?php

declare(strict_types=1);

/**
 * Poster Production Automated Deploy Script (FTP + HTTP Runner)
 * Targets: https://datapersonnel.reru.ac.th/poster
 */
echo "====================================================\n";
echo " Starting Automated Deployment for Poster to Host\n";
echo "====================================================\n";

$projectRoot = dirname(__DIR__);
$zipPath = sys_get_temp_dir().'/poster_deploy_'.time().'.zip';

if (file_exists($zipPath)) {
    @unlink($zipPath);
}

// 1. Create Zip Package
echo "--> 1. Packaging files into deploy_package.zip...\n";
$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    exit("Failed to create zip at $zipPath\n");
}

function addFolderToZip(ZipArchive $zip, string $folder, string $zipSubfolder = ''): void
{
    $dir = opendir($folder);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $filePath = "$folder/$file";
        $localZipPath = $zipSubfolder === '' ? $file : "$zipSubfolder/$file";
        if (is_dir($filePath)) {
            $zip->addEmptyDir($localZipPath);
            addFolderToZip($zip, $filePath, $localZipPath);
        } else {
            $zip->addFile($filePath, $localZipPath);
        }
    }
    closedir($dir);
}

echo "    - Adding app/ ...\n";
$zip->addEmptyDir('app');
addFolderToZip($zip, "$projectRoot/app", 'app');

echo "    - Adding routes/ ...\n";
$zip->addEmptyDir('routes');
addFolderToZip($zip, "$projectRoot/routes", 'routes');

echo "    - Adding resources/ ...\n";
$zip->addEmptyDir('resources');
addFolderToZip($zip, "$projectRoot/resources", 'resources');

echo "    - Adding database/migrations/ ...\n";
$zip->addEmptyDir('database/migrations');
addFolderToZip($zip, "$projectRoot/database/migrations", 'database/migrations');

echo "    - Adding build/ (Vite assets) ...\n";
$zip->addEmptyDir('build');
addFolderToZip($zip, "$projectRoot/public/build", 'build');

$zip->close();
echo '    Package created successfully ('.number_format(filesize($zipPath) / 1024, 2)." KB)\n";

// 2. Prepare deploy_runner.php content
$deployRunnerContent = <<<'PHP'
<?php
header('Content-Type: application/json; charset=utf-8');
@ini_set('max_execution_time', 600);
@ini_set('memory_limit', '512M');

$token = $_GET['token'] ?? '';
if ($token !== 'reru_poster_sync_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$baseDir = __DIR__;
$rootDir = dirname($baseDir);
$zipFile = $baseDir . '/deploy_package.zip';
$res = [
    'success' => false,
    'steps' => [],
    'errors' => [],
];

if (!file_exists($zipFile)) {
    $res['errors'][] = 'deploy_package.zip not found';
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$tempDir = $baseDir . '/deploy_temp';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0777, true);
}

$zip = new ZipArchive();
if ($zip->open($zipFile) === TRUE) {
    $zip->extractTo($tempDir);
    $zip->close();
    $res['steps'][] = 'Extracted deploy_package.zip';
    @unlink($zipFile);
} else {
    $res['errors'][] = 'Failed to open deploy_package.zip';
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function recurseCopy(string $src, string $dst): void {
    $dir = opendir($src);
    @mkdir($dst, 0777, true);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') continue;
        if (is_dir("$src/$file")) {
            recurseCopy("$src/$file", "$dst/$file");
        } else {
            copy("$src/$file", "$dst/$file");
        }
    }
    closedir($dir);
}

function rmdirRecurse(string $dir): void {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (is_dir("$dir/$file")) {
            rmdirRecurse("$dir/$file");
        } else {
            @unlink("$dir/$file");
        }
    }
    @rmdir($dir);
}

try {
    if (is_dir("$tempDir/app")) {
        recurseCopy("$tempDir/app", "$baseDir/app");
        $res['steps'][] = 'Copied app/ folder';
    }
    if (is_dir("$tempDir/routes")) {
        recurseCopy("$tempDir/routes", "$baseDir/routes");
        $res['steps'][] = 'Copied routes/ folder';
    }
    if (is_dir("$tempDir/resources")) {
        recurseCopy("$tempDir/resources", "$baseDir/resources");
        $res['steps'][] = 'Copied resources/ folder';
    }
    if (is_dir("$tempDir/database/migrations")) {
        recurseCopy("$tempDir/database/migrations", "$baseDir/database/migrations");
        $res['steps'][] = 'Copied database/migrations/ folder';
    }
    if (is_dir("$tempDir/build")) {
        recurseCopy("$tempDir/build", "$baseDir/public/build");
        recurseCopy("$tempDir/build", "$baseDir/build");
        recurseCopy("$tempDir/build", "$rootDir/build");
        $res['steps'][] = 'Deployed build/ to public/build and root/build';
    }
} catch (\Throwable $e) {
    $res['errors'][] = 'Copy failed: ' . $e->getMessage();
}

rmdirRecurse($tempDir);

try {
    require_once "$baseDir/vendor/autoload.php";
    $app = require_once "$baseDir/bootstrap/app.php";
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $migrateOutput = trim(\Illuminate\Support\Facades\Artisan::output());
    $res['steps'][] = 'Artisan migrate: ' . ($migrateOutput ?: 'Nothing to migrate');

    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    $res['steps'][] = 'Artisan optimize:clear executed';

    $res['success'] = empty($res['errors']);
} catch (\Throwable $e) {
    $res['errors'][] = 'Artisan bootstrap/execution failed: ' . $e->getMessage();
}

@unlink(__FILE__);
$res['steps'][] = 'Self-cleanup completed';

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
PHP;

$runnerPath = sys_get_temp_dir().'/deploy_runner_'.time().'.php';
file_put_contents($runnerPath, $deployRunnerContent);

// 3. FTP Upload
echo "--> 2. Connecting to FTP 10.80.2.11...\n";
$ftp = ftp_connect('10.80.2.11');
if (! $ftp) {
    exit("FTP Connection to 10.80.2.11 failed!\n");
}

if (! ftp_login($ftp, 'ftp_datapersonnel_reru_ac_th', 'da7edb346be6f')) {
    exit("FTP Login failed!\n");
}

ftp_pasv($ftp, true);
echo "    Logged in as ftp_datapersonnel_reru_ac_th\n";

echo '--> 3. Uploading deploy_package.zip ('.number_format(filesize($zipPath) / (1024 * 1024), 2)." MB)...\n";
if (! ftp_put($ftp, './poster/deploy_package.zip', $zipPath, FTP_BINARY)) {
    exit("Failed to upload deploy_package.zip via FTP\n");
}
echo "    deploy_package.zip uploaded successfully!\n";

echo "--> 4. Uploading deploy_runner.php...\n";
if (! ftp_put($ftp, './poster/deploy_runner.php', $runnerPath, FTP_BINARY)) {
    exit("Failed to upload deploy_runner.php via FTP\n");
}
echo "    deploy_runner.php uploaded successfully!\n";

ftp_close($ftp);

// 4. Trigger Deployment via HTTP
echo "--> 5. Triggering deployment on server via HTTP...\n";
$ch = curl_init('https://datapersonnel.reru.ac.th/poster/deploy_runner.php?token=reru_poster_sync_2026');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "    HTTP Status: $httpCode\n";
echo "    Response Payload:\n";
echo $response."\n";

if ($err) {
    echo "    cURL error: $err\n";
}

@unlink($zipPath);
@unlink($runnerPath);

echo "====================================================\n";
echo " Deployment Process Finished\n";
echo "====================================================\n";
