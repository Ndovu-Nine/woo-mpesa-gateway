<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once MPESA_WC_GATEWAY_PLUGIN_DIR . 'includes/class-wcmp-api.php';
require_once MPESA_WC_GATEWAY_PLUGIN_DIR . 'includes/class-wcmp-rest-api.php';

class WC_Gateway_MPesa extends WC_Payment_Gateway {
    public $id = 'mpesa';
    public $icon;
    public $has_fields = true;
    public $method_title;
    public $method_description;
    public $supports = ['products', 'refunds'];
    public string $consumer_key;
    public string $consumer_secret;
    public string $shortcode;
    public string $passkey;
    public string $sandbox;
    public string $debug;
    public string $instructions;
    protected $api;
    protected $rest_api;

    public function __construct() {
        $this->icon = apply_filters('woocommerce_mpesa_icon', plugins_url('../assets/images/mpesa.png', __FILE__));
        $this->method_title = __('M-Pesa', 'mpesa-wc-gateway');
        $this->method_description = __('Accept M-Pesa payments via Daraja API.', 'mpesa-wc-gateway');

        // Initialize REST API first
        $this->rest_api = new WCMP_REST_API();

        $this->init_form_fields();
        $this->init_settings();

        // Initialize properties from settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', '');
        $this->enabled = $this->get_option('enabled');
        $this->consumer_key = $this->get_option('consumer_key');
        $this->consumer_secret = $this->get_option('consumer_secret');
        $this->shortcode = $this->get_option('shortcode');
        $this->passkey = $this->get_option('passkey');
        $this->sandbox = $this->get_option('sandbox');
        $this->debug = $this->get_option('debug');

        // Initialize API client
        $this->api = new WCMP_API(
            $this->consumer_key,
            $this->consumer_secret,
            $this->shortcode,
            $this->passkey,
            $this->sandbox === 'yes'
        );

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
        add_filter('woocommerce_get_order_item_totals', [$this, 'order_item_totals'], 10, 2);
    }

    public function get_callback_url() {
        return home_url('/abz.php');
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'mpesa-wc-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable M-Pesa Payment', 'mpesa-wc-gateway'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'mpesa-wc-gateway'),
                'type' => 'text',
                'description' => __('Payment method title that customers see.', 'mpesa-wc-gateway'),
                'default' => __('M-Pesa', 'mpesa-wc-gateway'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'mpesa-wc-gateway'),
                'type' => 'textarea',
                'description' => __('Payment method description.', 'mpesa-wc-gateway'),
                'default' => __('Pay via M-Pesa: Enter your phone number to receive a payment request', 'mpesa-wc-gateway'),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => __('Instructions', 'mpesa-wc-gateway'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to thank you page and emails.', 'mpesa-wc-gateway'),
                'default' => __('Please check your phone to complete the M-Pesa payment.', 'mpesa-wc-gateway'),
                'desc_tip' => true,
            ],
            'consumer_key' => [
                'title' => __('Consumer Key', 'mpesa-wc-gateway'),
                'type' => 'text',
                'description' => __('Your M-Pesa Daraja API Consumer Key', 'mpesa-wc-gateway'),
                'default' => '',
            ],
            'consumer_secret' => [
                'title' => __('Consumer Secret', 'mpesa-wc-gateway'),
                'type' => 'password',
                'description' => __('Your M-Pesa Daraja API Consumer Secret', 'mpesa-wc-gateway'),
                'default' => '',
            ],
            'shortcode' => [
                'title' => __('Paybill/Till Number', 'mpesa-wc-gateway'),
                'type' => 'text',
                'description' => __('Your M-Pesa Paybill or Till Number', 'mpesa-wc-gateway'),
                'default' => '',
            ],
            'passkey' => [
                'title' => __('Passkey', 'mpesa-wc-gateway'),
                'type' => 'password',
                'description' => __('Your M-Pesa Daraja API Passkey', 'mpesa-wc-gateway'),
                'default' => '',
            ],
            'sandbox' => [
                'title' => __('Sandbox Mode', 'mpesa-wc-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Sandbox Mode', 'mpesa-wc-gateway'),
                'default' => 'no',
                'description' => __('Test payments using sandbox credentials', 'mpesa-wc-gateway'),
            ],
            'debug' => [
                'title' => __('Debug Log', 'mpesa-wc-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Logging', 'mpesa-wc-gateway'),
                'default' => 'no',
                'description' => __('Log M-Pesa API events, such as API requests and responses. The log can be found in your server\'s error log file.', 'mpesa-wc-gateway'),
            ],
            'callback_url' => [
                'title' => __('Callback URL', 'mpesa-wc-gateway'),
                'type' => 'text',
                'description' => __('Copy this URL to your Daraja API settings', 'mpesa-wc-gateway'),
                'default' => $this->get_callback_url(),
                'custom_attributes' => [
                    'readonly' => 'readonly',
                    'onclick' => 'this.select()'
                ],
            ]
        ];
    }

    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        $phone_value = '';
        if (is_user_logged_in()) {
            $phone_value = get_user_meta(get_current_user_id(), 'billing_phone', true);
            if (empty($phone_value)) {
                $current_user = wp_get_current_user();
                $phone_value = $current_user->billing_phone ?? '';
            }
        }

        woocommerce_form_field('wcmp_phone', [
            'type' => 'tel',
            'label' => __('M-Pesa Phone Number', 'mpesa-wc-gateway'),
            'placeholder' => __('2547XXXXXXXX', 'mpesa-wc-gateway'),
            'required' => true,
            'class' => ['form-row-wide'],
            'input_class' => ['input-text'],
            'default' => $phone_value,
            'custom_attributes' => [
                'pattern' => '^254[17][0-9]{8}$',
                'title' => __('Enter Kenyan number starting with 254', 'mpesa-wc-gateway'),
            ],
        ]);
    }

    public function validate_fields() {
        if (empty($_POST['wcmp_phone'])) {
            wc_add_notice(__('Please enter your M-Pesa phone number', 'mpesa-wc-gateway'), 'error');
            return false;
        }

        $phone = sanitize_text_field($_POST['wcmp_phone']);
        if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
            wc_add_notice(__('Please enter a valid Kenyan phone number in format 2547XXXXXXXX', 'mpesa-wc-gateway'), 'error');
            return false;
        }

        return true;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $phone = sanitize_text_field($_POST['wcmp_phone']);

        $order->update_meta_data('_wcmp_phone', $phone);
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'billing_phone', $phone);
        }

        $stk_push_response = $this->initiate_stk_push($order, $phone);

        if ($stk_push_response['status']) {
            $order->update_status('pending', __('Awaiting M-Pesa payment', 'mpesa-wc-gateway'));
            $order->update_meta_data('_wcmp_request_id', $stk_push_response['request_id']);
            $order->update_meta_data('_wcmp_payment_initiated', time());
            $order->save();

            return [
                'result' => 'success',
                'redirect' => add_query_arg([
                    'wcmp_wait_payment' => 'true',
                    'order_id' => $order_id,
                    'order_key' => $order->get_order_key(),
                ], wc_get_checkout_url()),
            ];
        } else {
            $order->update_status('failed', __('M-Pesa payment initiation failed: ', 'mpesa-wc-gateway') . $stk_push_response['message']);
            wc_add_notice(__('Payment error: ', 'mpesa-wc-gateway') . $stk_push_response['message'], 'error');
            return false;
        }
    }

    private function initiate_stk_push($order, $phone) {
        return $this->api->stk_push(
            $phone,
            $order->get_total(),
            $order->get_id(),
            $this->get_callback_url()
        );
    }

    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);

        if ($this->instructions) {
            echo wpautop(wptexturize($this->instructions));
        }

        $receipt = $order->get_meta('_wcmp_receipt');
        if ($receipt) {
            echo '<p>' . __('M-Pesa Receipt Number: ', 'mpesa-wc-gateway') . esc_html($receipt) . '</p>';
        }
    }

    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method()) {
            echo $plain_text ? wptexturize($this->instructions) : wpautop(wptexturize($this->instructions));

            $receipt = $order->get_meta('_wcmp_receipt');
            if ($receipt) {
                echo $plain_text
                    ? "\n" . __('M-Pesa Receipt Number: ', 'mpesa-wc-gateway') . esc_html($receipt) . "\n"
                    : '<p>' . __('M-Pesa Receipt Number: ', 'mpesa-wc-gateway') . esc_html($receipt) . '</p>';
            }
        }
    }

    public function order_item_totals($total_rows, $order) {
        if ($order->get_payment_method() === $this->id) {
            $receipt = $order->get_meta('_wcmp_receipt');
            $phone = $order->get_meta('_wcmp_phone');

            if ($receipt) {
                $total_rows['mpesa_receipt'] = [
                    'label' => __('M-Pesa Receipt:', 'mpesa-wc-gateway'),
                    'value' => $receipt,
                ];
            }

            if ($phone) {
                $total_rows['mpesa_phone'] = [
                    'label' => __('Paid From:', 'mpesa-wc-gateway'),
                    'value' => $phone,
                ];
            }
        }

        return $total_rows;
    }
}