# Quick NC login diagnostic (does not print password)
$ErrorActionPreference = 'Stop'
$envLocal = Join-Path $PSScriptRoot 'env.local.ps1'
if (Test-Path $envLocal) { . $envLocal }

if (-not $env:NC_URL) { throw 'NC_URL not set' }
if (-not $env:NC_PASSWORD) { throw 'NC_PASSWORD not set' }
$user = if ($env:NC_USER) { $env:NC_USER } else { 'admin' }
$base = $env:NC_URL.TrimEnd('/')

Write-Host "URL:  $base"
Write-Host "User: $user"
if ($env:NC_PASSWORD -eq 'your-password') {
    Write-Host 'WARN: NC_PASSWORD still looks like a placeholder in env.local.ps1' -ForegroundColor Yellow
}

. (Join-Path $PSScriptRoot 'nc-local.ps1')

$auth = $null
try {
    $auth = Invoke-NcCookieLogin -BaseUrl $base -User $user -Pass $env:NC_PASSWORD
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
}

if ($auth) {
    Write-Host 'Cookie login: OK' -ForegroundColor Green
    Write-Host "ShareGate requesttoken: $($auth.RequestToken.Length -gt 0)"
    Write-Host 'LOGIN OK' -ForegroundColor Green
    exit 0
}

if (Test-NcBasicAuth -BaseUrl $base -User $user -Pass $env:NC_PASSWORD) {
    Write-Host 'Basic Auth: OK (password correct)' -ForegroundColor Green
    Write-Host 'Cookie login: FAILED — POST /login needs Origin header on NC 33' -ForegroundColor Yellow
    exit 1
}

Write-Host 'LOGIN FAILED — check NC_USER / NC_PASSWORD' -ForegroundColor Red
exit 1
