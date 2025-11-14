<?php

/**
 * Standalone M-Pesa Callback Handler for WooCommerce
 *
 * Place this file in the root directory of your WordPress installation.
 */

// Bootstrap the WordPress environment to access its functions.
require_once __DIR__ . '/wp-load.php';

// --- Helper Functions ---

/**
 * Sends a JSON response and terminates the script.
 * This is required by the Safaricom API.
 *
 * @param array $response The response data.
 * @param int   $status_code The HTTP status code.
 */
function send_json_response(array $response, int $status_code = 200) {
    // Ensure no other output is sent.
    if (!headers_sent()) {
        status_header($status_code);
        header('Content-Type: application/json');
    }
    echo json_encode($response);
    exit;
}

/**
 * A simple logger that uses error_log.
 *
 * @param string $message The message to log.
 */
function log_message($message) {
    // This will log to your server's standard error log file.
    error_log('M-Pesa Standalone Callback: ' . $message);
}

// --- Main Processing Logic ---

$payload = file_get_contents('php://input');
log_message('Received payload: ' . $payload);

try {
    if (empty($payload)) {
        throw new Exception('No payload received.');
    }

    $data = json_decode($payload, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload received.');
    }

    // Safaricom nests the actual data inside 'Body'.
    $stkCallback = $data['Body']['stkCallback'] ?? null;

    if (empty($stkCallback['CheckoutRequestID']) || !isset($stkCallback['ResultCode'])) {
        throw new Exception('Invalid callback parameters. CheckoutRequestID or ResultCode missing.');
    }

    $request_id = sanitize_text_field($stkCallback['CheckoutRequestID']);
    $result_code = sanitize_text_field($stkCallback['ResultCode']);

    // Find the WooCommerce order using the request ID stored in its meta data.
    $orders = wc_get_orders([
        'meta_key' => '_wcmp_request_id',
        'meta_value' => $request_id,
        'limit' => 1,
    ]);

    if (empty($orders)) {
        throw new Exception('Order not found for CheckoutRequestID: ' . $request_id);
    }

    $order = $orders[0];

    if (!$order) {
        throw new Exception('Could not retrieve a valid order object.');
    }

    // If the order is already paid, just acknowledge the callback and exit.
    if ($order->is_paid()) {
        log_message('Order #' . $order->get_id() . ' is already paid. Acknowledging callback.');
        send_json_response(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    // Check if the payment was successful.
    if ($result_code === '0') {
        log_message('Processing successful payment for Order #' . $order->get_id());

        // Mark the order as paid in WooCommerce.
        $order->payment_complete();

        // Extract and save transaction details from the callback metadata.
        if (!empty($stkCallback['CallbackMetadata']['Item'])) {
            foreach ($stkCallback['CallbackMetadata']['Item'] as $item) {
                if (isset($item['Name']) && isset($item['Value'])) {
                    switch ($item['Name']) {
                        case 'MpesaReceiptNumber':
                            $receipt = sanitize_text_field($item['Value']);
                            $order->set_transaction_id($receipt); // Set the official transaction ID.
                            $order->update_meta_data('_mpesa_receipt', $receipt);
                            break;
                    }
                }
            }
        }

        $order->add_order_note(__('M-Pesa payment successfully processed via STK Push.', 'mpesa-wc-gateway'));
        $order->save();

        // Send success response to Safaricom.
        send_json_response(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

    } else {
        // The payment failed or was cancelled.
        $error_message = $stkCallback['ResultDesc'] ?? __('Payment failed or was cancelled by the user.', 'mpesa-wc-gateway');
        $sanitized_message = sanitize_text_field($error_message);

        log_message('Processing failed payment for Order #' . $order->get_id() . '. Reason: ' . $sanitized_message);

        // Mark the order as failed and add a note.
        $order->update_status('failed', $sanitized_message);
        $order->save();

        // Acknowledge the callback.
        send_json_response(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

} catch (Exception $e) {
    log_message('Error: ' . $e->getMessage());

    // Respond with an error to Safaricom, but use a format it expects.
    send_json_response([
        'ResultCode' => 1, // Using 1 indicates a generic failure.
        'ResultDesc' => 'An error occurred on the server.'
    ], 500); // Use HTTP 500 for server errors.
}
