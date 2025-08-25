<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer Management System - Core Functionality
 * Part 1 of the split WPLM_Customer_Management_System class
 */
class WPLM_Customer_Management_System_Core {

    private $customer_post_type = 'wplm_customer';

    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Auto-create customers from license generation
        add_action('wplm_license_created', [$this, 'auto_create_customer_from_license'], 10, 2);
        
        // WooCommerce integration
        add_action('woocommerce_order_status_completed', [$this, 'sync_customer_from_order'], 15, 1);
        add_action('woocommerce_customer_save_address', [$this, 'sync_customer_address'], 10, 2);
        
        // Customer data aggregation (daily)
        add_action('wplm_aggregate_customer_data', [$this, 'aggregate_customer_data']);
        if (!wp_next_scheduled('wplm_aggregate_customer_data')) {
            wp_schedule_event(time(), 'daily', 'wplm_aggregate_customer_data');
        }
    }

    /**
     * Sync customer address from WooCommerce
     */
    public function sync_customer_address($user_id, $load_address) {
        $customer_email = get_user_meta($user_id, 'billing_email', true);
        if (empty($customer_email)) {
            return;
        }

        $customer_post = $this->get_customer_by_email($customer_email);
        if (!$customer_post) {
            return;
        }

        $address = [
            'address_1' => get_user_meta($user_id, 'billing_address_1', true),
            'address_2' => get_user_meta($user_id, 'billing_address_2', true),
            'city' => get_user_meta($user_id, 'billing_city', true),
            'state' => get_user_meta($user_id, 'billing_state', true),
            'postcode' => get_user_meta($user_id, 'billing_postcode', true),
            'country' => get_user_meta($user_id, 'billing_country', true)
        ];

        update_post_meta($customer_post->ID, '_wplm_address', $address);
        update_post_meta($customer_post->ID, '_wplm_last_activity', current_time('mysql'));

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $customer_post->ID,
                'customer_address_updated',
                sprintf('Customer address updated for %s', $customer_email),
                ['source' => 'woocommerce_profile_update']
            );
        }
    }

    /**
     * Auto-create customer from license
     */
    public function auto_create_customer_from_license($license_id, $license_data) {
        $sanitized_license_id = absint($license_id);
        if (0 === $sanitized_license_id) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid license ID provided for auto-customer creation.', 'wplm'));
            return false;
        }

        // Validate and sanitize license data
        if (empty($license_data) || !is_array($license_data) || empty($license_data['customer_email'])) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid or incomplete license data provided for auto-customer creation.', 'wplm'));
            return false;
        }

        $customer_email = sanitize_email($license_data['customer_email']);
        if (empty($customer_email) || !is_email($customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid customer email in license data for ID %d.', 'wplm'), $sanitized_license_id));
            return false;
        }

        $existing_customer = $this->get_customer_by_email($customer_email);
        if ($existing_customer) {
            $this->update_customer_license_count($existing_customer->ID);
            
            // Update existing customer's license keys
            $existing_license_keys = get_post_meta($existing_customer->ID, '_wplm_license_keys', true) ?: [];
            $license_key = sanitize_text_field($license_data['license_key']);
            if (!in_array($license_key, $existing_license_keys, true)) {
                $existing_license_keys[] = $license_key;
                if (!update_post_meta($existing_customer->ID, '_wplm_license_keys', $existing_license_keys)) {
                    error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update license keys for existing customer %d.', 'wplm'), $existing_customer->ID));
                }
            }
            
            // Update last activity
            if (!update_post_meta($existing_customer->ID, '_wplm_last_activity', current_time('mysql'))) {
                error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update last activity for existing customer %d.', 'wplm'), $existing_customer->ID));
            }
            
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $existing_customer->ID,
                    'customer_license_added',
                    sprintf(esc_html__('License %s added to existing customer %s.', 'wplm'), $license_key, $customer_email),
                    ['source_license_id' => $sanitized_license_id, 'email' => $customer_email]
                );
            }

            return $existing_customer->ID;
        }

        // Create new customer
        $customer_name = !empty($license_data['customer_name']) ? sanitize_text_field($license_data['customer_name']) : $customer_email;
        
        $new_customer_data = [
            'post_type' => $this->customer_post_type,
            'post_title' => $customer_name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ];

        $customer_id = wp_insert_post($new_customer_data, true);

        if (is_wp_error($customer_id)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to create new customer for license ID %d. Error: %s', 'wplm'), $sanitized_license_id, $customer_id->get_error_message()));
            return false;
        }

        // Store customer metadata
        if (!update_post_meta($customer_id, '_wplm_customer_email', $customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save email for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_customer_name', $customer_name)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save name for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_first_license_date', current_time('mysql'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save first license date for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save last activity for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_total_licenses', 1)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save total licenses for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_active_licenses', 1)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save active licenses for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_customer_status', 'active')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save status for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_customer_source', 'license_generation')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save source for new customer %d.', 'wplm'), $customer_id));
        }
        
        // Initialize arrays
        $license_key = sanitize_text_field($license_data['license_key']);
        if (!update_post_meta($customer_id, '_wplm_license_keys', [$license_key])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize license keys for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_communication_log', [])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize communication log for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_tags', [])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize tags for new customer %d.', 'wplm'), $customer_id));
        }
        if (!update_post_meta($customer_id, '_wplm_notes', '')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize notes for new customer %d.', 'wplm'), $customer_id));
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_created',
                sprintf(esc_html__('New customer created automatically from license generation for %s.', 'wplm'), $customer_email),
                ['source_license_id' => $sanitized_license_id, 'email' => $customer_email]
            );
        }

        return $customer_id;
    }

    /**
     * Sync customer from WooCommerce order
     */
    public function sync_customer_from_order($order_id) {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $customer_email = $order->get_billing_email();
        if (empty($customer_email)) {
            return;
        }

        $existing_customer = $this->get_customer_by_email($customer_email);
        if ($existing_customer) {
            $this->update_customer_from_order($existing_customer->ID, $order);
        } else {
            $this->create_customer_from_order($order);
        }
    }

    /**
     * Create customer from WooCommerce order
     */
    private function create_customer_from_order($order) {
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
        if (empty(trim($customer_name))) {
            $customer_name = $customer_email;
        }

        $new_customer_data = [
            'post_type' => $this->customer_post_type,
            'post_title' => $customer_name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ];

        $customer_id = wp_insert_post($new_customer_data, true);

        if (is_wp_error($customer_id)) {
            error_log(sprintf('WPLM_Customer_Management_System: Failed to create customer from order. Error: %s', $customer_id->get_error_message()));
            return false;
        }

        // Store customer metadata
        $address = [
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country()
        ];

        $phone = $order->get_billing_phone();

        update_post_meta($customer_id, '_wplm_customer_email', $customer_email);
        update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
        update_post_meta($customer_id, '_wplm_address', $address);
        update_post_meta($customer_id, '_wplm_phone', $phone);
        update_post_meta($customer_id, '_wplm_first_license_date', current_time('mysql'));
        update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
        update_post_meta($customer_id, '_wplm_total_licenses', 0);
        update_post_meta($customer_id, '_wplm_active_licenses', 0);
        update_post_meta($customer_id, '_wplm_customer_status', 'active');
        update_post_meta($customer_id, '_wplm_customer_source', 'woocommerce_order');
        update_post_meta($customer_id, '_wplm_woocommerce_user_id', $order->get_customer_id());
        update_post_meta($customer_id, '_wplm_woocommerce_orders', [$order->get_id()]);
        update_post_meta($customer_id, '_wplm_woocommerce_total_spent', $order->get_total());
        update_post_meta($customer_id, '_wplm_license_keys', []);
        update_post_meta($customer_id, '_wplm_communication_log', []);
        update_post_meta($customer_id, '_wplm_tags', []);
        update_post_meta($customer_id, '_wplm_notes', '');

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_created',
                sprintf('New customer created from WooCommerce order %s for %s', $order->get_id(), $customer_email),
                ['source_order_id' => $order->get_id(), 'email' => $customer_email]
            );
        }

        return $customer_id;
    }

    /**
     * Update customer from WooCommerce order
     */
    private function update_customer_from_order($customer_id, $order) {
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
        if (!empty(trim($customer_name))) {
            update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
        }

        // Update address
        $address = [
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country()
        ];
        update_post_meta($customer_id, '_wplm_address', $address);

        // Update phone
        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            update_post_meta($customer_id, '_wplm_phone', $phone);
        }

        // Update WooCommerce data
        $woocommerce_orders = get_post_meta($customer_id, '_wplm_woocommerce_orders', true) ?: [];
        if (!in_array($order->get_id(), $woocommerce_orders)) {
            $woocommerce_orders[] = $order->get_id();
            update_post_meta($customer_id, '_wplm_woocommerce_orders', $woocommerce_orders);
        }

        $current_total_spent = get_post_meta($customer_id, '_wplm_woocommerce_total_spent', true) ?: 0;
        $new_total_spent = $current_total_spent + $order->get_total();
        update_post_meta($customer_id, '_wplm_woocommerce_total_spent', $new_total_spent);

        update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_updated',
                sprintf('Customer updated from WooCommerce order %s', $order->get_id()),
                ['source_order_id' => $order->get_id(), 'email' => $customer_email]
            );
        }
    }

    /**
     * Get customer by email
     */
    public function get_customer_by_email($email) {
        $sanitized_email = sanitize_email($email);
        if (empty($sanitized_email)) {
            return false;
        }

        $customers = get_posts([
            'post_type' => $this->customer_post_type,
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $sanitized_email,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        return !empty($customers) ? $customers[0] : false;
    }

    /**
     * Update customer license count
     */
    private function update_customer_license_count($customer_id) {
        $sanitized_customer_id = absint($customer_id);
        if (0 === $sanitized_customer_id) {
            return false;
        }

        // Count total licenses
        $total_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => get_post_meta($sanitized_customer_id, '_wplm_customer_email', true),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any'
        ]);

        $total_licenses = $total_licenses_query->found_posts;

        // Count active licenses
        $active_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => get_post_meta($sanitized_customer_id, '_wplm_customer_email', true),
            'meta_query' => [
                [
                    'key' => '_wplm_status',
                    'value' => 'active',
                    'compare' => '='
                ]
            ],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any'
        ]);

        $active_licenses = $active_licenses_query->found_posts;

        // Update metadata
        update_post_meta($sanitized_customer_id, '_wplm_total_licenses', $total_licenses);
        update_post_meta($sanitized_customer_id, '_wplm_active_licenses', $active_licenses);

        return true;
    }

    /**
     * Aggregate customer data
     */
    public function aggregate_customer_data() {
        $customers = get_posts([
            'post_type' => $this->customer_post_type,
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        foreach ($customers as $customer) {
            $this->update_customer_license_count($customer->ID);
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'customer_data_aggregated',
                sprintf('Customer data aggregated for %d customers', count($customers)),
                ['customer_count' => count($customers)]
            );
        }
    }
}