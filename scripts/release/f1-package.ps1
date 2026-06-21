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

# Keep in sync with .krankerlignore
$excludeNames = @(
    ".git", ".gitignore", ".gitattributes", ".idea", ".vscode", ".cursor",
    ".phpunit.cache", ".phpunit.result.cache", ".editorconfig",
    "node_modules", "release", "docker",
    "tests", "phpunit.xml.dist", "phpunit.phar", "composer.phar",
    "frontend", "src",
    "package.json", "package-lock.json", "webpack.config.js",
    "certificate-request"
)
$excludePatterns = @(
    "temp_*.py",
    "*.tar.gz",
    "*.zip",
    "sharegate-*.tar.gz"
)
$requiredPaths = @(
    "appinfo\info.xml",
    "CHANGELOG.md",
    "CHANGELOG.zh-hans.md",
    "lib",
    "vendor\autoload.php",
    "js\dashboard.js",
    "l10n\en.json",
    "l10n\zh_CN.json",
    "l10n\en.js",
    "l10n\zh_CN.js"
)
$forbiddenPaths = @(
    "tests",
    "phpunit.xml.dist",
    ".phpunit.cache",
    "node_modules",
    "src",
    ".cursor",
    "scripts\release"
)

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

function Test-ShouldExclude([string]$Name) {
    if ($excludeNames -contains $Name) { return $true }
    foreach ($pat in $excludePatterns) {
        if ($Name -like $pat) { return $true }
    }
    return $false
}

function Remove-ExcludedTree([string]$BaseDir) {
    Get-ChildItem $BaseDir -Force -Recurse -Directory | ForEach-Object {
        if (Test-ShouldExclude $_.Name) {
            Remove-Item $_.FullName -Recurse -Force
        }
    }
    Get-ChildItem $BaseDir -Force -Recurse -File | ForEach-Object {
        if (Test-ShouldExclude $_.Name) {
            Remove-Item $_.FullName -Force
        }
    }
}

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

$staging = Join-Path $env:TEMP "sharegate-pack-$Version"
$appDir = Join-Path $staging "sharegate"
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Path $appDir -Force | Out-Null

Write-Step "Copy files to staging/sharegate"
Get-ChildItem $root -Force | ForEach-Object {
    if (Test-ShouldExclude $_.Name) { return }
    if ($_.Name -eq "scripts") {
        $scriptsDest = Join-Path $appDir "scripts"
        New-Item -ItemType Directory -Path $scriptsDest -Force | Out-Null
        Get-ChildItem (Join-Path $root "scripts") -File | Copy-Item -Destination $scriptsDest
        return
    }
    Copy-Item $_.FullName -Destination (Join-Path $appDir $_.Name) -Recurse -Force
}

Write-Step "Prune excluded paths"
Remove-ExcludedTree $appDir

Write-Step "Validate package contents"
foreach ($rel in $requiredPaths) {
    $p = Join-Path $appDir $rel
    if (-not (Test-Path $p)) { throw "Release missing required path: $rel" }
}
foreach ($rel in $forbiddenPaths) {
    $p = Join-Path $appDir $rel
    if (Test-Path $p) { throw "Release must not include: $rel" }
}

$junk = Get-ChildItem $appDir -Recurse -Force | Where-Object {
    $_.Name -like 'temp_*.py' -or $_.Name -like '*.tar.gz' -or $_.Name -like '*.zip' -or $_.FullName -match '\\\.cursor(\\|$)'
}
if ($junk) {
    $sample = ($junk | Select-Object -First 5 | ForEach-Object { $_.FullName.Replace($appDir + '\', '') }) -join ', '
    throw "Release still contains dev/junk files: $sample"
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

if (-not (Get-Command tar -ErrorAction SilentlyContinue)) {
    throw "tar is required for App Store releases (.tar.gz)"
}

$sizeBytes = (Get-Item $archive).Length
$sizeLabel = "{0:N2} MB" -f ($sizeBytes / 1MB)
Write-Host ""
Write-Host "[F1] Done: $archive ($sizeLabel)" -ForegroundColor Green
Write-Host "Next: sign archive, upload GitHub Release, submit App Store" -ForegroundColor Gray
Write-Host "Verify: powershell -File scripts\release\f0c-appstore-verify.ps1" -ForegroundColor Gray
