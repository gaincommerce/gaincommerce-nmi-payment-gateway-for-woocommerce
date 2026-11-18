<?php
/**
 * Live / Test / Sandbox environment switcher.
 *
 * One "Environment" dropdown replaces the old "Test mode" and "Pathfinder
 * Sandbox" checkboxes. The two legacy option keys (`testmode`,
 * `pathfinder_sandbox`) are still written on every save, so every other part
 * of the plugin (API endpoint, wallets, review tracking) keeps working
 * unchanged. Stores that never save the new field keep their current mode,
 * which is derived from the legacy keys.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Environment
{
    public const LIVE    = 'live';
    public const TEST    = 'test';
    public const SANDBOX = 'sandbox';

    /**
     * Current environment: the saved choice, or derived from the legacy options.
     */
    public static function get(?array $settings = null): string
    {
        if ( null === $settings ) {
            $settings = get_option(AP_NMI_WC_GATEWAY_SETTINGS_ID, []);
        }
        if ( ! is_array($settings) ) {
            $settings = [];
        }

        $saved = $settings['environment'] ?? '';
        if ( in_array($saved, [self::LIVE, self::TEST, self::SANDBOX], true) ) {
            return $saved;
        }

        // Existing install that has not saved the new field yet.
        if ( 'yes' === ( $settings['pathfinder_sandbox'] ?? 'no' ) ) {
            return self::SANDBOX; // The sandbox endpoint wins, same as before.
        }
        if ( 'yes' === ( $settings['testmode'] ?? 'no' ) ) {
            return self::TEST;
        }
        return self::LIVE;
    }

    public static function is_live(): bool
    {
        return self::LIVE === self::get();
    }

    public static function is_sandbox(): bool
    {
        return self::SANDBOX === self::get();
    }

    /** Test or Sandbox: anything that is not live. */
    public static function is_non_live(): bool
    {
        return self::LIVE !== self::get();
    }

    /**
     * Tokenization (public) key for the active environment.
     *
     * In Sandbox the dedicated sandbox key is used. If it is empty, the main
     * key is used, so stores that already typed their sandbox keys into the
     * main fields keep working.
     */
    public static function public_key(?array $settings = null): string
    {
        return self::resolve_key('public_key', 'sandbox_public_key', $settings);
    }

    /** Private (API security) key for the active environment. Same fallback as public_key(). */
    public static function private_key(?array $settings = null): string
    {
        return self::resolve_key('private_key', 'sandbox_private_key', $settings);
    }

    private static function resolve_key(string $live_key, string $sandbox_key, ?array $settings): string
    {
        if ( null === $settings ) {
            $settings = get_option(AP_NMI_WC_GATEWAY_SETTINGS_ID, []);
        }
        if ( ! is_array($settings) ) {
            $settings = [];
        }

        if ( self::SANDBOX === self::get($settings) && ! empty($settings[$sandbox_key]) ) {
            return (string) $settings[$sandbox_key];
        }
        return (string) ( $settings[$live_key] ?? '' );
    }

    /**
     * Mirror the chosen environment onto the legacy option keys.
     *
     * @param array $settings Gateway settings array.
     * @return array
     */
    public static function apply_legacy(array $settings): array
    {
        $env = self::get($settings);

        $settings['environment']        = $env;
        $settings['testmode']           = self::TEST === $env ? 'yes' : 'no';
        $settings['pathfinder_sandbox'] = self::SANDBOX === $env ? 'yes' : 'no';

        return $settings;
    }

    public static function label(?string $env = null): string
    {
        switch ( $env ?? self::get() ) {
            case self::TEST:
                return __('TEST MODE', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
            case self::SANDBOX:
                return __('SANDBOX MODE', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
            default:
                return __('LIVE', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
        }
    }

    /* ------------------------------------------------------------------ *
     * Front-end / admin indicators
     * ------------------------------------------------------------------ */

    public static function init(): void
    {
        // Optional "NMI Settings" shortcut in the admin bar (off by default).
        add_action('admin_bar_menu', [self::class, 'admin_bar_node'], 100);

        // Notice above the block checkout (classic checkout shows it in the payment box).
        add_filter('render_block_woocommerce/checkout', [self::class, 'prepend_block_checkout_notice'], 10, 1);
    }

    /**
     * Optional "NMI Settings" shortcut in the admin bar. Off by default;
     * switched on with the "Admin bar shortcut" setting.
     */
    public static function admin_bar_node(\WP_Admin_Bar $bar): void
    {
        $settings = get_option(AP_NMI_WC_GATEWAY_SETTINGS_ID, []);
        if ( ! is_array($settings) || 'yes' !== ( $settings['admin_bar_menu'] ?? 'no' ) ) {
            return;
        }
        if ( ! current_user_can('manage_woocommerce') ) {
            return;
        }

        $bar->add_node([
            'id'    => 'apnmi-settings',
            'title' => esc_html__('NMI Settings', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            'href'  => admin_url('admin.php?page=wc-settings&tab=nmi'),
        ]);
    }

    /**
     * Plain-text notice shown to shoppers while not live.
     */
    public static function checkout_notice_text(): string
    {
        if ( self::is_live() ) {
            return '';
        }

        return sprintf(
            /* translators: 1: environment name, 2: test card number */
            __('%1$s: this store is not taking real payments. Use a test card such as %2$s, any future expiry date and CVV 999. Do not enter real card details.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            self::label(),
            '4111 1111 1111 1111'
        );
    }

    public static function prepend_block_checkout_notice($content)
    {
        $text = self::checkout_notice_text();
        if ( '' === $text || ! is_string($content) ) {
            return $content;
        }

        return '<div class="apnmi-env-notice" style="background:#fff4e5;border-left:4px solid #b32d2e;padding:10px 14px;margin:0 0 16px;font-weight:600">'
            . esc_html($text) . '</div>' . $content;
    }

    /* ------------------------------------------------------------------ *
     * Settings-page helpers
     * ------------------------------------------------------------------ */

    /**
     * Status box + test card helper printed at the top of the NMI Settings tab.
     */
    public static function render_admin_panel(): void
    {
        $env = self::get();

        $styles = [
            self::LIVE    => ['#00a32a', '#edfaef'],
            self::TEST    => ['#dba617', '#fcf9e8'],
            self::SANDBOX => ['#2271b1', '#f0f6fc'],
        ];
        $messages = [
            self::LIVE    => __('LIVE — real cards are charged. Use this once you have finished testing.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            self::TEST    => __('TEST MODE — use test cards only. Your NMI account must also be in Test Mode in the NMI portal, otherwise a real card could still be charged.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            self::SANDBOX => __('SANDBOX MODE — nothing real is charged. Requests go to NMI\'s separate sandbox (sandbox.nmi.com), so enter your sandbox keys below.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
        ];
        list($border, $bg) = $styles[$env];

        printf(
            '<div class="apnmi-env-status" style="border-left:4px solid %1$s;background:%2$s;padding:12px 16px;margin:12px 0 4px;max-width:900px"><strong>%3$s</strong></div>',
            esc_attr($border),
            esc_attr($bg),
            esc_html($messages[$env])
        );

        if ( self::LIVE === $env ) {
            return;
        }
        ?>
        <details class="apnmi-test-cards" style="max-width:900px;margin:8px 0 16px">
            <summary style="cursor:pointer;font-weight:600"><?php esc_html_e('Test cards and scenarios', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></summary>
            <table class="widefat striped" style="margin-top:8px">
                <thead><tr>
                    <th><?php esc_html_e('Card', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Number', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></th>
                </tr></thead>
                <tbody>
                    <tr><td>Visa</td><td><code>4111 1111 1111 1111</code></td></tr>
                    <tr><td>Mastercard</td><td><code>5431 1111 1111 1111</code></td></tr>
                    <tr><td>Discover</td><td><code>6011 6011 6011 6611</code></td></tr>
                    <tr><td>American Express</td><td><code>3411 1111 1111 111</code></td></tr>
                </tbody>
            </table>
            <ul style="list-style:disc;margin:10px 0 0 20px">
                <li><?php esc_html_e('Expiry: any date in the future. CVV: 999 (American Express: 9999).', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></li>
                <li><?php esc_html_e('To test a decline, place an order with a total under $1.00.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></li>
                <li><?php esc_html_e('3-D Secure and saved cards are not supported in Sandbox. Test them in Test Mode.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></li>
                <li><?php esc_html_e('These are NMI\'s standard test values. If a result differs, check NMI\'s test data documentation.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></li>
            </ul>
        </details>
        <?php
    }
}
