<?php

/**
 * Registration form shortcode: [wpsg_register]
 * Renders prefix + domain dropdown + password + confirm-password.
 */
class WPSG_Shortcode {

    public static function render($atts = []) {
        if (is_user_logged_in()) {
            return '<p>You are already logged in. <a href="' . esc_url(admin_url()) . '">Go to Dashboard</a></p>';
        }

        $domains = self::get_domains();

        wp_enqueue_script(
            'initrix-register',
            WPSG_URL . 'assets/register.js',
            ['jquery'],
            WPSG_VERSION,
            true
        );
        wp_localize_script('initrix-register', 'initrix_ajax', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wpsg_register_nonce'),
            'publishable_key' => self::get_publishable_key(),
        ]);
        wp_enqueue_style(
            'initrix-register',
            WPSG_URL . 'assets/register.css',
            [],
            WPSG_VERSION
        );

        ob_start();
        ?>
        <div class="initrix-register-wrapper">
            <form id="initrix-register-form" method="post" action="" novalidate>
                <div class="initrix-field-group">
                    <label for="initrix-email-prefix">Email Address</label>
                    <div class="initrix-email-row">
                        <input type="text" id="initrix-email-prefix" name="email_prefix"
                               placeholder="you" required autocomplete="username"
                               pattern="[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+"
                               title="Letters, numbers, and standard email characters">
                        <span class="initrix-at">@</span>
                        <select id="initrix-domain" name="domain" required>
                            <?php foreach ($domains as $d): ?>
                                <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="initrix-email-preview">
                        Your email will be: <strong><span id="initrix-email-preview"></span></strong>
                    </div>
                </div>

                <div class="initrix-field-group">
                    <label for="initrix-password">Password</label>
                    <input type="password" id="initrix-password" name="password"
                           required autocomplete="new-password" minlength="8">
                    <div id="initrix-password-strength" class="initrix-strength-bar"></div>
                </div>

                <div class="initrix-field-group">
                    <label for="initrix-password-confirm">Confirm Password</label>
                    <input type="password" id="initrix-password-confirm" name="password_confirm"
                           required autocomplete="new-password">
                    <div id="initrix-password-match" class="initrix-validation-msg"></div>
                </div>

                <div id="initrix-form-errors" class="initrix-error-msg"></div>

                <div class="initrix-field-group">
                    <button type="submit" id="initrix-submit-btn" class="initrix-submit-btn">
                        Subscribe &mdash; Create Mailbox
                    </button>
                </div>

                <div class="initrix-payment-notice">
                    <small>&#x1f512; You will be redirected to Stripe for secure credit card payment. Annual subscription.</small>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_create_checkout() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpsg_register_nonce')) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh the page.']);
        }

        $email_prefix     = sanitize_text_field(wp_unslash($_POST['email_prefix'] ?? ''));
        $domain           = sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));
        $password         = wp_unslash($_POST['password'] ?? '');
        $password_confirm = wp_unslash($_POST['password_confirm'] ?? '');

        if (empty($email_prefix) || empty($domain) || empty($password)) {
            wp_send_json_error(['message' => 'All fields are required.']);
        }

        if ($password !== $password_confirm) {
            wp_send_json_error(['message' => 'Passwords do not match.']);
        }

        if (strlen($password) < 8) {
            wp_send_json_error(['message' => 'Password must be at least 8 characters.']);
        }

        if (!in_array($domain, self::get_domains(), true)) {
            wp_send_json_error(['message' => 'Invalid domain selected.']);
        }

        $result = WPSG_Checkout::create_session($email_prefix, $domain, $password);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success(['url' => $result['url']]);
    }

    public static function get_domains() {
        return apply_filters('wpsg_registration_domains', ['initrix.com']);
    }

    private static function get_publishable_key() {
        return defined('WPSG_STRIPE_PUBLISHABLE_KEY') ? WPSG_STRIPE_PUBLISHABLE_KEY : get_option('initrix_stripe_publishable_key', '');
    }
}
