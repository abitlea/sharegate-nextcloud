# Shared helpers for native (non-Docker) Nextcloud installs.
# Dot-source: . (Join-Path $PSScriptRoot "nc-local.ps1")

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

function Resolve-PhpExe {
    param([string]$PhpExe = "")
    if ($PhpExe -and (Test-Path $PhpExe)) { return $PhpExe }
    if ($env:NC_PHP -and (Test-Path $env:NC_PHP)) { return $env:NC_PHP }
    $candidates = @(
        "D:\ProgramData\php-8.2.30\php.exe",
        "C:\php\php.exe"
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { return $c }
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw "PHP not found. Set -PhpExe or env NC_PHP to your Nextcloud PHP binary."
}

function Resolve-NcRoot {
    param([string]$NcRoot = "")
    if (-not $NcRoot) { $NcRoot = $env:NC_ROOT }
    if (-not $NcRoot) { throw "Set -NcRoot or env NC_ROOT to your Nextcloud install path (folder containing occ)." }
    $NcRoot = (Resolve-Path $NcRoot).Path
    if (-not (Test-Path (Join-Path $NcRoot "occ"))) {
        $htmlOcc = Join-Path $NcRoot "html\occ"
        if (Test-Path $htmlOcc) {
            return (Resolve-Path (Join-Path $NcRoot "html")).Path
        }
        throw "occ not found under: $NcRoot (try NC_ROOT=.../html for Docker layout)"
    }
    return $NcRoot
}

function Get-NcConfig {
    param([string]$NcRoot, [string]$PhpExe)
    $configFile = (Resolve-Path (Join-Path $NcRoot "config\config.php")).Path -replace '\\', '/'
    if (-not (Test-Path $configFile)) { throw "config.php not found: $configFile" }
    $code = @"
`$CONFIG = null;
include '$configFile';
echo json_encode([
  'datadirectory' => `$CONFIG['datadirectory'] ?? '',
  'dbtype' => `$CONFIG['dbtype'] ?? '',
  'dbname' => `$CONFIG['dbname'] ?? '',
  'dbhost' => `$CONFIG['dbhost'] ?? '',
  'dbuser' => `$CONFIG['dbuser'] ?? '',
  'dbpassword' => `$CONFIG['dbpassword'] ?? '',
], JSON_UNESCAPED_SLASHES);
"@
    $json = & $PhpExe -r $code 2>$null
    if (-not $json) { throw "Failed to read config.php via PHP" }
    return $json | ConvertFrom-Json
}

function Invoke-Occ {
    param(
        [string]$NcRoot,
        [string[]]$OccArgs,
        [string]$PhpExe
    )
    Push-Location $NcRoot
    try {
        & $PhpExe occ @OccArgs
        if ($LASTEXITCODE -ne 0) {
            throw "occ failed ($LASTEXITCODE): occ $($OccArgs -join ' ')"
        }
    } finally {
        Pop-Location
    }
}

function Get-SharegateAppDir {
    param([string]$NcRoot)
    $custom = Join-Path $NcRoot "custom_apps\sharegate"
    if (Test-Path $custom) { return $custom }
    $apps = Join-Path $NcRoot "apps\sharegate"
    if (Test-Path $apps) { return $apps }
    return $null
}

function Ensure-SharegateApp {
    param(
        [string]$NcRoot,
        [string]$SourceRoot,
        [switch]$ForceCopy
    )
    $dest = Get-SharegateAppDir -NcRoot $NcRoot
    if ($dest -and -not $ForceCopy) {
        Write-Host "ShareGate app found: $dest" -ForegroundColor Gray
        return $dest
    }
    $target = Join-Path $NcRoot "custom_apps\sharegate"
    Write-Step "Copy app to $target"
    New-Item -ItemType Directory -Path (Split-Path $target) -Force | Out-Null
    if (Test-Path $target) { Remove-Item $target -Recurse -Force }
    $exclude = @(".git", "release", "docker", "tests", "node_modules", ".phpunit.cache")
    robocopy $SourceRoot $target /E /XD $exclude /NFL /NDL /NJH /NJS /nc /ns /np | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }
    return $target
}

function Test-SharegateTables {
    param(
        [string]$NcRoot,
        [string]$PhpExe
    )
    $required = @(
        "sharegate_shares",
        "sharegate_payments",
        "sharegate_access_grants",
        "sharegate_share_stats"
    )
    $cfg = Get-NcConfig -NcRoot $NcRoot -PhpExe $PhpExe

    $mysql = Get-Command mysql -ErrorAction SilentlyContinue
    if ($mysql -and $cfg.dbtype -match "mysql|mariadb") {
        $host = ($cfg.dbhost -split ":")[0]
        $port = if ($cfg.dbhost -match ":(\d+)") { $Matches[1] } else { "3306" }
        $missing = @()
        foreach ($t in $required) {
            $sql = "SHOW TABLES LIKE '$t';"
            $out = & $mysql.Source -h $host -P $port -u $cfg.dbuser "-p$($cfg.dbpassword)" $cfg.dbname -N -e $sql 2>$null
            if ($out -notmatch $t) { $missing += $t }
        }
        if ($missing.Count -gt 0) { throw "Missing tables: $($missing -join ', ')" }
        return
    }

    Write-Warning "mysql client not in PATH; checking migration:status sharegate"
    Push-Location $NcRoot
    $status = & $PhpExe occ migration:status sharegate 2>&1 | Out-String
    Pop-Location
    foreach ($m in @("000001Date20250101000000", "000002Date20250603000000")) {
        if ($status -notmatch $m) {
            throw "Migration $m not applied. Run: cd $NcRoot; php occ upgrade"
        }
    }
    Write-Host "Migrations 001+002 applied (tables assumed created)" -ForegroundColor Gray
}

function New-NcTestFileRemote {
    param(
        [string]$BaseUrl,
        [string]$User,
        [string]$Pass,
        [string]$FileName,
        [string]$Content = "ShareGate E2E test"
    )
    $BaseUrl = $BaseUrl.TrimEnd('/')
    $url = "$BaseUrl/remote.php/dav/files/$User/$FileName"
    $tmp = [System.IO.Path]::GetTempFileName()
    try {
        Set-Content -LiteralPath $tmp -Value $Content -Encoding UTF8 -NoNewline
        $httpCode = (& curl.exe -sS -u "${User}:${Pass}" -X PUT $url `
            -H "Content-Type: text/plain" --data-binary "@$tmp" -w "%{http_code}" -o NUL 2>&1 | Out-String).Trim()
        if ($httpCode -notin @('201', '204')) {
            throw "WebDAV upload failed for $FileName (HTTP $httpCode). URL: $url"
        }
        Write-Host "Test file uploaded via WebDAV: $FileName" -ForegroundColor Gray
    } finally {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
    }
}

function New-NcTestFile {
    param(
        [string]$NcRoot,
        [string]$PhpExe,
        [string]$User,
        [string]$FileName,
        [string]$Content = "ShareGate E2E test"
    )
    $cfg = Get-NcConfig -NcRoot $NcRoot -PhpExe $PhpExe
    $filesDir = Join-Path $cfg.datadirectory $User "files"
    if (-not (Test-Path $filesDir)) {
        throw "User files dir not found: $filesDir (check -User or create account)"
    }
    $path = Join-Path $filesDir $FileName
    Set-Content -Path $path -Value $Content -Encoding UTF8 -NoNewline
    Invoke-Occ -NcRoot $NcRoot -PhpExe $PhpExe -OccArgs @("files:scan", $User)
    Write-Host "Test file: $path" -ForegroundColor Gray
}

function Wait-NextcloudUrl {
    param([string]$Url, [int]$TimeoutSec = 60)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        try {
            $r = Invoke-WebRequest -Uri "$Url/status.php" -UseBasicParsing -TimeoutSec 5
            if ($r.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Seconds 3
    }
    throw "Nextcloud URL not reachable: $Url/status.php"
}

function Get-NcRequestToken {
    param([string]$Html)
    if ($Html -match 'data-requesttoken="([^"]+)"') { return $Matches[1] }
    if ($Html -match "requestToken\s*:\s*'([^']+)'") { return $Matches[1] }
    if ($Html -match 'OC\.requestToken\s*=\s*"([^"]+)"') { return $Matches[1] }
    if ($Html -match '"requestToken"\s*:\s*"([^"]+)"') { return $Matches[1] }
    return $null
}

function Import-CurlCookieJarToWebSession {
    param(
        [string]$JarPath,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$BaseUrl
    )
    $baseUri = [Uri]$BaseUrl
    foreach ($line in Get-Content -LiteralPath $JarPath -ErrorAction SilentlyContinue) {
        if ($line.StartsWith('#') -or [string]::IsNullOrWhiteSpace($line)) { continue }
        $parts = $line -split "`t"
        if ($parts.Count -lt 7) { continue }
        $domain = $parts[0]
        $path = $parts[2]
        $name = $parts[5]
        $value = $parts[6]
        if ([string]::IsNullOrWhiteSpace($name)) { continue }
        $hostPart = if ($domain.StartsWith('.')) { $domain.TrimStart('.') } else { $domain }
        $portPart = if ($baseUri.IsDefaultPort) { '' } else { ':' + $baseUri.Port }
        $cookieUri = [Uri]::new($baseUri.Scheme + '://' + $hostPart + $portPart)
        $cookie = [System.Net.Cookie]::new($name, $value, $path, $cookieUri.Host)
        $null = $Session.Cookies.Add($cookieUri, $cookie)
    }
}

function Invoke-NcPublicJsonPost {
    param(
        [string]$Uri,
        [string]$Body
    )
    $bodyFile = [System.IO.Path]::GetTempFileName()
    try {
        [System.IO.File]::WriteAllText($bodyFile, $Body, [System.Text.UTF8Encoding]::new($false))
        $out = (& curl.exe -sS -X POST $Uri `
            -H 'Accept: application/json' `
            -H 'Content-Type: application/json' `
            --data-binary "@$bodyFile" 2>&1 | Out-String).Trim()
        if ($out -match '^curl:') { throw $out }
        return [PSCustomObject]@{ Content = $out }
    } finally {
        Remove-Item -LiteralPath $bodyFile -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-NcCurlRequest {
    param(
        [string]$Url,
        [string]$Method = 'GET',
        [string]$User = '',
        [string]$Pass = '',
        [string]$CookieJar = '',
        [hashtable]$Headers = @{},
        [string]$Body = ''
    )
    $curlArgs = @('-sS')
    if ($CookieJar) {
        $curlArgs += @('-b', $CookieJar, '-c', $CookieJar)
    } elseif ($User -and $Pass) {
        $curlArgs += @('-u', "${User}:${Pass}")
    }
    $curlArgs += @('-X', $Method, $Url)
    foreach ($entry in $Headers.GetEnumerator()) {
        $curlArgs += @('-H', "$($entry.Key): $($entry.Value)")
    }
    if ($Body) {
        if (-not $Headers.ContainsKey('Content-Type')) {
            $curlArgs += @('-H', 'Content-Type: application/json')
        }
        $bodyFile = [System.IO.Path]::GetTempFileName()
        try {
            [System.IO.File]::WriteAllText($bodyFile, $Body, [System.Text.UTF8Encoding]::new($false))
            $curlArgs += @('--data-binary', "@$bodyFile")
            $out = (& curl.exe @curlArgs 2>&1 | Out-String).Trim()
        } finally {
            Remove-Item -LiteralPath $bodyFile -Force -ErrorAction SilentlyContinue
        }
    } else {
        $out = (& curl.exe @curlArgs 2>&1 | Out-String).Trim()
    }
    if ($out -match '^curl:') { throw $out }
    return $out
}

function Invoke-NcAppWebRequest {
    param(
        [string]$Uri,
        [string]$Method = 'GET',
        [hashtable]$Headers = @{},
        [string]$Body = '',
        [hashtable]$Auth
    )
    if ($Auth.CookieJar) {
        $content = Invoke-NcCurlRequest -Url $Uri -Method $Method -CookieJar $Auth.CookieJar `
            -Headers $Headers -Body $Body
        return [PSCustomObject]@{ Content = $content }
    }
    if ($Auth.User -and $Auth.Pass) {
        $content = Invoke-NcCurlRequest -Url $Uri -Method $Method -User $Auth.User -Pass $Auth.Pass `
            -Headers $Headers -Body $Body
        return [PSCustomObject]@{ Content = $content }
    }

    $params = @{
        Uri = $Uri
        Method = $Method
        UseBasicParsing = $true
        WebSession = $Auth.Session
    }
    if ($Headers.Count -gt 0) { $params.Headers = $Headers }
    if ($Body) { $params.Body = $Body }
    return Invoke-WebRequest @params
}

function Test-NcBasicAuth {
    param([string]$BaseUrl, [string]$User, [string]$Pass)
    $userJson = (& curl.exe -sS -u "${User}:${Pass}" -H 'OCS-APIRequest: true' `
        "$BaseUrl/ocs/v2.php/cloud/user?format=json" 2>&1 | Out-String)
    if ($userJson -match 'curl:') { throw "Cannot reach OCS API: $userJson" }
    return ($userJson -match '"status":"ok"')
}

function Get-NcAppRequestToken {
    param(
        [string]$BaseUrl,
        [string]$User,
        [string]$Pass,
        [string]$AppRoute = 'index.php/apps/sharegate/'
    )
    $dashUri = "$BaseUrl/$AppRoute"
    $dashHtml = (& curl.exe -sS -u "${User}:${Pass}" $dashUri 2>&1 | Out-String)
    if ($dashHtml -match 'curl:') { throw "Cannot reach ShareGate app: $dashHtml" }
    if ($dashHtml -match '"message":"Current user is not logged in"') {
        throw "ShareGate rejected credentials. Check the app is enabled: $dashUri"
    }
    $rt = Get-NcRequestToken $dashHtml
    if (-not $rt) { throw "requesttoken not found at $dashUri" }
    return $rt
}

function Invoke-NcCookieLogin {
    param(
        [string]$BaseUrl,
        [string]$User,
        [string]$Pass,
        [string]$AppRoute = 'index.php/apps/sharegate/'
    )
    $jar = [System.IO.Path]::GetTempFileName()
    $loginReferer = "$BaseUrl/login"
    $baseUri = [Uri]$BaseUrl
    $originHeader = $baseUri.Scheme + '://' + $baseUri.Authority
    try {
        $loginHtml = (& curl.exe -sS -c $jar $loginReferer 2>&1 | Out-String)
        if ($loginHtml -match 'curl:') { throw "Cannot reach login page: $loginHtml" }

        $token = Get-NcRequestToken $loginHtml
        if (-not $token) { throw 'requesttoken not found on /login page' }

        $null = & curl.exe -sS -b $jar -c $jar -o NUL -X POST $loginReferer `
            -H "Origin: $originHeader" `
            -H "Referer: $loginReferer" `
            -H "Content-Type: application/x-www-form-urlencoded" `
            --data-urlencode "user=$User" `
            --data-urlencode "password=$Pass" `
            -d 'timezone=UTC' `
            -d 'timezone_offset=0' `
            --data-urlencode "requesttoken=$token" 2>&1

        $dashUri = "$BaseUrl/$AppRoute"
        $dashHtml = (& curl.exe -sS -b $jar -c $jar $dashUri 2>&1 | Out-String)
        if ($dashHtml -match 'curl:') { throw "Cannot reach ShareGate app: $dashHtml" }
        if ($dashHtml -match '"message":"Current user is not logged in"') {
            return $null
        }

        $userJson = (& curl.exe -sS -b $jar -H 'OCS-APIRequest: true' `
            "$BaseUrl/ocs/v2.php/cloud/user?format=json" 2>&1 | Out-String)
        if ($userJson -notmatch '"status":"ok"') {
            return $null
        }

        $rt = Get-NcRequestToken $dashHtml
        if (-not $rt) { throw "requesttoken not found after login at $dashUri" }

        $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        Import-CurlCookieJarToWebSession -JarPath $jar -Session $session -BaseUrl $BaseUrl

        return @{
            Session = $session
            CookieJar = $jar
            RequestToken = $rt
        }
    } catch {
        Remove-Item -LiteralPath $jar -Force -ErrorAction SilentlyContinue
        throw
    }
}

function Invoke-NcWebLogin {
    param(
        [string]$BaseUrl,
        [string]$User,
        [string]$Pass,
        [string]$AppRoute = 'index.php/apps/sharegate/'
    )
    if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
        throw 'curl.exe not found. Install Git for Windows or add curl to PATH.'
    }

    $BaseUrl = $BaseUrl.TrimEnd('/')

    $cookieAuth = $null
    try {
        $cookieAuth = Invoke-NcCookieLogin -BaseUrl $BaseUrl -User $User -Pass $Pass -AppRoute $AppRoute
    } catch {
        if ($_.Exception.Message -match 'ShareGate rejected') { throw }
    }

    if ($cookieAuth) {
        return @{
            Session = $cookieAuth.Session
            CookieJar = $cookieAuth.CookieJar
            User = $null
            Pass = $null
            RequestToken = $cookieAuth.RequestToken
        }
    }

    if (Test-NcBasicAuth -BaseUrl $BaseUrl -User $User -Pass $Pass) {
        throw "Credentials are valid (Basic Auth) but cookie login failed. NC 33 requires a browser-like login POST with Origin header; check brute-force lock or 2FA."
    }

    throw "Login failed for '$User'. Check NC_PASSWORD / NC_USER in env.local.ps1 (wrong password, 2FA, or brute-force lock)."
}
