<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Manager - AJAX Handlers and License Generation
 * Part 2 of the split WPLM_Admin_Manager class
 */
class WPLM_Admin_Manager_AJAX {

    public function __construct() {
        add_action('wp_ajax_wplm_generate_key', [$this, 'ajax_generate_key']);
        add_action('wp_ajax_wplm_generate_api_key', [$this, 'ajax_generate_api_key']);
        add_action('wp_ajax_wplm_get_customer_details', [$this, 'ajax_get_customer_details']);
        add_action('wp_ajax_wplm_filter_activity_log', [$this, 'ajax_filter_activity_log']);
        add_action('wp_ajax_wplm_clear_activity_log', [$this, 'ajax_clear_activity_log']);
        add_action('wp_ajax_wplm_filter_subscriptions', [$this, 'ajax_filter_subscriptions']);
    }

    /**
     * AJAX handler for generating license keys.
     */
    public function ajax_generate_key() {
        check_ajax_referer('wplm_generate_key_nonce', 'nonce');

        if (!current_user_can('edit_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $post_id = absint($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error(['message' => __('Invalid post ID.', 'wp-license-manager')]);
        }

        $license_key = $this->generate_unique_license_key();
        if (is_wp_error($license_key)) {
            wp_send_json_error(['message' => $license_key->get_error_message()]);
        }

        // Update the post title with the license key
        $update_result = wp_update_post([
            'ID' => $post_id,
            'post_title' => $license_key,
            'post_name' => sanitize_title($license_key),
        ], true);

        if (is_wp_error($update_result)) {
            wp_send_json_error(['message' => __('Failed to update license key.', 'wp-license-manager')]);
        }

        // Update the license key meta
        update_post_meta($post_id, '_wplm_license_key', $license_key);

        wp_send_json_success([
            'license_key' => $license_key,
            'message' => __('License key generated successfully.', 'wp-license-manager')
        ]);
    }

    /**
     * Generate a unique license key.
     */
    private function generate_unique_license_key() {
        $max_attempts = 100;
        $attempt = 0;

        do {
            $license_key = $this->generate_license_key_string();
            $attempt++;

            // Check if this key already exists
            $existing_license = get_page_by_title($license_key, OBJECT, 'wplm_license');
            if (!$existing_license) {
                return $license_key;
            }
        } while ($attempt < $max_attempts);

        return new WP_Error('license_generation_failed', __('Failed to generate unique license key after maximum attempts.', 'wp-license-manager'));
    }

    /**
     * Generate a license key string.
     */
    private function generate_license_key_string() {
        $format = get_option('wplm_license_key_format', 'XXXX-XXXX-XXXX-XXXX-XXXX');
        
        $key = '';
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] === 'X') {
                $key .= chr(rand(65, 90)); // Generate uppercase letter A-Z
            } else {
                $key .= $format[$i]; // Keep the separator character
            }
        }
        
        return $key;
    }

    /**
     * AJAX handler for generating API keys.
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('wplm_generate_api_key_nonce', 'nonce');

        if (!current_user_can('manage_wplm_api_key')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        // Generate a new API key
        if (function_exists('random_bytes')) {
            $api_key = bin2hex(random_bytes(32)); // Generate a 64-character hex key
        } else {
            // Fallback for older PHP versions
            $api_key = md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
        }

        // Update the option
        $update_result = update_option('wplm_api_key', $api_key);

        if ($update_result) {
            wp_send_json_success([
                'api_key' => $api_key,
                'message' => __('API key generated successfully.', 'wp-license-manager')
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to generate API key.', 'wp-license-manager')]);
        }
    }

    /**
     * AJAX handler for getting customer details.
     */
    public function ajax_get_customer_details() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $customer_email = sanitize_email($_POST['customer_email']);
        if (empty($customer_email)) {
            wp_send_json_error(['message' => __('Customer email is required.', 'wp-license-manager')]);
        }

        // Get customer details from WPLM customers
        $customer_posts = get_posts([
            'post_type' => 'wplm_customer',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $customer_email,
            'posts_per_page' => 1,
        ]);

        $customer_data = [];
        if (!empty($customer_posts)) {
            $customer = $customer_posts[0];
            $customer_data = [
                'id' => $customer->ID,
                'name' => get_post_meta($customer->ID, '_wplm_customer_name', true),
                'email' => $customer_email,
                'total_licenses' => get_post_meta($customer->ID, '_wplm_total_licenses', true),
                'active_licenses' => get_post_meta($customer->ID, '_wplm_active_licenses', true),
                'first_license_date' => get_post_meta($customer->ID, '_wplm_first_license_date', true),
                'last_activity' => get_post_meta($customer->ID, '_wplm_last_activity', true),
            ];
        }

        // Also check WooCommerce customers
        if (function_exists('wc_get_customer_by_email')) {
            $wc_customer = wc_get_customer_by_email($customer_email);
            if ($wc_customer) {
                $customer_data['woocommerce_id'] = $wc_customer->get_id();
                $customer_data['woocommerce_name'] = $wc_customer->get_display_name();
                $customer_data['woocommerce_orders'] = $wc_customer->get_order_count();
                $customer_data['woocommerce_total_spent'] = $wc_customer->get_total_spent();
            }
        }

        wp_send_json_success(['customer' => $customer_data]);
    }

    /**
     * AJAX handler for filtering activity log.
     */
    public function ajax_filter_activity_log() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $filters = [];
        if (isset($_POST['activity_type'])) {
            $filters['activity_type'] = sanitize_text_field($_POST['activity_type']);
        }
        if (isset($_POST['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_POST['date_from']);
        }
        if (isset($_POST['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_POST['date_to']);
        }
        if (isset($_POST['search'])) {
            $filters['search'] = sanitize_text_field($_POST['search']);
        }

        $activity_logs = $this->get_filtered_activity_logs($filters);
        wp_send_json_success(['logs' => $activity_logs]);
    }

    /**
     * Get filtered activity logs.
     */
    private function get_filtered_activity_logs($filters = []) {
        $args = [
            'post_type' => 'wplm_activity_log',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        // Filter by activity type
        if (!empty($filters['activity_type'])) {
            $args['meta_query'][] = [
                'key' => '_wplm_activity_type',
                'value' => $filters['activity_type'],
                'compare' => '=',
            ];
        }

        // Filter by date range
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $date_query = [];
            if (!empty($filters['date_from'])) {
                $date_query['after'] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $date_query['before'] = $filters['date_to'];
            }
            $args['date_query'] = $date_query;
        }

        // Search functionality
        if (!empty($filters['search'])) {
            $args['s'] = $filters['search'];
        }

        $logs = get_posts($args);
        $formatted_logs = [];

        foreach ($logs as $log) {
            $formatted_logs[] = [
                'id' => $log->ID,
                'title' => $log->post_title,
                'content' => $log->post_content,
                'date' => get_the_date('Y-m-d H:i:s', $log->ID),
                'activity_type' => get_post_meta($log->ID, '_wplm_activity_type', true),
                'object_id' => get_post_meta($log->ID, '_wplm_object_id', true),
                'user_id' => get_post_meta($log->ID, '_wplm_user_id', true),
                'additional_data' => get_post_meta($log->ID, '_wplm_additional_data', true),
            ];
        }

        return $formatted_logs;
    }

    /**
     * AJAX handler for clearing activity log.
     */
    public function ajax_clear_activity_log() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $logs = get_posts([
            'post_type' => 'wplm_activity_log',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $deleted_count = 0;
        foreach ($logs as $log_id) {
            if (wp_delete_post($log_id, true)) {
                $deleted_count++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Activity log cleared successfully. %d entries deleted.', 'wp-license-manager'), $deleted_count),
            'deleted_count' => $deleted_count
        ]);
    }

    /**
     * AJAX handler for filtering subscriptions.
     */
    public function ajax_filter_subscriptions() {
        check_ajax_referer('wplm_filter_subscriptions', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $filters = isset($_POST['status']) ? ['status' => sanitize_text_field($_POST['status'])] : [];
        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        if (!empty($search_term)) {
            $filters['search'] = $search_term;
        }

        $subscriptions = $this->get_subscription_data($filters);
        wp_send_json_success(['subscriptions' => $subscriptions]);
    }

    /**
     * Helper to get subscription data with filters.
     */
    private function get_subscription_data($filters = []) {
        if (!class_exists('WCS_Subscription')) {
            return [];
        }

        $args = [
            'subscriptions_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        if (!empty($filters['status'])) {
            $args['subscription_status'] = sanitize_text_field($filters['status']);
        }

        $subscriptions = wcs_get_subscriptions($args);
        $formatted_subscriptions = [];

        foreach ($subscriptions as $subscription) {
            // Basic search filtering
            if (!empty($filters['search'])) {
                $search_term = strtolower(sanitize_text_field($filters['search']));
                $customer = new WC_Customer($subscription->get_customer_id());
                $product = $subscription->get_product();

                $match = false;
                if (stripos($subscription->get_id(), $search_term) !== false) $match = true;
                if (stripos($customer->get_billing_email(), $search_term) !== false) $match = true;
                if (stripos($customer->get_display_name(), $search_term) !== false) $match = true;
                if ($product && stripos($product->get_name(), $search_term) !== false) $match = true;

                if (!$match) continue;
            }

            $customer = new WC_Customer($subscription->get_customer_id());
            $product = $subscription->get_product();
            $product_name = $product ? $product->get_name() : __('N/A', 'wp-license-manager');

            $formatted_subscriptions[] = [
                'id' => $subscription->get_id(),
                'customer_name' => $customer->get_display_name(),
                'customer_edit_link' => get_edit_post_link($customer->get_id()),
                'product_name' => $product_name,
                'status_slug' => $subscription->get_status(),
                'status_text' => wc_get_order_status_name($subscription->get_status()),
                'start_date' => wc_format_datetime($subscription->get_date('start')),
                'next_payment_date' => $subscription->get_date('next_payment') ? wc_format_datetime($subscription->get_date('next_payment')) : __('N/A', 'wp-license-manager'),
                'can_cancel' => $subscription->can_be_cancelled(),
                'can_reactivate' => $subscription->can_be_reactivated(),
            ];
        }

        return $formatted_subscriptions;
    }
}