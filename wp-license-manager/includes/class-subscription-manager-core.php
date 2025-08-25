<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subscription Manager - Core Functionality
 * Part 1 of the split WPLM_Subscription_Manager class
 */
class WPLM_Subscription_Manager_Core {

    public function __construct() {
        add_action('init', [$this, 'init']);
        
        // Cron job for subscription renewals and expiry
        add_action('wplm_process_subscription_renewals', [$this, 'process_subscription_renewals']);
        add_action('wplm_check_subscription_expiry', [$this, 'check_subscription_expiry']);
        
        // Schedule cron jobs
        if (!wp_next_scheduled('wplm_process_subscription_renewals')) {
            wp_schedule_event(time(), 'daily', 'wplm_process_subscription_renewals');
        }
        if (!wp_next_scheduled('wplm_check_subscription_expiry')) {
            wp_schedule_event(time(), 'twicedaily', 'wplm_check_subscription_expiry');
        }
    }

    /**
     * Initialize the subscription system
     */
    public function init() {
        $this->maybe_create_subscription_tables();
    }

    /**
     * Create subscription tables if they don't exist
     */
    private function maybe_create_subscription_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE `{$table_name}` (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                subscription_key varchar(255) NOT NULL,
                license_id bigint(20) NOT NULL,
                customer_email varchar(255) NOT NULL,
                product_id varchar(255) NOT NULL,
                status varchar(50) NOT NULL DEFAULT 'active',
                billing_period varchar(50) NOT NULL DEFAULT 'monthly',
                billing_interval int(11) NOT NULL DEFAULT 1,
                trial_end_date datetime DEFAULT NULL,
                next_payment_date datetime NOT NULL,
                last_payment_date datetime DEFAULT NULL,
                end_date datetime DEFAULT NULL,
                created_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                wc_subscription_id bigint(20) DEFAULT NULL,
                payment_method varchar(255) DEFAULT NULL,
                payment_gateway varchar(255) DEFAULT NULL,
                subscription_meta longtext DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY subscription_key (subscription_key),
                KEY license_id (license_id),
                KEY customer_email (customer_email),
                KEY status (status),
                KEY next_payment_date (next_payment_date)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            // Log table creation
            if (!empty($wpdb->last_error)) {
                error_log(sprintf(esc_html__('WPLM: Failed to create subscription table %s. Error: %s', 'wplm'), $table_name, $wpdb->last_error));
            } else {
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        0, // No specific object ID
                        'db_table_created',
                        sprintf(esc_html__('Subscription table %s created or updated.', 'wplm'), $table_name)
                    );
                }
            }
        }
    }

    /**
     * Create a new subscription
     */
    public function create_subscription($args) {
        global $wpdb;
        
        $defaults = [
            'license_id' => 0,
            'customer_email' => '',
            'product_id' => '',
            'status' => 'active',
            'billing_period' => 'monthly',
            'billing_interval' => 1,
            'trial_end_date' => null,
            'next_payment_date' => null,
            'payment_method' => '',
            'payment_gateway' => '',
            'wc_subscription_id' => null,
            'subscription_meta' => []
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Input validation and sanitization
        $license_id = absint($args['license_id']);
        $customer_email = sanitize_email($args['customer_email']);
        $product_id = sanitize_text_field($args['product_id']);
        $status = sanitize_key($args['status']);
        $billing_period = sanitize_key($args['billing_period']);
        $billing_interval = absint($args['billing_interval']);
        $trial_end_date = !empty($args['trial_end_date']) ? sanitize_text_field($args['trial_end_date']) : null;
        $next_payment_date_input = !empty($args['next_payment_date']) ? sanitize_text_field($args['next_payment_date']) : null;
        $payment_method = sanitize_text_field($args['payment_method']);
        $payment_gateway = sanitize_text_field($args['payment_gateway']);
        $wc_subscription_id = absint($args['wc_subscription_id']);
        $subscription_meta = is_array($args['subscription_meta']) ? $args['subscription_meta'] : [];
        
        if (empty($customer_email) || !is_email($customer_email)) {
            return new WP_Error('wplm_invalid_customer_email', esc_html__('Invalid customer email provided.', 'wplm'));
        }

        if (empty($product_id)) {
            return new WP_Error('wplm_missing_product_id', esc_html__('Product ID is required.', 'wplm'));
        }

        $allowed_statuses = ['active', 'cancelled', 'on-hold', 'pending', 'expired'];
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('wplm_invalid_status', esc_html__('Invalid subscription status.', 'wplm'));
        }

        $allowed_billing_periods = ['daily', 'weekly', 'monthly', 'yearly', 'lifetime'];
        if (!in_array($billing_period, $allowed_billing_periods, true)) {
            return new WP_Error('wplm_invalid_billing_period', esc_html__('Invalid billing period.', 'wplm'));
        }

        if ($billing_interval < 1) {
            return new WP_Error('wplm_invalid_billing_interval', esc_html__('Billing interval must be at least 1.', 'wplm'));
        }

        // Generate unique subscription key
        $subscription_key = $this->generate_subscription_key();
        
        // Calculate next payment date if not provided
        $next_payment_date = $next_payment_date_input;
        if (is_wp_error($subscription_key)) {
            return $subscription_key;
        }
        if (empty($next_payment_date)) {
            $next_payment_date = $this->calculate_next_payment_date(
                $billing_period, 
                $billing_interval,
                $trial_end_date
            );
            if (is_wp_error($next_payment_date)) {
                return $next_payment_date;
            }
        }

        if (!empty($trial_end_date) && false === strtotime($trial_end_date)) {
            return new WP_Error('wplm_invalid_trial_end_date', esc_html__('Invalid trial end date format.', 'wplm'));
        }
        if (!empty($next_payment_date) && false === strtotime($next_payment_date)) {
            return new WP_Error('wplm_invalid_next_payment_date', esc_html__('Invalid next payment date format.', 'wplm'));
        }

        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $insert_data = [
            'subscription_key' => $subscription_key,
            'license_id' => $license_id,
            'customer_email' => $customer_email,
            'product_id' => $product_id,
            'status' => $status,
            'billing_period' => $billing_period,
            'billing_interval' => $billing_interval,
            'trial_end_date' => $trial_end_date,
            'next_payment_date' => $next_payment_date,
            'payment_method' => $payment_method,
            'payment_gateway' => $payment_gateway,
            'wc_subscription_id' => $wc_subscription_id,
            'subscription_meta' => json_encode($subscription_meta)
        ];

        $result = $wpdb->insert($table_name, $insert_data);

        if (false === $result) {
            return new WP_Error('wplm_db_insert_failed', esc_html__('Failed to insert subscription into database.', 'wplm'));
        }

        $subscription_id = $wpdb->insert_id;

        // Log subscription creation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_created',
                sprintf(esc_html__('Subscription created for customer %s with product %s', 'wplm'), $customer_email, $product_id),
                [
                    'subscription_key' => $subscription_key,
                    'customer_email' => $customer_email,
                    'product_id' => $product_id,
                    'billing_period' => $billing_period,
                    'billing_interval' => $billing_interval
                ]
            );
        }

        return $subscription_id;
    }

    /**
     * Get subscription by ID or key
     */
    public function get_subscription($id_or_key) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        if (is_numeric($id_or_key)) {
            $subscription = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", absint($id_or_key))
            );
        } else {
            $subscription = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE subscription_key = %s", sanitize_text_field($id_or_key))
            );
        }
        
        if (!$subscription) {
            return false;
        }
        
        // Decode subscription meta
        if (!empty($subscription->subscription_meta)) {
            $subscription->subscription_meta = json_decode($subscription->subscription_meta, true);
        }
        
        return $subscription;
    }

    /**
     * Update subscription
     */
    public function update_subscription($subscription_id, $data) {
        global $wpdb;
        
        $subscription_id = absint($subscription_id);
        if (0 === $subscription_id) {
            return new WP_Error('wplm_invalid_subscription_id', esc_html__('Invalid subscription ID.', 'wplm'));
        }
        
        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription) {
            return new WP_Error('wplm_subscription_not_found', esc_html__('Subscription not found.', 'wplm'));
        }
        
        $update_data = [];
        $allowed_fields = [
            'status', 'billing_period', 'billing_interval', 'trial_end_date',
            'next_payment_date', 'last_payment_date', 'end_date',
            'payment_method', 'payment_gateway', 'wc_subscription_id', 'subscription_meta'
        ];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $sanitized_value = $this->sanitize_subscription_field($field, $data[$field]);
                if (is_wp_error($sanitized_value)) {
                    return $sanitized_value;
                }
                $update_data[$field] = $sanitized_value;
            }
        }
        
        if (empty($update_data)) {
            return new WP_Error('wplm_no_data_to_update', esc_html__('No valid data provided for update.', 'wplm'));
        }
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $subscription_id],
            null,
            ['%d']
        );
        
        if (false === $result) {
            return new WP_Error('wplm_update_failed', esc_html__('Failed to update subscription.', 'wplm'));
        }
        
        // Log subscription update
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_updated',
                sprintf(esc_html__('Subscription updated for customer %s', 'wplm'), $subscription->customer_email),
                ['updated_fields' => array_keys($update_data)]
            );
        }
        
        return true;
    }

    /**
     * Sanitize subscription field
     */
    private function sanitize_subscription_field(string $key, $value) {
        switch ($key) {
            case 'status':
                $allowed_statuses = ['active', 'cancelled', 'on-hold', 'pending', 'expired'];
                return in_array($value, $allowed_statuses, true) ? $value : 'active';
                
            case 'billing_period':
                $allowed_periods = ['daily', 'weekly', 'monthly', 'yearly', 'lifetime'];
                return in_array($value, $allowed_periods, true) ? $value : 'monthly';
                
            case 'billing_interval':
                $interval = absint($value);
                return $interval > 0 ? $interval : 1;
                
            case 'trial_end_date':
            case 'next_payment_date':
            case 'last_payment_date':
            case 'end_date':
                if (empty($value)) {
                    return null;
                }
                return strtotime($value) ? $value : null;
                
            case 'payment_method':
            case 'payment_gateway':
                return sanitize_text_field($value);
                
            case 'wc_subscription_id':
                return absint($value);
                
            case 'subscription_meta':
                return is_array($value) ? json_encode($value) : $value;
                
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel_subscription($subscription_id, $reason = '') {
        $subscription_id = absint($subscription_id);
        if (0 === $subscription_id) {
            return new WP_Error('wplm_invalid_subscription_id', esc_html__('Invalid subscription ID.', 'wplm'));
        }
        
        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription) {
            return new WP_Error('wplm_subscription_not_found', esc_html__('Subscription not found.', 'wplm'));
        }
        
        if ($subscription->status === 'cancelled') {
            return new WP_Error('wplm_subscription_already_cancelled', esc_html__('Subscription is already cancelled.', 'wplm'));
        }
        
        $update_data = [
            'status' => 'cancelled',
            'end_date' => current_time('mysql')
        ];
        
        $result = $this->update_subscription($subscription_id, $update_data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Log subscription cancellation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_cancelled',
                sprintf(esc_html__('Subscription cancelled for customer %s. Reason: %s', 'wplm'), $subscription->customer_email, $reason ?: 'No reason provided'),
                ['reason' => $reason]
            );
        }
        
        return true;
    }

    /**
     * Process subscription renewals
     */
    public function process_subscription_renewals() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        // Get active subscriptions that are due for renewal
        $due_subscriptions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                WHERE status = %s 
                AND next_payment_date <= %s 
                AND (end_date IS NULL OR end_date > %s)",
                'active',
                current_time('mysql'),
                current_time('mysql')
            )
        );
        
        foreach ($due_subscriptions as $subscription) {
            $this->process_subscription_renewal($subscription);
        }
        
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'subscription_renewals_processed',
                sprintf(esc_html__('Processed %d subscription renewals', 'wplm'), count($due_subscriptions)),
                ['processed_count' => count($due_subscriptions)]
            );
        }
    }

    /**
     * Process individual subscription renewal
     */
    private function process_subscription_renewal($subscription) {
        // Check if subscription is still active
        if ($subscription->status !== 'active') {
            return;
        }
        
        // Check if next payment is due
        if (strtotime($subscription->next_payment_date) > strtotime(current_time('mysql'))) {
            return;
        }
        
        // Calculate new next payment date
        $new_next_payment_date = $this->calculate_next_payment_date(
            $subscription->billing_period,
            $subscription->billing_interval,
            $subscription->next_payment_date
        );
        
        if (is_wp_error($new_next_payment_date)) {
            error_log(sprintf('WPLM: Failed to calculate next payment date for subscription %d: %s', $subscription->id, $new_next_payment_date->get_error_message()));
            return;
        }
        
        // Update subscription
        $update_data = [
            'next_payment_date' => $new_next_payment_date,
            'last_payment_date' => current_time('mysql')
        ];
        
        $result = $this->update_subscription($subscription->id, $update_data);
        
        if (is_wp_error($result)) {
            error_log(sprintf('WPLM: Failed to update subscription %d: %s', $subscription->id, $result->get_error_message()));
            return;
        }
        
        // Extend license expiry if license exists
        if (!empty($subscription->license_id)) {
            $this->extend_license_expiry(
                $subscription->license_id,
                $subscription->billing_period,
                $subscription->billing_interval
            );
        }
        
        // Log renewal
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $subscription->id,
                'subscription_renewed',
                sprintf(esc_html__('Subscription renewed for customer %s', 'wplm'), $subscription->customer_email),
                [
                    'old_next_payment_date' => $subscription->next_payment_date,
                    'new_next_payment_date' => $new_next_payment_date
                ]
            );
        }
    }

    /**
     * Check subscription expiry
     */
    public function check_subscription_expiry() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        // Get expired subscriptions
        $expired_subscriptions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                WHERE status = %s 
                AND next_payment_date IS NOT NULL 
                AND next_payment_date <= %s",
                'active',
                current_time('mysql')
            )
        );
        
        foreach ($expired_subscriptions as $subscription) {
            $this->handle_overdue_subscription($subscription);
        }
        
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'subscription_expiry_checked',
                sprintf(esc_html__('Checked %d expired subscriptions', 'wplm'), count($expired_subscriptions)),
                ['expired_count' => count($expired_subscriptions)]
            );
        }
    }

    /**
     * Handle overdue subscription
     */
    private function handle_overdue_subscription($subscription) {
        // Update subscription status to expired
        $update_data = [
            'status' => 'expired'
        ];
        
        $result = $this->update_subscription($subscription->id, $update_data);
        
        if (is_wp_error($result)) {
            error_log(sprintf('WPLM: Failed to expire subscription %d: %s', $subscription->id, $result->get_error_message()));
            return;
        }
        
        // Log expiry
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $subscription->id,
                'subscription_expired',
                sprintf(esc_html__('Subscription expired for customer %s', 'wplm'), $subscription->customer_email),
                ['expiry_date' => $subscription->next_payment_date]
            );
        }
    }

    /**
     * Extend license expiry
     */
    private function extend_license_expiry($license_id, $billing_period, $billing_interval) {
        $license_id = absint($license_id);
        if (0 === $license_id) {
            return false;
        }
        
        $license = get_post($license_id);
        if (!$license || $license->post_type !== 'wplm_license') {
            return false;
        }
        
        $current_expiry = get_post_meta($license_id, '_wplm_expiry_date', true);
        $new_expiry = $this->calculate_next_payment_date($billing_period, $billing_interval, $current_expiry);
        
        if (is_wp_error($new_expiry)) {
            return false;
        }
        
        update_post_meta($license_id, '_wplm_expiry_date', $new_expiry);
        
        // Log license extension
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_id,
                'license_extended',
                sprintf(esc_html__('License expiry extended to %s', 'wplm'), $new_expiry),
                [
                    'old_expiry' => $current_expiry,
                    'new_expiry' => $new_expiry,
                    'billing_period' => $billing_period,
                    'billing_interval' => $billing_interval
                ]
            );
        }
        
        return true;
    }

    /**
     * Generate subscription key
     */
    public function generate_subscription_key(int $length = 20): string {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key = '';
        
        for ($i = 0; $i < $length; $i++) {
            $key .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $key;
    }

    /**
     * Calculate next payment date
     */
    public function calculate_next_payment_date(string $billing_period, int $billing_interval, string $start_date = null): string|WP_Error {
        if (empty($start_date)) {
            $start_date = current_time('mysql');
        }
        
        $start_timestamp = strtotime($start_date);
        if (false === $start_timestamp) {
            return new WP_Error('wplm_invalid_start_date', esc_html__('Invalid start date format.', 'wplm'));
        }
        
        $period_days = $this->calculate_period_in_days($billing_period, $billing_interval);
        if (is_wp_error($period_days)) {
            return $period_days;
        }
        
        $next_payment_timestamp = $start_timestamp + ($period_days * 24 * 60 * 60);
        
        return date('Y-m-d H:i:s', $next_payment_timestamp);
    }

    /**
     * Calculate period in days
     */
    public function calculate_period_in_days(string $billing_period, int $billing_interval): int|WP_Error {
        if ($billing_interval < 1) {
            return new WP_Error('wplm_invalid_billing_interval', esc_html__('Billing interval must be at least 1.', 'wplm'));
        }
        
        $base_days = 0;
        switch ($billing_period) {
            case 'daily':
                $base_days = 1;
                break;
            case 'weekly':
                $base_days = 7;
                break;
            case 'monthly':
                $base_days = 30;
                break;
            case 'yearly':
                $base_days = 365;
                break;
            case 'lifetime':
                return 0; // Lifetime subscriptions don't have renewal dates
            default:
                return new WP_Error('wplm_invalid_billing_period', esc_html__('Invalid billing period.', 'wplm'));
        }
        
        return $base_days * $billing_interval;
    }

    /**
     * Get customer subscriptions
     */
    public function get_customer_subscriptions(string $customer_email): array {
        global $wpdb;
        
        $customer_email = sanitize_email($customer_email);
        if (empty($customer_email)) {
            return [];
        }
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $subscriptions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE customer_email = %s ORDER BY created_date DESC",
                $customer_email
            )
        );
        
        // Decode subscription meta for each subscription
        foreach ($subscriptions as $subscription) {
            if (!empty($subscription->subscription_meta)) {
                $subscription->subscription_meta = json_decode($subscription->subscription_meta, true);
            }
        }
        
        return $subscriptions;
    }

    /**
     * Get subscription statistics
     */
    public function get_subscription_stats(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $stats = [
            'total' => 0,
            'active' => 0,
            'cancelled' => 0,
            'expired' => 0,
            'on_hold' => 0,
            'pending' => 0
        ];
        
        // Get total count
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        // Get counts by status
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table_name} GROUP BY status"
        );
        
        foreach ($status_counts as $status_count) {
            if (isset($stats[$status_count->status])) {
                $stats[$status_count->status] = $status_count->count;
            }
        }
        
        return $stats;
    }
}