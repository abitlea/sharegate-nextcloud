# Sign a ShareGate release tarball for Nextcloud App Store (Windows-safe)
param(
    [string]$Version,
    [string]$PackagePath = '',
    [switch]$CopySignature
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$appId = 'sharegate'

$opensslCandidates = @(
    'C:\Program Files\Git\usr\bin\openssl.exe',
    'C:\Program Files\Git\mingw64\bin\openssl.exe'
)
$openssl = $opensslCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $openssl) {
    throw 'OpenSSL not found. Install Git for Windows.'
}

if (-not $Version) {
    $infoXml = Join-Path $root 'appinfo\info.xml'
    $m = Select-String -Path $infoXml -Pattern '<version>(.*?)</version>' | Select-Object -First 1
    if ($m) { $Version = $m.Matches[0].Groups[1].Value.Trim() }
}
if (-not $Version) { throw 'Pass -Version or set version in appinfo/info.xml.' }

if (-not $PackagePath) {
    $PackagePath = Join-Path $root "release\$appId-$Version.tar.gz"
}
$PackagePath = (Resolve-Path $PackagePath).Path

$certDir = Join-Path $env:USERPROFILE '.nextcloud\certificates'
$keyPath = Join-Path $certDir "$appId.key"
$crtPath = Join-Path $certDir "$appId.crt"
if (-not (Test-Path $keyPath)) { throw "Missing private key: $keyPath" }
if (-not (Test-Path $crtPath)) { throw "Missing certificate: $crtPath" }
if (-not (Test-Path $PackagePath)) { throw "Missing package: $PackagePath" }

function Get-ModulusMd5([string]$file) {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    $mod = & $openssl x509 -noout -modulus -in $file 2>$null
    if (-not $mod) { $mod = & $openssl rsa -noout -modulus -in $file 2>$null }
    if (-not $mod) { $mod = & $openssl req -noout -modulus -in $file 2>$null }
    $ErrorActionPreference = $prev
    if (-not $mod) { throw "Could not read modulus from $file" }
    return ($mod | & $openssl md5).Trim()
}

$keyMd5 = Get-ModulusMd5 $keyPath
$crtMd5 = Get-ModulusMd5 $crtPath
if ($keyMd5 -ne $crtMd5) {
    throw 'sharegate.key and sharegate.crt do not match. Use the key pair from your CSR.'
}

$sigFile = Join-Path $env:TEMP "$appId-release-$Version.sig"
$pubFile = Join-Path $env:TEMP "$appId-release-$Version.pub"
Remove-Item $sigFile, $pubFile -Force -ErrorAction SilentlyContinue

& $openssl dgst -sha512 -sign $keyPath -out $sigFile $PackagePath
if ($LASTEXITCODE -ne 0) { throw 'openssl sign failed' }

$signature = (& $openssl base64 -in $sigFile -A).Trim()
if ($signature -match '\s') {
    throw 'Signature contains whitespace; must be a single base64 line.'
}

& $openssl x509 -in $crtPath -pubkey -noout -out $pubFile | Out-Null
$verify = & $openssl dgst -sha512 -verify $pubFile -signature $sigFile $PackagePath 2>&1
Remove-Item $sigFile, $pubFile -Force -ErrorAction SilentlyContinue

Write-Host ''
Write-Host "Package: $PackagePath" -ForegroundColor Gray
Write-Host "Size:    $((Get-Item $PackagePath).Length) bytes" -ForegroundColor Gray
Write-Host "Verify:  $verify" -ForegroundColor $(if ($verify -eq 'Verified OK') { 'Green' } else { 'Red' })
if ($verify -ne 'Verified OK') {
    throw 'Local signature verification failed.'
}

Write-Host ''
Write-Host '=== Release signature (paste into App Store) ===' -ForegroundColor Cyan
Write-Host $signature
Write-Host ''

if ($CopySignature) {
    Set-Clipboard -Value $signature
    Write-Host 'Signature copied to clipboard.' -ForegroundColor Green
} else {
    Write-Host 'Tip: rerun with -CopySignature to copy to clipboard.' -ForegroundColor Yellow
}

Write-Host ''
Write-Host 'IMPORTANT:' -ForegroundColor Yellow
Write-Host '1) Sign the EXACT .tar.gz file at your Download URL (upload to GitHub first, or sign this file then upload it).'
Write-Host '2) Do not rebuild/repack after signing.'
Write-Host '3) Paste signature as one line with no spaces or line breaks.'
