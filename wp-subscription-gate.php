<?php
/**
 * Plugin Name: WP Subscription Gate
 * Description: Stripe Checkout annual subscription for hosted mailboxes. No paid plugin dependency.
 * Version: 1.2.0
 * Requires PHP: 8.3
 * Author: WPSG
 * Text Domain: wp-subscription-gate
 */

if (!defined('ABSPATH')) exit;

define('WPSG_VERSION', '1.2.0');
define('WPSG_PATH', plugin_dir_path(__FILE__));
define('WPSG_URL', plugin_dir_url(__FILE__));
define('WPSG_TEXT_DOMAIN', 'wp-subscription-gate');

// ---- Stripe SDK ----
require_once WPSG_PATH . 'vendor/stripe/stripe-php/init.php';

// ---- Translations ----
add_action('init', function () {
    load_plugin_textdomain(WPSG_TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
});

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
require_once WPSG_PATH . 'includes/class-settings.php';
require_once WPSG_PATH . 'includes/class-stripe-settings.php';
require_once WPSG_PATH . 'includes/class-notifications.php';
require_once WPSG_PATH . 'includes/class-refund.php';
require_once WPSG_PATH . 'includes/class-pages-settings.php';
require_once WPSG_PATH . 'includes/class-admin.php';

/**
 * Pages & behavior settings (single option array).
 */
function wpsg_pages_option() {
    static $opts = null;
    if ($opts === null) {
        $defaults = [
            'account_page_id'               => 0,
            'mail_login_page_id'            => 0,
            'login_url'                     => '',
            'webmail_url'                   => '',
            'after_login_redirect'          => 'default',
            'after_login_redirect_url'      => '',
            'hide_member_items_logged_out'  => 0,
            'hide_auth_items_logged_in'     => 0,
            'email_login_enabled'           => 1,
            'login_email_domain'            => '',
        ];
        $opts = wp_parse_args((array) get_option('wpsg_pages', []), $defaults);

        // Migrate the legacy single menu_mode value into the two visibility toggles.
        if (isset($opts['menu_mode'])) {
            $legacy = $opts['menu_mode'];
            unset($opts['menu_mode']);
            if ($legacy === 'pages' || $legacy === 'titles') {
                $opts['hide_member_items_logged_out'] = 1;
            }
            if ($legacy === 'titles') {
                $opts['hide_auth_items_logged_in'] = 1;
            }
            update_option('wpsg_pages', $opts);
        }
    }
    return $opts;
}

/**
 * Account page ID (portable). Configured page wins; otherwise falls back to
 * the legacy /profile/ or /account/ slugs for sites that upgraded.
 */
function wpsg_account_page_id() {
    $opts = wpsg_pages_option();
    if (!empty($opts['account_page_id'])) {
        return (int) $opts['account_page_id'];
    }
    $page = get_page_by_path('profile') ?: get_page_by_path('account');
    return $page ? (int) $page->ID : 0;
}

/**
 * Mailbox login page ID (portable). Configured page wins; otherwise falls
 * back to the legacy /mail/ or /email-login/ slugs.
 */
function wpsg_mail_login_page_id() {
    $opts = wpsg_pages_option();
    if (!empty($opts['mail_login_page_id'])) {
        return (int) $opts['mail_login_page_id'];
    }
    $page = get_page_by_path('mail') ?: get_page_by_path('email-login');
    return $page ? (int) $page->ID : 0;
}

/**
 * Webmail URL (portable). Configured value wins; otherwise derives
 * https://mail.<site-host>/.
 */
function wpsg_mail_url() {
    $opts = wpsg_pages_option();
    $url  = trim((string) $opts['webmail_url']);
    if ($url === '') {
        $url = 'https://mail.' . wpsg_current_host() . '/';
    }
    return apply_filters('wpsg_mail_url', $url);
}

/**
 * Login page URL (portable). Configured value wins; otherwise uses the
 * legacy /login-2/ page when present, else the standard wp-login.php.
 *
 * @param string $redirect Optional same-site URL to return to after login.
 */
function wpsg_login_url($redirect = '') {
    $opts = wpsg_pages_option();
    $url  = trim((string) $opts['login_url']);
    if ($url === '') {
        $url = get_page_by_path('login-2') ? home_url('/login-2/') : wp_login_url();
    }

    if ($redirect !== '') {
        $redirect = wp_validate_redirect($redirect, '');
        if ($redirect !== '') {
            $url = add_query_arg('redirect_to', $redirect, $url);
        }
    }

    return apply_filters('wpsg_login_url', $url, $redirect);
}

/**
 * Where users should land after logging in, as configured under
 * Settings -> Subscription Gate -> Pages & Behavior -> Login.
 */
function wpsg_after_login_url() {
    $opts   = wpsg_pages_option();
    $choice = isset($opts['after_login_redirect']) ? $opts['after_login_redirect'] : 'default';
    $url    = '';

    if ($choice === 'account') {
        $url = wpsg_account_url();
    } elseif ($choice === 'mailbox') {
        $url = wpsg_mail_login_page_url();
    } elseif ($choice === 'custom') {
        $url = trim((string) $opts['after_login_redirect_url']);
    }

    // Keep redirect_to destinations same-site.
    if ($url !== '') {
        $url = wp_validate_redirect($url, '');
    }

    return apply_filters('wpsg_after_login_url', $url);
}

/**
 * Account page URL (portable).
 */
function wpsg_account_url() {
    $id  = wpsg_account_page_id();
    $url = $id ? (get_permalink($id) ?: home_url('/')) : home_url('/');
    return apply_filters('wpsg_account_url', $url);
}

/**
 * Mailbox login page URL (portable).
 */
function wpsg_mail_login_page_url() {
    $id  = wpsg_mail_login_page_id();
    $url = $id ? (get_permalink($id) ?: home_url('/')) : home_url('/');
    return apply_filters('wpsg_mail_login_page_url', $url);
}

/**
 * Whether Account / Mailbox Login menu items should be hidden from
 * logged-out visitors. WPSG_DISABLE_MENU_FILTERS wins over the setting.
 */
function wpsg_menu_hide_member_items() {
    if (defined('WPSG_DISABLE_MENU_FILTERS') && WPSG_DISABLE_MENU_FILTERS) {
        return false;
    }
    $opts = wpsg_pages_option();
    return !empty($opts['hide_member_items_logged_out']);
}

/**
 * Whether Login / Registration menu items should be hidden from
 * logged-in visitors. WPSG_DISABLE_MENU_FILTERS wins over the setting.
 */
function wpsg_menu_hide_auth_items() {
    if (defined('WPSG_DISABLE_MENU_FILTERS') && WPSG_DISABLE_MENU_FILTERS) {
        return false;
    }
    $opts = wpsg_pages_option();
    return !empty($opts['hide_auth_items_logged_in']);
}

// ---- Stripe credential helpers (wp-config constants override DB options) ----
function wpsg_stripe_secret_key() {
    return (defined('WPSG_STRIPE_SECRET_KEY') && WPSG_STRIPE_SECRET_KEY !== '') ? WPSG_STRIPE_SECRET_KEY : get_option('wpsg_stripe_secret_key', '');
}

function wpsg_stripe_publishable_key() {
    return (defined('WPSG_STRIPE_PUBLISHABLE_KEY') && WPSG_STRIPE_PUBLISHABLE_KEY !== '') ? WPSG_STRIPE_PUBLISHABLE_KEY : get_option('wpsg_stripe_publishable_key', '');
}

function wpsg_stripe_price_id() {
    return (defined('WPSG_STRIPE_PRICE_ID') && WPSG_STRIPE_PRICE_ID !== '') ? WPSG_STRIPE_PRICE_ID : get_option('wpsg_stripe_price_id', '');
}

function wpsg_stripe_webhook_secret() {
    return (defined('WPSG_STRIPE_WEBHOOK_SECRET') && WPSG_STRIPE_WEBHOOK_SECRET !== '') ? WPSG_STRIPE_WEBHOOK_SECRET : get_option('wpsg_stripe_webhook_secret', '');
}

// ---- Register REST route (webhook) ----
add_action('rest_api_init', function () {
    WPSG_Webhook::register_route();
});

// ---- Register email notification settings ----
WPSG_Settings::register();

// ---- Register Stripe settings ----
WPSG_Stripe_Settings::register();

// ---- Register pages & behavior settings ----
WPSG_Pages_Settings::register();

// ---- Register single consolidated settings page ----
WPSG_Admin::register();

// ---- Register shortcodes ----
add_shortcode('wpsg_register',    ['WPSG_Shortcode', 'render']);
add_shortcode('wpsg_mail_login',  'wpsg_mail_login_shortcode');
add_shortcode('wpsg_account',     'wpsg_account_shortcode');
add_shortcode('wpsg_login',       'wpsg_login_shortcode');

// ---- Register AJAX handlers ----
add_action('wp_ajax_wpsg_create_checkout',        ['WPSG_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_nopriv_wpsg_create_checkout',  ['WPSG_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_wpsg_renew_checkout',          'wpsg_renew_checkout_ajax');
add_action('wp_ajax_wpsg_portal_session',          'wpsg_portal_session_ajax');
add_action('wp_ajax_wpsg_request_refund',          'wpsg_request_refund_ajax');

// ---- Frontend assets: registered here, enqueued only when a shortcode is present ----
add_action('wp_enqueue_scripts', 'wpsg_enqueue_frontend_assets');
function wpsg_enqueue_frontend_assets() {
    wp_register_style('wpsg-register', WPSG_URL . 'assets/register.css', [], WPSG_VERSION);
    wp_register_script('wpsg-register', WPSG_URL . 'assets/register.js', ['jquery'], WPSG_VERSION, true);

    $enqueue = false;
    if (is_singular()) {
        $post = get_post();
        if ($post && !empty($post->post_content)) {
            foreach (['wpsg_register', 'wpsg_mail_login', 'wpsg_account', 'wpsg_login'] as $tag) {
                if (has_shortcode($post->post_content, $tag)) {
                    $enqueue = true;
                    break;
                }
            }
        }
    }

    $enqueue = apply_filters('wpsg_enqueue_frontend_assets', $enqueue);

    if ($enqueue) {
        wp_enqueue_style('wpsg-register');
    }
    if ($enqueue && !is_user_logged_in()) {
        wp_enqueue_script('wpsg-register');
        wp_localize_script('wpsg-register', 'wpsg_ajax', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wpsg_register_nonce'),
            'publishable_key' => wpsg_stripe_publishable_key(),
        ]);
    }
}

// ---- Navigation menu behavior (opt-in via Settings -> WPSG Pages & Behavior) ----
// Member items (Account / Mailbox Login) can be hidden from logged-out visitors.
// Auth items (Login / Registration) can be hidden from logged-in visitors.
add_filter('wp_nav_menu_objects', 'wpsg_filter_nav_menu_objects', 10);
add_filter('wp_nav_menu_items', 'wpsg_filter_nav_menu_items', 10, 2);

function wpsg_urls_match($url1, $url2) {
    return untrailingslashit((string) $url1) === untrailingslashit((string) $url2);
}

function wpsg_filter_nav_menu_objects($items) {
    $hide_member = wpsg_menu_hide_member_items();
    $hide_auth   = wpsg_menu_hide_auth_items();

    if ((!$hide_member && !$hide_auth) || empty($items)) {
        return $items;
    }

    $logged_in     = is_user_logged_in();
    $account_id    = wpsg_account_page_id();
    $mail_login_id = wpsg_mail_login_page_id();

    foreach ($items as $key => $item) {
        $is_member = false;
        $is_auth   = false;

        // Member pages: match by configured page ID first, then custom-link URL.
        $object_id = isset($item->object_id) ? (int) $item->object_id : 0;
        if (($account_id && $object_id === $account_id) || ($mail_login_id && $object_id === $mail_login_id)) {
            $is_member = true;
        }
        if (!$is_member && isset($item->url)) {
            if ($account_id && wpsg_urls_match($item->url, get_permalink($account_id))) {
                $is_member = true;
            }
            if (!$is_member && $mail_login_id && wpsg_urls_match($item->url, get_permalink($mail_login_id))) {
                $is_member = true;
            }
        }
        // Title fallback for legacy menus and sites without configured pages.
        if (!$is_member && in_array($item->title, ['Account', 'Email Login'], true)) {
            $is_member = true;
        }
        if (in_array($item->title, ['Login', 'Registration'], true)) {
            $is_auth = true;
        }

        if ($hide_auth && $is_auth && $logged_in) {
            unset($items[$key]);
            continue;
        }

        if ($hide_member && $is_member && !$logged_in) {
            unset($items[$key]);
        }
    }

    return $items;
}

function wpsg_filter_nav_menu_items($items, $args) {
    if (!wpsg_menu_hide_member_items() && !wpsg_menu_hide_auth_items()) {
        return $items;
    }
    if (is_user_logged_in()) {
        $logout_url = wp_logout_url(home_url());
        $items .= '<li class="menu-item menu-item-logout"><a href="' . esc_url($logout_url) . '">' . esc_html__('Logout', WPSG_TEXT_DOMAIN) . '</a></li>';
    }
    return $items;
}

// ---- Allow login with email prefix when bare username not found ----
add_filter('authenticate', function ($user, $username, $password) {
    // Only intervene when default auth says "invalid username" and no @ in input
    if (!is_wp_error($user) || $user->get_error_code() !== 'invalid_username') {
        return $user;
    }
    if (strpos($username, '@') !== false) {
        return $user;
    }

    $opts = wpsg_pages_option();
    if (empty($opts['email_login_enabled'])) {
        return $user;
    }

    $domain = trim((string) $opts['login_email_domain']);
    if ($domain === '') {
        $domain = wpsg_current_host();
    }

    $full_email = $username . '@' . $domain;
    $wp_user    = get_user_by('login', $full_email);

    if (!$wp_user) {
        return $user; // still invalid — let the original error stand
    }

    if (!wp_check_password($password, $wp_user->user_pass, $wp_user->ID)) {
        return new WP_Error('incorrect_password', __('<strong>Error:</strong> The password you entered is incorrect.', WPSG_TEXT_DOMAIN));
    }

    return $wp_user;
}, 30, 3);

// ---- Auto-redirect active users from /mail/ straight to Roundcube ----
add_action('template_redirect', function () {
    $mail_login_slugs = apply_filters('wpsg_mail_login_slugs', []);
    if (!empty($mail_login_slugs)) {
        if (!is_page($mail_login_slugs)) return;
    } else {
        $mail_login_id = wpsg_mail_login_page_id();
        if (!$mail_login_id || !is_page($mail_login_id)) return;
    }
    if (!is_user_logged_in()) return;

    $user   = wp_get_current_user();
    $status = WPSG_Provisioner::get_access_status($user->ID);

    // Active and lifetime users go straight to webmail
    if ($status === 'lifetime' || ($status && strpos($status, 'active_until:') === 0)) {
        wp_redirect(wpsg_mail_url());
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
    $uid = wp_unique_id('wpsg-mail-');
    wp_enqueue_style('wpsg-register');

    if (!is_user_logged_in()) {
        ?>
        <div class="wpsg-mail-login">
            <p><?php esc_html_e('Please log in to access your mailbox.', WPSG_TEXT_DOMAIN); ?></p>
            <a href="<?php echo esc_url(wpsg_login_url(wpsg_after_login_url())); ?>" class="wpsg-btn"><?php esc_html_e('Log In', WPSG_TEXT_DOMAIN); ?></a>
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
            <p><?php esc_html_e('⚠️ Your mailbox access has been suspended because your subscription lapsed. Your mail is preserved and you will regain access as soon as you renew.', WPSG_TEXT_DOMAIN); ?></p>
            <button id="<?php echo esc_attr($uid); ?>-renew-btn" class="wpsg-btn wpsg-btn-renew"><?php esc_html_e('Renew Subscription', WPSG_TEXT_DOMAIN); ?></button>
            <p id="<?php echo esc_attr($uid); ?>-renew-error" class="wpsg-error-msg" style="display:none;"></p>
        </div>
        <script>
        document.getElementById('<?php echo esc_js($uid); ?>-renew-btn').addEventListener('click', function() {
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
                    document.getElementById('<?php echo esc_js($uid); ?>-renew-error').textContent = data.data.message || 'Something went wrong. Please try again.';
                    document.getElementById('<?php echo esc_js($uid); ?>-renew-error').style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Renew Subscription';
                }
            })
            .catch(function() {
                document.getElementById('<?php echo esc_js($uid); ?>-renew-error').textContent = 'Network error. Please try again.';
                document.getElementById('<?php echo esc_js($uid); ?>-renew-error').style.display = 'block';
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
        <a href="<?php echo esc_url(wpsg_mail_url()); ?>" class="wpsg-btn wpsg-btn-primary"><?php esc_html_e('Go to Webmail', WPSG_TEXT_DOMAIN); ?></a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * [wpsg_login] shortcode — thin branded wrapper around the configured
 * WordPress login URL. Logged-in users get account/webmail shortcuts.
 */
function wpsg_login_shortcode() {
    ob_start();
    wp_enqueue_style('wpsg-register');

    if (is_user_logged_in()) {
        ?>
        <div class="wpsg-login wpsg-login-logged-in">
            <p><?php esc_html_e('You are already logged in.', WPSG_TEXT_DOMAIN); ?></p>
            <a href="<?php echo esc_url(wpsg_account_url()); ?>" class="wpsg-btn wpsg-btn-primary"><?php esc_html_e('Manage Account', WPSG_TEXT_DOMAIN); ?></a>
            <a href="<?php echo esc_url(wpsg_mail_url()); ?>" class="wpsg-btn"><?php esc_html_e('Go to Webmail', WPSG_TEXT_DOMAIN); ?></a>
        </div>
        <?php
        return ob_get_clean();
    }

    $login_url = wpsg_login_url(wpsg_after_login_url());
    ?>
    <div class="wpsg-login">
        <p><?php esc_html_e('Log in to manage your mailbox and subscription.', WPSG_TEXT_DOMAIN); ?></p>
        <a href="<?php echo esc_url($login_url); ?>" class="wpsg-btn wpsg-btn-primary"><?php esc_html_e('Log In', WPSG_TEXT_DOMAIN); ?></a>
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
            <p><?php esc_html_e('Please log in to view your account.', WPSG_TEXT_DOMAIN); ?></p>
            <a href="<?php echo esc_url(wpsg_login_url(wpsg_after_login_url())); ?>" class="wpsg-btn"><?php esc_html_e('Log In', WPSG_TEXT_DOMAIN); ?></a>
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

    $uid = wp_unique_id('wpsg-account-');
    wp_enqueue_style('wpsg-register');
    ?>

    <div class="wpsg-account">
        <h2><?php esc_html_e('Subscription', WPSG_TEXT_DOMAIN); ?></h2>

        <?php if ($status === 'lifetime'): ?>
            <p><?php esc_html_e('Status:', WPSG_TEXT_DOMAIN); ?> <strong><?php esc_html_e('Lifetime', WPSG_TEXT_DOMAIN); ?></strong></p>
            <p><?php esc_html_e('Your mailbox has permanent access.', WPSG_TEXT_DOMAIN); ?></p>
        <?php elseif ($status && strpos($status, 'active_until:') === 0): ?>
            <p><?php esc_html_e('Status:', WPSG_TEXT_DOMAIN); ?> <strong><?php esc_html_e('Active', WPSG_TEXT_DOMAIN); ?></strong></p>
            <p><?php esc_html_e('Your mailbox is active until', WPSG_TEXT_DOMAIN); ?> <strong><?php echo esc_html($until); ?></strong>.</p>
            <?php if ($customer_id): ?>
                <p>
                    <button id="<?php echo esc_attr($uid); ?>-manage-btn" class="wpsg-btn wpsg-btn-primary"><?php esc_html_e('Manage Subscription', WPSG_TEXT_DOMAIN); ?></button>
                </p>
                <p id="<?php echo esc_attr($uid); ?>-portal-error" class="wpsg-error-msg" style="display:none;"></p>
                <script>
                document.getElementById('<?php echo esc_js($uid); ?>-manage-btn').addEventListener('click', function() {
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
                            document.getElementById('<?php echo esc_js($uid); ?>-portal-error').textContent = data.data.message || 'Something went wrong.';
                            document.getElementById('<?php echo esc_js($uid); ?>-portal-error').style.display = 'block';
                            btn.disabled = false;
                            btn.textContent = 'Manage Subscription';
                        }
                    })
                    .catch(function() {
                        document.getElementById('<?php echo esc_js($uid); ?>-portal-error').textContent = 'Network error. Please try again.';
                        document.getElementById('<?php echo esc_js($uid); ?>-portal-error').style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Manage Subscription';
                    });
                });
                </script>
            <?php else: ?>
                <p><em><?php esc_html_e('Subscription management is not available for this account. Please contact support for changes.', WPSG_TEXT_DOMAIN); ?></em></p>
            <?php endif; ?>
            <?php if (WPSG_Refund::is_eligible($user->ID)): ?>
                <p>
                    <button id="<?php echo esc_attr($uid); ?>-refund-btn" class="wpsg-btn wpsg-btn-secondary"><?php esc_html_e('Request Refund', WPSG_TEXT_DOMAIN); ?></button>
                </p>
                <p class="wpsg-refund-policy"><?php esc_html_e('Not satisfied? New subscribers can request a full refund within 30 days of signup.', WPSG_TEXT_DOMAIN); ?></p>
                <p id="<?php echo esc_attr($uid); ?>-refund-error" class="wpsg-error-msg" style="display:none;"></p>
                <script>
                document.getElementById('<?php echo esc_js($uid); ?>-refund-btn').addEventListener('click', function() {
                    if (!confirm('Are you sure you want to request a full refund? Your mailbox access will end immediately.')) {
                        return;
                    }
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = 'Processing…';
                    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wpsg_request_refund&_ajax_nonce=<?php echo esc_js(wp_create_nonce('wpsg_refund_nonce')); ?>'
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            btn.textContent = 'Refund processed. Reloading…';
                            setTimeout(function() { window.location.reload(); }, 1500);
                        } else {
                            document.getElementById('<?php echo esc_js($uid); ?>-refund-error').textContent = data.data.message || 'Something went wrong. Please try again.';
                            document.getElementById('<?php echo esc_js($uid); ?>-refund-error').style.display = 'block';
                            btn.disabled = false;
                            btn.textContent = 'Request Refund';
                        }
                    })
                    .catch(function() {
                        document.getElementById('<?php echo esc_js($uid); ?>-refund-error').textContent = 'Network error. Please try again.';
                        document.getElementById('<?php echo esc_js($uid); ?>-refund-error').style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Request Refund';
                    });
                });
                </script>
            <?php endif; ?>
        <?php elseif ($status === 'lapsed'): ?>
            <p><?php esc_html_e('Status:', WPSG_TEXT_DOMAIN); ?> <strong><?php esc_html_e('Lapsed', WPSG_TEXT_DOMAIN); ?></strong></p>
            <p><?php esc_html_e('Your subscription has ended. Your mail data is preserved.', WPSG_TEXT_DOMAIN); ?></p>
            <p>
                <button id="<?php echo esc_attr($uid); ?>-renew-btn" class="wpsg-btn wpsg-btn-renew"><?php esc_html_e('Renew Subscription', WPSG_TEXT_DOMAIN); ?></button>
            </p>
            <p id="<?php echo esc_attr($uid); ?>-renew-error" class="wpsg-error-msg" style="display:none;"></p>
            <script>
            document.getElementById('<?php echo esc_js($uid); ?>-renew-btn').addEventListener('click', function() {
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
                        document.getElementById('<?php echo esc_js($uid); ?>-renew-error').textContent = data.data.message || 'Something went wrong. Please try again.';
                        document.getElementById('<?php echo esc_js($uid); ?>-renew-error').style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Renew Subscription';
                    }
                })
                .catch(function() {
                    document.getElementById('<?php echo esc_js($uid); ?>-renew-error').textContent = 'Network error. Please try again.';
                    document.getElementById('<?php echo esc_js($uid); ?>-renew-error').style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Renew Subscription';
                });
            });
            </script>
        <?php else: ?>
            <p><?php esc_html_e('Status:', WPSG_TEXT_DOMAIN); ?> <strong><?php esc_html_e('Unknown', WPSG_TEXT_DOMAIN); ?></strong></p>
            <p><?php esc_html_e('Please contact support.', WPSG_TEXT_DOMAIN); ?></p>
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
        wp_send_json_error(['message' => __('You must be logged in.', WPSG_TEXT_DOMAIN)]);
    }

    $user        = wp_get_current_user();
    $customer_id = get_user_meta($user->ID, 'wpsg_stripe_customer_id', true);

    // Handle serialized blobs
    if (!$customer_id || !is_string($customer_id)) {
        wp_send_json_error(['message' => __('No Stripe account linked. Please contact support.', WPSG_TEXT_DOMAIN)]);
    }

    \Stripe\Stripe::setApiKey(wpsg_stripe_secret_key());

    try {
        $session = \Stripe\BillingPortal\Session::create([
            'customer'   => $customer_id,
            'return_url' => wpsg_account_url(),
        ]);

        wp_send_json_success(['url' => $session->url]);
    } catch (\Exception $e) {
        error_log("WPSG Stripe: Portal session failed for user {$user->ID}: " . $e->getMessage());
        wp_send_json_error(['message' => __('Could not open subscription management. Please try again.', WPSG_TEXT_DOMAIN)]);
    }
}

/**
 * AJAX handler: creates a Stripe Checkout session for a lapsed user to renew.
 */
function wpsg_renew_checkout_ajax() {
    check_ajax_referer('wpsg_renew_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in.', WPSG_TEXT_DOMAIN)]);
    }

    $user   = wp_get_current_user();
    $status = WPSG_Provisioner::get_access_status($user->ID);

    // Only allow lapsed users to renew
    if ($status !== 'lapsed') {
        wp_send_json_error(['message' => __('Your subscription is still active.', WPSG_TEXT_DOMAIN)]);
    }

    \Stripe\Stripe::setApiKey(wpsg_stripe_secret_key());

    $price_id = wpsg_stripe_price_id();

    try {
        $session = \Stripe\Checkout\Session::create([
            'mode'              => 'subscription',
            'line_items'        => [['price' => $price_id, 'quantity' => 1]],
            'success_url'       => wpsg_mail_login_page_url(),
            'cancel_url'        => wpsg_mail_login_page_url(),
            'customer_email'    => $user->user_email,
            'metadata'          => [
                'is_renewal'  => '1',
                'user_id'     => $user->ID,
            ],
        ]);

        wp_send_json_success(['url' => $session->url]);
    } catch (\Exception $e) {
        error_log("WPSG Stripe: Renew checkout failed for user {$user->ID}: " . $e->getMessage());
        wp_send_json_error(['message' => __('Could not create checkout session. Please try again.', WPSG_TEXT_DOMAIN)]);
    }
}

/**
 * AJAX handler: processes a self-serve 30-day refund request.
 */
function wpsg_request_refund_ajax() {
    check_ajax_referer('wpsg_refund_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in.', WPSG_TEXT_DOMAIN)]);
    }

    $user   = wp_get_current_user();
    $result = WPSG_Refund::process($user->ID);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success(['message' => __('Your refund has been processed.', WPSG_TEXT_DOMAIN)]);
}

// ---- Activation hook ----
register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
    if (!get_role('subscriber')) {
        add_role('subscriber', 'Subscriber', ['read' => true]);
    }
    if (!get_option('wpsg_notifications')) {
        add_option('wpsg_notifications', WPSG_Settings::defaults());
    }
});
