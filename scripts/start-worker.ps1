$ErrorActionPreference = "Stop"

$ProjectPath = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectPath

# Prevent duplicate workers for this project.
$existingWorker = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object {
        $_.CommandLine -like "*$ProjectPath*" -and
        $_.CommandLine -match "artisan\s+queue:work"
    } |
    Select-Object -First 1

if ($existingWorker) {
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Poster queue worker is already running (PID $($existingWorker.ProcessId))." -ForegroundColor Yellow
    exit 0
}

$phpCandidates = @(
    "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
)

$laragonPhp = Get-ChildItem "C:\laragon\bin\php\php-*\php.exe" -ErrorAction SilentlyContinue |
    Sort-Object FullName -Descending |
    Select-Object -First 1

if ($laragonPhp) {
    $phpCandidates += $laragonPhp.FullName
}

$pathPhp = Get-Command php.exe -ErrorAction SilentlyContinue
if ($pathPhp) {
    $phpCandidates += $pathPhp.Source
}

$php = $phpCandidates |
    Where-Object { $_ -and (Test-Path $_) } |
    Select-Object -First 1

if (-not $php) {
    throw "Unable to find php.exe. Install PHP/Laragon or add PHP to PATH."
}

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  Poster Queue Worker" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "PHP:     $php" -ForegroundColor Gray
Write-Host "Project: $ProjectPath" -ForegroundColor Gray
Write-Host "Time:    $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Gray
Write-Host "Ctrl+C to stop" -ForegroundColor Yellow
Write-Host ""

# Auto-restart loop
while ($true) {
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Starting queue worker..." -ForegroundColor Green

    & $php artisan queue:work database --sleep=2 --timeout=90 --tries=1 --max-jobs=100 --max-time=3600

    $exitCode = $LASTEXITCODE
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Worker stopped (exit code: $exitCode)" -ForegroundColor Yellow

    if ($exitCode -ne 0) {
        Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Restarting in 5 seconds..." -ForegroundColor Yellow
        Start-Sleep -Seconds 5
    } else {
        Start-Sleep -Seconds 1
    }
}
