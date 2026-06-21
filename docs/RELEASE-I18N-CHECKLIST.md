# 1.3.5 上架前国际化检查清单

上架 **App Store** 与 **GitHub** 发布前逐项勾选。最后更新：2026-06-03。

## 1. App Store（商店 metadata + 发布包）

| 项 | 状态 | 说明 |
|----|------|------|
| `info.xml` `<version>` | ✅ 1.3.5 | 与 CHANGELOG 一致 |
| 英文 `name` / `summary` / `description` **无 `lang`** | ✅ | Paid sharing + ShareGate (Paid sharing) |
| 中文 `lang="zh-hans"` | ✅ | 付费分享 + 完整 description |
| `<navigations><name>` 仅一条英文 | ✅ | Paid sharing（XSD 限制） |
| `CHANGELOG.md` 含 `## 1.3.5` | ✅ | Keep a Changelog 格式 |
| `CHANGELOG.zh-hans.md` 含 `## 1.3.5` | ✅ | 与英文版对应 |
| 截图 URL（5 张） | ⬜ 人工 | `release/screenshots/`，建议英文 UI |
| 发布包含 `l10n/en.js` + `l10n/zh_CN.js` | ✅ | `f1-package.ps1` 已校验 |
| 发布包含两个 CHANGELOG | ✅ | `f1-package.ps1` 已校验 |

**已知限制（非应用 bug）：** NC「设置 → 应用」详情页可能仍显示英文描述/更新日志（NC 用 `zh` 查商店 `zh-hans`）。商店网页与 metadata 本身已具备中文。

## 2. GitHub（仓库文档）

| 项 | 状态 | 说明 |
|----|------|------|
| `README.md` 英文宣传段 | ✅ | Paid sharing + ShareGate |
| `README.zh-CN.md` 中文对应 | ✅ | 与英文结构对齐 |
| 双 README 版本号 1.3.5 | ✅ | |
| `docs/I18N.md` | ✅ | 规范与发布前检查 |
| `docs/showcase-help-nextcloud-en.md` | ✅ | 社区帖草稿 |

## 3. 应用内 i18n（运行时）

| 项 | 状态 | 说明 |
|----|------|------|
| `en.json` / `zh_CN.json` 键一致 | ✅ | 369 / 369（`node scripts/check-l10n-keys.js`） |
| `l10n/*.js` 已生成 | ✅ | 发布前运行 `npm run l10n` |
| Vue 管理台 / 买家页 | ✅ | 经 `t()` + l10n |
| PHP **Controller** API 错误 | ✅ | 已用 `IL10N` |
| PHP **Service** 层 API 错误 | ❌ | `ShareService`、`DownloadService`、`DashboardService` 等仍有硬编码中文 — **英文界面下 API 可能返回中文** |
| `SaveToCloudService` | ⚠️ | 自身已 l10n，但继承 `DownloadService` 中文 verify 消息 |

### 上架前建议命令

```bash
node scripts/check-l10n-keys.js
npm run l10n
composer test   # 可选
```

### 英文 / 中文 UI 手工冒烟

- [ ] 个人语言 **English**：管理台全流程、买家页 Stripe/PayPal、我的已购
- [ ] 个人语言 **简体中文**：同上
- [ ] 服务器：`sudo -u www-data php occ l10n:createjs sharegate`

## 4. 结论（1.3.5）

| 范围 | 可否上架 |
|------|----------|
| **App Store metadata + CHANGELOG** | ✅ 可以 |
| **GitHub Release 说明** | ✅ 可以 |
| **应用内 i18n 完美** | ⚠️ Service 层待修；若主打海外，建议 **先修 Service 再上架** 或发 **1.3.6** 快速跟进 |
