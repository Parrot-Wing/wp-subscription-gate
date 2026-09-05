<?php

/**
 * Admin settings for WP Subscription Gate email notifications.
 * Registers a Settings API page: Settings → Subscription Gate Emails.
 */

if (!defined('ABSPATH')) exit;

class WPSG_Settings {

    const OPTION = 'wpsg_notifications';
    const GROUP  = 'wpsg_notifications_group';

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_menu() {
        add_options_page(
            'Subscription Gate Emails',
            'Subscription Gate Emails',
            'manage_options',
            'wpsg-emails',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    /**
     * Sensible defaults, used until the admin customizes them.
     */
    public static function defaults() {
        $admin = get_option('admin_email');
        return [
            // Welcome (new user)
            'welcome_enabled'          => 1,
            'welcome_subject'          => 'Your new mailbox is ready',
            'welcome_body'             => "Hi %user_login%,\n\nYour mailbox has been created and is ready to use.\n\nEmail address: %user_email%\nWebmail: %mail_url%\nSubscription active until: %expiry_date%\n\nWelcome aboard,\nTeam %blogname%",

            // Admin notification (new signup) — includes IP
            'admin_new_user_enabled'   => 1,
            'admin_recipients'         => $admin,
            'admin_new_user_subject'   => 'New user registration: %user_email%',
            'admin_new_user_body'      => "A new user has registered on %domain%.\n\nUsername: %user_login%\nEmail: %user_email%\nIP address: %user_ip%\nSubscription active until: %expiry_date%",

            // Renewal (user)
            'renewal_user_enabled'     => 1,
            'renewal_user_subject'     => 'Your subscription has been renewed',
            'renewal_user_body'        => "Hi %user_login%,\n\nYour subscription has been renewed and your mailbox access is active until %expiry_date%.\n\nWebmail: %mail_url%\n\nThank you,\nTeam %blogname%",

            // Renewal (admin)
            'renewal_admin_enabled'    => 1,
            'renewal_admin_recipients' => $admin,
            'renewal_admin_subject'    => 'Subscription renewed: %user_email%',
            'renewal_admin_body'       => "A user has renewed their subscription on %domain%.\n\nUsername: %user_login%\nEmail: %user_email%\nActive until: %expiry_date%",
        ];
    }

    public static function get() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function sanitize($input) {
        $input = (array) $input;
        $out   = [];
        foreach (self::fields() as $id => $type) {
            if ($type === 'checkbox') {
                $out[$id] = !empty($input[$id]) ? 1 : 0;
            } elseif ($type === 'textarea') {
                $out[$id] = isset($input[$id]) ? sanitize_textarea_field(wp_unslash($input[$id])) : self::defaults()[$id];
            } else {
                $out[$id] = isset($input[$id]) ? sanitize_text_field(wp_unslash($input[$id])) : self::defaults()[$id];
            }
        }
        return $out;
    }

    private static function fields() {
        $fields = [];
        foreach (self::email_types() as $type) {
            foreach ($type['fields'] as $f) {
                $fields[$f['id']] = $f['type'];
            }
        }
        return $fields;
    }

    private static function email_types() {
        $common = ['%user_login%', '%user_email%', '%firstname%', '%lastname%', '%blogname%', '%siteurl%', '%domain%', '%mail_url%', '%expiry_date%'];
        return [
            'welcome' => [
                'label'        => 'Welcome email (sent to new user)',
                'placeholders' => $common,
                'fields'       => [
                    ['id' => 'welcome_enabled', 'type' => 'checkbox', 'label' => 'Welcome email'],
                    ['id' => 'welcome_subject', 'type' => 'text',     'label' => 'Subject'],
                    ['id' => 'welcome_body',    'type' => 'textarea', 'label' => 'Body'],
                ],
            ],
            'admin_new_user' => [
                'label'        => 'Admin notification (new signup)',
                'placeholders' => array_merge($common, ['%user_ip%']),
                'fields'       => [
                    ['id' => 'admin_new_user_enabled', 'type' => 'checkbox', 'label' => 'Admin notification'],
                    ['id' => 'admin_recipients',        'type' => 'text',     'label' => 'Recipients (comma-separated)'],
                    ['id' => 'admin_new_user_subject',  'type' => 'text',     'label' => 'Subject'],
                    ['id' => 'admin_new_user_body',     'type' => 'textarea', 'label' => 'Body'],
                ],
            ],
            'renewal_user' => [
                'label'        => 'Renewal email (sent to renewing user)',
                'placeholders' => $common,
                'fields'       => [
                    ['id' => 'renewal_user_enabled', 'type' => 'checkbox', 'label' => 'Renewal email'],
                    ['id' => 'renewal_user_subject', 'type' => 'text',     'label' => 'Subject'],
                    ['id' => 'renewal_user_body',    'type' => 'textarea', 'label' => 'Body'],
                ],
            ],
            'renewal_admin' => [
                'label'        => 'Renewal notification (sent to admins)',
                'placeholders' => $common,
                'fields'       => [
                    ['id' => 'renewal_admin_enabled',    'type' => 'checkbox', 'label' => 'Renewal admin notification'],
                    ['id' => 'renewal_admin_recipients', 'type' => 'text',     'label' => 'Recipients (comma-separated)'],
                    ['id' => 'renewal_admin_subject',    'type' => 'text',     'label' => 'Subject'],
                    ['id' => 'renewal_admin_body',       'type' => 'textarea', 'label' => 'Body'],
                ],
            ],
        ];
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opts = self::get();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Subscription Gate Emails', 'wp-subscription-gate'); ?></h1>
            <p><?php esc_html_e('Use placeholders like %user_email% and %user_ip% (admin emails only). The sender (From) address is controlled by Easy WP SMTP.', 'wp-subscription-gate'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <?php foreach (self::email_types() as $type) : ?>
                    <h2><?php echo esc_html($type['label']); ?></h2>
                    <table class="form-table" role="presentation">
                        <?php foreach ($type['fields'] as $f) : ?>
                            <tr>
                                <th scope="row"><label for="<?php echo esc_attr($f['id']); ?>"><?php echo esc_html($f['label']); ?></label></th>
                                <td>
                                    <?php
                                    $name = self::OPTION . '[' . $f['id'] . ']';
                                    $val  = isset($opts[$f['id']]) ? $opts[$f['id']] : '';
                                    if ($f['type'] === 'checkbox') : ?>
                                        <label><input type="checkbox" id="<?php echo esc_attr($f['id']); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked(1, $val); ?> /> <?php esc_html_e('Enabled', 'wp-subscription-gate'); ?></label>
                                    <?php elseif ($f['type'] === 'textarea') : ?>
                                        <textarea id="<?php echo esc_attr($f['id']); ?>" name="<?php echo esc_attr($name); ?>" rows="8" class="large-text code"><?php echo esc_textarea($val); ?></textarea>
                                    <?php else : ?>
                                        <input type="text" id="<?php echo esc_attr($f['id']); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($val); ?>" class="regular-text" />
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Available placeholders', 'wp-subscription-gate'); ?></th>
                            <td><code><?php echo esc_html(implode('  ', $type['placeholders'])); ?></code></td>
                        </tr>
                    </table>
                <?php endforeach; ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
