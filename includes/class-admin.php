<?php

/**
 * Single admin page for all WP Subscription Gate settings.
 * Renders one Settings page with tabs: Stripe, Pages & Behavior, Emails.
 */

if (!defined('ABSPATH')) exit;

class WPSG_Admin {

    const PAGE = 'wpsg-settings';

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
    }

    public static function add_menu() {
        add_options_page(
            __('Subscription Gate Settings', WPSG_TEXT_DOMAIN),
            __('Subscription Gate', WPSG_TEXT_DOMAIN),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    public static function current_tab() {
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'stripe';
        $tabs = self::tabs();
        return isset($tabs[$tab]) ? $tab : 'stripe';
    }

    private static function tabs() {
        return [
            'stripe' => [
                'label'    => __('Stripe', WPSG_TEXT_DOMAIN),
                'callback' => ['WPSG_Stripe_Settings', 'render_form'],
            ],
            'pages'  => [
                'label'    => __('Pages & Behavior', WPSG_TEXT_DOMAIN),
                'callback' => ['WPSG_Pages_Settings', 'render_form'],
            ],
            'emails' => [
                'label'    => __('Emails', WPSG_TEXT_DOMAIN),
                'callback' => ['WPSG_Settings', 'render_form'],
            ],
        ];
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab  = self::current_tab();
        $tabs = self::tabs();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Subscription Gate Settings', WPSG_TEXT_DOMAIN); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $item) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::PAGE, 'tab' => $key], admin_url('options-general.php'))); ?>"
                       class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($item['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="wpsg-tab-panel" style="margin-top:1em;">
                <?php call_user_func($tabs[$tab]['callback']); ?>
            </div>
        </div>
        <?php
    }
}
