# WP Subscription Gate

Stripe Checkout annual subscription for mailbox provisioning. No paid plugin dependency.

## How It Works

1. User fills in email prefix + domain dropdown + password on the registration page.
2. Plugin creates a Stripe Checkout Session (subscription mode, annual) and redirects to Stripe.
3. On successful payment, Stripe sends a webhook to `/wp-json/wpsg/v1/stripe-webhook`.
4. The webhook handler verifies the Stripe signature, then calls `wp_create_user()` — the SHA512-Pass plugin ensures the password hash is Dovecot-compatible SHA512-CRYPT.
5. User is redirected to a welcome page.

## Requirements

- WordPress with the **SHA512-Pass** plugin active
- Dovecot authenticating against `wp_users` via `password_query`
- PHP 8.3+ with curl, mbstring, json
- Stripe account with a Product and annual Price configured

## Installation

1. Clone this repo into `wp-content/plugins/wp-subscription-gate/`. The Stripe PHP SDK is bundled — no Composer needed.
2. Activate the plugin in WordPress Admin → Plugins.
3. Go to **Settings → Subscription Gate → Stripe** and paste your Stripe credentials (Secret key, Publishable key, Price ID, Webhook signing secret). Use test keys (`sk_test_`, `pk_test_`) and a test Price ID first.

   As an alternative, you can define the constants in `wp-config.php` before the "stop editing" line — they take priority over the settings page:

       define('WPSG_STRIPE_SECRET_KEY',      'sk_...');
       define('WPSG_STRIPE_PUBLISHABLE_KEY', 'pk_...');
       define('WPSG_STRIPE_PRICE_ID',        'price_...');
       define('WPSG_STRIPE_WEBHOOK_SECRET',  'whsec_...');

4. Create a page with slug `welcome` — users land here after payment.
5. On your registration page, use the shortcode `[wpsg_register]`.
6. In Stripe Dashboard → Developers → Webhooks, create an endpoint at `https://your-domain/wp-json/wpsg/v1/stripe-webhook` listening for: `checkout.session.completed`, `invoice.paid`, `customer.subscription.deleted`, `invoice.payment_failed`.
7. Test with Stripe's test card `4242 4242 4242 4242` before switching to live keys.

## Upgrading from 1.1.x

Menu management is now opt-in. After upgrading, the plugin leaves your navigation menus alone by default. To restore the old visibility behavior (hide Login/Registration while logged in, hide Account/Email Login while logged out), go to **Settings → Subscription Gate → Pages & Behavior** and check all three **Menu visibility** options. Existing Stripe and email settings are preserved.

## Shortcodes

- `[wpsg_register]` — signup form: email prefix + domain dropdown + password + confirm password. The domain dropdown defaults to the site's own domain (from `home_url()`). Use the filter `wpsg_registration_domains` to customize.
- `[wpsg_mail_login]` — mailbox login / subscription-status gate.
- `[wpsg_account]` — account management for logged-in users.
- `[wpsg_login]` — optional branded login button. Links to the configured login URL and returns users to the destination chosen under Settings → Subscription Gate → Pages & Behavior → Login.

## Credentials

Stripe credentials are configured in **WordPress Admin → Settings → Subscription Gate → Stripe** and stored in the database as `wpsg_stripe_*` options. If the `WPSG_STRIPE_*` constants are defined in `wp-config.php`, they take priority. No secret values are committed to this repository.
