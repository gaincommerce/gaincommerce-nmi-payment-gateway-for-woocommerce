<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$apnmi_description        = isset($args['description']) ? $args['description'] : '';
$apnmi_gateway_id         = isset($args['gateway_id']) ? $args['gateway_id'] : '';
$apnmi_is_on_test_mode    = isset($args['is_on_test_mode']) ? $args['is_on_test_mode'] : false;
$apnmi_test_mode_notes    = isset($args['test_mode_notes']) ? $args['test_mode_notes'] : '';
$apnmi_use_collect_js     = isset($args['use_collect_js']) ? $args['use_collect_js'] : false;
$apnmi_display_accepted_cards = isset($args['display_accepted_cards']) ? $args['display_accepted_cards'] : false;
$apnmi_debug_console_enabled = isset($args['debug_console_enabled']) ? $args['debug_console_enabled'] : false;

// Check for saved payment method (premium feature)
$apnmi_has_saved_card = false;
$apnmi_saved_card_details = null;
if (class_exists('GainCommerceNmiEnterprise\\User\\Meta_Save_Payment_Method_CC') && 
    class_exists('GainCommerceNmiEnterprise\\Service\\Get_Customer_Vault_Service')) {
    
    $apnmi_user_id = get_current_user_id();
    if ($apnmi_user_id) {
        $apnmi_has_saved_card = \GainCommerceNmiEnterprise\User\Meta_Save_Payment_Method_CC::has_customer_vault_id_in_user_meta($apnmi_user_id);
        
        if ($apnmi_has_saved_card) {
            $apnmi_customer_vault_id = \GainCommerceNmiEnterprise\User\Meta_Save_Payment_Method_CC::get_customer_vault_id_from_user_meta($apnmi_user_id);
            $apnmi_saved_card_details = \GainCommerceNmiEnterprise\Service\Get_Customer_Vault_Service::get_cc_details($apnmi_customer_vault_id, $apnmi_user_id);
        }
    }
}
?>
<div>
    <p><?php echo wp_kses_post( $apnmi_description  );?></p>
    <?php if($apnmi_is_on_test_mode) : ?>
        <p><?php echo wp_kses_post( $apnmi_test_mode_notes ); ?></p>
    <?php endif; ?>

    <?php if ($apnmi_debug_console_enabled) : ?>
    <!-- NMI Debug Panel for Apple Pay/Google Pay Debugging -->
    <div class="nmi-debug-panel">
        <div class="nmi-debug-panel-header">
            <div>
                <h3 class="nmi-debug-panel-title">🐛 NMI Payment Debug Console</h3>
                <p class="nmi-debug-panel-subtitle">Real-time debugging for Apple Pay & Google Pay</p>
            </div>
            <div class="nmi-debug-panel-actions">
                <button type="button" id="nmi-debug-clear" class="nmi-debug-btn">Clear Logs</button>
                <button type="button" id="nmi-debug-copy" class="nmi-debug-btn">Copy All</button>
            </div>
        </div>

        <div class="nmi-debug-section">
            <h4 class="nmi-debug-section-title">📱 System Information</h4>
            <div id="nmi-debug-system-info" class="nmi-debug-system-info">
                <div class="nmi-debug-loading">Loading system information</div>
            </div>
        </div>

        <div class="nmi-debug-section">
            <h4 class="nmi-debug-section-title">📝 Console Logs (NMI-related only)</h4>
            <div id="nmi-debug-logs" class="nmi-debug-logs-container">
                <div class="nmi-debug-no-logs">Waiting for logs...</div>
            </div>
        </div>
    </div>
    <!-- End NMI Debug Panel -->
    <?php endif; ?>

    <fieldset id="wc-<?php echo esc_attr( $apnmi_gateway_id ); ?>-payment-form" class="ap-nmi-payment-form loading">

    <?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own standard hook (fired by WC_Payment_Gateway_CC::form()); several WC extensions hook into it expecting every gateway's card form to fire it, so renaming it would break that ecosystem compatibility. ?>
    <?php do_action( 'woocommerce_credit_card_form_start', $apnmi_gateway_id ); ?>

    <?php if ($apnmi_has_saved_card && $apnmi_saved_card_details): ?>
    <div class="ap-nmi-saved-card-selection" style="margin-bottom: 1em;">
        <p class="form-row form-row-wide">
            <label>
                <input type="radio" name="use_save_payment_method" value="1" checked="checked" />
                <?php
                    printf(
                        // translators: %s: Last 4 digits of the saved card number
                        esc_html__('Use saved card ending in ****%s', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
                        esc_html($apnmi_saved_card_details['last4'])
                    );
                    if (!empty($apnmi_saved_card_details['type'])) {
                        echo ' (' . esc_html($apnmi_saved_card_details['type']) . ')';
                    }
                    if (!empty($apnmi_saved_card_details['exp_date'])) {
                        echo ' - ' . esc_html__('Exp:', 'gaincommerce-nmi-payment-gateway-for-woocommerce') . ' ' . esc_html($apnmi_saved_card_details['exp_date']);
                    }
                ?>
            </label>
        </p>
        <p class="form-row form-row-wide">
            <label>
                <input type="radio" name="use_save_payment_method" value="0" />
                <?php esc_html_e('Use a new card', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?>
            </label>
        </p>
    </div>
    <?php else: ?>
        <input type="hidden" name="use_save_payment_method" value="0" />
    <?php endif; ?>

    <div id="ap-nmi-wc-fields-container" <?php echo ($apnmi_has_saved_card ? 'style="display:none;"' : ''); ?>>
        <?php
            wc_get_template(
                'public/api-collectjs-fields.php',
                $args, // Pass data as needed
                '', // No override path
                apnmi_get_plugin_dir() . 'template/'
            );
        ?>
    </div>

    <?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own standard hook (fired by WC_Payment_Gateway_CC::form()); see the matching form_start hook above for why it isn't renamed. ?>
    <?php do_action( 'woocommerce_credit_card_form_end', $apnmi_gateway_id ); ?>

    <?php 
        // Save payment method checkbox - only show when using new card
        $apnmi_save_payment_enabled = isset($args['save_payment_enabled']) ? $args['save_payment_enabled'] : false;
        if ($apnmi_save_payment_enabled) :
            do_action('gaincommerce_before_save_payment_checkbox', $apnmi_gateway_id);
    ?>
    <p class="form-row form-row-wide ap-nmi-save-payment-row" <?php echo ($apnmi_has_saved_card ? 'style="display:none;"' : ''); ?>>
        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
            <input id="save_payment_method" type="checkbox" value="1" />
            <span><?php esc_html_e('Save payment method for future purchases', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></span>
        </label>
    </p>
    <?php
            do_action('gaincommerce_after_save_payment_checkbox', $apnmi_gateway_id);
        endif;
    ?>

    <!-- 3D Secure Mount Point -->
    <div id="threeDSMountPoint"></div>
    
    <!-- 3D Secure Message -->
    <div id="threeDSMessage" style="display: none; padding: 10px; background: #f0f0f0; margin-top: 10px; border-radius: 4px; text-align: center;"></div>

    <?php if ($apnmi_has_saved_card): ?>
    <script type="text/javascript">
    jQuery(function($) {
        // Scoped to CC selection div to avoid conflict with ACH saved payment radios
        $('.ap-nmi-saved-card-selection input[name="use_save_payment_method"]').on('change', function() {
            var useSavedCard = $(this).val() === '1';
            if (useSavedCard) {
                $('#ap-nmi-wc-fields-container').hide();
                $('.ap-nmi-save-payment-row').hide();
            } else {
                $('#ap-nmi-wc-fields-container').show();
                $('.ap-nmi-save-payment-row').show();
            }
        });
    });
    </script>
    <?php endif; ?>
</div>
