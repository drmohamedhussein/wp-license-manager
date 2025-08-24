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

        // Customer license management
        add_action('wp_ajax_wplm_deactivate_customer_license', [$this, 'ajax_deactivate_customer_license']);

        // Auto-sync between WC and WPLM products
        add_action('save_post', [$this, 'sync_woocommerce_to_wplm'], 20, 2);

        // WPLM Product Data tab in WooCommerce
        add_filter('woocommerce_product_data_tabs', [$this, 'add_wplm_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'add_wplm_product_data_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_wplm_product_data']);

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
                $license_post_query = new WP_Query([
                    'post_type'      => 'wplm_license',
                    'posts_per_page' => 1,
                    'title'          => $license_key,
                    'fields'         => 'ids',
                    'exact'          => true,
                ]);
                $license_post = $license_post_query->posts[0] ?? null;

                if ($license_post) {
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
                        update_post_meta($license_post, '_wplm_status', $wplm_status);
                        
                        if (class_exists('WPLM_Activity_Logger')) {
                            WPLM_Activity_Logger::log(
                                $license_post,
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

        // Check if WooCommerce integration is enabled and if the current order status is selected for license generation.
        $integration_enabled = get_option('wplm_wc_integration_enabled', true);
        $generation_statuses = get_option('wplm_wc_license_generation_statuses', ['wc-processing', 'wc-completed']);
        $current_order_status = $order->get_status();
        
        if (!$integration_enabled || !in_array('wc-' . $current_order_status, $generation_statuses)) {
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
                    $license_post_query = new WP_Query([
                        'post_type'      => 'wplm_license',
                        'posts_per_page' => 1,
                        'title'          => $license_key,
                        'fields'         => 'ids',
                        'exact'          => true,
                    ]);
                    $license_post_id = $license_post_query->posts[0] ?? null;

                    if ($license_post_id) {
                        WPLM_Activity_Logger::log(
                            $license_post_id,
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
        try {
            // Validate inputs
            if (!$wc_product || !is_object($wc_product) || !method_exists($wc_product, 'get_name')) {
                error_log('WPLM Error: Invalid WooCommerce product object provided to create_wplm_product_from_wc');
                return false;
            }
            
            $wc_product_id = absint($wc_product_id);
            if ($wc_product_id <= 0) {
                error_log('WPLM Error: Invalid WooCommerce product ID provided to create_wplm_product_from_wc');
                return false;
            }
            
            $product_title = sanitize_text_field($wc_product->get_name());
            if (empty($product_title)) {
                error_log('WPLM Error: Empty product title for WooCommerce product ID: ' . $wc_product_id);
                return false;
            }
            
            $product_slug = sanitize_title($product_title);
            
            // Ensure unique slug
            $original_slug = $product_slug;
            $counter = 1;
            $max_attempts = 100; // Prevent infinite loops
            
            while ($counter <= $max_attempts && get_page_by_title($product_slug, OBJECT, 'wplm_product')) {
                $product_slug = $original_slug . '-' . $counter++;
            }
            
            if ($counter > $max_attempts) {
                error_log('WPLM Error: Could not generate unique slug for product after ' . $max_attempts . ' attempts');
                return false;
            }
            
            // Create WPLM product
            $wplm_product_id = wp_insert_post([
                'post_title' => $product_title,
                'post_name' => $product_slug,
                'post_type' => 'wplm_product',
                'post_status' => 'publish',
                'post_content' => wp_kses_post($wc_product->get_description())
            ]);
            
            if (is_wp_error($wplm_product_id)) {
                error_log('WPLM Error: Failed to create WPLM product. Error: ' . $wplm_product_id->get_error_message());
                return false;
            }
            
            // Set product meta
            $meta_updates = [
                '_wplm_current_version' => '1.0.0',
                '_wplm_wc_product_id' => $wc_product_id,
                '_wplm_created_from_wc' => true,
                '_wplm_created_date' => current_time('mysql')
            ];
            
            foreach ($meta_updates as $meta_key => $meta_value) {
                if (false === update_post_meta($wplm_product_id, $meta_key, $meta_value)) {
                    error_log('WPLM Error: Failed to update meta ' . $meta_key . ' for WPLM product ID: ' . $wplm_product_id);
                }
            }
            
            // Link back to WooCommerce product (using ID now)
            if (false === update_post_meta($wc_product_id, '_wplm_wc_linked_wplm_product_id', $wplm_product_id)) {
                error_log('WPLM Error: Failed to link WooCommerce product ' . $wc_product_id . ' to WPLM product ' . $wplm_product_id);
            }
            
            // Remove old slug-based link
            delete_post_meta($wc_product_id, '_wplm_wc_linked_wplm_product');
            
            // Log successful creation
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $wplm_product_id,
                    'wplm_product_created_from_wc',
                    sprintf('WPLM product created from WooCommerce product "%s" (ID: %d)', $product_title, $wc_product_id),
                    ['wc_product_id' => $wc_product_id, 'wc_product_title' => $product_title]
                );
            }
            
            return $wplm_product_id;
            
        } catch (Exception $e) {
            error_log('WPLM Error: Exception in create_wplm_product_from_wc: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate license for specific product
     */
    private function generate_license_for_product(int $wplm_product_id, string $customer_email, $order, $item) {
        try {
            // Validate inputs
            if ($wplm_product_id <= 0) {
                error_log('WPLM Error: Invalid WPLM product ID provided to generate_license_for_product: ' . $wplm_product_id);
                return false;
            }
            
            if (empty($customer_email) || !is_email($customer_email)) {
                error_log('WPLM Error: Invalid customer email provided to generate_license_for_product: ' . $customer_email);
                return false;
            }
            
            if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
                error_log('WPLM Error: Invalid order object provided to generate_license_for_product');
                return false;
            }
            
            if (!$item || !is_object($item) || !method_exists($item, 'get_id')) {
                error_log('WPLM Error: Invalid item object provided to generate_license_for_product');
                return false;
            }
            
            // Generate standardized license key
            $license_key = $this->generate_standard_license_key();
            
            // Ensure uniqueness by checking existing titles
            $attempts = 0;
            $max_attempts = 10; // Increased from 5 to 10
            while ($attempts < $max_attempts) {
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

            if ($attempts >= $max_attempts) {
                error_log('WPLM Error: Failed to generate a unique license key after ' . $max_attempts . ' attempts for WPLM product ID: ' . $wplm_product_id);
                return false;
            }
            
            // Get license settings (from WPLM product if available, fallback to global)
            $duration_type = get_post_meta($wplm_product_id, '_wplm_wc_default_duration_type', true) ?: get_option('wplm_default_duration_type', 'lifetime');
            $duration_value = get_post_meta($wplm_product_id, '_wplm_wc_default_duration_value', true) ?: get_option('wplm_default_duration_value', 1);
            $activation_limit = get_option('wplm_default_activation_limit', 1);
            
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
            
            if (is_wp_error($license_id)) {
                error_log('WPLM Error: Failed to create license post for product ID ' . $wplm_product_id . '. Error: ' . $license_id->get_error_message());
                return false;
            }
            
            // Set license meta with error checking
            $meta_updates = [
                '_wplm_status' => 'active',
                '_wplm_customer_email' => sanitize_email($customer_email),
                '_wplm_product_id' => $wplm_product_id,
                '_wplm_product_type' => 'wplm',
                '_wplm_activation_limit' => absint($activation_limit),
                '_wplm_activated_domains' => [],
                '_wplm_wc_order_id' => absint($order->get_id()),
                '_wplm_wc_item_id' => absint($item->get_id()),
                '_wplm_created_date' => current_time('mysql'),
                '_wplm_created_from_wc' => true
            ];
            
            if (!empty($expiry_date)) {
                $meta_updates['_wplm_expiry_date'] = $expiry_date;
            }
            
            // Update all meta fields with error checking
            foreach ($meta_updates as $meta_key => $meta_value) {
                if (false === update_post_meta($license_id, $meta_key, $meta_value)) {
                    error_log('WPLM Error: Failed to update meta ' . $meta_key . ' for license ID: ' . $license_id);
                }
            }
            
            // Log successful license creation
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license_id,
                    'license_generated_from_wc',
                    sprintf('License generated from WooCommerce order for product ID: %d', $wplm_product_id),
                    [
                        'wplm_product_id' => $wplm_product_id,
                        'customer_email' => $customer_email,
                        'wc_order_id' => $order->get_id(),
                        'wc_item_id' => $item->get_id(),
                        'license_key' => $license_key
                    ]
                );
            }
            
            return $license_key;
            
        } catch (Exception $e) {
            error_log('WPLM Error: Exception in generate_license_for_product for product ID ' . $wplm_product_id . ': ' . $e->getMessage());
            return false;
        }
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
     * Add WPLM tab to WooCommerce product data meta box.
     *
     * @param array $tabs Existing product data tabs.
     * @return array Modified product data tabs.
     */
    public function add_wplm_product_data_tab($tabs) {
        // Check if meta boxes should be hidden
        if (get_option('wplm_wc_hide_meta_boxes', false)) {
            return $tabs;
        }

        $tabs['wplm_licenses'] = [
            'label'    => __('WPLM Licenses', 'wp-license-manager'),
            'target'   => 'wplm_product_data',
            'class'    => ['hide_if_grouped', 'hide_if_external', 'hide_if_variable'],
            'priority' => 70,
        ];
        return $tabs;
    }

    /**
     * Display the WPLM product data panel content.
     */
    public function add_wplm_product_data_panel() {
        // Check if meta boxes should be hidden
        if (get_option('wplm_wc_hide_meta_boxes', false)) {
            return;
        }

        global $post;
        $product_id = $post->ID;

        // Get current linked WPLM product ID and license status
        $linked_wplm_product_id = get_post_meta($product_id, '_wplm_wc_linked_wplm_product_id', true);
        $is_licensed = get_post_meta($product_id, '_wplm_wc_is_licensed_product', true) === 'yes';

        echo '<div id="wplm_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group">';

        woocommerce_wp_checkbox([
            'id'            => '_wplm_wc_is_licensed_product',
            'value'         => $is_licensed ? 'yes' : 'no',
            'cbvalue'       => 'yes',
            'label'         => __('Enable WPLM Licensing', 'wp-license-manager'),
            'description'   => __('Check this box to enable WPLM license key generation and management for this product.', 'wp-license-manager'),
        ]);

        // Fetch all WPLM products for the dropdown
        $wplm_products = get_posts([
            'post_type'      => 'wplm_product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids', // Get only IDs for performance
        ]);

        $product_options = ['' => __('-- Select WPLM Product --', 'wp-license-manager')];
        if (!empty($wplm_products)) {
            foreach ($wplm_products as $wplm_p_id) {
                $product_options[$wplm_p_id] = get_the_title($wplm_p_id) . ' (ID: ' . $wplm_p_id . ')';
            }
        }

        woocommerce_wp_select([
            'id'            => '_wplm_wc_linked_wplm_product_id',
            'label'         => __('Linked WPLM Product', 'wp-license-manager'),
            'options'       => $product_options,
            'value'         => $linked_wplm_product_id,
            'description'   => __('Link this WooCommerce product to an existing WPLM product. If left unlinked and licensing is enabled, a new WPLM product will be created automatically.', 'wp-license-manager'),
            'wrapper_class' => 'form-field _wplm_wc_linked_wplm_product_id_field',
        ]);

        echo '</div>';
        echo '</div>';

        // Add JavaScript to toggle visibility based on checkbox
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function toggleWPLMLinkedProductField() {
                    if ($('#_wplm_wc_is_licensed_product').is(':checked')) {
                        $('._wplm_wc_linked_wplm_product_id_field').show();
                    } else {
                        $('._wplm_wc_linked_wplm_product_id_field').hide();
                    }
                }
                toggleWPLMLinkedProductField();
                $('#_wplm_wc_is_licensed_product').change(toggleWPLMLinkedProductField);
            });
        </script>
        <?php
    }

    /**
     * Save WPLM product data from the WooCommerce product edit screen.
     * @param int $post_id The ID of the post being saved.
     */
    public function save_wplm_product_data($post_id) {
        // Check if meta boxes should be hidden
        if (get_option('wplm_wc_hide_meta_boxes', false)) {
            return;
        }
        
        // Verify nonce for security
        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce($_POST['woocommerce_meta_nonce'], 'woocommerce_save_data')) {
            return;
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Validate post type
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        
        // Prevent autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        try {
            // Sanitize and validate the licensed product checkbox
            $is_licensed = isset($_POST['_wplm_wc_is_licensed_product']) ? 'yes' : 'no';
            
            // Update the licensed product status
            if (false === update_post_meta($post_id, '_wplm_wc_is_licensed_product', $is_licensed)) {
                error_log('WPLM Error: Failed to update licensed product status for post ID: ' . $post_id);
            }
            
            // Sanitize and validate the linked WPLM product ID
            $linked_wplm_product_id = '';
            if (isset($_POST['_wplm_wc_linked_wplm_product_id']) && !empty($_POST['_wplm_wc_linked_wplm_product_id'])) {
                $linked_wplm_product_id = absint($_POST['_wplm_wc_linked_wplm_product_id']);
                
                // Validate that the linked product exists and is a WPLM product
                if ($linked_wplm_product_id > 0) {
                    $linked_product = get_post($linked_wplm_product_id);
                    if (!$linked_product || $linked_product->post_type !== 'wplm_product') {
                        error_log('WPLM Error: Invalid linked WPLM product ID: ' . $linked_wplm_product_id . ' for post ID: ' . $post_id);
                        $linked_wplm_product_id = '';
                    }
                }
            }
            
            // Update the linked WPLM product ID
            if (false === update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $linked_wplm_product_id)) {
                error_log('WPLM Error: Failed to update linked WPLM product ID for post ID: ' . $post_id);
            }
            
            // Log the changes
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $post_id,
                    'wplm_wc_product_data_updated',
                    sprintf('WPLM product data updated for WooCommerce product (ID: %d)', $post_id),
                    [
                        'is_licensed' => $is_licensed,
                        'linked_wplm_product_id' => $linked_wplm_product_id,
                        'user_id' => get_current_user_id()
                    ]
                );
            }
            
        } catch (Exception $e) {
            error_log('WPLM Error: Exception in save_wplm_product_data for post ID ' . $post_id . ': ' . $e->getMessage());
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
    }
    
    /**
     * Add licenses menu item to My Account
     */
    public function add_my_account_menu_item($items) {
        $items['licenses'] = __('My Licenses', 'wp-license-manager');
        return $items;
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
        
        // Get all licenses for this user
        global $wpdb;
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title as license_key, pm_product.meta_value as product_id, 
                    pm_status.meta_value as status, pm_expiry.meta_value as expiry_date,
                    pm_domains.meta_value as activated_domains, pm_limit.meta_value as activation_limit
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id
             LEFT JOIN {$wpdb->postmeta} pm_product ON p.ID = pm_product.post_id AND pm_product.meta_key = '_wplm_product_id'
             LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_wplm_status'
             LEFT JOIN {$wpdb->postmeta} pm_expiry ON p.ID = pm_expiry.post_id AND pm_expiry.meta_key = '_wplm_expiry_date'
             LEFT JOIN {$wpdb->postmeta} pm_domains ON p.ID = pm_domains.post_id AND pm_domains.meta_key = '_wplm_activated_domains'
             LEFT JOIN {$wpdb->postmeta} pm_limit ON p.ID = pm_limit.post_id AND pm_limit.meta_key = '_wplm_activation_limit'
             WHERE pm_email.meta_key = '_wplm_customer_email' 
             AND pm_email.meta_value = %s 
             AND p.post_type = 'wplm_license' 
             AND p.post_status = 'publish'
             ORDER BY p.post_date DESC",
            $user_email
        ));
        
        if (empty($licenses)) {
            echo '<p>' . __('You have no licenses yet.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<div class="wplm-customer-licenses">';
        echo '<h3>' . __('Your Licenses', 'wp-license-manager') . '</h3>';
        
        foreach ($licenses as $license) {
            $activated_domains = maybe_unserialize($license->activated_domains) ?: [];
            $activation_limit = $license->activation_limit ?: 1;
            $activations_used = count($activated_domains);
            
            $status_class = $license->status === 'active' ? 'active' : 'inactive';
            $expiry_text = !empty($license->expiry_date) ? date('M j, Y', strtotime($license->expiry_date)) : __('Lifetime', 'wp-license-manager');
            
            echo '<div class="license-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 5px;">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
            echo '<h4 style="margin: 0;">' . esc_html($license->product_id ?: __('Unknown Product', 'wp-license-manager')) . '</h4>';
            echo '<span class="status-badge status-' . esc_attr($status_class) . '" style="padding: 5px 10px; border-radius: 3px; font-size: 12px; font-weight: bold; background: ' . ($status_class === 'active' ? '#28a745' : '#dc3545') . '; color: white;">' . ucfirst($license->status ?: 'inactive') . '</span>';
            echo '</div>';
            
            echo '<p><strong>' . __('License Key:', 'wp-license-manager') . '</strong></p>';
            echo '<p style="background: #f8f9fa; padding: 10px; border-radius: 3px; font-family: monospace; word-break: break-all;">' . esc_html($license->license_key) . '</p>';
            
            echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">';
            echo '<div><strong>' . __('Expires:', 'wp-license-manager') . '</strong><br>' . esc_html($expiry_text) . '</div>';
            echo '<div><strong>' . __('Activations:', 'wp-license-manager') . '</strong><br>' . $activations_used . ' / ' . $activation_limit . '</div>';
            echo '</div>';
            
            if (!empty($activated_domains)) {
                echo '<div style="margin-top: 10px;"><strong>' . __('Active Domains:', 'wp-license-manager') . '</strong>';
                echo '<ul style="margin: 5px 0 0 20px;">';
                foreach ($activated_domains as $domain) {
                    echo '<li>' . esc_html($domain) . ' <button class="deactivate-domain" data-license="' . esc_attr($license->license_key) . '" data-domain="' . esc_attr($domain) . '" style="margin-left: 10px; font-size: 11px; color: #dc3545; background: none; border: 1px solid #dc3545; padding: 2px 6px; border-radius: 3px; cursor: pointer;">' . __('Deactivate', 'wp-license-manager') . '</button></li>';
                }
                echo '</ul></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        // Add JavaScript for deactivation
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.deactivate-domain').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to deactivate this license from this domain?', 'wp-license-manager')); ?>')) {
                    return;
                }
                
                var button = $(this);
                var license = button.data('license');
                var domain = button.data('domain');
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Deactivating...', 'wp-license-manager')); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'wplm_deactivate_customer_license',
                        license_key: license,
                        domain: domain,
                        nonce: '<?php echo wp_create_nonce('wplm_customer_deactivate'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.parent().fadeOut();
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Deactivation failed', 'wp-license-manager')); ?>');
                            button.prop('disabled', false).text('<?php echo esc_js(__('Deactivate', 'wp-license-manager')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred', 'wp-license-manager')); ?>');
                        button.prop('disabled', false).text('<?php echo esc_js(__('Deactivate', 'wp-license-manager')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for customer license deactivation
     */
    public function ajax_deactivate_customer_license() {
        check_ajax_referer('wplm_customer_deactivate', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in', 'wp-license-manager')]);
        }
        
        $license_key = sanitize_text_field($_POST['license_key']);
        $domain = sanitize_text_field($_POST['domain']);
        $user = wp_get_current_user();
        
        // Find license
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            wp_send_json_error(['message' => __('License not found', 'wp-license-manager')]);
        }
        
        // Verify ownership
        $customer_email = get_post_meta($license_post->ID, '_wplm_customer_email', true);
        if ($customer_email !== $user->user_email) {
            wp_send_json_error(['message' => __('Access denied', 'wp-license-manager')]);
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
     * Sync WooCommerce product changes to WPLM
     */
    public function sync_woocommerce_to_wplm($post_id, $post) {
        // If WPLM meta boxes are hidden, assume WPLM product management is not desired for WC products,
        // so skip synchronization, unless an explicit link already exists.
        if (get_option('wplm_wc_hide_meta_boxes', false) && empty(get_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', true))) {
            return;
        }

        if ($post->post_type !== 'product' || wp_is_post_revision($post_id)) {
            return;
        }
        
        $is_licensed = get_post_meta($post_id, '_wplm_wc_is_licensed_product', true);
        if ($is_licensed !== 'yes') {
            // If not marked as licensed, ensure no WPLM product is linked (clean up)
            delete_post_meta($post_id, '_wplm_wc_linked_wplm_product_id');
            delete_post_meta($post_id, '_wplm_wc_linked_wplm_product');
            // Also, clean up WPLM product if it was created solely for this WC product
            $wplm_product_id = get_post_meta($post_id, '_wplm_wc_product_id', true); // Check WPLM product's meta
            if ($wplm_product_id) {
                $wplm_product = get_post($wplm_product_id);
                if ($wplm_product && $wplm_product->post_type === 'wplm_product') {
                    // Check if this WPLM product is exclusively linked to this WC product
                    $linked_wc_id = get_post_meta($wplm_product_id, '_wplm_wc_product_id', true);
                    if ((int)$linked_wc_id === $post_id) {
                        wp_delete_post($wplm_product_id, true); // Delete WPLM product permanently
                        if (class_exists('WPLM_Activity_Logger')) {
                            WPLM_Activity_Logger::log(
                                $post_id,
                                'wplm_product_unlinked_and_deleted',
                                sprintf('WPLM product (ID: %d) unlinked and deleted because WooCommerce product (ID: %d) is no longer licensed.', $wplm_product_id, $post_id),
                                ['wc_product_id' => $post_id, 'wplm_product_id' => $wplm_product_id]
                            );
                        }
                    }
                }
            }
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
            // If no WPLM product is linked, and the WC product is licensed, create a new one.
            // Only create if not explicitly linked to an existing WPLM product via the meta box.
            $linked_wplm_product_id_from_meta = get_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', true);
            if (empty($linked_wplm_product_id_from_meta)) {
                $wplm_product_id = $this->create_wplm_product_from_wc($post, $post_id); // Pass $post object directly
                if ($wplm_product_id) {
                    // Link the newly created WPLM product back to the WooCommerce product
                    update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $wplm_product_id);
                }
            }
        }
    }
}
