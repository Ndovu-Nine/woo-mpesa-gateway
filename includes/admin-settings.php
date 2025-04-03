<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

add_filter('woocommerce_get_settings_checkout', 'mpesa_wc_gateway_admin_settings', 10, 2);

function mpesa_wc_gateway_admin_settings($settings, $current_section) {
    if ($current_section === 'mpesa') {
        $settings = [
            [
                'title' => __('M-Pesa Settings', 'mpesa-wc-gateway'),
                'type'  => 'title',
                'desc'  => __('Configure M-Pesa payment gateway settings.', 'mpesa-wc-gateway')
            ],
            [
                'id'    => 'mpesa_consumer_key',
                'title' => __('Consumer Key', 'mpesa-wc-gateway'),
                'type'  => 'text',
                'desc'  => __('Your M-Pesa Daraja API Consumer Key.', 'mpesa-wc-gateway')
            ],
            [
                'id'    => 'mpesa_consumer_secret',
                'title' => __('Consumer Secret', 'mpesa-wc-gateway'),
                'type'  => 'password',
                'desc'  => __('Your M-Pesa Daraja API Consumer Secret.', 'mpesa-wc-gateway')
            ],
            [
                'id'    => 'mpesa_shortcode',
                'title' => __('Shortcode', 'mpesa-wc-gateway'),
                'type'  => 'text',
                'desc'  => __('Your M-Pesa Paybill or Till Number.', 'mpesa-wc-gateway')
            ],
            [
                'id'    => 'mpesa_passkey',
                'title' => __('Passkey', 'mpesa-wc-gateway'),
                'type'  => 'text',
                'desc'  => __('M-Pesa Lipa Na M-Pesa Online Passkey.', 'mpesa-wc-gateway')
            ],
            [
                'type'  => 'sectionend'
            ]
        ];
    }
    return $settings;
}
