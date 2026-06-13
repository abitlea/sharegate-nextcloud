#!/usr/bin/env bash
# Resolve Nextcloud paths (occ root, config.php).
# Docker-style layout: /opt/nextcloud/{html,config,data} with occ in html/

resolve_nc_root() {
  if [[ -n "${NC_ROOT:-}" && -f "${NC_ROOT}/occ" ]]; then
    echo "$NC_ROOT"
    return 0
  fi
  local base="${NC_BASE:-/opt/nextcloud}"
  if [[ -f "$base/html/occ" ]]; then
    echo "$base/html"
    return 0
  fi
  if [[ -f "$base/occ" ]]; then
    echo "$base"
    return 0
  fi
  return 1
}

resolve_nc_config() {
  local root="$1"
  local base="${NC_BASE:-/opt/nextcloud}"
  if [[ -f "$root/config/config.php" ]]; then
    echo "$root/config/config.php"
    return 0
  fi
  if [[ -L "$root/config" && -f "$(readlink -f "$root/config")/config.php" ]]; then
    echo "$(readlink -f "$root/config")/config.php"
    return 0
  fi
  if [[ -f "$base/config/config.php" ]]; then
    echo "$base/config/config.php"
    return 0
  fi
  return 1
}

# True when occ can run app:* (config reachable from html/)
nc_occ_ready() {
  local root="$1"
  [[ -f "$root/config/config.php" ]] || [[ -L "$root/config" ]]
}

# Export resolved paths when sourced
if NC_ROOT_RESOLVED="$(resolve_nc_root)"; then
  export NC_ROOT="$NC_ROOT_RESOLVED"
fi
if [[ -n "${NC_ROOT:-}" ]]; then
  if NC_CONFIG_RESOLVED="$(resolve_nc_config "$NC_ROOT")"; then
    export NC_CONFIG="$NC_CONFIG_RESOLVED"
  fi
fi
