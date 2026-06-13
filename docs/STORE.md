# 应用商店上架素材清单

## 版本

- `appinfo/info.xml` → **1.3.2**
- 发布包：`release/sharegate-1.3.2.tar.gz`（`scripts/release/f1-package.ps1` 生成）

## 上架前置（必做，按顺序）

### ① 公开 GitHub 仓库

`info.xml` 中的 `repository` / `bugs` / `website` 必须指向**真实可访问**的仓库（当前 `github.com/sharegate/nextcloud-app` 为 404，需先创建并推送代码）。

### ② Nextcloud 代码签名证书（强制）

商店**只接受签名过的** `.tar.gz`，不能直传未签名包。

```bash
mkdir -p ~/.nextcloud/certificates
cd ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 -keyout sharegate.key -out sharegate.csr -subj "/CN=sharegate"
```

向 [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests) 提交 PR，上传 `sharegate/sharegate.csr`。审批后下载 `sharegate.crt` 到同目录。

### ③ 在商店注册应用

登录 [注册应用](https://apps.nextcloud.com/developer/apps/new)：

- 粘贴 `sharegate.crt`
- Signature：`echo -n "sharegate" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/sharegate.key | openssl base64`

### ④ 截图（HTTPS，每张 ≤ 2MB，建议 1280×720）

保存为 `release/screenshots/01-public-links.png` 等 5 张后，放到 GitHub `raw` 或你自己的 HTTPS 静态地址，并写入 `appinfo/info.xml`：

```xml
<screenshot>https://raw.githubusercontent.com/你的用户/你的仓库/main/release/screenshots/01-public-links.png</screenshot>
```

建议内容见下方「截图建议」。

### ⑤ 打包 → GitHub Release → 签名 → 上传商店

```powershell
# 本地
npm run build
powershell -File scripts\release\f1-package.ps1 -Version 1.3.2

# 签名（证书到位后）
openssl dgst -sha512 -sign $env:USERPROFILE\.nextcloud\certificates\sharegate.key release\sharegate-1.3.2.tar.gz | openssl base64
# 复制输出的 base64 作为 Release Signature

# 将 sharegate-1.3.2.tar.gz 上传到 GitHub Releases，得到 HTTPS 下载链接
```

在 [上传版本](https://apps.nextcloud.com/developer/apps/releases/new) 填写：

- **Download**：`https://github.com/你的用户/你的仓库/releases/download/v1.3.2/sharegate-1.3.2.tar.gz`
- **Signature**：上一步 openssl 输出
- **Changelog**：根目录 `CHANGELOG.md` 会自动被商店读取（已含 1.3.2）

可选：安装 [krankerl](https://github.com/nextcloud/krankerl) 后 `krankerl sign --package` + `krankerl publish <url>` 简化签名与登记。

## 提交前检查

- [x] `composer install --no-dev` 通过
- [x] `npm run build` 通过
- [x] 发布包已生成（`release/sharegate-1.3.2.tar.gz`）
- [ ] **公开 GitHub 仓库**已创建并更新 `info.xml` 中的 URL
- [ ] **Nextcloud 签名证书**已申请并注册应用
- [ ] **5 张截图**已上传 HTTPS 并写入 `info.xml`
- [ ] `info.xml` 中 `author mail` 改为真实邮箱（非 `admin@example.com`）
- [ ] F2：`occ upgrade`，4 张 `sharegate_*` 表存在
- [ ] F3：Mock 端到端（`scripts/release/f3-e2e-mock.ps1`）

流程说明：[RELEASE.md](RELEASE.md)
