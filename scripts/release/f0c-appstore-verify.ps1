# F0c: Verify ShareGate release on Nextcloud App Store + optional GitHub download check
param(
    [string]$AppId = 'sharegate',
    [string]$ExpectedVersion = '',
    [string]$NcPlatform = '33.0.0',
    [int]$DownloadTimeoutSec = 600,
    [switch]$SkipDownload,
    [switch]$CompareLocalArchive
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
if (-not $ExpectedVersion) {
    $infoXml = Join-Path $root 'appinfo\info.xml'
    $m = Select-String -Path $infoXml -Pattern '<version>(.*?)</version>' | Select-Object -First 1
    if ($m) { $ExpectedVersion = $m.Matches[0].Groups[1].Value.Trim() }
}
if (-not $ExpectedVersion) { throw 'Unable to determine expected version. Pass -ExpectedVersion.' }

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Write-Pass($msg) { Write-Host "[OK] $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "[WARN] $msg" -ForegroundColor Yellow }
function Write-Fail($msg) { Write-Host "[FAIL] $msg" -ForegroundColor Red }

$verifyPy = @'
import json, re, sys, tarfile, tempfile, urllib.request, ssl
from pathlib import Path

app_id = sys.argv[1]
expected = sys.argv[2]
platform = sys.argv[3]
skip_download = sys.argv[4] == '1'
compare_local = sys.argv[5] == '1'
local_archive = sys.argv[6]
timeout_sec = int(sys.argv[7])

def fetch(url):
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, context=ctx, timeout=timeout_sec) as resp:
        chunks = []
        while True:
            chunk = resp.read(1024 * 1024)
            if not chunk:
                break
            chunks.append(chunk)
        return b''.join(chunks)

store_url = f'https://apps.nextcloud.com/api/v1/platform/{platform}/apps.json'
print(f'STORE_URL={store_url}')
raw = fetch(store_url)
apps = json.loads(raw.decode('utf-8'))
app = next((a for a in apps if a.get('id') == app_id), None)
if not app:
    print('STORE_FOUND=0')
    sys.exit(2)
print('STORE_FOUND=1')
rels = app.get('releases') or []
print(f'RELEASE_COUNT={len(rels)}')
if not rels:
    print('NO_RELEASES=1')
    sys.exit(3)
rel = rels[0]
version = rel.get('version', '')
download = rel.get('download', '')
print(f'RELEASE_VERSION={version}')
print(f'RELEASE_DOWNLOAD={download}')
if version != expected:
    print(f'VERSION_MISMATCH=1 expected={expected} got={version}')
    sys.exit(4)
if not download.startswith('https://') or not download.endswith('.tar.gz'):
    print('BAD_DOWNLOAD_URL=1')
    sys.exit(5)
if skip_download:
    print('DOWNLOAD_SKIPPED=1')
    sys.exit(0)

data = fetch(download)
print(f'DOWNLOAD_BYTES={len(data)}')
tmp = Path(tempfile.gettempdir()) / f'{app_id}-store-verify.tgz'
tmp.write_bytes(data)
if not tarfile.is_tarfile(tmp):
    print('INVALID_TAR=1')
    sys.exit(6)
with tarfile.open(tmp, 'r:gz') as tar:
    names = tar.getnames()
    if f'{app_id}/appinfo/info.xml' not in names:
        print('MISSING_INFO_XML=1')
        sys.exit(7)
    info = tar.extractfile(f'{app_id}/appinfo/info.xml').read().decode('utf-8')
    m = re.search(r'<version>(.*?)</version>', info)
    pkg_ver = m.group(1) if m else ''
    print(f'PACKAGE_VERSION={pkg_ver}')
    junk = [n for n in names if '.cursor' in n or n.endswith('.tar.gz') or re.search(r'/temp_.*\.py$', n)]
    print(f'JUNK_COUNT={len(junk)}')
    for item in junk[:10]:
        print(f'JUNK={item}')
    if compare_local and local_archive and Path(local_archive).is_file():
        local_size = Path(local_archive).stat().st_size
        print(f'LOCAL_BYTES={local_size}')
        print(f'SIZE_MATCH={int(local_size == len(data))}')
'@

$pyFile = Join-Path $env:TEMP 'sharegate-store-verify.py'
Set-Content -Path $pyFile -Value $verifyPy -Encoding UTF8
$localArchive = Join-Path $root "release\$AppId-$ExpectedVersion.tar.gz"

Write-Step "App Store verify ($AppId $ExpectedVersion, NC $NcPlatform)"
$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$pyOut = & python $pyFile $AppId $ExpectedVersion $NcPlatform ($(if ($SkipDownload) { '1' } else { '0' })) ($(if ($CompareLocalArchive) { '1' } else { '0' })) $localArchive $DownloadTimeoutSec 2>&1
$pyExit = $LASTEXITCODE
$ErrorActionPreference = $prevEap
$pyOut | ForEach-Object { Write-Host $_ }

if ($pyExit -ne 0 -and -not ($pyOut -match '^STORE_FOUND=0')) {
    Write-Fail "Verification helper failed (exit $pyExit)."
    exit $pyExit
}

$parsed = @{}
foreach ($line in $pyOut) {
    if ($line -match '^([^=]+)=(.*)$') { $parsed[$Matches[1]] = $Matches[2] }
}

if ($parsed.STORE_FOUND -ne '1') {
    Write-Warn "Not listed for NC $NcPlatform yet. Trying 32.0.0..."
    if ($NcPlatform -ne '32.0.0') {
        & $PSCommandPath -AppId $AppId -ExpectedVersion $ExpectedVersion -NcPlatform '32.0.0' -DownloadTimeoutSec $DownloadTimeoutSec -SkipDownload:$SkipDownload -CompareLocalArchive:$CompareLocalArchive
        exit $LASTEXITCODE
    }
    Write-Fail 'App not found in App Store platform API.'
    exit 2
}

Write-Pass "Listed on App Store for NC $NcPlatform"
Write-Pass "Release $($parsed.RELEASE_VERSION) — $($parsed.RELEASE_DOWNLOAD)"

if ($parsed.DOWNLOAD_SKIPPED -eq '1') {
    Write-Pass 'Download check skipped.'
    exit 0
}

if ($parsed.INVALID_TAR -eq '1') {
    Write-Fail 'Downloaded file is not a valid tar.gz.'
    exit 6
}

Write-Pass "Downloaded $($parsed.DOWNLOAD_BYTES) bytes"
Write-Pass "Package version $($parsed.PACKAGE_VERSION)"

if ($parsed.JUNK_COUNT -and [int]$parsed.JUNK_COUNT -gt 0) {
    Write-Warn "Package contains $($parsed.JUNK_COUNT) dev/junk paths (repack with updated f1-package.ps1)"
    $pyOut | Where-Object { $_ -like 'JUNK=*' } | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
}

if ($CompareLocalArchive -and $parsed.SIZE_MATCH -eq '1') {
    Write-Pass 'Store download matches local archive size.'
}

Write-Host ''
Write-Host "[F0c] Store verification passed." -ForegroundColor Green
Write-Host "Store page: https://apps.nextcloud.com/apps/$AppId" -ForegroundColor Gray
