<?php
/**
 * BulletProof Security Pro plugin-firewall whitelist auto-patcher.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Some hosts run BulletProof Security Pro, which manages a whitelist-based
 * plugin firewall at wp-content/plugins/.htaccess: any request for a static
 * asset (including .js) gets a flat 403 unless its exact path is explicitly
 * listed in that file's "BEGIN WHITELIST" / "END WHITELIST" block.
 *
 * This plugin ships two scripts — nmi-wallet-support.js and
 * nmi-checkout-trace.js — that BPS Pro has no way of knowing about ahead of
 * time. Without an entry there, both 403 for every visitor. Apple Pay /
 * Google Pay depend on nmi-wallet-support.js loading (it gates whether the
 * wallet fields get added to CollectJS.configure()), so on a BPS Pro site
 * this silently breaks both wallets with no error anywhere in WooCommerce or
 * WordPress — the request never reaches PHP at all.
 *
 * This class adds the two missing whitelist entries automatically, so
 * installers don't need to know about or manually edit server config to get
 * wallets working. Sites that don't run BPS Pro are unaffected —
 * maybe_whitelist_scripts() is a safe no-op whenever the file doesn't exist,
 * isn't readable/writable, or doesn't look like a BPS Pro whitelist.
 */
class Bulletproof_Security_Whitelist
{
    const BEGIN_MARKER = '# BEGIN WHITELIST';
    const END_MARKER   = '# END WHITELIST';

    /**
     * Add this plugin's script paths to BPS Pro's plugin-firewall whitelist,
     * if that firewall is present and they aren't already listed.
     *
     * Safe to call any number of times — idempotent, and never fatals if the
     * file is missing, unreadable, unwritable, or doesn't match the expected
     * format (e.g. a different security plugin, or BPS Pro not installed).
     *
     * @return void
     */
    public static function maybe_whitelist_scripts(): void
    {
        $htaccess_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/.htaccess' : '';

        if ( ! $htaccess_path || ! file_exists( $htaccess_path ) || ! is_readable( $htaccess_path ) ) {
            return;
        }

        $contents = file_get_contents( $htaccess_path );
        if ( false === $contents ) {
            return;
        }

        // Not a BPS Pro whitelist firewall file (or a version of it we don't
        // recognize) — nothing to do. Never touch a file we can't identify.
        if ( false === strpos( $contents, self::BEGIN_MARKER ) || false === strpos( $contents, self::END_MARKER ) ) {
            return;
        }

        // Derive the folder name at runtime rather than hardcoding it — a
        // manual re-upload or a folder-name collision can install this
        // plugin under a suffixed name (e.g. "...-woocommerce-1").
        $plugin_folder = dirname( plugin_basename( AP_NMI_PAYMENT_GATEWAY_PLUGIN_FILE ) );

        $script_paths = [
            $plugin_folder . '/assets/js/nmi-wallet-support.js',
            $plugin_folder . '/assets/js/nmi-checkout-trace.js',
            $plugin_folder . '/assets/js/bot-protection.js',
            $plugin_folder . '/assets/js/nmi-payment-monitor.js',
            $plugin_folder . '/assets/js/nmi-collectjs-trace.js',
            $plugin_folder . '/assets/js/address-autocomplete.js',
        ];

        $missing_rules = [];
        foreach ( $script_paths as $script_path ) {
            $rule = 'RewriteRule ^' . $script_path . '(.*) - [L]';
            if ( false === strpos( $contents, $rule ) ) {
                $missing_rules[] = $rule;
            }
        }

        // Already whitelisted (e.g. this ran once before, or someone added
        // the lines manually) — nothing to add.
        if ( empty( $missing_rules ) ) {
            return;
        }

        $updated = str_replace(
            self::END_MARKER,
            implode( "\n", $missing_rules ) . "\n" . self::END_MARKER,
            $contents
        );

        // Belt-and-braces: only write if the replace actually changed
        // something we can account for.
        if ( $updated === $contents ) {
            return;
        }

        self::write( $htaccess_path, $updated );
    }

    /**
     * Write via WP_Filesystem so this works whether the host uses direct
     * filesystem access or requires WordPress's FTP fallback — a raw
     * file_put_contents() can silently fail under stricter hosting
     * permission setups that WP_Filesystem already knows how to handle.
     *
     * @param string $path    Absolute file path.
     * @param string $content New file contents.
     * @return void
     */
    private static function write( string $path, string $content ): void
    {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;

        // Only proceeds without prompting when the host supports direct
        // filesystem access (the common case); otherwise it needs
        // credentials we don't have here, and we skip silently rather than
        // interrupt activation with a credentials form.
        if ( ! WP_Filesystem() || ! $wp_filesystem ) {
            return;
        }

        if ( ! $wp_filesystem->is_writable( $path ) ) {
            return;
        }

        $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
    }
}
