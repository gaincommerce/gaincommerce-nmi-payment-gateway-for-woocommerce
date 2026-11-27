<?php
/**
 * Optional Google address autocomplete for the checkout billing/shipping address fields.
 *
 * Off by default. Nothing is loaded and nothing is sent to Google unless the merchant
 * turns the feature on AND saves their own Google API key. The Google script itself is
 * only requested by the browser once the shopper focuses an address field.
 *
 * Works on both the Blocks and the classic checkout. The front-end logic lives in
 * assets/js/address-autocomplete.js (no build step needed).
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Address_Autocomplete
{

    /**
     * Google restricts includedRegionCodes to 15 entries.
     */
    private const MAX_COUNTRIES = 15;

    public static function init(): void
    {
        add_filter(
            'woocommerce_settings_api_form_fields_' . AP_NMI_WC_GATEWAY_ID,
            [ self::class, 'add_settings' ],
            25,
            1
        );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_scripts' ] );
    }

    /**
     * Add the settings to the gateway form (shown on Payments > Manage and the NMI Settings tab).
     *
     * @param array $fields Existing gateway form fields.
     * @return array
     */
    public static function add_settings( array $fields ): array
    {
        // Fields added through this filter skip WC_Settings_API::set_defaults(), so every
        // entry (headers included) needs an explicit 'default'.
        $fields['address_autocomplete_section'] = [
            'title'       => __( 'Address Autocomplete', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'        => 'title',
            'class'       => \APNMIPaymentGateway\Settings\Settings_Style::FREE_CLASS,
            'default'     => '',
            'description' => __(
                'Suggests full addresses while customers type in the checkout address fields, which reduces typos and AVS mismatches. Uses your own Google Places API key, so Google bills your Google Cloud account directly. Turn this off if your theme or another plugin already provides address autocomplete, otherwise customers may see two suggestion lists.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            ),
        ];

        $fields['address_autocomplete_enabled'] = [
            'title'       => __( 'Enable', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'        => 'checkbox',
            'label'       => __( 'Enable address autocomplete at checkout', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'default'     => 'no',
            'description' => __( 'Does nothing until a Google API key is saved below.', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'desc_tip'    => false,
        ];

        $fields['address_autocomplete_api_key'] = [
            'title'       => __( 'Google API Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'        => 'text',
            'default'     => '',
            'description' => __(
                'In Google Cloud, enable the <strong>Places API (New)</strong> and <strong>Maps JavaScript API</strong>, then create an API key. Because this key is visible in the page source, restrict it to your website under <em>Application restrictions &rarr; Websites (HTTP referrers)</em> and to those two APIs under <em>API restrictions</em>. Never reuse your server-side keys here.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            ),
            'desc_tip'    => false,
        ];

        $fields['address_autocomplete_countries'] = [
            'title'       => __( 'Limit to Countries', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'        => 'text',
            'default'     => '',
            'placeholder' => 'US, CA',
            'description' => __(
                'Optional. Comma-separated two-letter country codes (maximum 15). Leave empty to use the countries your store sells and ships to, or all countries if you sell to more than 15.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            ),
            'desc_tip'    => false,
        ];

        return $fields;
    }

    // ── Settings helpers ──────────────────────────────────────────────────────

    private static function settings(): array
    {
        $settings = get_option( AP_NMI_WC_GATEWAY_SETTINGS_ID, [] );
        return is_array( $settings ) ? $settings : [];
    }

    /**
     * A Google API key is letters, digits, "-" and "_". Anything else is treated as "no key".
     */
    public static function get_api_key(): string
    {
        $key = trim( (string) ( self::settings()['address_autocomplete_api_key'] ?? '' ) );
        return preg_match( '/^[A-Za-z0-9_\-]{20,}$/', $key ) ? $key : '';
    }

    public static function is_enabled(): bool
    {
        $enabled = 'yes' === ( self::settings()['address_autocomplete_enabled'] ?? 'no' )
            && '' !== self::get_api_key();

        /**
         * Lets a theme or another plugin switch address autocomplete off (e.g. when it
         * already provides its own).
         */
        return (bool) apply_filters( 'apnmi_address_autocomplete_enabled', $enabled );
    }

    /**
     * @return string[] Upper-case ISO 3166-1 alpha-2 codes. Empty means "no restriction".
     */
    public static function get_countries(): array
    {
        $raw   = (string) ( self::settings()['address_autocomplete_countries'] ?? '' );
        $codes = [];

        if ( preg_match_all( '/[A-Za-z]{2}/', $raw, $m ) ) {
            $codes = array_map( 'strtoupper', $m[0] );
        } elseif ( function_exists( 'WC' ) && WC()->countries ) {
            // No explicit list: follow the countries the store actually sells/ships to,
            // as long as that is a short list Google will accept.
            $codes = array_merge(
                array_keys( (array) WC()->countries->get_allowed_countries() ),
                array_keys( (array) WC()->countries->get_shipping_countries() )
            );
        }

        $codes = array_values( array_unique( $codes ) );

        return count( $codes ) > self::MAX_COUNTRIES ? [] : $codes;
    }

    // ── Front end ─────────────────────────────────────────────────────────────

    public static function enqueue_scripts(): void
    {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        // The order-received and pay-for-order endpoints have no editable address fields.
        if ( function_exists( 'is_wc_endpoint_url' )
            && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) )
        ) {
            return;
        }

        $base = apnmi_get_plugin_dir_url();
        $dir  = defined( 'AP_NMI_PAYMENT_GATEWAY_PLUGIN_DIR' ) ? AP_NMI_PAYMENT_GATEWAY_PLUGIN_DIR : '';

        $js_version  = AP_NMI_PAYMENT_GATEWAY_VERSION;
        $css_version = AP_NMI_PAYMENT_GATEWAY_VERSION;
        if ( $dir && file_exists( $dir . 'assets/js/address-autocomplete.js' ) ) {
            $js_version .= '.' . filemtime( $dir . 'assets/js/address-autocomplete.js' );
        }
        if ( $dir && file_exists( $dir . 'assets/css/address-autocomplete.css' ) ) {
            $css_version .= '.' . filemtime( $dir . 'assets/css/address-autocomplete.css' );
        }

        wp_enqueue_style(
            'apnmi-address-autocomplete',
            $base . 'assets/css/address-autocomplete.css',
            [],
            $css_version
        );

        wp_register_script(
            'apnmi-address-autocomplete',
            $base . 'assets/js/address-autocomplete.js',
            [],
            $js_version,
            true
        );

        wp_localize_script(
            'apnmi-address-autocomplete',
            'apnmiAddressAutocomplete',
            [
                'apiKey'    => self::get_api_key(),
                'countries' => self::get_countries(),
                'language'  => str_replace( '_', '-', determine_locale() ),
                'i18n'      => [
                    'poweredBy'   => __( 'Powered by Google', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                    /* translators: %d: number of address suggestions */
                    'suggestions' => __( '%d address suggestions available. Use the up and down arrow keys to review.', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                    'noResults'   => __( 'No address suggestions found.', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                ],
            ]
        );

        wp_enqueue_script( 'apnmi-address-autocomplete' );
    }
}
