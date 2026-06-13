# ShareGate 发布与验证流程

按顺序：**F2 部署验证 → F3 端到端 → F1 打包上架**

---

## 已有 Nextcloud（非 Docker）— 推荐

### ⚠️ 双 Nextcloud 实例（同机多套）

**192.168.128.128** 上常见两套 NC，**URL 与目录必须一一对应**：

| 实例 | URL | 目录 |
|------|-----|------|
| Docker | `http://192.168.128.128:8080/` | `/opt/nextcloud/html`（`config` 在 `/opt/nextcloud/config`） |
| DietPi | `http://192.168.128.128/nextcloud` | 多在 `/var/www/nextcloud` 等（**不是** `/opt/nextcloud`） |

```bash
bash scripts/release/discover-nc.sh   # 列出本机所有 occ 路径
```

ShareGate 若装在 `/opt/nextcloud/html/custom_apps/sharegate`，只在 **:8080** 实例生效。  

**生产推荐（DietPi `/nextcloud`）：**

```bash
bash scripts/release/discover-nc.sh
cp scripts/release/env.dietpi-nextcloud.sh scripts/release/env.local.sh
# 编辑 NC_ROOT + NC_PASSWORD
source scripts/release/env.local.sh
bash scripts/release/deploy-dietpi.sh
```

访问：`http://192.168.128.128/nextcloud/index.php/apps/sharegate/`

环境模板：`env.dietpi-nextcloud.sh`（生产）· `env.docker-8080.sh`（备用）

### 1. 部署应用

```text
/opt/nextcloud/html/custom_apps/sharegate/   ← 本仓库内容
```

在 **NC 服务器**上（SSH）：

```bash
cd /path/to/sharegate-nextcloud
cp scripts/release/env.example.sh scripts/release/env.local.sh
# 编辑 NC_PASSWORD
source scripts/release/env.local.sh
bash scripts/release/deploy-to-nc.sh
bash scripts/release/f2-deploy-verify.sh
bash scripts/release/f3-e2e-mock.sh
```

或用脚本首次拷贝：

```powershell
cd e:\code\sharegate-nextcloud
powershell -ExecutionPolicy Bypass -File scripts\release\f2-deploy-verify.ps1 `
  -NcRoot "D:\path\to\nextcloud" `
  -NcUrl "https://your-nc.example.com" `
  -CopyApp
```

> **NcUrl** 须与浏览器访问地址一致（含子目录，如 `https://example.com/nextcloud`）。

### 2. 环境变量（可选，避免每次传参）

```powershell
$env:NC_ROOT = "D:\path\to\nextcloud"      # 含 occ 的目录
$env:NC_URL  = "https://your-nc.example.com"
$env:NC_PASSWORD = "your-admin-password"
# 可选：NC 使用的 PHP
$env:NC_PHP = "C:\php\php.exe"
```

### 3. F2 — 部署验证

**Linux NC 服务器**（推荐，`/opt/nextcloud`）：

```bash
source scripts/release/env.local.sh
bash scripts/release/f2-deploy-verify.sh
```

**Windows 本机**（仅当 NC_ROOT 在本机可访问时，如 WSL 挂载）：

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release\f2-deploy-verify.ps1 `
  -NcRoot $env:NC_ROOT -NcUrl $env:NC_URL
```

验收：

- `occ app:enable sharegate` 成功
- `occ upgrade` 成功，migration 001 + 002 已执行
- 存在 4 张表（有 `mysql` 客户端时直接查表；否则以 migration 状态为准）

手动等价命令：

```bash
cd /opt/nextcloud/html
sudo -u www-data php occ status
sudo -u www-data php occ app:enable sharegate
sudo -u www-data php occ upgrade
sudo -u www-data php occ app:list --enabled | grep sharegate
```

### `occ app:enable` 报 `There are no commands defined in the "app" namespace`

说明 **`html/config/config.php` 不存在**，occ 处于「未安装」精简模式。

Docker 常见布局：`config/` 在 `/opt/nextcloud/config`，但 `html/config` 未链接。

**诊断：**

```bash
ls -la /opt/nextcloud/html/config
ls -la /opt/nextcloud/config/config.php
bash scripts/release/diagnose-nc.sh
```

**若 `html/config/config.php` 大小为 0**（空文件），必须先删掉再链接真实配置：

```bash
wc -c /opt/nextcloud/html/config/config.php   # 若为 0 → 坏了
wc -c /opt/nextcloud/config/config.php        # 应 >> 1KB

rm -rf /opt/nextcloud/html/config
ln -sfn /opt/nextcloud/config /opt/nextcloud/html/config
```

**修复（在服务器上）：**

```bash
# 若 html/config 不存在或为空，链到上级 config
ln -sfn /opt/nextcloud/config /opt/nextcloud/html/config

# 或用脚本
bash scripts/release/fix-nc-config-link.sh

cd /opt/nextcloud/html
sudo -u www-data php occ status
sudo -u www-data php occ app:enable sharegate
```

### `Could not download app sharegate, it was not found on the appstore`

说明 **本机没有** `custom_apps/sharegate` 应用文件，`occ` 才会去商店下载（商店里也没有）。

```bash
# 1. 部署代码（在开发机打包后 scp，或在服务器 git clone）
mkdir -p /opt/nextcloud/html/custom_apps/sharegate
# 将 sharegate-nextcloud 仓库内容复制到该目录

# 2. 依赖与权限
cd /opt/nextcloud/html/custom_apps/sharegate
composer install --no-dev
chown -R www-data:www-data .

# 3. 确认存在
test -f appinfo/info.xml && grep max-version appinfo/info.xml

# 4. 启用
cd /opt/nextcloud/html
sudo -u www-data php occ app:enable sharegate
sudo -u www-data php occ upgrade
```

### `PDOException: could not find driver`

宿主机 CLI 的 PHP **未启用** `pdo_mysql`（网页能开但 `occ` 失败时很常见）。

**方案 A — NC 在 Docker 里跑（推荐先试）：**

```bash
cd /opt/nextcloud
docker compose ps
docker compose exec -u www-data nextcloud php occ app:enable sharegate
docker compose exec -u www-data nextcloud php occ upgrade
```

**方案 B — 在 DietPi/Debian 宿主机装驱动：**

```bash
sudo -u www-data php -m | grep -i pdo
php -v

# 按 PHP 版本安装，例如 8.2：
sudo apt update
sudo apt install php8.2-mysql
# 或: apt install php-mysql

sudo -u www-data php -m | grep pdo_mysql
```

然后再：

```bash
cd /opt/nextcloud/html
sudo -u www-data php occ app:enable sharegate
```

### 服务器上仍是 `max-version="30"`

NC 33 需 **1.3.1** 包，在服务器执行：

```bash
sed -i 's/max-version="30"/max-version="33"/' \
  /opt/nextcloud/html/custom_apps/sharegate/appinfo/info.xml
sed -i 's/<version>1.3.0<\/version>/<version>1.3.1<\/version>/' \
  /opt/nextcloud/html/custom_apps/sharegate/appinfo/info.xml
grep -E 'version|max-version' /opt/nextcloud/html/custom_apps/sharegate/appinfo/info.xml
```

若 NC 跑在 **Docker 容器内**，也可在容器里执行 occ：

```bash
cd /opt/nextcloud
docker compose exec -u www-data nextcloud php occ app:enable sharegate
```

### `occ status` 里 `installed: false`

若同时满足：

- 浏览器能打开 NC 并登录
- `needsDbUpgrade: false`
- `version` 有正常版本号（如 33.0.4）

则 **可继续** `app:enable sharegate`，不必纠结 `installed` 字段。  
ShareGate 需 **NC 28–33**（`info.xml` max-version）；NC 33 请用 **v1.3.1+** 应用包。

### 4. F3 — Mock 端到端

**NC 服务器**：

```bash
source scripts/release/env.local.sh
bash scripts/release/f3-e2e-mock.sh
```

**Windows 本机**（仅 HTTP，需网盘里已有测试文件或先在服务器跑过 F3 前半段）：

```powershell
. .\scripts\release\env.local.ps1
powershell -File scripts\release\f3-e2e-mock.ps1 -NcUrl $env:NC_URL -Password $env:NC_PASSWORD
```

脚本会：在用户网盘创建测试文件 → 创建付费分享 → Mock 支付 → 验证下载 → 转存到 `ShareGate/`。

### 5. 一键 F2+F3+F1

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release\verify-all.ps1 `
  -NcRoot $env:NC_ROOT -NcUrl $env:NC_URL -User admin -Password $env:NC_PASSWORD
```

---

## Docker 环境（可选）

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release\verify-all.ps1 -UseDocker
```

默认 `http://localhost:8088`，admin/admin。

---

## F1 — 打包上架

```powershell
powershell -ExecutionPolicy Bypass -File scripts\release\f1-package.ps1
# 输出: release/sharegate-1.3.0.tar.gz
```

上架清单：[STORE.md](STORE.md)

## 截图

登录你的 NC，截取 5 张图至 `release/screenshots/`（见 STORE.md）。

## 本机 PHP（composer / phpunit）

```powershell
D:\ProgramData\php-8.2.30\php.exe -c scripts\php-dev.ini composer.phar install
D:\ProgramData\php-8.2.30\php.exe -c scripts\php-dev.ini vendor\bin\phpunit
```
