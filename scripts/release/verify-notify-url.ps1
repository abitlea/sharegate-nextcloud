# Verify ShareGate Alipay notify endpoint on a deployed Nextcloud instance.
param(
    [string]$BaseUrl = "http://covevault.top/nextcloud"
)

$notifyUrl = "$($BaseUrl.TrimEnd('/'))/index.php/apps/sharegate/payment/notify/alipay_f2f"

Write-Host "GET  $notifyUrl"
$get = curl.exe -sS -m 20 -w "`nHTTP:%{http_code}" $notifyUrl
Write-Host $get
Write-Host ""

Write-Host "POST $notifyUrl"
$post = curl.exe -sS -m 20 -w "`nHTTP:%{http_code}" -X POST $notifyUrl
Write-Host $post
Write-Host ""

Write-Host "Expected after deploy:"
Write-Host "  GET  -> HTTP:200 + ShareGate alipay_f2f notify OK..."
Write-Host "  POST -> HTTP:200 + fail (plain text, not JSON login error)"
