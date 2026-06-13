# ShareGate 管理台 — 技术说明

> **产品与页面设计以 [WEBUI-DESIGN.md](WEBUI-DESIGN.md) 为准。**  
> 任务与剩余工作 → [BACKLOG.md](BACKLOG.md)

**版本** 1.3.0 · **最后同步** 2026-06-03

---

## 布局

- **左侧栏**：NC 全局 `#app-navigation`，`RegisterNavigationListener` 注册四项
- **主区**：`templates/dashboard/index.php` 仅 `#app-content`
- **路由**：`#public` / `#paid` / `#account` / `#stats`（`js/dashboard.js`）

---

## 四视图（均已实现）

| 视图 | hash | 数据源 | 前端 |
|------|------|--------|------|
| 公开链接 | `#public` | `GET /api/files/public-links` | `renderPublicRows` |
| 付费分享 | `#paid` | `GET /api/dashboard/shares?filter=active` | `renderPaidRows` |
| 账户绑定 | `#account` | 内嵌 `admin-form` / `account-readonly` | `admin-settings.js` |
| 统计相关 | `#stats` | `GET /api/dashboard/stats` | `loadStats` |

---

## API 一览

| 路由名 | 方法 | 用途 |
|--------|------|------|
| `dashboard#index` | GET | 壳页面 + `__sharegateDashboard` |
| `dashboard#summary` | GET | 侧栏角标 + 账户摘要 |
| `dashboard#list` | GET | 付费分享列表 `?filter=active\|all` |
| `dashboard#stats` | GET | 卖家文件级统计 |
| `files#publicLinks` | GET | 网盘文件 + 分享状态 |
| `share#getShareSettings` | GET | 付费设置弹层数据 |
| `share#updateShare` | PUT | 更新分享 |
| `share#disable` | PATCH | 取消分享 |
| `share#settings` | GET | **重定向** `/?edit={id}#paid` |

完整对照见 [api-parity.md](api-parity.md)。

---

## 前端配置（`dashboard_config`）

`dashboardUrl`、`publicLinksUrl`、`summaryUrl`、`statsUrl`、`listUrl`、`createUrl`、`shareGetUrlTemplate`、`shareUpdateUrlTemplate`、`disableUrlTemplate`、`requestToken`

---

## 深链与弹层

- 编辑分享：`/apps/sharegate/?edit={shareId}#paid` → `consumeEditParam()` 打开 ocdialog
- 公开链接「已分享」：`sessionStorage` + `#paid` 行高亮 `sg-row--highlight`

---

## 剩余技术项

见 [BACKLOG.md §剩余工作](BACKLOG.md#剩余工作速览)：`admin#shares`/`#payments` 已实现、`file_id` 迁移、遗留 `share/settings` 模板。
