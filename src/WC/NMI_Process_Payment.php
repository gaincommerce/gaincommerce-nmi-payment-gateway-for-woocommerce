<?php

namespace APNMIPaymentGateway\WC;
use APNMIPaymentGateway\Single_Instance_Trait;
use APNMIPaymentGateway\API\NMI_API_Factory;
use APNMIPaymentGateway\API\NMI_Payment_API;
use WC_Order;
use APNMIPaymentGateway\Logger;
class NMI_Process_Payment
{
    use Single_Instance_Trait;

    private $order;
    private $config;

    public function __construct(WC_Order $order, array $config = [])
    {
        $this->order = $order;
        $this->config = $config;
    }

    /**
     * @return array|null Failure response when no usable token arrived, else null.
     */
    public function check_payment_token()
    {
        $config = $this->config;

        // A saved card carries no Collect.js token by design — the enterprise plugin
        // swaps in customer_vault_id and unsets payment_token further down the filter
        // chain. Only a *new* card is required to have one.
        if (!empty($config['use_save_payment_method'])) {
            return null;
        }

        if ($config['use_collect_js'] && empty($config['payment_token'])) {
            // get payment token from the form
            return [
                'success' => false,
                'message' => __('Payment token is missing. Please try again.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            ];
        }

        return null;
    }

    /**
     * Server-side enforcement of "Restrict Card Types", so the setting can't be
     * bypassed by a modified or replayed checkout request that skips the same
     * check already run in the browser (see ap-nmi-unified-integration.js /
     * checkout-blocks.js).
     *
     * A saved card (customer_vault_id) or any flow that couldn't report a
     * classifiable brand falls through unchecked here rather than block a
     * payment we can't positively identify — this is a deliberate fail-open,
     * matching the merchant-preference nature of the setting rather than
     * treating it as a hard compliance control.
     *
     * @param NMI_Payment_API $payment_api Already-configured API instance, reused
     *                                     so this needs no extra construction.
     * @param array           $config      The (possibly filter-modified) config in
     *                                     use for this sale — not $this->config,
     *                                     which is the pre-filter snapshot.
     * @return array|null Failure response when the card type is restricted, else null.
     */
    private function check_card_restriction(NMI_Payment_API $payment_api, array $config)
    {
        $restricted = array_map('strtolower', array_filter((array) ($config['restricted_card_types'] ?? [])));
        if (empty($restricted)) {
            return null;
        }

        $card_type = '';

        if (!empty($config['card_data']['ccnumber'])) {
            // Raw card fallback: the digits are on the server, detect for real.
            $card_type = $payment_api->get_card_type($config['card_data']['ccnumber']);
        } elseif (!empty($config['card_type'])) {
            // Tokenized (CollectJS) path: trust the brand the browser's own
            // tokenization response reported — the server never sees the raw
            // digits for a token-based payment.
            $card_type = strtolower(sanitize_text_field((string) $config['card_type']));
        }

        if ($card_type === '' || $card_type === 'unknown' || !in_array($card_type, $restricted, true)) {
            return null;
        }

        Logger::get_instance()->info('NMI Process Payment: Rejected restricted card type', [
            'card_type' => $card_type,
        ]);

        return [
            'success' => false,
            'message' => __('This card type is not accepted. Please use a different card.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
        ];
    }

    public function process_sale()
    {
        // Configure API with current gateway settings
        $config = $this->config;
        $order  = $this->order;

        // Bail early on a missing token rather than letting the request fall through to
        // the API's raw-card branch, which fails with the far less useful
        // "Missing required field: ccnumber".
        $token_error = $this->check_payment_token();
        if (is_array($token_error)) {
            return $token_error;
        }

        $payment_token = $config['payment_token'];

        $payment_data = [
            'amount'            => $order->get_total(),
            'payment_token'     => $payment_token,
            'first_name'        => $order->get_billing_first_name(),
            'last_name'         => $order->get_billing_last_name(),
            'address1'          => $order->get_billing_address_1(),
            'address2'          => $order->get_billing_address_2(),
            'city'              => $order->get_billing_city(),
            'state'             => $order->get_billing_state(),
            'zip'               => $order->get_billing_postcode(),
            'country'           => $order->get_billing_country(),
            'phone'             => $order->get_billing_phone(),
            'email'             => $order->get_billing_email(),
            'shipping'          => [
                'shipping_firstname' => $order->get_shipping_first_name(),
                'shipping_lastname'  => $order->get_shipping_last_name(),
                'shipping_address1'   => $order->get_shipping_address_1(),
                'shipping_address2'   => $order->get_shipping_address_2(),
                'shipping_city'       => $order->get_shipping_city(),
                'shipping_state'      => $order->get_shipping_state(),
                'shipping_postcode'   => $order->get_shipping_postcode(),
                'shipping_country'    => $order->get_shipping_country(),
                'shipping_phone'      => $order->get_billing_phone(),
                'shipping_email'      => $order->get_billing_email(),
            ],
            'orderid'               => $order->get_order_number(),
            'orderdescription'      => sprintf('Order #%s', $order->get_order_number()),
            'ccnumber'              => isset($config['card_data']['ccnumber']) ? $config['card_data']['ccnumber'] : '',
            'ccexp'                 => isset($config['card_data']['ccexp']) ? $config['card_data']['ccexp'] : '',
            'cvv'                   => isset($config['card_data']['cvv']) ? $config['card_data']['cvv'] : '',
        ];

        $payment_data = apply_filters('gaincommerce_nmi_process_payment_data', $payment_data, $order, $config);
        
        $config = apply_filters('gaincommerce_nmi_process_payment_config', $config, $payment_data, $order);

        // Process payment based on transaction mode
        Logger::get_instance()->debug('Processing payment with data', $payment_data);
        
        // Use the new API factory
        $factory = NMI_API_Factory::get_instance();
        
        $payment_api = $factory->create_payment_api($config);

        $restriction_error = $this->check_card_restriction($payment_api, $config);
        if (is_array($restriction_error)) {
            return $restriction_error;
        }

        $response_handler = $factory->create_response_handler();
        if ($config['transaction_mode'] === 'auth') {
            $api_response = $payment_api->process_auth($payment_data);
        } else {
            $api_response = $payment_api->process_sale($payment_data);
        }

        // Process and standardize response
        $processed_response = $response_handler->process_response($api_response);

        // Return standardized response format
        if ($processed_response['success']) {
            return [
                'success' => true,
                'transaction_id' => $processed_response['transaction_id'],
                'auth_code' => $processed_response['auth_code'],
                'response_message' => $processed_response['response_message'],
                'cvv_response' => $processed_response['cvv_response'],
                'avs_response' => $processed_response['avs_response'],
                'response_code' => $processed_response['response_code'],
                'customer_vault_id' => $processed_response['customer_vault_id'] ?? null,
            ];
        } else {
            return [
                'success' => false,
                'message' => $processed_response['response_message'] ?: __('Payment failed.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                // NMI's numeric code, so the gateway can show the shopper a friendly message.
                'response_code' => $processed_response['response_code'] ?? null,
            ];
        }
    }
}