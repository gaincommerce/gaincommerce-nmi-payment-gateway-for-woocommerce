<?php
/**
 * Registers the "NMI Settings" tab in WooCommerce > Settings.
 *
 * Kept separate from NMI_Settings_Page because that class extends
 * WC_Settings_Page, which isn't loaded yet when the plugin boots; it is only
 * autoloaded from the `woocommerce_get_settings_pages` callback below.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NMI_Settings_Tab
{
    public static function init(): void
    {
        add_filter('woocommerce_get_settings_pages', [self::class, 'register']);
        add_filter('woocommerce_settings_tabs_array', [self::class, 'move_after_payments'], 100);
    }

    public static function register(array $pages): array
    {
        $pages[] = new NMI_Settings_Page();
        return $pages;
    }

    /**
     * Place the tab right after Payments (WooCommerce appends new tabs at the end).
     */
    public static function move_after_payments(array $tabs): array
    {
        if (! isset($tabs['nmi'], $tabs['checkout'])) {
            return $tabs;
        }

        $label = $tabs['nmi'];
        unset($tabs['nmi']);

        $ordered = [];
        foreach ($tabs as $key => $value) {
            $ordered[$key] = $value;
            if ('checkout' === $key) {
                $ordered['nmi'] = $label;
            }
        }

        return $ordered;
    }
}
