# ShareGate for Nextcloud

> **语言：** [English](README.md) · 简体中文（本页）

**在 Nextcloud 里卖文件 — 内置 Stripe 与 PayPal，无需额外服务器。**

ShareGate（**付费分享**）让你在网盘内为文件生成**付费下载链接**。安装并启用即可使用；文件与买家访问权限留在**你的** Nextcloud 实例上（自托管、AGPL）。

### 为什么选择 ShareGate？

| | |
|---|---|
| **自托管** | 数字商品留在你的服务器 — 若已使用 Nextcloud，可替代 Gumroad 类 SaaS |
| **Stripe 与 PayPal** | 国际买家熟悉的 Checkout + Webhook（可选支付宝当面付，面向国内卖家） |
| **卖家管理台** | 浏览文件、创建付费链接、设价格/授权天数/有效期、收款明细与统计 |
| **买家体验** | 简洁付费页 → 支付 → 下载；**我的已购**与同一实例内可选站内转存 |
| **无第二套服务** | 纯 PHP 应用，运行在 Nextcloud 内 — 无需部署 Node.js ShareGate 服务 |

**安装：** Nextcloud **应用** → **付费分享**（商店上架后）或下方 [手动安装](#安装)（GitHub）。

**当前版本** 1.3.6 — 管理台（你的共享 · 付费分享 · 账户绑定 · 收款明细 · 收益查看）、买家 **我的已购**、付费页、**Stripe / PayPal**（+ 支付宝当面付）、站内转存、中英双语界面（`en` / `zh_CN`）；Service 层 API 与表单校验错误已完整本地化。

**链接：** [GitHub](https://github.com/abitlea/sharegate-nextcloud) · [Issues 与反馈](https://github.com/abitlea/sharegate-nextcloud/issues) · [应用商店](https://apps.nextcloud.com/apps/sharegate)（上架后）

---

## 要求

- Nextcloud 28 – 33
- PHP 8.2+（需 `openssl`、`mbstring`、`curl` 扩展；跑测试与 `composer install` 时需要）

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

3. 侧栏页面：
   - **你的共享** — 网盘文件，未分享行点「添加分享」
   - **付费分享** — 复制链接 / 编辑 / 取消分享
   - **账户绑定** — 管理员配置 **Stripe、PayPal 或支付宝**（Mock 仅开发环境）
   - **收款明细** — 按订单查看收款记录、支付账号、金额与状态
   - **收益查看** — 预览、转存、下载次数

创建分享：`/apps/sharegate/embed/create`，支持 `?path=Documents/a.pdf&name=a.pdf` 预填。

## 买家使用

访问卖家短链 `/apps/sharegate/s/{shareId}`：

- **Stripe / PayPal**：跳转 Checkout 完成支付（面向国际买家）
- **支付宝**：扫码支付（面向国内卖家）
- 支付成功后下载；已登录本站用户可「保存到我的 Nextcloud」（`ShareGate/` 目录）
- 已登录用户可在买家下载页通过 **我的已购** 查看购买记录并在有效期内再次下载

## 支付配置

**Nextcloud 管理后台 → 设置 → 付费分享**，或管理台 **账户绑定** 页（管理员）。

| 方式 | 说明 |
|------|------|
| Mock | 开发测试，无真实扣款（生产站点不可选） |
| 支付宝当面付 | 国内买家，沙箱或生产；需配置异步通知 URL（公网可达） |
| Stripe Checkout | 国际卡/钱包，`sk_test_` / `sk_live_` + Webhook `checkout.session.completed` |
| PayPal Checkout | 国际买家，Sandbox Client ID/Secret + 可选 Webhook |

Webhook 说明与测试步骤见 [lib/Payment/README.md](lib/Payment/README.md)。  
应用内界面国际化见 [docs/I18N.md](docs/I18N.md)。

## 开发与测试

```bash
npm install
npm run build          # 生成 js/dashboard.js、js/download.js、l10n/*.js
composer install
composer test          # phpunit.xml.dist → tests/Unit/
```

任务清单：[docs/BACKLOG.md](docs/BACKLOG.md)  
发布验证（已有 NC）：[docs/RELEASE.md](docs/RELEASE.md)  
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
