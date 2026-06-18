# Payment providers

| Provider | ID | Region | Flow |
|----------|-----|--------|------|
| Mock | `mock` | Global | Dev test page |
| Stripe | `stripe` | International | Redirect to Stripe Checkout |
| PayPal | `paypal` | International | Redirect to PayPal Checkout |
| Alipay Face-to-Face | `alipay_f2f` | China | QR code scan |

Configure in **Nextcloud admin → Paid sharing → Account binding** (or NC Settings → ShareGate).

## Stripe (international)

1. Create a [Stripe](https://stripe.com) account.
2. Copy **Secret key** (`sk_test_…` or `sk_live_…`).
3. Add a webhook endpoint pointing to the URL shown in the admin form (`…/payment/notify/stripe`).
4. Subscribe to **`checkout.session.completed`** and copy the **Signing secret** (`whsec_…`).
5. Choose **Checkout currency** (USD, EUR, etc.). Share prices are stored in the smallest currency unit (e.g. cents); JPY uses whole yen.

No extra Composer package — uses Stripe REST API over HTTPS.

## PayPal (international)

1. Create a [PayPal Developer](https://developer.paypal.com) app (Sandbox or Live).
2. Copy **Client ID** and **Client Secret** into the account binding form.
3. Enable **Sandbox mode** for testing.
4. (Recommended) Add a webhook to the URL shown in the admin form (`…/payment/notify/paypal`) for events:
   - `CHECKOUT.ORDER.APPROVED`
   - `PAYMENT.Capture.COMPLETED`
5. Copy the **Webhook ID** (`whhook_…`) for signature verification. If omitted, payment confirmation relies on the buyer return URL and status polling.

Uses PayPal Orders v2 REST API (no SDK).

## Alipay Face-to-Face (China)

Depends on `alipaysdk/easysdk`:

```bash
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev
```

Register the async notify URL from the admin form in the Alipay open platform.
