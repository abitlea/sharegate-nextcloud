# Copy to env.local.sh — pick ONE Nextcloud instance (see discover-nc.sh)
#
# Instance A — Docker :8080  →  source env.docker-8080.sh
# Instance B — DietPi /nextcloud  →  source env.dietpi-nextcloud.sh

export NC_BASE=/opt/nextcloud
export NC_ROOT=/opt/nextcloud/html
export NC_URL=http://192.168.128.128:8080
export NC_USER=admin
export NC_PASSWORD=change-me
export NC_OCC_USER=www-data
