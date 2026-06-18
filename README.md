# ShareGate for Nextcloud

Nextcloud 应用：**安装启用即可用**，无需单独部署 Node 服务。

**当前版本** 1.3.4 — 管理台四页（你的共享 · 付费分享 · 账户绑定 · 收益查看）、买家付费页、**Stripe / PayPal / 支付宝当面付**、站内转存、中英双语界面。

## 要求

- Nextcloud 28 – 33
- PHP 8.2+（`openssl`、`mbstring`、`curl` 扩展；跑测试与 `composer install` 时需要）

## 安装

```bash
# 复制到 Nextcloud apps 目录（文件夹名必须为 sharegate）
cp -r sharegate-nextcloud /path/to/nextcloud/apps/sharegate
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev
```

管理后台 → **应用** → 启用 **ShareGate** → `php occ upgrade`（创建/更新 `sharegate_*` 表，含 `file_id` 等 migration）。

## 卖家使用（管理台）

1. 登录 Nextcloud
2. 顶栏 **付费分享**，或访问 `/index.php/apps/sharegate/`

多实例部署见 [docs/RELEASE.md](docs/RELEASE.md)（DietPi `/nextcloud` vs Docker `:8080`）。

3. 侧栏四页：
   - **公开链接** — 网盘文件，未分享行点「添加分享」
   - **付费分享** — 复制链接 / 编辑 / 取消分享
   - **账户绑定** — 管理员配置 **Stripe、PayPal 或支付宝**（Mock 仅开发环境）
   - **收益查看** — 预览、转存、下载次数

创建分享：`/apps/sharegate/embed/create`，支持 `?path=Documents/a.pdf&name=a.pdf` 预填。

## 买家使用

访问卖家短链 `/apps/sharegate/s/{shareId}`：

- **支付宝**：扫码支付
- **Stripe / PayPal**：跳转 Checkout 完成支付
- 支付成功后下载；已登录本站用户可「保存到我的 Nextcloud」（`ShareGate/` 目录）

## 支付配置

**Nextcloud 管理后台 → 设置 → 付费分享**，或管理台 **账户绑定** 页（管理员）。

| 方式 | 说明 |
|------|------|
| Mock | 开发测试，无真实扣款（生产站点不可选） |
| 支付宝当面付 | 国内买家，沙箱或生产；需配置异步通知 URL（公网可达） |
| Stripe Checkout | 国际卡/钱包，`sk_test_` / `sk_live_` + Webhook `checkout.session.completed` |
| PayPal Checkout | 国际买家，Sandbox Client ID/Secret + 可选 Webhook |

Webhook 说明与测试步骤见 [lib/Payment/README.md](lib/Payment/README.md)。  
国际化（`en` / `zh_CN`）见 [docs/I18N.md](docs/I18N.md)。

## 开发与测试

```bash
npm install
npm run build          # 生成 js/dashboard.js、js/download.js、l10n/*.js
composer install
composer test          # phpunit.xml.dist → tests/Unit/
```

任务清单：[docs/BACKLOG.md](docs/BACKLOG.md)  
发布验证（已有 NC）：[docs/RELEASE.md](docs/RELEASE.md) — `f2-deploy-verify.ps1 -NcRoot -NcUrl`  
上架检查：[docs/STORE.md](docs/STORE.md)

## 与 ShareGate monorepo 关系

| monorepo | 本仓库 |
|----------|--------|
| Node 服务 + AList | NC 原生 App |
| `apps/server/src/frontend/embed/*` | 同步 → `js/embed-create.js` 等 |
| `packages/core` | `lib/Service/*` PHP 移植 |

```powershell
powershell -ExecutionPolicy Bypass -File scripts/sync-from-sharegate.ps1
```

详见 [docs/PLAN.md](docs/PLAN.md)。

## 路线图

| 阶段 | 内容 | 状态 |
|------|------|------|
| 1 | 创建付费分享 + 买家页 | ✅ |
| 2 | Mock 支付 + 下载 | ✅ |
| 3 | 支付宝当面付 + 管理设置 | ✅ |
| 4 | 管理台四页 + 收益查看 + 转存 | ✅ |
| 5 | Stripe / PayPal + 中英 i18n + `file_id`（v1.3.4） | ✅ |
| 6 | 应用商店发布 | ⬜ 见 [STORE.md](docs/STORE.md) |
| 7 | 全站管理 API、Files 右键等 | ⬜ 二期 |
