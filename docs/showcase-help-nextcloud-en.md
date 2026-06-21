# help.nextcloud.com — Showcase post draft (English)

Copy/paste into a new topic on [help.nextcloud.com](https://help.nextcloud.com).  
Suggested category: **Apps** or **Showcase** (pick what fits when you post).

---

## Title (pick one)

- **Paid file sharing for Nextcloud — Stripe, PayPal, self-hosted (ShareGate)**
- **Showcase: Sell PDFs and digital files from your Nextcloud with Stripe/PayPal**

---

## Post body

Hi everyone,

I’d like to share **ShareGate** (**Paid sharing**), a Nextcloud app that lets you **sell files directly from your instance** — without sending them to Gumroad, Payhip, or another SaaS.

### The problem

Many of us already use Nextcloud as our file hub. When we want to sell a PDF, template, report, or course material, the usual options are:

- Upload copies to a third-party store (files leave your infrastructure)
- Run WooCommerce or a separate payment site (extra stack to maintain)
- Share a public link and collect payment manually (no access control)

I wanted **paid download links** that stay **on the same Nextcloud server**, with **Stripe** and **PayPal** for international buyers.

### What ShareGate does

**ShareGate (Paid sharing)** is a Nextcloud app (PHP, AGPL). Enable it — **no separate Node.js service**.

**Sellers**

- Open **Paid sharing** in the Nextcloud header (seller dashboard)
- **Your shares** — pick a file and create a paid link
- **Paid shares** — copy link, edit price / access days / link expiry, cancel
- **Payment ledger** — orders, amounts, status, payer account
- **Statistics** — preview, save-to-cloud, and download counts

**Buyers**

- Visit a short link like `/apps/sharegate/s/{shareId}`
- Pay via **Stripe Checkout** or **PayPal Checkout**
- Download within the configured access period
- Logged-in users on the same instance: **My purchases** and optional **Save to my Nextcloud**

**Admin**

- **Settings → Paid sharing** — Stripe / PayPal keys, webhooks, display currency
- Mock provider for development only (hidden on production sites)

Alipay Face-to-Face is also supported for China-facing sellers; for global audiences I’ve been testing **Stripe** in particular.

### Why self-hosted?

- Files and access rules stay on **your** Nextcloud
- You control URLs, retention, and who can admin payments
- Fits homelab creators, consultants, training providers, and teams that already standardize on Nextcloud

### Requirements

- Nextcloud **28 – 33**
- PHP **8.2+** (`openssl`, `mbstring`, `curl`)

### Try it

**From GitHub (manual install)**

```bash
git clone https://github.com/abitlea/sharegate-nextcloud.git sharegate
# copy into your Nextcloud apps/ directory as sharegate/
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev
sudo -u www-data php /path/to/nextcloud/occ app:enable sharegate
sudo -u www-data php /path/to/nextcloud/occ upgrade
```

Then log in → **Paid sharing** in the header, or **Settings → Apps** → ShareGate.

**App Store**

When listed: [ShareGate on apps.nextcloud.com](https://apps.nextcloud.com/apps/sharegate) — enable from **Apps** like any other app.

Docs: [README](https://github.com/abitlea/sharegate-nextcloud/blob/main/README.md) · [Payment setup](https://github.com/abitlea/sharegate-nextcloud/blob/main/lib/Payment/README.md) · [Release notes](https://github.com/abitlea/sharegate-nextcloud/blob/main/CHANGELOG.md)

### Screenshots

Attach your five store screenshots from `release/screenshots/` when posting (English UI recommended):

1. Your shares — file list + create paid link  
2. Paid shares — manage links  
3. Account binding — Stripe / PayPal / Alipay  
4. Statistics  
5. Buyer paywall  

### Feedback welcome

This is early days for the App Store listing. I’d especially appreciate feedback on:

- Stripe / PayPal setup clarity  
- Seller dashboard workflow  
- What you’d expect from a “paid download” app on Nextcloud  

Please open issues on GitHub or reply here:  
https://github.com/abitlea/sharegate-nextcloud/issues

Thanks for reading — hope this is useful for others who want **paid sharing without leaving Nextcloud**.

---

## Posting checklist

- [ ] Set personal language to **English** for screenshots / demo GIF if you record one  
- [ ] Run through **Stripe test mode** once before posting  
- [ ] Upload 3–5 screenshots (help.nextcloud.com attachment limit)  
- [ ] Link GitHub; add App Store URL when live  
- [ ] Tag or mention `#apps` if the forum supports tags  
- [ ] Reply to comments within a few days (helps visibility)

## Optional follow-up replies (prepare ahead)

**“How is this different from public links + payment link?”**  
ShareGate ties **payment confirmation** to **time-limited download access** and gives sellers a **ledger** and **dashboard** — not just a file URL.

**“Does it take a platform fee?”**  
No ShareGate platform fee. You pay Stripe/PayPal (and your hosting) only.

**“GDPR / data location?”**  
Files remain on your Nextcloud; payment data is between you, Stripe/PayPal, and your buyers per those providers’ terms.
