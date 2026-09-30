# Switching the site to your Stripe account

## Part 1 — In your Stripe dashboard (dashboard.stripe.com)

**1. Copy your API keys**
Go to **Developers → API keys**. Copy:
- Publishable key (`pk_live_…`)
- Secret key (`sk_live_…`)

**2. Create a recurring (subscription) price**
Go to **Products → Add product**.
- Name: "Initrix Mailbox" (or anything)
- Pricing: **Recurring**, **Yearly**, set the amount
- Save, then copy the **Price ID** (`price_…`)

**3. Create a webhook endpoint**
Go to **Developers → Webhooks → Add endpoint**.
- URL: `https://initrix.com/wp-json/wpsg/v1/stripe-webhook`
- Events — select all four:
  - `checkout.session.completed`
  - `invoice.paid`
  - `customer.subscription.deleted`
  - `invoice.payment_failed`
- Save, then copy the **Signing secret** (`whsec_…`)

## Part 2 — In WordPress admin

**4.** Log in to WordPress Admin and go to **Settings → Subscription Gate Stripe**.

There are two possible situations:

**A. The fields are editable** (no `WPSG_STRIPE_*` constants in `wp-config.php`):
- Paste the four values from Part 1 into the page and click **Save Changes**. That's it — the settings page is the source of truth.

**B. The fields are grayed out with a warning** (`wp-config.php` still defines `WPSG_STRIPE_*` constants):
- The constants take priority, so the page will not let you edit those fields.
- To switch control to the UI: back up `wp-config.php`, remove the four `define(...)` lines for `WPSG_STRIPE_SECRET_KEY`, `WPSG_STRIPE_PUBLISHABLE_KEY`, `WPSG_STRIPE_PRICE_ID`, and `WPSG_STRIPE_WEBHOOK_SECRET`, then reload this page — the fields become editable. Paste the Part 1 values and save.
- To keep using `wp-config.php`: edit those four `define(...)` lines there instead, and ignore this page.

The four values are:

| Field | Value |
|---|---|
| Secret key | `sk_live_...` |
| Publishable key | `pk_live_...` |
| Price ID | `price_...` |
| Webhook signing secret | `whsec_...` |
