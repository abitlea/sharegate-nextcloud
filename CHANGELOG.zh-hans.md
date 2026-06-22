# 更新日志

## 1.3.6 — Unreleased

### 变更
- **Service 层 i18n** — `ShareService`、`DownloadService`、`DashboardService`、`PublicLinkService`、`ShareStatsService`、`ShareFileResolver` 的 API 错误与状态消息改用 `IL10N`（英文界面不再从 Service 层返回硬编码中文）

### 修复
- **创建 / 编辑付费分享** — 校验与 API 错误在侧栏（`NcNoteCard`）与 toast 中可见；移除 HTML5 `required` 导致的静默拦截提交
- **账户绑定** — 支付配置校验改为直接读表单值；Stripe/PayPal/支付宝必填项留空时显示 `NcNoteCard` 与 toast（修复 `NcTextField` 上 `reportValidity()` 无效）

## 1.3.5 — 2026-06-22

### 新增
- **我的已购** — 已登录买家可在管理台查看购买记录、在授权期内再次下载，并支持站内转存
- 买家身份：登录后绑定 Nextcloud 用户 ID；匿名购买可在登录后关联

### 变更
- 应用商店文案：在英文与中文 `summary`、`description` 中统一 **ShareGate** 品牌与 **Paid sharing / 付费分享** 功能名（如 `ShareGate (Paid sharing)`），避免标题与正文像两个产品

### 修复
- 应用商店 i18n：`info.xml` 英文 `name` / `summary` / `description` **不写 `lang`**，`zh-hans` 为简体中文（见 [App Store 开发者文档](https://nextcloudappstore.readthedocs.io/en/latest/developer.html)）

## 1.3.4 — 2026-06-18

### 新增
- **Stripe Checkout** — 国际卡/钱包支付（跳转 + Webhook）
- **PayPal Checkout** — 国际 PayPal 支付（跳转 + Webhook + 捕获）
- 支付渠道目录：Mock · Stripe · PayPal · 支付宝当面付（国内）
- 渠道 API 字段：`providers`、`effective_provider_label`、`payment_flow`、`display_currency`
- 买家页：服务端 l10n、动态货币、支付宝 QR 与 Stripe/PayPal 跳转
- **`file_id` 绑定** — 付费分享持久化 Nextcloud 文件 ID；重命名/移动后仍可下载/转存（旧数据路径回退）
- **买家站内转存 UI** — 已登录买家将已购文件复制到同一实例网盘 `ShareGate/` 目录

### 变更
- 支付系统 i18n：PHP `IL10N` 处理错误/消息；账户绑定界面翻译
- `info.xml` 双语商店 metadata（`en` + `zh-hans`），含站内转存说明
- 生产环境管理界面隐藏 Mock 支付（API 保留供开发/E2E）
- 买家提示：支付宝「扫码支付」；Stripe/PayPal 按渠道显示跳转文案
- PayPal/Stripe 回跳 URL 触发支付确认轮询

## 1.3.3 — 2026-06-16

### 变更
- 发布包剔除开发残留（`.cursor/`、嵌套 tar.gz、`temp_*.py`、前端构建源码等），体积约 62 MB → 7 MB

## 1.3.2 — 2026-06-13

### 新增
- 买家页本地 QR 码生成（`chillerlan/php-qrcode`）、支付二维码图片 API
- 应用启动时加载 Composer `vendor/autoload.php`（支付宝 EasySDK）

### 变更
- 买家页使用 `RENDER_AS_BASE` 布局，修复样式与脚本加载
- 支付授权绑定浏览器 `buyerId`，不再误用支付宝 payer id
- 复制链接返回完整 URL（`linkToRouteAbsolute`）
- 分享时间显示精确到时分；下载留在当前页、卡片居中

### 修复
- CSP 拦截内联 `onclick`、买家页 `bindActions` 作用域错误
- 支付宝密钥 PEM 头尾规范化；验签失败提示更明确
- 已支付但无授权记录时自动补建 `access_grant`

## 1.3.1 — 2026-06-07

### 变更
- 声明兼容 Nextcloud 33（`info.xml` max-version 30 → 33）
- 发布脚本默认 `NC_ROOT=/opt/nextcloud/html`（Docker compose 布局）

## 1.3.0 — 2026-06-03

### 新增
- 管理台四页：你的共享（文件表）、付费分享、账户绑定、收益查看
- NC 全局左侧栏导航 + hash 路由
- `PublicLinkService` 网盘文件列表与分享状态匹配
- `sharegate_share_stats` 预览/转存/下载埋点
- 站内转存 API `POST /s/{id}/save-to-cloud`
- 管理员站点统计 API `GET /admin/stats`
- `docs/api-parity.md` API 对照文档
- PHPUnit 脚手架（`phpunit.xml.dist`、`tests/Unit/`、`tests/stubs/Entity.php`）
- 发布验证脚本（`scripts/release/`）、`docker/docker-compose.yml`、`krankerl.toml`
- 买家页与创建页 `OC.L10n` 文案（`download.js`、`embed-create.js`）

### 变更
- `/share/{id}/settings` 重定向至管理台 `?edit={id}#paid` + ocdialog
- 付费设置统一使用管理台弹层，废弃整页设置为主路径
- 应用描述更新为 WebUI 四页版本

### 修复
- `ShareService::disableShare` 类外语法错误
- `DashboardService` 路径匹配与 `file_mtime` 读取

## 1.2.0

- 支付宝当面付沙箱、Mock 支付、管理台初版
