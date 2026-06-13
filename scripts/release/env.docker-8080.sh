# Nextcloud #1 — Docker @ :8080
# /opt/nextcloud/{config,data,db,html}

export NC_BASE=/opt/nextcloud
export NC_ROOT=/opt/nextcloud/html
export NC_URL=http://192.168.128.128:8080
export NC_USER=admin
export NC_PASSWORD=change-me
export NC_OCC_USER=www-data

# occ 建议在容器内执行:
# cd /opt/nextcloud && docker compose exec -u www-data nextcloud php occ ...
