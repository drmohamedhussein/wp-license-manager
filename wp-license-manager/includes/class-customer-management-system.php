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
        if (empty($license_data['customer_email'])) {
            return;
        }

        $existing_customer = $this->get_customer_by_email($license_data['customer_email']);
        if ($existing_customer) {
            $this->update_customer_license_count($existing_customer->ID);
            
            // Update existing customer's license keys
            $existing_license_keys = get_post_meta($existing_customer->ID, '_wplm_license_keys', true) ?: [];
            if (!in_array($license_data['license_key'], $existing_license_keys)) {
                $existing_license_keys[] = $license_data['license_key'];
                update_post_meta($existing_customer->ID, '_wplm_license_keys', $existing_license_keys);
            }
            
            // Update last activity
            update_post_meta($existing_customer->ID, '_wplm_last_activity', current_time('mysql'));
            
            return $existing_customer->ID;
        }

        // Create new customer
        $customer_name = !empty($license_data['customer_name']) ? $license_data['customer_name'] : $license_data['customer_email'];
        
        $customer_id = wp_insert_post([
            'post_type' => $this->customer_post_type,
            'post_title' => $customer_name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ]);

        if (!is_wp_error($customer_id)) {
            // Store customer metadata
            update_post_meta($customer_id, '_wplm_customer_email', $license_data['customer_email']);
            update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
            update_post_meta($customer_id, '_wplm_first_license_date', current_time('mysql'));
            update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
            update_post_meta($customer_id, '_wplm_total_licenses', 1);
            update_post_meta($customer_id, '_wplm_active_licenses', 1);
            update_post_meta($customer_id, '_wplm_customer_status', 'active');
            update_post_meta($customer_id, '_wplm_customer_source', 'license_generation');
            
            // Initialize arrays
            update_post_meta($customer_id, '_wplm_license_keys', [$license_data['license_key']]);
            update_post_meta($customer_id, '_wplm_communication_log', []);
            update_post_meta($customer_id, '_wplm_tags', []);
            update_post_meta($customer_id, '_wplm_notes', '');

            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_created',
                sprintf('Customer %s created from license generation', $customer_name),
                ['source_license' => $license_id, 'email' => $license_data['customer_email']]
            );

            return $customer_id;
        }

        return false;
    }

    /**
     * Sync customer from WooCommerce order
     */
    public function sync_customer_from_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $customer_email = $order->get_billing_email();
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        
        if (empty($customer_email)) {
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
    }

    /**
     * Create customer from WooCommerce order
     */
    public function create_customer_from_order($order) {
        $customer_email = $order->get_billing_email();
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        
        if (empty($customer_name)) {
            $customer_name = $customer_email;
        }

        $customer_id = wp_insert_post([
            'post_type' => $this->customer_post_type,
            'post_title' => $customer_name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ]);

        if (!is_wp_error($customer_id)) {
            // Store customer metadata
            update_post_meta($customer_id, '_wplm_customer_email', $customer_email);
            update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
            update_post_meta($customer_id, '_wplm_first_name', $order->get_billing_first_name());
            update_post_meta($customer_id, '_wplm_last_name', $order->get_billing_last_name());
            update_post_meta($customer_id, '_wplm_phone', $order->get_billing_phone());
            update_post_meta($customer_id, '_wplm_company', $order->get_billing_company());
            update_post_meta($customer_id, '_wplm_address', [
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country()
            ]);
            update_post_meta($customer_id, '_wplm_wc_customer_id', $order->get_customer_id());
            
            // Handle username and date_registered safely
            $wc_customer_id = $order->get_customer_id();
            if ($wc_customer_id > 0) {
                $user_data = get_userdata($wc_customer_id);
                if ($user_data) {
                    update_post_meta($customer_id, '_wplm_username', $user_data->user_login);
                    update_post_meta($customer_id, '_wplm_date_registered', $user_data->user_registered);
                } else {
                    update_post_meta($customer_id, '_wplm_username', '');
                    update_post_meta($customer_id, '_wplm_date_registered', current_time('mysql'));
                }
            } else {
                update_post_meta($customer_id, '_wplm_username', '');
                update_post_meta($customer_id, '_wplm_date_registered', current_time('mysql'));
            }
            update_post_meta($customer_id, '_wplm_last_active', current_time('mysql'));
            update_post_meta($customer_id, '_wplm_first_order_date', $order->get_date_created()->date('Y-m-d H:i:s'));
            update_post_meta($customer_id, '_wplm_last_order_date', $order->get_date_created()->date('Y-m-d H:i:s'));
            update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
            update_post_meta($customer_id, '_wplm_total_spent', $order->get_total());
            update_post_meta($customer_id, '_wplm_order_count', 1);
            update_post_meta($customer_id, '_wplm_customer_status', 'active');
            update_post_meta($customer_id, '_wplm_customer_source', 'woocommerce');
            
            // Initialize arrays
            update_post_meta($customer_id, '_wplm_order_ids', [$order->get_id()]);
            update_post_meta($customer_id, '_wplm_license_keys', []);
            update_post_meta($customer_id, '_wplm_communication_log', []);
            update_post_meta($customer_id, '_wplm_tags', []);
            update_post_meta($customer_id, '_wplm_notes', '');

            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_created',
                sprintf('Customer %s created from WooCommerce order #%d', $customer_name, $order->get_order_number()),
                ['source_order' => $order->get_id(), 'email' => $customer_email]
            );

            return $customer_id;
        }

        return false;
    }

    /**
     * Update customer from WooCommerce order
     */
    public function update_customer_from_order($customer_id, $order) {
        // Update basic info
        update_post_meta($customer_id, '_wplm_last_order_date', $order->get_date_created()->date('Y-m-d H:i:s'));
        update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
        update_post_meta($customer_id, '_wplm_last_active', current_time('mysql'));
        
        // Update spending
        $current_spent = get_post_meta($customer_id, '_wplm_total_spent', true) ?: 0;
        $new_total = floatval($current_spent) + floatval($order->get_total());
        update_post_meta($customer_id, '_wplm_total_spent', $new_total);
        
        // Update order count
        $current_count = get_post_meta($customer_id, '_wplm_order_count', true) ?: 0;
        update_post_meta($customer_id, '_wplm_order_count', intval($current_count) + 1);
        
        // Add order ID
        $order_ids = get_post_meta($customer_id, '_wplm_order_ids', true) ?: [];
        if (!in_array($order->get_id(), $order_ids)) {
            $order_ids[] = $order->get_id();
            update_post_meta($customer_id, '_wplm_order_ids', $order_ids);
        }

        WPLM_Activity_Logger::log(
            $customer_id,
            'customer_updated',
            sprintf('Customer updated from WooCommerce order #%d', $order->get_order_number()),
            ['source_order' => $order->get_id(), 'new_total_spent' => $new_total]
        );
    }

    /**
     * Get customer by email
     */
    public function get_customer_by_email($email) {
        $customers = get_posts([
            'post_type' => $this->customer_post_type,
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $email,
            'posts_per_page' => 1
        ]);

        return !empty($customers) ? $customers[0] : null;
    }

    /**
     * Update customer license count
     */
    private function update_customer_license_count($customer_id) {
        $customer_email = get_post_meta($customer_id, '_wplm_customer_email', true);
        
        // Count total licenses
        $total_licenses = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $customer_email,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        
        // Count active licenses
        $active_licenses = new WP_Query([
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
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);

        update_post_meta($customer_id, '_wplm_total_licenses', $total_licenses->found_posts);
        update_post_meta($customer_id, '_wplm_active_licenses', $active_licenses->found_posts);
        update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
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
        
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true);
        $first_name = get_post_meta($post->ID, '_wplm_first_name', true);
        $last_name = get_post_meta($post->ID, '_wplm_last_name', true);
        $phone = get_post_meta($post->ID, '_wplm_phone', true);
        $company = get_post_meta($post->ID, '_wplm_company', true);
        $address = get_post_meta($post->ID, '_wplm_address', true);
        $customer_status = get_post_meta($post->ID, '_wplm_customer_status', true);
        $customer_source = get_post_meta($post->ID, '_wplm_customer_source', true);
        $tags = get_post_meta($post->ID, '_wplm_tags', true) ?: [];
        $notes = get_post_meta($post->ID, '_wplm_notes', true);
        
        ?>
        <table class="form-table">
            <tr>
                <th><label for="wplm_customer_email"><?php _e('Email Address', 'wp-license-manager'); ?></label></th>
                <td><input type="email" id="wplm_customer_email" name="wplm_customer_email" value="<?php echo esc_attr($customer_email); ?>" class="regular-text" required /></td>
            </tr>
            <tr>
                <th><label for="wplm_first_name"><?php _e('First Name', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_first_name" name="wplm_first_name" value="<?php echo esc_attr($first_name); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_last_name"><?php _e('Last Name', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_last_name" name="wplm_last_name" value="<?php echo esc_attr($last_name); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_phone"><?php _e('Phone', 'wp-license-manager'); ?></label></th>
                <td><input type="tel" id="wplm_phone" name="wplm_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_company"><?php _e('Company', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_company" name="wplm_company" value="<?php echo esc_attr($company); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_customer_status"><?php _e('Status', 'wp-license-manager'); ?></label></th>
                <td>
                    <select id="wplm_customer_status" name="wplm_customer_status">
                        <option value="active" <?php selected($customer_status, 'active'); ?>><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="inactive" <?php selected($customer_status, 'inactive'); ?>><?php _e('Inactive', 'wp-license-manager'); ?></option>
                        <option value="blocked" <?php selected($customer_status, 'blocked'); ?>><?php _e('Blocked', 'wp-license-manager'); ?></option>
                        <option value="prospect" <?php selected($customer_status, 'prospect'); ?>><?php _e('Prospect', 'wp-license-manager'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="wplm_tags"><?php _e('Tags', 'wp-license-manager'); ?></label></th>
                <td>
                    <input type="text" id="wplm_tags" name="wplm_tags" value="<?php echo esc_attr(implode(', ', $tags)); ?>" class="regular-text" />
                    <p class="description"><?php _e('Comma-separated tags for categorizing customers', 'wp-license-manager'); ?></p>
                </td>
            </tr>
        </table>

        <h4><?php _e('Address Information', 'wp-license-manager'); ?></h4>
        <table class="form-table">
            <tr>
                <th><label for="wplm_address_1"><?php _e('Address Line 1', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_address_1" name="wplm_address_1" value="<?php echo esc_attr($address['address_1'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_address_2"><?php _e('Address Line 2', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_address_2" name="wplm_address_2" value="<?php echo esc_attr($address['address_2'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_city"><?php _e('City', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_city" name="wplm_city" value="<?php echo esc_attr($address['city'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_state"><?php _e('State/Province', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_state" name="wplm_state" value="<?php echo esc_attr($address['state'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_postcode"><?php _e('Postal Code', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_postcode" name="wplm_postcode" value="<?php echo esc_attr($address['postcode'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_country"><?php _e('Country', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_country" name="wplm_country" value="<?php echo esc_attr($address['country'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
        </table>

        <h4><?php _e('Notes', 'wp-license-manager'); ?></h4>
        <table class="form-table">
            <tr>
                <td>
                    <textarea id="wplm_notes" name="wplm_notes" rows="5" class="large-text"><?php echo esc_textarea($notes); ?></textarea>
                    <p class="description"><?php _e('Internal notes about this customer', 'wp-license-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render customer activity meta box
     */
    public function render_customer_activity_meta_box($post) {
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true);
        $total_licenses = get_post_meta($post->ID, '_wplm_total_licenses', true) ?: 0;
        $active_licenses = get_post_meta($post->ID, '_wplm_active_licenses', true) ?: 0;
        $total_spent = get_post_meta($post->ID, '_wplm_total_spent', true) ?: 0;
        $order_count = get_post_meta($post->ID, '_wplm_order_count', true) ?: 0;
        $first_order_date = get_post_meta($post->ID, '_wplm_first_order_date', true);
        $last_activity = get_post_meta($post->ID, '_wplm_last_activity', true);

        ?>
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
                <div class="wplm-stat-item">
                    <h4><?php echo $order_count; ?></h4>
                    <p><?php _e('Orders', 'wp-license-manager'); ?></p>
                </div>
            </div>
        </div>

        <h4><?php _e('Recent Licenses', 'wp-license-manager'); ?></h4>
        <div id="customer-licenses-list">
            <?php $this->render_customer_licenses($customer_email); ?>
        </div>

        <h4><?php _e('Recent Orders', 'wp-license-manager'); ?></h4>
        <div id="customer-orders-list">
            <?php $this->render_customer_orders($post->ID); ?>
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
            <h4><?php _e('Send Email', 'wp-license-manager'); ?></h4>
            <p>
                <input type="text" id="email-subject" placeholder="<?php _e('Subject', 'wp-license-manager'); ?>" class="widefat" />
            </p>
            <p>
                <textarea id="email-message" placeholder="<?php _e('Message', 'wp-license-manager'); ?>" rows="5" class="widefat"></textarea>
            </p>
            <p>
                <button type="button" class="button" id="send-customer-email" data-customer-id="<?php echo $post->ID; ?>">
                    <?php _e('Send Email', 'wp-license-manager'); ?>
                </button>
            </p>
        </div>

        <div class="wplm-communication-log">
            <h4><?php _e('Communication History', 'wp-license-manager'); ?></h4>
            <?php if (empty($communication_log)): ?>
                <p class="description"><?php _e('No communication history yet.', 'wp-license-manager'); ?></p>
            <?php else: ?>
                <div class="wplm-comm-list">
                    <?php foreach (array_reverse(array_slice($communication_log, -10)) as $comm): ?>
                        <div class="wplm-comm-item">
                            <strong><?php echo esc_html($comm['type']); ?></strong>
                            <span class="wplm-comm-date"><?php echo date('M j, Y g:i A', strtotime($comm['date'])); ?></span>
                            <p><?php echo esc_html($comm['subject'] ?? $comm['message']); ?></p>
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
        }
        </style>
        <?php
    }

    /**
     * Render customer licenses
     */
    private function render_customer_licenses($customer_email) {
        if (empty($customer_email)) {
            echo '<p class="description">' . __('No email address available.', 'wp-license-manager') . '</p>';
            return;
        }

        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $customer_email,
            'posts_per_page' => 5,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        if (empty($licenses)) {
            echo '<p class="description">' . __('No licenses found for this customer.', 'wp-license-manager') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat">';
        echo '<thead><tr><th>License Key</th><th>Product</th><th>Status</th><th>Created</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($licenses as $license) {
            $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
            $status = get_post_meta($license->ID, '_wplm_status', true);
            $created = get_the_date('M j, Y', $license->ID);
            
            echo '<tr>';
            echo '<td><a href="' . get_edit_post_link($license->ID) . '">' . esc_html($license->post_title) . '</a></td>';
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
        $order_ids = get_post_meta($customer_id, '_wplm_order_ids', true) ?: [];
        
        if (empty($order_ids) || !function_exists('wc_get_order')) {
            echo '<p class="description">' . __('No orders found for this customer.', 'wp-license-manager') . '</p>';
            return;
        }

        $recent_orders = array_slice(array_reverse($order_ids), 0, 5);
        
        echo '<table class="wp-list-table widefat">';
        echo '<thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($recent_orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;
            
            echo '<tr>';
            echo '<td><a href="' . $order->get_edit_order_url() . '">#' . $order->get_order_number() . '</a></td>';
            echo '<td>' . $order->get_date_created()->date('M j, Y') . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
            echo '<td>' . $order->get_formatted_order_total() . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }

    /**
     * Save customer meta
     */
    public function save_customer_meta($post_id) {
        if (!isset($_POST['wplm_customer_meta_nonce']) || !wp_verify_nonce($_POST['wplm_customer_meta_nonce'], 'wplm_customer_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Basic fields
        $fields = [
            '_wplm_customer_email',
            '_wplm_first_name',
            '_wplm_last_name',
            '_wplm_phone',
            '_wplm_company',
            '_wplm_customer_status',
            '_wplm_notes'
        ];

        foreach ($fields as $field) {
            $key = str_replace('_wplm_', '', $field);
            if (isset($_POST['wplm_' . $key])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST['wplm_' . $key]));
            }
        }

        // Address
        $address = [
            'address_1' => sanitize_text_field($_POST['wplm_address_1'] ?? ''),
            'address_2' => sanitize_text_field($_POST['wplm_address_2'] ?? ''),
            'city' => sanitize_text_field($_POST['wplm_city'] ?? ''),
            'state' => sanitize_text_field($_POST['wplm_state'] ?? ''),
            'postcode' => sanitize_text_field($_POST['wplm_postcode'] ?? ''),
            'country' => sanitize_text_field($_POST['wplm_country'] ?? '')
        ];
        update_post_meta($post_id, '_wplm_address', $address);

        // Tags
        if (isset($_POST['wplm_tags'])) {
            $tags = array_filter(array_map('trim', explode(',', $_POST['wplm_tags'])));
            update_post_meta($post_id, '_wplm_tags', $tags);
        }

        // Update post title based on name
        $first_name = $_POST['wplm_first_name'] ?? '';
        $last_name = $_POST['wplm_last_name'] ?? '';
        $full_name = trim($first_name . ' ' . $last_name);
        
        if (!empty($full_name) && $full_name !== get_the_title($post_id)) {
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $full_name
            ]);
        }
    }

    /**
     * AJAX: Get customers for DataTables
     */
    public function ajax_get_customers() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Safe defaults for DataTables params to avoid undefined index notices
        $draw = intval($_POST['draw'] ?? 1);
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 25);
        if ($length <= 0) { $length = 25; }

        // Support both DataTables array format and flat string
        $search_value = '';
        if (isset($_POST['search'])) {
            if (is_array($_POST['search']) && isset($_POST['search']['value'])) {
                $search_value = sanitize_text_field($_POST['search']['value']);
            } else {
                $search_value = sanitize_text_field($_POST['search']);
            }
        }

        $args = [
            'post_type' => $this->customer_post_type,
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start,
            'orderby' => 'title',
            'order' => 'ASC'
        ];

        if (!empty($search_value)) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => '_wplm_customer_email',
                    'value' => $search_value,
                    'compare' => 'LIKE'
                ],
                [
                    'key' => '_wplm_customer_name',
                    'value' => $search_value,
                    'compare' => 'LIKE'
                ]
            ];
        }

        $customers = get_posts($args);
        
        // Get total count
        $total_args = $args;
        $total_args['posts_per_page'] = -1;
        $total_args['fields'] = 'ids';
        unset($total_args['offset']);
        $total_customers = get_posts($total_args);
        $total_count = count($total_customers);

        $data = [];
        foreach ($customers as $customer) {
            $customer_email = get_post_meta($customer->ID, '_wplm_customer_email', true);
            $customer_name = get_post_meta($customer->ID, '_wplm_customer_name', true);
            $total_licenses = get_post_meta($customer->ID, '_wplm_total_licenses', true) ?: 0;
            $active_licenses = get_post_meta($customer->ID, '_wplm_active_licenses', true) ?: 0;
            $total_spent = get_post_meta($customer->ID, '_wplm_total_spent', true) ?: 0;
            $last_activity = get_post_meta($customer->ID, '_wplm_last_activity', true);

            $edit_link = admin_url('post.php?post=' . $customer->ID . '&action=edit');
            $actions = sprintf(
                '<button class="button button-small wplm-view-customer" data-customer-id="%d">%s</button> ' .
                '<a href="%s" class="button button-small">%s</a>',
                $customer->ID,
                __('View', 'wp-license-manager'),
                esc_url($edit_link),
                __('Edit', 'wp-license-manager')
            );

            $row_data = [
                'customer' => esc_html($customer_name),
                'email' => esc_html($customer_email),
                'total_licenses' => intval($total_licenses),
                'active_licenses' => intval($active_licenses),
                'total_spent' => function_exists('wc_price') ? wc_price($total_spent) : '$' . number_format($total_spent, 2),
                'last_activity' => $last_activity ? date('M j, Y', strtotime($last_activity)) : '—',
                'actions' => $actions
            ];
            
            $data[] = $row_data;
        }

        $response = [
            'draw' => $draw,
            'recordsTotal' => $total_count,
            'recordsFiltered' => $total_count,
            'data' => $data
        ];
        
        wp_send_json($response);
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
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_customers')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $customer_id = intval($_POST['customer_id']);
        $subject = sanitize_text_field($_POST['subject']);
        $message = sanitize_textarea_field($_POST['message']);

        if (empty($subject) || empty($message)) {
            wp_send_json_error(['message' => __('Subject and message are required', 'wp-license-manager')]);
        }

        $customer_email = get_post_meta($customer_id, '_wplm_customer_email', true);
        if (empty($customer_email) || !is_email($customer_email)) {
            wp_send_json_error(['message' => __('Invalid or missing customer email', 'wp-license-manager')]);
        }

        // Send email
        $sent = wp_mail($customer_email, $subject, $message);

        if ($sent) {
            // Log communication
            $communication_log = get_post_meta($customer_id, '_wplm_communication_log', true) ?: [];
            $communication_log[] = [
                'type' => 'Email Sent',
                'subject' => $subject,
                'message' => $message,
                'date' => current_time('mysql'),
                'user' => get_current_user_id()
            ];
            update_post_meta($customer_id, '_wplm_communication_log', $communication_log);

            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_email_sent',
                'Email sent to customer: ' . $subject,
                ['subject' => $subject, 'recipient' => $customer_email]
            );

            wp_send_json_success(['message' => __('Email sent successfully', 'wp-license-manager')]);
        } else {
            // Log error if email failed to send
            $error_message = __('Failed to send email', 'wp-license-manager');
            if (function_exists('error_log')) {
                error_log(sprintf('WPLM Email Error: Failed to send email to %s with subject %s', $customer_email, $subject));
            }
            WPLM_Activity_Logger::log(
                $customer_id,
                'customer_email_failed',
                'Failed to send email to customer: ' . $subject,
                ['subject' => $subject, 'recipient' => $customer_email, 'error' => 'wp_mail_failure']
            );
            wp_send_json_error(['message' => $error_message]);
            return; // Exit after sending error
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

