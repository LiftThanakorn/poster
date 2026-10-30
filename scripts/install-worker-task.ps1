$ErrorActionPreference = "Stop"

$TaskName = "Poster Queue Worker"
$WorkerScript = Join-Path $PSScriptRoot "start-worker.ps1"

if (-not (Test-Path $WorkerScript)) {
    throw "start-worker.ps1 was not found at $WorkerScript"
}

$PowerShell = (Get-Command powershell.exe -ErrorAction Stop).Source
$Action = New-ScheduledTaskAction -Execute $PowerShell -Argument ("-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"" + $WorkerScript + "`"")
$Trigger = New-ScheduledTaskTrigger -AtLogOn
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Description "Starts the Poster Laravel database queue worker when the user signs in." -Force | Out-Null

Write-Host "Installed scheduled task: $TaskName"
Write-Host "The worker will start automatically at the next Windows sign-in."
