# Changelog

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
