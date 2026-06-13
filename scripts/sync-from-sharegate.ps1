# 从 ShareGate monorepo 同步共享前端资源
#
# 用法（在 sharegate-nextcloud 目录执行）：
#   powershell -ExecutionPolicy Bypass -File scripts/sync-from-sharegate.ps1
#
# 指定 monorepo 路径：
#   powershell -File scripts/sync-from-sharegate.ps1 -ShareGateRoot "e:\code\ShareGate"

param(
    [string]$ShareGateRoot = ""
)

$DstRoot = Split-Path $PSScriptRoot -Parent

if (-not $ShareGateRoot) {
    $candidate = Join-Path (Split-Path $DstRoot -Parent) "ShareGate"
    if (Test-Path $candidate) {
        $ShareGateRoot = (Resolve-Path $candidate).Path
    }
}

if (-not $ShareGateRoot -or -not (Test-Path $ShareGateRoot)) {
    Write-Error "找不到 ShareGate 目录。请用 -ShareGateRoot 指定，例如: e:\code\ShareGate"
    exit 1
}

$ShareGateRoot = (Resolve-Path $ShareGateRoot).Path
$SrcFrontend = Join-Path $ShareGateRoot "apps\server\src\frontend"

Write-Host "同步自: $SrcFrontend"
Write-Host "同步到: $DstRoot"

function Copy-IfExists($rel) {
    $src = Join-Path $SrcFrontend $rel
    if (-not (Test-Path $src)) {
        Write-Warning "跳过（不存在）: $rel"
        return $null
    }
    return $src
}

$embedDst = Join-Path $DstRoot "frontend\embed"
New-Item -ItemType Directory -Force -Path $embedDst | Out-Null

foreach ($f in @("create.html", "create.css", "create.js")) {
    $src = Copy-IfExists "embed\$f"
    if ($src) {
        Copy-Item $src (Join-Path $embedDst $f) -Force
        Write-Host "  frontend/embed/$f"
    }
}

$dlSrc = Copy-IfExists "download.html"
if ($dlSrc) {
    New-Item -ItemType Directory -Force -Path (Join-Path $DstRoot "frontend") | Out-Null
    Copy-Item $dlSrc (Join-Path $DstRoot "frontend\download.html") -Force
    Write-Host "  frontend/download.html"
}

$jsDst = Join-Path $DstRoot "js"
$cssDst = Join-Path $DstRoot "css"
New-Item -ItemType Directory -Force -Path $jsDst, $cssDst | Out-Null

$jsSrc = Join-Path $embedDst "create.js"
$cssSrc = Join-Path $embedDst "create.css"
if (Test-Path $jsSrc) {
    Copy-Item $jsSrc (Join-Path $jsDst "embed-create.js") -Force
    Write-Host "  js/embed-create.js"
}
if (Test-Path $cssSrc) {
    Copy-Item $cssSrc (Join-Path $cssDst "embed-create.css") -Force
    Write-Host "  css/embed-create.css"
}

# 买家页 download.*
$dlJs = Join-Path $SrcFrontend "download.js"
$dlCss = Join-Path $SrcFrontend "download.css"
if (Test-Path $dlJs) {
    Copy-Item $dlJs (Join-Path $jsDst "download.js") -Force
    Write-Host "  js/download.js"
}
if (Test-Path $dlCss) {
    Copy-Item $dlCss (Join-Path $cssDst "download.css") -Force
    Write-Host "  css/download.css"
}

Write-Host "完成。请检查 js/、css/ 变更后提交。"
