# Store screenshots

5 screenshots for the [Nextcloud App Store](https://apps.nextcloud.com/apps/sharegate) (`appinfo/info.xml`).

| File | Page (English UI) |
|------|-------------------|
| `01-your-shares.png` | Your shares |
| `02-paid-shares.png` | Paid shares (also list thumbnail in `info.xml`) |
| `03-account.png` | Account binding |
| `04-stats.png` | Statistics |
| `05-buyer-pay.png` | Buyer paywall |
| `06-ledger.png` | Payment ledger (optional App Store screenshot) |
| `07-my-purchases.png` | My purchases (buyer page, optional) |

**Specs:** 1280×720 (or 16:9), each ≤ 2 MB, PNG.

**URLs** (after push to `main`):

`https://raw.githubusercontent.com/abitlea/sharegate-nextcloud/main/release/screenshots/`

---

## English screenshot checklist

### Before you start

1. **Personal language → English**  
   Nextcloud → avatar → **Settings** → **Personal info** → **Language** → **English (US)** or **English (UK)** → refresh.
2. **Build / deploy** latest app on the instance you screenshot (`npm run build` if you changed frontend).
3. **Sample data:** at least 3–4 files with mixed share states (shared / not shared), 2+ paid shares with links, a few preview/save/download stats.
4. **Browser:** zoom 100%, window ~1280×720 or crop to 16:9. Hide unrelated bookmarks/extensions if possible.
5. **Optional:** Stripe or PayPal configured for `05` (international); Alipay OK for China-focused `05`.

### ① `01-your-shares.png` — Your shares

| Step | Action |
|------|--------|
| Open | Header **Paid sharing**, or `/index.php/apps/sharegate/` |
| Sidebar | Click **Your shares** (first item) |
| Main area | File list visible: columns **Name**, **Size**, **Modified**, **Paid share** |
| Nice to have | One row selected; right panel **Create share** open with price/days filled |
| Avoid | Empty list, error toasts, Chinese labels |

**English labels to verify:** Your shares · Paid shares · Account binding · Payment ledger · Statistics · Search shared file names · Add share · Shared

### ② `02-paid-shares.png` — Paid shares

| Step | Action |
|------|--------|
| Sidebar | **Paid shares** |
| Main area | Table with share title, price, link, status |
| Nice to have | 2–4 rows; one row expanded or action menu visible |
| Avoid | No paid shares yet |

**English labels:** Paid shares · Copy link · Edit · Cancel (or current action labels)

### ③ `03-account.png` — Account binding

| Step | Action |
|------|--------|
| Sidebar | **Account binding** |
| Main area | Payment provider section (Stripe / PayPal / Alipay fields) |
| Who | Log in as **admin** (binding is admin-only) |
| Nice to have | Provider dropdown or tabs visible; do **not** expose real API secrets — use test placeholders or blur keys |
| Avoid | “Access denied” for non-admin |

**English labels:** Account binding · Stripe · PayPal · Alipay · Save / Test connection (as implemented)

### ④ `04-stats.png` — Statistics

| Step | Action |
|------|--------|
| Sidebar | **Statistics** |
| Main area | Stats table: previews, save-to-cloud, downloads, amounts |
| Nice to have | Non-zero numbers for 2+ shares |
| Avoid | Empty “0 everywhere” if you have test orders |

### ⑤ `05-buyer-pay.png` — Buyer paywall

| Step | Action |
|------|--------|
| Open | Copy a paid share link → `/apps/sharegate/s/{shareId}` (incognito or second browser OK) |
| Choose provider | **International store:** Stripe or PayPal checkout button visible (not only Alipay QR). **China:** Alipay QR is fine |
| Main area | Title, file name, size, validity, price, pay CTA or QR |
| Nice to have | Price in USD/EUR for Stripe; or clear “Pay with PayPal” |
| Avoid | Chinese-only copy (“请使用支付宝…”) on English gallery |

**English labels:** Paid content · Pay now · Save to my Nextcloud · Download · Confirming payment…

---

## After capture

```text
release/screenshots/
  01-your-shares.png
  02-paid-shares.png
  03-account.png
  04-stats.png
  05-buyer-pay.png
```

1. Overwrite files (keep filenames — `info.xml` URLs unchanged).
2. Check each file ≤ 2 MB; resize if needed.
3. `git add release/screenshots/*.png && git commit && git push origin main`
4. Wait a few minutes; refresh [App Store page](https://apps.nextcloud.com/apps/sharegate) (CDN cache).

**No new app release required** for screenshot-only updates.

## Optional: bilingual gallery

App Store shows the same 5 images for all locales. Common approaches:

- **English only** (recommended for international listing), or
- Mix: e.g. `05` Alipay for China + English dashboard for `01`–`04` (less consistent).

For a second Chinese set, keep files locally or use a `screenshots-zh/` folder — not wired in `info.xml` unless you add more `<screenshot>` entries later.
