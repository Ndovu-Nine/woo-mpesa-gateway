<?php
/**
 * Plugin Name: WooCommerce M-Pesa Payment Gateway
 * Plugin URI: https://wppay.ke/
 * Description: A WooCommerce payment gateway integration with M-Pesa Daraja API.
 * Version: 1.2.0
 * Author: Jeremiah Rotich
 * Author URI: https://jroti.ch/
 * License: GPL v2 or later
 * WC requires at least: 6.0
 * WC tested up to: 9.7.1
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

// Add this right after your plugin header
register_activation_hook(__FILE__, 'wcmp_flush_rewrite_rules');
function wcmp_flush_rewrite_rules() {
    // Ensure REST API routes are registered on activation
    require_once plugin_dir_path(__FILE__) . 'includes/class-wcmp-rest-api.php';
    new WCMP_REST_API();
    flush_rewrite_rules();
}

add_action('plugins_loaded', function() {
    if (class_exists('WC_Payment_Gateway')) {
        require_once __DIR__ . '/includes/class-wcmp-rest-api.php';
        require_once __DIR__ . '/includes/class-wcmp-api.php';
        require_once __DIR__ . '/includes/class-wc-gateway-mpesa.php';
        
        add_filter('woocommerce_payment_gateways', function($gateways) {
            $gateways[] = 'WC_Gateway_MPesa';
            return $gateways;
        });
    }
});

// Helper function for debug logging
function mpesa_write_log($log) {
    if (true === WP_DEBUG) {
        error_log(is_array($log) || is_object($log) ? print_r($log, true) : $log);
    }
}

// Check if WooCommerce is active
function mpesa_is_woocommerce_active(): bool {
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) ||
           (is_multisite() && array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins')));
}

if (!mpesa_is_woocommerce_active()) {
    add_action('admin_notices', 'mpesa_woocommerce_not_active_notice');
    return;
}

// Declare HPOS compatibility (currently incompatible)
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, false);
    }
});

define('MPESA_WC_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCMP_CALLBACK_ENDPOINT', 'wcmp-callback');
define('WCMP_VALIDATE_ENDPOINT', 'wcmp-validate');

// Initialize the plugin
add_action('plugins_loaded', 'mpesa_init_payment_gateway');

function mpesa_init_payment_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'mpesa_wc_payment_gateway_not_found_notice');
        return;
    }

    require_once MPESA_WC_GATEWAY_PLUGIN_DIR . 'includes/class-wc-gateway-mpesa.php';
    require_once MPESA_WC_GATEWAY_PLUGIN_DIR . 'includes/mpesa-callback.php';

    add_filter('woocommerce_payment_gateways', function($methods) {
        $methods[] = 'WC_Gateway_Mpesa';
        return $methods;
    });

    // Register endpoints
    add_action('init', function() {
        add_rewrite_endpoint(WCMP_CALLBACK_ENDPOINT, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(WCMP_VALIDATE_ENDPOINT, EP_ROOT);
    });

    // Callback handler
    add_action('woocommerce_api_' . WCMP_CALLBACK_ENDPOINT, function() {
        if (function_exists('wcmp_process_callback')) {
            wcmp_process_callback();
        }
    });

    // Validation handler (optional)
    add_action('woocommerce_api_' . WCMP_VALIDATE_ENDPOINT, function() {
        header('Content-Type: application/json');
        echo json_encode([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted'
        ]);
        exit;
    });

    // Enqueue scripts and styles
    add_action('wp_enqueue_scripts', 'wcmp_enqueue_assets');
}

function wcmp_enqueue_assets() {
    // Only load on checkout page
    if (is_checkout()) {
        // Custom CSS
        wp_enqueue_style(
            'wcmp-checkout',
            plugins_url('assets/css/mpesa-checkout.css', MPESA_WC_GATEWAY_PLUGIN_DIR),
            [],
            filemtime(MPESA_WC_GATEWAY_PLUGIN_DIR . 'assets/css/mpesa-checkout.css')
        );

        // Phone number validation
        wp_enqueue_script(
            'wcmp-checkout',
            plugins_url('assets/js/mpesa-checkout.js', MPESA_WC_GATEWAY_PLUGIN_DIR),
            ['jquery'],
            filemtime(MPESA_WC_GATEWAY_PLUGIN_DIR . 'assets/js/mpesa-checkout.js'),
            true
        );

        // Localize script for translations
        wp_localize_script('wcmp-checkout', 'wcmp_params', [
            'invalid_phone' => __('Please enter a valid Kenyan phone number in format 2547XXXXXXXX', 'mpesa-wc-gateway'),
            'default_prefix' => '254'
        ]);
    }
}

// Activation hooks
register_activation_hook(__FILE__, function() {
    if (!mpesa_is_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('WooCommerce M-Pesa requires WooCommerce to be active.', 'mpesa-wc-gateway'),
            __('Plugin Activation Error', 'mpesa-wc-gateway'),
            ['back_link' => true]
        );
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

// Admin notices
function mpesa_woocommerce_not_active_notice() {
    echo '<div class="notice notice-error"><p>'
        . sprintf(
            __('WooCommerce M-Pesa requires WooCommerce. %s', 'mpesa-wc-gateway'),
            '<a href="' . esc_url(admin_url('plugin-install.php?tab=search&s=woocommerce')) . '">'
            . __('Install WooCommerce now', 'mpesa-wc-gateway') . '</a>'
        )
        . '</p></div>';
}

function mpesa_wc_payment_gateway_not_found_notice() {
    echo '<div class="notice notice-error"><p>'
        . __('Required WooCommerce payment classes not found.', 'mpesa-wc-gateway')
        . '</p></div>';
}

add_action('admin_notices', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
        if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . sprintf(
                    __('M-Pesa Gateway works with traditional WooCommerce order storage. %sLearn more%s', 'mpesa-wc-gateway'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=advanced&section=features')) . '">',
                    '</a>'
                )
                . '</p></div>';
        }
    }
});

// Add settings link to plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mpesa') . '">' . __('Settings', 'mpesa-wc-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});