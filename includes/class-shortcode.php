<?php

/**
 * Registration form shortcode: [wpsg_register]
 * Renders prefix + domain dropdown + password + confirm-password.
 *
 * Assets (register.js / register.css) are registered and enqueued centrally in
 * wp-subscription-gate.php via wpsg_enqueue_frontend_assets().
 */
class WPSG_Shortcode {

    public static function render($atts = []) {
        if (is_user_logged_in()) {
            return '<p>' . esc_html__('You are already logged in.', WPSG_TEXT_DOMAIN) . ' <a href="' . esc_url(admin_url()) . '">' . esc_html__('Go to Dashboard', WPSG_TEXT_DOMAIN) . '</a></p>';
        }

        $domains = self::get_domains();

        // Fallback for contexts where wpsg_enqueue_frontend_assets() didn't run
        // (widgets, do_shortcode outside post content, etc.).
        if (!wp_script_is('wpsg-register', 'enqueued')) {
            wp_enqueue_script('wpsg-register');
            wp_localize_script('wpsg-register', 'wpsg_ajax', [
                'ajax_url'        => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('wpsg_register_nonce'),
                'publishable_key' => self::get_publishable_key(),
            ]);
        }
        wp_enqueue_style('wpsg-register');

        ob_start();
        ?>
        <div class="wpsg-register-wrapper">
            <form id="wpsg-register-form" method="post" action="" novalidate>
                <div class="wpsg-field-group">
                    <label for="wpsg-email-prefix"><?php esc_html_e('Email Address', WPSG_TEXT_DOMAIN); ?></label>
                    <div class="wpsg-email-row">
                        <input type="text" id="wpsg-email-prefix" name="email_prefix"
                               placeholder="<?php esc_attr_e('username', WPSG_TEXT_DOMAIN); ?>" required autocomplete="username"
                               pattern="[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+"
                               title="<?php esc_attr_e('Letters, numbers, and standard email characters', WPSG_TEXT_DOMAIN); ?>">
                        <span class="wpsg-at">@</span>
                        <select id="wpsg-domain" name="domain" required>
                            <?php foreach ($domains as $d): ?>
                                <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wpsg-email-preview">
                        <?php esc_html_e('Your email will be:', WPSG_TEXT_DOMAIN); ?> <strong><span id="wpsg-email-preview"></span></strong>
                    </div>
                </div>

                <div class="wpsg-field-group">
                    <label for="wpsg-password"><?php esc_html_e('Password', WPSG_TEXT_DOMAIN); ?></label>
                    <input type="password" id="wpsg-password" name="password"
                           required autocomplete="new-password" minlength="8">
                    <div id="wpsg-password-strength" class="wpsg-strength-bar"></div>
                </div>

                <div class="wpsg-field-group">
                    <label for="wpsg-password-confirm"><?php esc_html_e('Confirm Password', WPSG_TEXT_DOMAIN); ?></label>
                    <input type="password" id="wpsg-password-confirm" name="password_confirm"
                           required autocomplete="new-password">
                    <div id="wpsg-password-match" class="wpsg-validation-msg"></div>
                </div>

                <div id="wpsg-form-errors" class="wpsg-error-msg"></div>

                <div class="wpsg-field-group">
                    <button type="submit" id="wpsg-submit-btn" class="wpsg-submit-btn">
                        <?php esc_html_e('Subscribe — Create Mailbox', WPSG_TEXT_DOMAIN); ?>
                    </button>
                </div>

                <div class="wpsg-payment-notice">
                    <small>&#x1f512; <?php esc_html_e('You will be redirected to Stripe for secure credit card payment. Annual subscription.', WPSG_TEXT_DOMAIN); ?></small>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_create_checkout() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpsg_register_nonce')) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page.', WPSG_TEXT_DOMAIN)]);
        }

        $email_prefix     = sanitize_text_field(wp_unslash($_POST['email_prefix'] ?? ''));
        $domain           = sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));
        $password         = wp_unslash($_POST['password'] ?? '');
        $password_confirm = wp_unslash($_POST['password_confirm'] ?? '');

        if (empty($email_prefix) || empty($domain) || empty($password)) {
            wp_send_json_error(['message' => __('All fields are required.', WPSG_TEXT_DOMAIN)]);
        }

        if ($password !== $password_confirm) {
            wp_send_json_error(['message' => __('Passwords do not match.', WPSG_TEXT_DOMAIN)]);
        }

        if (strlen($password) < 8) {
            wp_send_json_error(['message' => __('Password must be at least 8 characters.', WPSG_TEXT_DOMAIN)]);
        }

        if (!in_array($domain, self::get_domains(), true)) {
            wp_send_json_error(['message' => __('Invalid domain selected.', WPSG_TEXT_DOMAIN)]);
        }

        $result = WPSG_Checkout::create_session($email_prefix, $domain, $password);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success(['url' => $result['url']]);
    }

    public static function get_domains() {
        return apply_filters('wpsg_registration_domains', [wp_parse_url(home_url(), PHP_URL_HOST)]);
    }

    private static function get_publishable_key() {
        return wpsg_stripe_publishable_key();
    }
}
