<?php

namespace APNMIPaymentGateway\WC;

class Void_Transaction
{

    public function init_hook()
    {
        // Voids the transaction when an order is cancelled and has a transaction ID.
        // Refunded orders should not be voided.
        add_action( 'woocommerce_order_status_cancelled', 'my_custom_cancelled_order_action' );
    }

    public function process_void($transaction_id)
    {
        // TODO: void the transaction via the NMI API.
        return true;
    }
}