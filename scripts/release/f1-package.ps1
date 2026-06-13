# F1: Production package -- composer --no-dev + release archive
param(
    [string]$Version,
    [string]$OutDir = ""
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\..")
if (-not $Version) {
    $infoXml = Join-Path $root "appinfo\info.xml"
    $versionMatch = Select-String -Path $infoXml -Pattern '<version>(.*?)</version>'
    if ($versionMatch) {
        $Version = $versionMatch.Matches[0].Groups[1].Value.Trim()
    }
}
if (-not $Version) { throw "Unable to determine version from appinfo/info.xml. Pass -Version explicitly." }
if (-not $OutDir) { $OutDir = Join-Path $root "release" }

$php = "D:\ProgramData\php-8.2.30\php.exe"
if (-not (Test-Path $php)) { $php = "php" }
$phpIni = Join-Path $root "scripts\php-dev.ini"

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

Write-Step "F1 package start (v$Version)"

Write-Step "composer install --no-dev --optimize-autoloader"
Push-Location $root
if (Test-Path (Join-Path $root "composer.phar")) {
    & $php -c $phpIni composer.phar install --no-dev --no-interaction --optimize-autoloader
} elseif (Get-Command composer -ErrorAction SilentlyContinue) {
    composer install --no-dev --no-interaction --optimize-autoloader
} else {
    throw "composer or composer.phar required"
}
if (-not (Test-Path "vendor\autoload.php")) { throw "vendor/autoload.php missing" }
Pop-Location

Write-Step "Check info.xml version"
$info = Get-Content (Join-Path $root "appinfo\info.xml") -Raw
if ($info -notmatch "<version>$Version</version>") {
    Write-Warning "info.xml version mismatch with $Version"
}

$exclude = @(
    ".git", ".gitignore", ".idea", ".vscode",
    ".phpunit.cache", ".phpunit.result.cache",
    "node_modules", "release", "docker",
    "tests", "phpunit.xml.dist", "phpunit.phar", "composer.phar",
    "temp_*.py", "frontend", "src",
    "scripts\release", "scripts\php-dev.ini"
)

$staging = Join-Path $env:TEMP "sharegate-pack-$Version"
$appDir = Join-Path $staging "sharegate"
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Path $appDir -Force | Out-Null

Write-Step "Copy files to staging/sharegate"
Get-ChildItem $root -Force | ForEach-Object {
    if ($exclude -contains $_.Name) { return }
    if ($_.Name -eq "scripts") {
        $scriptsDest = Join-Path $appDir "scripts"
        New-Item -ItemType Directory -Path $scriptsDest -Force | Out-Null
        Get-ChildItem (Join-Path $root "scripts") -File | Copy-Item -Destination $scriptsDest
        return
    }
    Copy-Item $_.FullName -Destination (Join-Path $appDir $_.Name) -Recurse -Force
}

@("tests", "phpunit.xml.dist", ".phpunit.cache") | ForEach-Object {
    if (Test-Path (Join-Path $appDir $_)) { throw "Release must not include: $_" }
}

New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
$archive = Join-Path $OutDir "sharegate-$Version.tar.gz"

Write-Step "Create archive $archive"
if (Get-Command tar -ErrorAction SilentlyContinue) {
    Push-Location $staging
    tar -czf $archive sharegate
    Pop-Location
} else {
    $zipPath = Join-Path $OutDir "sharegate-$Version.zip"
    Compress-Archive -Path $appDir -DestinationPath $zipPath -Force
    $archive = $zipPath
    Write-Warning "tar not found, created zip: $zipPath"
}

$sizeBytes = (Get-Item $archive).Length
$sizeLabel = "{0:N2} MB" -f ($sizeBytes / 1MB)
Write-Host ""
Write-Host "[F1] Done: $archive ($sizeLabel)" -ForegroundColor Green
Write-Host "Upload: https://apps.nextcloud.com/developer/apps/releases" -ForegroundColor Gray
Write-Host "Or: krankerl package" -ForegroundColor Gray
