<?php

namespace APNMIPaymentGateway;
use APNMIPaymentGateway\Single_Instance_Trait;
use APNMIPaymentGateway\Gateway;
use WooCommerce\WC_Logger;
class Logger
{
    use Single_Instance_Trait;

    private const LOG_SOURCE = 'gaincommerce-nmi-gateway';

    private $_wc_logger = null;

    public function __construct()
    {
        if(function_exists('wc_get_logger')) {
            $this->_wc_logger = wc_get_logger();
        } else {
            $this->_wc_logger = new WC_Logger();
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log_transaction(string $type, array $data): void
    {
        $message = sprintf('Transaction %s: %s', $type, $data['transaction_id'] ?? 'N/A');
        $this->info($message, $data);
    }

    public function log_api_request(string $endpoint, array $request, array $response): void
    {
        $message = sprintf('API Request to %s', $endpoint);

        $context = [
            'endpoint' => $endpoint,
            'request' => $this->sanitized_data($request),
            'response' => $this->sanitized_data($response),
        ];

        $this->debug($message, $context);
    }

    public function log_webhook(string $event_type, array $payload): void
    {
        $message = sprintf('Webhook received: %s', $event_type);

        $context = [
            'event_type' => $event_type,
            'payload' => $this->sanitized_data($payload),
        ];

        $this->info($message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->is_logging_enabled()) {
            return;
        }

        if ($this->_wc_logger) {
            $formatted_message = $this->format_message($message, $context);
            $this->_wc_logger->log($level, $formatted_message, ['source' => self::LOG_SOURCE]);
        }
    }

    private function format_message(string $message, array $context): string
    {
        if (empty($context)) {
            return $message;
        }

        $formatted_context = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $formatted_context[] = $key . ': ' . wp_json_encode($value);
            } else {
                $formatted_context[] = $key . ': ' . $value;
            }
        }

        return $message . ' | ' . implode(' | ', $formatted_context);
    }

    /**
     * Redact sensitive fields before they reach the log file.
     */
    private function sanitized_data(array $data): array
    {
        $sensitive_fields = [
            'password',
            'security-key',
            'ccnumber',
            'ccexp',
            'cvv',
            'checkaba',
            'checkaccount',
            'checkname',
            'account_number',
            'routing_number',
        ];

        $sanitized = $data;

        foreach ($sensitive_fields as $field) {
            if (isset($sanitized[$field])) {
                if (in_array($field, ['ccnumber', 'account_number'], true)) {
                    $value = (string) $sanitized[$field];
                    $sanitized[$field] = '***' . substr($value, -4);
                } else {
                    $sanitized[$field] = '***';
                }
            }
        }

        return $sanitized;
    }

    private function is_logging_enabled(): bool
    {
        $cc_gateway = new Gateway();
        return $cc_gateway->get_option('logging') === 'yes';
    }

    public function get_log_file_path(): string
    {
        if ($this->_wc_logger && method_exists($this->_wc_logger, 'get_log_file_path')) {
            return $this->_wc_logger->get_log_file_path(self::LOG_SOURCE);
        }

        return '';
    }

    public function clear_logs(): bool
    {
        $log_path = $this->get_log_file_path();

        if (!empty($log_path) && file_exists($log_path)) {
            return wp_delete_file($log_path);
        }

        return false;
    }

}
