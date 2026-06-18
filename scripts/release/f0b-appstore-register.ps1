# Generate App Store registration certificate + signature (Windows-safe)
# App id must match CN in sharegate.crt and info.xml <id>sharegate</id>

param(
    [switch]$CopyCertificate,
    [switch]$CopySignature
)

$ErrorActionPreference = 'Stop'
$opensslCandidates = @(
    'C:\Program Files\Git\usr\bin\openssl.exe',
    'C:\Program Files\Git\mingw64\bin\openssl.exe'
)
$openssl = $opensslCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $openssl) {
    throw 'OpenSSL not found. Install Git for Windows.'
}

$appId = 'sharegate'
$certDir = Join-Path $env:USERPROFILE '.nextcloud\certificates'
$keyPath = Join-Path $certDir "$appId.key"
$crtPath = Join-Path $certDir "$appId.crt"

foreach ($path in @($keyPath, $crtPath)) {
    if (-not (Test-Path $path)) {
        throw "Missing file: $path"
    }
}

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
    throw "Key and certificate do not match. Use the key that generated the CSR for this certificate."
}

$idFile = Join-Path $env:TEMP "$appId-id.txt"
$sigFile = Join-Path $env:TEMP "$appId-id.sig"
[System.IO.File]::WriteAllBytes($idFile, [System.Text.Encoding]::ASCII.GetBytes($appId))
& $openssl dgst -sha512 -sign $keyPath -out $sigFile -binary $idFile | Out-Null
$signature = (& $openssl base64 -in $sigFile -A).Trim()
$certificate = (Get-Content $crtPath -Raw).Trim()

$pubFile = Join-Path $env:TEMP "$appId.pub"
& $openssl x509 -in $crtPath -pubkey -noout -out $pubFile | Out-Null
$verify = & $openssl dgst -sha512 -verify $pubFile -signature $sigFile -binary $idFile 2>&1
Remove-Item $idFile, $sigFile, $pubFile -Force -ErrorAction SilentlyContinue

Write-Host "Local verify: $verify" -ForegroundColor $(if ($verify -eq 'Verified OK') { 'Green' } else { 'Red' })
Write-Host ''
Write-Host '=== 1) Public certificate ===' -ForegroundColor Cyan
Write-Host $certificate
Write-Host ''
Write-Host '=== 2) Signature (single line) ===' -ForegroundColor Cyan
Write-Host $signature
Write-Host ''

if ($CopyCertificate) {
    Set-Clipboard -Value $certificate
    Write-Host 'Certificate copied to clipboard.' -ForegroundColor Green
}
elseif ($CopySignature) {
    Set-Clipboard -Value $signature
    Write-Host 'Signature copied to clipboard.' -ForegroundColor Green
}
else {
    Write-Host 'Tip: rerun with -CopyCertificate or -CopySignature to copy to clipboard.' -ForegroundColor Yellow
}
