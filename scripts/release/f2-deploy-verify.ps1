# F2: Deploy verify -- occ enable/upgrade + sharegate tables
# Native NC (recommended): -NcRoot "D:\nextcloud" -NcUrl "https://nc.example.com"
# Docker: -UseDocker
param(
    [string]$NcRoot = "",
    [string]$NcUrl = "",
    [string]$PhpExe = "",
    [switch]$UseDocker,
    [switch]$CopyApp,
    [string]$ComposeFile = (Join-Path $PSScriptRoot "..\..\docker\docker-compose.yml"),
    [int]$WaitSeconds = 180
)

$ErrorActionPreference = "Stop"
$appRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
. (Join-Path $PSScriptRoot "nc-local.ps1")

if (-not $NcUrl) { $NcUrl = $env:NC_URL }
if (-not $NcUrl -and $UseDocker) { $NcUrl = "http://localhost:8088" }

if ($UseDocker -or (-not $NcRoot -and -not $env:NC_ROOT)) {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw @"
Docker not found and -NcRoot not set.

For an existing Nextcloud install, run:
  powershell -File scripts\release\f2-deploy-verify.ps1 -NcRoot "C:\path\to\nextcloud" -NcUrl "https://your-nc-url"

Or set environment variables:
  `$env:NC_ROOT = "C:\path\to\nextcloud"
  `$env:NC_URL = "https://your-nc-url"
"@
    }

    Write-Step "F2 deploy verify (Docker)"
    Push-Location (Split-Path $ComposeFile)
    docker compose up -d
    if ($LASTEXITCODE -ne 0) { Pop-Location; throw "docker compose up failed" }
    Pop-Location

    Wait-NextcloudUrl -Url $NcUrl -TimeoutSec $WaitSeconds
    $ncContainer = (docker compose -f $ComposeFile ps -q nextcloud 2>$null | Select-Object -First 1).Trim()
    if (-not $ncContainer) { throw "nextcloud container not found" }

    $php = Resolve-PhpExe -PhpExe $PhpExe
    $phpIni = Join-Path $appRoot "scripts\php-dev.ini"
    if (Test-Path (Join-Path $appRoot "composer.phar")) {
        & $php -c $phpIni (Join-Path $appRoot "composer.phar") install --no-dev --working-dir=$appRoot --no-interaction
    }

    Write-Step "occ app:enable sharegate"
    docker exec -u www-data $ncContainer php occ app:enable sharegate
    Write-Step "occ upgrade"
    docker exec -u www-data $ncContainer php occ upgrade --no-interaction

    Write-Step "Verify sharegate tables"
    $tables = @("sharegate_shares", "sharegate_payments", "sharegate_access_grants", "sharegate_share_stats")
    $dbContainer = (docker compose -f $ComposeFile ps -q db 2>$null | Select-Object -First 1).Trim()
    $missing = @()
    foreach ($t in $tables) {
        $out = docker exec $dbContainer mariadb -unextcloud -pnextcloud nextcloud -N -e "SHOW TABLES LIKE '$t';" 2>$null
        if ($out -notmatch $t) { $missing += $t }
    }
    if ($missing.Count -gt 0) { throw "Missing tables: $($missing -join ', ')" }
} else {
    $NcRoot = Resolve-NcRoot -NcRoot $NcRoot
    $php = Resolve-PhpExe -PhpExe $PhpExe
    if (-not $NcUrl) {
        throw "Set -NcUrl (e.g. https://cloud.example.com) or env NC_URL"
    }

    Write-Step "F2 deploy verify (native NC)"
    Write-Host "NC_ROOT: $NcRoot" -ForegroundColor Gray
    Write-Host "NC_URL:  $NcUrl" -ForegroundColor Gray

    Wait-NextcloudUrl -Url $NcUrl -TimeoutSec $WaitSeconds

    if ($CopyApp) {
        Ensure-SharegateApp -NcRoot $NcRoot -SourceRoot $appRoot -ForceCopy | Out-Null
    } else {
        $appDir = Get-SharegateAppDir -NcRoot $NcRoot
        if (-not $appDir) {
            throw "ShareGate not in custom_apps/sharegate or apps/sharegate. Use -CopyApp to deploy from this repo."
        }
    }

    $appDir = Get-SharegateAppDir -NcRoot $NcRoot
    Write-Step "composer install --no-dev (in app dir)"
    $phpIni = Join-Path $appRoot "scripts\php-dev.ini"
    Push-Location $appDir
    if (Test-Path (Join-Path $appRoot "composer.phar")) {
        & $php -c $phpIni (Join-Path $appRoot "composer.phar") install --no-dev --no-interaction
    } elseif (Get-Command composer -ErrorAction SilentlyContinue) {
        composer install --no-dev --no-interaction
    } else {
        Write-Warning "Skipped composer install in app dir"
    }
    Pop-Location

    Write-Step "occ app:enable sharegate"
    Invoke-Occ -NcRoot $NcRoot -PhpExe $php -OccArgs @("app:enable", "sharegate")

    Write-Step "occ upgrade"
    Invoke-Occ -NcRoot $NcRoot -PhpExe $php -OccArgs @("upgrade", "--no-interaction")

    Write-Step "occ app:list (sharegate)"
    Invoke-Occ -NcRoot $NcRoot -PhpExe $php -OccArgs @("app:list", "--enabled") | Select-String sharegate

    Write-Step "Verify sharegate tables"
    Test-SharegateTables -NcRoot $NcRoot -PhpExe $php
}

Write-Step "PHPUnit (local dev tree)"
$php = Resolve-PhpExe -PhpExe $PhpExe
$phpIni = Join-Path $appRoot "scripts\php-dev.ini"
if (Test-Path (Join-Path $appRoot "vendor\bin\phpunit")) {
    Push-Location $appRoot
    & $php -c $phpIni vendor\bin\phpunit --configuration phpunit.xml.dist
    if ($LASTEXITCODE -ne 0) { throw "PHPUnit failed" }
    Pop-Location
} else {
    Write-Warning "Skipped PHPUnit (run composer install in repo root first)"
}

Write-Host ""
Write-Host "[F2] PASS: app enabled, upgrade done, tables verified." -ForegroundColor Green
Write-Host "Next: scripts\release\f3-e2e-mock.ps1 -NcRoot `"$NcRoot`" -NcUrl `"$NcUrl`"" -ForegroundColor Gray
