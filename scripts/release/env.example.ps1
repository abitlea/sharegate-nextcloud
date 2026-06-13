# Windows: F3 only (HTTP). F2 runs on NC server via f2-deploy-verify.sh
# Copy to env.local.ps1:  . .\scripts\release\env.local.ps1

# Base URL — NOT .../apps/dashboard/
$env:NC_URL  = "http://192.168.128.128/nextcloud"
$env:NC_PASSWORD = "your-password"
$env:NC_USER = "admin"

# F2 on server (SSH), not used by Windows occ:
# NC_ROOT=/opt/nextcloud
