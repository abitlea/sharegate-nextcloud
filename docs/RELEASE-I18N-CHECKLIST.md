# 1.3.6 上架前国际化检查清单

上架 **App Store** 与 **GitHub** 发布前逐项勾选。

## 1. App Store（商店 metadata + 发布包）

| 项 | 状态 | 说明 |
|----|------|------|
| `info.xml` `<version>` | ✅ 1.3.6 | 与 CHANGELOG 一致 |
| 英文 `summary` ≤ 128 字符 | ✅ | 当前 123 字符 |
| 英文 `name` / `summary` / `description` **无 `lang`** | ✅ | Paid sharing + ShareGate (Paid sharing) |
| 中文 `lang="zh-hans"` | ✅ | 付费分享 + 完整 description |
| `<navigations><name>` 仅一条英文 | ✅ | Paid sharing（XSD 限制） |
| `CHANGELOG.md` 含 `## 1.3.6` | ✅ | 发版日将 `Unreleased` 改为实际日期 |
| `CHANGELOG.zh-hans.md` 含 `## 1.3.6` | ✅ | 与英文版对应 |
| 截图 URL（6 张） | ✅ | `01`–`06`，含 payment ledger + stats |
| 发布包含 `l10n/en.js` + `l10n/zh_CN.js` | ✅ | `f1-package.ps1` 已校验 |
| 发布包含两个 CHANGELOG | ✅ | `f1-package.ps1` 已校验 |

**已知限制（非应用 bug）：** NC「设置 → 应用」详情页可能仍显示英文描述/更新日志（NC 用 `zh` 查商店 `zh-hans`）。商店网页与 metadata 本身已具备中文。

## 2. GitHub（仓库文档）

| 项 | 状态 | 说明 |
|----|------|------|
| `README.md` / `README.zh-CN.md` 版本号 | ✅ | 1.3.6 |
| `docs/I18N.md` | ✅ | 规范与发布前检查 |
| `docs/STORE.md` | ✅ | 打包/签名命令已更新为 1.3.6 |

## 3. 应用内 i18n（运行时）

| 项 | 状态 | 说明 |
|----|------|------|
| `en.json` / `zh_CN.json` 键一致 | ✅ | 386 / 386（`node scripts/check-l10n-keys.js`） |
| `l10n/*.js` 已生成 | ✅ | 发布前运行 `npm run build` |
| Vue 管理台 / 买家页 | ✅ | 经 `t()` + l10n |
| PHP **Controller** API 错误 | ✅ | `IL10N` |
| PHP **Service** 层 API 错误 | ✅ | 1.3.6：`ShareService`、`DownloadService`、`DashboardService`、`PublicLinkService`、`ShareStatsService`、`ShareFileResolver` |
| 创建/编辑分享、账户绑定校验提示 | ✅ | `NcNoteCard` + toast（1.3.6） |

### 上架前建议命令

```bash
node scripts/check-l10n-keys.js
npm run build
powershell -File scripts\release\f1-package.ps1 -Version 1.3.6
powershell -File scripts\release\f1-sign-release.ps1 -Version 1.3.6 -CopySignature
composer test   # 可选
```

### 英文 / 中文 UI 手工冒烟

- [ ] 个人语言 **English**：管理台全流程、创建分享留空字段有提示、账户绑定留空有提示、买家页 Stripe/PayPal
- [ ] 个人语言 **简体中文**：同上
- [ ] 服务器：`sudo -u www-data php occ l10n:createjs sharegate`

## 4. 结论（1.3.6）

| 范围 | 可否上架 |
|------|----------|
| **App Store metadata + CHANGELOG** | ✅ 可以（发版日更新 CHANGELOG 日期） |
| **GitHub Release 说明** | ✅ 可以 |
| **应用内 i18n（含 Service 层）** | ✅ 可以 |
