# Payment providers

- `MockPaymentProvider.php` — 开发/测试模拟支付（阶段 2）
- `AlipayF2fProvider.php` — 支付宝当面付（阶段 3，依赖 `alipaysdk/easysdk`）

安装 SDK：

```bash
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev
```

配置路径：**Nextcloud 管理后台 → 设置 → ShareGate**
