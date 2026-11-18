<?php
/**
 * Bot/spam protection for checkout.
 *
 * Adds an optional, invisible human-verification step (Google reCAPTCHA v3 or
 * Cloudflare Turnstile — merchant's choice, one at a time) in front
 * of both the legacy and blocks checkout, to blunt automated "card spinning"
 * (many stolen card numbers attempted in quick succession for small amounts).
 *
 * Deliberately decoupled from the payment method's own data-passing mechanics
 * (which differ between legacy POST fields and the blocks Store API's
 * payment_method_data), since the blocks checkout's React component ships as a
 * pre-built webpack bundle this plugin's source tree has no build step for.
 * Instead, the browser verifies once (and periodically re-verifies) via a plain,
 * always-loaded script that posts the provider's token to a small AJAX endpoint
 * here; a successful verification is stamped on the shopper's WooCommerce
 * session. Gateway::validate_fields() (legacy) and Gateway::process_payment()
 * (both, but the only enforcement point blocks checkout ever reaches) simply
 * check whether that stamp is present and fresh.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway;

class Bot_Protection
{
    /**
     * AJAX action name, also used as the nonce action.
     */
    public const AJAX_ACTION = 'ap_nmi_bot_protection_verify';

    /**
     * WC session key a successful verification is stamped under.
     */
    private const SESSION_KEY = 'ap_nmi_bot_protection_verified_at';

    /**
     * How long a verification stays valid. The frontend script re-verifies well
     * inside this window (see assets/js/bot-protection.js) so a shopper who
     * lingers on checkout doesn't get silently blocked at Place Order.
     */
    private const VERIFICATION_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Provider slug => the API endpoint that verifies a token server-side.
     */
    private const VERIFY_URLS = [
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side verify endpoint called via wp_remote_post() below, not a front-end asset load. Each provider's siteverify API only exists at its own domain; there is no self-hostable alternative.
        'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side verify endpoint called via wp_remote_post() below, not a front-end asset load. Each provider's siteverify API only exists at its own domain; there is no self-hostable alternative.
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ];

    /**
     * Provider slug => the client-side SDK script to load.
     */
    private const SDK_URLS = [
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Third-party fraud-prevention SDK that must be loaded live from the vendor's own domain to issue fresh, unreplayable challenges/scores; self-hosting is unsupported by the vendor and would break verification.
        // render=SITE_KEY makes v3 auto-load ready for grecaptcha.execute().
        'recaptcha' => 'https://www.google.com/recaptcha/api.js?render=',
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Third-party fraud-prevention SDK that must be loaded live from the vendor's own domain to issue fresh, unreplayable challenges/scores; self-hosting is unsupported by the vendor and would break verification.
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
    ];

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_scripts']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle_verify']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [self::class, 'handle_verify']);
    }

    /**
     * @return array Raw gateway settings option.
     */
    private static function settings(): array
    {
        $settings = get_option(AP_NMI_WC_GATEWAY_SETTINGS_ID, []);
        return is_array($settings) ? $settings : [];
    }

    /**
     * @return string One of 'none', 'recaptcha', 'turnstile'.
     */
    public static function get_provider(): string
    {
        $provider = self::settings()['bot_protection_provider'] ?? 'none';
        return in_array($provider, ['recaptcha', 'turnstile'], true) ? $provider : 'none';
    }

    /**
     * @param string $provider
     * @return array{0:string,1:string} [site_key, secret_key]
     */
    private static function get_keys(string $provider): array
    {
        $settings = self::settings();
        return [
            (string) ($settings[$provider . '_site_key'] ?? ''),
            (string) ($settings[$provider . '_secret_key'] ?? ''),
        ];
    }

    /**
     * Bot protection is only active when a provider is selected AND both its
     * keys are actually filled in — an incomplete configuration behaves as if
     * disabled rather than locking out every checkout.
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        $provider = self::get_provider();
        if ('none' === $provider) {
            return false;
        }

        [$site_key, $secret_key] = self::get_keys($provider);
        return '' !== $site_key && '' !== $secret_key;
    }

    /**
     * Enqueue the provider SDK plus the small glue script that fetches a token
     * and posts it to handle_verify(). Only on cart/checkout, only when enabled.
     *
     * @return void
     */
    public static function enqueue_scripts(): void
    {
        if (!self::is_enabled()) {
            return;
        }

        if (!function_exists('is_checkout') || (!is_checkout() && !is_cart())) {
            return;
        }

        // is_checkout() is also true on the order-received (thank-you) endpoint, where no
        // card is submitted. Skip it so customers never see the challenge there.
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return;
        }

        $provider = self::get_provider();
        [$site_key] = self::get_keys($provider);

        $sdk_url = self::SDK_URLS[$provider];
        if ('recaptcha' === $provider) {
            $sdk_url .= rawurlencode($site_key);
        }

        // Version is null on purpose: Cloudflare Turnstile warns about the unknown "?ver=" parameter WordPress would append.
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party SDK served from the provider's own CDN; a ?ver= suffix makes Turnstile log an "Unknown parameter" warning, and the provider versions the file itself.
        wp_register_script('ap-nmi-bot-protection-sdk', $sdk_url, [], null, true);
        wp_enqueue_script('ap-nmi-bot-protection-sdk');

        wp_register_script(
            'ap-nmi-bot-protection',
            apnmi_get_plugin_dir_url() . 'assets/js/bot-protection.js',
            ['ap-nmi-bot-protection-sdk'],
            AP_NMI_PAYMENT_GATEWAY_VERSION,
            true
        );
        wp_localize_script('ap-nmi-bot-protection', 'apNmiBotProtection', [
            'provider' => $provider,
            'siteKey'  => $site_key,
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'action'   => self::AJAX_ACTION,
            'nonce'    => wp_create_nonce('ap_nmi_nonce'),
        ]);
        wp_enqueue_script('ap-nmi-bot-protection');
    }

    /**
     * AJAX handler: verify a provider token and, on success, stamp the
     * shopper's WC session so validate_fields()/process_payment() can trust it.
     *
     * @return void
     */
    public static function handle_verify(): void
    {
        check_ajax_referer('ap_nmi_nonce', 'nonce');

        if (!self::is_enabled()) {
            wp_send_json_error(['message' => 'Bot protection is not enabled.'], 403);
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if ('' === $token) {
            wp_send_json_error(['message' => 'Missing token.'], 400);
        }

        if (!self::verify($token)) {
            wp_send_json_error(['message' => 'Verification failed.'], 403);
        }

        self::mark_verified();
        wp_send_json_success(['verified' => true]);
    }

    /**
     * Verify a token against the active provider's siteverify endpoint.
     *
     * @param string $token
     * @return bool
     */
    public static function verify(string $token): bool
    {
        $provider = self::get_provider();
        [, $secret_key] = self::get_keys($provider);

        if ('none' === $provider || '' === $secret_key || '' === $token) {
            return false;
        }

        $response = wp_remote_post(self::VERIFY_URLS[$provider], [
            'timeout' => 10,
            'body'    => [
                'secret'   => $secret_key,
                'response' => $token,
                'remoteip' => self::get_client_ip(),
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success'])) {
            return false;
        }

        // reCAPTCHA v3 is the only one of the three that returns a graded score
        // (0.0 bot .. 1.0 human) rather than a plain pass/fail.
        if ('recaptcha' === $provider) {
            $settings  = self::settings();
            $min_score = isset($settings['recaptcha_min_score']) && '' !== $settings['recaptcha_min_score']
                ? (float) $settings['recaptcha_min_score']
                : 0.5;
            $score = isset($body['score']) ? (float) $body['score'] : 0.0;
            if ($score < $min_score) {
                return false;
            }
        }

        return true;
    }

    /**
     * Best-effort client IP, sent onward to the provider's own verify endpoint
     * only — never persisted.
     *
     * @return string
     */
    private static function get_client_ip(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only, forwarded as-is to the provider's verify endpoint; never stored or output.
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    /**
     * Stamp a successful verification on the shopper's WC session.
     *
     * @return void
     */
    private static function mark_verified(): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_KEY, time());
        }
    }

    /**
     * Whether the current shopper has a fresh, verified human check on file.
     * Returns true when bot protection is disabled — nothing to enforce.
     *
     * @return bool
     */
    public static function is_session_verified(): bool
    {
        if (!self::is_enabled()) {
            return true;
        }

        if (!function_exists('WC') || !WC()->session) {
            return false;
        }

        $verified_at = (int) WC()->session->get(self::SESSION_KEY, 0);
        return $verified_at > 0 && (time() - $verified_at) <= self::VERIFICATION_TTL;
    }
}
