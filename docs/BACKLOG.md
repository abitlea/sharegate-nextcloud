# ShareGate Nextcloud — 工作清单



> [RELEASE.md](RELEASE.md) · [STORE.md](STORE.md)



**最后同步**：2026-06-07 · **版本** 1.3.1  

**生产实例**：DietPi `http://192.168.128.128/nextcloud`（非 Docker :8080）



---



## 双 NC 说明（勿混用）



| 实例 | URL | 目录 | 状态 |

|------|-----|------|------|

| Docker | `:8080` | `/opt/nextcloud/html` | 可停用/忽略 ShareGate |

| **DietPi（生产）** | **`/nextcloud`** | **`/var/www/...`** | **在此部署** |



`/opt/nextcloud/html/custom_apps/sharegate` 只属于 :8080，**不影响** `/nextcloud`。



---



## DietPi 部署（从零）



### 1. 确认路径



```bash

bash scripts/release/discover-nc.sh

# 记下非 /opt/nextcloud 的 NC_ROOT

```



### 2. 配置环境



```bash

cp scripts/release/env.dietpi-nextcloud.sh scripts/release/env.local.sh

nano scripts/release/env.local.sh

# 设置 NC_ROOT（discover 结果）和 NC_PASSWORD

source scripts/release/env.local.sh

```



### 3. 一键部署



在服务器上（源码目录或 scp 后的路径）：



```bash

source scripts/release/env.local.sh

bash scripts/release/deploy-dietpi.sh

```



或手动：



```bash

export NC_ROOT=/var/www/nextcloud          # 以 discover 为准

export NC_URL=http://192.168.128.128/nextcloud



rsync -a /path/to/sharegate-nextcloud/ "$NC_ROOT/custom_apps/sharegate/"

cd "$NC_ROOT/custom_apps/sharegate" && composer install --no-dev

chown -R www-data:www-data "$NC_ROOT/custom_apps/sharegate"



cd "$NC_ROOT"

sudo -u www-data php occ app:enable sharegate

sudo -u www-data php occ upgrade

```



### 4. 访问



`http://192.168.128.128/nextcloud/index.php/apps/sharegate/`



### 5. F3 端到端（可选）



```bash

source scripts/release/env.local.sh

bash scripts/release/f3-e2e-mock.sh

```



---



## 从 Windows 上传代码



```powershell

scp -r e:\code\sharegate-nextcloud root@192.168.128.128:/tmp/sharegate-nextcloud

```



```bash

ssh root@192.168.128.128

source /tmp/sharegate-nextcloud/scripts/release/env.local.sh

bash /tmp/sharegate-nextcloud/scripts/release/deploy-dietpi.sh /tmp/sharegate-nextcloud

```



---



## 剩余工作



| 任务 | 状态 |

|------|------|

| DietPi 部署 + enable | 🟡 执行 `deploy-dietpi.sh` |

| F3 Mock 端到端 | 🟡 |

| 商店截图（/nextcloud UI） | [ ] |

| 清理 :8080 旧部署（可选） | [ ] |



---



## 阶段 A–E



四页主功能 ✅ · 发布包 ✅ · 目标 NC 33 + DietPi `/nextcloud`

