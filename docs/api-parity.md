# ShareGate API 对照表

> 前缀：`/apps/sharegate`（由 Nextcloud 路由生成）

## 管理台（卖家）

| 路由名 | 方法 | 路径 | 说明 |
|--------|------|------|------|
| `dashboard#index` | GET | `/` | 管理台页面 |
| `dashboard#summary` | GET | `/api/dashboard/summary` | 侧栏角标 + 账户摘要 |
| `dashboard#list` | GET | `/api/dashboard/shares` | 付费分享列表 `?filter=active\|all` |
| `dashboard#stats` | GET | `/api/dashboard/stats` | 卖家文件级统计表 |
| `files#publicLinks` | GET | `/api/files/public-links` | 公开链接（网盘文件 + 分享状态） |

## 分享

| 路由名 | 方法 | 路径 | 说明 |
|--------|------|------|------|
| `share#createEmbed` | GET | `/embed/create` | 创建页 |
| `share#createShare` | POST | `/share/create` | 创建分享 |
| `share#settings` | GET | `/share/{id}/settings` | **重定向** → `/?edit={id}#paid` |
| `share#getShareSettings` | GET | `/api/share/{id}` | 付费设置弹层数据 |
| `share#updateShare` | PUT | `/share/{id}` | 更新分享 |
| `share#disable` | PATCH | `/share/{id}/disable` | 取消分享 |
| `share#view` | GET | `/s/{id}` | 对外页（计预览） |
| `share#getShareInfo` | GET | `/s/{id}/info` | 对外页 JSON |
| `share#download` | POST | `/s/{id}/verify` | 验证下载权限 |
| `share#downloadFile` | GET | `/s/{id}/download` | 文件下载（计下载） |
| `share#saveToCloud` | POST | `/s/{id}/save-to-cloud` | 站内转存（计转存） |

## 支付

| 路由名 | 方法 | 路径 |
|--------|------|------|
| `payment#create` | POST | `/payment/create` |
| `payment#check` | GET | `/payment/check/{shareId}` |
| `payment#verify` | POST | `/payment/verify` |
| `payment#status` | GET | `/payment/status/{orderId}` |
| `payment#mockPay` | GET | `/pay/mock/{orderId}` |
| `payment#notifyAlipay` | POST | `/payment/notify/alipay_f2f` |

## 管理后台（站点管理员）

| 路由名 | 方法 | 路径 | 说明 |
|--------|------|------|------|
| `admin#paymentConfig` | GET | `/admin/payment-config` | 读取支付配置 |
| `admin#savePaymentConfig` | POST | `/admin/payment-config` | 保存支付配置 |
| `admin#stats` | GET | `/admin/stats` | 站点级统计 |
| `admin#shares` | GET | `/admin/shares` | 全站分享列表 |
| `admin#payments` | GET | `/admin/payments` | 全站订单列表 |

## 遗留 / 二期

| 项 | 状态 | 说明 |
|----|------|------|
| `admin#shares` | ✅ | 全站分享列表已实现 |
| `admin#payments` | ✅ | 全站订单列表已实现 |
| `share#settings` 整页模板 | 已废弃 | 路由重定向；`templates/share/settings.php` 已删除 |
| `file_id` 列 | 未实现 | 分享绑定路径，路径改名会失效 |

## 测试

```bash
composer install
composer test   # tests/Unit/ShareStatsEntityTest.php
```

`tests/bootstrap.php` 含 NC `Entity` 最小 stub，可在无完整 NC 检出时跑单元测试。

## 与 monorepo 差异

- 管理台四页 UI 为 Nextcloud 原生布局，无独立 ShareGate 服务端口
- `provider_user_id` 在 NC 版可为浏览器持久化的 `buyer_*` 或 NC 用户 ID（转存须登录）
- 统计指标 `save_count` / `download_count` / `preview_count` 为 NC 插件独有表

任务跟踪 → [BACKLOG.md](BACKLOG.md)
