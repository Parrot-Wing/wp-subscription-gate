<?php

/**
 * Admin settings for WP Subscription Gate pages & behavior.
 * Registers a Settings API page: Settings → WPSG Pages & Behavior.
 */

if (!defined('ABSPATH')) exit;

class WPSG_Pages_Settings {

    const OPTION = 'wpsg_pages';
    const GROUP  = 'wpsg_pages_group';

    public static function register() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_settings() {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    public static function defaults() {
        return [
            'account_page_id'     => 0,
            'mail_login_page_id'  => 0,
            'login_url'           => '',
            'webmail_url'         => '',
            'menu_mode'           => 'off',
            'email_login_enabled' => 1,
            'login_email_domain'  => '',
        ];
    }

    public static function get() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function sanitize($input) {
        $input = (array) $input;
        $out   = self::defaults();

        $out['account_page_id']     = isset($input['account_page_id']) ? absint($input['account_page_id']) : 0;
        $out['mail_login_page_id']  = isset($input['mail_login_page_id']) ? absint($input['mail_login_page_id']) : 0;
        $out['login_url']           = isset($input['login_url']) ? esc_url_raw(wp_unslash($input['login_url'])) : '';
        $out['webmail_url']         = isset($input['webmail_url']) ? esc_url_raw(wp_unslash($input['webmail_url'])) : '';

        $menu_mode     = isset($input['menu_mode']) ? sanitize_key(wp_unslash($input['menu_mode'])) : 'off';
        $out['menu_mode'] = in_array($menu_mode, ['off', 'pages', 'titles'], true) ? $menu_mode : 'off';

        $out['email_login_enabled'] = !empty($input['email_login_enabled']) ? 1 : 0;

        $domain = isset($input['login_email_domain']) ? sanitize_text_field(wp_unslash($input['login_email_domain'])) : '';
        $out['login_email_domain']  = ltrim($domain, '@');

        return $out;
    }

    public static function render_form() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts          = self::get();
        $account_id    = wpsg_account_page_id();
        $mail_login_id = wpsg_mail_login_page_id();
        ?>
        <div class="notice notice-info" style="padding:10px 14px;">
                <p><strong><?php esc_html_e('How it works', WPSG_TEXT_DOMAIN); ?></strong></p>
                <ol style="margin-left:1.4em;list-style:decimal;">
                    <li>
                        <?php esc_html_e('Create three normal WordPress pages, each containing one shortcode:', WPSG_TEXT_DOMAIN); ?>
                        <ul style="margin-left:1.4em;list-style:disc;">
                            <li><code>[wpsg_register]</code> — <?php esc_html_e('the signup form.', WPSG_TEXT_DOMAIN); ?></li>
                            <li><code>[wpsg_mail_login]</code> — <?php esc_html_e('mailbox login / subscription-status check.', WPSG_TEXT_DOMAIN); ?></li>
                            <li><code>[wpsg_account]</code> — <?php esc_html_e('account management for logged-in users.', WPSG_TEXT_DOMAIN); ?></li>
                        </ul>
                    </li>
                    <li><?php esc_html_e('Pick those pages below. The plugin then uses them for its “Log In”, renewal, and Stripe return links instead of guessing page slugs.', WPSG_TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Decide whether the plugin may adjust your navigation menu (see Menu behavior). Menu changes are off by default so a new site is never surprised.', WPSG_TEXT_DOMAIN); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>

                <h2><?php esc_html_e('Page mapping', WPSG_TEXT_DOMAIN); ?></h2>
                <p class="description"><?php esc_html_e('Leave a field blank to use automatic detection (legacy slugs such as /profile/, /account/, /mail/, /email-login/, and the default WordPress login URL).', WPSG_TEXT_DOMAIN); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpsg-account-page"><?php esc_html_e('Account page', WPSG_TEXT_DOMAIN); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages([
                                'name'               => self::OPTION . '[account_page_id]',
                                'id'                 => 'wpsg-account-page',
                                'selected'           => $opts['account_page_id'],
                                'show_option_none'   => __('— Auto-detect (legacy) —', WPSG_TEXT_DOMAIN),
                                'option_none_value'  => '0',
                            ]);
                            ?>
                            <p class="description"><?php esc_html_e('The page that contains [wpsg_account].', WPSG_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpsg-mail-login-page"><?php esc_html_e('Mailbox login page', WPSG_TEXT_DOMAIN); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages([
                                'name'               => self::OPTION . '[mail_login_page_id]',
                                'id'                 => 'wpsg-mail-login-page',
                                'selected'           => $opts['mail_login_page_id'],
                                'show_option_none'   => __('— Auto-detect (legacy) —', WPSG_TEXT_DOMAIN),
                                'option_none_value'  => '0',
                            ]);
                            ?>
                            <p class="description"><?php esc_html_e('The page that contains [wpsg_mail_login]. Active subscribers are redirected from this page straight to webmail.', WPSG_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpsg-login-url"><?php esc_html_e('Login URL', WPSG_TEXT_DOMAIN); ?></label></th>
                        <td>
                            <input type="url" id="wpsg-login-url" name="<?php echo esc_attr(self::OPTION . '[login_url]'); ?>" value="<?php echo esc_attr($opts['login_url']); ?>" class="regular-text" placeholder="<?php echo esc_attr(wp_login_url()); ?>" />
                            <p class="description"><?php esc_html_e('Where the “Log In” links should point. Leave blank for the default WordPress login (shown as the placeholder).', WPSG_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpsg-webmail-url"><?php esc_html_e('Webmail URL', WPSG_TEXT_DOMAIN); ?></label></th>
                        <td>
                            <input type="url" id="wpsg-webmail-url" name="<?php echo esc_attr(self::OPTION . '[webmail_url]'); ?>" value="<?php echo esc_attr($opts['webmail_url']); ?>" class="regular-text" placeholder="<?php echo esc_attr('https://mail.' . wpsg_current_host() . '/'); ?>" />
                            <p class="description"><?php esc_html_e('The webmail application URL. Leave blank to use https://mail.&lt;your-domain&gt;/.', WPSG_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Menu behavior', WPSG_TEXT_DOMAIN); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpsg-menu-mode"><?php esc_html_e('Menu mode', WPSG_TEXT_DOMAIN); ?></label></th>
                        <td>
                            <select id="wpsg-menu-mode" name="<?php echo esc_attr(self::OPTION . '[menu_mode]'); ?>">
                                <option value="off" <?php selected('off', $opts['menu_mode']); ?>><?php esc_html_e('Off — leave my menus alone', WPSG_TEXT_DOMAIN); ?></option>
                                <option value="pages" <?php selected('pages', $opts['menu_mode']); ?>><?php esc_html_e('Pages — hide Account and Mailbox Login from logged-out visitors by page', WPSG_TEXT_DOMAIN); ?></option>
                                <option value="titles" <?php selected('titles', $opts['menu_mode']); ?>><?php esc_html_e('Titles — legacy: also hide Login / Registration by exact title', WPSG_TEXT_DOMAIN); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('“Pages” hides the Account and Mailbox Login menu items from logged-out visitors; logged-in visitors see those items unchanged. “Titles” additionally hides items named exactly “Login” and “Registration” while logged in.', WPSG_TEXT_DOMAIN); ?>
                            </p>
                            <p class="description">
                                <?php esc_html_e('To disable menu changes without visiting this screen, add this to wp-config.php:', WPSG_TEXT_DOMAIN); ?>
                                <code>define( 'WPSG_DISABLE_MENU_FILTERS', true );</code>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Login', WPSG_TEXT_DOMAIN); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Email-prefix login', WPSG_TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION . '[email_login_enabled]'); ?>" value="1" <?php checked(1, $opts['email_login_enabled']); ?> />
                                <?php esc_html_e('Allow users to log in with just their email prefix (for example “alice” instead of “alice@example.com”).', WPSG_TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpsg-login-domain"><?php esc_html_e('Mailbox domain', WPSG_TEXT_DOMAIN); ?></label></th>
                        <td>
                            <input type="text" id="wpsg-login-domain" name="<?php echo esc_attr(self::OPTION . '[login_email_domain]'); ?>" value="<?php echo esc_attr($opts['login_email_domain']); ?>" class="regular-text" placeholder="<?php echo esc_attr(wpsg_current_host()); ?>" />
                            <p class="description"><?php esc_html_e('Appended to a bare login name. Leave blank to use this site’s domain.', WPSG_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Currently resolved values', WPSG_TEXT_DOMAIN); ?></h2>
            <div style="max-width:720px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;">
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><?php esc_html_e('Account page', WPSG_TEXT_DOMAIN); ?></td>
                        <td><code><?php echo esc_html($account_id ? get_the_title($account_id) . ' — ' . get_permalink($account_id) : '—'); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Mailbox login page', WPSG_TEXT_DOMAIN); ?></td>
                        <td><code><?php echo esc_html($mail_login_id ? get_the_title($mail_login_id) . ' — ' . get_permalink($mail_login_id) : '—'); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Login URL', WPSG_TEXT_DOMAIN); ?></td>
                        <td><code><?php echo esc_html(wpsg_login_url()); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Webmail URL', WPSG_TEXT_DOMAIN); ?></td>
                        <td><code><?php echo esc_html(wpsg_mail_url()); ?></code></td>
                    </tr>
                </tbody>
            </table>
            </div>
        <?php
    }
}
