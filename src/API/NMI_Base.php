<?php
/**
 * Base class for all NMI API-related classes: request signing, endpoint
 * resolution, and the shared HTTP call to NMI.
 *
 * @package APNMIPaymentGateway
 */

namespace APNMIPaymentGateway\API;

use APNMIPaymentGateway\Logger;
use APNMIPaymentGateway\Single_Instance_Trait;
use WP_Error;

class NMI_Base
{
    use Single_Instance_Trait;

    public const API_URL = 'https://secure.nmi.com/api/transact.php';
    public const REQUEST_TIMEOUT = 45;

    protected string $public_key = '';
    protected string $private_key = '';
    protected bool $test_mode = false;
    protected Logger $logger;
    protected ?array $last_response = null;

    public function __construct(array $config = [])
    {
        $this->logger = Logger::get_instance();

        if (!empty($config)) {
            $this->configure($config);
        }
    }

    protected function is_pathfinder_enabled(): bool
    {
        $settings = get_option('woocommerce_' . AP_NMI_WC_GATEWAY_ID . '_settings', []);
        $enabled = \APNMIPaymentGateway\Settings\Environment::is_sandbox();

        $this->logger->debug('Pathfinder sandbox check', [
            'pathfinder_sandbox_setting' => isset($settings['pathfinder_sandbox']) ? $settings['pathfinder_sandbox'] : 'NOT SET',
            'is_enabled' => $enabled ? 'YES' : 'NO',
        ]);

        return $enabled;
    }

    /**
     * The regular private key, which holds the Pathfinder key when Pathfinder mode is on.
     */
    protected function get_effective_private_key(array $config): string
    {
        if ($this->is_pathfinder_enabled()) {
            $this->logger->info('Pathfinder mode enabled - using private key from settings', [
                'has_private_key' => !empty($config['private_key']),
            ]);
        } else {
            $this->logger->debug('Gateway mode - using private key from settings');
        }

        return $config['private_key'] ?? '';
    }

    public function configure(array $config): void
    {
        // CollectJS always uses the Gateway public key, not the Pathfinder key.
        $this->public_key = $config['public_key'] ?? '';

        // The API uses the Pathfinder private key when enabled, otherwise the Gateway private key.
        $this->private_key = $this->get_effective_private_key($config);
        $this->test_mode = $config['test_mode'] ?? false;

        $is_pathfinder = $this->is_pathfinder_enabled();

        $this->logger->info('NMI API: Client configured', [
            'test_mode' => $this->test_mode,
            'pathfinder_mode' => $is_pathfinder ? 'YES' : 'NO',
            'has_public_key' => !empty($this->public_key),
            'has_private_key' => !empty($this->private_key),
            'mode_type' => $is_pathfinder ? 'PATHFINDER SANDBOX' : ($this->test_mode ? 'GATEWAY TEST' : 'GATEWAY LIVE'),
            'endpoint' => $this->get_api_endpoint(),
        ]);

        if (empty($this->private_key)) {
            $mode_label = $is_pathfinder ? 'Pathfinder' : ($this->test_mode ? 'test' : 'live');
            $this->logger->error("NMI API: Missing {$mode_label} private key for {$mode_label} mode");
        }
    }

    /**
     * Priority: Pathfinder sandbox > premium-plugin filter override > default production endpoint.
     */
    protected function get_api_endpoint(): string
    {
        if ($this->is_pathfinder_enabled()) {
            $pathfinder_url = 'https://sandbox.nmi.com/api/transact.php';

            $this->logger->info('Using Pathfinder sandbox endpoint', [
                'endpoint' => $pathfinder_url,
            ]);

            return $pathfinder_url;
        }

        $base_url = self::API_URL;

        // Allow premium plugins to override the endpoint (e.g. other custom environments).
        $base_url = apply_filters('gaincommerce_nmi_api_endpoint', $base_url, $this->test_mode);

        return $base_url;
    }

    public function make_post_request(array $data, int $retry_count = 0)
    {
        $validation_error = $this->validate_request_data($data);
        if ($validation_error) {
            return $validation_error;
        }

        // Pass the shopper's IP so NMI doesn't log this web server's IP for every
        // transaction. Only for customer-initiated types; WC_Geolocation handles
        // proxies/CDNs (Cloudflare, X-Forwarded-For).
        if (empty($data['ipaddress']) && in_array($data['type'] ?? '', ['sale', 'auth', 'validate'], true) && class_exists('WC_Geolocation')) {
            $customer_ip = \WC_Geolocation::get_ip_address();
            if (!empty($customer_ip) && filter_var($customer_ip, FILTER_VALIDATE_IP)) {
                $data['ipaddress'] = $customer_ip;
            }
        }

        $data = apply_filters('gaincommerce_nmi_api_request_data', $data);

        $endpoint = $this->get_api_endpoint();

        $data['security_key'] = $this->private_key;

        $this->logger->info('NMI API: Making request', [
            'endpoint' => $endpoint,
            'type' => $data['type'] ?? 'unknown',
            'retry_count' => $retry_count,
            'test_mode' => $this->test_mode,
        ]);

        $args = [
            'method'      => 'POST',
            'timeout'     => self::REQUEST_TIMEOUT,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent'   => $this->get_user_agent(),
            ],
            'body'        => http_build_query($data),
            'cookies'     => [],
            'sslverify'   => true,
        ];

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            $this->logger->error('NMI API: Request failed', [
                'error' => $response->get_error_message(),
                'retry_count' => $retry_count,
            ]);

            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            $error_message = sprintf('HTTP %d: %s', $response_code, wp_remote_retrieve_response_message($response));

            $this->logger->error('NMI API: HTTP error', [
                'response_code' => $response_code,
                'response_body' => $response_body,
            ]);

            return new WP_Error('nmi_http_error', $error_message, [
                'response_code' => $response_code,
                'response_body' => $response_body,
            ]);
        }

        $parsed_response = $this->parse_response($response_body);
        $this->last_response = $parsed_response;

        $this->logger->info('NMI API: Request completed', [
            'response_code' => $response_code,
            'transaction_id' => $parsed_response['transactionid'] ?? 'N/A',
            'response_status' => $parsed_response['response'] ?? 'unknown',
        ]);

        return $parsed_response;
    }

    public function validate_request_data(array $data): ?WP_Error
    {
        if (empty($this->private_key)) {
            $mode_label = $this->test_mode ? 'test' : 'live';
            return new WP_Error(
                'nmi_auth_error',
                "Private key is required for {$mode_label} mode API requests"
            );
        }

        if (empty($data['type'])) {
            return new WP_Error('nmi_validation_error', 'Transaction type is required');
        }

        $valid_types = ['sale', 'auth', 'capture', 'void', 'refund', 'credit', 'validate', 'generic'];
        if (!in_array($data['type'], $valid_types, true)) {
            return new WP_Error('nmi_validation_error', 'Invalid transaction type: ' . $data['type']);
        }

        return null;
    }

    public function validate_keys_for_mode(): bool
    {
        $mode_label = $this->test_mode ? 'test' : 'live';

        if (empty($this->private_key)) {
            $this->logger->error("NMI API: Missing {$mode_label} private key for {$mode_label} mode");
            return false;
        }

        $this->logger->debug("NMI API: Keys validated for {$mode_label} mode", [
            'mode' => $mode_label,
            'has_private_key' => !empty($this->private_key),
            'has_public_key' => !empty($this->public_key),
        ]);

        return true;
    }

    protected function parse_response(string $response_body): array
    {
        $parsed = [];
        parse_str($response_body, $parsed);

        $log_data = $parsed;
        unset($log_data['ccnumber'], $log_data['cvv']);

        $this->logger->debug('NMI API: Response parsed', $log_data);

        return $parsed;
    }

    protected function get_user_agent(): string
    {
        global $wp_version;

        $plugin_version = defined('AP_NMI_PAYMENT_GATEWAY_VERSION') ? AP_NMI_PAYMENT_GATEWAY_VERSION : '1.0.0';

        return sprintf(
            'WordPress/%s WooCommerce/%s AP-NMI-Gateway/%s (PHP/%s)',
            $wp_version,
            defined('WC_VERSION') ? WC_VERSION : 'unknown',
            $plugin_version,
            PHP_VERSION
        );
    }

    public function get_last_response(): ?array
    {
        return $this->last_response;
    }

    public function is_last_response_successful(): bool
    {
        return isset($this->last_response['response']) && $this->last_response['response'] === '1';
    }

    public function get_last_error_message(): string
    {
        if (empty($this->last_response)) {
            return 'No response available';
        }

        return $this->last_response['responsetext'] ?? 'Unknown error occurred';
    }

    public function get_last_transaction_id(): ?string
    {
        return $this->last_response['transactionid'] ?? null;
    }

    protected function sanitize_for_logging(array $data): array
    {
        $sanitized = $data;
        $sensitive_fields = [
            'ccnumber', 'cvv', 'checkaccount', 'checkaba'
        ];

        foreach ($sensitive_fields as $field) {
            if (isset($sanitized[$field])) {
                $sanitized[$field] = str_repeat('*', strlen($sanitized[$field]));
            }
        }

        return $sanitized;
    }
}
