<?php

/**
 * Gateway-agnostic mailbox provisioning.
 * All paths (Stripe, future PayPal) call provision() — never create users directly.
 */
class WPSG_Provisioner {

    public static function provision($full_email, $password, $access_status = 'lifetime') {
        if (!is_email($full_email)) {
            return new WP_Error('invalid_email', 'Invalid email address.');
        }

        $existing = get_user_by('login', $full_email);
        if ($existing) {
            return new WP_Error('user_exists', 'A mailbox with this email already exists.');
        }

        $user_id = wp_create_user($full_email, $password, $full_email);

        if (is_wp_error($user_id)) {
            error_log("WPSG Stripe: wp_create_user failed for {$full_email}: " . $user_id->get_error_message());
            return $user_id;
        }

        wp_update_user(['ID' => $user_id, 'role' => 'subscriber']);
        self::set_access_status($user_id, $access_status);
        wp_update_user(['ID' => $user_id, 'display_name' => $full_email]);

        error_log("WPSG Stripe: Provisioned {$full_email} (ID={$user_id}, status={$access_status})");
        return $user_id;
    }

    public static function set_access_status($user_id, $access_status) {
        update_user_meta($user_id, 'wpsg_access_status', $access_status);

        if ($access_status === 'lifetime') {
            update_user_meta($user_id, 'wpsg_active_until', '9999-12-31');
        } elseif (strpos($access_status, 'active_until:') === 0) {
            update_user_meta($user_id, 'wpsg_active_until', explode(':', $access_status, 2)[1] ?? '');
        } elseif ($access_status === 'lapsed') {
            update_user_meta($user_id, 'wpsg_active_until', date('Y-m-d', time() - 86400));
        }
    }

    public static function extend_subscription($user_id, $months = 12) {
        $current = get_user_meta($user_id, 'wpsg_active_until', true);
        $base = ($current && $current > date('Y-m-d')) ? $current : date('Y-m-d');
        $new_date = date('Y-m-d', strtotime($base . " +{$months} months"));
        update_user_meta($user_id, 'wpsg_active_until', $new_date);
        update_user_meta($user_id, 'wpsg_access_status', "active_until:{$new_date}");
        error_log("WPSG Stripe: Extended user {$user_id} to {$new_date}");
        return $new_date;
    }

    public static function lapse_access($user_id) {
        update_user_meta($user_id, 'wpsg_access_status', 'lapsed');
        update_user_meta($user_id, 'wpsg_active_until', date('Y-m-d', time() - 86400));
        error_log("WPSG Stripe: Lapsed access for user {$user_id}");
    }

    public static function get_access_status($user_id) {
        return get_user_meta($user_id, 'wpsg_access_status', true);
    }
}
