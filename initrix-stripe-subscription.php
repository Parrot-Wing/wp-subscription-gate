<?php
/**
 * Plugin Name: Initrix Stripe Subscription
 * Description: Stripe Checkout annual subscription for Initrix mailboxes. No paid plugin dependency.
 * Version: 1.0.0
 * Requires PHP: 8.3
 * Author: Initrix
 */

if (!defined('ABSPATH')) exit;

define('INITRIX_STRIPE_VERSION', '1.0.0');
define('INITRIX_STRIPE_PATH', plugin_dir_path(__FILE__));
define('INITRIX_STRIPE_URL', plugin_dir_url(__FILE__));

// ---- Stripe SDK ----
require_once INITRIX_STRIPE_PATH . 'vendor/stripe/stripe-php/init.php';

// ---- Our Classes ----
require_once INITRIX_STRIPE_PATH . 'includes/class-provisioner.php';
require_once INITRIX_STRIPE_PATH . 'includes/class-webhook.php';
require_once INITRIX_STRIPE_PATH . 'includes/class-checkout.php';
require_once INITRIX_STRIPE_PATH . 'includes/class-shortcode.php';

// ---- Register REST route (webhook) ----
add_action('rest_api_init', function () {
    Initrix_Webhook::register_route();
});

// ---- Register shortcode ----
add_shortcode('initrix_register', ['Initrix_Shortcode', 'render']);

// ---- Register AJAX handlers ----
add_action('wp_ajax_initrix_create_checkout',        ['Initrix_Shortcode', 'ajax_create_checkout']);
add_action('wp_ajax_nopriv_initrix_create_checkout',  ['Initrix_Shortcode', 'ajax_create_checkout']);

// ---- Activation hook ----
register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
    if (!get_role('subscriber')) {
        add_role('subscriber', 'Subscriber', ['read' => true]);
    }
});
