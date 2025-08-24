<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Licensing - API and Encryption
 * Part 2 of the split WPLM_Advanced_Licensing class
 */
class WPLM_Advanced_Licensing_API {

    private $encryption_key;
    private $api_version = '2.0';

    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
        
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // License response encryption
        add_filter('wplm_license_response', [$this, 'encrypt_license_response'], 10, 2);
        
        // API authentication
        add_filter('wplm_api_authenticate', [$this, 'authenticate_api_request'], 10, 2);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // License validation endpoint
        register_rest_route('wplm/v2', '/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_validate_license'],
            'permission_callback' => '__return_true',
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'domain' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'fingerprint' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // License activation endpoint
        register_rest_route('wplm/v2', '/activate', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_activate_license'],
            'permission_callback' => '__return_true',
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'domain' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // License deactivation endpoint
        register_rest_route('wplm/v2', '/deactivate', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_deactivate_license'],
            'permission_callback' => '__return_true',
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'domain' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // License status endpoint
        register_rest_route('wplm/v2', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_license_status'],
            'permission_callback' => '__return_true',
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // Update check endpoint
        register_rest_route('wplm/v2', '/update-check', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_check_for_updates'],
            'permission_callback' => '__return_true',
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'product_slug' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'current_version' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
    }

    /**
     * REST API license validation
     */
    public function rest_validate_license($request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        $fingerprint = $request->get_param('fingerprint');

        // Basic validation
        if (empty($license_key) || empty($domain) || empty($fingerprint)) {
            return new WP_Error('wplm_missing_params', esc_html__('Missing required parameters.', 'wplm'), ['status' => 400]);
        }

        // Get license post
        $license_post = $this->get_license_by_key($license_key);
        if (!$license_post) {
            return new WP_Error('wplm_invalid_license', esc_html__('Invalid license key.', 'wplm'), ['status' => 404]);
        }

        // Validate license
        $validation_result = $this->validate_license($license_post, $domain, $fingerprint);
        
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        // Prepare response
        $response_data = [
            'success' => true,
            'license_key' => $license_key,
            'domain' => $domain,
            'status' => 'valid',
            'expires' => get_post_meta($license_post->ID, '_wplm_expiry_date', true),
            'features' => $this->get_license_features($license_post->ID),
            'next_check' => $this->calculate_next_check_time($license_post->ID)
        ];

        return rest_ensure_response($response_data);
    }

    /**
     * REST API license activation
     */
    public function rest_activate_license($request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');

        // Basic validation
        if (empty($license_key) || empty($domain)) {
            return new WP_Error('wplm_missing_params', esc_html__('Missing required parameters.', 'wplm'), ['status' => 400]);
        }

        // Get license post
        $license_post = $this->get_license_by_key($license_key);
        if (!$license_post) {
            return new WP_Error('wplm_invalid_license', esc_html__('Invalid license key.', 'wplm'), ['status' => 404]);
        }

        // Activate license
        $activation_result = $this->activate_license($license_post, $domain);
        
        if (is_wp_error($activation_result)) {
            return $activation_result;
        }

        // Prepare response
        $response_data = [
            'success' => true,
            'license_key' => $license_key,
            'domain' => $domain,
            'status' => 'activated',
            'message' => esc_html__('License activated successfully.', 'wplm')
        ];

        return rest_ensure_response($response_data);
    }

    /**
     * REST API license deactivation
     */
    public function rest_deactivate_license($request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');

        // Basic validation
        if (empty($license_key) || empty($domain)) {
            return new WP_Error('wplm_missing_params', esc_html__('Missing required parameters.', 'wplm'), ['status' => 400]);
        }

        // Get license post
        $license_post = $this->get_license_by_key($license_key);
        if (!$license_post) {
            return new WP_Error('wplm_invalid_license', esc_html__('Invalid license key.', 'wplm'), ['status' => 404]);
        }

        // Deactivate license
        $deactivation_result = $this->deactivate_license($license_post, $domain);
        
        if (is_wp_error($deactivation_result)) {
            return $deactivation_result;
        }

        // Prepare response
        $response_data = [
            'success' => true,
            'license_key' => $license_key,
            'domain' => $domain,
            'status' => 'deactivated',
            'message' => esc_html__('License deactivated successfully.', 'wplm')
        ];

        return rest_ensure_response($response_data);
    }

    /**
     * REST API get license status
     */
    public function rest_get_license_status($request) {
        $license_key = $request->get_param('license_key');

        // Basic validation
        if (empty($license_key)) {
            return new WP_Error('wplm_missing_params', esc_html__('Missing license key parameter.', 'wplm'), ['status' => 400]);
        }

        // Get license post
        $license_post = $this->get_license_by_key($license_key);
        if (!$license_post) {
            return new WP_Error('wplm_invalid_license', esc_html__('Invalid license key.', 'wplm'), ['status' => 404]);
        }

        // Get license status
        $status_data = $this->get_license_status_data($license_post);

        return rest_ensure_response($status_data);
    }

    /**
     * REST API check for updates
     */
    public function rest_check_for_updates($request) {
        $license_key = $request->get_param('license_key');
        $product_slug = $request->get_param('product_slug');
        $current_version = $request->get_param('current_version');

        // Basic validation
        if (empty($license_key) || empty($product_slug) || empty($current_version)) {
            return new WP_Error('wplm_missing_params', esc_html__('Missing required parameters.', 'wplm'), ['status' => 400]);
        }

        // Get license post
        $license_post = $this->get_license_by_key($license_key);
        if (!$license_post) {
            return new WP_Error('wplm_invalid_license', esc_html__('Invalid license key.', 'wplm'), ['status' => 404]);
        }

        // Check for updates
        $update_data = $this->check_for_updates($license_post, $product_slug, $current_version);

        return rest_ensure_response($update_data);
    }

    /**
     * Encrypt license response
     */
    public function encrypt_license_response($response_data, $license_key) {
        if (empty($response_data) || !is_array($response_data)) {
            return $response_data;
        }

        // Add encryption metadata
        $response_data['encrypted'] = true;
        $response_data['encryption_version'] = $this->api_version;
        $response_data['timestamp'] = time();

        // Encrypt sensitive data
        $sensitive_keys = ['license_key', 'domain', 'customer_email'];
        foreach ($sensitive_keys as $key) {
            if (isset($response_data[$key])) {
                $response_data[$key] = $this->encrypt_data($response_data[$key]);
            }
        }

        // Add digital signature
        $response_data['signature'] = $this->generate_digital_signature($response_data, $license_key);

        return $response_data;
    }

    /**
     * Authenticate API request
     */
    public function authenticate_api_request($request, $license_key) {
        // Check if request has valid signature
        $signature = $request->get_header('X-WPLM-Signature');
        if (empty($signature)) {
            return new WP_Error('wplm_missing_signature', esc_html__('Missing authentication signature.', 'wplm'), ['status' => 401]);
        }

        // Verify signature
        if (!$this->verify_digital_signature($request, $signature, $license_key)) {
            return new WP_Error('wplm_invalid_signature', esc_html__('Invalid authentication signature.', 'wplm'), ['status' => 401]);
        }

        return true;
    }

    /**
     * Get license by key
     */
    private function get_license_by_key($license_key) {
        $args = [
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_wplm_license_key',
                    'value' => $license_key,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1
        ];

        $licenses = get_posts($args);
        return !empty($licenses) ? $licenses[0] : false;
    }

    /**
     * Validate license
     */
    private function validate_license($license_post, $domain, $fingerprint) {
        // Check if license is active
        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return new WP_Error('wplm_inactive_license', esc_html__('License is not active.', 'wplm'), ['status' => 403]);
        }

        // Check expiry
        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
            return new WP_Error('wplm_expired_license', esc_html__('License has expired.', 'wplm'), ['status' => 403]);
        }

        // Check domain activation
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        if (!in_array($domain, $activated_domains)) {
            return new WP_Error('wplm_domain_not_activated', esc_html__('Domain not activated for this license.', 'wplm'), ['status' => 403]);
        }

        return true;
    }

    /**
     * Activate license
     */
    private function activate_license($license_post, $domain) {
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        
        if (in_array($domain, $activated_domains)) {
            return new WP_Error('wplm_domain_already_activated', esc_html__('Domain already activated for this license.', 'wplm'), ['status' => 409]);
        }

        $activated_domains[] = $domain;
        update_post_meta($license_post->ID, '_wplm_activated_domains', $activated_domains);

        // Log activation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_post->ID,
                'license_activated',
                sprintf('License activated for domain: %s', $domain),
                ['domain' => $domain]
            );
        }

        return true;
    }

    /**
     * Deactivate license
     */
    private function deactivate_license($license_post, $domain) {
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        
        if (!in_array($domain, $activated_domains)) {
            return new WP_Error('wplm_domain_not_activated', esc_html__('Domain not activated for this license.', 'wplm'), ['status' => 409]);
        }

        $activated_domains = array_diff($activated_domains, [$domain]);
        update_post_meta($license_post->ID, '_wplm_activated_domains', $activated_domains);

        // Log deactivation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_post->ID,
                'license_deactivated',
                sprintf('License deactivated for domain: %s', $domain),
                ['domain' => $domain]
            );
        }

        return true;
    }

    /**
     * Get license status data
     */
    private function get_license_status_data($license_post) {
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        $status = get_post_meta($license_post->ID, '_wplm_status', true);

        return [
            'license_key' => get_post_meta($license_post->ID, '_wplm_license_key', true),
            'status' => $status,
            'expires' => $expiry_date,
            'activated_domains' => $activated_domains,
            'domain_count' => count($activated_domains),
            'product_id' => get_post_meta($license_post->ID, '_wplm_product_id', true),
            'customer_id' => get_post_meta($license_post->ID, '_wplm_customer_id', true)
        ];
    }

    /**
     * Check for updates
     */
    private function check_for_updates($license_post, $product_slug, $current_version) {
        $product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        if (!$product_id) {
            return new WP_Error('wplm_no_product', esc_html__('No product associated with this license.', 'wplm'), ['status' => 404]);
        }

        $product = get_post($product_id);
        if (!$product || $product->post_type !== 'wplm_product') {
            return new WP_Error('wplm_invalid_product', esc_html__('Invalid product associated with this license.', 'wplm'), ['status' => 404]);
        }

        $latest_version = get_post_meta($product_id, '_wplm_latest_version', true);
        if (empty($latest_version)) {
            return [
                'has_update' => false,
                'current_version' => $current_version,
                'latest_version' => $current_version
            ];
        }

        $has_update = version_compare($latest_version, $current_version, '>');
        
        return [
            'has_update' => $has_update,
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'download_url' => $has_update ? $this->get_download_url($license_post, $product) : null,
            'changelog' => $has_update ? get_post_meta($product_id, '_wplm_changelog', true) : null
        ];
    }

    /**
     * Get download URL
     */
    private function get_download_url($license_post, $product) {
        // Generate secure download URL
        $download_token = wp_generate_password(32, false);
        $expiry = time() + (60 * 60); // 1 hour
        
        update_option("wplm_download_{$download_token}", [
            'license_id' => $license_post->ID,
            'product_id' => $product->ID,
            'expires' => $expiry
        ], false);
        
        return add_query_arg([
            'wplm_download' => $download_token,
            'product' => $product->post_name
        ], home_url('/wp-json/wplm/v2/download'));
    }

    /**
     * Encrypt data
     */
    private function encrypt_data($data) {
        if (empty($data)) {
            return $data;
        }

        $method = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        $encrypted = openssl_encrypt($data, $method, $this->encryption_key, 0, $iv);
        
        if ($encrypted === false) {
            return $data; // Return original data if encryption fails
        }
        
        return base64_encode($iv . $encrypted);
    }

    /**
     * Generate digital signature
     */
    private function generate_digital_signature($data, $license_key) {
        // Remove signature from data before signing
        $data_for_signing = $data;
        unset($data_for_signing['signature']);
        
        $data_string = json_encode($data_for_signing);
        return hash_hmac('sha256', $data_string, $this->encryption_key . $license_key);
    }

    /**
     * Verify digital signature
     */
    private function verify_digital_signature($request, $signature, $license_key) {
        $data = $request->get_params();
        $data_string = json_encode($data);
        
        $expected_signature = hash_hmac('sha256', $data_string, $this->encryption_key . $license_key);
        
        return hash_equals($expected_signature, $signature);
    }

    /**
     * Get encryption key
     */
    private function get_encryption_key() {
        $key = get_option('wplm_encryption_key');
        if (empty($key)) {
            $key = wp_generate_password(64, false);
            update_option('wplm_encryption_key', $key);
        }
        return $key;
    }

    /**
     * Get license features
     */
    private function get_license_features($license_id) {
        $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
        if (!$license_type_id) {
            return [];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        $license_type = $wpdb->get_row($wpdb->prepare("SELECT features FROM {$table} WHERE id = %d", absint($license_type_id)));
        
        if (!$license_type || empty($license_type->features)) {
            return [];
        }
        
        return json_decode($license_type->features, true) ?: [];
    }

    /**
     * Calculate next check time
     */
    private function calculate_next_check_time($license_id) {
        $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
        if (!$license_type_id) {
            return time() + (24 * 60 * 60); // Default to 24 hours
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        $license_type = $wpdb->get_row($wpdb->prepare("SELECT check_interval FROM {$table} WHERE id = %d", absint($license_type_id)));
        
        if (!$license_type) {
            return time() + (24 * 60 * 60);
        }
        
        $check_interval = $license_type->check_interval;
        return time() + ($check_interval * 60 * 60);
    }
}