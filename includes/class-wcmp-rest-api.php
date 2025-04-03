<?php
if (!defined('ABSPATH')) exit;

class WCMP_REST_API {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('mpesa/v1', '/callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_callback'],
            'permission_callback' => [$this, 'verify_ip']
        ]);
    }

    public function verify_ip() {
        $safaricom_ips = ['196.201.214.200', '196.201.214.206'];
        $client_ip = $_SERVER['REMOTE_ADDR'];
        return in_array($client_ip, $safaricom_ips) || defined('WCMP_SKIP_IP_CHECK');
    }

    public function handle_callback(WP_REST_Request $request) {
        require_once dirname(__DIR__) . '/mpesa-callback.php';
        return wcmp_process_callback();
    }
}