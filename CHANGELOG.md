# Changelog

## 1.3.5 — 2026-06-03

### Added
- **My purchases** — logged-in buyers see purchase history in the dashboard, download again within the access period, and save to cloud
- Buyer identity binds to the Nextcloud user ID when logged in; anonymous purchases can be linked after login

### Changed
- App Store listing copy: align **ShareGate** brand with **Paid sharing / 付费分享** in English and Chinese `summary` and `description` (e.g. `ShareGate (Paid sharing)`), so store title and body read as one product

### Fixed
- App Store listing i18n: `info.xml` uses **no `lang`** for English default (`name` / `summary` / `description`); `zh-hans` for Simplified Chinese (per [App Store developer guide](https://nextcloudappstore.readthedocs.io/en/latest/developer.html))

## 1.3.4 — 2026-06-03

### Added
- **Stripe Checkout** — international card/wallet payments (redirect + webhook)
- **PayPal Checkout** — international PayPal payments (redirect + webhook + capture)
- Payment provider catalog: Mock · Stripe · PayPal · Alipay Face-to-Face (China)
- Provider API fields: `providers`, `effective_provider_label`, `payment_flow`, `display_currency`
- Buyer page: server-side l10n, dynamic currency, QR (Alipay) vs redirect (Stripe/PayPal)
- **`file_id` binding** — paid shares persist Nextcloud file ID; download/save survives rename/move (path fallback for legacy rows)
- **Buyer save-to-cloud UI** — logged-in buyers copy purchased files to `ShareGate/` on the same Nextcloud instance

### Changed
- Payment system i18n: PHP `IL10N` for errors/messages; account binding UI translations
- `info.xml` bilingual store metadata (`en` + `zh_CN`) with save-to-cloud feature description
- Mock payment hidden from production admin UI (API retained for development/E2E)
- Buyer hint: “Scan with Alipay to pay” (Alipay); Stripe/PayPal redirect copy per provider
- PayPal/Stripe return URLs trigger payment confirmation polling

## 1.3.3 — 2026-06-16

### Changed
- 发布包剔除开发残留（`.cursor/`、嵌套 tar.gz、`temp_*.py`、前端构建源码等），体积约 62 MB → 7 MB

## 1.3.2 — 2026-06-13

### Added
- 买家页本地 QR 码生成（`chillerlan/php-qrcode`）、支付二维码图片 API
- 应用启动时加载 Composer `vendor/autoload.php`（支付宝 EasySDK）

### Changed
- 买家页使用 `RENDER_AS_BASE` 布局，修复样式与脚本加载
- 支付授权绑定浏览器 `buyerId`，不再误用支付宝 payer id
- 复制链接返回完整 URL（`linkToRouteAbsolute`）
- 分享时间显示精确到时分；下载留在当前页、卡片居中

### Fixed
- CSP 拦截内联 `onclick`、买家页 `bindActions` 作用域错误
- 支付宝密钥 PEM 头尾规范化；验签失败提示更明确
- 已支付但无授权记录时自动补建 `access_grant`

## 1.3.1 — 2026-06-07

### Changed
- 声明兼容 Nextcloud 33（`info.xml` max-version 30 → 33）
- 发布脚本默认 `NC_ROOT=/opt/nextcloud/html`（Docker compose 布局）

## 1.3.0 — 2026-06-03

### Added
- 管理台四页：公开链接（文件表）、付费分享、账户绑定、统计相关
- NC 全局左侧栏导航 + hash 路由
- `PublicLinkService` 网盘文件列表与分享状态匹配
- `sharegate_share_stats` 预览/转存/下载埋点
- 站内转存 API `POST /s/{id}/save-to-cloud`
- 管理员站点统计 API `GET /admin/stats`
- `docs/api-parity.md` API 对照文档
- PHPUnit 脚手架（`phpunit.xml.dist`、`tests/Unit/`、`tests/stubs/Entity.php`）
- 发布验证脚本（`scripts/release/`）、`docker/docker-compose.yml`、`krankerl.toml`
- 买家页与创建页 `OC.L10n` 文案（`download.js`、`embed-create.js`）

### Changed
- `/share/{id}/settings` 重定向至管理台 `?edit={id}#paid` + ocdialog
- 付费设置统一使用管理台弹层，废弃整页设置为主路径
- 应用描述更新为 WebUI 四页版本

### Fixed
- `ShareService::disableShare` 类外语法错误
- `DashboardService` 路径匹配与 `file_mtime` 读取

## 1.2.0

- 支付宝当面付沙箱、Mock 支付、管理台初版
