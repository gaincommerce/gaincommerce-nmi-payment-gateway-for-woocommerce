<?php
/**
 * Loads the stylesheet that turns each settings section into a card.
 *
 * Every feature on the NMI settings screens has its own heading. This file gives each one a tinted
 * background with padding and margin, so a feature is easy to see as a block of its own. Free sections are
 * off-white; the Premium add-on's sections use the same stylesheet in green.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings_Style
{
    /** Shared with the Premium add-on, so only one copy of the stylesheet is ever loaded. */
    const HANDLE = 'gcnmi-settings-sections';

    /** CSS classes for a free section heading (use as the 'class' of a 'title' settings field). */
    const FREE_CLASS = 'gcnmi-section gcnmi-section--free';

    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    /** WooCommerce > Settings > NMI Settings, or Payments > the NMI gateway. */
    public static function is_settings_screen(string $hook, string $tab, string $section): bool
    {
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return false;
        }
        return 'nmi' === $tab || ( 'checkout' === $tab && AP_NMI_WC_GATEWAY_ID === $section );
    }

    public static function enqueue($hook): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check, no data processed.
        $tab     = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( ! self::is_settings_screen((string) $hook, $tab, $section) ) {
            return;
        }

        $file = AP_NMI_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/css/settings-sections.css';

        wp_enqueue_style(
            self::HANDLE,
            AP_NMI_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/css/settings-sections.css',
            [],
            AP_NMI_PAYMENT_GATEWAY_VERSION . '.' . ( file_exists($file) ? filemtime($file) : '0' )
        );
    }
}
