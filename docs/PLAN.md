# ShareGate Nextcloud App — 整体规划

> 独立仓库：`sharegate-nextcloud`（与 `ShareGate` monorepo 并列）  
> 目标：**应用商店安装 → 启用即用**，无需单独启动 Node 服务、无需反代。

---

## 1. 产品定位

| 维度 | ShareGate Server（现有 monorepo） | sharegate-nextcloud（本仓库） |
|------|-----------------------------------|-------------------------------|
| 运行时 | Node.js Express | **Nextcloud PHP 进程内** |
| 安装 | 手动部署 / Docker | **应用商店 → 启用** |
| 存储 | 插件 `adapters/*` | **Nextcloud 原生 Files API** |
| 买家页 | `/s/:id` | `/apps/sharegate/s/{id}` |
| 代码复用 | — | **业务规则与 schema 对齐 monorepo** |

---

## 2. 代码复用策略

PHP 无法直接 `require` TypeScript，复用分三层：

```
ShareGate monorepo (e:\code\ShareGate)
├── apps/server/src/frontend/embed/   → create.html/css/js（共享 UI）
├── apps/server/src/frontend/download.html → 买家页（阶段 2 同步）
├── packages/core                       → 业务规则「源真相」
├── packages/payment-interface          → 接口契约 → lib/Payment/Contract/
├── payments/alipay-f2f                 → 阶段 3 移植为 PHP
└── adapters/nextcloud                  → lib/Service/ShareService + IFile

sharegate-nextcloud
├── frontend/embed/                     ← sync 脚本归档（与 monorepo 一致）
├── js/embed-create.js                  ← NC 加载的 create.js 副本
├── css/embed-create.css                ← NC 加载的 create.css 副本
├── templates/embed/create.php          ← 同 DOM 结构 + NC 配置注入
├── lib/Service/ShareService.php        ← LinkManager
└── scripts/sync-from-sharegate.ps1     ← 一键同步前端
```

**前端同步**（monorepo 改 UI 后执行）：

```powershell
cd e:\code\sharegate-nextcloud
powershell -ExecutionPolicy Bypass -File scripts/sync-from-sharegate.ps1
```

**原则**：字段名、金额单位（分）、`share_id` 长度（16）、状态枚举与 monorepo 保持一致；`ShareGateEmbedCreate.init()` 通过 `authType: ncCsrf` 适配 NC。

---

## 3. Nextcloud App 规范清单（必须满足）

| 项 | 路径 | 状态 |
|----|------|------|
| 应用元数据 | `appinfo/info.xml` | ✅ 已有 |
| 路由 | `appinfo/routes.php` | ✅ 已有 |
| 引导 | `lib/AppInfo/Application.php` | 🔨 本期生成 |
| PSR-4 | `composer.json` → `OCA\ShareGate\` | 🔨 本期生成 |
| 数据库迁移 | `lib/Migration/Version*.php` | ✅ 已有，本期对齐 schema |
| Controller | `lib/Controller/*Controller.php` | 🔨 本期 ShareController |
| Service | `lib/Service/*` | 🔨 本期 ShareService |
| Entity + Mapper | `lib/Db/*` | 🔨 本期 |
| 模板 | `templates/` | 🔨 本期 embed/create |
| 国际化 | `l10n/zh_CN.json`, `l10n/en.json` | 🔨 本期 |
| 管理设置 | `lib/Settings/Admin*.php` | 🔨 占位（info.xml 已引用） |
| 签名 / 发布 | `.github/workflows`, `krankerl` | 四期（应用商店） |

依赖：`nextcloud min-version 28 max-version 30`（与 info.xml 一致）。

---

## 4. 分阶段实施（严格顺序）

### 阶段 1 — 创建付费分享页

- [x] 目录骨架
- [x] 复用 monorepo `embed/create.*` 前端
- [x] `GET /apps/sharegate/embed/create`
- [x] `POST /apps/sharegate/share/create`
- [x] 侧边栏导航入口
- [x] 管理台骨架（`/apps/sharegate/`）— **WebUI 四页改版**见 [docs/WEBUI-DESIGN.md](WEBUI-DESIGN.md)、[docs/BACKLOG.md](BACKLOG.md)

### 阶段 2 — 买家页 + Mock 支付

- [x] 买家页、Mock 支付、文件下载（见上）

### 阶段 3 — 管理设置 + 支付宝沙箱（**当前**）

- [x] `lib/Settings/Admin.php` — 支付宝当面付配置表单
- [x] `PaymentConfigService` — IConfig 持久化（App ID / 私钥 / 公钥 / 沙箱）
- [x] `AlipayF2fProvider.php` — `alipay.trade.precreate` + notify 验签
- [x] `PaymentService` — mock / alipay_f2f 按配置切换
- [x] `POST /payment/notify/alipay_f2f` — 异步通知
- [x] `composer.json` — `alipaysdk/easysdk`
- [ ] 管理后台分享/订单/统计 API（可选，后续）

**验收**：管理后台填写沙箱密钥 → 支付模式选「支付宝当面付」→ 买家扫码真实沙箱 QR → 支付后自动解锁下载

### 阶段 4 — 应用商店发布

- [ ] `info.xml` 完善、截图、CHANGELOG
- [ ] Krankerl 打包、`apps.nextcloud.com` 提交

### 阶段 5 — WebUI 四页（**见 [WEBUI-DESIGN.md](WEBUI-DESIGN.md)**）

- [x] ~~Files 右键~~ — **取消**；改 **公开链接** 页「添加分享」
- [x] NC 全局左侧栏四项 + 主区 `#app-content` + hash 路由（2026-06-03）
- [ ] 四页主内容：公开链接（文件表）/ 付费分享（§4.2 列）/ 账户绑定（内嵌表单）/ 统计相关（文件表）
- [ ] 统计表 + `sharegate_share_stats` 埋点
- [ ] 实施阶段 A–E 见 [BACKLOG.md](BACKLOG.md)（**下一步：阶段 A 付费分享表**）

---

## 5. URL 与路由（NC 自动生成前缀 `/apps/sharegate`）

| 路由名 | 方法 | 路径 | 阶段 |
|--------|------|------|------|
| `dashboard#index` | GET | `/` | 管理台 |
| `dashboard#summary` | GET | `/api/dashboard/summary` | 管理台 |
| `dashboard#list` | GET | `/api/dashboard/shares` | 管理台 |
| `share#createEmbed` | GET | `/embed/create` | 1 |
| `share#createShare` | POST | `/share/create` | 1 |
| `share#view` | GET | `/s/{shareId}` | 2 |
| `payment#create` | POST | `/payment/create` | 2 |
| `payment#notify` | POST | `/payment/notify/{provider}` | 3 |

---

## 6. 目录检查（2025-05 现状）

**已有文件：**

- `appinfo/info.xml`, `appinfo/routes.php`
- `lib/Migration/Version000001Date20250101000000.php`
- `lib/Db/Share.php`（占位内容 `test`，需替换）

**空目录 / 缺失（本期补齐）：**

- `lib/AppInfo/Application.php`
- `lib/Controller/ShareController.php`
- `lib/Db/ShareMapper.php`, `Payment.php`, `AccessGrant.php`…
- `lib/Service/ShareService.php`
- `lib/Settings/Admin.php`, `AdminSection.php`
- `templates/embed/create.php`
- `l10n/*`, `composer.json`, `README.md`
- `js/`, `src/` — 预留阶段 5，一期可不填

**可删除：** `test.txt`（占位）

---

## 7. 本地开发

```bash
# 1. 克隆到 Nextcloud apps 目录
cp -r sharegate-nextcloud /path/to/nextcloud/apps/sharegate
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev

# 2. Nextcloud 管理后台 → 应用 → 启用 ShareGate

# 3. 访问
https://your-nc/apps/sharegate/embed/create
```

日志：`nextcloud/data/nextcloud.log`  
数据库迁移：启用 App 时自动执行 `Version000001*`.

---

## 8. 与 monorepo 协作

- **Issue / 版本**：App `info.xml` version 独立 semver，业务变更同步更新 `docs/api-parity.md`
- **不要**在 NC App 内启动 Node；所有 HTTP 对外仅 NC 域名
- monorepo 的 `apps/server` 继续服务 AList / 自托管场景；NC App 是另一条产品线
