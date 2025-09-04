<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprehensive REST API Manager for WPLM
 */
class WPLM_REST_API_Manager {

    private $namespace = 'wplm/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Ensure sessions are closed before REST API requests
        add_action('rest_api_init', [$this, 'close_sessions_for_rest_api']);
    }
    
    /**
     * Close any active sessions before REST API requests
     */
    public function close_sessions_for_rest_api() {
        if (session_id()) {
            session_write_close();
        }
    }

    /**
     * Register all REST API routes
     */
    public function register_routes() {
        // License Management Routes
        register_rest_route($this->namespace, '/licenses', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_licenses'],
                'permission_callback' => [$this, 'check_api_permissions'],
                'args' => $this->get_collection_params()
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_license'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args' => $this->get_license_schema()
            ]
        ]);

        register_rest_route($this->namespace, '/licenses/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_license'],
                'permission_callback' => [$this, 'check_api_permissions'],
                'args' => ['id' => ['validate_callback' => 'is_numeric']]
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_license'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args' => $this->get_license_schema()
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_license'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args' => ['id' => ['validate_callback' => 'is_numeric']]
            ]
        ]);

        // License Key Lookup
        register_rest_route($this->namespace, '/licenses/key/(?P<key>[a-zA-Z0-9\-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_license_by_key'],
            'permission_callback' => [$this, 'check_api_permissions'],
            'args' => ['key' => ['sanitize_callback' => 'sanitize_text_field']]
        ]);

        // License Activation/Deactivation
        register_rest_route($this->namespace, '/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activate_license'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'license_key' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'product_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'version' => ['sanitize_callback' => 'sanitize_text_field']
            ]
        ]);

        register_rest_route($this->namespace, '/deactivate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'deactivate_license'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'license_key' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
            ]
        ]);

        // License Validation
        register_rest_route($this->namespace, '/validate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'validate_license'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'license_key' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'product_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
            ]
        ]);

        // License Information
        register_rest_route($this->namespace, '/info', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'get_license_info'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'license_key' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
            ]
        ]);

        // Product Management Routes
        register_rest_route($this->namespace, '/products', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_products'],
                'permission_callback' => [$this, 'check_api_permissions'],
                'args' => $this->get_collection_params()
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_product'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args' => $this->get_product_schema()
            ]
        ]);

        register_rest_route($this->namespace, '/products/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_product'],
                'permission_callback' => [$this, 'check_api_permissions'],
                'args' => ['id' => ['validate_callback' => 'is_numeric']]
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_product'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args' => $this->get_product_schema()
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_product'],
                'permission_callback' => [$this, 'check_create_permissions'],
                'args' => ['id' => ['validate_callback' => 'is_numeric']]
            ]
        ]);

        // Customer Management Routes
        register_rest_route($this->namespace, '/customers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_customers'],
            'permission_callback' => [$this, 'check_api_permissions'],
            'args' => $this->get_collection_params()
        ]);

        register_rest_route($this->namespace, '/customers/(?P<email>[^/]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_customer'],
            'permission_callback' => [$this, 'check_api_permissions'],
            'args' => ['email' => ['sanitize_callback' => 'sanitize_email']]
        ]);

        // Analytics and Statistics
        register_rest_route($this->namespace, '/analytics/overview', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_analytics_overview'],
            'permission_callback' => [$this, 'check_api_permissions']
        ]);

        register_rest_route($this->namespace, '/analytics/licenses', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_license_analytics'],
            'permission_callback' => [$this, 'check_api_permissions'],
            'args' => [
                'period' => ['default' => '30days', 'sanitize_callback' => 'sanitize_text_field'],
                'product_id' => ['sanitize_callback' => 'sanitize_text_field']
            ]
        ]);

        // Bulk Operations
        register_rest_route($this->namespace, '/bulk/licenses', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_create_licenses'],
            'permission_callback' => [$this, 'check_create_permissions'],
            'args' => [
                'count' => ['required' => true, 'validate_callback' => 'is_numeric'],
                'product_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'customer_email' => ['sanitize_callback' => 'sanitize_email'],
                'duration_type' => ['default' => 'lifetime', 'sanitize_callback' => 'sanitize_text_field'],
                'duration_value' => ['default' => 1, 'validate_callback' => 'is_numeric'],
                'activation_limit' => ['default' => 1, 'validate_callback' => 'is_numeric']
            ]
        ]);

        // Export/Import Routes
        register_rest_route($this->namespace, '/export', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'export_data'],
            'permission_callback' => [$this, 'check_create_permissions'],
            'args' => [
                'type' => ['default' => 'all', 'sanitize_callback' => 'sanitize_text_field'],
                'format' => ['default' => 'json', 'sanitize_callback' => 'sanitize_text_field']
            ]
        ]);

        // System Information
        register_rest_route($this->namespace, '/system/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_system_status'],
            'permission_callback' => [$this, 'check_api_permissions']
        ]);
    }

    /**
     * Check API permissions
     */
    public function check_api_permissions($request) {
        // Check if REST API is enabled
        if (!get_option('wplm_rest_api_enabled', true)) {
            return new WP_Error('api_disabled', 'REST API is disabled', ['status' => 403]);
        }

        // Check API key
        $api_key = $request->get_header('X-API-Key') ?: $request->get_param('api_key');
        $stored_api_key = get_option('wplm_api_key', '');

        if (empty($api_key) || empty($stored_api_key) || !hash_equals($stored_api_key, $api_key)) {
            return new WP_Error('invalid_api_key', 'Invalid API key', ['status' => 401]);
        }

        return true;
    }

    /**
     * Check create/modify permissions
     */
    public function check_create_permissions($request) {
        $api_check = $this->check_api_permissions($request);
        if (is_wp_error($api_check)) {
            return $api_check;
        }

        // Additional permission check for write operations
        if (!current_user_can('manage_wplm_licenses')) {
            return new WP_Error('insufficient_permissions', 'Insufficient permissions', ['status' => 403]);
        }

        return true;
    }

    /**
     * Get licenses
     */
    public function get_licenses($request) {
        $page = $request->get_param('page') ?: 1;
        $per_page = min($request->get_param('per_page') ?: 10, 100);
        $status = $request->get_param('status');
        $product_id = $request->get_param('product_id');
        $customer_email = $request->get_param('customer_email');

        $args = [
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'meta_query' => []
        ];

        if ($status) {
            $args['meta_query'][] = [
                'key' => '_wplm_status',
                'value' => $status,
                'compare' => '='
            ];
        }

        if ($product_id) {
            $args['meta_query'][] = [
                'key' => '_wplm_product_id',
                'value' => $product_id,
                'compare' => '='
            ];
        }

        if ($customer_email) {
            $args['meta_query'][] = [
                'key' => '_wplm_customer_email',
                'value' => $customer_email,
                'compare' => '='
            ];
        }

        $query = new WP_Query($args);
        $licenses = [];

        foreach ($query->posts as $post) {
            $licenses[] = $this->prepare_license_response($post);
        }

        $response = rest_ensure_response($licenses);
        $response->header('X-WP-Total', $query->found_posts);
        $response->header('X-WP-TotalPages', $query->max_num_pages);

        return $response;
    }

    /**
     * Get single license
     */
    public function get_license($request) {
        $license_id = $request->get_param('id');
        $license = get_post($license_id);

        if (!$license || $license->post_type !== 'wplm_license') {
            return new WP_Error('license_not_found', 'License not found', ['status' => 404]);
        }

        return rest_ensure_response($this->prepare_license_response($license));
    }

    /**
     * Get license by key
     */
    public function get_license_by_key($request) {
        $license_key = $request->get_param('key');
        $license = wplm_get_post_by_title($license_key, 'wplm_license');

        if (!$license) {
            return new WP_Error('license_not_found', 'License not found', ['status' => 404]);
        }

        return rest_ensure_response($this->prepare_license_response($license));
    }

    /**
     * Create license
     */
    public function create_license($request) {
        $product_id = $request->get_param('product_id');
        $customer_email = $request->get_param('customer_email');
        $duration_type = $request->get_param('duration_type') ?: 'lifetime';
        $duration_value = $request->get_param('duration_value') ?: 1;
        $activation_limit = $request->get_param('activation_limit') ?: 1;
        $status = $request->get_param('status') ?: 'active';

        if (empty($product_id)) {
            return new WP_Error('missing_product', 'Product ID is required', ['status' => 400]);
        }

        try {
            // Generate unique license key
            $license_key = $this->generate_license_key();
            $attempts = 0;
            $license_posts = get_posts([
                'post_type' => 'wplm_license',
                'title' => $license_key,
                'posts_per_page' => 1,
                'post_status' => 'publish'
            ]);
            while (!empty($license_posts) && $attempts < 5) {
                $attempts++;
                $license_key = $this->generate_license_key();
                $license_posts = get_posts([
                    'post_type' => 'wplm_license',
                    'title' => $license_key,
                    'posts_per_page' => 1,
                    'post_status' => 'publish'
                ]);
            }

            // Create license post
            $license_id = wp_insert_post([
                'post_title' => $license_key,
                'post_type' => 'wplm_license',
                'post_status' => 'publish'
            ]);

            if (is_wp_error($license_id)) {
                return new WP_Error('creation_failed', 'Failed to create license', ['status' => 500]);
            }

            // Set license meta
            update_post_meta($license_id, '_wplm_status', $status);
            update_post_meta($license_id, '_wplm_product_id', $product_id);
            update_post_meta($license_id, '_wplm_activation_limit', $activation_limit);
            update_post_meta($license_id, '_wplm_activated_domains', []);
            
            if (!empty($customer_email)) {
                update_post_meta($license_id, '_wplm_customer_email', $customer_email);
            }

            // Set expiry date
            if ($duration_type !== 'lifetime') {
                $expiry_date = $this->calculate_expiry_date($duration_type, $duration_value);
                update_post_meta($license_id, '_wplm_expiry_date', $expiry_date);
            }

            // Log activity
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log($license_id, 'license_created', 'License created via REST API', [
                    'product_id' => $product_id,
                    'customer_email' => $customer_email,
                    'api_created' => true
                ]);
            }

            $license = get_post($license_id);
            return rest_ensure_response($this->prepare_license_response($license));

        } catch (Exception $e) {
            return new WP_Error('creation_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Activate license
     */
    public function activate_license($request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        $product_id = $request->get_param('product_id');
        $version = $request->get_param('version');

        // Find license
        $license_posts = get_posts([
            'post_type' => 'wplm_license',
            'title' => $license_key,
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);
        if (empty($license_posts)) {
            return new WP_Error('invalid_license', 'Invalid license key', ['status' => 404]);
        }
        $license = $license_posts[0];

        // Check status
        $status = get_post_meta($license->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return new WP_Error('license_inactive', 'License is not active', ['status' => 403]);
        }

        // Check expiry
        $expiry_date = get_post_meta($license->ID, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < current_time('timestamp')) {
            return new WP_Error('license_expired', 'License has expired', ['status' => 403]);
        }

        // Check product match with improved logic
        $license_product = get_post_meta($license->ID, '_wplm_product_id', true);
        $product_match = false;
        
        // Direct match
        if ($license_product === $product_id) {
            $product_match = true;
        } else {
            // Try to find WPLM product by the provided product_id
            $wplm_product = get_page_by_title($product_id, OBJECT, 'wplm_product');
            if ($wplm_product && $wplm_product->post_name === $license_product) {
                $product_match = true;
            } else {
                // Check if product_id is a WPLM product ID
                $wplm_product_by_id = get_post($product_id);
                if ($wplm_product_by_id && $wplm_product_by_id->post_type === 'wplm_product') {
                    if ($wplm_product_by_id->post_name === $license_product) {
                        $product_match = true;
                    }
                }
            }
        }
        
        if (!$product_match) {
            return new WP_Error('product_mismatch', 'License not valid for this product. Expected: ' . $license_product . ', Received: ' . $product_id, ['status' => 403]);
        }

        // Check activation limit
        $activated_domains = get_post_meta($license->ID, '_wplm_activated_domains', true) ?: [];
        $activation_limit = get_post_meta($license->ID, '_wplm_activation_limit', true) ?: 1;

        if (!in_array($domain, $activated_domains)) {
            if (count($activated_domains) >= $activation_limit && $activation_limit !== -1) {
                return new WP_Error('activation_limit_exceeded', 'Activation limit exceeded', ['status' => 403]);
            }
            
            $activated_domains[] = $domain;
            update_post_meta($license->ID, '_wplm_activated_domains', $activated_domains);
        }

        // Log activation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log($license->ID, 'license_activated', 'License activated via API', [
                'domain' => $domain,
                'product_id' => $product_id,
                'version' => $version,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        // Send notification
        do_action('wplm_license_activated', $license->ID, $domain);

        return rest_ensure_response([
            'success' => true,
            'message' => 'License activated successfully',
            'license_key' => $license_key,
            'expires' => $expiry_date ?: 'never',
            'activations_remaining' => $activation_limit === -1 ? 'unlimited' : ($activation_limit - count($activated_domains))
        ]);
    }

    /**
     * Deactivate license
     */
    public function deactivate_license($request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');

        // Find license
        $license_posts = get_posts([
            'post_type' => 'wplm_license',
            'title' => $license_key,
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);
        if (empty($license_posts)) {
            return new WP_Error('invalid_license', 'Invalid license key', ['status' => 404]);
        }
        $license = $license_posts[0];

        // Remove domain from activated domains
        $activated_domains = get_post_meta($license->ID, '_wplm_activated_domains', true) ?: [];
        $activated_domains = array_diff($activated_domains, [$domain]);
        update_post_meta($license->ID, '_wplm_activated_domains', $activated_domains);

        // Log deactivation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log($license->ID, 'license_deactivated', 'License deactivated via API', [
                'domain' => $domain,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        // Send notification
        do_action('wplm_license_deactivated', $license->ID, $domain);
        
        // Trigger automatic plugin deactivation
        do_action('wplm_license_deactivated_for_plugin_deactivation', $license->ID, $domain, $license_key);

        return rest_ensure_response([
            'success' => true,
            'message' => 'License deactivated successfully',
            'license_key' => $license_key
        ]);
    }

    /**
     * Validate license
     */
    public function validate_license($request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        $product_id = $request->get_param('product_id');

        // Find license
        $license = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license) {
            return rest_ensure_response([
                'valid' => false,
                'error' => 'invalid_license',
                'message' => 'Invalid license key'
            ]);
        }

        // Check status
        $status = get_post_meta($license->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return rest_ensure_response([
                'valid' => false,
                'error' => 'license_inactive',
                'message' => 'License is not active'
            ]);
        }

        // Check expiry
        $expiry_date = get_post_meta($license->ID, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < current_time('timestamp')) {
            return rest_ensure_response([
                'valid' => false,
                'error' => 'license_expired',
                'message' => 'License has expired'
            ]);
        }

        // Check product match with improved logic
        $license_product = get_post_meta($license->ID, '_wplm_product_id', true);
        $product_match = false;
        
        // Direct match
        if ($license_product === $product_id) {
            $product_match = true;
        } else {
            // Try to find WPLM product by the provided product_id
            $wplm_product = get_posts([
                'post_type' => 'wplm_product',
                'name' => $product_id,
                'posts_per_page' => 1,
                'post_status' => 'publish'
            ]);
            
            if (!empty($wplm_product)) {
                $wplm_product_slug = get_post_meta($wplm_product[0]->ID, '_wplm_product_id', true);
                if ($wplm_product_slug === $license_product) {
                    $product_match = true;
                }
            } else {
                // Check if product_id is a WPLM product ID
                $wplm_product_by_id = get_post($product_id);
                if ($wplm_product_by_id && $wplm_product_by_id->post_type === 'wplm_product') {
                    $wplm_product_slug = get_post_meta($wplm_product_by_id->ID, '_wplm_product_id', true);
                    if ($wplm_product_slug === $license_product) {
                        $product_match = true;
                    }
                }
            }
        }
        
        if (!$product_match) {
            return rest_ensure_response([
                'valid' => false,
                'error' => 'product_mismatch',
                'message' => 'License not valid for this product. Expected: ' . $license_product . ', Received: ' . $product_id
            ]);
        }

        // Check domain activation
        $activated_domains = get_post_meta($license->ID, '_wplm_activated_domains', true) ?: [];
        $activation_limit = get_post_meta($license->ID, '_wplm_activation_limit', true) ?: 1;
        
        // If activation limit is unlimited (-1), skip domain enforcement
        if ($activation_limit !== -1 && !in_array($domain, $activated_domains)) {
            return rest_ensure_response([
                'valid' => false,
                'error' => 'domain_not_activated',
                'message' => 'Domain not activated for this license'
            ]);
        }

        return rest_ensure_response([
            'valid' => true,
            'license_key' => $license_key,
            'expires' => $expiry_date ?: 'never',
            'status' => $status
        ]);
    }

    /**
     * Get license info
     */
    public function get_license_info($request) {
        $license_key = $request->get_param('license_key');

        // Find license
        $license_posts = get_posts([
            'post_type' => 'wplm_license',
            'title' => $license_key,
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);
        if (empty($license_posts)) {
            return new WP_Error('invalid_license', 'Invalid license key', ['status' => 404]);
        }
        $license = $license_posts[0];

        return rest_ensure_response($this->prepare_license_response($license));
    }

    /**
     * Get analytics overview
     */
    public function get_analytics_overview($request) {
        global $wpdb;

        $total_licenses = wp_count_posts('wplm_license')->publish;
        
        $active_licenses = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_status' 
             AND pm.meta_value = 'active'"
        );

        $expired_licenses = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_expiry_date' 
             AND pm.meta_value != '' 
             AND pm.meta_value < %s",
            date('Y-m-d')
        ));

        $total_products = wp_count_posts('wplm_product')->publish;

        // Get unique customers
        $total_customers = $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_customer_email' 
             AND pm.meta_value != ''"
        );

        return rest_ensure_response([
            'total_licenses' => intval($total_licenses),
            'active_licenses' => intval($active_licenses),
            'expired_licenses' => intval($expired_licenses),
            'total_products' => intval($total_products),
            'total_customers' => intval($total_customers),
            'generated_at' => current_time('mysql')
        ]);
    }

    /**
     * Prepare license response
     */
    private function prepare_license_response($license) {
        $license_data = [
            'id' => $license->ID,
            'license_key' => $license->post_title,
            'status' => get_post_meta($license->ID, '_wplm_status', true),
            'product_id' => get_post_meta($license->ID, '_wplm_product_id', true),
            'customer_email' => get_post_meta($license->ID, '_wplm_customer_email', true),
            'activation_limit' => get_post_meta($license->ID, '_wplm_activation_limit', true),
            'activated_domains' => get_post_meta($license->ID, '_wplm_activated_domains', true) ?: [],
            'expiry_date' => get_post_meta($license->ID, '_wplm_expiry_date', true),
            'created_date' => $license->post_date,
            'modified_date' => $license->post_modified
        ];

        // Calculate remaining activations
        $license_data['activations_remaining'] = max(0, intval($license_data['activation_limit']) - count($license_data['activated_domains']));

        // Check if expired
        if (!empty($license_data['expiry_date'])) {
            $license_data['is_expired'] = strtotime($license_data['expiry_date']) < current_time('timestamp');
        } else {
            $license_data['is_expired'] = false;
        }

        return $license_data;
    }

    /**
     * Generate standardized license key
     */
    private function generate_license_key() {
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
     * Calculate expiry date
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
     * Get collection parameters
     */
    private function get_collection_params() {
        return [
            'page' => [
                'description' => 'Current page of the collection.',
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'description' => 'Maximum number of items to be returned in result set.',
                'type' => 'integer',
                'default' => 10,
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'description' => 'Limit results to those matching a string.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'description' => 'Limit result set to licenses with specific status.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'product_id' => [
                'description' => 'Limit result set to licenses for specific product.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'customer_email' => [
                'description' => 'Limit result set to licenses for specific customer.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
            ]
        ];
    }

    /**
     * Get license schema
     */
    private function get_license_schema() {
        return [
            'product_id' => [
                'description' => 'Product identifier for the license.',
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'customer_email' => [
                'description' => 'Customer email address.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
            ],
            'duration_type' => [
                'description' => 'License duration type.',
                'type' => 'string',
                'enum' => ['lifetime', 'days', 'months', 'years'],
                'default' => 'lifetime',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'duration_value' => [
                'description' => 'License duration value.',
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'activation_limit' => [
                'description' => 'Number of domains that can activate this license.',
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'status' => [
                'description' => 'License status.',
                'type' => 'string',
                'enum' => ['active', 'inactive', 'expired'],
                'default' => 'active',
                'sanitize_callback' => 'sanitize_text_field',
            ]
        ];
    }

    /**
     * Get product schema
     */
    private function get_product_schema() {
        return [
            'name' => [
                'description' => 'Product name.',
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'slug' => [
                'description' => 'Product identifier slug.',
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_title',
            ],
            'version' => [
                'description' => 'Current product version.',
                'type' => 'string',
                'default' => '1.0.0',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => [
                'description' => 'Product description.',
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ]
        ];
    }
}
