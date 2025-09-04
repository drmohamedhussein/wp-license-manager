<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Built-in Subscription System for WPLM
 * Integrates WooCommerce Subscriptions functionality directly into WPLM
 */
class WPLM_Built_In_Subscription_System {

    private $subscription_post_type = 'wplm_subscription';

    public function __construct() {
        $this->init_hooks();
        // Removed: $this->register_subscription_post_type(); // CPT registered by WPLM_Subscription_Manager
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // WooCommerce Subscriptions integration
        add_action('plugins_loaded', [$this, 'integrate_woocommerce_subscriptions'], 20);
        
        // AJAX handlers
        add_action('wp_ajax_wplm_create_subscription', [$this, 'ajax_create_subscription']);
        add_action('wp_ajax_wplm_update_subscription', [$this, 'ajax_update_subscription']);
        add_action('wp_ajax_wplm_cancel_subscription', [$this, 'ajax_cancel_subscription']);
        add_action('wp_ajax_wplm_get_subscriptions', [$this, 'ajax_get_subscriptions']);
        
        // Cron jobs for subscription management
        add_action('wplm_process_subscription_renewals', [$this, 'process_subscription_renewals']);
        add_action('wplm_check_expired_subscriptions', [$this, 'check_expired_subscriptions']);
        
        // Schedule cron jobs
        if (!wp_next_scheduled('wplm_process_subscription_renewals')) {
            wp_schedule_event(time(), 'daily', 'wplm_process_subscription_renewals');
        }
        
        if (!wp_next_scheduled('wplm_check_expired_subscriptions')) {
            wp_schedule_event(time(), 'hourly', 'wplm_check_expired_subscriptions');
        }
        
        // Meta boxes
        add_action('add_meta_boxes', [$this, 'add_subscription_meta_boxes']);
        add_action('save_post', [$this, 'save_subscription_meta']);
    }

    /**
     * Integrate with WooCommerce Subscriptions
     */
    public function integrate_woocommerce_subscriptions() {
        if (!class_exists('WCS_Subscription')) {
            return;
        }

        // Hook into WooCommerce Subscriptions events
        add_action('woocommerce_subscription_status_updated', [$this, 'sync_wc_subscription_status'], 10, 3);
        add_action('woocommerce_scheduled_subscription_payment', [$this, 'process_subscription_payment'], 10, 1);
        add_action('woocommerce_subscription_payment_complete', [$this, 'handle_subscription_payment_complete'], 10, 1);
        add_action('woocommerce_subscription_cancelled', [$this, 'handle_subscription_cancelled'], 10, 1);
        add_action('woocommerce_subscription_expired', [$this, 'handle_subscription_expired'], 10, 1);
        
        // Import existing WooCommerce subscriptions
        add_action('admin_init', [$this, 'import_wc_subscriptions'], 5);
    }

    /**
     * Import existing WooCommerce subscriptions
     */
    public function import_wc_subscriptions() {
        if (get_option('wplm_wc_subscriptions_imported', false)) {
            return;
        }

        $wc_subscriptions = wcs_get_subscriptions([
            'subscriptions_per_page' => -1,
            'subscription_status' => ['active', 'on-hold', 'cancelled', 'expired']
        ]);

        foreach ($wc_subscriptions as $wc_subscription) {
            $this->create_wplm_subscription_from_wc($wc_subscription);
        }

        update_option('wplm_wc_subscriptions_imported', true);
    }

    /**
     * Create WPLM subscription from WooCommerce subscription
     */
    public function create_wplm_subscription_from_wc($wc_subscription) {
        // Check if already imported
        $existing = get_posts([
            'post_type' => $this->subscription_post_type,
            'meta_key' => '_wplm_wc_subscription_id',
            'meta_value' => $wc_subscription->get_id(),
            'posts_per_page' => 1
        ]);

        if (!empty($existing)) {
            return $existing[0]->ID;
        }

        $customer_email = $wc_subscription->get_billing_email();
        $customer_name = $wc_subscription->get_billing_first_name() . ' ' . $wc_subscription->get_billing_last_name();
        
        // Get the main product from subscription items
        $items = $wc_subscription->get_items();
        $main_item = reset($items);
        $product_name = $main_item ? $main_item->get_name() : 'Unknown Product';

        $subscription_id = wp_insert_post([
            'post_type' => $this->subscription_post_type,
            'post_title' => sprintf('Subscription #%d - %s', $wc_subscription->get_id(), $customer_name),
            'post_status' => 'publish',
            'post_author' => 1
        ]);

        if (!is_wp_error($subscription_id)) {
            // Store subscription metadata
            update_post_meta($subscription_id, '_wplm_wc_subscription_id', $wc_subscription->get_id());
            update_post_meta($subscription_id, '_wplm_customer_email', $customer_email);
            update_post_meta($subscription_id, '_wplm_customer_name', trim($customer_name));
            update_post_meta($subscription_id, '_wplm_product_name', $product_name);
            update_post_meta($subscription_id, '_wplm_subscription_status', $wc_subscription->get_status());
            update_post_meta($subscription_id, '_wplm_billing_period', $wc_subscription->get_billing_period());
            update_post_meta($subscription_id, '_wplm_billing_interval', $wc_subscription->get_billing_interval());
            update_post_meta($subscription_id, '_wplm_start_date', $wc_subscription->get_date('start'));
            update_post_meta($subscription_id, '_wplm_next_payment', $wc_subscription->get_date('next_payment'));
            update_post_meta($subscription_id, '_wplm_end_date', $wc_subscription->get_date('end'));
            update_post_meta($subscription_id, '_wplm_total_paid', $wc_subscription->get_total());
            update_post_meta($subscription_id, '_wplm_currency', $wc_subscription->get_currency());

            // Link license keys from order items
            $license_keys = [];
            foreach ($items as $item) {
                $license_key = wc_get_order_item_meta($item->get_id(), '_wplm_license_key', true);
                if (!empty($license_key)) {
                    $license_keys[] = $license_key;
                }
            }
            update_post_meta($subscription_id, '_wplm_license_keys', $license_keys);

            // Log activity
            WPLM_Activity_Logger::log(
                $subscription_id,
                'subscription_imported',
                sprintf('Subscription imported from WooCommerce subscription #%d', $wc_subscription->get_id()),
                ['wc_subscription_id' => $wc_subscription->get_id()]
            );

            return $subscription_id;
        }

        return false;
    }

    /**
     * Sync WooCommerce subscription status changes
     */
    public function sync_wc_subscription_status($subscription, $new_status, $old_status) {
        $wplm_subscriptions = get_posts([
            'post_type' => $this->subscription_post_type,
            'meta_key' => '_wplm_wc_subscription_id',
            'meta_value' => $subscription->get_id(),
            'posts_per_page' => 1
        ]);

        if (!empty($wplm_subscriptions)) {
            $wplm_subscription_id = $wplm_subscriptions[0]->ID;
            update_post_meta($wplm_subscription_id, '_wplm_subscription_status', $new_status);

            // Update related license keys status
            $license_keys = get_post_meta($wplm_subscription_id, '_wplm_license_keys', true);
            if (is_array($license_keys)) {
                foreach ($license_keys as $license_key) {
                    $license_posts = get_posts([
                        'post_type'      => 'wplm_license',
                        'posts_per_page' => 1,
                        'title'          => $license_key,
                        'fields'         => 'ids',
                        'exact'          => true,
                    ]);

                    if (!empty($license_posts)) {
                        $license_post_id = $license_posts[0];
                        $license_status = $this->map_subscription_status_to_license($new_status);
                        update_post_meta($license_post_id, '_wplm_status', $license_status);
                        
                        WPLM_Activity_Logger::log(
                            $license_post_id,
                            'license_status_updated_by_subscription',
                            sprintf('License status updated to %s due to subscription status change', $license_status),
                            ['subscription_id' => $wplm_subscription_id, 'new_status' => $new_status]
                        );
                    }
                }
            }

            WPLM_Activity_Logger::log(
                $wplm_subscription_id,
                'subscription_status_changed',
                sprintf('Subscription status changed from %s to %s', $old_status, $new_status),
                ['old_status' => $old_status, 'new_status' => $new_status]
            );
        }
    }

    /**
     * Map subscription status to license status
     */
    private function map_subscription_status_to_license($subscription_status) {
        $status_map = [
            'active' => 'active',
            'on-hold' => 'inactive',
            'cancelled' => 'inactive',
            'expired' => 'expired',
            'pending' => 'inactive',
            'pending-cancel' => 'active', // Still active until cancellation
        ];

        return isset($status_map[$subscription_status]) ? $status_map[$subscription_status] : 'inactive';
    }

    /**
     * Process subscription payment
     */
    public function process_subscription_payment($subscription_id) {
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return;
        }

        // Extend license expiry dates for renewed subscriptions
        $wplm_subscriptions = get_posts([
            'post_type' => $this->subscription_post_type,
            'meta_key' => '_wplm_wc_subscription_id',
            'meta_value' => $subscription_id,
            'posts_per_page' => 1
        ]);

        if (!empty($wplm_subscriptions)) {
            $wplm_subscription_id = $wplm_subscriptions[0]->ID;
            
            // Update next payment date
            update_post_meta($wplm_subscription_id, '_wplm_next_payment', $subscription->get_date('next_payment'));
            
            // Extend license keys
            $license_keys = get_post_meta($wplm_subscription_id, '_wplm_license_keys', true);
            if (is_array($license_keys)) {
                foreach ($license_keys as $license_key) {
                    $this->extend_license_expiry($license_key, $subscription);
                }
            }
        }
    }

    /**
     * Handle subscription payment complete
     */
    public function handle_subscription_payment_complete($subscription) {
        $wplm_subscriptions = get_posts([
            'post_type' => $this->subscription_post_type,
            'meta_key' => '_wplm_wc_subscription_id',
            'meta_value' => $subscription->get_id(),
            'posts_per_page' => 1
        ]);

        if (!empty($wplm_subscriptions)) {
            $wplm_subscription_id = $wplm_subscriptions[0]->ID;
            
            // Update total paid amount
            $current_total = get_post_meta($wplm_subscription_id, '_wplm_total_paid', true);
            $payment_amount = $subscription->get_total();
            $new_total = floatval($current_total) + floatval($payment_amount);
            update_post_meta($wplm_subscription_id, '_wplm_total_paid', $new_total);

            WPLM_Activity_Logger::log(
                $wplm_subscription_id,
                'subscription_payment_complete',
                sprintf('Subscription payment of %s completed', wc_price($payment_amount)),
                ['payment_amount' => $payment_amount, 'new_total' => $new_total]
            );
        }
    }

    /**
     * Handle subscription cancelled
     */
    public function handle_subscription_cancelled($subscription) {
        $this->sync_wc_subscription_status($subscription, 'cancelled', $subscription->get_status());
    }

    /**
     * Handle subscription expired
     */
    public function handle_subscription_expired($subscription) {
        $this->sync_wc_subscription_status($subscription, 'expired', $subscription->get_status());
    }

    /**
     * Extend license expiry based on subscription renewal
     */
    private function extend_license_expiry($license_key, $subscription) {
        $license_posts = get_posts([
            'post_type'      => 'wplm_license',
            'posts_per_page' => 1,
            'title'          => $license_key,
            'fields'         => 'ids',
            'exact'          => true,
        ]);

        if (empty($license_posts)) {
            return;
        }
        $license_post_id = $license_posts[0];

        $current_expiry = get_post_meta($license_post_id, '_wplm_expiry_date', true);
        $billing_period = $subscription->get_billing_period();
        $billing_interval = $subscription->get_billing_interval();

        // Calculate new expiry date
        $base_date = !empty($current_expiry) && strtotime($current_expiry) > time() ? $current_expiry : date('Y-m-d');
        
        switch ($billing_period) {
            case 'day':
                $new_expiry = date('Y-m-d', strtotime($base_date . ' +' . $billing_interval . ' days'));
                break;
            case 'week':
                $new_expiry = date('Y-m-d', strtotime($base_date . ' +' . ($billing_interval * 7) . ' days'));
                break;
            case 'month':
                $new_expiry = date('Y-m-d', strtotime($base_date . ' +' . $billing_interval . ' months'));
                break;
            case 'year':
                $new_expiry = date('Y-m-d', strtotime($base_date . ' +' . $billing_interval . ' years'));
                break;
            default:
                $new_expiry = date('Y-m-d', strtotime($base_date . ' +1 month'));
        }

        update_post_meta($license_post_id, '_wplm_expiry_date', $new_expiry);
        update_post_meta($license_post_id, '_wplm_status', 'active');

        WPLM_Activity_Logger::log(
            $license_post_id,
            'license_renewed',
            sprintf('License renewed until %s via subscription payment', $new_expiry),
            ['old_expiry' => $current_expiry, 'new_expiry' => $new_expiry]
        );
    }

    /**
     * Process subscription renewals (daily cron job)
     */
    public function process_subscription_renewals() {
        // Get subscriptions due for renewal in next 3 days
        $three_days_from_now = date('Y-m-d', strtotime('+3 days'));
        
        $subscriptions = get_posts([
            'post_type' => $this->subscription_post_type,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wplm_subscription_status',
                    'value' => 'active',
                    'compare' => '='
                ],
                [
                    'key' => '_wplm_next_payment',
                    'value' => $three_days_from_now,
                    'compare' => '<=',
                    'type' => 'DATE'
                ]
            ],
            'posts_per_page' => -1
        ]);

        foreach ($subscriptions as $subscription) {
            $this->send_renewal_reminder($subscription->ID);
        }
    }

    /**
     * Check for expired subscriptions (hourly cron job)
     */
    public function check_expired_subscriptions() {
        $today = date('Y-m-d');
        
        $subscriptions = get_posts([
            'post_type' => $this->subscription_post_type,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wplm_subscription_status',
                    'value' => 'active',
                    'compare' => '='
                ],
                [
                    'key' => '_wplm_next_payment',
                    'value' => $today,
                    'compare' => '<',
                    'type' => 'DATE'
                ]
            ],
            'posts_per_page' => -1
        ]);

        foreach ($subscriptions as $subscription) {
            // Grace period of 7 days for manual subscriptions
            $next_payment = get_post_meta($subscription->ID, '_wplm_next_payment', true);
            $grace_period_end = date('Y-m-d', strtotime($next_payment . ' +7 days'));
            
            if ($today > $grace_period_end) {
                $this->expire_subscription($subscription->ID);
            }
        }
    }

    /**
     * Send renewal reminder
     */
    private function send_renewal_reminder($subscription_id) {
        $customer_email = get_post_meta($subscription_id, '_wplm_customer_email', true);
        $customer_name = get_post_meta($subscription_id, '_wplm_customer_name', true);
        $next_payment = get_post_meta($subscription_id, '_wplm_next_payment', true);
        
        if (empty($customer_email)) {
            return;
        }

        $subject = sprintf(__('Subscription Renewal Reminder - %s', 'wp-license-manager'), get_bloginfo('name'));
        $message = sprintf(
            __('Hello %s,

Your subscription is due for renewal on %s.

Please ensure your payment method is up to date to avoid any interruption to your license.

You can manage your subscription by logging into your account.

Thank you!', 'wp-license-manager'),
            $customer_name,
            date('F j, Y', strtotime($next_payment))
        );

        wp_mail($customer_email, $subject, $message);

        WPLM_Activity_Logger::log(
            $subscription_id,
            'subscription_renewal_reminder_sent',
            'Renewal reminder email sent to customer',
            ['customer_email' => $customer_email, 'next_payment' => $next_payment]
        );
    }

    /**
     * Expire subscription
     */
    private function expire_subscription($subscription_id) {
        update_post_meta($subscription_id, '_wplm_subscription_status', 'expired');
        
        // Expire related license keys
        $license_keys = get_post_meta($subscription_id, '_wplm_license_keys', true);
        if (is_array($license_keys)) {
            foreach ($license_keys as $license_key) {
                $license_posts = get_posts([
                    'post_type' => 'wplm_license',
                    'title' => $license_key,
                    'posts_per_page' => 1,
                    'post_status' => 'publish'
                ]);
                if (!empty($license_posts)) {
                    $license_post = $license_posts[0];
                    update_post_meta($license_post->ID, '_wplm_status', 'expired');
                }
            }
        }

        WPLM_Activity_Logger::log(
            $subscription_id,
            'subscription_expired',
            'Subscription automatically expired due to non-payment',
            []
        );
    }

    /**
     * Add subscription meta boxes
     */
    public function add_subscription_meta_boxes() {
        add_meta_box(
            'wplm_subscription_details',
            __('Subscription Details', 'wp-license-manager'),
            [$this, 'render_subscription_meta_box'],
            $this->subscription_post_type,
            'normal',
            'high'
        );
    }

    /**
     * Render subscription meta box
     */
    public function render_subscription_meta_box($post) {
        wp_nonce_field('wplm_subscription_meta', 'wplm_subscription_meta_nonce');
        
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true);
        $customer_name = get_post_meta($post->ID, '_wplm_customer_name', true);
        $product_name = get_post_meta($post->ID, '_wplm_product_name', true);
        $status = get_post_meta($post->ID, '_wplm_subscription_status', true);
        $billing_period = get_post_meta($post->ID, '_wplm_billing_period', true);
        $billing_interval = get_post_meta($post->ID, '_wplm_billing_interval', true);
        $start_date = get_post_meta($post->ID, '_wplm_start_date', true);
        $next_payment = get_post_meta($post->ID, '_wplm_next_payment', true);
        $end_date = get_post_meta($post->ID, '_wplm_end_date', true);
        $total_paid = get_post_meta($post->ID, '_wplm_total_paid', true);
        $currency = get_post_meta($post->ID, '_wplm_currency', true);
        $license_keys = get_post_meta($post->ID, '_wplm_license_keys', true);
        
        ?>
        <table class="form-table">
            <tr>
                <th><label for="wplm_customer_email"><?php _e('Customer Email', 'wp-license-manager'); ?></label></th>
                <td><input type="email" id="wplm_customer_email" name="wplm_customer_email" value="<?php echo esc_attr($customer_email); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_customer_name"><?php _e('Customer Name', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_customer_name" name="wplm_customer_name" value="<?php echo esc_attr($customer_name); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_product_name"><?php _e('Product Name', 'wp-license-manager'); ?></label></th>
                <td><input type="text" id="wplm_product_name" name="wplm_product_name" value="<?php echo esc_attr($product_name); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="wplm_subscription_status"><?php _e('Status', 'wp-license-manager'); ?></label></th>
                <td>
                    <select id="wplm_subscription_status" name="wplm_subscription_status">
                        <option value="active" <?php selected($status, 'active'); ?>><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="on-hold" <?php selected($status, 'on-hold'); ?>><?php _e('On Hold', 'wp-license-manager'); ?></option>
                        <option value="cancelled" <?php selected($status, 'cancelled'); ?>><?php _e('Cancelled', 'wp-license-manager'); ?></option>
                        <option value="expired" <?php selected($status, 'expired'); ?>><?php _e('Expired', 'wp-license-manager'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="wplm_billing_interval"><?php _e('Billing Frequency', 'wp-license-manager'); ?></label></th>
                <td>
                    <input type="number" id="wplm_billing_interval" name="wplm_billing_interval" value="<?php echo esc_attr($billing_interval ?: '1'); ?>" min="1" style="width: 80px;" />
                    <select name="wplm_billing_period">
                        <option value="day" <?php selected($billing_period, 'day'); ?>><?php _e('Day(s)', 'wp-license-manager'); ?></option>
                        <option value="week" <?php selected($billing_period, 'week'); ?>><?php _e('Week(s)', 'wp-license-manager'); ?></option>
                        <option value="month" <?php selected($billing_period, 'month'); ?>><?php _e('Month(s)', 'wp-license-manager'); ?></option>
                        <option value="year" <?php selected($billing_period, 'year'); ?>><?php _e('Year(s)', 'wp-license-manager'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="wplm_start_date"><?php _e('Start Date', 'wp-license-manager'); ?></label></th>
                <td><input type="date" id="wplm_start_date" name="wplm_start_date" value="<?php echo esc_attr($start_date ? date('Y-m-d', strtotime($start_date)) : ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="wplm_next_payment"><?php _e('Next Payment', 'wp-license-manager'); ?></label></th>
                <td><input type="date" id="wplm_next_payment" name="wplm_next_payment" value="<?php echo esc_attr($next_payment ? date('Y-m-d', strtotime($next_payment)) : ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="wplm_end_date"><?php _e('End Date', 'wp-license-manager'); ?></label></th>
                <td><input type="date" id="wplm_end_date" name="wplm_end_date" value="<?php echo esc_attr($end_date ? date('Y-m-d', strtotime($end_date)) : ''); ?>" />
                    <p class="description"><?php _e('Leave empty for ongoing subscription', 'wp-license-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wplm_total_paid"><?php _e('Total Paid', 'wp-license-manager'); ?></label></th>
                <td>
                    <input type="number" id="wplm_total_paid" name="wplm_total_paid" value="<?php echo esc_attr($total_paid); ?>" step="0.01" />
                    <input type="text" name="wplm_currency" value="<?php echo esc_attr($currency ?: get_woocommerce_currency()); ?>" placeholder="USD" style="width: 80px;" />
                </td>
            </tr>
            <tr>
                <th><label for="wplm_license_keys"><?php _e('License Keys', 'wp-license-manager'); ?></label></th>
                <td>
                    <textarea id="wplm_license_keys" name="wplm_license_keys" rows="4" class="large-text"><?php echo esc_textarea(is_array($license_keys) ? implode("\n", $license_keys) : $license_keys); ?></textarea>
                    <p class="description"><?php _e('One license key per line', 'wp-license-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save subscription meta
     */
    public function save_subscription_meta($post_id) {
        if (!isset($_POST['wplm_subscription_meta_nonce']) || !wp_verify_nonce($_POST['wplm_subscription_meta_nonce'], 'wplm_subscription_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = [
            '_wplm_customer_email',
            '_wplm_customer_name',
            '_wplm_product_name',
            '_wplm_subscription_status',
            '_wplm_billing_period',
            '_wplm_billing_interval',
            '_wplm_start_date',
            '_wplm_next_payment',
            '_wplm_end_date',
            '_wplm_total_paid',
            '_wplm_currency'
        ];

        foreach ($fields as $field) {
            $key = str_replace('_wplm_', '', $field);
            if (isset($_POST['wplm_' . $key])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST['wplm_' . $key]));
            }
        }

        // Handle license keys (array)
        if (isset($_POST['wplm_license_keys'])) {
            $license_keys = array_filter(array_map('trim', explode("\n", $_POST['wplm_license_keys'])));
            update_post_meta($post_id, '_wplm_license_keys', $license_keys);
        }
    }

    /**
     * AJAX: Get subscriptions for DataTables
     */
    public function ajax_get_subscriptions() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_subscriptions')) {
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
            'post_type' => $this->subscription_post_type,
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start,
            'orderby' => 'date',
            'order' => 'DESC'
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

        $subscriptions = get_posts($args);
        
        // Get total count
        $total_args = $args;
        $total_args['posts_per_page'] = -1;
        $total_args['fields'] = 'ids';
        unset($total_args['offset']);
        $total_subscriptions = get_posts($total_args);
        $total_count = count($total_subscriptions);

        $data = [];
        foreach ($subscriptions as $subscription) {
            $customer_email = get_post_meta($subscription->ID, '_wplm_customer_email', true);
            $customer_name = get_post_meta($subscription->ID, '_wplm_customer_name', true);
            $product_name = get_post_meta($subscription->ID, '_wplm_product_name', true);
            $status = get_post_meta($subscription->ID, '_wplm_subscription_status', true);
            $next_payment = get_post_meta($subscription->ID, '_wplm_next_payment', true);
            $total_paid = get_post_meta($subscription->ID, '_wplm_total_paid', true);
            $currency = get_post_meta($subscription->ID, '_wplm_currency', true);

            $actions = sprintf(
                '<a href="%s" class="button button-small">%s</a> ' .
                '<button class="button button-small wplm-cancel-subscription" data-id="%d">%s</button>',
                get_edit_post_link($subscription->ID),
                __('Edit', 'wp-license-manager'),
                $subscription->ID,
                __('Cancel', 'wp-license-manager')
            );

            $data[] = [
                'subscription_id' => '#' . $subscription->ID,
                'customer' => sprintf('%s<br><small>%s</small>', esc_html($customer_name), esc_html($customer_email)),
                'product' => esc_html($product_name),
                'status' => sprintf('<span class="wplm-status-badge wplm-status-%s">%s</span>', esc_attr($status), esc_html(ucfirst($status))),
                'next_payment' => $next_payment ? date('M j, Y', strtotime($next_payment)) : '—',
                'total_paid' => $total_paid ? wc_price($total_paid, ['currency' => $currency]) : '—',
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
     * AJAX: Create subscription (delegates to WPLM_Subscription_Manager)
     */
    public function ajax_create_subscription() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        // Ensure WPLM_Subscription_Manager is available
        $subscription_manager = new WPLM_Subscription_Manager();

        $args = [
            'license_id' => intval($_POST['license_id'] ?? 0), // Assuming an existing license can be linked
            'customer_email' => sanitize_email($_POST['customer_email'] ?? ''),
            'product_id' => sanitize_text_field($_POST['product_name'] ?? ''), // Using product_name as product_id for simplicity
            'billing_period' => sanitize_text_field($_POST['billing_period'] ?? 'month'),
            'billing_interval' => intval($_POST['billing_interval'] ?? 1),
            // 'trial_end_date' => sanitize_text_field($_POST['trial_end_date'] ?? ''), // Add if needed
            'next_payment_date' => sanitize_text_field($_POST['start_date'] ?? current_time('mysql')), // Treat start_date as first payment date
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
        ];

        $result = $subscription_manager->create_subscription($args);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return; // Exit after sending error
        }
        
        wp_send_json_success(['message' => __('Subscription created successfully', 'wp-license-manager'), 'subscription_id' => $result]);
    }

    /**
     * Helper to calculate the next payment date.
     * This is no longer needed here as WPLM_Subscription_Manager handles it.
     */
    // private function calculate_next_payment_date($start_date, $billing_period, $billing_interval) {
    //     // ... (removed content)
    // }

    /**
     * AJAX: Update subscription (delegates to WPLM_Subscription_Manager)
     */
    public function ajax_update_subscription() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        // Ensure WPLM_Subscription_Manager is available
        $subscription_manager = new WPLM_Subscription_Manager();

        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        if (!$subscription_id) {
            wp_send_json_error(['message' => __('Invalid subscription ID.', 'wp-license-manager')]);
            return; // Exit after sending error
        }

        $data = [
            'customer_email' => sanitize_email($_POST['customer_email'] ?? ''),
            'product_id' => sanitize_text_field($_POST['product_name'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
            'billing_period' => sanitize_text_field($_POST['billing_period'] ?? 'month'),
            'billing_interval' => intval($_POST['billing_interval'] ?? 1),
            'next_payment_date' => sanitize_text_field($_POST['next_payment'] ?? ''),
            'end_date' => sanitize_text_field($_POST['end_date'] ?? ''),
        ];

        $result = $subscription_manager->update_subscription($subscription_id, $data);

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to update subscription', 'wp-license-manager')]);
            return; // Exit after sending error
        }
        
        wp_send_json_success(['message' => __('Subscription updated successfully', 'wp-license-manager'), 'subscription_id' => $subscription_id]);
    }

    /**
     * AJAX: Cancel subscription (delegates to WPLM_Subscription_Manager)
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_wplm_subscriptions')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        // Ensure WPLM_Subscription_Manager is available
        $subscription_manager = new WPLM_Subscription_Manager();

        $subscription_id = intval($_POST['subscription_id']);
        if (!$subscription_id) {
            wp_send_json_error(['message' => __('Invalid subscription ID', 'wp-license-manager')]);
            return; // Exit after sending error
        }

        $result = $subscription_manager->cancel_subscription($subscription_id, __('Manually cancelled from admin', 'wp-license-manager'));
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return; // Exit after sending error
        }

        wp_send_json_success(['message' => __('Subscription cancelled successfully', 'wp-license-manager')]);
    }
}

