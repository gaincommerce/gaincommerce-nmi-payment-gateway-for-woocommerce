<?php
/**
 * Card Processing Fee Settings for NMI Payment Gateway
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Settings;

use APNMIPaymentGateway\Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Processing_Fee_Settings
{
    const TYPE_NONE       = 'none';
    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_FLAT       = 'flat';

    // Store API extension namespace used to sync the live-selected payment method
    // out of the Checkout block's React state and into the session — see
    // register_store_api_callback() for why this is needed at all.
    const STORE_API_NAMESPACE = 'gaincommerce-nmi-processing-fee';

    // Separate from WooCommerce core's own 'chosen_payment_method' session key,
    // which classic (shortcode) checkout keeps updated via its update_order_review
    // AJAX call but the Checkout block never touches.
    const BLOCKS_SESSION_KEY = 'apnmi_blocks_chosen_payment_method';

    public static function init(): void
    {
        add_filter(
            'woocommerce_settings_api_form_fields_' . AP_NMI_WC_GATEWAY_ID,
            [ self::class, 'add_processing_fee_settings' ],
            20,
            1
        );

        add_action( 'woocommerce_cart_calculate_fees', [ self::class, 'maybe_add_fee' ] );
        add_action( 'admin_footer', [ self::class, 'maybe_render_toggle_script' ] );
        add_action( 'woocommerce_blocks_loaded', [ self::class, 'register_store_api_callback' ] );
    }

    /**
     * Keeps BLOCKS_SESSION_KEY in sync with whichever payment method is selected in
     * the Checkout block, live, before the order is placed.
     *
     * woocommerce_cart_calculate_fees (and the Order Summary total it drives) needs
     * to know the selected gateway to decide whether to add this fee. Classic
     * checkout gets that for free via WC_AJAX::update_order_review(), which stores
     * $_POST['payment_method'] into WC()->session under 'chosen_payment_method' on
     * every totals refresh. The Checkout block has no equivalent: payment method
     * selection there is pure client-side React state, only ever sent to the server
     * in the final place-order request — by which point the Order Summary the
     * shopper was looking at has already rendered without the fee.
     *
     * The fix is the Store API's own extension mechanism: checkout-blocks.js
     * listens for the active payment method changing and pushes it here via
     * extensionCartUpdate(), which re-runs cart totals (including this callback)
     * on the same request.
     *
     * @return void
     */
    public static function register_store_api_callback(): void
    {
        if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
            return;
        }

        woocommerce_store_api_register_update_callback( [
            'namespace' => self::STORE_API_NAMESPACE,
            'callback'  => function ( $data ) {
                if ( isset( $data['payment_method'] ) && function_exists( 'WC' ) && WC()->session ) {
                    WC()->session->set( self::BLOCKS_SESSION_KEY, wc_clean( wp_unslash( $data['payment_method'] ) ) );
                }
            },
        ] );
    }

    public static function add_processing_fee_settings( array $fields ): array
    {
        // Fields added through this filter skip WC_Settings_API::set_defaults(), and
        // init_settings() plucks 'default' from every field, so a header needs one too.
        $fields['processing_fee_section'] = [
            'title'       => __( 'Credit Card Surcharge', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'        => 'title',
            'default'     => '',
            'description' => __(
                'Add an extra fee to credit card orders at checkout. Automatically excluded for ACH, eCheck, and COD payment methods.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            ),
        ];

        $fields['processing_fee_type'] = [
            'title'    => __( 'Processing Fee', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'     => 'select',
            'class'    => 'apnmi-processing-fee-type',
            'default'  => self::TYPE_NONE,
            'desc_tip' => false,
            'options'  => [
                self::TYPE_NONE       => __( 'No Processing Fee', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                self::TYPE_PERCENTAGE => __( 'Percentage Fee', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                self::TYPE_FLAT       => __( 'Flat Rate Fee', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            ],
        ];

        $fields['processing_fee_percent'] = [
            'title'             => __( 'Fee Percentage', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'              => 'number',
            'description'       => __(
                'Percentage added on top of the order subtotal (after discounts) plus shipping, before tax. For example, 3 = 3%.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            ),
            'default'           => '3',
            'desc_tip'          => false,
            'class'             => 'apnmi-processing-fee-field apnmi-processing-fee-field--percentage',
            'custom_attributes' => [
                'min'  => '0',
                'max'  => '100',
                'step' => '0.01',
            ],
        ];

        $fields['processing_fee_flat_amount'] = [
            'title'             => __( 'Flat Fee Amount', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            'type'              => 'number',
            'description'       => __(
                'Flat amount added to every order paid via this gateway, regardless of order total.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            ),
            'default'           => '5',
            'desc_tip'          => false,
            'class'             => 'apnmi-processing-fee-field apnmi-processing-fee-field--flat',
            'custom_attributes' => [
                'min'  => '0',
                'step' => '0.01',
            ],
        ];

        return $fields;
    }

    public static function maybe_render_toggle_script(): void
    {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
            return;
        }
        $type_id = 'woocommerce_' . AP_NMI_WC_GATEWAY_ID . '_processing_fee_type';
        ?>
        <script>
        jQuery(function ($) {
            var $type = $('#<?php echo esc_js( $type_id ); ?>');
            if (!$type.length) return;

            function toggle() {
                var val = $type.val();
                $('.apnmi-processing-fee-field--percentage').closest('tr').toggle(val === '<?php echo esc_js( self::TYPE_PERCENTAGE ); ?>');
                $('.apnmi-processing-fee-field--flat').closest('tr').toggle(val === '<?php echo esc_js( self::TYPE_FLAT ); ?>');
            }

            $type.on('change', toggle);
            toggle();
        });
        </script>
        <?php
    }

    public static function get_fee_type(): string
    {
        $settings = get_option( 'woocommerce_' . AP_NMI_WC_GATEWAY_ID . '_settings', [] );

        if ( isset( $settings['processing_fee_type'] ) ) {
            $type = $settings['processing_fee_type'];
        } elseif ( isset( $settings['enable_processing_fee'] ) && 'yes' === $settings['enable_processing_fee'] ) {
            $type = self::TYPE_PERCENTAGE;
        } else {
            $type = self::TYPE_NONE;
        }

        return in_array( $type, [ self::TYPE_NONE, self::TYPE_PERCENTAGE, self::TYPE_FLAT ], true ) ? $type : self::TYPE_NONE;
    }

    public static function get_percent(): float
    {
        $settings = get_option( 'woocommerce_' . AP_NMI_WC_GATEWAY_ID . '_settings', [] );
        $percent  = isset( $settings['processing_fee_percent'] ) ? (float) $settings['processing_fee_percent'] : 3.0;

        return max( 0.0, min( 100.0, $percent ) );
    }

    public static function get_flat_amount(): float
    {
        $settings = get_option( 'woocommerce_' . AP_NMI_WC_GATEWAY_ID . '_settings', [] );
        $amount   = isset( $settings['processing_fee_flat_amount'] ) ? (float) $settings['processing_fee_flat_amount'] : 0.0;

        return max( 0.0, min( 100000.0, $amount ) );
    }

    /**
     * Text describing the configured fee, meant to be appended to the gateway's
     * checkout description — e.g. " (Includes $3.00 surcharge)" or
     * " (Includes 3% surcharge)". Empty string when no fee is configured.
     *
     * Read by both classic checkout (Gateway::$description, rendered in
     * template/public/wc-payment-fields.php) and block checkout
     * (NMI_Blocks_Payment_Method::get_payment_method_data(), rendered as plain
     * text by the Label component in checkout-blocks.js) via the same property,
     * so this only needs to be computed in one place.
     *
     * @return string
     */
    public static function get_fee_label_suffix(): string
    {
        $type = self::get_fee_type();

        if ( self::TYPE_PERCENTAGE === $type ) {
            $percent = self::get_percent();
            if ( $percent <= 0 ) {
                return '';
            }

            // Trim trailing zeros so "3.00" reads as "3" but "3.25" stays whole.
            $percent_text = rtrim( rtrim( number_format( $percent, 2, '.', '' ), '0' ), '.' );

            return ' ' . sprintf(
                /* translators: %s: Fee percentage, e.g. "3" */
                __( '(Includes %s%% surcharge)', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                $percent_text
            );
        }

        if ( self::TYPE_FLAT === $type ) {
            $amount = self::get_flat_amount();
            if ( $amount <= 0 ) {
                return '';
            }

            // Rendered as plain text in block checkout (React escapes children,
            // no dangerouslySetInnerHTML), so strip wc_price()'s markup rather
            // than pass it through — wp_kses_post() on the classic side would
            // tolerate the HTML, but block checkout would show the raw tags.
            $amount_text = trim( wp_strip_all_tags( html_entity_decode( wc_price( $amount ), ENT_QUOTES ) ) );

            return ' ' . sprintf(
                /* translators: %s: Fee amount, e.g. "$3.00" */
                __( '(Includes %s surcharge)', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
                $amount_text
            );
        }

        return '';
    }

    public static function maybe_add_fee( $cart ): void
    {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        // NOTE: previously gated on `! is_checkout()` here to keep the fee off the
        // Cart page. Reverted — production logging (order #518) showed is_checkout()
        // only evaluates true for the initial, non-REST page render; every later
        // Store API call (including the one that finalizes the order total when
        // Place Order is clicked) runs with is_checkout() false, so the fee was
        // being silently dropped from real orders, not just hidden on the Cart
        // page. The Cart/Checkout blocks share the same /wc/store/v1/cart endpoint
        // for live totals, so there's no reliable request-level signal to
        // distinguish them — revisit with a different approach (e.g. Referer)
        // rather than a blanket is_checkout() gate.

        $type = self::get_fee_type();
        if ( self::TYPE_NONE === $type ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        // Production logging (2026-09-16) showed 'chosen_payment_method' actually
        // stays correctly in sync with the shopper's real selection during block
        // checkout too (bacs/cheque/cod all showed up accurately after switching)
        // — contrary to the assumption this code used to make. What's NOT
        // reliable is BLOCKS_SESSION_KEY: the same logs showed it gets pushed to
        // this gateway's id once (on initial load or an explicit switch *to* it
        // via checkout-blocks.js) and then never updates again, including after
        // switching away — so preferring it during Store API requests was
        // serving a stale "still gaincommerce_nmi" value on every subsequent
        // switch. Trust the classic key whenever it's set; only fall back to
        // BLOCKS_SESSION_KEY for the brief window before WC has populated the
        // classic key at all (seen at the very start of a session).
        $chosen_gateway = WC()->session->get( 'chosen_payment_method' );
        if ( empty( $chosen_gateway ) ) {
            $chosen_gateway = WC()->session->get( self::BLOCKS_SESSION_KEY );
        }

        // Temporary diagnostic for the "fee doesn't clear on switch" reports —
        // enable Logging in the gateway settings and check WooCommerce > Status >
        // Logs (source: gaincommerce-nmi-gateway) after reproducing, to see what
        // both session keys held at that moment and which one was used.
        Logger::get_instance()->debug( 'Processing fee gateway check', [
            'classic_session_key'      => WC()->session->get( 'chosen_payment_method' ),
            'blocks_session_key'       => WC()->session->get( self::BLOCKS_SESSION_KEY ),
            'resolved_chosen_gateway'  => $chosen_gateway,
            'gateway_matched'          => AP_NMI_WC_GATEWAY_ID === $chosen_gateway,
        ] );

        if ( AP_NMI_WC_GATEWAY_ID !== $chosen_gateway ) {
            return;
        }

        $fee_amount = 0.0;
        $fee_base   = null;

        if ( self::TYPE_PERCENTAGE === $type ) {
            $percent = self::get_percent();
            if ( $percent <= 0 ) {
                Logger::get_instance()->debug( 'Processing fee skipped: percent <= 0', [ 'percent' => $percent ] );
                return;
            }

            $fee_base = (float) $cart->get_cart_contents_total() + (float) $cart->get_shipping_total();
            if ( $fee_base <= 0 ) {
                Logger::get_instance()->debug( 'Processing fee skipped: fee_base <= 0', [
                    'cart_contents_total' => $cart->get_cart_contents_total(),
                    'shipping_total'      => $cart->get_shipping_total(),
                ] );
                return;
            }

            $fee_amount = round( $fee_base * ( $percent / 100 ), 2 );
        } elseif ( self::TYPE_FLAT === $type ) {
            $fee_amount = round( self::get_flat_amount(), 2 );
        }

        if ( $fee_amount <= 0 ) {
            Logger::get_instance()->debug( 'Processing fee skipped: fee_amount <= 0', [
                'type'       => $type,
                'fee_base'   => $fee_base,
                'fee_amount' => $fee_amount,
            ] );
            return;
        }

        Logger::get_instance()->debug( 'Processing fee: adding fee to cart', [
            'type'       => $type,
            'fee_base'   => $fee_base,
            'fee_amount' => $fee_amount,
        ] );

        $cart->add_fee(
            __( 'Card Processing Fee', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ),
            $fee_amount,
            false
        );
    }
}
