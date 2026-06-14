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

$envLocal = Join-Path $PSScriptRoot "env.local.ps1"
if (Test-Path $envLocal) {
    . $envLocal
}

if (-not $NcUrl) { $NcUrl = $env:NC_URL }
if (-not $NcUrl) { throw "Set -NcUrl or env NC_URL (e.g. https://cloud.example.com)" }
if (-not $Password) { $Password = $env:NC_PASSWORD }
if (-not $Password) { throw "Set -Password or env NC_PASSWORD" }

$NcUrl = $NcUrl.TrimEnd("/")
$appBase = "$NcUrl/index.php/apps/sharegate"

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
    Write-Step "Create test file (WebDAV)"
    New-NcTestFileRemote -BaseUrl $NcUrl -User $User -Pass $Password -FileName $TestFileName
}

Write-Step "Login ($User)"
$auth = Invoke-NcWebLogin -BaseUrl $NcUrl -User $User -Pass $Password
try {
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

$createRes = Invoke-NcAppWebRequest -Uri "$appBase/share/create" -Method POST `
    -Headers $headers -Body $createBody -Auth $auth
$create = $createRes.Content | ConvertFrom-Json
if (-not $create.success) {
    $detail = if ($create.error) { $create.error } else { $createRes.Content }
    throw "create share failed: $detail"
}
$shareId = $create.share_id
Write-Host "share_id: $shareId"

$buyerId = "e2e_buyer_" + [guid]::NewGuid().ToString("N").Substring(0, 12)

Write-Step "Create Mock payment"
$payBody = @{ share_id = $shareId; provider_user_id = $buyerId } | ConvertTo-Json -Compress
$payRes = Invoke-NcPublicJsonPost -Uri "$appBase/payment/create" -Body $payBody
$pay = $payRes.Content | ConvertFrom-Json
if (-not $pay.success) { throw "payment create failed: $($pay.error)" }
$orderId = $pay.order_id
Write-Host "order_id: $orderId"

Write-Step "Confirm Mock payment (webhook)"
$confirmBody = @{ order_id = $orderId; provider_user_id = $buyerId } | ConvertTo-Json -Compress
$confirmRes = Invoke-NcPublicJsonPost -Uri "$appBase/payment/webhook" -Body $confirmBody
$confirm = $confirmRes.Content | ConvertFrom-Json
if (-not $confirm.success) { throw "payment confirm failed: $($confirm.error)" }

Write-Step "Verify download access"
$verifyBody = @{ share_id = $shareId; provider_user_id = $buyerId } | ConvertTo-Json -Compress
$verifyRes = Invoke-NcPublicJsonPost -Uri "$appBase/payment/verify" -Body $verifyBody
$verify = $verifyRes.Content | ConvertFrom-Json
if (-not $verify.success -or $verify.code -ne "ACCESS_GRANTED") {
    throw "verify failed: $($verify | ConvertTo-Json -Compress)"
}
Write-Host "download_url: $($verify.download_url)"

Write-Step "Check has_access"
$checkRes = Invoke-NcCurlRequest -Url "$appBase/payment/check/$shareId`?provider_user_id=$buyerId"
$check = $checkRes | ConvertFrom-Json
if (-not $check.has_access) { throw "has_access should be true" }

Write-Step "Save to Nextcloud (logged-in user)"
$saveRes = Invoke-NcAppWebRequest -Uri "$appBase/s/$shareId/save-to-cloud" -Method POST `
    -Headers $headers -Body (@{ provider_user_id = $buyerId } | ConvertTo-Json -Compress) -Auth $auth
$save = $saveRes.Content | ConvertFrom-Json
if (-not $save.success) { throw "save-to-cloud failed: $($save.error)" }
Write-Host "saved: $($save.path)"

Write-Host ""
Write-Host "[F3] PASS: create -> pay -> download -> save-to-cloud" -ForegroundColor Green
} finally {
    if ($auth.CookieJar) {
        Remove-Item -LiteralPath $auth.CookieJar -Force -ErrorAction SilentlyContinue
    }
}
