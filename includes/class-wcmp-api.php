<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WCMP_API {
    private $consumer_key;
    private $consumer_secret;
    private $shortcode;
    private $passkey;
    private $base_url;
    private $stk_push_url;
    private $callback_url;

    public function __construct($consumer_key, $consumer_secret, $shortcode, $passkey, $is_sandbox = true) {
        $this->consumer_key = $consumer_key;
        $this->consumer_secret = $consumer_secret;
        $this->shortcode = $shortcode;
        $this->passkey = $passkey;
        $this->base_url = $is_sandbox ? 
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' : 
            'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $this->stk_push_url = $is_sandbox ? 
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' : 
            'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        $this->callback_url = home_url('/wc-api/wcmp-callback');
    }

    private function get_access_token() {
        $credentials = base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        $response = wp_remote_get($this->base_url, [
            'headers' => [
                'Authorization' => 'Basic ' . $credentials
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            mpesa_write_log('WCMP API Error (Get Token): ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['access_token'])) {
            mpesa_write_log('WCMP API Error (Get Token): Invalid response - ' . json_encode($body));
            return false;
        }
        
        return $body['access_token'];
    }

    public function stk_push($phone, $amount, $order_id) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return [
                'status' => false, 
                'message' => __('Failed to authenticate with M-Pesa API', 'mpesa-wc-gateway')
            ];
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $request_data = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => round($amount),
            'PartyA' => $phone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callback_url,
            'AccountReference' => 'ORDER-' . $order_id,
            'TransactionDesc' => __('Payment for Order #', 'mpesa-wc-gateway') . $order_id
        ];

        $response = wp_remote_post($this->stk_push_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($request_data),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            mpesa_write_log('WCMP API Error (STK Push): ' . $response->get_error_message());
            return [
                'status' => false, 
                'message' => __('Failed to initiate payment request', 'mpesa-wc-gateway')
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        mpesa_write_log('WCMP API Response: ' . json_encode($body));

        if (isset($body['ResponseCode']) && $body['ResponseCode'] === '0') {
            return [
                'status' => true,
                'message' => __('Payment request sent to your phone', 'mpesa-wc-gateway'),
                'request_id' => $body['CheckoutRequestID'],
                'response' => $body
            ];
        } else {
            $error_msg = $body['errorMessage'] ?? __('Payment request failed', 'mpesa-wc-gateway');
            return [
                'status' => false,
                'message' => $error_msg,
                'response' => $body
            ];
        }
    }
}