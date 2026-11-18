<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$apnmi_display_accepted_cards = isset($args['display_accepted_cards']) ? $args['display_accepted_cards'] : '';
?>
<div>
    <?php echo wp_kses_post($apnmi_display_accepted_cards); ?>
</div>
<div class="ap-nmi-collectjs-container">
    <div class="ap-nmi-fields-loader">
        <span class="ap-nmi-spinner"></span>
        <span class="ap-nmi-loader-text"><?php esc_html_e('Loading secure payment fields', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></span>
    </div>
    <div class="ap-nmi-legacy-fields">
        <div class="form-row form-row-wide ap-nmi-legacy-number">
            <label for="ap-nmi-card-number"><?php esc_html_e('Card Number', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?><span class="required">*</span></label>
            <div id="ap-nmi-card-number"></div>
        </div>
        <div class="form-row form-row-wide ap-nmi-legacy-expiry">
            <label for="ap-nmi-expiry-date"><?php esc_html_e('Expiry Date', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?> <span class="required">*</span></label>
            <div id="ap-nmi-expiry-date"></div>
        </div>
        <div class="form-row form-row-wide ap-nmi-legacy-cvv">
            <label for="ap-nmi-card-cvv"><?php esc_html_e('CVV', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></label>
            <div id="ap-nmi-card-cvv"></div>
        </div>
    </div>
</div>
