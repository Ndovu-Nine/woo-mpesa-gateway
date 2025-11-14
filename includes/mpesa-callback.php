<?php
if (!defined('ABSPATH')) {
    exit;
}

function wcmp_process_callback()
{
    $payload = file_get_contents('php://input');
    mpesa_write_log('WCMP Callback Received: ' . $payload);

    try {
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON payload');
        }

        if (
            empty($data['Body']['stkCallback']['CheckoutRequestID']) ||
            empty($data['Body']['stkCallback']['ResultCode'])
        ) {
            throw new Exception('Invalid callback parameters');
        }

        $request_id = sanitize_text_field($data['Body']['stkCallback']['CheckoutRequestID']);
        $result_code = sanitize_text_field($data['Body']['stkCallback']['ResultCode']);

        $orders = [];
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            // HPOS is enabled
            $orders = wc_get_orders(['_wcmp_request_id' => $request_id]);
        } else {
            // Traditional post store
            $orders = wc_get_orders([
                'meta_key' => '_wcmp_request_id', 'meta_value' => $request_id, 'limit' => 1
            ]);
        }

        if (empty($orders)) {
            throw new Exception('Order not found for request ID: ' . $request_id);
        }

        $order_id = $orders[0];
        $order = wc_get_order($order_id);

        if (!$order) {
            throw new Exception('Invalid order ID: ' . $order_id);
        }

        if ($order->is_paid()) {
            header('Content-Type: application/json');
            echo json_encode([
                'ResultCode' => 0,
                'ResultDesc' => 'Already processed'
            ]);
            exit;
        }

        if ($result_code === '0') {
            $order->payment_complete();
            update_post_meta($order_id, '_wcmp_payment_verified', 'yes');
            update_post_meta($order_id, '_wcmp_callback_received', time());
            update_post_meta($order_id, '_wcmp_last_callback', wp_json_encode($data));

            $transaction_details = [];
            if (!empty($data['Body']['stkCallback']['CallbackMetadata']['Item'])) {
                foreach ($data['Body']['stkCallback']['CallbackMetadata']['Item'] as $item) {
                    switch ($item['Name']) {
                        case 'Amount':
                            $amount = sanitize_text_field($item['Value']);
                            $order->update_meta_data('_wcmp_amount', $amount);
                            $transaction_details['amount'] = $amount;
                            break;
                        case 'MpesaReceiptNumber':
                            $receipt = sanitize_text_field($item['Value']);
                            $order->update_meta_data('_wcmp_receipt', $receipt);
                            $order->set_transaction_id($receipt);
                            $transaction_details['receipt'] = $receipt;
                            break;
                        case 'PhoneNumber':
                            $phone = sanitize_text_field($item['Value']);
                            $order->update_meta_data('_wcmp_phone', $phone);
                            $transaction_details['phone'] = $phone;
                            break;
                        case 'TransactionDate':
                            $date = sanitize_text_field($item['Value']);
                            $order->update_meta_data('_wcmp_transaction_date', $date);
                            $transaction_details['date'] = $date;
                            break;
                    }
                }
            }

            $note = __('M-Pesa payment received', 'mpesa-wc-gateway');
            if (!empty($transaction_details)) {
                $note .= "\n" . implode("\n", array_map(
                    function ($v, $k) {
                        return ucfirst($k) . ': ' . $v;
                    },
                    $transaction_details,
                    array_keys($transaction_details)
                ));
            }
            $order->add_order_note($note);
            $order->save();

            do_action('wcmp_payment_complete', $order_id, $transaction_details);

            header('Content-Type: application/json');
            echo json_encode([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted'
            ]);
            exit;

        } else {
            $error_message = $data['Body']['stkCallback']['ResultDesc'] ?? __('Payment failed', 'mpesa-wc-gateway');
            $sanitized_message = sanitize_text_field($error_message);

            $order->update_status('failed', $sanitized_message);
            update_post_meta($order_id, '_wcmp_payment_failed', 'yes');
            do_action('wcmp_payment_failed', $order_id, $sanitized_message);

            header('Content-Type: application/json');
            echo json_encode([
                'ResultCode' => 1,
                'ResultDesc' => $sanitized_message
            ]);
            exit;
        }
    } catch (Exception $e) {
        mpesa_write_log('WCMP Callback Error: ' . $e->getMessage());

        header('Content-Type: application/json');
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => $e->getMessage()
        ]);
        exit;
    }

    // Return REST response instead of exiting
    return new WP_REST_Response([
        'ResultCode' => 0,
        'ResultDesc' => 'Accepted'
    ], 200);
}

add_action('wp_ajax_wcmp_verify_payment', 'wcmp_verify_payment');
add_action('wp_ajax_nopriv_wcmp_verify_payment', 'wcmp_verify_payment');

function wcmp_verify_payment()
{
    try {
        if (!isset($_POST['order_id']) || !isset($_POST['order_key'])) {
            throw new Exception(__('Invalid request', 'mpesa-wc-gateway'));
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== sanitize_text_field($_POST['order_key'])) {
            throw new Exception(__('Invalid order', 'mpesa-wc-gateway'));
        }

        $callback_received = $order->get_meta('_wcmp_callback_received', true);
        $payment_initiated = $order->get_meta('_wcmp_payment_initiated', true);

        if ($callback_received) {
            wp_send_json_success([
                'status' => 'completed',
                'via' => 'callback',
                'redirect' => $order->get_checkout_order_received_url()
            ]);
        }

        if ($order->is_paid()) {
            wp_send_json_success([
                'status' => 'completed',
                'via' => 'database',
                'redirect' => $order->get_checkout_order_received_url()
            ]);
        }

        if (time() - $payment_initiated > 120) {
            wp_send_json_success([
                'status' => 'timeout',
                'message' => __('Payment verification timeout. Please check your M-Pesa messages.', 'mpesa-wc-gateway')
            ]);
        }

        wp_send_json_success([
            'status' => 'pending',
            'message' => __('Waiting for payment confirmation...', 'mpesa-wc-gateway'),
            'attempts' => absint($_POST['attempts'] ?? 0) + 1
        ]);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage()
        ]);
    }
}

add_action('wp_footer', 'wcmp_add_payment_verification_script');
function wcmp_add_payment_verification_script()
{
    if (!is_checkout() || !isset($_GET['wcmp_wait_payment']) || !isset($_GET['order_id']) || !isset($_GET['order_key'])) {
        return;
    }
    ?>
    <div id="wcmp-payment-processing"
        style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:999999;">
        <div class="wcmp-processing-overlay" style="background:rgba(0,0,0,0.7);position:absolute;width:100%;height:100%;">
        </div>
        <div class="wcmp-processing-container"
            style="background:#fff;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);padding:2rem;border-radius:5px;text-align:center;max-width:500px;width:90%;">
            <h3><?php esc_html_e('Processing your payment', 'mpesa-wc-gateway'); ?></h3>
            <p class="wcmp-status-message"><?php esc_html_e('Waiting for payment confirmation...', 'mpesa-wc-gateway'); ?>
            </p>
            <div class="wcmp-loader"
                style="border:5px solid #f3f3f3;border-top:5px solid #3498db;border-radius:50%;width:50px;height:50px;animation:wcmp-spin 1s linear infinite;margin:1rem auto;">
            </div>
            <p class="wcmp-help-text"><?php esc_html_e('Please complete the payment on your phone', 'mpesa-wc-gateway'); ?>
            </p>
            <p class="wcmp-timeout-text" style="display:none;color:#ff0000;"></p>
        </div>
    </div>
    <style>
        @keyframes wcmp-spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
    <script>
        jQuery(document).ready(function ($) {
            $('#wcmp-payment-processing').show();
            var statusEl = $('.wcmp-status-message');
            var loaderEl = $('.wcmp-loader');
            var timeoutEl = $('.wcmp-timeout-text');

            var config = {
                orderId: <?php echo absint($_GET['order_id']); ?>,
                orderKey: '<?php echo esc_js($_GET['order_key']); ?>',
                checkInterval: 3000,
                maxAttempts: 40,
                attempts: 0
            };

            function checkPayment() {
                $.ajax({
                    url: wc_checkout_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcmp_verify_payment',
                        order_id: config.orderId,
                        order_key: config.orderKey,
                        attempts: config.attempts
                    },
                    success: function (response) {
                        if (response.success) {
                            config.attempts++;

                            if (response.data.status === 'completed') {
                                statusEl.text('<?php esc_html_e('Payment successful! Redirecting...', 'mpesa-wc-gateway'); ?>');
                                loaderEl.css('border-top-color', '#4CAF50');
                                setTimeout(function () {
                                    window.location.href = response.data.redirect;
                                }, 1500);
                            }
                            else if (response.data.status === 'timeout') {
                                statusEl.text(response.data.message);
                                timeoutEl.text('<?php esc_html_e('You may check your M-Pesa messages and refresh this page.', 'mpesa-wc-gateway'); ?>').show();
                                loaderEl.hide();
                            }
                            else if (config.attempts < config.maxAttempts) {
                                setTimeout(checkPayment, config.checkInterval);
                            }
                            else {
                                statusEl.text('<?php esc_html_e('Payment confirmation taking longer than expected.', 'mpesa-wc-gateway'); ?>');
                                timeoutEl.text('<?php esc_html_e('Please check your M-Pesa messages and refresh this page.', 'mpesa-wc-gateway'); ?>').show();
                                loaderEl.hide();
                            }
                        }
                    },
                    error: function () {
                        if (config.attempts < config.maxAttempts) {
                            setTimeout(checkPayment, config.checkInterval * 1.5);
                        }
                    }
                });
            }

            checkPayment();
        });
    </script>
    <?php
}