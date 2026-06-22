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
2. Add the following constants to `wp-config.php` (before the "stop editing" line):

       define('WPSG_STRIPE_SECRET_KEY',      'sk_...');
       define('WPSG_STRIPE_PUBLISHABLE_KEY', 'pk_...');
       define('WPSG_STRIPE_PRICE_ID',        'price_...');
       define('WPSG_STRIPE_WEBHOOK_SECRET',  'whsec_...');

   All four values come from the Stripe Dashboard. Use test keys (`sk_test_`, `pk_test_`) and a test Price ID first.

3. Activate the plugin in WordPress Admin → Plugins.
4. Create a page with slug `welcome` — users land here after payment.
5. On your registration page, use the shortcode `[wpsg_register]`.
6. In Stripe Dashboard → Developers → Webhooks, create an endpoint at `https://your-domain/wp-json/wpsg/v1/stripe-webhook` listening for: `checkout.session.completed`, `invoice.paid`, `customer.subscription.deleted`, `invoice.payment_failed`.
7. Test with Stripe's test card `4242 4242 4242 4242` before switching to live keys.

## Shortcode

    [wpsg_register]

Renders: email prefix input + domain dropdown + password + confirm password. The domain list defaults to `['initrix.com']`. Use the filter `wpsg_registration_domains` to customize.

## Credentials

All Stripe keys are read from `wp-config.php` constants. Nothing is stored in the database or committed to this repository.
