<?php

/**
 * Creates Stripe Checkout Sessions for annual subscription.
 */
class WPSG_Checkout {

    public static function create_session($email_prefix, $domain, $password) {
        \Stripe\Stripe::setApiKey(self::get_secret_key());

        $full_email = $email_prefix . '@' . $domain;

        if (!is_email($full_email)) {
            return ['error' => 'Invalid email address.'];
        }

        if (get_user_by('login', $full_email)) {
            return ['error' => 'A mailbox with this email already exists.'];
        }

        if (!preg_match('/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/', $email_prefix)) {
            return ['error' => 'Email prefix contains invalid characters.'];
        }

        $price_id    = self::get_price_id();
        $success_url = home_url('/welcome/');
        $cancel_url  = home_url('/registration/');

        try {
            $session = \Stripe\Checkout\Session::create([
                'mode'                 => 'subscription',
                'line_items'           => [[
                    'price'    => $price_id,
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'email_prefix' => $email_prefix,
                    'domain'       => $domain,
                    'source'       => 'wpsg-stripe-plugin',
                ],
                'success_url'    => $success_url . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'     => $cancel_url,
                'customer_email' => $full_email,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("WPSG Stripe: Checkout error: " . $e->getMessage());
            return ['error' => 'Payment system temporarily unavailable. Please try again.'];
        }

        set_transient('wpsg_pending_' . $session->id, [
            'password'     => $password,
            'email_prefix' => $email_prefix,
            'domain'       => $domain,
            'created'      => time(),
        ], 24 * HOUR_IN_SECONDS);

        error_log("WPSG Stripe: Checkout session {$session->id} for {$full_email}");

        return ['url' => $session->url];
    }

    private static function get_secret_key() {
        return defined('WPSG_STRIPE_SECRET_KEY') ? WPSG_STRIPE_SECRET_KEY : get_option('wpsg_stripe_secret_key', '');
    }

    private static function get_price_id() {
        return defined('WPSG_STRIPE_PRICE_ID') ? WPSG_STRIPE_PRICE_ID : get_option('wpsg_stripe_price_id', '');
    }
}
