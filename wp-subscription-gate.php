<?php
/**
 * Plugin Name: WP Subscription Gate
 * Description: Stripe Checkout annual subscription for hosted mailboxes. No paid plugin dependency.
 * Version: 1.0.0
 * Requires PHP: 8.3
 * Author: WPSG
 */

if (!defined('ABSPATH')) exit;

define('WPSG_VERSION', '1.0.0');
define('WPSG_PATH', plugin_dir_path(__FILE__));
define('WPSG_URL', plugin_dir_url(__FILE__));

// ---- Stripe SDK ----
require_once WPSG_PATH . 'vendor/stripe/stripe-php/init.php';

// ---- Helper: current hostname (with fallback) ----
function wpsg_current_host() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    return $host ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
}


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
add_shortcode('wpsg_account',     'wpsg_account_shortcode');

// ---- Register AJAX handlers ----
add_action('wp_ajax_wpsg_create_checkout',        ['WPSG_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_nopriv_wpsg_create_checkout',  ['WPSG_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_wpsg_renew_checkout',          'wpsg_renew_checkout_ajax');
add_action('wp_ajax_wpsg_portal_session',          'wpsg_portal_session_ajax');

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

// Rename menu items for logged-in users and fix Account URL
add_filter('wp_nav_menu_objects', function ($items) {
    foreach ($items as $item) {
        if ($item->title === 'Email Login') {
            $item->title = 'Email Inbox';
        }
        if ($item->title === 'Account') {
            $item->title = 'Account Management';
            $item->url   = home_url('/profile/');
        }
    }
    return $items;
});

// Append logout link to navigation menu for logged-in users
add_filter('wp_nav_menu_items', function ($items) {
    if (is_user_logged_in()) {
        $logout_url = wp_logout_url(home_url());
        $items .= '<li class="menu-item menu-item-logout"><a href="' . esc_url($logout_url) . '">Logout</a></li>';
    }
    return $items;
});

// ---- Allow login with email prefix when bare username not found ----
add_filter('authenticate', function ($user, $username, $password) {
    // Only intervene when default auth says "invalid username" and no @ in input
    if (!is_wp_error($user) || $user->get_error_code() !== 'invalid_username') {
        return $user;
    }
    if (strpos($username, '@') !== false) {
        return $user;
    }

    $full_email = $username . '@' . wpsg_current_host();
    $wp_user    = get_user_by('login', $full_email);

    if (!$wp_user) {
        return $user; // still invalid — let the original error stand
    }

    if (!wp_check_password($password, $wp_user->user_pass, $wp_user->ID)) {
        return new WP_Error('incorrect_password', __('<strong>Error:</strong> The password you entered is incorrect.'));
    }

    return $wp_user;
}, 30, 3);

// ---- Auto-redirect active users from /mail/ straight to Roundcube ----
add_action('template_redirect', function () {
    if (!is_page('mail')) return;
    if (!is_user_logged_in()) return;

    $user   = wp_get_current_user();
    $status = WPSG_Provisioner::get_access_status($user->ID);

    // Active and lifetime users go straight to webmail
    if ($status === 'lifetime' || ($status && strpos($status, 'active_until:') === 0)) {
        wp_redirect('https://mail.' . wpsg_current_host() . '/');
        exit;
    }
    // Lapsed and unknown users see the page content
});

/**
 * [wpsg_mail_login] shortcode — fallback for lapsed / logged-out users.
 * Active users are auto-redirected by template_redirect above.
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

    // Lapsed — show renewal prompt
    if ($status === 'lapsed') {
        ?>
        <div class="wpsg-mail-login wpsg-mail-login-lapsed">
            <p>⚠️ Your mailbox access has been suspended because your subscription lapsed. Your mail is preserved and you will regain access as soon as you renew.</p>
            <button id="wpsg-renew-btn-mail" class="wpsg-btn wpsg-btn-renew">Renew Subscription</button>
            <p id="wpsg-renew-error-mail" class="wpsg-error-msg" style="display:none;"></p>
        </div>
        <script>
        document.getElementById('wpsg-renew-btn-mail').addEventListener('click', function() {
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
                    document.getElementById('wpsg-renew-error-mail').textContent = data.data.message || 'Something went wrong. Please try again.';
                    document.getElementById('wpsg-renew-error-mail').style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Renew Subscription';
                }
            })
            .catch(function() {
                document.getElementById('wpsg-renew-error-mail').textContent = 'Network error. Please try again.';
                document.getElementById('wpsg-renew-error-mail').style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Renew Subscription';
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // Active/lifetime should never reach here (redirected), but fail-safe:
    ?>
    <div class="wpsg-mail-login">
        <a href="<?php echo esc_url('https://mail.' . wpsg_current_host() . '/'); ?>" class="wpsg-btn wpsg-btn-primary">Go to Webmail</a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * [wpsg_account] shortcode — subscription status + Stripe Customer Portal.
 */
function wpsg_account_shortcode() {
    ob_start();

    if (!is_user_logged_in()) {
        ?>
        <div class="wpsg-account">
            <p>Please log in to view your account.</p>
            <a href="<?php echo esc_url(home_url('/login-2/')); ?>" class="wpsg-btn">Log In</a>
        </div>
        <?php
        return ob_get_clean();
    }

    $user   = wp_get_current_user();
    $status = WPSG_Provisioner::get_access_status($user->ID);
    $until  = get_user_meta($user->ID, 'wpsg_active_until', true);
    $customer_id = get_user_meta($user->ID, 'wpsg_stripe_customer_id', true);

    // Clean up serialized blobs — extract just the ID string
    if ($customer_id && !is_string($customer_id)) {
        $customer_id = '';
    }

    wp_enqueue_style('wpsg-register', WPSG_URL . 'assets/register.css', [], WPSG_VERSION);
    ?>

    <div class="wpsg-account">
        <h2>Subscription</h2>

        <?php if ($status === 'lifetime'): ?>
            <p>Status: <strong>Lifetime</strong></p>
            <p>Your mailbox has permanent access.</p>
        <?php elseif ($status && strpos($status, 'active_until:') === 0): ?>
            <p>Status: <strong>Active</strong></p>
            <p>Your mailbox is active until <strong><?php echo esc_html($until); ?></strong>.</p>
            <?php if ($customer_id): ?>
                <p>
                    <button id="wpsg-manage-btn" class="wpsg-btn wpsg-btn-primary">Manage Subscription</button>
                </p>
                <p id="wpsg-portal-error" class="wpsg-error-msg" style="display:none;"></p>
                <script>
                document.getElementById('wpsg-manage-btn').addEventListener('click', function() {
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = 'Loading…';
                    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wpsg_portal_session&_ajax_nonce=<?php echo esc_js(wp_create_nonce('wpsg_portal_nonce')); ?>'
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data.url) {
                            window.location.href = data.data.url;
                        } else {
                            document.getElementById('wpsg-portal-error').textContent = data.data.message || 'Something went wrong.';
                            document.getElementById('wpsg-portal-error').style.display = 'block';
                            btn.disabled = false;
                            btn.textContent = 'Manage Subscription';
                        }
                    })
                    .catch(function() {
                        document.getElementById('wpsg-portal-error').textContent = 'Network error. Please try again.';
                        document.getElementById('wpsg-portal-error').style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Manage Subscription';
                    });
                });
                </script>
            <?php else: ?>
                <p><em>Subscription management is not available for this account. Please contact support for changes.</em></p>
            <?php endif; ?>
        <?php elseif ($status === 'lapsed'): ?>
            <p>Status: <strong>Lapsed</strong></p>
            <p>Your subscription has ended. Your mail data is preserved.</p>
            <p>
                <button id="wpsg-renew-btn" class="wpsg-btn wpsg-btn-renew">Renew Subscription</button>
            </p>
            <p id="wpsg-renew-error" class="wpsg-error-msg" style="display:none;"></p>
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
        <?php else: ?>
            <p>Status: <strong>Unknown</strong></p>
            <p>Please contact support.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * AJAX handler: creates a Stripe Customer Portal session.
 */
function wpsg_portal_session_ajax() {
    check_ajax_referer('wpsg_portal_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $user        = wp_get_current_user();
    $customer_id = get_user_meta($user->ID, 'wpsg_stripe_customer_id', true);

    // Handle serialized blobs
    if (!$customer_id || !is_string($customer_id)) {
        wp_send_json_error(['message' => 'No Stripe account linked. Please contact support.']);
    }

    \Stripe\Stripe::setApiKey(
        defined('WPSG_STRIPE_SECRET_KEY') ? WPSG_STRIPE_SECRET_KEY : get_option('wpsg_stripe_secret_key', '')
    );

    try {
        $session = \Stripe\BillingPortal\Session::create([
            'customer'   => $customer_id,
            'return_url' => home_url('/profile/'),
        ]);

        wp_send_json_success(['url' => $session->url]);
    } catch (\Exception $e) {
        error_log("WPSG Stripe: Portal session failed for user {$user->ID}: " . $e->getMessage());
        wp_send_json_error(['message' => 'Could not open subscription management. Please try again.']);
    }
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
            'customer_email'    => $user->user_email,
            'metadata'          => [
                'is_renewal'  => '1',
                'user_id'     => $user->ID,
            ],
        ]);

        wp_send_json_success(['url' => $session->url]);
    } catch (\Exception $e) {
        error_log("WPSG Stripe: Renew checkout failed for user {$user->ID}: " . $e->getMessage());
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
