<?php
/**
 * NMI WooCommerce Payment Gateway
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway;

use APNMIPaymentGateway\API\NMI_API_Factory;
use APNMIPaymentGateway\Settings\Environment;
use APNMIPaymentGateway\Settings\Processing_Fee_Settings;
use APNMIPaymentGateway\Single_Instance_Trait;
use APNMIPaymentGateway\WC\NMI_Process_Payment;
use APNMIPaymentGateway\Parse_Response_Codes;
use WC_Payment_Gateway;
use WC_Order;
class Gateway extends WC_Payment_Gateway
{
    use Single_Instance_Trait;

    public const GATEWAY_ID = AP_NMI_WC_GATEWAY_ID;
    public const USE_COLLECT_JS = AP_NMI_PAYMENT_GATEWAY_USE_COLLECT_JS;

    public $testmode;
    public $private_key;
    public $public_key;
    public $transaction_mode;
    public $send_receipts;
    public $logging;
    public $use_collect_js;
    public $restricted_card_types;
    public $enable_descriptor;
    public $descriptor;
    public $descriptor_phone;

    /**
     * URL WooCommerce's Payments settings page uses for the "Manage" button.
     * Points to the single "NMI Settings" tab so there is one place to manage the gateway.
     */
    public function get_settings_url(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=nmi');
    }

    public function __construct()
    {
        $this->id                 = self::GATEWAY_ID;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally the generic, un-prefixed filter name several WooCommerce gateway-icon customization tutorials/snippets target so one site-wide snippet can override any gateway's icon; renaming it would silently break that convention for merchants already using it.
        $this->icon               = apply_filters('woocommerce_custom_gateway_icon', '');
        $this->has_fields         = true;
        $this->method_title       = __('NMI Payment Gateway by Gain Commerce', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
        $this->method_description = __('Accept payments via NMI Payment Gateway by Gain Commerce.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
        $this->supports           = array(
            'products',
            'refunds',
            'blocks'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        // Appends "(Includes $3.00 surcharge)" / "(Includes 3% surcharge)" per the
        // configured Processing Fee, so shoppers see it before choosing this
        // gateway — same description property feeds both classic checkout
        // (payment_fields()) and block checkout (NMI_Blocks_Payment_Method).
        $this->description = $this->get_option('description') . Processing_Fee_Settings::get_fee_label_suffix();
        $this->enabled = $this->get_option('enabled');
        // True only in "Test" (Sandbox has its own endpoint and is handled separately).
        $this->testmode = Environment::TEST === Environment::get($this->settings);
        $this->transaction_mode = $this->get_option('transaction_mode', 'sale');
        $this->send_receipts = 'yes' === $this->get_option('send_receipts');
        $this->restricted_card_types = $this->get_option('restricted_card_types', array());
        $this->logging = 'yes' === $this->get_option('logging');
        $this->enable_descriptor = 'yes' === $this->get_option('enable_descriptor');
        $this->descriptor = $this->get_option('descriptor');
        $this->descriptor_phone = $this->get_option('descriptor_phone');

        // Sandbox uses its own keys (falls back to the main keys if left empty).
        $this->private_key = Environment::private_key($this->settings);
        $this->public_key = Environment::public_key($this->settings);

        $this->use_collect_js = self::USE_COLLECT_JS ? 1:0;

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );

        // Show only the selected Bot Protection provider's key fields, same
        // show/hide-on-select pattern as the Processing Fee dropdown.
        add_action('admin_footer', [$this, 'render_bot_protection_toggle_script']);

        // Environment dropdown: show the sandbox-only fields only in Sandbox.
        add_action('admin_footer', [$this, 'render_environment_script']);

        add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);

        // Also enqueue on AJAX calls for checkout updates.
        add_action('wp_ajax_woocommerce_checkout', [$this, 'payment_scripts'], 5);
        add_action('wp_ajax_nopriv_woocommerce_checkout', [$this, 'payment_scripts'], 5);

        add_action( 'woocommerce_api_{webhook name}',  [$this, 'webhook'] );
    }

    public function init_form_fields(): void
    {
        // Section headings ('title' fields) are display only: WooCommerce does not save them. Each one starts a
        // card (see Settings\Settings_Style) so the settings of one feature sit together in a block of their own.
        $card = \APNMIPaymentGateway\Settings\Settings_Style::FREE_CLASS;

        $this->form_fields = [
            'section_general' => [
                'title'       => __('General', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type'        => 'title',
                'class'       => $card,
                'default'     => '',
                'description' => __('Turn the gateway on and choose what customers see for it at checkout.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'enabled' => [
                'title' => __('Enable/Disable', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable NMI Payment Gateway', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'description' => __('Enable this payment gateway to accept credit card payments.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => 'yes',
                'desc_tip' => false,
            ],
            'title' => [
                'title' => __('Title', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'This controls the title customers see during checkout.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => __('Credit Card', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'desc_tip' => false,
            ],
            'description' => [
                'title' => __('Description', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'textarea',
                'description' => __(
                    'Payment method description that customers will see on your checkout.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => __('Pay securely using your credit card.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'desc_tip' => false,
            ],
            'section_environment' => [
                'title'       => __('Environment & API Keys', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type'        => 'title',
                'class'       => $card,
                'default'     => '',
                'description' => __('Choose Live, Test or Sandbox and connect your NMI account with its keys.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'environment' => [
                'title' => __('Environment', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'select',
                'description' => __(
                    '<strong>Live:</strong> Processes real transactions and charges customer cards.<br><strong>Test:</strong> Simulates transactions using test cards on your live NMI account (requires Test Mode enabled in your NMI portal).<br><strong>Sandbox:</strong> Connects to a separate NMI developer environment with its own API keys. No real charges occur. Does not support 3-D Secure or saved payment methods.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                // Existing stores keep their current mode (derived from the old checkboxes).
                'default' => Environment::get(),
                'desc_tip' => false,
                'options' => [
                    Environment::LIVE    => __('Live (real payments)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    Environment::TEST    => __('Test (test cards on your NMI account)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    Environment::SANDBOX => __('Sandbox (NMI Pathfinder sandbox)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                ],
            ],
            'public_key' => [
                'class' => 'apnmi-live-field',
                'title' => __('Live Tokenization Key (Public Key)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    '<strong>CollectJS Tokenization Key</strong> — found in your NMI merchant portal under <em>Settings → Security Keys → Tokenization Key</em>. This is <strong>not</strong> the API Security Key. The tokenization key is used by CollectJS to securely collect card data in the browser.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => '',
                'desc_tip' => false,
            ],
            'private_key' => [
                'class' => 'apnmi-live-field',
                'title' => __('Live Private Key (API Security Key)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'password',
                'description' => __(
                    'Your NMI live private (API Security) key, used in Live and Test. Make sure the key has "API" permission enabled.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => '',
                'desc_tip' => false,
                'custom_attributes' => ['autocomplete' => 'new-password'],
            ],
            'sandbox_public_key' => [
                'title' => __('Sandbox Tokenization Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __('Only used in Sandbox. The tokenization (public) key from your NMI sandbox account. Your live keys stay saved above and are not used in Sandbox. If left empty, the keys above are used.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-sandbox-field',
            ],
            'sandbox_private_key' => [
                'title' => __('Sandbox Private Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'password',
                'description' => __('Only used in Sandbox. The private (API security) key from your NMI sandbox account.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-sandbox-field',
                'custom_attributes' => ['autocomplete' => 'new-password'],
            ],
            'pathfinder_merchant_email' => [
                'title' => __('Sandbox Merchant Email', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'email',
                'description' => __(
                    'Only used in Sandbox. The email registered with your NMI Pathfinder sandbox account. It is sent with every test transaction to get around sandbox email restrictions. Enter your sandbox keys in the Sandbox Tokenization Key and Sandbox Private Key fields above.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-sandbox-field',
            ],
            'section_processing' => [
                'title'       => __('Payment Processing', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type'        => 'title',
                'class'       => $card,
                'default'     => '',
                'description' => __('How payments are taken: sale or authorize first, receipts, and which cards are accepted.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'transaction_mode' => [
                'title' => __('Transaction Mode', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'select',
                'description' => __(
                    'Choose whether to capture payment immediately or authorize first and capture later. 
                    <br>When Choosen as Authorize only, the order will set into on hold and when change order to processing or completed then the system will capture the payment.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => 'sale',
                'desc_tip' => false,
                'options' => [
                    'sale' => __('Sale (Authorize and Capture Immediately)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'auth' => __('Authorize Only (Capture Later)', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                ],
            ],
            'send_receipts' => [
                'title' => __('Email Receipts', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'label' => __('Send email receipts from NMI', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __(
                    'When enabled, NMI will automatically send email receipts to customers after successful payments.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => 'no',
                'desc_tip' => false,
            ],
            'restricted_card_types' => [
                'title' => __('Restrict Card Types', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'multiselect',
                // Renders as WooCommerce's standard Select2 tag picker instead of a bare
                // HTML <select multiple>. Without this class, clearing a selection back
                // to "no restrictions" (already the default — see 'default' below) only
                // works via the non-obvious Ctrl/Cmd+click-to-deselect, which reads as
                // "there's no way to turn restrictions off" even though there always was.
                'class' => 'wc-enhanced-select',
                'description' => __(
                    'Select card types to restrict. Customers will see an error message if they try to use a restricted card type. Leave empty to accept all card types.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => array(),
                'desc_tip' => false,
                'options' => [
                    'visa' => __('Visa', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'mastercard' => __('Mastercard', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'amex' => __('American Express', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'discover' => __('Discover', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'diners' => __('Diners Club', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'jcb' => __('JCB', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'unionpay' => __('UnionPay', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                ],
                'custom_attributes' => [
                    'multiple' => 'multiple',
                ],
            ],
            'section_bot_protection' => [
                'title'       => __('Bot Protection', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type'        => 'title',
                'class'       => $card,
                'default'     => '',
                'description' => __('Block automated card-testing attempts at checkout.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'bot_protection_provider' => [
                'title' => __('Bot Protection', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'select',
                'description' => __(
                    'Adds an invisible human-verification check to checkout to help block automated card-testing ("card spinning") attempts. Choose a provider and enter its keys below to enable it — leave "Disabled" to keep checkout exactly as-is.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => 'none',
                'desc_tip' => false,
                'options' => [
                    'none' => __('Disabled', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'recaptcha' => __('Google reCAPTCHA v3', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    'turnstile' => __('Cloudflare Turnstile', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                ],
            ],
            'recaptcha_site_key' => [
                'title' => __('reCAPTCHA Site Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __('Only used when Bot Protection above is set to Google reCAPTCHA v3.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-bot-protection-field apnmi-bot-protection-field--recaptcha',
            ],
            'recaptcha_secret_key' => [
                'title' => __('reCAPTCHA Secret Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'password',
                'description' => __('Only used when Bot Protection above is set to Google reCAPTCHA v3.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-bot-protection-field apnmi-bot-protection-field--recaptcha',
            ],
            'recaptcha_min_score' => [
                'title' => __('reCAPTCHA Minimum Score', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __('reCAPTCHA v3 scores each request from 0.0 (likely a bot) to 1.0 (likely human). Requests scoring below this are blocked. Default: 0.5.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '0.5',
                'desc_tip' => false,
                'class' => 'apnmi-bot-protection-field apnmi-bot-protection-field--recaptcha',
            ],
            'turnstile_site_key' => [
                'title' => __('Turnstile Site Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __('Only used when Bot Protection above is set to Cloudflare Turnstile.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-bot-protection-field apnmi-bot-protection-field--turnstile',
            ],
            'turnstile_secret_key' => [
                'title' => __('Turnstile Secret Key', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'password',
                'description' => __('Only used when Bot Protection above is set to Cloudflare Turnstile.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => '',
                'desc_tip' => false,
                'class' => 'apnmi-bot-protection-field apnmi-bot-protection-field--turnstile',
            ],
            'section_descriptor' => [
                'title'       => __('Billing Descriptor', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type'        => 'title',
                'class'       => $card,
                'default'     => '',
                'description' => __('The business name and phone number that appear on your customers\' card statements.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'enable_descriptor' => [
                'title' => __('Enable Billing Descriptor', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'label' => __('Enable custom billing descriptor', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __(
                    'When enabled, the specified billing descriptor will be sent with transactions.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
            ],
            'descriptor' => [
                'title' => __('Billing Descriptor', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    '<p>The billing descriptor that appears on customers\' credit card statements. 
                    Typically includes your business name and contact information. 
                    Maximum 60 characters.</p>
                    <p>The address, city, state, country fields are taken in WooCommerce General Settings.</p>
                    <p>The URL is taken from WordPress Site Address (URL) setting.</p>
                    ',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => substr(get_option('blogname'), 0, 60),
                'desc_tip' => false,
            ],
            'descriptor_phone' => [
                'title' => __('Billing Descriptor Phone', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'text',
                'description' => __(
                    'The phone number that appears on customers\' credit card statements. 
                    Typically includes your business phone number.
                    <br>Maximum 13 characters.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => '',
                'desc_tip' => false,
            ],
            'section_developer' => [
                'title'       => __('Developer Tools', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type'        => 'title',
                'class'       => $card,
                'default'     => '',
                'description' => __('Logging, the checkout debug console and the admin bar shortcut.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ],
            'logging' => [
                'title' => __('Logging', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'label' => __('Enable Logging', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __(
                    'Enable logging for the payment gateway.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => 'no',
                'desc_tip' => false,
            ],
            'enable_debug_console' => [
                'title' => __('Debug Console', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'label' => __('Enable NMI Payment Debug Console', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __(
                    'Shows an on-page debug console on checkout with real-time console logs and system info — useful for diagnosing Apple Pay/Google Pay on devices without dev tools access. Leave disabled for real customers.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                ),
                'default' => 'no',
                'desc_tip' => false,
            ],
            'admin_bar_menu' => [
                'title' => __('Admin bar shortcut', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'label' => __('Show "NMI Settings" in the top admin bar', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Adds a quick link to these settings in the black bar at the top of the screen. Only store managers see it.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                'default' => 'no',
                'desc_tip' => false,
            ],
        ];
    }

    /**
     * Show only the selected Bot Protection provider's key fields, hiding the
     * other two — same show/hide-on-select pattern as the Processing Fee
     * dropdown (see Processing_Fee_Settings::maybe_render_toggle_script()).
     *
     * @return void
     */
    public function render_bot_protection_toggle_script(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'woocommerce_page_wc-settings' !== $screen->id) {
            return;
        }

        $provider_field_id = 'woocommerce_' . self::GATEWAY_ID . '_bot_protection_provider';
        ?>
        <script>
        jQuery(function ($) {
            var $provider = $('#<?php echo esc_js($provider_field_id); ?>');
            if (!$provider.length) return;

            var providers = ['recaptcha', 'turnstile'];

            function toggle() {
                var val = $provider.val();
                providers.forEach(function (provider) {
                    $('.apnmi-bot-protection-field--' + provider).closest('tr').toggle(val === provider);
                });
            }

            $provider.on('change', toggle);
            toggle();
        });
        </script>
        <?php
    }

    /**
     * Save settings, then mirror the chosen Environment onto the legacy
     * `testmode` / `pathfinder_sandbox` options the rest of the plugin reads.
     *
     * @return bool
     */
    public function process_admin_options()
    {
        $saved = parent::process_admin_options();

        $settings = get_option($this->get_option_key(), []);
        if (is_array($settings)) {
            $settings = Environment::apply_legacy($settings);
            update_option($this->get_option_key(), $settings, 'yes');
            $this->settings = $settings;
            $this->testmode = Environment::TEST === $settings['environment'];
        }

        return $saved;
    }

    /**
     * Environment dropdown helper on the settings screen: only show the
     * Sandbox email field in Sandbox.
     *
     * @return void
     */
    public function render_environment_script(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'woocommerce_page_wc-settings' !== $screen->id) {
            return;
        }

        // The gateway is instantiated more than once on the NMI Settings tab, so this
        // footer hook is registered more than once. Print the script a single time.
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        $field_id = 'woocommerce_' . self::GATEWAY_ID . '_environment';
        ?>
        <style>
            .apnmi-decline-field { border: 1px solid #c3c4c7; border-left: 4px solid #d63638; border-radius: 4px; padding: 8px 10px; }
            .apnmi-decline-field:focus { border-color: #d63638; box-shadow: 0 0 0 1px #d63638; }
            .apnmi-decline-preview { margin-top: 10px; max-width: 700px; padding: 10px 14px; background: #fcf0f1; border: 1px solid #f1c6c8; border-left: 4px solid #d63638; border-radius: 4px; color: #1d2327; font-size: 13px; line-height: 1.5; }
            .apnmi-decline-preview::before { content: "<?php echo esc_js(__('Customer sees:', 'gaincommerce-nmi-payment-gateway-for-woocommerce')); ?>"; display: block; margin-bottom: 2px; color: #b32d2e; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        </style>
        <script>
        jQuery(function ($) {
            var $env = $('#<?php echo esc_js($field_id); ?>');
            if (!$env.length) return;

            // Decline message inputs only show while "Show Decline Message" is selected.
            var $friendly = $('#woocommerce_<?php echo esc_js(self::GATEWAY_ID); ?>_decline_messages_editor');
            function toggleDeclines() {
                $('.apnmi-decline-field').closest('tr').toggle(!$friendly.length || $friendly.val() === 'yes');
            }
            $friendly.on('change', toggleDeclines);
            toggleDeclines();

            // Live preview under each decline message: what the shopper will actually see.
            $('.apnmi-decline-field').each(function () {
                var $input = $(this);
                var $preview = $('<div class="apnmi-decline-preview" aria-live="polite"></div>');
                $input.closest('td').append($preview);
                function refresh() {
                    var text = $.trim($input.val()) || $input.attr('placeholder') || '';
                    $preview.text(text);
                }
                $input.on('input', refresh);
                refresh();
            });

            function toggle() {
                var sandbox = $env.val() === 'sandbox';
                $('.apnmi-sandbox-field').closest('tr').toggle(sandbox);
                $('.apnmi-live-field').closest('tr').toggle(!sandbox);
            }

            $env.on('change', toggle);
            toggle();
        });
        </script>
        <?php
    }

    public function payment_fields()
    {
        // Shown for both Test and Sandbox; empty when live.
        $test_mode_notes = Environment::checkout_notice_text();

        // Safely check if premium plugin's save card feature is enabled
        $save_payment_enabled = false;
        if (class_exists('GainCommerceNmiEnterprise\Save_Card_Settings')) {
            $save_payment_enabled = \GainCommerceNmiEnterprise\Save_Card_Settings::is_save_card_enabled();
        }

        // Both Apple Pay and Google Pay are rendered as express buttons above all payment
        // methods via Plugin::render_wallet_express_buttons() — nothing to render here.

        $args = [
            'gateway_id'        => $this->id,
            'description'       => $this->description,
            'test_mode_notes'   => $test_mode_notes,
            'is_on_test_mode'   => Environment::is_non_live(),
            'use_collect_js'    => $this->use_collect_js,
            'display_accepted_cards' => $this->display_card_type_icons(),
            'save_payment_enabled' => $save_payment_enabled,
            'debug_console_enabled' => $this->get_option('enable_debug_console') === 'yes'
        ];

        wc_get_template(
            'public/wc-payment-fields.php',
            $args,
            '',
            apnmi_get_plugin_dir() . 'template/'
        );
    }

    public function payment_scripts()
    {
        if( ! is_cart() && ! is_checkout() ) {
            return;
        }

        if( 'no' === $this->enabled ) {
            return;
        }

        if( empty( $this->private_key ) || empty( $this->public_key ) ) {
            return;
        }

        // Blocks checkout has its own script integration.
        if ($this->is_blocks_checkout()) {
            return;
        }

        wp_enqueue_script('nmi-collectjs');
        wp_enqueue_script('ap-nmi-unified-integration');
        wp_enqueue_style('ap-nmi-unified-styles');
	}

    /**
     * Check if the current request is for blocks checkout
     * 
     * @return bool
     */
    private function is_blocks_checkout() {

        // Method 1: Check for AJAX requests from blocks checkout
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally checking for blocks checkout patterns
        if (wp_doing_ajax() && isset($_POST['wc-ajax']) && $_POST['wc-ajax'] === 'checkout') {
            // Check if request contains blocks-specific data
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally checking for blocks checkout patterns
            if (isset($_POST['payment_method_data']) || 
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally checking for blocks checkout patterns
                (isset($_POST['extensions']) && is_array($_POST['extensions']))) {
                return true;
            }
        }

        // Method 2: Check for Store API requests (REST API)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $rest_route = isset($GLOBALS['wp']->query_vars['rest_route']) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
            if (strpos($rest_route, '/wc/store/') !== false && strpos($rest_route, 'checkout') !== false) {
                return true;
            }
        }

        // Method 3: Check if we have blocks-specific $_POST data patterns
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally checking for blocks checkout patterns
        if (isset($_POST['payment_method']) && isset($_POST['billing_address'])) {
            return true;
        }
        
        return false;
    }

    public function validate_fields() 
    {
        if (
            // Standard blocks detection
            $this->is_blocks_checkout() ||
            // Additional safety checks for blocks
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checking for blocks checkout patterns
            isset($_POST['payment_method_data']) ||
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checking for blocks checkout patterns
            isset($_POST['extensions']) ||
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checking for blocks checkout patterns
            (isset($_POST['billing_address']) && is_array($_POST['billing_address'])) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            // If we're in an AJAX context without the legacy nonce
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checking for blocks checkout patterns
            (wp_doing_ajax() && !isset($_POST['woocommerce-process-checkout-nonce']))
        ) {
            return true;
        }
        // Legacy checkout nonce verification
        if (
            ! isset($_POST['woocommerce-process-checkout-nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce'])),
                'woocommerce-process_checkout'
            )
        ) {
            wc_add_notice(__('Security check failed. Please refresh and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 'error');
            return false;
        }

        if (!\APNMIPaymentGateway\Bot_Protection::is_session_verified()) {
            wc_add_notice(__('We could not verify this request as coming from a human. Please refresh the page and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 'error');
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This check is for validation purposes only
        if( isset($_POST['payment_token']) && empty($_POST['payment_token'])) {
            wc_add_notice(__('Payment information is required. Please fill in all credit card fields.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 'error');
            return false;
        }
        
        return true;
    }

    public function process_payment( $order_id )
    {
        // Opens the server half of the checkout trace. The browser sends its trace id
        // along with the order, so these lines interleave with the browser's under the
        // same id in WooCommerce > Status > Logs.
        \APNMIPaymentGateway\Checkout_Trace::server('process_payment:start', [
            'order_id' => $order_id,
            'checkout' => $this->is_blocks_checkout() ? 'blocks' : 'legacy',
        ]);

        $order = wc_get_order($order_id);

        if (!$order) {
            \APNMIPaymentGateway\Checkout_Trace::server('process_payment:order_not_found', ['order_id' => $order_id], 'error');
            wc_add_notice(__('Order not found.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 'error');
            return ['result' => 'fail'];
        }

        // Bot protection (reCAPTCHA v3 / Turnstile). Legacy checkout is
        // already rejected earlier by validate_fields(); this is the only
        // enforcement point blocks checkout ever reaches, since it skips
        // validate_fields() entirely (see is_blocks_checkout() branching above).
        // Express wallet payments (Apple Pay / Google Pay on the cart and product pages, from the
        // Enterprise add-on) are authenticated on the shopper's own device, so they can opt out here.
        $bypass_bot_protection = (bool) apply_filters('apnmi_bypass_bot_protection', false, $order);

        if (!$bypass_bot_protection && !\APNMIPaymentGateway\Bot_Protection::is_session_verified()) {
            \APNMIPaymentGateway\Checkout_Trace::server('process_payment:bot_protection_failed', ['order_id' => $order_id], 'warning');
            wc_add_notice(__('We could not verify this request as coming from a human. Please refresh the page and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 'error');
            return ['result' => 'fail'];
        }

        // Skip nonce verification for blocks checkout as it's handled by Store API
        if (!$this->is_blocks_checkout()) {
            // Legacy checkout nonce verification
            if (
                ! isset($_POST['woocommerce-process-checkout-nonce']) ||
                ! wp_verify_nonce(
                    sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce'])),
                    'woocommerce-process_checkout'
                )
            ) {
                wc_add_notice(__('Security check failed. Please refresh and try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 'error');
                return ['result' => 'fail'];
            }
        }

        $payment_token = '';
        $use_saved_payment_method = false;
        
        // For blocks checkout, the token is in a different place
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        if (isset($_POST['payment_method_data']['payment_token'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
            $payment_token = sanitize_text_field(wp_unslash($_POST['payment_method_data']['payment_token']));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        } elseif (isset($_POST['payment_token']) && !empty($_POST['payment_token'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
            $payment_token = sanitize_text_field(wp_unslash($_POST['payment_token']));
        }

        // Card brand CollectJS's own tokenization response reported in the browser
        // (see ap-nmi-unified-integration.js / checkout-blocks.js). Used below to
        // enforce "Restrict Card Types" server-side; not present for saved-card
        // (customer vault) payments, which fall through the restriction check
        // unchecked.
        $card_type = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        if (isset($_POST['payment_method_data']['card_type']) && is_string($_POST['payment_method_data']['card_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
            $card_type = sanitize_text_field(wp_unslash($_POST['payment_method_data']['card_type']));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        } elseif (isset($_POST['card_type']) && is_string($_POST['card_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
            $card_type = sanitize_text_field(wp_unslash($_POST['card_type']));
        }

        // Check if using saved payment method (blocks checkout)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        if (isset($_POST['payment_method_data']['use_save_payment_method']) && $_POST['payment_method_data']['use_save_payment_method'] == '1') {
            $use_saved_payment_method = true;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        } elseif (isset($_POST['use_save_payment_method']) && $_POST['use_save_payment_method'] == '1') {
            // Legacy checkout
            $use_saved_payment_method = true;
        }
        
        // The single most useful line in the whole trace: did a token actually arrive,
        // and from which of the two possible request shapes?
        \APNMIPaymentGateway\Checkout_Trace::server('process_payment:token', [
            'has_token'    => !empty($payment_token),
            'token_length' => strlen($payment_token),
            'source'       => isset($_POST['payment_method_data']['payment_token'])
                ? 'payment_method_data'
                : (isset($_POST['payment_token']) ? 'post' : 'none'),
            'use_saved'    => $use_saved_payment_method,
        ]);

        $card_data = [];
        if (empty($payment_token)) {
            // Fallback for non-CollectJS, or if the token is missing.
            $card_data = [
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
                'ccnumber' => isset($_POST['ccnumber']) ? sanitize_text_field(wp_unslash($_POST['ccnumber'])) : '',
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
                'ccexp' => isset($_POST['ccexp']) ? sanitize_text_field(wp_unslash($_POST['ccexp'])) : '',
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
                'cvv' => isset($_POST['cvv']) ? sanitize_text_field(wp_unslash($_POST['cvv'])) : '',
            ];
        }

        $gateway_config = [
            'test_mode' => $this->testmode,
            'public_key' => $this->public_key,
            'private_key' => $this->private_key,
            'use_collect_js' => $this->use_collect_js,
            'transaction_mode' => $this->transaction_mode,
            'payment_token' => $payment_token,
            'card_data' => $card_data,
            'card_type' => $card_type,
            'restricted_card_types' => $this->restricted_card_types,
            'use_save_payment_method' => $use_saved_payment_method
        ];

        if (isset($_POST['save_payment_method']) && $_POST['save_payment_method'] == '1') {
            $gateway_config['save_payment_method'] = true;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
        } elseif (isset($_POST['payment_method_data']['save_payment_method']) && $_POST['payment_method_data']['save_payment_method'] == '1') {
            $gateway_config['save_payment_method'] = true;
        } else {
            $gateway_config['save_payment_method'] = false;
        }

        // NOTE: do not recompute 'use_save_payment_method' here. It is already set
        // above from the derivation at the top of this method, which reads both
        // request shapes. A second block used to overwrite it and guarded on
        // isset($_POST['save_payment_method']) while reading
        // $_POST['use_save_payment_method'] — two different keys. On legacy checkout
        // 'save_payment_method' is never posted for a saved card (the template
        // checkbox has no name attribute, and the hidden input is only appended on
        // the new-card path), so the guard failed, the else branch forced false, and
        // every legacy saved-card order fell through to the raw-card branch and
        // failed with "Missing required field: ccnumber".

        // Extract 3DS data if the premium plugin is active and 3DS is enabled.
        $threeds_data = [];

        $is_3ds_enabled = class_exists('GainCommerceNmiEnterprise\Settings\ThreeDS_Settings')
            && \GainCommerceNmiEnterprise\Settings\ThreeDS_Settings::is_3ds_enabled();

        if (!$is_3ds_enabled) {
            Logger::get_instance()->debug('3DS disabled or premium plugin not active', [
                'order_id' => $order->get_id()
            ]);
        } else {
            Logger::get_instance()->debug('3DS enabled - checking for authentication data', [
                'order_id' => $order->get_id()
            ]);

            // Blocks checkout.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
            if (isset($_POST['payment_method_data'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each sub-field is unslashed + sanitize_text_field()'d individually below before use.
                $payment_data = wp_unslash($_POST['payment_method_data']);

                if (isset($payment_data['cavv']) && is_string($payment_data['cavv']) && !empty($payment_data['cavv'])) {
                    $threeds_data['cavv'] = sanitize_text_field(wp_unslash($payment_data['cavv']));
                }
                if (isset($payment_data['xid']) && is_string($payment_data['xid']) && !empty($payment_data['xid'])) {
                    $threeds_data['xid'] = sanitize_text_field(wp_unslash($payment_data['xid']));
                }
                if (isset($payment_data['eci']) && is_string($payment_data['eci']) && !empty($payment_data['eci'])) {
                    $threeds_data['eci'] = sanitize_text_field(wp_unslash($payment_data['eci']));
                }
                if (isset($payment_data['cardholder_auth']) && is_string($payment_data['cardholder_auth']) && !empty($payment_data['cardholder_auth'])) {
                    $threeds_data['cardholder_auth'] = sanitize_text_field(wp_unslash($payment_data['cardholder_auth']));
                }
                if (isset($payment_data['three_ds_version']) && is_string($payment_data['three_ds_version']) && !empty($payment_data['three_ds_version'])) {
                    $threeds_data['three_ds_version'] = sanitize_text_field(wp_unslash($payment_data['three_ds_version']));
                }
                if (isset($payment_data['directory_server_id']) && is_string($payment_data['directory_server_id']) && !empty($payment_data['directory_server_id'])) {
                    $threeds_data['directory_server_id'] = sanitize_text_field(wp_unslash($payment_data['directory_server_id']));
                }
                if (isset($payment_data['cardholder_info']) && is_string($payment_data['cardholder_info']) && !empty($payment_data['cardholder_info'])) {
                    $threeds_data['cardholder_info'] = sanitize_text_field(wp_unslash($payment_data['cardholder_info']));
                }
            }
            
            // Legacy checkout.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled earlier in the method
            if (empty($threeds_data) && isset($_POST['threeds_cavv'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (is_string($_POST['threeds_cavv']) && !empty($_POST['threeds_cavv'])) {
                    $threeds_data['cavv'] = sanitize_text_field(wp_unslash($_POST['threeds_cavv']));
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (isset($_POST['threeds_xid']) && is_string($_POST['threeds_xid']) && !empty($_POST['threeds_xid'])) {
                    $threeds_data['xid'] = sanitize_text_field(wp_unslash($_POST['threeds_xid']));
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (isset($_POST['threeds_eci']) && is_string($_POST['threeds_eci']) && !empty($_POST['threeds_eci'])) {
                    $threeds_data['eci'] = sanitize_text_field(wp_unslash($_POST['threeds_eci']));
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (isset($_POST['threeds_cardholder_auth']) && is_string($_POST['threeds_cardholder_auth']) && !empty($_POST['threeds_cardholder_auth'])) {
                    $threeds_data['cardholder_auth'] = sanitize_text_field(wp_unslash($_POST['threeds_cardholder_auth']));
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (isset($_POST['threeds_version']) && is_string($_POST['threeds_version']) && !empty($_POST['threeds_version'])) {
                    $threeds_data['three_ds_version'] = sanitize_text_field(wp_unslash($_POST['threeds_version']));
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (isset($_POST['threeds_directory_server_id']) && is_string($_POST['threeds_directory_server_id']) && !empty($_POST['threeds_directory_server_id'])) {
                    $threeds_data['directory_server_id'] = sanitize_text_field(wp_unslash($_POST['threeds_directory_server_id']));
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if (isset($_POST['threeds_cardholder_info']) && is_string($_POST['threeds_cardholder_info']) && !empty($_POST['threeds_cardholder_info'])) {
                    $threeds_data['cardholder_info'] = sanitize_text_field(wp_unslash($_POST['threeds_cardholder_info']));
                }
            }

            if (!empty($threeds_data)) {
                Logger::get_instance()->debug('3DS data extracted from request', [
                    'order_id' => $order->get_id(),
                    'has_cavv' => !empty($threeds_data['cavv']),
                    'has_xid' => !empty($threeds_data['xid']),
                    'three_ds_version' => $threeds_data['three_ds_version'] ?? 'unknown'
                ]);
            } else {
                Logger::get_instance()->debug('No 3DS data found in request', [
                    'order_id' => $order->get_id()
                ]);
            }
        }

        if (!empty($threeds_data)) {
            $gateway_config['threeds_data'] = $threeds_data;
        }

        if ($this->send_receipts) {
            $gateway_config['customer_receipt'] = true;
        }

        try {
            $process_payment = new NMI_Process_Payment($order, $gateway_config);
            $response = $process_payment->process_sale();

            Logger::get_instance()->debug(
                'WC Gateway process_sale return API', 
                $response
            );
        } catch (\Exception $e) {
            Logger::get_instance()->error('Payment processing exception', [
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
            ]);
            
            $error_message = $e->getMessage();
            // Blocks (REST) re-throws below; a session notice would reappear on the next page load.
            if (!(defined('REST_REQUEST') && REST_REQUEST)) {
                wc_add_notice($error_message, 'error');
            }

            $order->add_order_note(
                sprintf(
                    // translators: %1$s: Exception message
                    __('NMI payment exception: %1$s', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    $error_message
                )
            );
            
            $order->update_status('failed', $error_message);
            
            // For Blocks checkout, re-throw to ensure error displays
            if (defined('REST_REQUEST') && REST_REQUEST) {
                throw $e;
            }
            
            return [
                'result' => 'fail',
                'messages' => '<div class="woocommerce-error">' . esc_html($error_message) . '</div>',
            ];
        }

        \APNMIPaymentGateway\Checkout_Trace::server('process_payment:gateway_response', [
            'success'        => !empty($response['success']),
            'transaction_id' => $response['transaction_id'] ?? null,
            'response_code'  => $response['response_code'] ?? null,
            'message'        => $response['message'] ?? null,
        ], !empty($response['success']) ? 'info' : 'error');

        if ($response['success']) {

            $order->add_meta_data(AP_NMI_WC_META_DATA_TRANSACTION_ID, $response['transaction_id']);

            do_action('apnmi_after_payment_success_processed', $order, $response, $gateway_config);

            if ($this->transaction_mode === 'auth') {
                // Authorization only — don't complete the payment yet; the order goes
                // on-hold until it's captured (see NMI_Capture_Payment).
                $order->update_status(
                    'on-hold',
                    sprintf(
                        // translators: %1$s: Transaction ID
                        __(
                            'Payment authorized. Transaction ID: %1$s. Capture payment when ready to complete the order.',
                            'gaincommerce-nmi-payment-gateway-for-woocommerce'
                        ),
                        $response['transaction_id']
                    )
                );

                $order->add_meta_data(
                    AP_NMI_WC_META_DATA_AUTH_AMOUNT,
                    $order->get_total()
                );

                $order->add_meta_data(
                    AP_NMI_WC_META_DATA_TRANSACTION_MODE,
                    'auth'
                );

                $note = sprintf(
                    /* translators: 1: Transaction ID*/
                    __(
                        'NMI payment authorized (not captured). Transaction ID: %1$s',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    ),
                    $response['transaction_id']
                );

                if ($this->send_receipts) {
                    $note .= ' ' . __(
                        'Email receipt sent to customer.',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    );
                }

                $order->add_order_note($note);
            }

            if ($this->transaction_mode === 'sale') {
                // Payment completed immediately — order moves straight to processing.
                $order->payment_complete($response['transaction_id']);
                wc_reduce_stock_levels($order_id);

                $note = sprintf(
                    // translators: 1: Transaction ID
                    __(
                        'NMI payment completed. Transaction ID: %1$s',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    ),
                    $response['transaction_id']
                );

                if ($this->send_receipts) {
                    $note .= ' ' . __(
                        'Email receipt sent to customer.',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    );
                }

                $order->add_order_note($note);
            }

            $avs_code = $response['avs_response'];
            $avs_response = Parse_Response_Codes::avs_response($avs_code);
            $cvv_code = $response['cvv_response'];
            $cvv_response = Parse_Response_Codes::cvv_response($cvv_code);
  
            $order->add_order_note(sprintf(
                // translators: 1: AVS response, 2: CVV response
                __('AVS Response: %1$s, CVV Response: %2$s', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                $avs_response,
                $cvv_response
            ));

            if (isset($response['response_code']) ) {
                $interpret_response_code = Parse_Response_Codes::api_response_code($response['response_code']);
                $order->add_order_note(sprintf(
                    // translators: 1: API response code
                    __('Response Code: %1$s', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                    $interpret_response_code
                ));
            }
            
            $order->save();

            WC()->cart->empty_cart();

            do_action('apnmi_after_payment_complete', $order, $response, $gateway_config);

            \APNMIPaymentGateway\Checkout_Trace::server('process_payment:success', [
                'order_id'       => $order->get_id(),
                'transaction_id' => $response['transaction_id'] ?? null,
            ]);

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } else {

            // NMI's original text stays in the order note and log; the shopper sees a friendly message.
            $raw_error_message = !empty($response['message'])
                ? $response['message']
                : __('Payment processing failed. Please try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
            $nmi_response_code = $response['response_code'] ?? null;
            $error_message = Decline_Messages::for_response($nmi_response_code, (string) $raw_error_message);

            Logger::get_instance()->error('Payment failed', [
                'order_id' => $order->get_id(),
                'error_message' => $raw_error_message,
                'shopper_message' => $error_message,
                'response_code' => $nmi_response_code,
                'response' => $response,
            ]);

            /**
             * Fires when NMI declines a card payment, before the order is marked failed.
             *
             * @param \WC_Order  $order             The order.
             * @param array      $response          The standardised gateway response.
             * @param string|int $nmi_response_code NMI's numeric response code, when there is one.
             */
            do_action('apnmi_payment_declined', $order, is_array($response) ? $response : [], $nmi_response_code);

            // Blocks (REST) shows the exception thrown below. A session notice on top of it is
            // never cleared by WooCommerce and reappears on the next page load, with whatever
            // wording was saved at the time. Only the classic checkout needs the notice.
            if (!(defined('REST_REQUEST') && REST_REQUEST)) {
                wc_add_notice($error_message, 'error');
            }

            $order->add_order_note(
                sprintf(
                     // translators: 1: NMI response code, 2: NMI's original error message
                    __(
                        'NMI payment failed (code %1$s): %2$s',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    ),
                    (null !== $nmi_response_code && '' !== (string) $nmi_response_code) ? $nmi_response_code : 'n/a',
                    $raw_error_message
                )
            );

            $order->update_status('failed', $raw_error_message);

            // Blocks checkout displays the error only if it's thrown, not returned.
            if (defined('REST_REQUEST') && REST_REQUEST) {
                throw new \Exception(esc_html($error_message));
            }
            
            return [
                'result' => 'fail',
                'messages' => '<div class="woocommerce-error">' . esc_html($error_message) . '</div>',
            ];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        $transaction_id = $order->get_meta(AP_NMI_WC_META_DATA_TRANSACTION_ID);

        if (!$transaction_id) {
            return new \WP_Error(
                'nmi_refund_error',
                __(
                    'Transaction ID not found.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                )
            );
        }

        $factory = NMI_API_Factory::get_instance();

        $config = [
            'test_mode' => $this->testmode,
            'public_key' => $this->public_key,
            'private_key' => $this->private_key,
        ];

        $payment_api = $factory->create_payment_api($config);
        $response_handler = $factory->create_response_handler();

        $api_response = $payment_api->refund_transaction($transaction_id, $amount);
        $processed_response = $response_handler->process_response($api_response);

        if ($processed_response['success']) {

            $order->add_order_note(
                sprintf(
                    // translators: 1: Amount, 2: Refund ID, 3: Reason
                    __(
                        'Refunded %1$s - Refund ID: %2$s - Reason: %3$s',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    ),
                    wc_price($amount),
                    $processed_response['transaction_id'],
                    $reason
                )
            );
            return true;
        } else {
            return new \WP_Error(
                'nmi_refund_error',
                $processed_response['response_message'] ?: __(
                    'Refund failed.',
                    'gaincommerce-nmi-payment-gateway-for-woocommerce'
                )
            );
        }
    }

    /**
     * Build the card type icons markup, with restriction indicators.
     *
     * Returns the HTML instead of echoing it: payment_fields() passes the
     * result through as template data ('display_accepted_cards'), and an
     * echo here would print at the point payment_fields() runs rather than
     * where the template actually wants it.
     *
     * @return string
     */
    public function display_card_type_icons(): string
    {
        $all_card_types = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay'
        ];

        $restricted_card_types = $this->get_option('restricted_card_types', []);
        $html = '<div class="ap-nmi-card-icons">';

        foreach ($all_card_types as $card_type => $card_name) {
            $class = 'card-icon ' . $card_type;
            if (in_array($card_type, $restricted_card_types)) {
                $class .= ' restricted';

                $title = sprintf(
                    // translators: 1: Card name
                    __(
                        '%1$s cards are not accepted',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    ),
                    $card_name
                );
            } else {
                $class .= ' allowed';

                $title = sprintf(
                    // translators: 1: Card name
                    __(
                        '%1$s cards are accepted',
                        'gaincommerce-nmi-payment-gateway-for-woocommerce'
                    ),
                    $card_name
                );
            }
            $icon_url = AP_NMI_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/images/cards/' . $card_type . '.jpg';
            $html .= '<span class="' . esc_attr($class) . '" title="' . esc_attr($title) . '">';
            $html .= '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($card_name) . '" loading="lazy" />';
            $html .= '</span>';
        }

        $html .= '</div>';

        if (!empty($restricted_card_types)) {
            $html .= '<div class="ap-nmi-card-restrictions-info">';
            $html .= esc_html__(
                'Cards marked with ✕ are not accepted for payment.',
                'gaincommerce-nmi-payment-gateway-for-woocommerce'
            );
            $html .= '</div>';
        }

        return $html;
    }

    public function get_allowed_card_types()
    {
        $allowed_card_types = [];

        $all_card_types = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard', 
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay'
        ];

        $restricted_card_types = $this->get_option('restricted_card_types', []);

        foreach ($all_card_types as $card_type => $card_name) {
            $status = '';
            $title = '';

            if (in_array($card_type, $restricted_card_types)) {
                $status = 'restricted';
                $title = sprintf(
                    // translators: 1: Card name
                    __('%1$s cards are not accepted', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 
                    $card_name
                );
            } else {
                $status = ' allowed';
                $title = sprintf(
                    // translators: 1: Card name
                    __('%1$s cards are accepted', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), 
                    $card_name
                );
            }
            $allowed_card_types[$card_type] = [
                'name' => $card_name,
                'title' => esc_attr($title),
                'status' => $status,
                'icon' => AP_NMI_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/images/cards/' . $card_type . '.jpg'
            ];
        }

        return $allowed_card_types;
    }

    public function webhook() 
    {

    }
}