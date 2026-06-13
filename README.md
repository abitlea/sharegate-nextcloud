# ShareGate for Nextcloud

Nextcloud 应用：**安装启用即可用**，无需单独部署 Node 服务。

**当前版本** 1.3.0 — 管理台四页（公开链接 · 付费分享 · 账户绑定 · 统计）、买家付费页、Mock/支付宝当面付、站内转存。

## 要求

- Nextcloud 28 – 30
- PHP 8.1+（`openssl`、`mbstring` 扩展；跑测试与 `composer install` 时需要）

## 安装

```bash
# 复制到 Nextcloud apps 目录（文件夹名必须为 sharegate）
cp -r sharegate-nextcloud /path/to/nextcloud/apps/sharegate
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev
```

管理后台 → **应用** → 启用 **ShareGate** → `php occ upgrade`（创建 `sharegate_share_stats` 等表）。

## 卖家使用（管理台）

1. 登录 Nextcloud
2. 顶栏 **ShareGate**，或访问 `/index.php/apps/sharegate/`

多实例部署见 [docs/RELEASE.md](docs/RELEASE.md)（DietPi `/nextcloud` vs Docker `:8080`）。
3. 侧栏四页：
   - **公开链接** — 网盘文件，未分享行点「添加分享」
   - **付费分享** — 复制链接 / 编辑 / 取消分享
   - **账户绑定** — 管理员配置支付宝（Mock 开发模式）
   - **统计相关** — 预览、转存、下载次数

创建分享：`/apps/sharegate/embed/create`，支持 `?path=Documents/a.pdf&name=a.pdf` 预填。

## 买家使用

访问卖家短链 `/apps/sharegate/s/{shareId}` → 扫码支付 → 下载；已登录本站用户可「保存到我的 Nextcloud」。

## 支付配置

**Nextcloud 管理后台 → 设置 → ShareGate**，或管理台 **账户绑定** 页（管理员）。

- Mock：开发测试，无真实扣款
- 支付宝当面付：沙箱或生产，需配置异步通知 URL（公网可达）

## 开发与测试

```bash
composer install
composer test    # phpunit.xml.dist → tests/Unit/
```

任务清单：[docs/BACKLOG.md](docs/BACKLOG.md)  
发布验证（已有 NC）：[docs/RELEASE.md](docs/RELEASE.md) — `f2-deploy-verify.ps1 -NcRoot -NcUrl`  
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
| 4 | 管理台四页 + 统计 + 转存（v1.3.0） | ✅ |
| 5 | 应用商店发布 | ⬜ 见 [STORE.md](docs/STORE.md) |
| 6 | 全站管理 API、`file_id`、Files 右键 | ⬜ 二期 |
