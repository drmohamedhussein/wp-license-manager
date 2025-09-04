<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced WooCommerce integration for license generation and management.
 * Supports simple and variable products with comprehensive license automation.
 */
class WPLM_WooCommerce_Integration {

    public function __construct() {
        // Core license generation
        add_action('woocommerce_order_status_completed', [$this, 'generate_license_on_order_complete']);
        add_action('woocommerce_order_status_processing', [$this, 'generate_license_on_order_complete']);
        
        // Admin order display
        add_action('woocommerce_admin_order_item_headers', [$this, 'add_license_key_admin_order_item_header']);
        add_action('woocommerce_admin_order_item_values', [$this, 'add_license_key_admin_order_item_value'], 10, 3);
        
        // Customer order display
        add_action('woocommerce_order_item_meta_end', [$this, 'display_license_key_customer_order'], 10, 3);
        add_action('woocommerce_email_order_details', [$this, 'add_license_key_to_order_emails'], 10, 4);

        // My Account integration
        add_action('init', [$this, 'add_my_account_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_my_account_menu_item']);
        add_action('woocommerce_account_licenses_endpoint', [$this, 'licenses_endpoint_content']);
        
        // Admin action to flush rewrite rules manually
        add_action('admin_init', [$this, 'maybe_flush_rewrite_rules']);

        // Customer license management
        add_action('wp_ajax_wplm_deactivate_customer_license', [$this, 'ajax_deactivate_customer_license']);
        add_action('wp_ajax_wplm_add_customer_domain', [$this, 'ajax_add_customer_domain']);

        // Enqueue scripts for my-account page
        add_action('wp_enqueue_scripts', [$this, 'enqueue_my_account_scripts']);
        
        // Add whitelabel CSS to my-account page
        add_action('wp_head', [$this, 'add_whitelabel_css_to_frontend']);

        // Auto-sync between WC and WPLM products
        add_action('save_post', [$this, 'sync_woocommerce_to_wplm'], 20, 2);

        // WooCommerce Subscriptions Integration (Centralized in WPLM_Built_In_Subscription_System)
        // if (class_exists('WCS_Subscription')) {
        //     add_action('woocommerce_subscription_status_on-hold', [$this, 'subscription_status_changed'], 10, 1);
        //     add_action('woocommerce_subscription_status_cancelled', [$this, 'subscription_status_changed'], 10, 1);
        //     add_action('woocommerce_subscription_status_active', [$this, 'subscription_status_changed'], 10, 1);
        //     add_action('woocommerce_subscription_status_expired', [$this, 'subscription_status_changed'], 10, 1);
        // }
    }

    /**
     * Handle WooCommerce Subscription status changes
     */
    public function subscription_status_changed($subscription) {
        $new_status = $subscription->get_status();
        $order = wc_get_order($subscription->get_parent_id());

        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $license_key = wc_get_order_item_meta($item_id, '_wplm_license_key', true);

            if (!empty($license_key)) {
                $license_posts = get_posts([
                    'post_type' => 'wplm_license',
                    'title' => $license_key,
                    'posts_per_page' => 1,
                    'post_status' => 'publish'
                ]);

                if (!empty($license_posts)) {
                    $license_post = $license_posts[0];
                    $wplm_status = '';
                    switch ($new_status) {
                        case 'on-hold':
                        case 'cancelled':
                        case 'expired':
                            $wplm_status = 'inactive';
                            break;
                        case 'active':
                            $wplm_status = 'active';
                            break;
                    }

                    if (!empty($wplm_status)) {
                        update_post_meta($license_post->ID, '_wplm_status', $wplm_status);
                        
                        if (class_exists('WPLM_Activity_Logger')) {
                            WPLM_Activity_Logger::log(
                                $license_post->ID,
                                'license_status_updated_by_subscription',
                                sprintf('License status updated to %s due to subscription status change to %s.', $wplm_status, $new_status),
                                ['subscription_id' => $subscription->get_id(), 'new_wc_status' => $new_status, 'new_wplm_status' => $wplm_status]
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Generate licenses when WooCommerce order is completed or processing
     */
    public function generate_license_on_order_complete(int $order_id): void {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Prevent duplicate generation
        $licenses_generated = get_post_meta($order_id, '_wplm_licenses_generated', true);
        if ($licenses_generated) {
            return;
        }

        $customer_email = $order->get_billing_email();
        $generated_licenses = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            // Skip if license already exists (for this item quantity or individually)
            $existing_license_key_meta = wc_get_order_item_meta($item_id, '_wplm_license_key', true);
            $existing_license_keys_meta = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
            
            if (!empty($existing_license_key_meta) || (!empty($existing_license_keys_meta) && is_array($existing_license_keys_meta))) {
                continue;
            }

            // Only process virtual, downloadable products
            if (!$product || !$product->is_virtual() || !$product->is_downloadable()) {
                continue;
            }
            
            // Check if it's a subscription product and if this is a renewal order
            if (class_exists('WCS_Subscription') && 
                WC_Subscriptions_Product::is_subscription($product->get_id()) && 
                wcs_order_contains_renewal($order))
            {
                // If it's a renewal order for a subscription product, skip license generation.
                // The WPLM_Built_In_Subscription_System handles license extension for renewals.
                continue;
            }

            // Handle both simple and variable products
            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();
            $effective_product_id = $parent_id > 0 ? $parent_id : $product_id;
            
            // Check if this product is marked as licensed
            $is_licensed = get_post_meta($effective_product_id, '_wplm_wc_is_licensed_product', true);
            if ($is_licensed !== 'yes') {
                continue;
            }
            
            // Get or create WPLM product
            $wplm_product_id = get_post_meta($effective_product_id, '_wplm_wc_linked_wplm_product_id', true);
            if (empty($wplm_product_id)) {
                $wplm_product_id = $this->create_wplm_product_from_wc($product, $effective_product_id);
            }
            
            if (empty($wplm_product_id)) {
                continue;
            }
            
            // Generate license for each quantity
            $quantity = $item->get_quantity();
            for ($i = 0; $i < $quantity; $i++) {
                $license_key = $this->generate_license_for_product($wplm_product_id, $customer_email, $order, $item);
                if ($license_key) {
                    $generated_licenses[] = $license_key;
                    
                    // Store license key in order item meta
                    if ($i === 0) {
                        wc_add_order_item_meta($item_id, '_wplm_license_key', $license_key);
                    } else {
                        // For multiple quantities, store as array
                        $existing_keys = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
                        if (!is_array($existing_keys)) {
                            $existing_keys = !empty($existing_keys) ? [$existing_keys] : [];
                        }
                        $existing_keys[] = $license_key;
                        wc_update_order_item_meta($item_id, '_wplm_license_keys', $existing_keys);
                    }
                }
            }
        }
        
        // Mark order as processed and log activity
        if (!empty($generated_licenses)) {
            update_post_meta($order_id, '_wplm_licenses_generated', current_time('mysql'));
            update_post_meta($order_id, '_wplm_generated_license_keys', $generated_licenses);
            
            // Log activity
            if (class_exists('WPLM_Activity_Logger')) {
                foreach ($generated_licenses as $license_key) {
                    $license_posts = get_posts([
                        'post_type' => 'wplm_license',
                        'title' => $license_key,
                        'posts_per_page' => 1,
                        'post_status' => 'publish'
                    ]);
                    if (!empty($license_posts)) {
                        $license_post = $license_posts[0];
                        WPLM_Activity_Logger::log(
                            $license_post->ID,
                            'license_generated_from_wc_order',
                            sprintf('License generated from WooCommerce order #%d for customer %s', $order_id, $customer_email),
                            ['order_id' => $order_id, 'customer_email' => $customer_email]
                        );
                    }
                }
            }
            
            // Send email notification
            $this->maybe_send_license_email($order, $generated_licenses);
        }
    }
    
    /**
     * Create WPLM product from WooCommerce product
     */
    private function create_wplm_product_from_wc($wc_product, $wc_product_id) {
        // Ensure we have a WC_Product instance (guard against WP_Post passed in)
        if (!$wc_product || !is_object($wc_product) || !method_exists($wc_product, 'get_name')) {
            if (function_exists('wc_get_product')) {
                $wc_product = wc_get_product($wc_product_id);
            } else {
                $wc_product = null;
            }
        }

        if (!$wc_product || !method_exists($wc_product, 'get_name')) {
            return 0;
        }

        $product_title = $wc_product->get_name();
        
        // Add WOO prefix to distinguish from WPLM store products
        $display_title = 'WOO ' . $product_title;
        $product_slug = sanitize_title($product_title);
        
        // First, check if a WPLM product already exists for this WC product by ID
        $existing_wplm_product_id = get_post_meta($wc_product_id, '_wplm_wc_linked_wplm_product_id', true);
        if (!empty($existing_wplm_product_id)) {
            $existing_product = get_post($existing_wplm_product_id);
            if ($existing_product && $existing_product->post_type === 'wplm_product') {
                // Update existing product instead of creating new one
                wp_update_post([
                    'ID' => $existing_wplm_product_id,
                    'post_title' => $display_title,
                    'post_content' => $wc_product->get_description()
                ]);
                return $existing_wplm_product_id;
            }
        }
        
        // Check for existing WPLM product by slug (backward compatibility)
        $existing_by_slug = get_posts([
            'post_type' => 'wplm_product',
            'name' => $product_slug,
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);
        
        if (!empty($existing_by_slug)) {
            $existing_product = $existing_by_slug[0];
            // Link this existing product to the WC product
            update_post_meta($wc_product_id, '_wplm_wc_linked_wplm_product_id', $existing_product->ID);
            update_post_meta($existing_product->ID, '_wplm_wc_product_id', $wc_product_id);
            update_post_meta($existing_product->ID, '_wplm_product_source', 'woocommerce');
            
            // Update the title to include WOO prefix if not already present
            if (!str_starts_with($existing_product->post_title, 'WOO ')) {
                wp_update_post([
                    'ID' => $existing_product->ID,
                    'post_title' => $display_title
                ]);
            }
            
            return $existing_product->ID;
        }
        
        // Create WPLM product with the same slug as WooCommerce product
        $wplm_product_id = wp_insert_post([
            'post_title' => $display_title,
            'post_name' => $product_slug, // Use the same slug as WooCommerce product
            'post_type' => 'wplm_product',
            'post_status' => 'publish',
            'post_content' => $wc_product->get_description()
        ]);
        
        if (!is_wp_error($wplm_product_id)) {
            // Set product meta
            update_post_meta($wplm_product_id, '_wplm_current_version', '1.0.0');
            update_post_meta($wplm_product_id, '_wplm_wc_product_id', $wc_product_id); // Store WC product ID on WPLM product
            update_post_meta($wplm_product_id, '_wplm_product_source', 'woocommerce'); // Mark as WooCommerce product
            update_post_meta($wplm_product_id, '_wplm_product_id', $product_slug); // Set the product ID to match WooCommerce slug
            
            // Link back to WooCommerce product (using ID now)
            update_post_meta($wc_product_id, '_wplm_wc_linked_wplm_product_id', $wplm_product_id);
            delete_post_meta($wc_product_id, '_wplm_wc_linked_wplm_product'); // Remove old slug-based link
            
            return $wplm_product_id; // Return the WPLM product ID
        }
        
        return false;
    }
    
    /**
     * Generate license for specific product
     */
    private function generate_license_for_product(int $wplm_product_id, string $customer_email, $order, $item) {
        // Generate standardized license key
        $license_key = $this->generate_standard_license_key();
        
        // Ensure uniqueness by checking existing titles
        $attempts = 0;
        while ($attempts < 5) {
            $existing_license = new WP_Query([
                'post_type'      => 'wplm_license',
                'posts_per_page' => 1,
                'title'          => $license_key,
                'fields'         => 'ids',
                'exact'          => true,
            ]);
            if (!$existing_license->have_posts()) {
                break;
            }
            $attempts++;
            $license_key = $this->generate_standard_license_key();
        }

        if ($attempts === 5) {
            error_log('WPLM Error: Failed to generate a unique license key after multiple attempts for WPLM product ID: ' . $wplm_product_id);
            return false;
        }
        
        // Get license settings (from WPLM product if available, fallback to global)
        $duration_type = get_post_meta($wplm_product_id, '_wplm_wc_default_duration_type', true) ?: get_option('wplm_default_duration_type', 'lifetime');
        $duration_value = get_post_meta($wplm_product_id, '_wplm_wc_default_duration_value', true) ?: get_option('wplm_default_duration_value', 1);
        $activation_limit = get_option('wplm_default_activation_limit', 1);
        
        // Check if email validation is required for this WooCommerce product
        $wc_product_id = get_post_meta($wplm_product_id, '_wplm_wc_product_id', true);
        $require_email_validation = '';
        if ($wc_product_id) {
            $require_email_validation = get_post_meta($wc_product_id, '_wplm_wc_require_email_validation', true);
        }
        
        // Calculate expiry date
        $expiry_date = '';
        if ($duration_type !== 'lifetime') {
            $expiry_date = $this->calculate_expiry_date($duration_type, $duration_value);
        }
        
        // Create license post
        $license_id = wp_insert_post([
            'post_title' => $license_key,
            'post_type' => 'wplm_license',
            'post_status' => 'publish'
        ]);
        
        if (!is_wp_error($license_id)) {
            // Get the product slug from the WPLM product
            $product_slug = get_post_meta($wplm_product_id, '_wplm_product_id', true);
            
            // Set license meta
            update_post_meta($license_id, '_wplm_status', 'active');
            update_post_meta($license_id, '_wplm_customer_email', $customer_email);
            update_post_meta($license_id, '_wplm_product_id', $product_slug); // Store product slug instead of WPLM product ID
            update_post_meta($license_id, '_wplm_product_type', 'wplm'); // Explicitly set product type as WPLM
            update_post_meta($license_id, '_wplm_activation_limit', $activation_limit);
            update_post_meta($license_id, '_wplm_activated_domains', []);
            update_post_meta($license_id, '_wplm_wc_order_id', $order->get_id());
            update_post_meta($license_id, '_wplm_wc_item_id', $item->get_id());
            
            // Set email validation requirement
            if ($require_email_validation === '1') {
                update_post_meta($license_id, '_wplm_require_email_validation', '1');
            }
            
            if (!empty($expiry_date)) {
                update_post_meta($license_id, '_wplm_expiry_date', $expiry_date);
            }
            
            return $license_key;
        }
        
        return false;
    }
    
    /**
     * Generate standardized license key
     */
    private function generate_standard_license_key() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key_parts = [];
        
        for ($i = 0; $i < 5; $i++) {
            $part = '';
            for ($j = 0; $j < 4; $j++) {
                $part .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $key_parts[] = $part;
        }
        
        return implode('-', $key_parts);
    }
    
    /**
     * Calculate expiry date based on duration
     */
    private function calculate_expiry_date($duration_type, $duration_value) {
        $current_time = current_time('timestamp');
        
        switch ($duration_type) {
            case 'days':
                $expiry_timestamp = strtotime("+{$duration_value} days", $current_time);
                break;
            case 'months':
                $expiry_timestamp = strtotime("+{$duration_value} months", $current_time);
                break;
            case 'years':
                $expiry_timestamp = strtotime("+{$duration_value} years", $current_time);
                break;
            default:
                return '';
        }
        
        return date('Y-m-d', $expiry_timestamp);
    }
    
    /**
     * Send license email notification
     */
    private function maybe_send_license_email($order, $licenses) {
        if (class_exists('WPLM_Notification_Manager')) {
            $notification_manager = new WPLM_Notification_Manager();
            foreach ($licenses as $license_key) {
                $notification_manager->send_license_delivery_email($order->get_billing_email(), $license_key, $order->get_id(), $order);
            }
        }
    }
    
    /**
     * Add license key admin header in order details
     */
    public function add_license_key_admin_order_item_header() {
        echo '<th class="item_license_key">' . __('License Key', 'wp-license-manager') . '</th>';
    }
    
    /**
     * Display license key in admin order item values
     */
    public function add_license_key_admin_order_item_value($product, $item, $item_id) {
        $license_key = wc_get_order_item_meta($item_id, '_wplm_license_key', true);
        $license_keys = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
        
        echo '<td class="item_license_key">';
        if (!empty($license_key)) {
            echo '<code>' . esc_html($license_key) . '</code>';
        }
        if (!empty($license_keys) && is_array($license_keys)) {
            foreach ($license_keys as $key) {
                echo '<br><code>' . esc_html($key) . '</code>';
            }
        }
        if (empty($license_key) && empty($license_keys)) {
            echo '—';
        }
        echo '</td>';
    }
    
    /**
     * Display license key to customer in order details
     */
    public function display_license_key_customer_order($item_id, $item, $order) {
        if (!is_admin()) {
            $license_key = wc_get_order_item_meta($item_id, '_wplm_license_key', true);
            $license_keys = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
            
            if (!empty($license_key) || !empty($license_keys)) {
                echo '<div class="wplm-license-keys" style="margin-top: 10px;">';
                echo '<strong>' . __('License Key(s):', 'wp-license-manager') . '</strong><br>';
                
                if (!empty($license_key)) {
                    echo '<code style="background: #f0f0f0; padding: 5px; display: inline-block; margin: 2px 0;">' . esc_html($license_key) . '</code><br>';
                }
                
                if (!empty($license_keys) && is_array($license_keys)) {
                    foreach ($license_keys as $key) {
                        echo '<code style="background: #f0f0f0; padding: 5px; display: inline-block; margin: 2px 0;">' . esc_html($key) . '</code><br>';
                    }
                }
                echo '</div>';
            }
        }
    }
    
    /**
     * Add license keys to order emails
     */
    public function add_license_key_to_order_emails($order, $sent_to_admin, $plain_text, $email) {
        if ($email->id !== 'customer_completed_order' && $email->id !== 'customer_invoice') {
            return;
        }
        
        $has_licenses = false;
        foreach ($order->get_items() as $item_id => $item) {
            $license_key = wc_get_order_item_meta($item_id, '_wplm_license_key', true);
            $license_keys = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
            
            if (!empty($license_key) || !empty($license_keys)) {
                $has_licenses = true;
                break;
            }
        }
        
        if (!$has_licenses) {
            return;
        }
        
        if ($plain_text) {
            echo "\n" . __('LICENSE KEYS:', 'wp-license-manager') . "\n";
            echo str_repeat('-', 20) . "\n";
        } else {
            echo '<h3>' . __('Your License Keys', 'wp-license-manager') . '</h3>';
            echo '<table style="border-collapse: collapse; width: 100%; margin-bottom: 20px;">';
        }
        
        foreach ($order->get_items() as $item_id => $item) {
            $license_key = wc_get_order_item_meta($item_id, '_wplm_license_key', true);
            $license_keys = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
            
            if (!empty($license_key) || !empty($license_keys)) {
                $product_name = $item->get_name();
                
                if ($plain_text) {
                    echo $product_name . ":\n";
                    if (!empty($license_key)) {
                        echo "  " . $license_key . "\n";
                    }
                    if (!empty($license_keys) && is_array($license_keys)) {
                        foreach ($license_keys as $key) {
                            echo "  " . $key . "\n";
                        }
                    }
                    echo "\n";
                } else {
                    echo '<tr>';
                    echo '<td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">' . esc_html($product_name) . '</td>';
                    echo '<td style="border: 1px solid #ddd; padding: 8px;">';
                    
                    if (!empty($license_key)) {
                        echo '<code style="background: #f5f5f5; padding: 3px 6px; margin: 2px; display: inline-block;">' . esc_html($license_key) . '</code>';
                    }
                    
                    if (!empty($license_keys) && is_array($license_keys)) {
                        foreach ($license_keys as $key) {
                            echo '<br><code style="background: #f5f5f5; padding: 3px 6px; margin: 2px; display: inline-block;">' . esc_html($key) . '</code>';
                        }
                    }
                    echo '</td>';
                    echo '</tr>';
                }
            }
        }
        
        if (!$plain_text) {
            echo '</table>';
            echo '<p><small>' . __('Keep your license keys safe! You can also view them in your account dashboard.', 'wp-license-manager') . '</small></p>';
        }
    }
    
    /**
     * Add My Account endpoint for licenses
     */
    public function add_my_account_endpoint() {
        add_rewrite_endpoint('licenses', EP_ROOT | EP_PAGES);
        
        // Flush rewrite rules if this is a new endpoint
        if (!get_option('wplm_licenses_endpoint_flushed')) {
            flush_rewrite_rules();
            update_option('wplm_licenses_endpoint_flushed', true);
        }
    }
    
    /**
     * Add licenses menu item to My Account
     */
    public function add_my_account_menu_item($items) {
        $items['licenses'] = __('My Licenses', 'wp-license-manager');
        return $items;
    }
    
    /**
     * Maybe flush rewrite rules if needed
     */
    public function maybe_flush_rewrite_rules() {
        // Check if we need to flush rewrite rules
        if (isset($_GET['wplm_flush_rewrite_rules']) && current_user_can('manage_options')) {
            flush_rewrite_rules();
            update_option('wplm_licenses_endpoint_flushed', true);
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=account&wplm_flushed=1'));
            exit;
        }
        
        // Debug: Check if endpoint is registered
        if (isset($_GET['wplm_debug_endpoints']) && current_user_can('manage_options')) {
            global $wp_rewrite;
            $endpoints = $wp_rewrite->endpoints;
            echo '<pre>';
            echo "Registered endpoints:\n";
            foreach ($endpoints as $endpoint) {
                if (strpos($endpoint[0], 'licenses') !== false) {
                    echo "Found licenses endpoint: " . print_r($endpoint, true) . "\n";
                }
            }
            echo '</pre>';
            exit;
        }
    }
    
    /**
     * Display licenses in My Account page
     */
    public function licenses_endpoint_content() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user = wp_get_current_user();
        $user_email = $user->user_email;
        
        // Use WooCommerce template system
        $licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_wplm_customer_email',
                    'value' => $user_email,
                    'compare' => '='
                ]
            ],
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        // Load the template
        wc_get_template('myaccount/my-licenses.php', [
            'licenses_query' => $licenses_query
        ], '', WPLM_PLUGIN_DIR . 'templates/');
    }
    
    /**
     * AJAX handler for customer license deactivation
     */
    public function ajax_deactivate_customer_license() {
        check_ajax_referer('wplm_customer_deactivate_license', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in', 'wp-license-manager')]);
        }
        
        $license_key = sanitize_text_field($_POST['license_key']);
        $domain = sanitize_text_field($_POST['domain']);
        $user = wp_get_current_user();
        
        // Find license using WP_Query instead of deprecated get_page_by_title
        $license_posts = get_posts([
            'post_type' => 'wplm_license',
            'title' => $license_key,
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);
        
        if (empty($license_posts)) {
            wp_send_json_error(['message' => __('License not found', 'wp-license-manager')]);
        }
        
        $license_post = $license_posts[0];
        
        // Verify ownership
        $customer_email = get_post_meta($license_post->ID, '_wplm_customer_email', true);
        if ($customer_email !== $user->user_email) {
            wp_send_json_error(['message' => __('Access denied', 'wp-license-manager')]);
        }
        
        // Check if admin has set override domains (prevents client-side changes)
        $admin_override_domains = get_post_meta($license_post->ID, '_wplm_admin_override_domains', true);
        if (!empty($admin_override_domains) && is_array($admin_override_domains) && count($admin_override_domains) > 0) {
            wp_send_json_error(['message' => __('Domain management is controlled by admin for this license', 'wp-license-manager')]);
        }
        
        // Remove domain from activated domains
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        $updated_domains = array_diff($activated_domains, [$domain]);
        update_post_meta($license_post->ID, '_wplm_activated_domains', $updated_domains);
        
        // Log activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_post->ID,
                'license_deactivated_by_customer',
                sprintf('License deactivated from domain %s by customer', $domain),
                ['domain' => $domain, 'customer_email' => $user->user_email]
            );
        }
        
        wp_send_json_success(['message' => __('License deactivated successfully', 'wp-license-manager')]);
    }

    /**
     * AJAX handler for customer adding a domain to their license
     */
    public function ajax_add_customer_domain() {
        check_ajax_referer('wplm_customer_add_domain', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in', 'wp-license-manager')]);
        }

        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        $domain_input = sanitize_text_field($_POST['domain'] ?? '');
        $user = wp_get_current_user();

        if (empty($license_key) || empty($domain_input)) {
            wp_send_json_error(['message' => __('License key and domain are required', 'wp-license-manager')]);
        }

        // Normalize domain (strip protocol, path, www)
        $domain = preg_replace('#^https?://#', '', trim($domain_input));
        $domain = preg_replace('#/.*$#', '', $domain); // keep host only
        $domain = preg_replace('/^www\./', '', $domain); // remove www prefix
        $domain = strtolower($domain);

        // Basic domain validation
        if (!preg_match('/^([a-z0-9-]+\.)*[a-z0-9-]+\.[a-z]{2,}$/i', $domain)) {
            wp_send_json_error(['message' => __('Invalid domain format', 'wp-license-manager')]);
        }

        // Find license using WP_Query instead of deprecated get_page_by_title
        $license_posts = get_posts([
            'post_type' => 'wplm_license',
            'title' => $license_key,
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);
        
        if (empty($license_posts)) {
            wp_send_json_error(['message' => __('License not found', 'wp-license-manager')]);
        }
        
        $license_post = $license_posts[0];

        // Verify ownership
        $customer_email = get_post_meta($license_post->ID, '_wplm_customer_email', true);
        if (strtolower($customer_email) !== strtolower($user->user_email)) {
            wp_send_json_error(['message' => __('Access denied', 'wp-license-manager')]);
        }

        // Check if domain validation is required for this license/product
        $require_domain_validation = get_post_meta($license_post->ID, '_wplm_require_domain_validation', true);
        if ($require_domain_validation !== '1') {
            wp_send_json_error(['message' => __('Domain validation is not enabled for this license', 'wp-license-manager')]);
        }
        
        // Check if admin has set override domains (prevents client-side changes)
        $admin_override_domains = get_post_meta($license_post->ID, '_wplm_admin_override_domains', true);
        if (!empty($admin_override_domains) && is_array($admin_override_domains) && count($admin_override_domains) > 0) {
            wp_send_json_error(['message' => __('Domain management is controlled by admin for this license', 'wp-license-manager')]);
        }

        $activation_limit = (int) get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];

        // Already present
        if (in_array($domain, $activated_domains, true)) {
            wp_send_json_success(['message' => __('Domain already added', 'wp-license-manager'), 'domains' => $activated_domains]);
        }

        // Enforce limit (allow -1 as unlimited)
        if ($activation_limit !== -1 && count($activated_domains) >= $activation_limit) {
            wp_send_json_error(['message' => __('Activation limit reached for this license', 'wp-license-manager')]);
        }

        $activated_domains[] = $domain;
        update_post_meta($license_post->ID, '_wplm_activated_domains', $activated_domains);

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_post->ID,
                'license_domain_added_by_customer',
                sprintf('Domain %s added by customer', $domain),
                ['domain' => $domain, 'customer_email' => $user->user_email]
            );
        }

        wp_send_json_success(['message' => __('Domain added', 'wp-license-manager'), 'domains' => $activated_domains]);
    }
    
    /**
     * Sync WooCommerce product changes to WPLM
     */
    public function sync_woocommerce_to_wplm($post_id, $post) {
        if ($post->post_type !== 'product' || wp_is_post_revision($post_id)) {
            return;
        }
        
        $is_licensed = get_post_meta($post_id, '_wplm_wc_is_licensed_product', true);
        if ($is_licensed !== 'yes') {
            return;
        }
        
        // First, try to find a linked WPLM product by its stored ID
        $linked_wplm_product_id = get_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', true);
        $wplm_product_post = null;

        if (!empty($linked_wplm_product_id)) {
            $wplm_product_post = get_post($linked_wplm_product_id);
            // Verify it's a wplm_product post type
            if ($wplm_product_post && $wplm_product_post->post_type !== 'wplm_product') {
                $wplm_product_post = null;
            }
        }

        // If not found by ID, try to find by existing slug link (for backward compatibility / initial setup)
        if (!$wplm_product_post) {
            $wplm_product_slug = get_post_meta($post_id, '_wplm_wc_linked_wplm_product', true);
            if (!empty($wplm_product_slug)) {
                $wplm_product_posts = get_posts([
                    'post_type' => 'wplm_product',
                    'name' => $wplm_product_slug,
                    'posts_per_page' => 1,
                    'post_status' => 'publish',
                ]);
                $wplm_product_post = $wplm_product_posts[0] ?? null;
                // If found by slug, update the WC product to link by ID for future syncs
                if ($wplm_product_post) {
                    update_post_meta($post->ID, '_wplm_wc_linked_wplm_product_id', $wplm_product_post->ID);
                    delete_post_meta($post->ID, '_wplm_wc_linked_wplm_product'); // Remove old slug-based link
                }
            }
        }
        
        // If a WPLM product is found or newly created, update it
        if ($wplm_product_post) {
            // Update WPLM product title and content to match WC product
            wp_update_post([
                'ID' => $wplm_product_post->ID,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content
            ]);
            
            // Sync version if available
            $version = get_post_meta($post_id, '_wplm_wc_current_version', true);
            if (!empty($version)) {
                update_post_meta($wplm_product_post->ID, '_wplm_current_version', $version);
            }

            // Ensure WPLM product also stores its linked WC product ID
            update_post_meta($wplm_product_post->ID, '_wplm_wc_product_id', $post_id);
        } else {
            // If no WPLM product is linked, check if one exists with the same slug
            $wc_product = wc_get_product($post_id);
            if ($wc_product) {
                $product_slug = sanitize_title($wc_product->get_name());
                
                // Check for existing WPLM product by slug
                $existing_wplm_product = get_posts([
                    'post_type' => 'wplm_product',
                    'name' => $product_slug,
                    'posts_per_page' => 1,
                    'post_status' => 'publish'
                ]);
                
                if (!empty($existing_wplm_product)) {
                    // Use existing product
                    $existing_product = $existing_wplm_product[0];
                    update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $existing_product->ID);
                    update_post_meta($existing_product->ID, '_wplm_wc_product_id', $post_id);
                    update_post_meta($existing_product->ID, '_wplm_product_source', 'woocommerce');
                    
                    // Update title if needed
                    if (!str_starts_with($existing_product->post_title, 'WOO ')) {
                        wp_update_post([
                            'ID' => $existing_product->ID,
                            'post_title' => 'WOO ' . $wc_product->get_name()
                        ]);
                    }
                } else {
                    // If no WPLM product is linked, and the WC product is licensed, create a new one.
                    // Pass a WC_Product instance to ensure proper getters are available
                    $wplm_product_id = $this->create_wplm_product_from_wc($wc_product, $post_id);
                    if ($wplm_product_id) {
                        // Link the newly created WPLM product back to the WooCommerce product
                        update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $wplm_product_id);
                    }
                }
            }
        }
    }

    /**
     * Enqueue scripts for my-account page
     */
    public function enqueue_my_account_scripts() {
        // Only enqueue on my-account page
        if (!is_account_page()) {
            return;
        }

        // Enqueue jQuery if not already loaded
        wp_enqueue_script('jquery');

        // Enqueue the main script
        wp_enqueue_script(
            'wplm-my-account-script',
            WPLM_PLUGIN_URL . 'assets/js/script.js',
            ['jquery'],
            WPLM_VERSION,
            true
        );

        // Enqueue the CSS file
        wp_enqueue_style(
            'wplm-my-account-styles',
            WPLM_PLUGIN_URL . 'assets/css/wplm-admin-styles.css',
            [],
            WPLM_VERSION
        );

        // Localize script with necessary data
        wp_localize_script(
            'wplm-my-account-script',
            'wplm_admin_vars',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'wplm_customer_deactivate_license_nonce' => wp_create_nonce('wplm_customer_deactivate_license'),
                'wplm_customer_add_domain_nonce' => wp_create_nonce('wplm_customer_add_domain'),
            ]
        );
    }

    /**
     * Add whitelabel CSS to frontend
     */
    public function add_whitelabel_css_to_frontend() {
        // Only add on WooCommerce My Account page
        if (!is_account_page()) {
            return;
        }
        
        $options = get_option('wplm_whitelabel_options', []);
        if (empty($options)) {
            return;
        }
        
        echo '<style type="text/css" id="wplm-whitelabel-frontend">';
        echo ':root {';
        echo '--wplm-primary-color: ' . esc_attr($options['primary_color'] ?? '#5de0e6') . ';';
        echo '--wplm-secondary-color: ' . esc_attr($options['secondary_color'] ?? '#004aad') . ';';
        echo '--wplm-success-color: ' . esc_attr($options['success_color'] ?? '#28a745') . ';';
        echo '--wplm-warning-color: ' . esc_attr($options['warning_color'] ?? '#ffc107') . ';';
        echo '--wplm-danger-color: ' . esc_attr($options['danger_color'] ?? '#dc3545') . ';';
        echo '--wplm-font-white: ' . esc_attr($options['font_white'] ?? '#ffffff') . ';';
        echo '--wplm-wc-card-bg: ' . esc_attr($options['wc_card_bg'] ?? '#ffffff') . ';';
        echo '--wplm-wc-card-border: ' . esc_attr($options['wc_card_border'] ?? '#e9ecef') . ';';
        echo '}';
        echo '</style>';
    }
}
