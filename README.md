# ShareGate for Nextcloud

> **Languages:** English (this file) · [简体中文](README.zh-CN.md)

Nextcloud app: **install and enable — no separate Node server required.**

**Current version:** 1.3.4 — seller dashboard (Your shares · Paid shares · Account binding · Revenue), buyer paywall, **Stripe / PayPal / Alipay Face-to-Face**, save-to-cloud, bilingual UI (`en` / `zh_CN`).

## Requirements

- Nextcloud 28 – 33
- PHP 8.2+ with `openssl`, `mbstring`, and `curl` (for tests and `composer install`)

## Installation

```bash
# Copy into Nextcloud apps directory (folder name must be sharegate)
cp -r sharegate-nextcloud /path/to/nextcloud/apps/sharegate
cd /path/to/nextcloud/apps/sharegate
composer install --no-dev
```

Admin → **Apps** → enable **ShareGate** → run `php occ upgrade` (creates/updates `sharegate_*` tables, including `file_id` migration).

## Sellers (dashboard)

1. Log in to Nextcloud
2. Open **Paid sharing** in the header, or visit `/index.php/apps/sharegate/`

Multi-instance deployment: [docs/RELEASE.md](docs/RELEASE.md) (DietPi `/nextcloud` vs Docker `:8080`).

3. Four sidebar pages:
   - **Your shares** — browse files; click **Add share** on unshared rows
   - **Paid shares** — copy link / edit / cancel
   - **Account binding** — admin configures **Stripe, PayPal, or Alipay** (Mock for dev only)
   - **Revenue** — preview, save-to-cloud, and download counts

Create a share: `/apps/sharegate/embed/create` — optional `?path=Documents/a.pdf&name=a.pdf` to pre-fill.

## Buyers

Visit the seller link `/apps/sharegate/s/{shareId}`:

- **Alipay** — scan QR code to pay
- **Stripe / PayPal** — redirect to Checkout
- After payment: download; logged-in users on the same server can **Save to my Nextcloud** (`ShareGate/` folder)

## Payment setup

**Nextcloud admin → Settings → Paid sharing**, or dashboard **Account binding** (admin only).

| Provider | Notes |
|----------|--------|
| Mock | Dev/test only; not selectable on production sites |
| Alipay Face-to-Face | China; sandbox or live; public notify URL required |
| Stripe Checkout | Cards/wallets; `sk_test_` / `sk_live_` + webhook `checkout.session.completed` |
| PayPal Checkout | International; Sandbox Client ID/Secret + optional webhook |

See [lib/Payment/README.md](lib/Payment/README.md) for webhooks and testing.  
In-app UI translations: [docs/I18N.md](docs/I18N.md).

## Development & testing

```bash
npm install
npm run build          # js/dashboard.js, js/download.js, l10n/*.js
composer install
composer test          # phpunit.xml.dist → tests/Unit/
```

Backlog: [docs/BACKLOG.md](docs/BACKLOG.md)  
Release verification: [docs/RELEASE.md](docs/RELEASE.md)  
App Store checklist: [docs/STORE.md](docs/STORE.md)

## Relation to ShareGate monorepo

| monorepo | this repo |
|----------|-----------|
| Node server + AList | native NC app |
| `apps/server/src/frontend/embed/*` | synced → `js/embed-create.js`, etc. |
| `packages/core` | ported to `lib/Service/*` (PHP) |

```powershell
powershell -ExecutionPolicy Bypass -File scripts/sync-from-sharegate.ps1
```

See [docs/PLAN.md](docs/PLAN.md).

## Roadmap

| Phase | Scope | Status |
|-------|--------|--------|
| 1 | Paid shares + buyer page | ✅ |
| 2 | Mock payment + download | ✅ |
| 3 | Alipay F2F + admin settings | ✅ |
| 4 | Dashboard four pages + revenue + save-to-cloud | ✅ |
| 5 | Stripe / PayPal + i18n + `file_id` (v1.3.4) | ✅ |
| 6 | Nextcloud App Store release | ⬜ [STORE.md](docs/STORE.md) |
| 7 | Admin APIs, Files context menu, etc. | ⬜ phase 2 |
