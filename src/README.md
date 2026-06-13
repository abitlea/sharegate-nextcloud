# ShareGate dashboard (Vue)

管理台由 `@nextcloud/vue` v8（**Vue 2.7**）构建，入口为 `src/main.js`，打包输出到 `js/dashboard.js`。

## 开发

```bash
npm install
npm run watch   # 开发监听
npm run build   # 生产构建（发布前必须执行）
```

## 结构

- `DashboardApp.vue` — `NcContent` / `NcAppNavigation` / `NcAppContent` 壳
- `components/SharesListPanel.vue` — 公开链接与付费分享列表
- `components/StatsPanel.vue` — 统计
- `components/AccountPanel.vue` — 账户绑定（管理员表单由 PHP 嵌入）
- `components/ShareSettingsModal.vue` — 付费设置（`NcDialog`）

后端 API 与 `window.__sharegateDashboard` 配置见 `lib/Controller/DashboardController.php`。
