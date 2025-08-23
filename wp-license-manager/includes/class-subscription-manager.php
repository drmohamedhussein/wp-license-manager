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
     */
    // private function create_subscription_post_type() {
    //     register_post_type('wplm_subscription', [
    //         'labels' => [
    //             'name' => __('Subscriptions', 'wp-license-manager'),
    //             'singular_name' => __('Subscription', 'wp-license-manager'),
    //             'add_new' => __('Add New Subscription', 'wp-license-manager'),
    //             'add_new_item' => __('Add New Subscription', 'wp-license-manager'),
    //             'edit_item' => __('Edit Subscription', 'wp-license-manager'),
    //             'new_item' => __('New Subscription', 'wp-license-manager'),
    //             'view_item' => __('View Subscription', 'wp-license-manager'),
    //             'search_items' => __('Search Subscriptions', 'wp-license-manager'),
    //             'not_found' => __('No subscriptions found', 'wp-license-manager'),
    //             'not_found_in_trash' => __('No subscriptions found in trash', 'wp-license-manager'),
    //             'all_items' => __('All Subscriptions', 'wp-license-manager'),
    //         ],
    //         'public' => false,
    //         'show_ui' => true,
    //         'show_in_menu' => false, // Handled by our custom menu
    //         'show_in_admin_bar' => false,
    //         'show_in_nav_menus' => false,
    //         'can_export' => true,
    //         'has_archive' => false,
    //         'hierarchical' => false,
    //         'rewrite' => false,
    //         'capability_type' => ['wplm_subscription', 'wplm_subscriptions'],
    //         'map_meta_cap' => true,
    //         'supports' => ['title'],
    //         'menu_icon' => 'dashicons-update',
    //     ]);
    // }

    /**
     * Create subscription tables if they don't exist
     */
    private function maybe_create_subscription_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
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
        
        // Generate unique subscription key
        $subscription_key = $this->generate_subscription_key();
        
        // Calculate next payment date if not provided
        if (!$args['next_payment_date']) {
            $args['next_payment_date'] = $this->calculate_next_payment_date(
                $args['billing_period'], 
                $args['billing_interval'],
                $args['trial_end_date']
            );
        }
        
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $result = $wpdb->insert(
            $table_name,
            [
                'subscription_key' => $subscription_key,
                'license_id' => $args['license_id'],
                'customer_email' => $args['customer_email'],
                'product_id' => $args['product_id'],
                'status' => $args['status'],
                'billing_period' => $args['billing_period'],
                'billing_interval' => $args['billing_interval'],
                'trial_end_date' => $args['trial_end_date'],
                'next_payment_date' => $args['next_payment_date'],
                'payment_method' => $args['payment_method'],
                'payment_gateway' => $args['payment_gateway'],
                'wc_subscription_id' => $args['wc_subscription_id'],
                'subscription_meta' => wp_json_encode($args['subscription_meta'])
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        
        if ($result === false) {
            return new WP_Error('subscription_creation_failed', __('Failed to create subscription', 'wp-license-manager'));
        }
        
        $subscription_id = $wpdb->insert_id;
        
        // Log activity
        WPLM_Activity_Logger::log(
            $subscription_id,
            'subscription_created',
            sprintf('Subscription %s created for license %d', $subscription_key, $args['license_id']),
            $args
        );
        
        return $subscription_id;
    }

    /**
     * Get subscription by ID or key
     */
    public function get_subscription($id_or_key) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        if (is_numeric($id_or_key)) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id_or_key));
        } else {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE subscription_key = %s", $id_or_key));
        }
    }

    /**
     * Update subscription
     */
    public function update_subscription($subscription_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $result = $wpdb->update(
            $table_name,
            $data,
            ['id' => $subscription_id],
            null,
            ['%d']
        );
        
        if ($result !== false) {
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_updated',
                sprintf('Subscription %d updated', $subscription_id),
                $data
            );
        }
        
        return $result;
    }

    /**
     * Cancel subscription
     */
    public function cancel_subscription($subscription_id, $reason = '') {
        global $wpdb;
        
        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription) {
            return new WP_Error('subscription_not_found', __('Subscription not found', 'wp-license-manager'));
        }
        
        $result = $this->update_subscription($subscription_id, [
            'status' => 'cancelled',
            'end_date' => current_time('mysql')
        ]);
        
        if ($result !== false) {
            // Update associated license status
            if ($subscription->license_id) {
                update_post_meta($subscription->license_id, '_wplm_status', 'inactive');
            }
            
            // Log activity
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_cancelled',
                sprintf('Subscription %s cancelled. Reason: %s', $subscription->subscription_key, $reason),
                ['reason' => $reason]
            );
            
            // Send notification
            $notification_manager = new WPLM_Notification_Manager();
            $notification_manager->send_subscription_cancelled_notification(
                $subscription->customer_email,
                $subscription->subscription_key,
                $reason
            );
        }
        
        return $result;
    }

    /**
     * Process subscription renewals
     */
    public function process_subscription_renewals() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        // Get subscriptions due for renewal
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE status = 'active' 
             AND next_payment_date <= %s
             AND next_payment_date IS NOT NULL",
            current_time('mysql')
        ));
        
        foreach ($subscriptions as $subscription) {
            $this->process_subscription_renewal($subscription);
        }
    }

    /**
     * Process individual subscription renewal
     */
    private function process_subscription_renewal($subscription) {
        // If it's a WooCommerce subscription, let WC handle it
        if ($subscription->wc_subscription_id && class_exists('WCS_Subscription')) {
            return;
        }
        
        // Calculate next payment date
        $next_payment = $this->calculate_next_payment_date(
            $subscription->billing_period,
            $subscription->billing_interval,
            $subscription->next_payment_date
        );
        
        // Update subscription
        $this->update_subscription($subscription->id, [
            'last_payment_date' => $subscription->next_payment_date,
            'next_payment_date' => $next_payment
        ]);
        
        // Extend license expiry
        if ($subscription->license_id) {
            $this->extend_license_expiry($subscription->license_id, $subscription->billing_period, $subscription->billing_interval);
        }
        
        // Log activity
        WPLM_Activity_Logger::log(
            $subscription->id,
            'subscription_renewed',
            sprintf('Subscription %s renewed. Next payment: %s', $subscription->subscription_key, $next_payment),
            ['next_payment_date' => $next_payment]
        );
        
        // Send renewal notification
        $notification_manager = new WPLM_Notification_Manager();
        $notification_manager->send_subscription_renewed_notification(
            $subscription->customer_email,
            $subscription->subscription_key,
            $next_payment
        );
    }

    /**
     * Check for subscription expiry
     */
    public function check_subscription_expiry() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        // Get subscriptions that have missed payments
        $overdue_subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE status = 'active' 
             AND next_payment_date < %s
             AND next_payment_date IS NOT NULL",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        foreach ($overdue_subscriptions as $subscription) {
            $this->handle_overdue_subscription($subscription);
        }
    }

    /**
     * Handle overdue subscription
     */
    private function handle_overdue_subscription($subscription) {
        // Cancel subscription after 7 days of non-payment
        $this->cancel_subscription($subscription->id, 'Payment overdue');
        
        // Deactivate associated license
        if ($subscription->license_id) {
            update_post_meta($subscription->license_id, '_wplm_status', 'expired');
        }
    }

    /**
     * Extend license expiry
     */
    private function extend_license_expiry($license_id, $billing_period, $billing_interval) {
        $current_expiry = get_post_meta($license_id, '_wplm_expiry_date', true);
        
        // Calculate extension
        $extension_period = $this->calculate_period_in_days($billing_period, $billing_interval);
        
        if (empty($current_expiry) || $current_expiry === '') {
            // If no expiry (lifetime), set expiry from now
            $new_expiry = date('Y-m-d', strtotime("+{$extension_period} days"));
        } else {
            // Extend from current expiry
            $new_expiry = date('Y-m-d', strtotime($current_expiry . " +{$extension_period} days"));
        }
        
        update_post_meta($license_id, '_wplm_expiry_date', $new_expiry);
        update_post_meta($license_id, '_wplm_status', 'active');
    }

    /**
     * Generate unique subscription key
     */
    private function generate_subscription_key() {
        do {
            $key = 'SUB-' . strtoupper(wp_generate_password(12, false));
        } while ($this->get_subscription($key));
        
        return $key;
    }

    /**
     * Calculate next payment date
     */
    private function calculate_next_payment_date($billing_period, $billing_interval, $from_date = null) {
        if (!$from_date) {
            $from_date = current_time('mysql');
        }
        
        $days = $this->calculate_period_in_days($billing_period, $billing_interval);
        return date('Y-m-d H:i:s', strtotime($from_date . " +{$days} days"));
    }

    /**
     * Calculate period in days
     */
    private function calculate_period_in_days($billing_period, $billing_interval) {
        switch ($billing_period) {
            case 'daily':
                return $billing_interval;
            case 'weekly':
                return $billing_interval * 7;
            case 'monthly':
                return $billing_interval * 30;
            case 'yearly':
                return $billing_interval * 365;
            default:
                return 30; // Default to monthly
        }
    }

    /**
     * Get subscriptions for customer
     */
    public function get_customer_subscriptions($customer_email) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE customer_email = %s ORDER BY created_date DESC",
            $customer_email
        ));
    }

    /**
     * Get subscription statistics
     */
    public function get_subscription_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_subscriptions';
        
        $stats = [];
        
        // Total subscriptions
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        // Active subscriptions
        $stats['active'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'active'));
        
        // Cancelled subscriptions
        $stats['cancelled'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'cancelled'));
        
        // Expired subscriptions
        $stats['expired'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'expired'));
        
        // On-hold subscriptions
        $stats['on_hold'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = %s", 'on-hold'));
        
        return $stats;
    }

    /**
     * Process subscription actions from URL parameters
     */
    public function process_subscription_actions() {
        if (!isset($_GET['wplm_action']) || !isset($_GET['subscription_key'])) {
            return;
        }

        $action = sanitize_text_field($_GET['wplm_action']);
        $subscription_key = sanitize_text_field($_GET['subscription_key']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'wplm_subscription_action_' . $subscription_key)) {
            wp_die(__('Security check failed', 'wp-license-manager'));
        }

        $subscription = $this->get_subscription($subscription_key);
        if (!$subscription) {
            wp_die(__('Subscription not found', 'wp-license-manager'));
        }

        switch ($action) {
            case 'cancel':
                $this->cancel_subscription($subscription->id, 'Customer requested cancellation');
                wp_redirect(add_query_arg(['message' => 'subscription_cancelled'], remove_query_arg(['wplm_action', 'subscription_key', '_wpnonce'])));
                exit;
                
            case 'reactivate':
                $this->update_subscription($subscription->id, ['status' => 'active']);
                wp_redirect(add_query_arg(['message' => 'subscription_reactivated'], remove_query_arg(['wplm_action', 'subscription_key', '_wpnonce'])));
                exit;
        }
    }

    /**
     * AJAX Handlers
     */
    public function ajax_create_subscription() {
        check_ajax_referer('wplm_subscription_action', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $args = [
            'license_id' => intval($_POST['license_id'] ?? 0),
            'customer_email' => sanitize_email($_POST['customer_email'] ?? ''),
            'product_id' => sanitize_text_field($_POST['product_id'] ?? ''),
            'billing_period' => sanitize_text_field($_POST['billing_period'] ?? 'monthly'),
            'billing_interval' => intval($_POST['billing_interval'] ?? 1),
            'trial_end_date' => sanitize_text_field($_POST['trial_end_date'] ?? ''),
        ];
        
        $result = $this->create_subscription($args);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['subscription_id' => $result, 'message' => 'Subscription created successfully']);
    }

    public function ajax_update_subscription() {
        check_ajax_referer('wplm_subscription_action', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $data = [
            'status' => sanitize_text_field($_POST['status'] ?? ''),
            'billing_period' => sanitize_text_field($_POST['billing_period'] ?? ''),
            'billing_interval' => intval($_POST['billing_interval'] ?? 1),
        ];
        
        $result = $this->update_subscription($subscription_id, $data);
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to update subscription']);
        }
        
        wp_send_json_success(['message' => 'Subscription updated successfully']);
    }

    public function ajax_cancel_subscription() {
        check_ajax_referer('wplm_subscription_action', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        
        $result = $this->cancel_subscription($subscription_id, $reason);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['message' => 'Subscription cancelled successfully']);
    }

    public function ajax_renew_subscription() {
        check_ajax_referer('wplm_subscription_action', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $subscription = $this->get_subscription($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(['message' => 'Subscription not found']);
        }
        
        $this->process_subscription_renewal($subscription);
        
        wp_send_json_success(['message' => 'Subscription renewed successfully']);
    }
}
