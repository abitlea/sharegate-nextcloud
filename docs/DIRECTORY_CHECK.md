# 目录检查报告

**生成时间**：2026-06-03 · **版本** 1.3.0

## 仓库

`e:\code\sharegate-nextcloud`

## 核心路径状态

| 路径 | 状态 | 说明 |
|------|------|------|
| `appinfo/info.xml` | ✅ | v1.3.0，`<navigations>` 顶栏 |
| `appinfo/routes.php` | ✅ | 管理台、分享、支付、公开链接、转存 |
| `lib/Listener/RegisterNavigationListener.php` | ✅ | NC 全局左侧栏四项 |
| `lib/Controller/DashboardController.php` | ✅ | 管理台页面与 API |
| `lib/Controller/FilesController.php` | ✅ | `publicLinks` → `PublicLinkService` |
| `lib/Service/PublicLinkService.php` | ✅ | 网盘文件 + 分享匹配 |
| `lib/Service/ShareStatsService.php` | ✅ | 统计读写 |
| `lib/Migration/Version000002...` | ✅ | `sharegate_share_stats` |
| `templates/dashboard/index.php` | ✅ | 仅 `#app-content` |
| `js/dashboard.js` | ✅ | 四视图、ocdialog、分页、l10n |
| `js/download.js` | ✅ | 买家页 + l10n |
| `tests/Unit/ShareStatsEntityTest.php` | ✅ | 单元测试脚手架 |
| `templates/share/settings.php` | ✅ | 已删除，整页设置已废弃 |
| `js/share-settings.js` | ✅ | 已删除，好用 `dashboard` 弹层替代 |

## 文档

| 文件 | 状态 |
|------|------|
| `docs/BACKLOG.md` | ✅ 任务与剩余工作 |
| `docs/WEBUI-DESIGN.md` | ✅ UI 源真相 |
| `docs/DASHBOARD.md` | ✅ 技术说明 |
| `docs/api-parity.md` | ✅ API 对照 |
| `docs/STORE.md` | 🟡 上架清单（截图待拍） |

## 本地开发命令

```bash
cd /path/to/nextcloud/apps/sharegate
composer install          # 需 PHP openssl、mbstring
composer test             # PHPUnit
# 在 NC 根目录：
php occ app:enable sharegate
php occ upgrade
```

## 下一步

见 [BACKLOG.md — 阶段 F](BACKLOG.md#三阶段-f--上架与二期下一步)。
