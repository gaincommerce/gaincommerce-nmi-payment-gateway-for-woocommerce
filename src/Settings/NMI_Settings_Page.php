<?php
/**
 * "NMI Settings" tab in WooCommerce > Settings.
 *
 * Renders and saves the gateway's own settings form, so merchants don't have
 * to go through Payments > Manage. Field names and the option row are the
 * gateway's, so both screens stay in sync.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Settings;

use APNMIPaymentGateway\Gateway;
use APNMIPaymentGateway\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NMI_Settings_Page extends \WC_Settings_Page
{
    public function __construct()
    {
        $this->id    = 'nmi';
        $this->label = __('NMI Settings', 'gaincommerce-nmi-payment-gateway-for-woocommerce');

        parent::__construct();
    }

    private function get_gateway(): Gateway
    {
        $gateway = Plugin::get_instance()->get_gateway();
        return $gateway instanceof Gateway ? $gateway : new Gateway();
    }

    /**
     * Print the gateway's settings form inside WooCommerce's settings <form>.
     */
    public function output(): void
    {
        $gateway = $this->get_gateway();

        \APNMIPaymentGateway\Review\Review_Prompt::render_setup_card();

        echo '<h2>' . esc_html($this->label) . '</h2>';
        Environment::render_admin_panel();
        echo '<table class="form-table">';
        $gateway->generate_settings_html($gateway->get_form_fields(), true);
        echo '</table>';

        \APNMIPaymentGateway\Review\Review_Prompt::render_box();
    }

    public function save(): void
    {
        $this->get_gateway()->process_admin_options();
    }
}
