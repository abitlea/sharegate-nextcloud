# F2 -> F3 -> F1 (native NC or Docker)
param(
    [string]$NcRoot = "",
    [string]$NcUrl = "",
    [string]$User = "admin",
    [string]$Password = "",
    [string]$PhpExe = "",
    [switch]$UseDocker,
    [switch]$CopyApp,
    [switch]$SkipPackage
)

$ErrorActionPreference = "Stop"
$here = $PSScriptRoot
$common = @{
    NcRoot = $NcRoot
    NcUrl = $NcUrl
    PhpExe = $PhpExe
}

if ($UseDocker) {
    & (Join-Path $here "f2-deploy-verify.ps1") -UseDocker -NcUrl $NcUrl
} else {
    & (Join-Path $here "f2-deploy-verify.ps1") @common -CopyApp:$CopyApp
}
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $here "f3-e2e-mock.ps1") @common -User $User -Password $Password
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if (-not $SkipPackage) {
    & (Join-Path $here "f1-package.ps1")
    exit $LASTEXITCODE
}
