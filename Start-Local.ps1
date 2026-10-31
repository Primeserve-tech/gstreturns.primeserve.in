param([int]$Port=8086)
$ErrorActionPreference='Stop'
Set-Location -LiteralPath $PSScriptRoot
$phpCommand=Get-Command php -ErrorAction SilentlyContinue
$phpPath=if ($phpCommand) { $phpCommand.Source } else { Join-Path (Split-Path $PSScriptRoot -Parent) '.runtime\php\php.exe' }
if (!(Test-Path -LiteralPath $phpPath)) { throw 'Install PHP 8.2+ with curl, intl, mbstring, sodium, pdo_sqlite and zip first.' }
if (!(Test-Path -LiteralPath 'vendor\autoload.php')) { throw 'Run composer install first.' }
if (!(Test-Path -LiteralPath '.env')) {
    $random=New-Object byte[] 32
    $generator=[Security.Cryptography.RandomNumberGenerator]::Create()
    $generator.GetBytes($random)
    $generator.Dispose()
    $encoded=[Convert]::ToBase64String($random)
    $envText="CI_ENVIRONMENT = production`napp.baseURL = 'http://127.0.0.1:$Port/'`nGST_MODE = fixture`nGST_ENCRYPTION_KEY = '$encoded'`nGST_POLL_SECONDS = 10`nGST_JOB_TIMEOUT_SECONDS = 540`nGST_AUDIT_RETENTION_DAYS = 30`n"
    [IO.File]::WriteAllText((Join-Path $PSScriptRoot '.env'),$envText,[Text.UTF8Encoding]::new($false))
}
New-Item -ItemType Directory -Force -Path 'writable\logs' | Out-Null
$server=Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if (!$server) {
    $serverProcess=Start-Process -FilePath $phpPath -ArgumentList @('spark','serve','--host','127.0.0.1','--port',"$Port") -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $PSScriptRoot 'writable\logs\local-server.log') -RedirectStandardError (Join-Path $PSScriptRoot 'writable\logs\local-server-error.log') -PassThru
    Set-Content -LiteralPath 'writable\local-server.pid' -Value $serverProcess.Id
}
$worker=Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -eq $phpPath -and $_.CommandLine -match 'spark returns:process-v3' -and $_.CommandLine -notmatch '--fixture' }
if (!$worker) {
    $workerProcess=Start-Process -FilePath $phpPath -ArgumentList @('spark','returns:process-v3') -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $PSScriptRoot 'writable\logs\local-worker-v3.log') -RedirectStandardError (Join-Path $PSScriptRoot 'writable\logs\local-worker-v3-error.log') -PassThru
    Set-Content -LiteralPath 'writable\local-worker.pid' -Value $workerProcess.Id
}
$janitor=Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -eq $phpPath -and $_.CommandLine -match 'spark returns:purge-v3' -and $_.CommandLine -notmatch '--fixture' }
if (!$janitor) {
    $janitorProcess=Start-Process -FilePath $phpPath -ArgumentList @('spark','returns:purge-v3') -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $PSScriptRoot 'writable\logs\local-janitor-v3.log') -RedirectStandardError (Join-Path $PSScriptRoot 'writable\logs\local-janitor-v3-error.log') -PassThru
    Set-Content -LiteralPath 'writable\local-janitor.pid' -Value $janitorProcess.Id
}
Write-Output "GST Return Portal: http://127.0.0.1:$Port"
Write-Output 'API mode follows .env. In fixture mode only, use OTP 123456.'
