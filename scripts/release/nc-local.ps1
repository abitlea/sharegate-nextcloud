# Shared helpers for native (non-Docker) Nextcloud installs.
# Dot-source: . (Join-Path $PSScriptRoot "nc-local.ps1")

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

function Resolve-PhpExe {
    param([string]$PhpExe = "")
    if ($PhpExe -and (Test-Path $PhpExe)) { return $PhpExe }
    if ($env:NC_PHP -and (Test-Path $env:NC_PHP)) { return $env:NC_PHP }
    $candidates = @(
        "D:\ProgramData\php-8.2.30\php.exe",
        "C:\php\php.exe"
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { return $c }
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw "PHP not found. Set -PhpExe or env NC_PHP to your Nextcloud PHP binary."
}

function Resolve-NcRoot {
    param([string]$NcRoot = "")
    if (-not $NcRoot) { $NcRoot = $env:NC_ROOT }
    if (-not $NcRoot) { throw "Set -NcRoot or env NC_ROOT to your Nextcloud install path (folder containing occ)." }
    $NcRoot = (Resolve-Path $NcRoot).Path
    if (-not (Test-Path (Join-Path $NcRoot "occ"))) {
        $htmlOcc = Join-Path $NcRoot "html\occ"
        if (Test-Path $htmlOcc) {
            return (Resolve-Path (Join-Path $NcRoot "html")).Path
        }
        throw "occ not found under: $NcRoot (try NC_ROOT=.../html for Docker layout)"
    }
    return $NcRoot
}

function Get-NcConfig {
    param([string]$NcRoot, [string]$PhpExe)
    $configFile = (Resolve-Path (Join-Path $NcRoot "config\config.php")).Path -replace '\\', '/'
    if (-not (Test-Path $configFile)) { throw "config.php not found: $configFile" }
    $code = @"
`$CONFIG = null;
include '$configFile';
echo json_encode([
  'datadirectory' => `$CONFIG['datadirectory'] ?? '',
  'dbtype' => `$CONFIG['dbtype'] ?? '',
  'dbname' => `$CONFIG['dbname'] ?? '',
  'dbhost' => `$CONFIG['dbhost'] ?? '',
  'dbuser' => `$CONFIG['dbuser'] ?? '',
  'dbpassword' => `$CONFIG['dbpassword'] ?? '',
], JSON_UNESCAPED_SLASHES);
"@
    $json = & $PhpExe -r $code 2>$null
    if (-not $json) { throw "Failed to read config.php via PHP" }
    return $json | ConvertFrom-Json
}

function Invoke-Occ {
    param(
        [string]$NcRoot,
        [string[]]$OccArgs,
        [string]$PhpExe
    )
    Push-Location $NcRoot
    try {
        & $PhpExe occ @OccArgs
        if ($LASTEXITCODE -ne 0) {
            throw "occ failed ($LASTEXITCODE): occ $($OccArgs -join ' ')"
        }
    } finally {
        Pop-Location
    }
}

function Get-SharegateAppDir {
    param([string]$NcRoot)
    $custom = Join-Path $NcRoot "custom_apps\sharegate"
    if (Test-Path $custom) { return $custom }
    $apps = Join-Path $NcRoot "apps\sharegate"
    if (Test-Path $apps) { return $apps }
    return $null
}

function Ensure-SharegateApp {
    param(
        [string]$NcRoot,
        [string]$SourceRoot,
        [switch]$ForceCopy
    )
    $dest = Get-SharegateAppDir -NcRoot $NcRoot
    if ($dest -and -not $ForceCopy) {
        Write-Host "ShareGate app found: $dest" -ForegroundColor Gray
        return $dest
    }
    $target = Join-Path $NcRoot "custom_apps\sharegate"
    Write-Step "Copy app to $target"
    New-Item -ItemType Directory -Path (Split-Path $target) -Force | Out-Null
    if (Test-Path $target) { Remove-Item $target -Recurse -Force }
    $exclude = @(".git", "release", "docker", "tests", "node_modules", ".phpunit.cache")
    robocopy $SourceRoot $target /E /XD $exclude /NFL /NDL /NJH /NJS /nc /ns /np | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }
    return $target
}

function Test-SharegateTables {
    param(
        [string]$NcRoot,
        [string]$PhpExe
    )
    $required = @(
        "sharegate_shares",
        "sharegate_payments",
        "sharegate_access_grants",
        "sharegate_share_stats"
    )
    $cfg = Get-NcConfig -NcRoot $NcRoot -PhpExe $PhpExe

    $mysql = Get-Command mysql -ErrorAction SilentlyContinue
    if ($mysql -and $cfg.dbtype -match "mysql|mariadb") {
        $host = ($cfg.dbhost -split ":")[0]
        $port = if ($cfg.dbhost -match ":(\d+)") { $Matches[1] } else { "3306" }
        $missing = @()
        foreach ($t in $required) {
            $sql = "SHOW TABLES LIKE '$t';"
            $out = & $mysql.Source -h $host -P $port -u $cfg.dbuser "-p$($cfg.dbpassword)" $cfg.dbname -N -e $sql 2>$null
            if ($out -notmatch $t) { $missing += $t }
        }
        if ($missing.Count -gt 0) { throw "Missing tables: $($missing -join ', ')" }
        return
    }

    Write-Warning "mysql client not in PATH; checking migration:status sharegate"
    Push-Location $NcRoot
    $status = & $PhpExe occ migration:status sharegate 2>&1 | Out-String
    Pop-Location
    foreach ($m in @("000001Date20250101000000", "000002Date20250603000000")) {
        if ($status -notmatch $m) {
            throw "Migration $m not applied. Run: cd $NcRoot; php occ upgrade"
        }
    }
    Write-Host "Migrations 001+002 applied (tables assumed created)" -ForegroundColor Gray
}

function New-NcTestFile {
    param(
        [string]$NcRoot,
        [string]$PhpExe,
        [string]$User,
        [string]$FileName,
        [string]$Content = "ShareGate E2E test"
    )
    $cfg = Get-NcConfig -NcRoot $NcRoot -PhpExe $PhpExe
    $filesDir = Join-Path $cfg.datadirectory $User "files"
    if (-not (Test-Path $filesDir)) {
        throw "User files dir not found: $filesDir (check -User or create account)"
    }
    $path = Join-Path $filesDir $FileName
    Set-Content -Path $path -Value $Content -Encoding UTF8 -NoNewline
    Invoke-Occ -NcRoot $NcRoot -PhpExe $PhpExe -OccArgs @("files:scan", $User)
    Write-Host "Test file: $path" -ForegroundColor Gray
}

function Wait-NextcloudUrl {
    param([string]$Url, [int]$TimeoutSec = 60)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        try {
            $r = Invoke-WebRequest -Uri "$Url/status.php" -UseBasicParsing -TimeoutSec 5
            if ($r.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Seconds 3
    }
    throw "Nextcloud URL not reachable: $Url/status.php"
}
