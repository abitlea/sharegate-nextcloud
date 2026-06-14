# 应用商店上架素材清单

## 版本

- `appinfo/info.xml` → **1.3.2**
- 发布包：`release/sharegate-1.3.2.tar.gz`（`scripts/release/f1-package.ps1` 生成）

## 上架前置（必做，按顺序）

### ① 公开 GitHub 仓库

- [x] 仓库：<https://github.com/abitlea/sharegate-nextcloud>
- [x] `info.xml` 中 `repository` / `bugs` / `website` 已指向该地址

### ② Nextcloud 代码签名证书（强制）

商店**只接受签名过的** `.tar.gz`，不能直传未签名包。

**应用 ID**（与 `info.xml` 的 `<id>` 一致）：`sharegate`  
**私钥/证书目录**（本机，勿提交 Git）：`%USERPROFILE%\.nextcloud\certificates\`

#### 2.1 生成 CSR（本机已完成可跳过）

Windows 若无 `openssl` 命令，使用 Git 自带的 OpenSSL：

```powershell
powershell -File scripts\release\f0-request-nc-certificate.ps1
```

或手动：

```powershell
$openssl = "C:\Program Files\Git\usr\bin\openssl.exe"
$dir = "$env:USERPROFILE\.nextcloud\certificates"
New-Item -ItemType Directory -Force -Path $dir | Out-Null
& $openssl req -nodes -newkey rsa:4096 `
  -keyout "$dir\sharegate.key" `
  -out "$dir\sharegate.csr" `
  -subj "/CN=sharegate"
```

产物：

| 文件 | 说明 |
|------|------|
| `sharegate.key` | **私钥，仅本机保存，切勿上传/提交** |
| `sharegate.csr` | 证书申请文件，用于提交 PR |
| `certificate-request/sharegate.csr` | 仓库内 CSR 副本（可公开） |

#### 2.2 向 Nextcloud 提交 CSR（待你操作）

1. 打开 [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
2. 点击 **Fork**（若无写权限）
3. 在你 Fork 的仓库中 **Create new file**
4. 路径必须为：`sharegate/sharegate.csr`（`APP_ID/APP_ID.csr`）
5. 粘贴 `certificate-request/sharegate.csr` 的全部内容
6. 提交并 **Open pull request**
7. PR 说明中可附上源码：<https://github.com/abitlea/sharegate-nextcloud>

维护者合并后，在同一 PR 或仓库的 `sharegate/` 目录会出现 **`sharegate.crt`**。

#### 2.3 下载证书到本机

将 `sharegate.crt` 保存到：

```
%USERPROFILE%\.nextcloud\certificates\sharegate.crt
```

与 `sharegate.key` 放在同一目录。审批通常需数小时到数天。

#### 2.4 下一步（证书到位后 → ③）

在 [注册应用](https://apps.nextcloud.com/developer/apps/new) 上传 `sharegate.crt`，并填写 Signature：

```powershell
$openssl = "C:\Program Files\Git\usr\bin\openssl.exe"
$key = "$env:USERPROFILE\.nextcloud\certificates\sharegate.key"
"sharegate" | & $openssl dgst -sha512 -sign $key | & $openssl base64
```

输出的一长串 base64 即为注册时的 **Signature**。

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
- [x] **公开 GitHub 仓库**已创建并更新 `info.xml` 中的 URL
- [ ] **Nextcloud 签名证书**：PR [#1044](https://github.com/nextcloud/app-certificate-requests/pull/1044) 审核中
- [x] **5 张截图**已上传 GitHub 并写入 `info.xml`
- [x] **应用图标** `img/app.svg`（顶栏导航 + 商店列表均从发布包读取此文件）
- [x] `info.xml` 中 `author mail` 为 `abitlea@126.com`
- [ ] F2：`occ upgrade`，4 张 `sharegate_*` 表存在
- [ ] F3：Mock 端到端（`scripts/release/f3-e2e-mock.ps1`）

流程说明：[RELEASE.md](RELEASE.md)
