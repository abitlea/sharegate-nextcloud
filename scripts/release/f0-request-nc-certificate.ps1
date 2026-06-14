# F0: Request Nextcloud app signing certificate (Windows)
# App id must match appinfo/info.xml <id>sharegate</id>
#
# Output (private — do NOT commit sharegate.key):
#   %USERPROFILE%\.nextcloud\certificates\sharegate.key
#   %USERPROFILE%\.nextcloud\certificates\sharegate.csr
#
# Public CSR copy for PR: certificate-request/sharegate.csr

param(
    [switch]$Regenerate
)

$ErrorActionPreference = 'Stop'
$opensslCandidates = @(
    'C:\Program Files\Git\usr\bin\openssl.exe',
    'C:\Program Files\Git\mingw64\bin\openssl.exe'
)
$openssl = $opensslCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $openssl) {
    throw 'OpenSSL not found. Install Git for Windows or add openssl to PATH.'
}

$certDir = Join-Path $env:USERPROFILE '.nextcloud\certificates'
$keyPath = Join-Path $certDir 'sharegate.key'
$csrPath = Join-Path $certDir 'sharegate.csr'
$repoCsr = Join-Path $PSScriptRoot '..\..\certificate-request\sharegate.csr' | Resolve-Path -ErrorAction SilentlyContinue

New-Item -ItemType Directory -Force -Path $certDir | Out-Null

if ((Test-Path $keyPath) -or (Test-Path $csrPath)) {
    if (-not $Regenerate) {
        Write-Host "Certificate files already exist in $certDir" -ForegroundColor Yellow
        Write-Host "Use -Regenerate to create new key/CSR (invalidates pending requests)." -ForegroundColor Yellow
    }
    else {
        Remove-Item $keyPath, $csrPath -Force -ErrorAction SilentlyContinue
    }
}

if (-not (Test-Path $csrPath)) {
    Write-Host "Generating RSA 4096 key + CSR (CN=sharegate)..." -ForegroundColor Cyan
    & $openssl req -nodes -newkey rsa:4096 `
        -keyout $keyPath `
        -out $csrPath `
        -subj '/CN=sharegate'
    Write-Host "Created: $keyPath" -ForegroundColor Green
    Write-Host "Created: $csrPath" -ForegroundColor Green
}

$repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$publicCsr = Join-Path $repoRoot 'certificate-request\sharegate.csr'
New-Item -ItemType Directory -Force -Path (Split-Path $publicCsr) | Out-Null
Copy-Item $csrPath $publicCsr -Force
Write-Host "Copied CSR to: $publicCsr" -ForegroundColor Green

Write-Host ''
Write-Host '=== Next: submit CSR to Nextcloud ===' -ForegroundColor Cyan
Write-Host '1. Open https://github.com/nextcloud/app-certificate-requests'
Write-Host '2. Fork the repo (if needed), then Create new file'
Write-Host '3. Path: sharegate/sharegate.csr'
Write-Host '4. Paste contents from certificate-request/sharegate.csr'
Write-Host '5. Open PR; optional: link https://github.com/abitlea/sharegate-nextcloud'
Write-Host '6. After merge, download sharegate.crt into:' $certDir
Write-Host ''
Write-Host 'Register app signature (step 3):' -ForegroundColor Cyan
Write-Host '  echo -n sharegate | & openssl dgst -sha512 -sign sharegate.key | openssl base64'
Write-Host ''
Write-Host 'KEEP sharegate.key PRIVATE. Never commit it to Git.' -ForegroundColor Red
