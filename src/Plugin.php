<?php
/**
 * Main plugin class: wires up the gateway, its settings, and WooCommerce Blocks support.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway;
use APNMIPaymentGateway\Single_Instance_Trait;
use APNMIPaymentGateway\Gateway;
use APNMIPaymentGateway\WC\NMI_Blocks_Payment_Method;
use APNMIPaymentGateway\WC\Blocks\NMI_Apple_Pay_Blocks;
use APNMIPaymentGateway\WC\Blocks\NMI_Google_Pay_Blocks;
use APNMIPaymentGateway\Settings\Digital_Wallet_Settings;
use APNMIPaymentGateway\Settings\Processing_Fee_Settings;
use APNMIPaymentGateway\Security\Bulletproof_Security_Whitelist;

class Plugin
{
    use Single_Instance_Trait;

    private $_gateway = null;

    private function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'add_gateway']);
        add_action('init', [$this, 'init_gateway']);

        Digital_Wallet_Settings::init();
        Processing_Fee_Settings::init();
        \APNMIPaymentGateway\Decline_Messages::init();
        \APNMIPaymentGateway\Settings\NMI_Settings_Tab::init();

        // Live / Test / Sandbox switcher: admin-bar marker and checkout notice.
        \APNMIPaymentGateway\Settings\Environment::init();

        // Each settings section is a card with its own background, so a feature reads as one block.
        if ( is_admin() ) {
            \APNMIPaymentGateway\Settings\Settings_Style::init();
        }

        // Review prompts (milestone notice, settings box, setup-complete card).
        \APNMIPaymentGateway\Review\Review_Prompt::init();

        // Receiver for browser checkout trace entries, mirrored into the WC log.
        \APNMIPaymentGateway\Checkout_Trace::init();

        // Optional bot/spam protection (reCAPTCHA v3 / Turnstile) at checkout.
        \APNMIPaymentGateway\Bot_Protection::init();

        // Optional Google address autocomplete at checkout (off until a key is saved).
        \APNMIPaymentGateway\Address_Autocomplete::init();

        add_action('woocommerce_blocks_loaded', [$this, 'init_blocks_support']);

        // Render Apple Pay + Google Pay express buttons above payment methods on classic checkout.
        // Two hooks cover different WooCommerce theme templates:
        //   woocommerce_review_order_before_payment — fires inside the order review table (default)
        //   woocommerce_before_checkout_form       — fires before the entire form (some themes)
        // A static flag prevents double output if both hooks fire on the same page.
        add_action('woocommerce_review_order_before_payment', [$this, 'render_wallet_express_buttons']);
        add_action('woocommerce_before_checkout_form', [$this, 'render_wallet_express_buttons'], 5);

        add_action('init', [$this, 'load_textdomain']);

        // Self-heal the BulletProof Security Pro whitelist (see
        // maybe_whitelist_scripts() for why). Activation already does this
        // once; this catches the case where BPS Pro later regenerates its
        // .htaccess from its own settings and drops the entries again.
        // Throttled to at most once a day so it's not a filesystem read on
        // every admin page load.
        add_action('admin_init', [$this, 'maybe_reheal_bps_whitelist']);

        add_filter(
            'plugin_action_links_' . AP_NMI_PAYMENT_GATEWAY_PLUGIN_BASENAME,
            [$this, 'add_action_links']
        );
    }

    public function add_gateway(array $gateways): array
    {
        $gateways[] = Gateway::class;
        return $gateways;
    }

    /**
     * Re-check the BulletProof Security Pro whitelist at most once a day.
     *
     * Bulletproof_Security_Whitelist::maybe_whitelist_scripts() is cheap and
     * idempotent, but there's no reason to read wp-content/plugins/.htaccess
     * on every single admin page load, so this throttles it with a
     * transient. Only ever runs in wp-admin, never on the frontend.
     *
     * @return void
     */
    public function maybe_reheal_bps_whitelist(): void
    {
        if (get_transient('apnmi_bps_whitelist_checked_' . AP_NMI_PAYMENT_GATEWAY_VERSION)) {
            return;
        }

        Bulletproof_Security_Whitelist::maybe_whitelist_scripts();

        set_transient('apnmi_bps_whitelist_checked_' . AP_NMI_PAYMENT_GATEWAY_VERSION, 1, DAY_IN_SECONDS);
    }

    public function init_gateway(): void
    {
        if (class_exists('WC_Payment_Gateway')) {
            $this->_gateway = new Gateway();
        }
    }

    /**
     * Render Apple Pay + Google Pay express button containers above the payment
     * methods list on classic checkout. CollectJS injects the actual buttons on
     * page load. Plug-and-play: accounts activate once merchant IDs are set in
     * gateway settings — zero code changes required.
     */
    public function render_wallet_express_buttons(): void
    {
        if ( ! is_checkout() ) {
            return;
        }

        // Only for classic checkout — blocks checkout uses registered express payment methods.
        if ( has_block('woocommerce/checkout') ) {
            return;
        }

        // Prevent double output when both hooks fire on the same page load.
        static $rendered = false;
        if ( $rendered ) {
            return;
        }
        $rendered = true;

        if ( ! class_exists('APNMIPaymentGateway\Settings\Digital_Wallet_Settings') ) {
            return;
        }

        $apple_pay_enabled  = Digital_Wallet_Settings::is_apple_pay_enabled();
        $google_pay_enabled = Digital_Wallet_Settings::is_google_pay_enabled();

        if ( ! $apple_pay_enabled && ! $google_pay_enabled ) {
            return;
        }

        // Wrapper starts hidden; JS shows it via .show() once CollectJS confirms
        // at least one wallet button has rendered (fieldsAvailableCallback).
        echo '<div class="nmi-wallet-express-wrap" style="display:none;">';

        if ( $apple_pay_enabled ) {
            // Only Safari on Apple devices supports Apple Pay — CollectJS handles hiding.
            echo '<div id="nmi-apple-pay-express" class="nmi-wallet-button"></div>';
        }

        if ( $google_pay_enabled ) {
            echo '<div id="nmi-google-pay-express" class="nmi-wallet-button"></div>';
        }

        echo '<p class="nmi-or-divider">' . esc_html__( '— or pay below —', 'gaincommerce-nmi-payment-gateway-for-woocommerce' ) . '</p>';
        echo '</div>';
    }

    public function init_blocks_support(): void
    {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function ($payment_method_registry) {
                    $payment_method_registry->register(new NMI_Blocks_Payment_Method());
                    // Wallet buttons (Apple Pay / Google Pay) are rendered INSIDE the CC
                    // payment form by checkout-blocks.js, not as separate express methods.
                    // This avoids the CollectJS single-configure constraint: CollectJS can
                    // only be configured once per page load, so all fields (CC + wallets)
                    // must be declared in the single configure() call inside checkout-blocks.js
                    // when the user selects the Credit Card payment option.
                }
            );
        }
    }

    public function gateway_supports_blocks($is_supported, $feature, $gateway)
    {
        if ($feature === 'blocks' && $gateway->id === AP_NMI_WC_GATEWAY_ID) {
            return true;
        }
        return $is_supported;
    }

    public function load_textdomain(): void
    {
        // No-op: WordPress 4.6+ auto-loads translations from wordpress.org for
        // plugins hosted there, so a manual load_plugin_textdomain() call isn't needed.
    }

    public function add_action_links(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wc-settings&tab=nmi'),
            esc_html__('Settings', 'gaincommerce-nmi-payment-gateway-for-woocommerce')
        );

        array_unshift($links, $settingsLink);

        // Upsell link after "Deactivate"; hidden once the Enterprise add-on is active.
        if (!class_exists('GainCommerceNmiEnterprise\Save_Card_Settings')) {
            $links[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#3858e9;font-weight:600;">%s</a>',
                esc_url('https://www.gaincommerce.com/register/'),
                esc_html__('Get Enterprise', 'gaincommerce-nmi-payment-gateway-for-woocommerce')
            );
        }

        return $links;
    }

    public function get_gateway(): ?Gateway
    {
        return $this->_gateway;
    }
}
