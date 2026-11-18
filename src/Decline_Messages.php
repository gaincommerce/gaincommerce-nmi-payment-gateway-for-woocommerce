<?php
/**
 * Turns NMI decline / error codes into clear, safe messages for shoppers.
 *
 * Shoppers see these friendly messages. The order note and log keep NMI's
 * original text and numeric code so support can still diagnose the problem.
 * Fraud-related and store-configuration reasons are never shown to shoppers.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Decline_Messages
{

    public static function init(): void
    {
        // Priority 30: after the Digital Wallets and Credit Card Surcharge sections (both 20),
        // so "Decline Messages" appears below them.
        add_filter('woocommerce_settings_api_form_fields_' . AP_NMI_WC_GATEWAY_ID, [self::class, 'add_settings'], 30, 1);
    }

    /**
     * The "Decline Messages" settings section.
     *
     * Fields added through this filter skip WC_Settings_API::set_defaults(), so each needs a 'default'.
     */
    public static function add_settings(array $fields): array
    {
        $fields['decline_messages_section'] = [
            'title'       => __('Decline Messages', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            'type'        => 'title',
            'default'     => '',
            'description' => __('What customers see when a card payment is declined. NMI\'s original text and code are always kept in the order note.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
        ];

        // Only shows or hides the boxes below. Saved messages keep working while hidden.
        $fields['decline_messages_editor'] = [
            'title'       => __('Decline messages', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            'type'        => 'select',
            'description' => __('Display a standard generic message when a transaction is declined, or customize responses for specific decline reasons.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            'default'     => 'no', // Hidden by default; saved wording still applies either way.
            'desc_tip'    => false,
            'options'     => [
                'yes' => __('Show Decline Message', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'no'  => __('Hide Decline Message', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
        ];

        foreach (self::editable() as $key => $item) {
            $fields['decline_msg_' . $key] = [
                'title'             => $item[0],
                'type'              => 'text',
                'css'               => 'width:100%;max-width:700px;',
                'description'       => __('Leave empty to use the default shown in grey.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default'           => '',
                'desc_tip'          => false,
                'class'             => 'apnmi-decline-field',
                // Top-level key: WooCommerce already prints placeholder="" itself, and a second
                // placeholder from custom_attributes would be ignored by the browser.
                'placeholder'       => $item[1],
            ];
        }

        return $fields;
    }

    /**
     * Message to show the shopper.
     *
     * @param mixed  $code NMI response_code (e.g. "201"), or a WP_Error code on connection errors.
     * @param string $raw  NMI's original response text.
     */
    public static function for_response($code, string $raw): string
    {
        // Nothing to map (card restriction, missing token, ...): those messages are already friendly.
        if ( null === $code || '' === (string) $code ) {
            return $raw;
        }

        $message = self::message_for(trim((string) $code), $raw);

        /**
         * Filter the decline message shown to the shopper.
         *
         * @param string $message Friendly message.
         * @param string $code    NMI response code.
         * @param string $raw     NMI's original response text.
         */
        $filtered = apply_filters('gaincommerce_nmi_decline_message', $message, (string) $code, $raw);

        return is_string($filtered) && '' !== $filtered ? $filtered : $message;
    }

    private static function message_for(string $code, string $raw): string
    {
        // Gateway-side rejections (300) and some processor declines (200) carry the real
        // reason only in the text, so look there for a more helpful hint.
        if ( in_array($code, ['200', '300'], true) ) {
            $hint = self::hint_from_text($raw);
            if ( null !== $hint ) {
                return $hint;
            }
        }

        switch ( $code ) {
            // Try another card.
            case '200':
            case '201':
            case '202':
            case '203':
            case '204':
            case '240':
            case '260':
            case '263':
            case '264':
                return self::text('bank');

            // Fraud / lost / stolen / pick up: never say why.
            case '250':
            case '251':
            case '252':
            case '253':
                return self::text('bank');

            // Check the card details.
            case '220':
            case '222':
                return self::text('details');
            case '223':
            case '224':
                return self::text('expiry');
            case '225':
                return self::text('cvv');
            case '226':
                return __('The card PIN was not accepted. Please try again or use a different card.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');

            // Card not accepted.
            case '221':
            case '461':
                return __('This card type is not accepted. Please use a different card.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');

            // Possible duplicate.
            case '430':
                return __('This looks like a duplicate payment. Please check your email for an order confirmation before trying again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');

            // Store-side configuration problems: the real reason goes to the admin note only.
            case '410':
            case '411':
            case '440':
            case '441':
            case '460':
                return self::text('store');

            // Temporary problems.
            case '300':
            case '400':
            case '420':
            case '421':
                return self::text('temporary');
        }

        // Unknown numeric codes: group by range. Anything else (e.g. a connection error) is temporary.
        if ( ctype_digit($code) ) {
            $first = (int) $code[0];
            if ( 2 === $first ) {
                return self::text('bank');
            }
        }

        return self::text('temporary');
    }

    /**
     * Pull a specific, safe hint out of NMI's text for generic codes.
     */
    private static function hint_from_text(string $raw): ?string
    {
        $text = strtolower($raw);
        if ( '' === $text ) {
            return null;
        }

        if ( false !== strpos($text, 'duplicate') ) {
            return __('This looks like a duplicate payment. Please check your email for an order confirmation before trying again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
        }
        if ( false !== strpos($text, 'cvv') || false !== strpos($text, 'cvc') || false !== strpos($text, 'security code') ) {
            return self::text('cvv');
        }
        if ( false !== strpos($text, 'expir') ) {
            return self::text('expiry');
        }
        if ( false !== strpos($text, 'avs') || false !== strpos($text, 'billing address') || false !== strpos($text, 'zip') || false !== strpos($text, 'postal') ) {
            return __('Your billing address could not be verified. Please check that it matches your card statement and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
        }
        if ( false !== strpos($text, 'card number') || false !== strpos($text, 'invalid credit card') || false !== strpos($text, 'invalid card') ) {
            return self::text('details');
        }

        return null;
    }

    /**
     * The editable messages: key => [settings label, built-in default].
     * Each has a "decline_msg_<key>" setting; empty means use the default.
     */
    public static function editable(): array
    {
        return [
            'bank' => [
                __('Bank declined the card', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                __('Your card was declined by your bank. Please double-check your details or try another payment method.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'details' => [
                __('Card details wrong', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                __('Your card details could not be verified. Please check your card number, expiry date and security code, or use a different card.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'cvv' => [
                __('Security code (CVV) wrong', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                __('The security code (CVV) was not accepted. Please check it and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'expiry' => [
                __('Card expired / wrong expiry date', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                __('The expiry date looks incorrect or the card has expired. Please check it and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'temporary' => [
                __('Temporary problem', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                __('We could not process your payment right now. Please try again in a few minutes, or use another payment method.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'store' => [
                __('Store-side problem', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                __('We could not process your payment. Please contact the store for help.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
        ];
    }

    /** The store's own wording for a message, or the built-in default when left empty. */
    private static function text(string $key): string
    {
        $messages = self::editable();
        $settings = get_option(AP_NMI_WC_GATEWAY_SETTINGS_ID, []);
        $custom   = is_array($settings) ? trim((string) ( $settings['decline_msg_' . $key] ?? '' )) : '';

        return '' !== $custom ? wp_strip_all_tags($custom) : $messages[$key][1];
    }
}
