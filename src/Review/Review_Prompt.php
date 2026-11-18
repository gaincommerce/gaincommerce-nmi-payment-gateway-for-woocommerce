<?php
/**
 * Review prompts: milestone admin notice, settings-page box (with a two-step
 * satisfaction check) and a "setup complete" card.
 *
 * All three share one dismissal flag (`gain_nmi_review_dismissed`), so a
 * merchant who says "Already did" / "Dismiss forever" is never asked again
 * anywhere in the plugin UI.
 *
 * Safety notes:
 *  - Payment-side code (record_success) is wrapped in try/catch and can never
 *    interfere with a checkout.
 *  - Everything UI-related is limited to users with `manage_woocommerce` and
 *    to WooCommerce > Settings screens.
 *  - The AJAX endpoint is nonce + capability protected and only accepts a
 *    fixed whitelist of actions.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\Review;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Review_Prompt
{
    public const AJAX_ACTION = 'apnmi_review_action';
    public const NONCE       = 'apnmi_review_nonce';

    // Options (all stored with autoload = no).
    public const OPT_DISMISSED    = 'gain_nmi_review_dismissed';      // 'yes' once dismissed / already reviewed.
    public const OPT_SNOOZE_UNTIL = 'gain_nmi_review_snooze_until';   // Unix timestamp.
    public const OPT_INSTALLED_AT = 'gain_nmi_installed_at';          // Unix timestamp.
    public const OPT_ORDERS       = 'gain_nmi_success_orders';        // Live successful NMI orders.
    public const OPT_SETUP_STATE  = 'gain_nmi_setup_card_state';      // 'pending' | 'done'.

    public const META_COUNTED = '_apnmi_review_counted';

    public const MILESTONE_ORDERS = 30;
    public const MILESTONE_DAYS   = 30;
    public const SNOOZE_DAYS      = 7;

    private const ACTIONS = ['already_did', 'dismiss', 'later', 'dismiss_setup'];

    public static function init(): void
    {
        // Payment-side counter (fires after every successful NMI payment).
        add_action('apnmi_after_payment_complete', [self::class, 'record_success'], 20, 3);

        if ( ! is_admin() ) {
            return;
        }

        add_action('admin_init', [self::class, 'bootstrap']);
        add_action('admin_notices', [self::class, 'render_notice']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle_ajax']);

        // Priority 20 so it runs after WooCommerce's own footer text on its settings screens.
        add_filter('admin_footer_text', [self::class, 'footer_text'], 20);
    }

    /**
     * WooCommerce-style footer line, shown only on this plugin's settings screens
     * (the NMI Settings tab and the legacy gateway section) until the review prompts
     * are dismissed.
     *
     * @param mixed $text Existing footer text.
     * @return mixed
     */
    public static function footer_text($text)
    {
        if ( ! self::user_can_see() || self::is_dismissed() || ! self::is_wc_settings_screen() ) {
            return $text;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check, no data processed.
        $tab     = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        // phpcs:enable

        if ( 'nmi' !== $tab && ! ( 'checkout' === $tab && AP_NMI_WC_GATEWAY_ID === $section ) ) {
            return $text;
        }

        return sprintf(
            /* translators: 1: plugin name, 2: five-star link */
            esc_html__('If you like %1$s, please leave a %2$s rating. Thanks!', 'gaincommerce-nmi-payment-gateway-for-woocommerce'),
            '<strong>' . esc_html__('Gain Commerce\'s NMI Plugin', 'gaincommerce-nmi-payment-gateway-for-woocommerce') . '</strong>',
            '<a href="' . esc_url(self::review_url()) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__('five star', 'gaincommerce-nmi-payment-gateway-for-woocommerce') . '">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
        );
    }

    /* ------------------------------------------------------------------ *
     * URLs
     * ------------------------------------------------------------------ */

    public static function review_url(): string
    {
        return 'https://wordpress.org/support/plugin/gaincommerce-nmi-payment-gateway-for-woocommerce/reviews/?rate=5#new-post';
    }

    public static function support_url(): string
    {
        return (string) apply_filters(
            'apnmi_review_support_url',
            'https://www.gaincommerce.com/contact/'
        );
    }

    /* ------------------------------------------------------------------ *
     * Tracking
     * ------------------------------------------------------------------ */

    /**
     * One-time setup, run in wp-admin. Records the install date and, for
     * stores that already have NMI orders, back-fills the order counter so
     * long-time merchants aren't treated as brand new.
     */
    public static function bootstrap(): void
    {
        if ( false === get_option(self::OPT_INSTALLED_AT, false) ) {
            add_option(self::OPT_INSTALLED_AT, time(), '', 'no');
        }

        if ( false !== get_option(self::OPT_ORDERS, false) ) {
            return;
        }

        $count = 0;
        try {
            if ( function_exists('wc_get_orders') ) {
                $result = wc_get_orders([
                    'payment_method' => AP_NMI_WC_GATEWAY_ID,
                    'status'         => ['wc-processing', 'wc-completed'],
                    'limit'          => 1,
                    'paginate'       => true,
                    'return'         => 'ids',
                ]);
                $count = isset($result->total) ? (int) $result->total : 0;
            }
        } catch ( \Throwable $e ) {
            $count = 0;
        }

        add_option(self::OPT_ORDERS, $count, '', 'no');

        // Merchants that already process orders are past onboarding.
        if ( $count > 0 && false === get_option(self::OPT_SETUP_STATE, false) ) {
            add_option(self::OPT_SETUP_STATE, 'done', '', 'no');
        }
    }

    /**
     * Count a successful NMI payment (once per order). Never throws.
     *
     * @param mixed $order    WC_Order.
     * @param mixed $response Gateway response (unused).
     * @param mixed $config   Gateway config; `test_mode` marks test payments.
     */
    public static function record_success($order, $response = null, $config = null): void
    {
        try {
            if ( ! $order instanceof \WC_Order ) {
                return;
            }
            if ( 'yes' === $order->get_meta(self::META_COUNTED) ) {
                return;
            }
            $order->update_meta_data(self::META_COUNTED, 'yes');
            $order->save_meta_data();

            // First successful transaction of any kind (test or live) triggers the "setup complete" card once.
            if ( false === get_option(self::OPT_SETUP_STATE, false) ) {
                add_option(self::OPT_SETUP_STATE, 'pending', '', 'no');
            }

            $is_test = ( is_array($config) && ! empty($config['test_mode']) ) || self::is_sandbox();
            if ( $is_test ) {
                return; // Test payments never count towards review milestones.
            }

            update_option(self::OPT_ORDERS, (int) get_option(self::OPT_ORDERS, 0) + 1, false);
        } catch ( \Throwable $e ) {
            // Swallow: review tracking must never affect a payment.
            return;
        }
    }

    private static function is_sandbox(): bool
    {
        return \APNMIPaymentGateway\Settings\Environment::is_sandbox();
    }

    /* ------------------------------------------------------------------ *
     * State helpers
     * ------------------------------------------------------------------ */

    public static function is_dismissed(): bool
    {
        return 'yes' === get_option(self::OPT_DISMISSED, 'no');
    }

    private static function milestone_reached(): bool
    {
        $orders = (int) get_option(self::OPT_ORDERS, 0);
        if ( $orders >= self::MILESTONE_ORDERS ) {
            return true;
        }

        $installed = (int) get_option(self::OPT_INSTALLED_AT, 0);
        // The "30 days" trigger also requires at least one real sale, so we only ask merchants who've seen value.
        return $orders >= 1 && $installed > 0 && ( time() - $installed ) >= self::MILESTONE_DAYS * DAY_IN_SECONDS;
    }

    private static function is_wc_settings_screen(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no data processed.
        return isset($_GET['page']) && 'wc-settings' === sanitize_key(wp_unslash($_GET['page']));
    }

    private static function is_nmi_tab(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no data processed.
        return self::is_wc_settings_screen() && isset($_GET['tab']) && 'nmi' === sanitize_key(wp_unslash($_GET['tab']));
    }

    private static function user_can_see(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    /* ------------------------------------------------------------------ *
     * Assets
     * ------------------------------------------------------------------ */

    public static function enqueue_assets(): void
    {
        if ( ! self::is_wc_settings_screen() || ! self::user_can_see() || self::is_dismissed() ) {
            return;
        }

        wp_enqueue_style(
            'apnmi-review',
            AP_NMI_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/css/nmi-review.css',
            [],
            AP_NMI_PAYMENT_GATEWAY_VERSION
        );
        wp_enqueue_script(
            'apnmi-review',
            AP_NMI_PAYMENT_GATEWAY_PLUGIN_URL . 'assets/js/nmi-review.js',
            [],
            AP_NMI_PAYMENT_GATEWAY_VERSION,
            true
        );
        wp_localize_script('apnmi-review', 'apnmiReview', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::NONCE),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Rendering
     * ------------------------------------------------------------------ */

    /** Strategy 1: milestone admin notice (WooCommerce settings screens only). */
    public static function render_notice(): void
    {
        if ( ! self::is_wc_settings_screen() || ! self::user_can_see() || self::is_dismissed() ) {
            return;
        }
        if ( time() < (int) get_option(self::OPT_SNOOZE_UNTIL, 0) || ! self::milestone_reached() ) {
            return;
        }

        $orders = (int) get_option(self::OPT_ORDERS, 0);
        if ( $orders >= self::MILESTONE_ORDERS ) {
            /* translators: %d: number of orders */
            $headline = sprintf(__('You\'ve processed %d successful orders with Gain Commerce NMI!', 'gaincommerce-nmi-payment-gateway-for-woocommerce'), $orders);
        } else {
            $headline = __('You\'ve been using Gain Commerce NMI for over 30 days!', 'gaincommerce-nmi-payment-gateway-for-woocommerce');
        }
        ?>
        <div class="notice notice-info apnmi-review-notice" data-apnmi-review>
            <p><strong><?php echo esc_html($headline); ?></strong></p>
            <p><?php esc_html_e('If it\'s been working well for your store, a quick 5-star review on WordPress.org would mean a lot to our small team and helps other store owners find us.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></p>
            <p class="apnmi-review-actions">
                <a class="button button-primary" href="<?php echo esc_url(self::review_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Leave a Review ⭐⭐⭐⭐⭐', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></a>
                <button type="button" class="button" data-apnmi-do="already_did"><?php esc_html_e('Already Did', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
                <button type="button" class="button" data-apnmi-do="later"><?php esc_html_e('Maybe Later', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
                <button type="button" class="button-link" data-apnmi-do="dismiss"><?php esc_html_e('Dismiss Forever', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
            </p>
        </div>
        <?php
    }

    /** Strategy 5: shown once on the NMI tab after the first successful transaction. */
    public static function render_setup_card(): void
    {
        if ( ! self::user_can_see() || self::is_dismissed() || 'pending' !== get_option(self::OPT_SETUP_STATE, false) ) {
            return;
        }
        ?>
        <div class="apnmi-review-card apnmi-setup-card" data-apnmi-review>
            <p><strong><?php esc_html_e('Payment successful! Your store is ready to accept cards.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></strong></p>
            <p><?php esc_html_e('If this setup was smooth, please consider giving us a star rating on WordPress.org.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></p>
            <p class="apnmi-review-actions">
                <a class="button button-primary" href="<?php echo esc_url(self::review_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Rate on WordPress.org', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></a>
                <button type="button" class="button" data-apnmi-do="dismiss_setup"><?php esc_html_e('Close', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
            </p>
        </div>
        <?php
    }

    /** Strategies 3 + 4: permanent passive box on the NMI tab with a two-step satisfaction check. */
    public static function render_box(): void
    {
        if ( ! self::user_can_see() || self::is_dismissed() ) {
            return;
        }
        ?>
        <div class="apnmi-review-card apnmi-review-box" data-apnmi-review>
            <div class="apnmi-step" data-apnmi-step="question">
                <p><strong><?php esc_html_e('Are you enjoying the Gain Commerce NMI Gateway?', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></strong></p>
                <p class="apnmi-review-actions">
                    <button type="button" class="button button-primary" data-apnmi-show="yes"><?php esc_html_e('Yes!', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
                    <button type="button" class="button" data-apnmi-show="no"><?php esc_html_e('Not really', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
                </p>
            </div>

            <div class="apnmi-step" data-apnmi-step="yes" hidden>
                <p><?php esc_html_e('Great to hear! A quick review on WordPress.org helps other WooCommerce store owners find us.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></p>
                <p class="apnmi-review-actions">
                    <a class="button button-primary" href="<?php echo esc_url(self::review_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Leave a Review ⭐⭐⭐⭐⭐', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></a>
                    <button type="button" class="button" data-apnmi-do="already_did"><?php esc_html_e('I already did', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button>
                </p>
            </div>

            <div class="apnmi-step" data-apnmi-step="no" hidden>
                <p><?php esc_html_e('We\'re sorry! How can we make it better? Tell us what\'s not working and we\'ll help.', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></p>
                <p class="apnmi-review-actions">
                    <a class="button button-primary" href="<?php echo esc_url(self::support_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Send Feedback', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></a>
                </p>
            </div>

            <p class="apnmi-review-hide"><button type="button" class="button-link" data-apnmi-do="dismiss"><?php esc_html_e('Don\'t show this again', 'gaincommerce-nmi-payment-gateway-for-woocommerce'); ?></button></p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     * AJAX
     * ------------------------------------------------------------------ */

    public static function handle_ajax(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if ( ! self::user_can_see() ) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        if ( ! in_array($do, self::ACTIONS, true) ) {
            wp_send_json_error(['message' => 'invalid action'], 400);
        }

        switch ( $do ) {
            case 'later':
                update_option(self::OPT_SNOOZE_UNTIL, time() + self::SNOOZE_DAYS * DAY_IN_SECONDS, false);
                break;
            case 'dismiss_setup':
                update_option(self::OPT_SETUP_STATE, 'done', false);
                break;
            case 'already_did':
            case 'dismiss':
                update_option(self::OPT_DISMISSED, 'yes', false);
                update_option(self::OPT_SETUP_STATE, 'done', false);
                break;
        }

        wp_send_json_success();
    }
}
