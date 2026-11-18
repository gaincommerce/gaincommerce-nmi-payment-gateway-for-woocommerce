<?php

namespace APNMIPaymentGateway\WC;
use APNMIPaymentGateway\Single_Instance_Trait;
use APNMIPaymentGateway\Logger;
use APNMIPaymentGateway\Gateway;
use APNMIPaymentGateway\API\NMI_API_Factory;
class NMI_Capture_Payment {
    use Single_Instance_Trait;

    public function init_hook()
    {
        add_action('woocommerce_order_status_changed', [$this, 'capture_payment'], 100, 3);
    }

    /**
     * Captures payment for an order when its status changes to completed or processing.
     */
    public function capture_payment($order_id, $old_status, $new_status)
    {
        $order = wc_get_order( $order_id );

        if ( ! $order || ! $order->get_payment_method() ) {
            return false;
        }

        if ( ! in_array( $new_status, [ 'completed', 'processing' ], true ) ) {
            return false;
        }

        if ($old_status == 'on-hold') {
            $gateway = new Gateway();
            $logger = Logger::get_instance();

            $config = [
                'testmode' => $gateway->testmode,
                'private_key' => $gateway->private_key,
                'public_key' => $gateway->public_key
            ];
            
            if ($gateway->send_receipts) {
                $config['customer_receipt'] = true;
            }

            $get_transaction_id = $order->get_meta(AP_NMI_WC_META_DATA_TRANSACTION_ID);
            $get_amount = $order->get_meta(AP_NMI_WC_META_DATA_AUTH_AMOUNT);
            $get_type = $order->get_meta(AP_NMI_WC_META_DATA_TRANSACTION_MODE);

            if ($get_type === 'auth') {
                $capture_info = 'Capturing payment for order ID: ' . $order_id . ', Transaction ID: ' . $get_transaction_id;
                $logger->info($capture_info);
                $order->add_order_note($capture_info);

                $factory = NMI_API_Factory::get_instance();
                $payment_api = $factory->create_payment_api($config);
                $response_handler = $factory->create_response_handler($payment_api);

                $transaction_id = $get_transaction_id;
                $amount = $get_amount;

                $api_response = $payment_api->capture_transaction($transaction_id, $amount);

                $processed_response = $response_handler->process_response($api_response);
                
                if ($response_handler->is_approved($api_response) ) {
                    $transaction_id = $response_handler->get_transaction_id($api_response);
                    
                    $capture_success = 'Success Capture Payment for order ID: ' . $order_id . ', Transaction ID: ' . $transaction_id;
                    
                    $logger->info($capture_success);
                    
                    $order->add_order_note($capture_success);

                } else {
                    $response_txt = $response_handler->get_response_message($api_response);
                    $response_code = $response_handler->get_response_code($api_response);

                    $error_text = 'Response Text : [' .$response_txt . '] Code : [' . $response_code . '] . Transaction ID : [' . $transaction_id . ']';

                    $logger->error($error_text);
                    $order->add_order_note($error_text);

                    // The money was not taken, so the order must not look paid. Put it back
                    // On hold (this hook ignores the on-hold status, so it cannot loop); the
                    // merchant can fix the cause and set Processing/Completed again to retry.
                    $order->update_status(
                        'on-hold',
                        __(
                            'NMI could not capture this payment, so the order was put back On hold. Check the note above, then set the order to Processing or Completed again to retry the capture.',
                            'gaincommerce-nmi-payment-gateway-for-woocommerce'
                        )
                    );
                }
            }
        }

        return true;
    }
}