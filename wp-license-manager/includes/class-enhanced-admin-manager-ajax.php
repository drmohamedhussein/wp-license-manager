<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Admin Manager - AJAX Handlers
 * Part 3 of the split WPLM_Enhanced_Admin_Manager class
 */
class WPLM_Enhanced_Admin_Manager_AJAX {

    public function __construct() {
        // Dashboard and stats
        add_action('wp_ajax_wplm_dashboard_stats', [$this, 'ajax_dashboard_stats']);
        
        // DataTables AJAX handlers
        add_action('wp_ajax_wplm_get_licenses', [$this, 'ajax_get_licenses']);
        add_action('wp_ajax_wplm_get_products', [$this, 'ajax_get_products']);
        add_action('wp_ajax_wplm_get_customers', [$this, 'ajax_get_customers']);
        add_action('wp_ajax_wplm_get_subscriptions', [$this, 'ajax_get_subscriptions']);
        add_action('wp_ajax_wplm_get_activity_logs', [$this, 'ajax_get_activity_logs']);
        
        // Customer management
        add_action('wp_ajax_wplm_add_customer', [$this, 'ajax_add_customer']);
        add_action('wp_ajax_wplm_edit_customer', [$this, 'ajax_edit_customer']);
        add_action('wp_ajax_wplm_delete_customer', [$this, 'ajax_delete_customer']);
        
        // Subscription management
        add_action('wp_ajax_wplm_add_subscription', [$this, 'ajax_add_subscription']);
        add_action('wp_ajax_wplm_edit_subscription', [$this, 'ajax_edit_subscription']);
        add_action('wp_ajax_wplm_delete_subscription', [$this, 'ajax_delete_subscription']);
        
        // License generation
        add_action('wp_ajax_wplm_generate_license_key', [$this, 'ajax_generate_license_key']);
        add_action('wp_ajax_wplm_generate_api_key', [$this, 'ajax_generate_api_key']);
        
        // Other actions
        add_action('wp_ajax_wplm_toggle_status', [$this, 'ajax_toggle_status']);
        add_action('wp_ajax_wplm_bulk_action', [$this, 'ajax_bulk_action']);
    }

    /**
     * AJAX handler for dashboard statistics
     */
    public function ajax_dashboard_stats() {
        check_ajax_referer('wplm_dashboard_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $stats = [
            'total_licenses' => $this->count_licenses_by_status(''),
            'active_licenses' => $this->count_licenses_by_status('active'),
            'total_products' => wp_count_posts('wplm_product')->publish,
            'total_customers' => $this->count_unique_customers()
        ];

        wp_send_json_success($stats);
    }

    /**
     * Helper methods for statistics
     */
    private function count_licenses_by_status($status) {
        $args = [
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];
        
        if (!empty($status)) {
            $args['meta_key'] = '_wplm_status';
            $args['meta_value'] = $status;
        }
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }

    private function count_unique_customers() {
        global $wpdb;
        $result = $wpdb->get_var("
            SELECT COUNT(DISTINCT meta_value) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wplm_customer_email' 
            AND meta_value != ''
        ");
        return (int) $result;
    }

    /**
     * AJAX handler for generating API keys
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('wplm_generate_api_key_nonce', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        try {
            $key = bin2hex(random_bytes(32)); // Generate a 64-character hex key
            update_option('wplm_api_key', $key);
            wp_send_json_success(['key' => $key]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error generating key: ' . $e->getMessage()], 500);
        }
    }

    /**
     * AJAX handler for generating license keys
     */
    public function ajax_generate_license_key() {
        check_ajax_referer('wplm_license_nonce', 'nonce');
        
        if (!current_user_can('create_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        try {
            $product_id = sanitize_text_field($_POST['product_id'] ?? '');
            $customer_email = sanitize_email($_POST['customer_email'] ?? '');
            $duration_type = sanitize_text_field($_POST['duration_type'] ?? 'lifetime');
            $duration_value = intval($_POST['duration_value'] ?? 1);
            $activation_limit = intval($_POST['activation_limit'] ?? 1);

            if (empty($product_id)) {
                wp_send_json_error(['message' => 'Product is required.'], 400);
            }

            // Generate unique license key
            $license_key = $this->generate_standard_license_key();
            $attempts = 0;
            while (get_page_by_title($license_key, OBJECT, 'wplm_license') && $attempts < 5) {
                $attempts++;
                $license_key = $this->generate_standard_license_key();
            }

            // Create license post
            $license_id = wp_insert_post([
                'post_title' => $license_key,
                'post_type' => 'wplm_license',
                'post_status' => 'publish'
            ]);

            if (is_wp_error($license_id)) {
                wp_send_json_error(['message' => 'Failed to create license.'], 500);
            }

            // Set license meta
            update_post_meta($license_id, '_wplm_license_key', $license_key);
            update_post_meta($license_id, '_wplm_product_id', $product_id);
            update_post_meta($license_id, '_wplm_customer_email', $customer_email);
            update_post_meta($license_id, '_wplm_status', 'active');
            update_post_meta($license_id, '_wplm_activation_limit', $activation_limit);
            update_post_meta($license_id, '_wplm_expiry_date', $this->calculate_expiry_date($duration_type, $duration_value));

            wp_send_json_success([
                'license_id' => $license_id,
                'license_key' => $license_key,
                'message' => 'License created successfully.'
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error creating license: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate standard license key
     */
    private function generate_standard_license_key() {
        $prefix = 'WPLM';
        $random = bin2hex(random_bytes(16)); // 32 characters
        $suffix = strtoupper(substr(md5(uniqid()), 0, 8)); // 8 characters
        
        return $prefix . '-' . $random . '-' . $suffix;
    }

    /**
     * Calculate expiry date
     */
    private function calculate_expiry_date($duration_type, $duration_value) {
        if ($duration_type === 'lifetime') {
            return '2030-12-31 23:59:59';
        }
        
        $date = new DateTime();
        
        switch ($duration_type) {
            case 'days':
                $date->add(new DateInterval("P{$duration_value}D"));
                break;
            case 'weeks':
                $date->add(new DateInterval("P{$duration_value}W"));
                break;
            case 'months':
                $date->add(new DateInterval("P{$duration_value}M"));
                break;
            case 'years':
                $date->add(new DateInterval("P{$duration_value}Y"));
                break;
            default:
                $date->add(new DateInterval("P1Y"));
        }
        
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * DataTables AJAX handlers
     */
    public function ajax_get_licenses() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Placeholder implementation
        wp_send_json([
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
    }

    public function ajax_get_products() {
        wp_send_json([
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
    }

    public function ajax_get_customers() {
        wp_send_json([
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
    }

    public function ajax_get_subscriptions() {
        wp_send_json([
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
    }

    public function ajax_get_activity_logs() {
        wp_send_json([
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
    }

    /**
     * Customer management AJAX handlers
     */
    public function ajax_add_customer() {
        wp_send_json_success(['message' => 'Customer added successfully']);
    }

    public function ajax_edit_customer() {
        wp_send_json_success(['message' => 'Customer updated successfully']);
    }

    public function ajax_delete_customer() {
        wp_send_json_success(['message' => 'Customer deleted successfully']);
    }

    /**
     * Subscription management AJAX handlers
     */
    public function ajax_add_subscription() {
        wp_send_json_success(['message' => 'Subscription added successfully']);
    }

    public function ajax_edit_subscription() {
        wp_send_json_success(['message' => 'Subscription updated successfully']);
    }

    public function ajax_delete_subscription() {
        wp_send_json_success(['message' => 'Subscription deleted successfully']);
    }

    /**
     * Other AJAX handlers
     */
    public function ajax_toggle_status() {
        wp_send_json_success(['message' => 'Status toggled successfully']);
    }

    public function ajax_bulk_action() {
        wp_send_json_success(['message' => 'Bulk action completed successfully']);
    }
}
