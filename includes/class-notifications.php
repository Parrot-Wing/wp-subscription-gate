<?php

/**
 * Sends email notifications for WP Subscription Gate.
 * Templates and toggles are read from WPSG_Settings (wpsg_notifications option).
 */

if (!defined('ABSPATH')) exit;

class WPSG_Notifications {

    /**
     * Welcome email → new user.
     */
    public static function send_welcome($user_id) {
        $s = WPSG_Settings::get();
        if (empty($s['welcome_enabled'])) {
            return false;
        }
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        $data = self::build_user_data($user);
        return wp_mail(
            $user->user_email,
            self::render($s['welcome_subject'], $data),
            self::render($s['welcome_body'], $data)
        );
    }

    /**
     * Admin notification → new signup (includes IP).
     */
    public static function send_admin_new_user($user_id, $ip = '') {
        $s = WPSG_Settings::get();
        if (empty($s['admin_new_user_enabled'])) {
            return false;
        }
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        $data = self::build_user_data($user);
        $data['user_ip'] = $ip;
        return wp_mail(
            self::parse_recipients($s['admin_recipients']),
            self::render($s['admin_new_user_subject'], $data),
            self::render($s['admin_new_user_body'], $data)
        );
    }

    /**
     * Renewal email → renewing user.
     */
    public static function send_renewal_user($user_id) {
        $s = WPSG_Settings::get();
        if (empty($s['renewal_user_enabled'])) {
            return false;
        }
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        $data = self::build_user_data($user);
        return wp_mail(
            $user->user_email,
            self::render($s['renewal_user_subject'], $data),
            self::render($s['renewal_user_body'], $data)
        );
    }

    /**
     * Renewal notification → admins.
     */
    public static function send_renewal_admin($user_id) {
        $s = WPSG_Settings::get();
        if (empty($s['renewal_admin_enabled'])) {
            return false;
        }
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        $data = self::build_user_data($user);
        return wp_mail(
            self::parse_recipients($s['renewal_admin_recipients']),
            self::render($s['renewal_admin_subject'], $data),
            self::render($s['renewal_admin_body'], $data)
        );
    }

    /**
     * Assemble placeholder data for a user.
     */
    private static function build_user_data($user) {
        $until = get_user_meta($user->ID, 'wpsg_active_until', true);
        return [
            'user_login'  => $user->user_login,
            'user_email'  => $user->user_email,
            'firstname'   => $user->first_name,
            'lastname'    => $user->last_name,
            'blogname'    => get_option('blogname'),
            'siteurl'     => home_url(),
            'domain'      => wp_parse_url(home_url(), PHP_URL_HOST),
            'mail_url'    => wpsg_mail_url(),
            'expiry_date' => $until ? $until : '',
        ];
    }

    /**
     * Replace %placeholders% in a template.
     */
    private static function render($template, $data) {
        $keys   = [];
        $values = [];
        foreach ($data as $k => $v) {
            $keys[]   = '%' . $k . '%';
            $values[] = $v;
        }
        return str_replace($keys, $values, $template);
    }

    /**
     * Parse a comma-separated recipient string into a validated array.
     */
    private static function parse_recipients($raw) {
        $parts = array_map('trim', explode(',', (string) $raw));
        $parts = array_values(array_filter($parts, 'is_email'));
        return $parts ? $parts : [get_option('admin_email')];
    }
}
