<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Only removes the review-prompt bookkeeping options. Gateway settings and
 * order data are intentionally left untouched.
 *
 * @package APNMIPaymentGateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array(
	'gain_nmi_review_dismissed',
	'gain_nmi_review_snooze_until',
	'gain_nmi_installed_at',
	'gain_nmi_success_orders',
	'gain_nmi_setup_card_state',
) as $apnmi_option ) {
	delete_option( $apnmi_option );
}
