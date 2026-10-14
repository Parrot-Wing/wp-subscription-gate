<?php

/**
 * 30-day first-time refund flow.
 *
 * A refund button is shown on the [wpsg_account] profile page for eligible
 * active subscribers. The whole feature is gated by the wpsg_refund_enabled
 * option (Settings → Subscription Gate Stripe).
 */

if (!defined('ABSPATH')) exit;

class WPSG_Refund {

    const OPTION      = 'wpsg_refund_enabled';
    const WINDOW      = 'wpsg_refund_window_days';
    const FLOW_VALUE  = '30_day_first_time';
    const IDEMPOTENCY_PREFIX = 'wpsg-refund-user-';

    /**
     * Feature flag.
     */
    public static function is_enabled() {
        return (bool) get_option(self::OPTION, 0);
    }

    /**
     * True when the current user is eligible to self-serve a refund.
     *
     * Requirements:
     *  - feature flag on
     *  - currently active (active_until:...)
     *  - first purchase was recorded and is within the window
     *  - never refunded before
     *  - has a Stripe subscription id
     */
    public static function is_eligible($user_id) {
        if (!self::is_enabled()) {
            return false;
        }

        $user_id = (int) $user_id;
        if (!$user_id) {
            return false;
        }

        $status = WPSG_Provisioner::get_access_status($user_id);
        if (!$status || strpos($status, 'active_until:') !== 0) {
            return false;
        }

        if (get_user_meta($user_id, 'wpsg_refunded_at', true)) {
            return false;
        }

        $initial = get_user_meta($user_id, 'wpsg_initial_purchase_at', true);
        if (!$initial) {
            return false;
        }

        $days = (int) apply_filters(self::WINDOW, 30);
        if ($days < 1) {
            $days = 30;
        }

        if ((time() - (int) $initial) > ($days * DAY_IN_SECONDS)) {
            return false;
        }

        $subscription_id = get_user_meta($user_id, 'wpsg_stripe_subscription_id', true);
        if (!$subscription_id || !is_string($subscription_id)) {
            return false;
        }

        return true;
    }

    /**
     * Run the refund flow for a user.
     *
     * Order (per decision): refund first, then cancel the subscription, then
     * lapse locally. Refund creation is idempotent per user.
     *
     * @param int $user_id
     * @return array|WP_Error ['refund_id' => 're_...', 'cancel_failed' => bool]
     */
    public static function process($user_id) {
        if (!self::is_enabled()) {
            return new WP_Error('refund_disabled', 'Refunds are not currently enabled.');
        }

        $user_id = (int) $user_id;
        if (!$user_id || get_current_user_id() !== $user_id) {
            return new WP_Error('forbidden', 'You can only refund your own account.');
        }

        if (!self::is_eligible($user_id)) {
            return new WP_Error('not_eligible', 'You are not eligible for a refund.');
        }

        $subscription_id = get_user_meta($user_id, 'wpsg_stripe_subscription_id', true);
        if (!$subscription_id || !is_string($subscription_id)) {
            return new WP_Error('no_subscription', 'No Stripe subscription found. Please contact support.');
        }

        \Stripe\Stripe::setApiKey(wpsg_stripe_secret_key());

        try {
            $invoice = self::find_earliest_paid_invoice($subscription_id);
        } catch (\Exception $e) {
            error_log("WPSG Refund: Failed to find invoice for user {$user_id} sub {$subscription_id}: " . $e->getMessage());
            return new WP_Error('stripe_error', 'Could not locate the original payment. Please contact support.');
        }

        if (!$invoice) {
            return new WP_Error('no_invoice', 'No paid invoice found for this subscription. Please contact support.');
        }

        $payment_intent_id = self::get_payment_intent_id($invoice);
        if (!$payment_intent_id || !is_string($payment_intent_id)) {
            return new WP_Error('no_payment_intent', 'No payment found to refund. Please contact support.');
        }

        try {
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        } catch (\Exception $e) {
            error_log("WPSG Refund: Failed to retrieve PaymentIntent {$payment_intent_id} for user {$user_id}: " . $e->getMessage());
            return new WP_Error('stripe_error', 'Could not retrieve the original payment. Please contact support.');
        }

        // Per decision: if any partial refund already exists, do not move money.
        if (!empty($payment_intent->amount_refunded) && $payment_intent->amount_refunded > 0) {
            error_log("WPSG Refund: User {$user_id} has a partial refund already ({$payment_intent->amount_refunded}/{$payment_intent->amount}); blocking self-serve flow.");
            return new WP_Error('partial_refund', 'A partial refund already exists on this payment. Please contact support.');
        }

        // 1) Refund first.
        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $payment_intent_id,
                'metadata'       => [
                    'wpsg_user_id'     => $user_id,
                    'wpsg_refund_flow' => self::FLOW_VALUE,
                ],
            ], [
                'idempotency_key' => self::IDEMPOTENCY_PREFIX . $user_id,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("WPSG Refund: Refund failed for user {$user_id}: " . $e->getMessage());
            return new WP_Error('refund_failed', 'The refund could not be processed. Please contact support.');
        }

        self::mark_refunded($user_id, $refund->id);

        // 2) Cancel the subscription immediately (no proration by default).
        $cancel_failed = false;
        try {
            $subscription = \Stripe\Subscription::retrieve($subscription_id);
            $subscription->cancel();
        } catch (\Exception $e) {
            $cancel_failed = true;
            error_log("WPSG Refund: Refund {$refund->id} succeeded for user {$user_id} but cancel of {$subscription_id} failed: " . $e->getMessage());
        }

        // 3) Lapse locally. (customer.subscription.deleted may also do this later — idempotent)
        WPSG_Provisioner::lapse_access($user_id);

        WPSG_Notifications::send_refund_user($user_id, $refund->id);
        WPSG_Notifications::send_refund_admin($user_id, $refund->id, $subscription_id, $cancel_failed);

        return [
            'refund_id'    => $refund->id,
            'cancel_failed' => $cancel_failed,
        ];
    }

    /**
     * Record that a refund has been completed. Used by the AJAX flow and the
     * refund.created webhook safety net.
     */
    public static function mark_refunded($user_id, $refund_id = '') {
        update_user_meta($user_id, 'wpsg_refund_status', 'refunded');
        update_user_meta($user_id, 'wpsg_refunded_at', time());
        if ($refund_id) {
            update_user_meta($user_id, 'wpsg_stripe_refund_id', $refund_id);
        }
    }

    /**
     * Find the earliest paid invoice for a subscription (the initial charge
     * for an annual plan).
     *
     * @param string $subscription_id
     * @return \Stripe\Invoice|null
     */
    public static function find_earliest_paid_invoice($subscription_id) {
        $invoices = \Stripe\Invoice::all([
            'subscription' => $subscription_id,
            'status'       => 'paid',
            'limit'        => 100,
        ]);

        $earliest = null;
        foreach ($invoices->autoPagingIterator() as $invoice) {
            if ($earliest === null || $invoice->created < $earliest->created) {
                $earliest = $invoice;
            }
        }

        if (!$earliest) {
            return null;
        }

        // Expand payments so the PaymentIntent (or charge) can be extracted.
        return \Stripe\Invoice::retrieve([
            'id'     => $earliest->id,
            'expand' => ['payments'],
        ]);
    }

    /**
     * Extract the PaymentIntent id from an expanded invoice.
     *
     * @param \Stripe\Invoice $invoice
     * @return string|null
     */
    public static function get_payment_intent_id($invoice) {
        if (empty($invoice->payments) || empty($invoice->payments->data)) {
            return null;
        }

        $payment = $invoice->payments->data[0]->payment ?? null;
        if (!$payment) {
            return null;
        }

        $payment_intent = $payment->payment_intent ?? null;
        if (is_object($payment_intent) && !empty($payment_intent->id)) {
            return $payment_intent->id;
        }

        return is_string($payment_intent) ? $payment_intent : null;
    }
}
