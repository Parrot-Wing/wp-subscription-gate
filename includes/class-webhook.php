<?php

/**
 * Stripe webhook handler.
 * Endpoint: POST /wp-json/wpsg/v1/stripe-webhook
 */
class WPSG_Webhook {

    public static function register_route() {
        register_rest_route('wpsg/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle($request) {
        $payload    = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        $secret     = self::get_webhook_secret();

        if (empty($sig_header)) {
            return new WP_REST_Response('Missing Stripe-Signature header', 400);
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $secret);
        } catch (\UnexpectedValueException $e) {
            return new WP_REST_Response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new WP_REST_Response('Invalid signature', 403);
        }

        error_log("w3i3 Stripe: Received event {$event->type}");

        switch ($event->type) {
            case 'checkout.session.completed':
                self::handle_checkout_completed($event->data->object);
                break;
            case 'invoice.paid':
                self::handle_invoice_paid($event->data->object);
                break;
            case 'customer.subscription.deleted':
                self::handle_subscription_deleted($event->data->object);
                break;
            case 'invoice.payment_failed':
                self::handle_payment_failed($event->data->object);
                break;
        }

        return new WP_REST_Response('OK', 200);
    }

    private static function handle_checkout_completed($session) {
        \Stripe\Stripe::setApiKey(self::get_secret_key());

        try {
            $session = \Stripe\Checkout\Session::retrieve([
                'id'     => $session->id,
                'expand' => ['customer', 'subscription'],
            ]);
        } catch (\Exception $e) {
            error_log("w3i3 Stripe: Failed to retrieve session {$session->id}: " . $e->getMessage());
            return;
        }

        $metadata = $session->metadata ?? [];

        // ---- RENEWAL PATH (lapsed user resubscribing) ----
        if (!empty($metadata['is_renewal']) && !empty($metadata['user_id'])) {
            $user_id = (int) $metadata['user_id'];
            $user    = get_user_by('id', $user_id);
            if (!$user) {
                error_log("w3i3 Stripe: Renewal for unknown user_id={$user_id}");
                return;
            }
            WPSG_Provisioner::extend_subscription($user_id, 12);
            update_user_meta($user_id, 'wpsg_stripe_customer_id', $session->customer->id);
            update_user_meta($user_id, 'wpsg_stripe_subscription_id', $session->subscription->id);
            error_log("w3i3 Stripe: ✅ Renewed {$user->user_login} (user_id={$user_id})");
            return;
        }

        // ---- NEW SIGNUP PATH ----
        $email_prefix = $metadata['email_prefix'] ?? null;
        $domain       = $metadata['domain'] ?? null;

        if (!$email_prefix || !$domain) {
            error_log("w3i3 Stripe: Missing email_prefix/domain in session metadata for {$session->id}");
            return;
        }

        $full_email    = $email_prefix . '@' . $domain;
        $transient_key = 'wpsg_pending_' . $session->id;
        $pending       = get_transient($transient_key);

        if (!$pending || empty($pending['password'])) {
            error_log("w3i3 Stripe: No pending signup for session {$session->id}");
            return;
        }

        $expiry_date = date('Y-m-d', strtotime('+1 year'));
        $result = WPSG_Provisioner::provision($full_email, $pending['password'], "active_until:{$expiry_date}");

        if (is_wp_error($result)) {
            error_log("w3i3 Stripe: Provision failed for {$full_email}: " . $result->get_error_message());
            return;
        }

        update_user_meta($result, 'wpsg_stripe_customer_id', $session->customer->id);
        update_user_meta($result, 'wpsg_stripe_subscription_id', $session->subscription->id);
        delete_transient($transient_key);

        error_log("w3i3 Stripe: ✅ Provisioned {$full_email} (user_id={$result})");
    }

    private static function handle_invoice_paid($invoice) {
        $user = self::find_user_by_stripe_customer($invoice->customer);
        if ($user) {
            WPSG_Provisioner::extend_subscription($user->ID, 12);
        }
    }

    private static function handle_subscription_deleted($subscription) {
        $user = self::find_user_by_stripe_customer($subscription->customer);
        if ($user) {
            WPSG_Provisioner::lapse_access($user->ID);
        }
    }

    private static function handle_payment_failed($invoice) {
        $user = self::find_user_by_stripe_customer($invoice->customer);
        if ($user) {
            WPSG_Provisioner::lapse_access($user->ID);
        }
    }

    private static function find_user_by_stripe_customer($customer_id) {
        $users = get_users([
            'meta_key'   => 'wpsg_stripe_customer_id',
            'meta_value' => $customer_id,
            'number'     => 1,
        ]);
        return !empty($users) ? $users[0] : null;
    }

    private static function get_secret_key() {
        return defined('WPSG_STRIPE_SECRET_KEY') ? WPSG_STRIPE_SECRET_KEY : get_option('wpsg_stripe_secret_key', '');
    }

    private static function get_webhook_secret() {
        return defined('WPSG_STRIPE_WEBHOOK_SECRET') ? WPSG_STRIPE_WEBHOOK_SECRET : get_option('wpsg_stripe_webhook_secret', '');
    }
}