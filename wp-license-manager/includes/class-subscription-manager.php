<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages built-in subscription system for WPLM
 */
class WPLM_Subscription_Manager {

    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('wp_loaded', [$this, 'process_subscription_actions']);
        
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

        // AJAX handlers
        add_action('wp_ajax_wplm_create_subscription', [$this, 'ajax_create_subscription']);
        add_action('wp_ajax_wplm_update_subscription', [$this, 'ajax_update_subscription']);
        add_action('wp_ajax_wplm_cancel_subscription', [$this, 'ajax_cancel_subscription']);
        add_action('wp_ajax_wplm_renew_subscription', [$this, 'ajax_renew_subscription']);
    }

    /**
     * Initialize the subscription system
     */
    public function init() {
        // The CPT is now registered by WPLM_CPT_Manager, remove duplicate registration
        // $this->create_subscription_post_type();
        $this->maybe_create_subscription_tables();
    }

    /**
     * Create subscription custom post type
     * This method is no longer needed as the CPT is registered by WPLM_CPT_Manager
     * @deprecated 1.0.0
     */
    private function create_subscription_post_type() {
        // This method is intentionally left empty. The CPT is registered via WPLM_CPT_Manager.
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
            'subscription_meta' => wp_json_encode($subscription_meta)
        ];

        $format = [
            '%s', // subscription_key
            '%d', // license_id
            '%s', // customer_email
            '%s', // product_id
            '%s', // status
            '%s', // billing_period
            '%d', // billing_interval
            '%s', // trial_end_date
            '%s', // next_payment_date
            '%s', // payment_method
            '%s', // payment_gateway
            '%d', // wc_subscription_id
            '%s', // subscription_meta
        ];
        
        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            $format
        );
        
        if ($result === false) {
            error_log(sprintf(esc_html__('WPLM: Database error creating subscription: %s', 'wplm'), $wpdb->last_error));
            return new WP_Error('subscription_creation_failed', esc_html__('Failed to create subscription due to a database error.', 'wplm'));
        }
        
        $subscription_id = $wpdb->insert_id;
        
        // Log activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_created',
                sprintf(esc_html__('Subscription %1$s created for license ID %2$d.', 'wplm'), $subscription_key, $license_id),
                [ // Log sanitized data
                    'customer_email' => $customer_email,
                    'product_id' => $product_id,
                    'status' => $status,
                    'billing_period' => $billing_period,
                    'billing_interval' => $billing_interval,
                    'trial_end_date' => $trial_end_date,
                    'next_payment_date' => $next_payment_date,
                    'payment_method' => $payment_method,
                    'payment_gateway' => $payment_gateway,
                    'wc_subscription_id' => $wc_subscription_id
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
            $id = absint($id_or_key);
            if (0 === $id) {
                return null; // Invalid ID
            }
            $subscription = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table_name}` WHERE id = %d", $id));
        } else {
            $key = sanitize_text_field($id_or_key);
            if (empty($key)) {
                return null; // Invalid key
            }
            $subscription = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table_name}` WHERE subscription_key = %s", $key));
        }

        if (json_last_error() === JSON_ERROR_NONE && !empty($subscription->subscription_meta)) {
            $subscription->subscription_meta = json_decode($subscription->subscription_meta, true);
        }

        return $subscription;
    }

    /**
     * Update subscription
     */
    public function update_subscription($subscription_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';

        // Validate subscription_id
        $sanitized_subscription_id = absint($subscription_id);
        if (0 === $sanitized_subscription_id) {
            error_log(esc_html__('WPLM_Subscription_Manager: Invalid subscription ID provided for update.', 'wplm'));
            return new WP_Error('invalid_subscription_id', esc_html__('Invalid subscription ID.', 'wplm'));
        }

        // Validate and sanitize data array
        $update_data = [];
        $format = [];
        $where_format = ['%d'];

        $allowed_fields = [
            'license_id' => '%d',
            'customer_email' => '%s',
            'product_id' => '%s',
            'status' => '%s',
            'billing_period' => '%s',
            'billing_interval' => '%d',
            'trial_end_date' => '%s',
            'next_payment_date' => '%s',
            'last_payment_date' => '%s',
            'end_date' => '%s',
            'wc_subscription_id' => '%d',
            'payment_method' => '%s',
            'payment_gateway' => '%s',
            'subscription_meta' => '%s',
        ];

        foreach ($data as $key => $value) {
            if (array_key_exists($key, $allowed_fields)) {
                $update_data[$key] = $this->sanitize_subscription_field($key, $value); // Custom sanitization helper
                $format[] = $allowed_fields[$key];
            } else {
                error_log(sprintf(esc_html__('WPLM_Subscription_Manager: Attempted to update disallowed field: %s', 'wplm'), $key));
            }
        }

        if (empty($update_data)) {
            return new WP_Error('no_data_to_update', esc_html__('No valid data provided for subscription update.', 'wplm'));
        }

        // Special handling for subscription_meta if it's an array
        if (isset($update_data['subscription_meta']) && is_array($update_data['subscription_meta'])) {
            $update_data['subscription_meta'] = wp_json_encode($update_data['subscription_meta']);
        }
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $sanitized_subscription_id],
            $format,
            $where_format
        );
        
        if ($result === false) {
            error_log(sprintf(esc_html__('WPLM: Database error updating subscription ID %d: %s', 'wplm'), $sanitized_subscription_id, $wpdb->last_error));
            return new WP_Error('subscription_update_failed', esc_html__('Failed to update subscription due to a database error.', 'wplm'));
        }

        if ($result === 0) {
            return new WP_Error('no_subscription_changes', esc_html__('No changes were made to the subscription or subscription not found.', 'wplm'));
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $sanitized_subscription_id,
                'subscription_updated',
                sprintf(esc_html__('Subscription ID %d updated.', 'wplm'), $sanitized_subscription_id),
                $update_data
            );
        }
        
        return true; // Indicate success
    }

    /**
     * Sanitizes a subscription field based on its key.
     *
     * @param string $key The field key.
     * @param mixed $value The field value.
     * @return mixed The sanitized value.
     */
    private function sanitize_subscription_field(string $key, $value) {
        switch ($key) {
            case 'license_id':
            case 'billing_interval':
            case 'wc_subscription_id':
                return absint($value);
            case 'customer_email':
                return sanitize_email($value);
            case 'status':
            case 'billing_period':
                return sanitize_key($value);
            case 'trial_end_date':
            case 'next_payment_date':
            case 'last_payment_date':
            case 'end_date':
                return !empty($value) ? sanitize_text_field($value) : null;
            case 'subscription_meta':
                // For subscription_meta, we expect an array; it will be json_encoded later
                return is_array($value) ? $value : [];
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel_subscription($subscription_id, $reason = '') {
        // Validate subscription ID
        $sanitized_subscription_id = absint($subscription_id);
        if (0 === $sanitized_subscription_id) {
            return new WP_Error('invalid_subscription_id', esc_html__('Invalid subscription ID provided.', 'wplm'));
        }

        // Sanitize reason
        $sanitized_reason = sanitize_text_field($reason);
        
        $subscription = $this->get_subscription($sanitized_subscription_id);
        if (is_wp_error($subscription) || !$subscription) {
            return new WP_Error('subscription_not_found', esc_html__('Subscription not found or invalid.', 'wplm'));
        }
        
        $update_result = $this->update_subscription($sanitized_subscription_id, [
            'status' => 'cancelled',
            'end_date' => current_time('mysql')
        ]);
        
        if (is_wp_error($update_result) || $update_result === false) {
            error_log(sprintf(esc_html__('WPLM: Failed to cancel subscription ID %d. Error: %s', 'wplm'), $sanitized_subscription_id, is_wp_error($update_result) ? $update_result->get_error_message() : 'Database error.'));
            return new WP_Error('subscription_cancellation_failed', esc_html__('Failed to cancel subscription.', 'wplm'));
        }

        // Update associated license status
        if (!empty($subscription->license_id)) {
            $license_id = absint($subscription->license_id);
            if ($license_id > 0) {
                if (false === update_post_meta($license_id, '_wplm_status', 'inactive')) {
                    error_log(sprintf(esc_html__('WPLM: Failed to update license status for license ID %d after subscription cancellation.', 'wplm'), $license_id));
                }
            }
        }
        
        // Log activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $sanitized_subscription_id,
                'subscription_cancelled',
                sprintf(esc_html__('Subscription %1$s cancelled. Reason: %2$s', 'wplm'), esc_html($subscription->subscription_key), esc_html($sanitized_reason)),
                ['reason' => $sanitized_reason, 'license_id' => $subscription->license_id]
            );
        }
        
        // Send notification
        if (class_exists('WPLM_Notification_Manager')) {
            $notification_manager = new WPLM_Notification_Manager();
            // Check if the method exists before calling
            if (method_exists($notification_manager, 'send_subscription_cancelled_notification')) {
                $notification_manager->send_subscription_cancelled_notification(
                    $subscription->customer_email,
                    $subscription->subscription_key,
                    $sanitized_reason
                );
            }
        }
        
        return true; // Indicate success
    }

    /**
     * Process subscription renewals
     */
    public function process_subscription_renewals() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        // Get subscriptions due for renewal
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table_name}` 
             WHERE status = %s 
             AND next_payment_date <= %s
             AND next_payment_date IS NOT NULL",
            'active',
            current_time('mysql')
        ));
        
        if (empty($subscriptions)) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'subscription_renewal_check',
                    esc_html__('No subscriptions due for renewal.', 'wplm')
                );
            }
            return;
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'subscription_renewal_check',
                sprintf(esc_html__('Found %d subscriptions due for renewal.', 'wplm'), count($subscriptions))
            );
        }
        
        foreach ($subscriptions as $subscription) {
            $this->process_subscription_renewal($subscription);
        }
    }

    /**
     * Process individual subscription renewal
     */
    private function process_subscription_renewal($subscription) {
        // Validate subscription object
        if (!is_object($subscription) || empty($subscription->id)) {
            error_log(esc_html__('WPLM_Subscription_Manager: Invalid subscription object provided for renewal.', 'wplm'));
            return;
        }

        // If it's a WooCommerce subscription, let WC handle it
        if (!empty($subscription->wc_subscription_id) && class_exists('WCS_Subscription')) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    absint($subscription->id),
                    'subscription_renewal_skipped',
                    sprintf(esc_html__('Skipping renewal for WooCommerce subscription ID %d.', 'wplm'), absint($subscription->wc_subscription_id))
                );
            }
            return;
        }

        // Calculate next payment date
        $next_payment = $this->calculate_next_payment_date(
            $subscription->billing_period,
            $subscription->billing_interval,
            $subscription->next_payment_date
        );
        
        if (is_wp_error($next_payment)) {
            error_log(sprintf(esc_html__('WPLM: Failed to calculate next payment date for subscription ID %d. Error: %s', 'wplm'), absint($subscription->id), $next_payment->get_error_message()));
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    absint($subscription->id),
                    'subscription_renewal_failed',
                    sprintf(esc_html__('Failed to calculate next payment date for renewal: %s', 'wplm'), $next_payment->get_error_message())
                );
            }
            return;
        }

        // Update subscription
        $update_result = $this->update_subscription(absint($subscription->id), [
            'last_payment_date' => $subscription->next_payment_date,
            'next_payment_date' => $next_payment
        ]);
        
        if (is_wp_error($update_result) || $update_result === false) {
            error_log(sprintf(esc_html__('WPLM: Failed to update subscription ID %d during renewal. Error: %s', 'wplm'), absint($subscription->id), is_wp_error($update_result) ? $update_result->get_error_message() : 'Database error.'));
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    absint($subscription->id),
                    'subscription_renewal_failed',
                    sprintf(esc_html__('Subscription update failed during renewal: %s', 'wplm'), is_wp_error($update_result) ? $update_result->get_error_message() : 'Database error.')
                );
            }
            return;
        }
        
        // Extend license expiry
        if (!empty($subscription->license_id)) {
            $license_extend_result = $this->extend_license_expiry(absint($subscription->license_id), $subscription->billing_period, $subscription->billing_interval);
            if (is_wp_error($license_extend_result) || $license_extend_result === false) {
                error_log(sprintf(esc_html__('WPLM: Failed to extend license expiry for license ID %d during subscription renewal. Error: %s', 'wplm'), absint($subscription->license_id), is_wp_error($license_extend_result) ? $license_extend_result->get_error_message() : 'Unknown error.'));
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        absint($subscription->id),
                        'license_extension_failed',
                        sprintf(esc_html__('License extension failed for license ID %d during renewal: %s', 'wplm'), absint($subscription->license_id), is_wp_error($license_extend_result) ? $license_extend_result->get_error_message() : 'Unknown error.')
                    );
                }
            }
        }
        
        // Log activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                absint($subscription->id),
                'subscription_renewed',
                sprintf(esc_html__('Subscription %1$s renewed. Next payment: %2$s', 'wplm'), esc_html($subscription->subscription_key), esc_html($next_payment)),
                ['next_payment_date' => $next_payment]
            );
        }
        
        // Send renewal notification
        if (class_exists('WPLM_Notification_Manager')) {
            $notification_manager = new WPLM_Notification_Manager();
            if (method_exists($notification_manager, 'send_subscription_renewed_notification')) {
                $notification_manager->send_subscription_renewed_notification(
                    $subscription->customer_email,
                    $subscription->subscription_key,
                    $next_payment
                );
            }
        }
    }

    /**
     * Check for subscription expiry
     */
    public function check_subscription_expiry() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        // Get subscriptions that have missed payments
        $overdue_subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table_name}` 
             WHERE status = %s 
             AND next_payment_date < %s
             AND next_payment_date IS NOT NULL",
            'active',
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        if (empty($overdue_subscriptions)) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'subscription_expiry_check',
                    esc_html__('No overdue subscriptions found.', 'wplm')
                );
            }
            return;
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'subscription_expiry_check',
                sprintf(esc_html__('Found %d overdue subscriptions.', 'wplm'), count($overdue_subscriptions))
            );
        }
        
        foreach ($overdue_subscriptions as $subscription) {
            $this->handle_overdue_subscription($subscription);
        }
    }

    /**
     * Handle overdue subscription
     */
    private function handle_overdue_subscription($subscription) {
        // Validate subscription object
        if (!is_object($subscription) || empty($subscription->id)) {
            error_log(esc_html__('WPLM_Subscription_Manager: Invalid subscription object provided for overdue handling.', 'wplm'));
            return;
        }

        // Cancel subscription after 7 days of non-payment
        $cancel_result = $this->cancel_subscription(absint($subscription->id), esc_html__('Payment overdue', 'wplm'));
        
        if (is_wp_error($cancel_result) || $cancel_result === false) {
            error_log(sprintf(esc_html__('WPLM: Failed to cancel overdue subscription ID %d. Error: %s', 'wplm'), absint($subscription->id), is_wp_error($cancel_result) ? $cancel_result->get_error_message() : 'Unknown error.'));
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    absint($subscription->id),
                    'overdue_subscription_cancellation_failed',
                    sprintf(esc_html__('Failed to cancel overdue subscription: %s', 'wplm'), is_wp_error($cancel_result) ? $cancel_result->get_error_message() : 'Unknown error.')
                );
            }
            return; // Exit if cancellation failed
        }

        // Deactivate associated license
        if (!empty($subscription->license_id)) {
            $license_id = absint($subscription->license_id);
            if ($license_id > 0) {
                if (false === update_post_meta($license_id, '_wplm_status', 'expired')) {
                    error_log(sprintf(esc_html__('WPLM: Failed to update license status for license ID %d after overdue subscription.', 'wplm'), $license_id));
                    if (class_exists('WPLM_Activity_Logger')) {
                        WPLM_Activity_Logger::log(
                            absint($subscription->id),
                            'license_deactivation_failed',
                            sprintf(esc_html__('Failed to deactivate license ID %d after overdue subscription.', 'wplm'), $license_id)
                        );
                    }
                }
            }
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                absint($subscription->id),
                'subscription_overdue_handled',
                sprintf(esc_html__('Overdue subscription %s handled (cancelled).', 'wplm'), esc_html($subscription->subscription_key))
            );
        }
        
        // Optionally, send a notification for overdue subscriptions handled
        if (class_exists('WPLM_Notification_Manager')) {
            $notification_manager = new WPLM_Notification_Manager();
            if (method_exists($notification_manager, 'send_overdue_subscription_notification')) {
                $notification_manager->send_overdue_subscription_notification(
                    $subscription->customer_email,
                    $subscription->subscription_key
                );
            }
        }
    }

    /**
     * Extend license expiry
     */
    private function extend_license_expiry($license_id, $billing_period, $billing_interval) {
        // Validate license ID
        $sanitized_license_id = absint($license_id);
        if (0 === $sanitized_license_id) {
            error_log(esc_html__('WPLM_Subscription_Manager: Invalid license ID provided for extension.', 'wplm'));
            return new WP_Error('invalid_license_id', esc_html__('Invalid license ID.', 'wplm'));
        }

        // Sanitize billing period and interval
        $sanitized_billing_period = sanitize_key($billing_period);
        $sanitized_billing_interval = absint($billing_interval);
        
        $current_expiry = get_post_meta($sanitized_license_id, '_wplm_expiry_date', true);
        
        // Calculate extension
        $extension_period_days = $this->calculate_period_in_days($sanitized_billing_period, $sanitized_billing_interval);
        
        if (is_wp_error($extension_period_days)) {
            error_log(sprintf(esc_html__('WPLM: Failed to calculate extension period for license ID %d. Error: %s', 'wplm'), $sanitized_license_id, $extension_period_days->get_error_message()));
            return $extension_period_days;
        }

        if (empty($current_expiry) || $current_expiry === 'lifetime') {
            // If no expiry (or lifetime), set expiry from now
            $new_expiry_timestamp = strtotime("+{$extension_period_days} days");
        } else {
            // Extend from current expiry
            $current_expiry_timestamp = strtotime($current_expiry);
            if (false === $current_expiry_timestamp) {
                error_log(sprintf(esc_html__('WPLM: Invalid current expiry date format for license ID %d: %s', 'wplm'), $sanitized_license_id, $current_expiry));
                return new WP_Error('invalid_current_expiry', esc_html__('Invalid current expiry date format.', 'wplm'));
            }
            $new_expiry_timestamp = strtotime($current_expiry . " +{$extension_period_days} days");
        }

        if (false === $new_expiry_timestamp) {
            error_log(sprintf(esc_html__('WPLM: Failed to calculate new expiry date for license ID %d.', 'wplm'), $sanitized_license_id));
            return new WP_Error('new_expiry_calculation_failed', esc_html__('Failed to calculate new expiry date.', 'wplm'));
        }
        
        $new_expiry_date = date('Y-m-d', $new_expiry_timestamp);
        
        if (false === update_post_meta($sanitized_license_id, '_wplm_expiry_date', $new_expiry_date)) {
            error_log(sprintf(esc_html__('WPLM: Failed to update expiry date for license ID %d.', 'wplm'), $sanitized_license_id));
            return new WP_Error('expiry_date_update_failed', esc_html__('Failed to update license expiry date.', 'wplm'));
        }

        if (false === update_post_meta($sanitized_license_id, '_wplm_status', 'active')) {
            error_log(sprintf(esc_html__('WPLM: Failed to update status to active for license ID %d.', 'wplm'), $sanitized_license_id));
            return new WP_Error('status_update_failed', esc_html__('Failed to update license status to active.', 'wplm'));
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $sanitized_license_id,
                'license_expiry_extended',
                sprintf(esc_html__('License expiry extended to %s.', 'wplm'), $new_expiry_date),
                ['old_expiry' => $current_expiry, 'new_expiry' => $new_expiry_date]
            );
        }

        return true; // Indicate success
    }

    /**
     * Generate unique subscription key
     */
    public function generate_subscription_key(int $length = 20): string {
        $key = '';
        $attempts = 0;
        $max_attempts = 10; // Prevent infinite loops

        do {
            $key = wp_generate_password($length, false); // Generate a strong, non-special-character key
            $attempts++;
            if ($attempts >= $max_attempts) {
                error_log(esc_html__('WPLM_Subscription_Manager: Failed to generate a unique subscription key after multiple attempts.', 'wplm'));
                return new WP_Error('key_generation_failed', esc_html__('Failed to generate a unique subscription key.', 'wplm'));
            }
        } while ($this->get_subscription($key)); // Check for uniqueness
        
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'subscription_key_generated',
                esc_html__('New subscription key generated.', 'wplm'),
                ['key_prefix' => substr($key, 0, 5)] // Log prefix, not full key
            );
        }

        return $key;
    }

    /**
     * Calculate next payment date
     */
    public function calculate_next_payment_date(string $billing_period, int $billing_interval, string $start_date = null): string|WP_Error {
        $sanitized_billing_period = sanitize_key($billing_period);
        $sanitized_billing_interval = absint($billing_interval);

        if (0 === $sanitized_billing_interval) {
            return new WP_Error('invalid_interval', esc_html__('Billing interval must be a positive integer.', 'wplm'));
        }

        $base_date = !empty($start_date) ? $start_date : current_time('mysql');
        $timestamp = strtotime($base_date);

        if (false === $timestamp) {
            return new WP_Error('invalid_start_date', sprintf(esc_html__('Invalid start date provided: %s.', 'wplm'), esc_html($base_date)));
        }

        switch ($sanitized_billing_period) {
            case 'day':
                $date_string = "+{$sanitized_billing_interval} days";
                break;
            case 'week':
                $date_string = "+{$sanitized_billing_interval} weeks";
                break;
            case 'month':
                $date_string = "+{$sanitized_billing_interval} months";
                break;
            case 'year':
                $date_string = "+{$sanitized_billing_interval} years";
                break;
            default:
                return new WP_Error('invalid_billing_period', sprintf(esc_html__('Invalid billing period provided: %s.', 'wplm'), esc_html($sanitized_billing_period)));
        }

        $next_payment_timestamp = strtotime($date_string, $timestamp);

        if (false === $next_payment_timestamp) {
            return new WP_Error('date_calculation_failed', sprintf(esc_html__('Failed to calculate next payment date with period %s and interval %d from %s.', 'wplm'), esc_html($sanitized_billing_period), $sanitized_billing_interval, esc_html($base_date)));
        }

        return date('Y-m-d H:i:s', $next_payment_timestamp);
    }

    /**
     * Calculate the period in days.
     *
     * @param string $billing_period The billing period (e.g., 'day', 'month', 'year').
     * @param int $billing_interval The billing interval (e.g., 1, 2, 3).
     * @return int|WP_Error The period in days, or WP_Error on failure.
     */
    public function calculate_period_in_days(string $billing_period, int $billing_interval): int|WP_Error {
        $sanitized_billing_period = sanitize_key($billing_period);
        $sanitized_billing_interval = absint($billing_interval);

        if (0 === $sanitized_billing_interval) {
            return new WP_Error('invalid_interval', esc_html__('Billing interval must be a positive integer.', 'wplm'));
        }

        $days = 0;
        switch ($sanitized_billing_period) {
            case 'day':
                $days = $sanitized_billing_interval;
                break;
            case 'week':
                $days = $sanitized_billing_interval * 7;
                break;
            case 'month':
                // Approximate month to 30 days for simplicity, or use DateTime for precision
                $days = $sanitized_billing_interval * 30;
                break;
            case 'year':
                // Approximate year to 365 days for simplicity, or use DateTime for precision
                $days = $sanitized_billing_interval * 365;
                break;
            default:
                return new WP_Error('invalid_billing_period', sprintf(esc_html__('Invalid billing period provided: %s.', 'wplm'), esc_html($sanitized_billing_period)));
        }

        return $days;
    }

    /**
     * Get subscriptions for customer
     */
    public function get_customer_subscriptions(string $customer_email): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';

        // Validate and sanitize customer email
        $sanitized_customer_email = sanitize_email($customer_email);
        if (empty($sanitized_customer_email) || !is_email($sanitized_customer_email)) {
            error_log(sprintf(esc_html__('WPLM_Subscription_Manager: Invalid customer email provided for fetching subscriptions: %s.', 'wplm'), $customer_email));
            return [];
        }

        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table_name}` WHERE customer_email = %s",
            $sanitized_customer_email
        ));
        
        if (empty($subscriptions)) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'get_customer_subscriptions',
                    sprintf(esc_html__('No subscriptions found for customer: %s.', 'wplm'), esc_html($sanitized_customer_email))
                );
            }
            return [];
        }

        // Recursively sanitize subscription meta for each subscription
        foreach ($subscriptions as $subscription) {
            if (json_last_error() === JSON_ERROR_NONE && !empty($subscription->subscription_meta)) {
                $subscription->subscription_meta = json_decode($subscription->subscription_meta, true);
            }
        }

        if ($wpdb->last_error) {
            error_log(sprintf(esc_html__('WPLM: Database error fetching customer subscriptions for %s: %s', 'wplm'), esc_html($sanitized_customer_email), $wpdb->last_error));
            return [];
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
            'total_subscriptions' => 0,
            'active_subscriptions' => 0,
            'cancelled_subscriptions' => 0,
            'pending_subscriptions' => 0,
            'overdue_subscriptions' => 0,
        ];

        // Total subscriptions
        $stats['total_subscriptions'] = absint($wpdb->get_var("SELECT COUNT(id) FROM `{$table_name}`"));

        // Active subscriptions
        $stats['active_subscriptions'] = absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM `{$table_name}` WHERE status = %s", 'active')));

        // Cancelled subscriptions
        $stats['cancelled_subscriptions'] = absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM `{$table_name}` WHERE status = %s", 'cancelled')));

        // Pending subscriptions
        $stats['pending_subscriptions'] = absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM `{$table_name}` WHERE status = %s", 'pending')));

        // Overdue subscriptions (active but next_payment_date is in the past)
        $stats['overdue_subscriptions'] = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM `{$table_name}` 
             WHERE status = %s 
             AND next_payment_date < %s
             AND next_payment_date IS NOT NULL",
            'active',
            current_time('mysql')
        )));
        
        if ($wpdb->last_error) {
            error_log(sprintf(esc_html__('WPLM: Database error fetching subscription stats: %s', 'wplm'), $wpdb->last_error));
            // Optionally return a WP_Error or re-throw an exception
            // For now, returning partial/zero stats
        }

        return $stats;
    }

    /**
     * Process subscription actions from URL parameters
     */
    public function process_subscription_actions($subscription_id, string $action = '', array $data = []): bool|WP_Error {
        // Cast to int and validate subscription ID
        $subscription_id = (int) $subscription_id;
        $sanitized_subscription_id = absint($subscription_id);
        if (0 === $sanitized_subscription_id) {
            return new WP_Error('invalid_subscription_id', esc_html__('Invalid subscription ID provided.', 'wplm'));
        }

        // Sanitize and validate action
        $sanitized_action = sanitize_key($action);
        $allowed_actions = ['renew', 'cancel', 'pause', 'resume', 'update_status'];

        if (!in_array($sanitized_action, $allowed_actions, true)) {
            return new WP_Error('invalid_action', sprintf(esc_html__('Invalid action provided: %s.', 'wplm'), esc_html($sanitized_action)));
        }

        // Check capability
        if (!current_user_can('manage_wplm_subscriptions')) {
            return new WP_Error('insufficient_permissions', esc_html__('You do not have sufficient permissions to perform this action.', 'wplm'));
        }

        $subscription = $this->get_subscription($sanitized_subscription_id);
        if (is_wp_error($subscription) || !$subscription) {
            return new WP_Error('subscription_not_found', esc_html__('Subscription not found.', 'wplm'));
        }

        $result = new WP_Error('action_failed', esc_html__('Subscription action failed for unknown reason.', 'wplm'));

        switch ($sanitized_action) {
            case 'renew':
                $next_payment_date = $this->calculate_next_payment_date(
                    $subscription->billing_period,
                    $subscription->billing_interval,
                    $subscription->next_payment_date
                );

                if (is_wp_error($next_payment_date)) {
                    $result = $next_payment_date;
                    break;
                }

                $update_data = [
                    'last_payment_date' => current_time('mysql'),
                    'next_payment_date' => $next_payment_date,
                    'status' => 'active',
                ];

                $update_result = $this->update_subscription($sanitized_subscription_id, $update_data);

                if (is_wp_error($update_result) || $update_result === false) {
                    $result = is_wp_error($update_result) ? $update_result : new WP_Error('db_error', esc_html__('Database error during renewal.', 'wplm'));
                    break;
                }

                // Extend license expiry
                if (!empty($subscription->license_id)) {
                    $license_extend_result = $this->extend_license_expiry(absint($subscription->license_id), $subscription->billing_period, $subscription->billing_interval);
                    if (is_wp_error($license_extend_result) || $license_extend_result === false) {
                        error_log(sprintf(esc_html__('WPLM: Failed to extend license expiry for license ID %d during subscription renewal from action. Error: %s', 'wplm'), absint($subscription->license_id), is_wp_error($license_extend_result) ? $license_extend_result->get_error_message() : 'Unknown error.'));
                    }
                }

                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $sanitized_subscription_id,
                        'subscription_action_renew',
                        sprintf(esc_html__('Subscription %1$s renewed via action. Next payment: %2$s', 'wplm'), esc_html($subscription->subscription_key), esc_html($next_payment_date)),
                        $update_data
                    );
                }
                $result = true; // Indicate success
                break;

            case 'cancel':
                $cancel_reason = sanitize_text_field($data['reason'] ?? '');
                $cancel_result = $this->cancel_subscription($sanitized_subscription_id, $cancel_reason);
                if (is_wp_error($cancel_result) || $cancel_result === false) {
                    $result = is_wp_error($cancel_result) ? $cancel_result : new WP_Error('cancellation_failed', esc_html__('Failed to cancel subscription.', 'wplm'));
                    break;
                }
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $sanitized_subscription_id,
                        'subscription_action_cancel',
                        sprintf(esc_html__('Subscription %1$s cancelled via action. Reason: %2$s', 'wplm'), esc_html($subscription->subscription_key), esc_html($cancel_reason)),
                        ['reason' => $cancel_reason]
                    );
                }
                $result = true;
                break;

            case 'pause':
            case 'resume':
                $new_status = ('pause' === $sanitized_action) ? 'on-hold' : 'active';
                $update_result = $this->update_subscription($sanitized_subscription_id, ['status' => $new_status]);

                if (is_wp_error($update_result) || $update_result === false) {
                    $result = is_wp_error($update_result) ? $update_result : new WP_Error('db_error', sprintf(esc_html__('Database error during %s subscription.', 'wplm'), esc_html($sanitized_action)));
                    break;
                }
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $sanitized_subscription_id,
                        'subscription_action_' . $sanitized_action,
                        sprintf(esc_html__('Subscription %1$s %2$s via action.', 'wplm'), esc_html($subscription->subscription_key), esc_html($sanitized_action)),
                        ['new_status' => $new_status]
                    );
                }
                $result = true;
                break;
            
            case 'update_status':
                $new_status_input = sanitize_key($data['new_status'] ?? '');
                $allowed_statuses = ['active', 'on-hold', 'cancelled', 'pending', 'expired']; // Ensure 'expired' is also allowed for manual update

                if (!in_array($new_status_input, $allowed_statuses, true)) {
                    $result = new WP_Error('invalid_status', sprintf(esc_html__('Invalid status provided for update: %s.', 'wplm'), esc_html($new_status_input)));
                    break;
                }

                $update_result = $this->update_subscription($sanitized_subscription_id, ['status' => $new_status_input]);

                if (is_wp_error($update_result) || $update_result === false) {
                    $result = is_wp_error($update_result) ? $update_result : new WP_Error('db_error', esc_html__('Database error updating subscription status.', 'wplm'));
                    break;
                }
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $sanitized_subscription_id,
                        'subscription_action_update_status',
                        sprintf(esc_html__('Subscription %1$s status updated to %2$s via action.', 'wplm'), esc_html($subscription->subscription_key), esc_html($new_status_input)),
                        ['old_status' => $subscription->status, 'new_status' => $new_status_input]
                    );
                }
                $result = true;
                break;
        }

        return $result;
    }

    /**
     * AJAX Handlers
     */
    public function ajax_create_subscription() {
        check_ajax_referer('wplm_manage_subscriptions', 'nonce');
        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => esc_html__('You do not have sufficient permissions to create subscriptions.', 'wplm')], 403);
        }

        $data = $_POST;

        // Validate required fields explicitly
        $required_fields = ['license_id', 'customer_email', 'product_id', 'billing_period', 'billing_interval', 'next_payment_date'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                wp_send_json_error(['message' => sprintf(esc_html__('%s is required.', 'wplm'), esc_html(str_replace(['_', 'id'], [' ', ' ID'], $field)))], 400);
            }
        }

        // Additional specific validations
        if (!is_email($data['customer_email'])) {
            wp_send_json_error(['message' => esc_html__('Invalid customer email address.', 'wplm')], 400);
        }
        if (absint($data['billing_interval']) <= 0) {
            wp_send_json_error(['message' => esc_html__('Billing interval must be a positive number.', 'wplm')], 400);
        }
        // Add more validation for billing_period, dates etc. if not already handled by create_subscription
        // The `create_subscription` method already handles most sanitization and validation

        $create_result = $this->create_subscription([
            'license_id' => $data['license_id'],
            'customer_email' => $data['customer_email'],
            'product_id' => $data['product_id'],
            'status' => sanitize_key($data['status'] ?? 'active'), // Default to active
            'billing_period' => $data['billing_period'],
            'billing_interval' => $data['billing_interval'],
            'trial_end_date' => $data['trial_end_date'] ?? null,
            'next_payment_date' => $data['next_payment_date'],
            'last_payment_date' => $data['last_payment_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'wc_subscription_id' => $data['wc_subscription_id'] ?? 0,
            'payment_method' => $data['payment_method'] ?? '',
            'payment_gateway' => $data['payment_gateway'] ?? '',
            'subscription_meta' => $data['subscription_meta'] ?? [],
        ]);

        if (is_wp_error($create_result)) {
            wp_send_json_error(['message' => $create_result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => esc_html__('Subscription created successfully.', 'wplm'), 'subscription_id' => $create_result]);
    }

    public function ajax_update_subscription() {
        check_ajax_referer('wplm_manage_subscriptions', 'nonce');
        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => esc_html__('You do not have sufficient permissions to update subscriptions.', 'wplm')], 403);
        }

        $subscription_id = absint($_POST['subscription_id'] ?? 0);
        if (0 === $subscription_id) {
            wp_send_json_error(['message' => esc_html__('Subscription ID is required for update.', 'wplm')], 400);
        }

        $data_to_update = $_POST['data'] ?? [];
        if (empty($data_to_update) || !is_array($data_to_update)) {
            wp_send_json_error(['message' => esc_html__('No valid data provided for update.', 'wplm')], 400);
        }

        // Basic sanitization for incoming data, more specific validation handled by update_subscription
        $sanitized_data = [];
        foreach ($data_to_update as $key => $value) {
            // Avoid overwriting sensitive fields without explicit logic, or add specific validation
            if (in_array($key, ['subscription_key', 'created_date'], true)) {
                continue; 
            }
            $sanitized_data[$key] = $this->sanitize_subscription_field($key, $value);
        }

        // Special validation for customer_email if it's being updated
        if (isset($sanitized_data['customer_email']) && !is_email($sanitized_data['customer_email'])) {
            wp_send_json_error(['message' => esc_html__('Invalid customer email address.', 'wplm')], 400);
        }

        $update_result = $this->update_subscription($subscription_id, $sanitized_data);

        if (is_wp_error($update_result)) {
            wp_send_json_error(['message' => $update_result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => esc_html__('Subscription updated successfully.', 'wplm'), 'subscription_id' => $subscription_id]);
    }

    public function ajax_cancel_subscription() {
        check_ajax_referer('wplm_manage_subscriptions', 'nonce');
        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => esc_html__('You do not have sufficient permissions to cancel subscriptions.', 'wplm')], 403);
        }

        $subscription_id = absint($_POST['subscription_id'] ?? 0);
        if (0 === $subscription_id) {
            wp_send_json_error(['message' => esc_html__('Subscription ID is required for cancellation.', 'wplm')], 400);
        }

        $reason = sanitize_text_field($_POST['reason'] ?? '');

        $cancel_result = $this->cancel_subscription($subscription_id, $reason);

        if (is_wp_error($cancel_result)) {
            wp_send_json_error(['message' => $cancel_result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => esc_html__('Subscription cancelled successfully.', 'wplm')]);
    }

    public function ajax_renew_subscription() {
        check_ajax_referer('wplm_manage_subscriptions', 'nonce');
        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => esc_html__('You do not have sufficient permissions to renew subscriptions.', 'wplm')], 403);
        }

        $subscription_id = absint($_POST['subscription_id'] ?? 0);
        if (0 === $subscription_id) {
            wp_send_json_error(['message' => esc_html__('Subscription ID is required for renewal.', 'wplm')], 400);
        }

        $subscription = $this->get_subscription($subscription_id);
        if (is_wp_error($subscription) || !$subscription) {
            wp_send_json_error(['message' => esc_html__('Subscription not found or invalid.', 'wplm')], 404);
        }
        
        // Perform renewal logic using process_subscription_actions
        $renewal_result = $this->process_subscription_actions($subscription_id, 'renew');

        if (is_wp_error($renewal_result)) {
            wp_send_json_error(['message' => $renewal_result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => esc_html__('Subscription renewed successfully.', 'wplm'), 'subscription_id' => $subscription_id]);
    }
}
