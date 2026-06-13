# F3: Mock payment E2E -- create share -> pay -> download -> save-to-cloud
# Native NC: -NcRoot "D:\nextcloud" -NcUrl "https://nc.example.com" -User admin -Password ***
param(
    [string]$NcRoot = "",
    [string]$NcUrl = "",
    [string]$User = "admin",
    [string]$Password = "",
    [string]$PhpExe = "",
    [string]$TestFileName = "sharegate-e2e-test.txt",
    [string]$ComposeFile = (Join-Path $PSScriptRoot "..\..\docker\docker-compose.yml")
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "nc-local.ps1")

if (-not $NcUrl) { $NcUrl = $env:NC_URL }
if (-not $NcUrl) { throw "Set -NcUrl or env NC_URL (e.g. https://cloud.example.com)" }
if (-not $Password) { $Password = $env:NC_PASSWORD }
if (-not $Password) { throw "Set -Password or env NC_PASSWORD" }

$NcUrl = $NcUrl.TrimEnd("/")
$appBase = "$NcUrl/index.php/apps/sharegate"

function Get-RequestToken($html) {
    if ($html -match 'data-requesttoken="([^"]+)"') { return $Matches[1] }
    if ($html -match "requestToken\s*:\s*'([^']+)'") { return $Matches[1] }
    if ($html -match 'OC\.requestToken\s*=\s*"([^"]+)"') { return $Matches[1] }
    return $null
}

function Invoke-NcLogin {
    param([string]$BaseUrl, [string]$User, [string]$Pass)
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $loginPage = Invoke-WebRequest -Uri "$BaseUrl/login" -WebSession $session -UseBasicParsing
    $token = Get-RequestToken $loginPage.Content
    $body = @{
        user = $User
        password = $Pass
        timezone = "UTC"
        timezone_offset = "0"
    }
    if ($token) { $body.requesttoken = $token }
    Invoke-WebRequest -Uri "$BaseUrl/login" -Method POST -WebSession $session -Body $body -UseBasicParsing | Out-Null
    $dash = Invoke-WebRequest -Uri "$BaseUrl/index.php/apps/sharegate/" -WebSession $session -UseBasicParsing
    $rt = Get-RequestToken $dash.Content
    if (-not $rt) { throw "requesttoken not found after login" }
    return @{ Session = $session; RequestToken = $rt }
}

Write-Step "F3 Mock E2E ($NcUrl)"

# Prepare test file
$ncContainer = $null
if (Get-Command docker -ErrorAction SilentlyContinue) {
    $ncContainer = (docker compose -f $ComposeFile ps -q nextcloud 2>$null | Select-Object -First 1)
}
if ($ncContainer) {
    $ncContainer = $ncContainer.Trim()
    Write-Step "Create test file (Docker)"
    docker exec -u www-data $ncContainer bash -c "echo 'ShareGate E2E test' > /var/www/html/data/$User/files/$TestFileName"
    docker exec -u www-data $ncContainer php occ files:scan $User 2>$null | Out-Null
} elseif ($NcRoot -or $env:NC_ROOT) {
    $NcRoot = Resolve-NcRoot -NcRoot $NcRoot
    $php = Resolve-PhpExe -PhpExe $PhpExe
    Write-Step "Create test file (native NC data dir)"
    New-NcTestFile -NcRoot $NcRoot -PhpExe $php -User $User -FileName $TestFileName
} else {
    Write-Warning "No -NcRoot and no Docker; assuming files/$User/$TestFileName already exists in NC"
}

Write-Step "Login ($User)"
$auth = Invoke-NcLogin -BaseUrl $NcUrl -User $User -Pass $Password
$headers = @{
    requesttoken = $auth.RequestToken
    Accept = "application/json"
    "Content-Type" = "application/json"
}

Write-Step "Create paid share"
$createBody = @{
    file_path = $TestFileName
    file_name = $TestFileName
    title = "E2E Test Share"
    price = 100
    access_days = 7
    storage_type = "nextcloud"
} | ConvertTo-Json -Compress

$createRes = Invoke-WebRequest -Uri "$appBase/share/create" -Method POST `
    -WebSession $auth.Session -Headers $headers -Body $createBody -UseBasicParsing
$create = $createRes.Content | ConvertFrom-Json
if (-not $create.success) { throw "create share failed: $($create.error)" }
$shareId = $create.share_id
Write-Host "share_id: $shareId"

$buyerId = "e2e_buyer_" + [guid]::NewGuid().ToString("N").Substring(0, 12)

Write-Step "Create Mock payment"
$payBody = @{ share_id = $shareId; provider_user_id = $buyerId } | ConvertTo-Json -Compress
$payRes = Invoke-WebRequest -Uri "$appBase/payment/create" -Method POST `
    -Body $payBody -ContentType "application/json" -UseBasicParsing
$pay = $payRes.Content | ConvertFrom-Json
if (-not $pay.success) { throw "payment create failed: $($pay.error)" }
$orderId = $pay.order_id
Write-Host "order_id: $orderId"

Write-Step "Confirm Mock payment (webhook)"
$confirmBody = @{ order_id = $orderId; provider_user_id = $buyerId } | ConvertTo-Json -Compress
$confirmRes = Invoke-WebRequest -Uri "$appBase/payment/webhook" -Method POST `
    -Body $confirmBody -ContentType "application/json" -UseBasicParsing
$confirm = $confirmRes.Content | ConvertFrom-Json
if (-not $confirm.success) { throw "payment confirm failed: $($confirm.error)" }

Write-Step "Verify download access"
$verifyBody = @{ share_id = $shareId; provider_user_id = $buyerId } | ConvertTo-Json -Compress
$verifyRes = Invoke-WebRequest -Uri "$appBase/payment/verify" -Method POST `
    -Body $verifyBody -ContentType "application/json" -UseBasicParsing
$verify = $verifyRes.Content | ConvertFrom-Json
if (-not $verify.success -or $verify.code -ne "ACCESS_GRANTED") {
    throw "verify failed: $($verify | ConvertTo-Json -Compress)"
}
Write-Host "download_url: $($verify.download_url)"

Write-Step "Check has_access"
$checkRes = Invoke-WebRequest -Uri "$appBase/payment/check/$shareId`?provider_user_id=$buyerId" -UseBasicParsing
$check = $checkRes.Content | ConvertFrom-Json
if (-not $check.has_access) { throw "has_access should be true" }

Write-Step "Save to Nextcloud (logged-in user)"
$saveRes = Invoke-WebRequest -Uri "$appBase/s/$shareId/save-to-cloud" -Method POST `
    -WebSession $auth.Session -Headers $headers `
    -Body (@{ provider_user_id = $buyerId } | ConvertTo-Json -Compress) -UseBasicParsing
$save = $saveRes.Content | ConvertFrom-Json
if (-not $save.success) { throw "save-to-cloud failed: $($save.error)" }
Write-Host "saved: $($save.path)"

Write-Host ""
Write-Host "[F3] PASS: create -> pay -> download -> save-to-cloud" -ForegroundColor Green
