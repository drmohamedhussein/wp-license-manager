<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer Relationship Management System for WPLM
 * Provides comprehensive customer management with CRM/ERM functionality
 */
class WPLM_Customer_Management_System {

    private $customer_post_type = 'wplm_customer';

    public function __construct() {
        $this->init_hooks();
        // The CPT is now registered by WPLM_CPT_Manager, remove duplicate registration
        // $this->register_customer_post_type(); 
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_wplm_get_customers', [$this, 'ajax_get_customers']);
        add_action('wp_ajax_wplm_get_customer_details', [$this, 'ajax_get_customer_details']);
        add_action('wp_ajax_wplm_customer_search', [$this, 'ajax_customer_search']);
        add_action('wp_ajax_wplm_create_customer', [$this, 'ajax_create_customer']);
        add_action('wp_ajax_wplm_update_customer', [$this, 'ajax_update_customer']);
        add_action('wp_ajax_wplm_export_customers', [$this, 'ajax_export_customers']);
        add_action('wp_ajax_wplm_import_customers', [$this, 'ajax_import_customers']);
        add_action('wp_ajax_wplm_send_customer_email', [$this, 'ajax_send_customer_email']);
        
        // Auto-create customers from license generation
        add_action('wplm_license_created', [$this, 'auto_create_customer_from_license'], 10, 2);
        
        // WooCommerce integration
        add_action('woocommerce_order_status_completed', [$this, 'sync_customer_from_order'], 15, 1);
        add_action('woocommerce_customer_save_address', [$this, 'sync_customer_address'], 10, 2);
        
        // Meta boxes
        add_action('add_meta_boxes', [$this, 'add_customer_meta_boxes']);
        add_action('save_post', [$this, 'save_customer_meta']);
        
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

        WPLM_Activity_Logger::log(
            $customer_post->ID,
            'customer_address_updated',
            sprintf('Customer address updated for %s', $customer_email),
            ['source' => 'woocommerce_profile_update']
        );
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
                sprintf(esc_html__('Customer %s created from license generation.', 'wplm'), $customer_name),
                ['source_license' => $sanitized_license_id, 'email' => $customer_email]
            );
        }

        return $customer_id;
    }

    /**
     * Sync customer from WooCommerce order
     */
    public function sync_customer_from_order($order_id) {
        $sanitized_order_id = absint($order_id);
        if (0 === $sanitized_order_id) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid order ID provided for customer sync.', 'wplm'));
            return; // Exit if order ID is invalid
        }

        // Check customer sync behavior setting
        $sync_behavior = get_option('wplm_wc_customer_sync_behavior', 'sync_on_order');
        if ($sync_behavior === 'manual_only') {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'customer_sync_skipped',
                    sprintf(esc_html__('Customer sync for order ID %d skipped due to manual_only setting.', 'wplm'), $sanitized_order_id),
                    ['order_id' => $sanitized_order_id]
                );
            }
            return; // Skip automatic sync if set to manual only
        }

        $order = wc_get_order($sanitized_order_id);
        if (!$order) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: WooCommerce order not found for ID %d during customer sync.', 'wplm'), $sanitized_order_id));
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'customer_sync_failed',
                    sprintf(esc_html__('Failed to sync customer: WooCommerce order not found for ID %d.', 'wplm'), $sanitized_order_id),
                    ['order_id' => $sanitized_order_id]
                );
            }
            return; // Exit if order not found
        }

        $customer_email = sanitize_email($order->get_billing_email());
        $customer_name = trim(sanitize_text_field($order->get_billing_first_name()) . ' ' . sanitize_text_field($order->get_billing_last_name()));
        
        if (empty($customer_email) || !is_email($customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid customer email for order ID %d during customer sync.', 'wplm'), $sanitized_order_id));
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'customer_sync_failed',
                    sprintf(esc_html__('Failed to sync customer: Invalid customer email for order ID %d.', 'wplm'), $sanitized_order_id),
                    ['order_id' => $sanitized_order_id, 'email' => $order->get_billing_email()]
                );
            }
            return;
        }

        $existing_customer = $this->get_customer_by_email($customer_email);
        
        if ($existing_customer) {
            // Update existing customer
            $this->update_customer_from_order($existing_customer->ID, $order);
        } else {
            // Create new customer from order
            $this->create_customer_from_order($order);
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'customer_sync_completed',
                sprintf(esc_html__('Customer sync for order ID %d completed successfully.', 'wplm'), $sanitized_order_id),
                ['order_id' => $sanitized_order_id, 'email' => $customer_email]
            );
        }
    }

    /**
     * Create customer from WooCommerce order
     */
    private function create_customer_from_order($order) {
        // Validate $order object
        if (!is_a($order, 'WC_Order')) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid WooCommerce order object provided for customer creation.', 'wplm'));
            return new WP_Error('invalid_order_object', esc_html__('Invalid WooCommerce order object.', 'wplm'));
        }

        $customer_email = sanitize_email($order->get_billing_email());
        $customer_name = trim(sanitize_text_field($order->get_billing_first_name()) . ' ' . sanitize_text_field($order->get_billing_last_name()));
        
        if (empty($customer_email) || !is_email($customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid customer email for order #%d during customer creation.', 'wplm'), $order->get_order_number()));
            return new WP_Error('invalid_customer_email', esc_html__('Invalid customer email.', 'wplm'));
        }

        if (empty($customer_name)) {
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
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to create new customer for order #%d. Error: %s', 'wplm'), $order->get_order_number(), $customer_id->get_error_message()));
            return $customer_id;
        }

        // Store customer metadata with error logging
        if (!update_post_meta($customer_id, '_wplm_customer_email', $customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save email for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_customer_name', $customer_name)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save name for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_first_name', sanitize_text_field($order->get_billing_first_name()))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save first name for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_last_name', sanitize_text_field($order->get_billing_last_name()))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save last name for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_phone', sanitize_text_field($order->get_billing_phone()))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save phone for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_company', sanitize_text_field($order->get_billing_company()))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save company for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        
        $address = [
            'address_1' => sanitize_text_field($order->get_billing_address_1()),
            'address_2' => sanitize_text_field($order->get_billing_address_2()),
            'city' => sanitize_text_field($order->get_billing_city()),
            'state' => sanitize_text_field($order->get_billing_state()),
            'postcode' => sanitize_text_field($order->get_billing_postcode()),
            'country' => sanitize_text_field($order->get_billing_country())
        ];
        if (!update_post_meta($customer_id, '_wplm_address', $address)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save address for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }

        if (!update_post_meta($customer_id, '_wplm_wc_customer_id', $order->get_customer_id())) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save WC customer ID for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_first_order_date', $order->get_date_created()->date('Y-m-d H:i:s'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save first order date for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_last_order_date', $order->get_date_created()->date('Y-m-d H:i:s'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save last order date for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save last activity for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_total_spent', floatval($order->get_total()))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save total spent for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_order_count', 1)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save order count for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_customer_status', 'active')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save customer status for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_customer_source', 'woocommerce')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to save customer source for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        
        // Initialize arrays
        if (!update_post_meta($customer_id, '_wplm_order_ids', [$order->get_id()])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize order IDs for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_license_keys', [])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize license keys for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_communication_log', [])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize communication log for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_tags', [])) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize tags for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($customer_id, '_wplm_notes', '')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to initialize notes for new customer %d from order #%d.', 'wplm'), $customer_id, $order->get_order_number()));
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_created',
                sprintf(esc_html__('Customer %s created from WooCommerce order #%d.', 'wplm'), $customer_name, $order->get_order_number()),
                ['source_order' => $order->get_id(), 'email' => $customer_email]
            );
        }

        return $customer_id;
    }

    /**
     * Update customer from WooCommerce order
     */
    private function update_customer_from_order($customer_id, $order) {
        $sanitized_customer_id = absint($customer_id);
        if (0 === $sanitized_customer_id) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid customer ID provided for update from order.', 'wplm'));
            return new WP_Error('invalid_customer_id', esc_html__('Invalid customer ID.', 'wplm'));
        }

        // Validate $order object
        if (!is_a($order, 'WC_Order')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid WooCommerce order object provided for customer update for customer ID %d.', 'wplm'), $sanitized_customer_id));
            return new WP_Error('invalid_order_object', esc_html__('Invalid WooCommerce order object.', 'wplm'));
        }

        // Update basic info with error logging
        if (!update_post_meta($sanitized_customer_id, '_wplm_last_order_date', $order->get_date_created()->date('Y-m-d H:i:s'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update last order date for customer %d from order #%d.', 'wplm'), $sanitized_customer_id, $order->get_order_number()));
        }
        if (!update_post_meta($sanitized_customer_id, '_wplm_last_activity', current_time('mysql'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update last activity for customer %d from order #%d.', 'wplm'), $sanitized_customer_id, $order->get_order_number()));
        }
        
        // Update spending with error logging
        $current_spent = get_post_meta($sanitized_customer_id, '_wplm_total_spent', true) ?: 0;
        $new_total = floatval($current_spent) + floatval($order->get_total());
        if (!update_post_meta($sanitized_customer_id, '_wplm_total_spent', $new_total)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update total spent for customer %d from order #%d.', 'wplm'), $sanitized_customer_id, $order->get_order_number()));
        }
        
        // Update order count with error logging
        $current_count = get_post_meta($sanitized_customer_id, '_wplm_order_count', true) ?: 0;
        if (!update_post_meta($sanitized_customer_id, '_wplm_order_count', intval($current_count) + 1)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update order count for customer %d from order #%d.', 'wplm'), $sanitized_customer_id, $order->get_order_number()));
        }
        
        // Add order ID with error logging
        $order_ids = get_post_meta($sanitized_customer_id, '_wplm_order_ids', true) ?: [];
        $order_id = $order->get_id();
        if (!in_array($order_id, $order_ids, true)) {
            $order_ids[] = $order_id;
            if (!update_post_meta($sanitized_customer_id, '_wplm_order_ids', $order_ids)) {
                error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to add order ID %d to customer %d.', 'wplm'), $order_id, $sanitized_customer_id));
            }
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $sanitized_customer_id,
                'customer_updated_from_order',
                sprintf(esc_html__('Customer updated from WooCommerce order #%d. New total spent: %s.', 'wplm'), $order->get_order_number(), wc_price($new_total)),
                ['source_order_id' => $order->get_id(), 'new_total_spent' => $new_total]
            );
        }
    }

    /**
     * Get customer by email
     */
    public function get_customer_by_email($email) {
        $sanitized_email = sanitize_email($email);
        if (empty($sanitized_email) || !is_email($sanitized_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid email provided for customer lookup: %s.', 'wplm'), esc_html($email)));
            return new WP_Error('invalid_email', esc_html__('Invalid email address.', 'wplm'));
        }

        $customers = get_posts([
            'post_type' => $this->customer_post_type,
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $sanitized_email,
            'posts_per_page' => 1,
            'fields' => 'ids', // Only fetch IDs for efficiency
        ]);

        if (empty($customers)) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'customer_not_found',
                    sprintf(esc_html__('Customer not found for email: %s.', 'wplm'), $sanitized_email),
                    ['email' => $sanitized_email]
                );
            }
            return null;
        }

        return get_post($customers[0]); // Return the full post object if found
    }

    /**
     * Update customer license count
     */
    private function update_customer_license_count($customer_id) {
        $sanitized_customer_id = absint($customer_id);
        if (0 === $sanitized_customer_id) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid customer ID provided for license count update.', 'wplm'));
            return new WP_Error('invalid_customer_id', esc_html__('Invalid customer ID.', 'wplm'));
        }

        $customer_email = get_post_meta($sanitized_customer_id, '_wplm_customer_email', true);
        if (empty($customer_email) || !is_email($customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Customer email not found or invalid for customer ID %d.', 'wplm'), $sanitized_customer_id));
            return new WP_Error('customer_email_missing', esc_html__('Customer email missing or invalid.', 'wplm'));
        }
        
        // Count total licenses
        $total_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $customer_email,
            'posts_per_page' => -1, // Get all matching licenses
            'fields' => 'ids'
        ]);
        $total_licenses = $total_licenses_query->found_posts;
        
        // Count active licenses
        $active_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wplm_customer_email',
                    'value' => $customer_email,
                    'compare' => '='
                ],
                [
                    'key' => '_wplm_status',
                    'value' => 'active',
                    'compare' => '='
                ]
            ],
            'posts_per_page' => -1, // Get all matching active licenses
            'fields' => 'ids'
        ]);
        $active_licenses = $active_licenses_query->found_posts;

        if (!update_post_meta($sanitized_customer_id, '_wplm_total_licenses', $total_licenses)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update total licenses for customer %d.', 'wplm'), $sanitized_customer_id));
        }
        if (!update_post_meta($sanitized_customer_id, '_wplm_active_licenses', $active_licenses)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update active licenses for customer %d.', 'wplm'), $sanitized_customer_id));
        }
        if (!update_post_meta($sanitized_customer_id, '_wplm_last_activity', current_time('mysql'))) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update last activity timestamp for customer %d.', 'wplm'), $sanitized_customer_id));
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $sanitized_customer_id,
                'customer_license_count_updated',
                sprintf(esc_html__('Customer license counts updated: Total %d, Active %d.', 'wplm'), $total_licenses, $active_licenses),
                ['total_licenses' => $total_licenses, 'active_licenses' => $active_licenses, 'email' => $customer_email]
            );
        }
    }

    /**
     * Add customer meta boxes
     */
    public function add_customer_meta_boxes() {
        add_meta_box(
            'wplm_customer_details',
            __('Customer Details', 'wp-license-manager'),
            [$this, 'render_customer_details_meta_box'],
            $this->customer_post_type,
            'normal',
            'high'
        );

        add_meta_box(
            'wplm_customer_activity',
            __('Customer Activity', 'wp-license-manager'),
            [$this, 'render_customer_activity_meta_box'],
            $this->customer_post_type,
            'normal',
            'default'
        );

        add_meta_box(
            'wplm_customer_communication',
            __('Communication Log', 'wp-license-manager'),
            [$this, 'render_customer_communication_meta_box'],
            $this->customer_post_type,
            'side',
            'default'
        );
    }

    /**
     * Render customer details meta box
     */
    public function render_customer_details_meta_box($post) {
        wp_nonce_field('wplm_customer_meta', 'wplm_customer_meta_nonce');
        
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true) ?: '';
        $first_name = get_post_meta($post->ID, '_wplm_first_name', true) ?: '';
        $last_name = get_post_meta($post->ID, '_wplm_last_name', true) ?: '';
        $phone = get_post_meta($post->ID, '_wplm_phone', true) ?: '';
        $company = get_post_meta($post->ID, '_wplm_company', true) ?: '';
        $address = get_post_meta($post->ID, '_wplm_address', true) ?: [];
        $customer_status = get_post_meta($post->ID, '_wplm_customer_status', true) ?: 'active';
        $customer_source = get_post_meta($post->ID, '_wplm_customer_source', true) ?: '';
        $tags = get_post_meta($post->ID, '_wplm_tags', true) ?: [];
        $notes = get_post_meta($post->ID, '_wplm_notes', true) ?: '';
        
        ?>
        <table class="form-table">
            <tr>
                <th><label for="wplm_customer_email"><?php esc_html_e('Email Address', 'wp-license-manager'); ?></label></th>
                <td><input type="email" id="wplm_customer_email" name="wplm_customer_email" value="<?php echo esc_attr($customer_email); ?>" class="regular-text" required /></td>
            </tr>
            <tr>
                <th><label for="wplm_first_name"><?php esc_html_e('First Name', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_first_name" name="wplm_first_name" value="<?php echo esc_attr($first_name); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_last_name"><?php esc_html_e('Last Name', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_last_name" name="wplm_last_name" value="<?php echo esc_attr($last_name); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_phone"><?php esc_html_e('Phone', 'wp-license-manager'); ?></label></th>
                <td><input type="tel" id="wplm_phone" name="wplm_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_company"><?php esc_html_e('Company', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_company" name="wplm_company" value="<?php echo esc_attr($company); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_customer_status"><?php esc_html_e('Status', 'wp-license-manager'); ?></label></th>
                <td>
                    <select id="wplm_customer_status" name="wplm_customer_status">
                        <option value="active" <?php selected($customer_status, 'active'); ?>><?php esc_html_e('Active', 'wp-license-manager'); ?></option>
                        <option value="inactive" <?php selected($customer_status, 'inactive'); ?>><?php esc_html_e('Inactive', 'wp-license-manager'); ?></option>
                        <option value="blocked" <?php selected($customer_status, 'blocked'); ?>><?php esc_html_e('Blocked', 'wp-license-manager'); ?></option>
                        <option value="prospect" <?php selected($customer_status, 'prospect'); ?>><?php esc_html_e('Prospect', 'wp-license-manager'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="wplm_tags"><?php esc_html_e('Tags', 'wp-license-manager'); ?></label></th>
                <td>
                    <input type="text" id="wplm_tags" name="wplm_tags" value="<?php echo esc_attr(implode(', ', $tags)); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Comma-separated tags for categorizing customers', 'wp-license-manager'); ?></p>
                </td>
            </tr>
        </table>

        <h4><?php esc_html_e('Address Information', 'wp-license-manager'); ?></h4>
        <table class="form-table">
            <tr>
                <th><label for="wplm_address_1"><?php esc_html_e('Address Line 1', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_address_1" name="wplm_address_1" value="<?php echo esc_attr($address['address_1'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_address_2"><?php esc_html_e('Address Line 2', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_address_2" name="wplm_address_2" value="<?php echo esc_attr($address['address_2'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_city"><?php esc_html_e('City', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_city" name="wplm_city" value="<?php echo esc_attr($address['city'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_state"><?php esc_html_e('State/Province', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_state" name="wplm_state" value="<?php echo esc_attr($address['state'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_postcode"><?php esc_html_e('Postal Code', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_postcode" name="wplm_postcode" value="<?php echo esc_attr($address['postcode'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_country"><?php esc_html_e('Country', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_country" name="wplm_country" value="<?php echo esc_attr($address['country'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
        </table>

        <h4><?php esc_html_e('Notes', 'wp-license-manager'); ?></h4>
        <table class="form-table">
            <tr>
                <td>
                    <textarea id="wplm_notes" name="wplm_notes" rows="5" class="large-text"><?php echo esc_textarea($notes); ?></textarea>
                    <p class="description"><?php esc_html_e('Internal notes about this customer', 'wp-license-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render customer activity meta box
     */
    public function render_customer_activity_meta_box($post) {
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true) ?: '';
        $total_licenses = absint(get_post_meta($post->ID, '_wplm_total_licenses', true) ?: 0);
        $active_licenses = absint(get_post_meta($post->ID, '_wplm_active_licenses', true) ?: 0);
        $total_spent = floatval(get_post_meta($post->ID, '_wplm_total_spent', true) ?: 0);
        $order_count = absint(get_post_meta($post->ID, '_wplm_order_count', true) ?: 0);
        $first_order_date = get_post_meta($post->ID, '_wplm_first_order_date', true) ?: '';
        $last_activity = get_post_meta($post->ID, '_wplm_last_activity', true) ?: '';

        ?>
        <div class="wplm-customer-stats">
            <div class="wplm-stat-grid">
                <div class="wplm-stat-item">
                    <h4><?php echo esc_html($total_licenses); ?></h4>
                    <p><?php esc_html_e('Total Licenses', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-stat-item">
                    <h4><?php echo esc_html($active_licenses); ?></h4>
                    <p><?php esc_html_e('Active Licenses', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-stat-item">
                    <h4><?php echo function_exists('wc_price') ? wc_price($total_spent) : '$' . number_format($total_spent, 2); ?></h4>
                    <p><?php esc_html_e('Total Spent', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-stat-item">
                    <h4><?php echo esc_html($order_count); ?></h4>
                    <p><?php esc_html_e('Orders', 'wp-license-manager'); ?></p>
                </div>
            </div>
        </div>

        <h4><?php esc_html_e('Recent Licenses', 'wp-license-manager'); ?></h4>
        <div id="customer-licenses-list">
            <?php $this->render_customer_licenses(esc_html($customer_email)); ?>
        </div>

        <h4><?php esc_html_e('Recent Orders', 'wp-license-manager'); ?></h4>
        <div id="customer-orders-list">
            <?php $this->render_customer_orders(absint($post->ID)); ?>
        </div>

        <style>
        .wplm-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .wplm-stat-item {
            text-align: center;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 6px;
        }
        .wplm-stat-item h4 {
            margin: 0 0 5px 0;
            font-size: 1.5em;
            color: #2271b1;
        }
        .wplm-stat-item p {
            margin: 0;
            font-size: 0.9em;
            color: #666;
        }
        </style>
        <?php
    }

    /**
     * Render customer communication meta box
     */
    public function render_customer_communication_meta_box($post) {
        $communication_log = get_post_meta($post->ID, '_wplm_communication_log', true) ?: [];
        
        ?>
        <div class="wplm-communication-section">
            <h4><?php esc_html_e('Send Email', 'wp-license-manager'); ?></h4>
            <p>
                <input type="text" id="email-subject" placeholder="<?php esc_attr_e('Subject', 'wp-license-manager'); ?>" class="widefat" />
            </p>
            <p>
                <textarea id="email-message" placeholder="<?php esc_attr_e('Message', 'wp-license-manager'); ?>" rows="5" class="widefat"></textarea>
            </p>
            <p>
                <button type="button" class="button" id="send-customer-email" data-customer-id="<?php echo esc_attr($post->ID); ?>">
                    <?php esc_html_e('Send Email', 'wp-license-manager'); ?>
                </button>
            </p>
        </div>

        <div class="wplm-communication-log">
            <h4><?php esc_html_e('Communication History', 'wp-license-manager'); ?></h4>
            <?php if (empty($communication_log)): ?>
                <p class="description"><?php esc_html_e('No communication history yet.', 'wp-license-manager'); ?></p>
            <?php else: ?>
                <div class="wplm-comm-list">
                    <?php foreach (array_reverse(array_slice($communication_log, -10)) as $comm): ?>
                        <div class="wplm-comm-item">
                            <strong><?php echo esc_html($comm['type'] ?? ''); ?></strong>
                            <span class="wplm-comm-date"><?php echo esc_html(date('M j, Y g:i A', strtotime($comm['date'] ?? ''))); ?></span>
                            <p><?php echo esc_html($comm['subject'] ?? $comm['message'] ?? ''); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .wplm-comm-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
        }
        .wplm-comm-date {
            float: right;
            font-size: 0.9em;
            color: #666;
        }
        .wplm-comm-item p {
            margin: 5px 0 0 0;
            font-size: 0.95em;
            color: #444;
        }
        </style>
        <?php
    }

    /**
     * Render customer licenses
     */
    private function render_customer_licenses($customer_email) {
        $sanitized_customer_email = sanitize_email($customer_email);
        if (empty($sanitized_customer_email) || !is_email($sanitized_customer_email)) {
            echo '<p class="description">' . esc_html__('No valid email address available to display licenses.', 'wp-license-manager') . '</p>';
            return;
        }

        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $sanitized_customer_email,
            'posts_per_page' => 5,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        if (empty($licenses)) {
            echo '<p class="description">' . esc_html__('No licenses found for this customer.', 'wp-license-manager') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat">';
        echo '<thead><tr><th>' . esc_html__('License Key', 'wp-license-manager') . '</th><th>' . esc_html__('Product', 'wp-license-manager') . '</th><th>' . esc_html__('Status', 'wp-license-manager') . '</th><th>' . esc_html__('Created', 'wp-license-manager') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($licenses as $license) {
            $product_id = get_post_meta($license->ID, '_wplm_product_id', true) ?: '';
            $status = get_post_meta($license->ID, '_wplm_status', true) ?: '';
            $created = get_the_date('M j, Y', $license->ID);
            
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($license->ID)) . '">' . esc_html($license->post_title) . '</a></td>';
            echo '<td>' . esc_html($product_id) . '</td>';
            echo '<td><span class="wplm-status-badge wplm-status-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span></td>';
            echo '<td>' . esc_html($created) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }

    /**
     * Render customer orders
     */
    private function render_customer_orders($customer_id) {
        $sanitized_customer_id = absint($customer_id);
        if (0 === $sanitized_customer_id) {
            error_log(esc_html__('WPLM_Customer_Management_System: Invalid customer ID provided for rendering orders.', 'wplm'));
            echo '<p class="description">' . esc_html__('Invalid customer ID to display orders.', 'wp-license-manager') . '</p>';
            return;
        }

        $order_ids = get_post_meta($sanitized_customer_id, '_wplm_order_ids', true) ?: [];
        
        if (empty($order_ids)) {
            echo '<p class="description">' . esc_html__('No orders found for this customer.', 'wp-license-manager') . '</p>';
            return;
        }

        if (!function_exists('wc_get_order')) {
            echo '<p class="description">' . esc_html__('WooCommerce is not active, unable to display orders.', 'wp-license-manager') . '</p>';
            return; // Exit if WooCommerce is not active
        }

        $recent_orders = array_slice(array_reverse($order_ids), 0, 5);
        
        echo '<table class="wp-list-table widefat">';
        echo '<thead><tr><th>' . esc_html__('Order', 'wp-license-manager') . '</th><th>' . esc_html__('Date', 'wp-license-manager') . '</th><th>' . esc_html__('Status', 'wp-license-manager') . '</th><th>' . esc_html__('Total', 'wp-license-manager') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($recent_orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $sanitized_customer_id,
                        'customer_order_not_found',
                        sprintf(esc_html__('WooCommerce order ID %d not found for customer %d during rendering.', 'wplm'), absint($order_id), $sanitized_customer_id),
                        ['order_id' => absint($order_id)]
                    );
                }
                continue;
            }
            
            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($order->get_date_created()->date('M j, Y')) . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
            echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }

    /**
     * Save customer meta
     */
    public function save_customer_meta($post_id) {
        // Verify nonce and capability
        if (!isset($_POST['wplm_customer_meta_nonce']) || !wp_verify_nonce($_POST['wplm_customer_meta_nonce'], 'wplm_customer_meta')) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Nonce verification failed for customer %d.', 'wplm'), absint($post_id)));
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: User does not have permission to edit customer %d.', 'wplm'), absint($post_id)));
            return;
        }

        // Sanitize and update basic fields
        $fields_to_sanitize = [
            'wplm_customer_email' => 'sanitize_email',
            'wplm_first_name' => 'sanitize_text_field',
            'wplm_last_name' => 'sanitize_text_field',
            'wplm_phone' => 'sanitize_text_field',
            'wplm_company' => 'sanitize_text_field',
            'wplm_customer_status' => 'sanitize_key',
            'wplm_notes' => 'sanitize_textarea_field', // Use this for notes to preserve line breaks
        ];

        foreach ($fields_to_sanitize as $key => $sanitization_function) {
            if (isset($_POST[$key])) {
                $sanitized_value = call_user_func($sanitization_function, $_POST[$key]);
                if (!update_post_meta($post_id, '_' . $key, $sanitized_value)) {
                    error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update meta %s for customer %d.', 'wplm'), esc_html($key), absint($post_id)));
                }
            }
        }

        // Additional validation for email
        $customer_email = sanitize_email($_POST['wplm_customer_email'] ?? '');
        if (!empty($customer_email) && !is_email($customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid email format provided for customer %d: %s.', 'wplm'), absint($post_id), esc_html($customer_email)));
            // Optionally, return or show an admin notice. For now, we log and proceed.
        }

        // Validate customer status
        $allowed_statuses = ['active', 'inactive', 'blocked', 'prospect'];
        $customer_status = sanitize_key($_POST['wplm_customer_status'] ?? '');
        if (!in_array($customer_status, $allowed_statuses, true)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Invalid status provided for customer %d: %s.', 'wplm'), absint($post_id), esc_html($customer_status)));
            // Set a default or revert to previous status if invalid
            $customer_status = get_post_meta($post_id, '_wplm_customer_status', true) ?: 'active';
            if (!update_post_meta($post_id, '_wplm_customer_status', $customer_status)) {
                error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to revert/set default status for customer %d.', 'wplm'), absint($post_id)));
            }
        }

        // Sanitize and update address
        $address_fields = [
            'wplm_address_1',
            'wplm_address_2',
            'wplm_city',
            'wplm_state',
            'wplm_postcode',
            'wplm_country',
        ];
        $address = [];
        foreach ($address_fields as $field) {
            $key = str_replace('wplm_', '', $field);
            $address[$key] = sanitize_text_field($_POST[$field] ?? '');
        }
        if (!update_post_meta($post_id, '_wplm_address', $address)) {
            error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update address for customer %d.', 'wplm'), absint($post_id)));
        }

        // Sanitize and update tags
        if (isset($_POST['wplm_tags'])) {
            $tags = array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $_POST['wplm_tags']))));
            if (!update_post_meta($post_id, '_wplm_tags', $tags)) {
                error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update tags for customer %d.', 'wplm'), absint($post_id)));
            }
        }

        // Update post title based on name
        $first_name = sanitize_text_field($_POST['wplm_first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['wplm_last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        
        if (empty($full_name)) {
            $full_name = $customer_email; // Fallback to email if name is empty
        }

        if ($full_name !== get_the_title($post_id)) {
            $update_result = wp_update_post([
                'ID' => $post_id,
                'post_title' => $full_name
            ], true);

            if (is_wp_error($update_result)) {
                error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update post title for customer %d. Error: %s', 'wplm'), absint($post_id), $update_result->get_error_message()));
            }
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $post_id,
                'customer_meta_updated',
                sprintf(esc_html__('Customer details updated for customer ID %d.', 'wplm'), absint($post_id)),
                ['updated_by' => get_current_user_id()]
            );
        }
    }

    /**
     * AJAX: Get customers for DataTables
     */
    public function ajax_get_customers() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);
        
        $search_value = '';
        if (isset($_POST['search']) && is_array($_POST['search']) && isset($_POST['search']['value'])) {
            $search_value = sanitize_text_field($_POST['search']['value']);
        }
        $search = $search_value;

        $args = [
            'post_type' => $this->customer_post_type,
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if (!empty($search)) {
            $args['s'] = $search; // WordPress built-in search for post_title and content

            // Additional meta query for custom fields
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => '_wplm_customer_email',
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => '_wplm_first_name',
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => '_wplm_last_name',
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => '_wplm_company',
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => '_wplm_phone',
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
            ];
        }

        $customers = get_posts($args);
        
        // Get total count (for filtered results)
        $total_args = $args;
        $total_args['posts_per_page'] = -1;
        $total_args['fields'] = 'ids';
        unset($total_args['offset']);
        $total_customers_query = new WP_Query($total_args);
        $total_count = $total_customers_query->found_posts;

        $data = [];
        foreach ($customers as $customer) {
            $customer_email = get_post_meta($customer->ID, '_wplm_customer_email', true);
            $total_licenses = get_post_meta($customer->ID, '_wplm_total_licenses', true) ?: 0;
            $active_licenses = get_post_meta($customer->ID, '_wplm_active_licenses', true) ?: 0;
            $total_spent = get_post_meta($customer->ID, '_wplm_total_spent', true) ?: 0;
            $last_activity = get_post_meta($customer->ID, '_wplm_last_activity', true);

            $actions = sprintf(
                '<button class="button button-small wplm-view-customer" data-customer-id="%d">%s</button> ' .
                '<a href="%s" class="button button-small">%s</a>',
                $customer->ID,
                __('View', 'wp-license-manager'),
                get_edit_post_link($customer->ID),
                __('Edit', 'wp-license-manager')
            );

            $data[] = [
                'customer' => esc_html($customer->post_title),
                'email' => esc_html($customer_email),
                'total_licenses' => $total_licenses,
                'active_licenses' => $active_licenses,
                'total_spent' => function_exists('wc_price') ? wc_price($total_spent) : '$' . number_format($total_spent, 2),
                'last_activity' => $last_activity ? date('M j, Y', strtotime($last_activity)) : '—',
                'actions' => $actions
            ];
        }

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => $total_count,
            'recordsFiltered' => $total_count,
            'data' => $data
        ]);
    }

    /**
     * AJAX: Search customers
     */
    public function ajax_customer_search() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $search_term = sanitize_text_field($_POST['search']);
        
        if (empty($search_term)) {
            wp_send_json_success([]);
        }

        $args = [
            'post_type' => $this->customer_post_type,
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wplm_customer_email',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                ],
                [
                    'key' => '_wplm_first_name',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                ],
                [
                    'key' => '_wplm_last_name',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                ],
                [
                    'key' => '_wplm_company',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                ]
            ]
        ];

        $customers = get_posts($args);
        $results = [];

        foreach ($customers as $customer) {
            $email = get_post_meta($customer->ID, '_wplm_customer_email', true);
            $results[] = [
                'id' => $customer->ID,
                'text' => esc_html($customer->post_title . ' (' . $email . ')')
            ];
        }

        wp_send_json_success($results);
    }

    /**
     * AJAX: Create a new customer
     */
    public function ajax_create_customer() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $email = sanitize_email($_POST['email']);
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $company = sanitize_text_field($_POST['company'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        $tags = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['tags'] ?? ''))));

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Valid email address is required.', 'wp-license-manager')]);
        }

        if ($this->get_customer_by_email($email)) {
            wp_send_json_error(['message' => __('A customer with this email already exists.', 'wp-license-manager')]);
        }

        $customer_name = trim($first_name . ' ' . $last_name);
        if (empty($customer_name)) {
            $customer_name = $email;
        }

        $customer_id = wp_insert_post([
            'post_type' => $this->customer_post_type,
            'post_title' => $customer_name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ]);

        if (is_wp_error($customer_id)) {
            wp_send_json_error(['message' => $customer_id->get_error_message()]);
        }

        // Save metadata
        update_post_meta($customer_id, '_wplm_customer_email', $email);
        update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
        update_post_meta($customer_id, '_wplm_first_name', $first_name);
        update_post_meta($customer_id, '_wplm_last_name', $last_name);
        update_post_meta($customer_id, '_wplm_company', $company);
        update_post_meta($customer_id, '_wplm_phone', $phone);
        update_post_meta($customer_id, '_wplm_notes', $notes);
        update_post_meta($customer_id, '_wplm_customer_status', $status);
        update_post_meta($customer_id, '_wplm_tags', $tags);
        update_post_meta($customer_id, '_wplm_first_license_date', current_time('mysql'));
        update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
        update_post_meta($customer_id, '_wplm_total_licenses', 0);
        update_post_meta($customer_id, '_wplm_active_licenses', 0);
        update_post_meta($customer_id, '_wplm_total_spent', 0);
        update_post_meta($customer_id, '_wplm_order_count', 0);
        update_post_meta($customer_id, '_wplm_customer_source', 'manual');
        update_post_meta($customer_id, '_wplm_communication_log', []);
        update_post_meta($customer_id, '_wplm_order_ids', []);
        update_post_meta($customer_id, '_wplm_license_keys', []); // Re-added missing initialization

        WPLM_Activity_Logger::log(
            $customer_id,
            'customer_created',
            sprintf('Customer %s created manually', $customer_name),
            ['email' => $email, 'source' => 'manual']
        );

        wp_send_json_success(['message' => __('Customer created successfully', 'wp-license-manager'), 'customer_id' => $customer_id]);
    }

    /**
     * AJAX: Update an existing customer
     */
    public function ajax_update_customer() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $customer_id = intval($_POST['customer_id']);
        $customer = get_post($customer_id);

        if (!$customer || $customer->post_type !== $this->customer_post_type) {
            wp_send_json_error(['message' => __('Customer not found', 'wp-license-manager')]);
        }

        $email = sanitize_email($_POST['email']);
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $company = sanitize_text_field($_POST['company'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        $tags = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['tags'] ?? ''))));

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Valid email address is required.', 'wp-license-manager')]);
        }

        // Check if email already exists for another customer
        $existing_customer_by_email = $this->get_customer_by_email($email);
        if ($existing_customer_by_email && $existing_customer_by_email->ID !== $customer_id) {
            wp_send_json_error(['message' => __('A customer with this email already exists.', 'wp-license-manager')]);
        }

        $customer_name = trim($first_name . ' ' . $last_name);
        if (empty($customer_name)) {
            $customer_name = $email;
        }

        // Update post title
        wp_update_post([
            'ID' => $customer_id,
            'post_title' => $customer_name,
        ]);

        // Update metadata
        update_post_meta($customer_id, '_wplm_customer_email', $email);
        update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
        update_post_meta($customer_id, '_wplm_first_name', $first_name);
        update_post_meta($customer_id, '_wplm_last_name', $last_name);
        update_post_meta($customer_id, '_wplm_company', $company);
        update_post_meta($customer_id, '_wplm_phone', $phone);
        update_post_meta($customer_id, '_wplm_notes', $notes);
        update_post_meta($customer_id, '_wplm_customer_status', $status);
        update_post_meta($customer_id, '_wplm_tags', $tags);
        update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));

        WPLM_Activity_Logger::log(
            $customer_id,
            'customer_updated',
            sprintf('Customer %s updated manually', $customer_name),
            ['email' => $email]
        );

        wp_send_json_success(['message' => __('Customer updated successfully', 'wp-license-manager'), 'customer_id' => $customer_id]);
    }

    /**
     * AJAX: Export customers
     */
    public function ajax_export_customers() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        $data_type = 'customers'; // Specific data type

        $export_manager = new WPLM_Import_Export_Manager();
        $result = $export_manager->export_data($data_type, $format);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return; // Exit after sending error
        } else {
            wp_send_json_success(['message' => __('Customers exported successfully', 'wp-license-manager'), 'file_url' => $result['file_url']]);
        }
    }

    /**
     * AJAX: Import customers
     */
    public function ajax_import_customers() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        if (empty($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(['message' => __('No file uploaded.', 'wp-license-manager')]);
        }

        $file_path = $_FILES['import_file']['tmp_name'];
        $file_name = sanitize_file_name($_FILES['import_file']['name']);
        $import_mode = sanitize_text_field($_POST['import_mode'] ?? 'create_new');

        $import_manager = new WPLM_Import_Export_Manager();
        $result = $import_manager->import_data('customers', $file_path, $file_name, $import_mode);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return; // Exit after sending error
        } else {
            wp_send_json_success(['message' => sprintf(__('%d customers imported/updated successfully.', 'wp-license-manager'), $result['imported_count'])]);
        }
    }

    /**
     * AJAX: Get customer details for modal
     */
    public function ajax_get_customer_details() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $customer_id = intval($_POST['customer_id']);
        $customer = get_post($customer_id);

        if (!$customer || $customer->post_type !== $this->customer_post_type) {
            wp_send_json_error(['message' => 'Customer not found']);
        }

        ob_start();
        
        // Get customer metadata
        $customer_email = get_post_meta($customer_id, '_wplm_customer_email', true);
        $phone = get_post_meta($customer_id, '_wplm_phone', true);
        $company = get_post_meta($customer_id, '_wplm_company', true);
        $total_licenses = get_post_meta($customer_id, '_wplm_total_licenses', true) ?: 0;
        $active_licenses = get_post_meta($customer_id, '_wplm_active_licenses', true) ?: 0;
        $total_spent = get_post_meta($customer_id, '_wplm_total_spent', true) ?: 0;
        $last_activity = get_post_meta($customer_id, '_wplm_last_activity', true);
        $tags = get_post_meta($customer_id, '_wplm_tags', true) ?: [];
        $notes = get_post_meta($customer_id, '_wplm_notes', true);

        ?>
        <div class="wplm-customer-details">
            <div class="wplm-customer-header">
                <h3><?php echo esc_html($customer->post_title); ?></h3>
                <p class="description"><?php echo esc_html($customer_email); ?></p>
            </div>

            <div class="wplm-customer-stats">
                <div class="wplm-stat-grid">
                    <div class="wplm-stat-item">
                        <h4><?php echo $total_licenses; ?></h4>
                        <p><?php _e('Total Licenses', 'wp-license-manager'); ?></p>
                    </div>
                    <div class="wplm-stat-item">
                        <h4><?php echo $active_licenses; ?></h4>
                        <p><?php _e('Active Licenses', 'wp-license-manager'); ?></p>
                    </div>
                    <div class="wplm-stat-item">
                        <h4><?php echo function_exists('wc_price') ? wc_price($total_spent) : '$' . number_format($total_spent, 2); ?></h4>
                        <p><?php _e('Total Spent', 'wp-license-manager'); ?></p>
                    </div>
                </div>
            </div>

            <?php if (!empty($phone) || !empty($company)): ?>
            <div class="wplm-customer-info">
                <h4><?php _e('Contact Information', 'wp-license-manager'); ?></h4>
                <?php if (!empty($phone)): ?>
                    <p><strong><?php _e('Phone:', 'wp-license-manager'); ?></strong> <?php echo esc_html($phone); ?></p>
                <?php endif; ?>
                <?php if (!empty($company)): ?>
                    <p><strong><?php _e('Company:', 'wp-license-manager'); ?></strong> <?php echo esc_html($company); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($tags)): ?>
            <div class="wplm-customer-tags">
                <h4><?php _e('Tags', 'wp-license-manager'); ?></h4>
                <div class="wplm-tags">
                    <?php foreach ($tags as $tag): ?>
                        <span class="wplm-tag"><?php echo esc_html($tag); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($notes)): ?>
            <div class="wplm-customer-notes">
                <h4><?php _e('Notes', 'wp-license-manager'); ?></h4>
                <p><?php echo nl2br(esc_html($notes)); ?></p>
            </div>
            <?php endif; ?>

            <div class="wplm-customer-licenses">
                <h4><?php _e('Recent Licenses', 'wp-license-manager'); ?></h4>
                <?php $this->render_customer_licenses($customer_email); ?>
            </div>

            <div class="wplm-customer-actions">
                <a href="<?php echo get_edit_post_link($customer_id); ?>" class="button button-primary">
                    <?php _e('Edit Customer', 'wp-license-manager'); ?>
                </a>
                <button class="button" onclick="WPLM_Enhanced_Admin.closeModal()">
                    <?php _e('Close', 'wp-license-manager'); ?>
                </button>
            </div>
        </div>

        <style>
        .wplm-customer-details h3 { margin-top: 0; }
        .wplm-customer-details h4 { margin-top: 20px; margin-bottom: 10px; }
        .wplm-customer-actions { margin-top: 20px; text-align: right; }
        .wplm-tags { margin-top: 10px; }
        .wplm-tag {
            display: inline-block;
            background: #e1ecf4;
            color: #39739d;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        </style>
        <?php

        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: Send email to customer
     */
    public function ajax_send_customer_email() {
        check_ajax_referer('wplm_send_customer_email_nonce', 'nonce'); // Add nonce check here

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => esc_html__('You do not have sufficient permissions to send emails to customers.', 'wp-license-manager')], 403);
        }

        $customer_id = absint($_POST['customer_id'] ?? 0);
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (0 === $customer_id) {
            wp_send_json_error(['message' => esc_html__('Invalid customer ID provided.', 'wp-license-manager')], 400);
        }

        if (empty($subject) || empty($message)) {
            wp_send_json_error(['message' => esc_html__('Subject and message are required.', 'wp-license-manager')], 400);
        }

        $customer_email = sanitize_email(get_post_meta($customer_id, '_wplm_customer_email', true) ?: '');
        if (empty($customer_email) || !is_email($customer_email)) {
            wp_send_json_error(['message' => esc_html__('Invalid or missing customer email address.', 'wp-license-manager')], 400);
        }

        // Send email
        $sent = wp_mail($customer_email, $subject, $message);

        if ($sent) {
            // Log communication
            $communication_log = get_post_meta($customer_id, '_wplm_communication_log', true) ?: [];
            $communication_log[] = [
                'type' => esc_html__('Email Sent', 'wp-license-manager'),
                'subject' => esc_html($subject),
                'message' => esc_html($message),
                'date' => current_time('mysql'),
                'user' => absint(get_current_user_id())
            ];
            if (!update_post_meta($customer_id, '_wplm_communication_log', $communication_log)) {
                error_log(sprintf(esc_html__('WPLM_Customer_Management_System: Failed to update communication log for customer %d.', 'wplm'), $customer_id));
            }

            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $customer_id,
                    'customer_email_sent',
                    sprintf(esc_html__('Email sent to customer %s with subject: %s.', 'wplm'), $customer_email, $subject),
                    ['subject' => $subject, 'recipient' => $customer_email]
                );
            }

            wp_send_json_success(['message' => esc_html__('Email sent successfully.', 'wp-license-manager')]);
        } else {
            // Log error if email failed to send
            $error_message = esc_html__('Failed to send email.', 'wp-license-manager');
            error_log(sprintf(esc_html__('WPLM Email Error: Failed to send email to %s with subject %s for customer %d.', 'wp-license-manager'), $customer_email, $subject, $customer_id));
            
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $customer_id,
                    'customer_email_failed',
                    sprintf(esc_html__('Failed to send email to customer %s with subject: %s.', 'wplm'), $customer_email, $subject),
                    ['subject' => $subject, 'recipient' => $customer_email, 'error' => 'wp_mail_failure']
                );
            }
            wp_send_json_error(['message' => $error_message], 500);
        }
    }

    /**
     * Daily aggregation of customer data
     */
    public function aggregate_customer_data() {
        $customers = get_posts([
            'post_type' => $this->customer_post_type,
            'posts_per_page' => -1
        ]);

        foreach ($customers as $customer) {
            $this->update_customer_license_count($customer->ID);
        }
    }
}

