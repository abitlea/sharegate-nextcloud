# DietPi Nextcloud — 长期使用 /nextcloud（非 Docker :8080)
# 1. bash scripts/release/discover-nc.sh   # 确认 NC_ROOT
# 2. 编辑 NC_PASSWORD
# 3. source scripts/release/env.dietpi-nextcloud.sh

export NC_URL=http://192.168.128.128/nextcloud

# discover-nc.sh 输出为准，常见：
export NC_ROOT=/var/www/nextcloud
# export NC_ROOT=/var/www/html/nextcloud

export NC_USER=admin
export NC_PASSWORD=change-me
export NC_OCC_USER=www-data
