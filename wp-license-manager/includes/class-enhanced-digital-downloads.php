<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Digital Downloads Manager for WPLM
 * Provides EasyDigitalDownloads-like functionality
 */
class WPLM_Enhanced_Digital_Downloads {

    public $cart = [];
    public $payment_gateways = []; // Declare the property to avoid deprecation warning
    private $download_post_type = 'wplm_download';

    public function __construct() {
        add_action('init', [$this, 'init_downloads_system']);
        add_action('init', [$this, 'setup_rewrite_rules']); // Hook rewrite rules to init
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        
        // Shortcodes
        add_shortcode('wplm_download', [$this, 'download_shortcode']);
        add_shortcode('wplm_downloads', [$this, 'downloads_list_shortcode']);
        add_shortcode('wplm_cart', [$this, 'cart_shortcode']);
        add_shortcode('wplm_checkout', [$this, 'checkout_shortcode']);
        add_shortcode('wplm_customer_dashboard', [$this, 'customer_dashboard_shortcode']);
        
        // AJAX handlers
        add_action('wp_ajax_wplm_add_to_cart', [$this, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_wplm_add_to_cart', [$this, 'ajax_add_to_cart']);
        add_action('wp_ajax_wplm_remove_from_cart', [$this, 'ajax_remove_from_cart']);
        add_action('wp_ajax_nopriv_wplm_remove_from_cart', [$this, 'ajax_remove_from_cart']);
        add_action('wp_ajax_wplm_get_cart_count', [$this, 'ajax_get_cart_count']);
        add_action('wp_ajax_nopriv_wplm_get_cart_count', [$this, 'ajax_get_cart_count']);
        add_action('wp_ajax_wplm_process_checkout', [$this, 'ajax_process_checkout']);
        add_action('wp_ajax_nopriv_wplm_process_checkout', [$this, 'ajax_process_checkout']);
        
        // Payment processing
        add_action('wplm_payment_completed', [$this, 'process_completed_payment'], 10, 2);
        
        // Email notifications
        add_action('wplm_purchase_completed', [$this, 'send_purchase_receipt'], 10, 2);
    }

    /**
     * Initialize downloads system
     */
    public function init_downloads_system() {
        $this->register_download_post_type();
        $this->create_tables();
        $this->init_cart_session();
        // Removed: $this->setup_download_endpoints(); // Now hooked directly to 'init'
        $this->load_payment_gateways();
    }

    /**
     * Register download post type
     */
    private function register_download_post_type() {
        $args = [
            'label' => __('Downloads', 'wp-license-manager'),
            'labels' => [
                'name' => __('Downloads', 'wp-license-manager'),
                'singular_name' => __('Download', 'wp-license-manager'),
                'add_new' => __('Add New Download', 'wp-license-manager'),
                'edit_item' => __('Edit Download', 'wp-license-manager'),
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'downloads'],
            'taxonomies' => ['download_category', 'download_tag']
        ];

        register_post_type('wplm_download', $args);
        
        // Register taxonomies
        register_taxonomy('download_category', 'wplm_download', [
            'label' => __('Download Categories', 'wp-license-manager'),
            'hierarchical' => true,
            'public' => true
        ]);
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Orders table
        $orders_table = $wpdb->prefix . 'wplm_orders';
        $orders_sql = "CREATE TABLE $orders_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_number varchar(50) NOT NULL,
            customer_email varchar(100) NOT NULL,
            customer_name varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            payment_method varchar(50) NOT NULL,
            total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_number (order_number)
        ) $charset_collate;";
        
        // Order items table
        $order_items_table = $wpdb->prefix . 'wplm_order_items';
        $order_items_sql = "CREATE TABLE $order_items_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            download_id bigint(20) NOT NULL,
            download_name varchar(200) NOT NULL,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            license_key varchar(100) NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($orders_sql);
        dbDelta($order_items_sql);
    }

    /**
     * Initialize cart session
     */
    private function init_cart_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        if (!isset($_SESSION['wplm_cart'])) {
            $_SESSION['wplm_cart'] = [];
        }
    }

    /**
     * Setup download endpoints
     */
    private function setup_download_endpoints() {
        // This method will now primarily handle template redirects, if needed, or other non-rewrite actions.
        add_action('template_redirect', [$this, 'handle_checkout_page']);
    }

    /**
     * Setup rewrite rules for checkout and other endpoints.
     * This method is hooked directly to the 'init' action.
     */
    public function setup_rewrite_rules() {
        add_rewrite_rule(
            '^wplm-checkout/?$',
            'index.php?wplm_checkout=1',
            'top'
        );
        
        // Removed: add_query_var('wplm_checkout'); // Now handled by query_vars filter
    }

    /**
     * Register custom query vars
     */
    public function register_query_vars($vars) {
        $vars[] = 'wplm_checkout';
        return $vars;
    }

    /**
     * Load payment gateways
     */
    private function load_payment_gateways() {
        $this->payment_gateways = [
            'paypal' => new WPLM_PayPal_Gateway(),
            'test' => new WPLM_Test_Gateway()
        ];
    }

    /**
     * Download shortcode [wplm_download id="123"]
     */
    public function download_shortcode($atts) {
        $atts = shortcode_atts(['id' => ''], $atts);

        if (empty($atts['id'])) {
            return '<p>' . __('Download ID is required.', 'wp-license-manager') . '</p>';
        }

        $download = get_post($atts['id']);
        
        if (!$download || $download->post_type !== 'wplm_download') {
            return '<p>' . __('Download not found.', 'wp-license-manager') . '</p>';
        }

        return $this->render_download_item($download);
    }

    /**
     * Downloads list shortcode [wplm_downloads]
     */
    public function downloads_list_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 10,
            'category' => ''
        ], $atts);

        $args = [
            'post_type' => 'wplm_download',
            'post_status' => 'publish',
            'posts_per_page' => intval($atts['limit'])
        ];

        if (!empty($atts['category'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'download_category',
                'field' => 'slug',
                'terms' => $atts['category']
            ];
        }

        $downloads = new WP_Query($args);
        
        if (!$downloads->have_posts()) {
            return '<p>' . __('No downloads found.', 'wp-license-manager') . '</p>';
        }

        ob_start();
        ?>
        <div class="wplm-downloads-list">
            <?php while ($downloads->have_posts()): $downloads->the_post(); ?>
                <?php echo $this->render_download_item(get_post()); ?>
            <?php endwhile; ?>
        </div>
        <?php wp_reset_postdata(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Cart shortcode [wplm_cart]
     */
    public function cart_shortcode($atts) {
        $cart_items = $this->get_cart_items();
        
        if (empty($cart_items)) {
            return '<div class="wplm-cart-empty">
                <p>' . __('Your cart is empty.', 'wp-license-manager') . '</p>
            </div>';
        }

        return $this->render_cart($cart_items);
    }

    /**
     * Checkout shortcode [wplm_checkout]
     */
    public function checkout_shortcode($atts) {
        $cart_items = $this->get_cart_items();
        
        if (empty($cart_items)) {
            return '<p>' . __('Your cart is empty.', 'wp-license-manager') . '</p>';
        }

        return $this->render_checkout_form($cart_items);
    }

    /**
     * Customer dashboard shortcode [wplm_customer_dashboard]
     */
    public function customer_dashboard_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to access your dashboard.', 'wp-license-manager') . '</p>';
        }

        $user = wp_get_current_user();
        $orders = $this->get_customer_orders_by_email($user->user_email);
        
        return $this->render_customer_dashboard($orders);
    }

    /**
     * Render download item
     */
    private function render_download_item($download) {
        $price = get_post_meta($download->ID, '_wplm_download_price', true);
        $is_free = empty($price) || $price == '0';
        
        ob_start();
        ?>
        <div class="wplm-download-item">
            <?php if (has_post_thumbnail($download->ID)): ?>
            <div class="wplm-download-image">
                <?php echo get_the_post_thumbnail($download->ID, 'medium'); ?>
            </div>
            <?php endif; ?>
            
            <div class="wplm-download-content">
                <h3><?php echo esc_html($download->post_title); ?></h3>
                
                <?php if (!empty($download->post_excerpt)): ?>
                <div class="wplm-download-description">
                    <?php echo wp_kses_post($download->post_excerpt); ?>
                </div>
                <?php endif; ?>
                
                <div class="wplm-download-price">
                    <?php if ($is_free): ?>
                        <span class="wplm-price-free"><?php _e('Free', 'wp-license-manager'); ?></span>
                    <?php else: ?>
                        <span class="wplm-price"><?php echo $this->format_price($price); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="wplm-download-actions">
                    <button class="wplm-add-to-cart-btn" data-download-id="<?php echo $download->ID; ?>">
                        <?php echo $is_free ? __('Download Now', 'wp-license-manager') : __('Add to Cart', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render cart
     */
    private function render_cart($cart_items) {
        $total = 0;
        foreach ($cart_items as $item) {
            $total += $item['total'];
        }
        
        ob_start();
        ?>
        <div class="wplm-cart">
            <h3><?php _e('Shopping Cart', 'wp-license-manager'); ?></h3>
            
            <div class="wplm-cart-items">
                <?php foreach ($cart_items as $item): ?>
                <div class="wplm-cart-item">
                    <span class="item-name"><?php echo esc_html($item['title']); ?></span>
                    <span class="item-price"><?php echo $this->format_price($item['price']); ?></span>
                    <button class="remove-item" data-download-id="<?php echo $item['download_id']; ?>">
                        <?php _e('Remove', 'wp-license-manager'); ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="wplm-cart-total">
                <strong><?php _e('Total:', 'wp-license-manager'); ?> <?php echo $this->format_price($total); ?></strong>
            </div>
            
            <div class="wplm-cart-actions">
                <a href="<?php echo home_url('/wplm-checkout/'); ?>" class="wplm-checkout-btn">
                    <?php _e('Proceed to Checkout', 'wp-license-manager'); ?>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render checkout form
     */
    private function render_checkout_form($cart_items) {
        $total = 0;
        foreach ($cart_items as $item) {
            $total += $item['total'];
        }
        
        ob_start();
        ?>
        <div class="wplm-checkout">
            <h3><?php _e('Checkout', 'wp-license-manager'); ?></h3>
            
            <form id="wplm-checkout-form">
                <div class="wplm-billing-details">
                    <h4><?php _e('Billing Details', 'wp-license-manager'); ?></h4>
                    
                    <p>
                        <label><?php _e('Email Address', 'wp-license-manager'); ?> *</label>
                        <input type="email" name="customer_email" required />
                    </p>
                    
                    <p>
                        <label><?php _e('Full Name', 'wp-license-manager'); ?> *</label>
                        <input type="text" name="customer_name" required />
                    </p>
                </div>
                
                <div class="wplm-order-summary">
                    <h4><?php _e('Order Summary', 'wp-license-manager'); ?></h4>
                    
                    <?php foreach ($cart_items as $item): ?>
                    <div class="order-item">
                        <span><?php echo esc_html($item['title']); ?></span>
                        <span><?php echo $this->format_price($item['price']); ?></span>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="order-total">
                        <strong><?php _e('Total:', 'wp-license-manager'); ?> <?php echo $this->format_price($total); ?></strong>
                    </div>
                </div>
                
                <div class="wplm-payment-methods">
                    <h4><?php _e('Payment Method', 'wp-license-manager'); ?></h4>
                    
                    <label>
                        <input type="radio" name="payment_method" value="paypal" checked />
                        <?php _e('PayPal', 'wp-license-manager'); ?>
                    </label>
                    
                    <label>
                        <input type="radio" name="payment_method" value="test" />
                        <?php _e('Test Payment', 'wp-license-manager'); ?>
                    </label>
                </div>
                
                <div class="wplm-checkout-actions">
                    <button type="submit" class="wplm-place-order-btn">
                        <?php _e('Place Order', 'wp-license-manager'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get cart items
     */
    public function get_cart_items() {
        $cart = $_SESSION['wplm_cart'] ?? [];
        $items = [];
        
        foreach ($cart as $download_id => $quantity) {
            $download = get_post($download_id);
            if ($download && $download->post_type === 'wplm_download') {
                $price = get_post_meta($download_id, '_wplm_download_price', true);
                
                $items[] = [
                    'download_id' => $download_id,
                    'title' => $download->post_title,
                    'quantity' => $quantity,
                    'price' => floatval($price),
                    'total' => floatval($price) * $quantity
                ];
            }
        }
        
        return $items;
    }

    /**
     * AJAX: Add to cart
     */
    public function ajax_add_to_cart() {
        check_ajax_referer('wplm_add_to_cart', 'nonce');
        
        $download_id = intval($_POST['download_id']);
        
        $download = get_post($download_id);
        if (!$download || $download->post_type !== 'wplm_download') {
            wp_send_json_error(['message' => __('Invalid download.', 'wp-license-manager')]);
        }
        
        // Add to cart
        if (!isset($_SESSION['wplm_cart'])) {
            $_SESSION['wplm_cart'] = [];
        }
        
        $_SESSION['wplm_cart'][$download_id] = 1;
        
        wp_send_json_success(['message' => __('Added to cart!', 'wp-license-manager')]);
    }

    /**
     * AJAX: Remove from cart
     */
    public function ajax_remove_from_cart() {
        check_ajax_referer('wplm_remove_from_cart', 'nonce');
        
        $download_id = intval($_POST['download_id']);
        
        if (isset($_SESSION['wplm_cart'][$download_id])) {
            unset($_SESSION['wplm_cart'][$download_id]);
            wp_send_json_success(['message' => __('Removed from cart!', 'wp-license-manager')]);
        } else {
            wp_send_json_error(['message' => __('Item not found in cart.', 'wp-license-manager')]);
        }
    }

    /**
     * AJAX: Get cart count
     */
    public function ajax_get_cart_count() {
        $count = isset($_SESSION['wplm_cart']) ? count($_SESSION['wplm_cart']) : 0;
        wp_send_json_success(['count' => $count]);
    }

    /**
     * AJAX: Process checkout
     */
    public function ajax_process_checkout() {
        check_ajax_referer('wplm_process_checkout', 'nonce');
        
        $cart_items = $this->get_cart_items();
        
        if (empty($cart_items)) {
            wp_send_json_error(['message' => __('Cart is empty.', 'wp-license-manager')]);
        }
        
        $checkout_data = [
            'customer_email' => sanitize_email($_POST['customer_email']),
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'payment_method' => sanitize_text_field($_POST['payment_method'])
        ];
        
        if (empty($checkout_data['customer_email']) || !is_email($checkout_data['customer_email'])) {
            wp_send_json_error(['message' => __('Valid email address is required.', 'wp-license-manager')]);
        }
        
        // Create order
        $order_id = $this->create_order($cart_items, $checkout_data);
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Failed to create order.', 'wp-license-manager')]);
        }
        
        // Process payment
        $payment_result = $this->process_payment($order_id, $checkout_data);
        
        if ($payment_result['success']) {
            $_SESSION['wplm_cart'] = [];
            
            wp_send_json_success([
                'message' => __('Order completed successfully!', 'wp-license-manager'),
                'order_id' => $order_id
            ]);
        } else {
            wp_send_json_error(['message' => __('Payment failed.', 'wp-license-manager')]);
        }
    }

    /**
     * Create order
     */
    private function create_order($cart_items, $checkout_data) {
        global $wpdb;
        
        $total_amount = 0;
        foreach ($cart_items as $item) {
            $total_amount += $item['total'];
        }
        
        $order_number = 'WPLM-' . time() . '-' . wp_rand(1000, 9999);
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'wplm_orders',
            [
                'order_number' => $order_number,
                'customer_email' => $checkout_data['customer_email'],
                'customer_name' => $checkout_data['customer_name'],
                'payment_method' => $checkout_data['payment_method'],
                'total_amount' => $total_amount,
                'created_at' => current_time('mysql')
            ]
        );
        
        if (!$result) {
            return false;
        }
        
        $order_id = $wpdb->insert_id;
        
        // Add order items
        foreach ($cart_items as $item) {
            $license_key = $this->generate_license_key();
            
            $wpdb->insert(
                $wpdb->prefix . 'wplm_order_items',
                [
                    'order_id' => $order_id,
                    'download_id' => $item['download_id'],
                    'download_name' => $item['title'],
                    'price' => $item['price'],
                    'license_key' => $license_key
                ]
            );
            
            // Create WPLM license
            $this->create_wplm_license($item, $license_key, $checkout_data);
        }
        
        return $order_id;
    }

    /**
     * Create WPLM license
     */
    private function create_wplm_license($item, $license_key, $checkout_data) {
        $license_data = [
            'post_title' => $license_key,
            'post_type' => 'wplm_license',
            'post_status' => 'publish'
        ];

        $license_id = wp_insert_post($license_data);

        if (!is_wp_error($license_id)) {
            update_post_meta($license_id, '_wplm_status', 'active');
            update_post_meta($license_id, '_wplm_customer_email', $checkout_data['customer_email']);
            update_post_meta($license_id, '_wplm_product_id', $item['download_id']);
            update_post_meta($license_id, '_wplm_activation_limit', 1);
            update_post_meta($license_id, '_wplm_activated_domains', []);
        }
        
        return $license_id;
    }

    /**
     * Generate license key
     */
    private function generate_license_key() {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8) . '-' . 
                         substr(md5(uniqid(mt_rand(), true)), 0, 8) . '-' . 
                         substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }

    /**
     * Process payment
     */
    private function process_payment($order_id, $checkout_data) {
        $gateway = $this->payment_gateways[$checkout_data['payment_method']] ?? null;
        
        if (!$gateway) {
            return ['success' => false, 'message' => 'Invalid payment method'];
        }
        
        return $gateway->process_payment($order_id);
    }

    /**
     * Format price
     */
    private function format_price($price) {
        return '$' . number_format(floatval($price), 2);
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_script('wplm-downloads', WPLM_URL . 'assets/js/downloads.js', ['jquery'], WPLM_VERSION, true);
        wp_enqueue_style('wplm-downloads', WPLM_URL . 'assets/css/downloads.css', [], WPLM_VERSION);
        
        wp_localize_script('wplm-downloads', 'wplm_downloads', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'add_to_cart_nonce' => wp_create_nonce('wplm_add_to_cart'),
            'remove_from_cart_nonce' => wp_create_nonce('wplm_remove_from_cart'),
            'process_checkout_nonce' => wp_create_nonce('wplm_process_checkout'),
            'wplm_downloads_nonce' => wp_create_nonce('wplm_downloads_nonce'), // Keep this for existing shortcodes if used
        ]);
    }

    /**
     * Get customer orders by email
     */
    private function get_customer_orders_by_email($email) {
        global $wpdb;
        
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wplm_orders WHERE customer_email = %s ORDER BY created_at DESC",
            $email
        ));
        
        foreach ($orders as $order) {
            // Initialize items array to prevent errors if no items are found
            $order->items = []; 
        }
        
        if (!empty($orders)) {
            $order_ids = wp_list_pluck($orders, 'id');
            $order_ids_placeholder = implode(', ', array_fill(0, count($order_ids), '%d'));
            $order_items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wplm_order_items WHERE order_id IN ($order_ids_placeholder)",
                ...$order_ids
            ));

            foreach ($orders as $order) {
                $order->items = array_filter($order_items, function($item) use ($order) {
                    return (int) $item->order_id === (int) $order->id;
                });
            }
         }

         return $orders;
     }

    /**
     * Render customer dashboard
     */
    private function render_customer_dashboard($orders) {
        ob_start();
        ?>
        <div class="wplm-customer-dashboard">
            <div class="wplm-dashboard-header">
                <h3><?php _e('My Downloads & Licenses', 'wp-license-manager'); ?></h3>
            </div>
            
            <?php if (empty($orders)): ?>
                <p><?php _e('You have no orders yet.', 'wp-license-manager'); ?></p>
            <?php else: ?>
                <div class="wplm-orders-list">
                    <?php foreach ($orders as $order): ?>
                    <div class="wplm-order-item">
                        <div class="wplm-order-header">
                            <span class="wplm-order-number"><?php echo esc_html($order->order_number); ?></span>
                            <span class="wplm-order-date"><?php echo date('F j, Y', strtotime($order->created_at)); ?></span>
                        </div>
                        
                        <div class="wplm-order-details">
                            <?php foreach ($order->items as $item): ?>
                            <div class="wplm-order-item-detail">
                                <strong><?php echo esc_html($item->download_name); ?></strong> - 
                                <?php echo $this->format_price($item->price); ?>
                                
                                <?php if (!empty($item->license_key)): ?>
                                <div class="wplm-license-info">
                                    <strong><?php _e('License Key:', 'wp-license-manager'); ?></strong>
                                    <div class="wplm-license-key"><?php echo esc_html($item->license_key); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle checkout page template
     */
    public function handle_checkout_page() {
        if (get_query_var('wplm_checkout')) {
            $template = locate_template('wplm-checkout.php');
            if (!$template) {
                $template = WPLM_PLUGIN_DIR . 'templates/wplm-checkout.php'; // Fallback to plugin's default template
            }
            // Load checkout template
            include $template;
            exit;
        }
    }
}

/**
 * Simple Payment Gateway Classes
 */
class WPLM_PayPal_Gateway {
    public function process_payment($order_id) {
        // Simplified PayPal integration
        // In a real scenario, this would integrate with PayPal's API.
        // For now, it just simulates a successful payment.
        return ['success' => true, 'message' => 'PayPal payment processed'];
    }
}

class WPLM_Test_Gateway {
    public function process_payment($order_id) {
        // Simple test gateway for development
        return ['success' => true, 'message' => 'Test payment processed'];
    }
}