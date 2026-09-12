<?php

/**
 * Admin settings for WP Subscription Gate Stripe credentials.
 * Registers a Settings API page: Settings → Subscription Gate Stripe.
 *
 * The four values written here are the same `wpsg_stripe_*` options the
 * plugin already falls back to when the `WPSG_STRIPE_*` wp-config.php
 * constants are not defined.
 */

if (!defined('ABSPATH')) exit;

class WPSG_Stripe_Settings {

    const GROUP = 'wpsg_stripe_settings_group';
    const PAGE  = 'wpsg-stripe';

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_menu() {
        add_options_page(
            'Subscription Gate Stripe',
            'Subscription Gate Stripe',
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        foreach (self::fields() as $id => $field) {
            register_setting(self::GROUP, $id, [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize'],
            ]);
        }
    }

    /**
     * Sanitize a saved value.
     *
     * - Fields overridden by a wp-config.php constant are left untouched.
     * - Secret fields are never rendered back into the form, so an empty
     *   submit means "keep the current value".
     */
    public static function sanitize($value, $option = '') {
        if ($option && self::is_overridden($option)) {
            return (string) get_option($option, '');
        }

        $value = sanitize_text_field(wp_unslash((string) $value));

        if ($option && self::is_secret_field($option) && $value === '') {
            return (string) get_option($option, '');
        }

        return $value;
    }

    /**
     * Map option names to their wp-config.php constant equivalents.
     */
    private static function constants() {
        return [
            'wpsg_stripe_secret_key'      => 'WPSG_STRIPE_SECRET_KEY',
            'wpsg_stripe_publishable_key' => 'WPSG_STRIPE_PUBLISHABLE_KEY',
            'wpsg_stripe_price_id'        => 'WPSG_STRIPE_PRICE_ID',
            'wpsg_stripe_webhook_secret'  => 'WPSG_STRIPE_WEBHOOK_SECRET',
        ];
    }

    /**
     * True when the matching WPSG_STRIPE_* constant is defined and non-empty,
     * which is the same condition the plugin helpers use to decide precedence.
     */
    public static function is_overridden($option) {
        $constants = self::constants();
        if (empty($constants[$option])) {
            return false;
        }
        $constant = $constants[$option];
        return defined($constant) && constant($constant) !== '';
    }

    /**
     * True when at least one field is controlled by wp-config.php.
     */
    public static function has_overrides() {
        foreach (self::fields() as $id => $field) {
            if (self::is_overridden($id)) {
                return true;
            }
        }
        return false;
    }

    private static function is_secret_field($option) {
        return $option === 'wpsg_stripe_secret_key' || $option === 'wpsg_stripe_webhook_secret';
    }

    private static function fields() {
        return [
            'wpsg_stripe_secret_key' => [
                'label'       => 'Secret key',
                'type'        => 'password',
                'description' => 'Starts with sk_test_ or sk_live_. Leave blank to keep the current value.',
            ],
            'wpsg_stripe_publishable_key' => [
                'label'       => 'Publishable key',
                'type'        => 'text',
                'description' => 'Starts with pk_test_ or pk_live_.',
            ],
            'wpsg_stripe_price_id' => [
                'label'       => 'Price ID',
                'type'        => 'text',
                'description' => 'Starts with price_.',
            ],
            'wpsg_stripe_webhook_secret' => [
                'label'       => 'Webhook signing secret',
                'type'        => 'password',
                'description' => 'Starts with whsec_. Leave blank to keep the current value.',
            ],
        ];
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Subscription Gate Stripe', 'wp-subscription-gate'); ?></h1>
            <?php if (self::has_overrides()) : ?>
                <div class="notice notice-warning inline">
                    <p><strong>Some values are controlled by wp-config.php.</strong> The WPSG_STRIPE_* constants take priority over this page, so the fields below are disabled while those constants are defined.</p>
                </div>
            <?php endif; ?>
            <p>
                <?php esc_html_e('Paste the values from your Stripe dashboard here. Values defined in wp-config.php (WPSG_STRIPE_* constants) take priority over the values saved on this page.', 'wp-subscription-gate'); ?>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <?php foreach (self::fields() as $id => $field) : ?>
                        <?php
                        $overridden  = self::is_overridden($id);
                        $value       = $overridden ? '' : ($field['type'] === 'password' ? '' : (string) get_option($id, ''));
                        $placeholder = $overridden ? 'Set in wp-config.php' : '';
                        $disabled    = $overridden ? ' disabled' : '';
                        ?>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label>
                            </th>
                            <td>
                                <input type="<?php echo esc_attr($field['type']); ?>"
                                       id="<?php echo esc_attr($id); ?>"
                                       name="<?php echo esc_attr($id); ?>"
                                       value="<?php echo esc_attr($value); ?>"
                                       placeholder="<?php echo esc_attr($placeholder); ?>"
                                       class="regular-text"
                                       autocomplete="new-password"<?php echo $disabled; ?> />
                                <?php if ($overridden) : ?>
                                    <p class="description"><strong>Active source: wp-config.php.</strong> This field is disabled because the matching constant takes priority.</p>
                                <?php else : ?>
                                    <p class="description"><?php echo esc_html($field['description']); ?></p>
                                    <p class="description">Active source: settings page.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
