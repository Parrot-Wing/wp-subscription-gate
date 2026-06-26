<?php
/**
 * Plugin Name: WP Subscription Gate
 * Description: Stripe Checkout annual subscription for Initrix mailboxes. No paid plugin dependency.
 * Version: 1.0.0
 * Requires PHP: 8.3
 * Author: Initrix
 */

if (!defined('ABSPATH')) exit;

define('WPSG_VERSION', '1.0.0');
define('WPSG_PATH', plugin_dir_path(__FILE__));
define('WPSG_URL', plugin_dir_url(__FILE__));

// ---- Stripe SDK ----
require_once WPSG_PATH . 'vendor/stripe/stripe-php/init.php';

// ---- Our Classes ----
require_once WPSG_PATH . 'includes/class-provisioner.php';
require_once WPSG_PATH . 'includes/class-webhook.php';
require_once WPSG_PATH . 'includes/class-checkout.php';
require_once WPSG_PATH . 'includes/class-shortcode.php';

// ---- Register REST route (webhook) ----
add_action('rest_api_init', function () {
    WPSG_Webhook::register_route();
});

// ---- Register shortcodes ----
add_shortcode('wpsg_register',    ['WPSG_Shortcode', 'render']);
add_shortcode('wpsg_mail_login',  'wpsg_mail_login_shortcode');

// ---- Register AJAX handlers ----
add_action('wp_ajax_wpsg_create_checkout',        ['WPSG_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_nopriv_wpsg_create_checkout',  ['WPSG_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_wpsg_renew_checkout',          'wpsg_renew_checkout_ajax');

// ---- Conditional navigation menu ----
// Logged out: show "Login" and "Registration"
// Logged in:  show "Email Inbox" and "Account Management"
add_filter('wp_nav_menu_objects', function ($items) {
    $logged_in = is_user_logged_in();
    foreach ($items as $key => $item) {
        if ($item->title === 'Login' || $item->title === 'Registration') {
            if ($logged_in) {
                unset($items[$key]);
            }
        }
        if ($item->title === 'Email Login' || $item->title === 'Account') {
            if (!$logged_in) {
                unset($items[$key]);
            }
        }
    }
    return $items;
});

// Rename menu items for logged-in users
add_filter('wp_nav_menu_objects', function ($items) {
    foreach ($items as $item) {
        if ($item->title === 'Email Login') {
            $item->title = 'Email Inbox';
        }
        if ($item->title === 'Account') {
            $item->title = 'Account Management';
        }
    }
    return $items;
});

/**
 * [wpsg_mail_login] shortcode — subscription status check + Roundcube redirect or lapse warning.
 */
function wpsg_mail_login_shortcode() {
    ob_start();

    if (!is_user_logged_in()) {
        ?>
        <div class="wpsg-mail-login">
            <p>Please log in to access your mailbox.</p>
            <a href="<?php echo esc_url(home_url('/login-2/')); ?>" class="wpsg-btn">Log In</a>
        </div>
        <?php
        return ob_get_clean();
    }

    $user   = wp_get_current_user();
    $status = WPSG_Provisioner::get_access_status($user->ID);

    if ($status === 'lifetime') {
        ?>
        <div class="wpsg-mail-login">
            <a href="https://mail.initrix.com/" class="wpsg-btn wpsg-btn-primary">Go to Webmail</a>
        </div>
        <?php
        return ob_get_clean();
    }

    if ($status && strpos($status, 'active_until:') === 0) {
        $date = substr($status, 13);
        ?>
        <div class="wpsg-mail-login">
            <p>Your mailbox is active until <strong><?php echo esc_html($date); ?></strong>.</p>
            <a href="https://mail.initrix.com/" class="wpsg-btn wpsg-btn-primary">Go to Webmail</a>
        </div>
        <?php
        return ob_get_clean();
    }

    // Lapsed — show renewal prompt
    if ($status === 'lapsed') {
        ?>
        <div class="wpsg-mail-login wpsg-mail-login-lapsed">
            <p>⚠️ Your mailbox access has been suspended because your subscription lapsed. Your mail is preserved and you will regain access as soon as you renew.</p>
            <button id="wpsg-renew-btn" class="wpsg-btn wpsg-btn-renew">Renew Subscription</button>
            <p id="wpsg-renew-error" class="wpsg-error-msg" style="display:none;"></p>
        </div>
        <script>
        document.getElementById('wpsg-renew-btn').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Connecting to Stripe…';
            fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=wpsg_renew_checkout&_ajax_nonce=<?php echo esc_js(wp_create_nonce('wpsg_renew_nonce')); ?>'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.data.url) {
                    window.location.href = data.data.url;
                } else {
                    document.getElementById('wpsg-renew-error').textContent = data.data.message || 'Something went wrong. Please try again.';
                    document.getElementById('wpsg-renew-error').style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Renew Subscription';
                }
            })
            .catch(function() {
                document.getElementById('wpsg-renew-error').textContent = 'Network error. Please try again.';
                document.getElementById('wpsg-renew-error').style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Renew Subscription';
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // Unknown/falsy status — allow (fail-safe)
    ?>
    <div class="wpsg-mail-login">
        <a href="https://mail.initrix.com/" class="wpsg-btn wpsg-btn-primary">Go to Webmail</a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * AJAX handler: creates a Stripe Checkout session for a lapsed user to renew.
 */
function wpsg_renew_checkout_ajax() {
    check_ajax_referer('wpsg_renew_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $user   = wp_get_current_user();
    $status = WPSG_Provisioner::get_access_status($user->ID);

    // Only allow lapsed users to renew
    if ($status !== 'lapsed') {
        wp_send_json_error(['message' => 'Your subscription is still active.']);
    }

    \Stripe\Stripe::setApiKey(
        defined('WPSG_STRIPE_SECRET_KEY') ? WPSG_STRIPE_SECRET_KEY : get_option('wpsg_stripe_secret_key', '')
    );

    $price_id = defined('WPSG_STRIPE_PRICE_ID') ? WPSG_STRIPE_PRICE_ID : get_option('wpsg_stripe_price_id', '');

    try {
        $session = \Stripe\Checkout\Session::create([
            'mode'              => 'subscription',
            'line_items'        => [['price' => $price_id, 'quantity' => 1]],
            'success_url'       => home_url('/mail/'),
            'cancel_url'        => home_url('/mail/'),
            'metadata'          => [
                'is_renewal'  => '1',
                'user_id'     => $user->ID,
            ],
        ]);

        wp_send_json_success(['url' => $session->url]);
    } catch (\Exception $e) {
        error_log("Initrix Stripe: Renew checkout failed for user {$user->ID}: " . $e->getMessage());
        wp_send_json_error(['message' => 'Could not create checkout session. Please try again.']);
    }
}

// ---- Activation hook ----
register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
    if (!get_role('subscriber')) {
        add_role('subscriber', 'Subscriber', ['read' => true]);
    }
});
