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

        error_log("WPSG Stripe: Received event {$event->type}");

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
            case 'refund.created':
                self::handle_refund_created($event->data->object);
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
            error_log("WPSG Stripe: Failed to retrieve session {$session->id}: " . $e->getMessage());
            return;
        }

        $metadata = $session->metadata ?? [];

        // ---- RENEWAL PATH (lapsed user resubscribing) ----
        if (!empty($metadata['is_renewal']) && !empty($metadata['user_id'])) {
            $user_id = (int) $metadata['user_id'];
            $user    = get_user_by('id', $user_id);
            if (!$user) {
                error_log("WPSG Stripe: Renewal for unknown user_id={$user_id}");
                return;
            }
            WPSG_Provisioner::extend_subscription($user_id, 12);
            update_user_meta($user_id, 'wpsg_stripe_customer_id', $session->customer->id);
            update_user_meta($user_id, 'wpsg_stripe_subscription_id', $session->subscription->id);
            WPSG_Notifications::send_renewal_user($user_id);
            WPSG_Notifications::send_renewal_admin($user_id);

            error_log("WPSG Stripe: ✅ Renewed {$user->user_login} (user_id={$user_id})");
            return;
        }

        // ---- NEW SIGNUP PATH ----
        $email_prefix = $metadata['email_prefix'] ?? null;
        $domain       = $metadata['domain'] ?? null;

        if (!$email_prefix || !$domain) {
            error_log("WPSG Stripe: Missing email_prefix/domain in session metadata for {$session->id}");
            return;
        }

        $full_email    = $email_prefix . '@' . $domain;
        $transient_key = 'wpsg_pending_' . $session->id;
        $pending       = get_transient($transient_key);

        if (!$pending || empty($pending['password'])) {
            error_log("WPSG Stripe: No pending signup for session {$session->id}");
            return;
        }

        $expiry_date = date('Y-m-d', strtotime('+1 year'));
        $result = WPSG_Provisioner::provision($full_email, $pending['password'], "active_until:{$expiry_date}");

        // Self-heal the case where a previous webhook attempt created the WP
        // user but was interrupted before storing the Stripe/access meta.
        if (is_wp_error($result) && $result->get_error_code() === 'user_exists') {
            $existing = get_user_by('login', $full_email);
            if ($existing) {
                WPSG_Provisioner::set_access_status($existing->ID, "active_until:{$expiry_date}");
                $result = $existing->ID;
            }
        }

        if (is_wp_error($result)) {
            error_log("WPSG Stripe: Provision failed for {$full_email}: " . $result->get_error_message());
            return;
        }

        $ip = isset($pending['ip']) ? $pending['ip'] : '';

        update_user_meta($result, 'wpsg_stripe_customer_id', $session->customer->id);
        update_user_meta($result, 'wpsg_stripe_subscription_id', $session->subscription->id);
        update_user_meta($result, 'wpsg_initial_purchase_at', time());
        delete_transient($transient_key);

        WPSG_Notifications::send_welcome($result);
        WPSG_Notifications::send_admin_new_user($result, $ip);

        error_log("WPSG Stripe: ✅ Provisioned {$full_email} (user_id={$result})");
    }

    private static function handle_invoice_paid($invoice) {
        \Stripe\Stripe::setApiKey(self::get_secret_key());

        // The initial invoice for a new subscription is handled by
        // checkout.session.completed. Extending here would double the term.
        if (isset($invoice->billing_reason) && $invoice->billing_reason === 'subscription_create') {
            error_log("WPSG Stripe: Skipping invoice.paid for initial invoice {$invoice->id} (billing_reason=subscription_create)");
            return;
        }

        $user = self::find_user_by_stripe_customer($invoice->customer);
        if ($user) {
            WPSG_Provisioner::extend_subscription($user->ID, 12);
            // Refresh Stripe metadata (may change across billing cycles)
            if (!empty($invoice->subscription)) {
                $sub = \Stripe\Subscription::retrieve($invoice->subscription);
                update_user_meta($user->ID, 'wpsg_stripe_subscription_id', $sub->id);
                if (!empty($sub->customer)) {
                    update_user_meta($user->ID, 'wpsg_stripe_customer_id', $sub->customer);
                }
            }
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

    private static function handle_refund_created($refund) {
        $metadata = $refund->metadata ?? [];

        if (($metadata['wpsg_refund_flow'] ?? '') !== WPSG_Refund::FLOW_VALUE) {
            return;
        }

        $user_id = isset($metadata['wpsg_user_id']) ? (int) $metadata['wpsg_user_id'] : 0;
        if (!$user_id) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log("WPSG Stripe: refund.created for unknown user_id={$user_id}");
            return;
        }

        WPSG_Refund::mark_refunded($user_id, $refund->id);
        WPSG_Provisioner::lapse_access($user_id);

        error_log("WPSG Stripe: ✅ Refund {$refund->id} processed for {$user->user_login} (user_id={$user_id})");
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
        return wpsg_stripe_secret_key();
    }

    private static function get_webhook_secret() {
        return wpsg_stripe_webhook_secret();
    }
}
