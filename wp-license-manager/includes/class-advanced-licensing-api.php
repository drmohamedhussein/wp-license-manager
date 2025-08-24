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
        add_action('rest_api_init', [$this, 'register_api_endpoints']);
        
        // License response encryption
        add_filter('wplm_license_response', [$this, 'encrypt_license_response'], 10, 2);
        
        // API authentication
        add_filter('wplm_api_authenticate', [$this, 'authenticate_api_request'], 10, 2);
    }

    /**
     * Register REST API endpoints
     */
    public function register_api_endpoints() {
        // Enhanced license validation endpoint
        register_rest_route('wplm/v2', '/validate', [
            'methods' => ['POST'],
            'callback' => [$this, 'api_validate_license'],
            'permission_callback' => '__return_true'
        ]);
        
        // License activation endpoint
        register_rest_route('wplm/v2', '/activate', [
            'methods' => ['POST'],
            'callback' => [$this, 'api_activate_license'],
            'permission_callback' => '__return_true'
        ]);
        
        // License deactivation endpoint
        register_rest_route('wplm/v2', '/deactivate', [
            'methods' => ['POST'],
            'callback' => [$this, 'api_deactivate_license'],
            'permission_callback' => '__return_true'
        ]);
        
        // License info endpoint
        register_rest_route('wplm/v2', '/info', [
            'methods' => ['POST'],
            'callback' => [$this, 'api_license_info'],
            'permission_callback' => '__return_true'
        ]);
        
        // Remote disable endpoint
        register_rest_route('wplm/v2', '/remote-disable', [
            'methods' => ['POST'],
            'callback' => [$this, 'api_remote_disable'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
    }

    /**
     * Enhanced license validation API
     */
    public function api_validate_license(WP_REST_Request $request) {
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $domain = sanitize_text_field($request->get_param('domain'));
        $product_id = sanitize_text_field($request->get_param('product_id'));
        $fingerprint = sanitize_text_field($request->get_param('fingerprint'));
        
        // Enhanced validation
        $validation_result = $this->perform_enhanced_validation([
            'license_key' => $license_key,
            'domain' => $domain,
            'product_id' => $product_id,
            'fingerprint' => $fingerprint,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_data' => $request->get_params()
        ]);
        
        // Track usage
        $this->track_license_usage($license_key, $validation_result);
        
        // Return encrypted response
        return $this->create_api_response($validation_result);
    }

    /**
     * Perform enhanced license validation
     */
    private function perform_enhanced_validation($data) {
        // Basic validation
        if (empty($data['license_key']) || empty($data['domain'])) {
            return [
                'valid' => false,
                'error' => 'Missing required parameters',
                'code' => 'MISSING_PARAMS'
            ];
        }
        
        // Get license info
        $license = $this->get_license_info($data['license_key']);
        if (!$license) {
            return [
                'valid' => false,
                'error' => 'Invalid license key',
                'code' => 'INVALID_LICENSE'
            ];
        }
        
        // Check if license is active
        if ($license->status !== 'active') {
            return [
                'valid' => false,
                'error' => 'License is not active',
                'code' => 'LICENSE_INACTIVE',
                'status' => $license->status
            ];
        }
        
        // Check expiry
        if ($license->expiry_date && strtotime($license->expiry_date) < time()) {
            return [
                'valid' => false,
                'error' => 'License has expired',
                'code' => 'LICENSE_EXPIRED',
                'expiry_date' => $license->expiry_date
            ];
        }
        
        // Check activation limit
        if ($license->activation_limit > 0 && $license->activations_count >= $license->activation_limit) {
            return [
                'valid' => false,
                'error' => 'Activation limit reached',
                'code' => 'ACTIVATION_LIMIT_REACHED',
                'activations_count' => $license->activations_count,
                'activation_limit' => $license->activation_limit
            ];
        }
        
        // Check domain
        if (!$this->is_domain_allowed($license, $data['domain'])) {
            return [
                'valid' => false,
                'error' => 'Domain not allowed for this license',
                'code' => 'DOMAIN_NOT_ALLOWED',
                'domain' => $data['domain']
            ];
        }
        
        // All checks passed
        return [
            'valid' => true,
            'license_id' => $license->id,
            'product_id' => $license->product_id,
            'expiry_date' => $license->expiry_date,
            'activations_count' => $license->activations_count,
            'activation_limit' => $license->activation_limit
        ];
    }

    /**
     * Get license information
     */
    private function get_license_info($license_key) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_licenses';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE license_key = %s",
            $license_key
        ));
    }

    /**
     * Check if domain is allowed for license
     */
    private function is_domain_allowed($license, $domain) {
        // Get allowed domains for this license
        $allowed_domains = get_post_meta($license->id, '_wplm_allowed_domains', true);
        
        if (empty($allowed_domains)) {
            // If no specific domains set, allow any domain
            return true;
        }
        
        if (is_string($allowed_domains)) {
            $allowed_domains = [$allowed_domains];
        }
        
        // Check if current domain is in allowed list
        foreach ($allowed_domains as $allowed_domain) {
            if ($this->domains_match($domain, $allowed_domain)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if two domains match
     */
    private function domains_match($domain1, $domain2) {
        // Remove protocol and www
        $domain1 = preg_replace('/^https?:\/\//', '', $domain1);
        $domain1 = preg_replace('/^www\./', '', $domain1);
        $domain2 = preg_replace('/^https?:\/\//', '', $domain2);
        $domain2 = preg_replace('/^www\./', '', $domain2);
        
        return strtolower($domain1) === strtolower($domain2);
    }

    /**
     * Track license usage
     */
    private function track_license_usage($license_key, $validation_result) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        $data = [
            'license_key' => $license_key,
            'domain' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fingerprint' => $validation_result['fingerprint'] ?? '',
            'last_check' => current_time('mysql'),
            'check_count' => 1,
            'status' => $validation_result['valid'] ? 'valid' : 'invalid'
        ];
        
        $wpdb->insert($table, $data);
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get encryption key
     */
    private function get_encryption_key() {
        $key = get_option('wplm_encryption_key');
        
        if (empty($key)) {
            // Generate new key
            $key = wp_generate_password(64, false);
            update_option('wplm_encryption_key', $key);
        }
        
        return $key;
    }

    /**
     * Create API response
     */
    private function create_api_response($data) {
        $response = [
            'success' => $data['valid'],
            'data' => $data,
            'timestamp' => time(),
            'version' => $this->api_version
        ];
        
        // Encrypt response if needed
        if (get_option('wplm_encrypt_api_responses', false)) {
            $response = $this->encrypt_response($response);
        }
        
        return new WP_REST_Response($response, $data['valid'] ? 200 : 400);
    }

    /**
     * Encrypt response
     */
    private function encrypt_response($data) {
        $json_data = json_encode($data);
        $encrypted = openssl_encrypt(
            $json_data,
            'AES-256-CBC',
            $this->encryption_key,
            0,
            substr(hash('sha256', $this->encryption_key), 0, 16)
        );
        
        return [
            'encrypted' => true,
            'data' => base64_encode($encrypted)
        ];
    }

    /**
     * License activation API
     */
    public function api_activate_license(WP_REST_Request $request) {
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $domain = sanitize_text_field($request->get_param('domain'));
        $product_id = sanitize_text_field($request->get_param('product_id'));
        
        // Validate license first
        $validation = $this->perform_enhanced_validation([
            'license_key' => $license_key,
            'domain' => $domain,
            'product_id' => $product_id
        ]);
        
        if (!$validation['valid']) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $validation['error'],
                'code' => $validation['code']
            ], 400);
        }
        
        // Check if already activated on this domain
        if ($this->is_license_activated_on_domain($license_key, $domain)) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'License already activated on this domain',
                'already_activated' => true
            ], 200);
        }
        
        // Activate license
        $result = $this->activate_license_on_domain($license_key, $domain);
        
        if ($result) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'License activated successfully',
                'activation_id' => $result
            ], 200);
        } else {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to activate license'
            ], 500);
        }
    }

    /**
     * Check if license is activated on domain
     */
    private function is_license_activated_on_domain($license_key, $domain) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE license_key = %s AND domain = %s AND status = 'active'",
            $license_key,
            $domain
        ));
        
        return $result > 0;
    }

    /**
     * Activate license on domain
     */
    private function activate_license_on_domain($license_key, $domain) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        // Update existing record or create new one
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE license_key = %s AND domain = %s",
            $license_key,
            $domain
        ));
        
        if ($existing) {
            $result = $wpdb->update(
                $table,
                [
                    'status' => 'active',
                    'last_check' => current_time('mysql')
                ],
                ['id' => $existing->id]
            );
            return $existing->id;
        } else {
            $result = $wpdb->insert(
                $table,
                [
                    'license_key' => $license_key,
                    'domain' => $domain,
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'fingerprint' => '',
                    'last_check' => current_time('mysql'),
                    'check_count' => 1,
                    'status' => 'active'
                ]
            );
            
            if ($result) {
                return $wpdb->insert_id;
            }
        }
        
        return false;
    }

    /**
     * License deactivation API
     */
    public function api_deactivate_license(WP_REST_Request $request) {
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $domain = sanitize_text_field($request->get_param('domain'));
        
        $result = $this->deactivate_license_on_domain($license_key, $domain);
        
        if ($result) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'License deactivated successfully'
            ], 200);
        } else {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to deactivate license'
            ], 400);
        }
    }

    /**
     * Deactivate license on domain
     */
    private function deactivate_license_on_domain($license_key, $domain) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        $result = $wpdb->update(
            $table,
            ['status' => 'inactive'],
            [
                'license_key' => $license_key,
                'domain' => $domain
            ]
        );
        
        return $result !== false;
    }

    /**
     * License info API
     */
    public function api_license_info(WP_REST_Request $request) {
        $license_key = sanitize_text_field($request->get_param('license_key'));
        
        $license = $this->get_license_info($license_key);
        if (!$license) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'License not found'
            ], 404);
        }
        
        // Get product info
        $product = get_post($license->product_id);
        
        $info = [
            'license_key' => $license->license_key,
            'product_name' => $product ? $product->post_title : 'Unknown Product',
            'status' => $license->status,
            'expiry_date' => $license->expiry_date,
            'activation_limit' => $license->activation_limit,
            'activations_count' => $license->activations_count,
            'created_at' => $license->created_at
        ];
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $info
        ], 200);
    }

    /**
     * Remote disable API
     */
    public function api_remote_disable(WP_REST_Request $request) {
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $reason = sanitize_text_field($request->get_param('reason'));
        
        // Check admin permissions
        if (!$this->check_admin_permissions($request)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Insufficient permissions'
            ], 403);
        }
        
        // Disable license
        $result = $this->disable_license($license_key, $reason);
        
        if ($result) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'License disabled successfully'
            ], 200);
        } else {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to disable license'
            ], 500);
        }
    }

    /**
     * Check admin permissions
     */
    public function check_admin_permissions($request) {
        return current_user_can('manage_options');
    }

    /**
     * Disable license
     */
    private function disable_license($license_key, $reason) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_licenses';
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'disabled',
                'updated_at' => current_time('mysql')
            ],
            ['license_key' => $license_key]
        );
        
        if ($result !== false) {
            // Log the action
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(0, 'license_disabled', "License {$license_key} disabled remotely. Reason: {$reason}");
            }
        }
        
        return $result !== false;
    }

    /**
     * Encrypt license response
     */
    public function encrypt_license_response($response, $license_key) {
        if (!get_option('wplm_encrypt_license_responses', false)) {
            return $response;
        }
        
        $json_data = json_encode($response);
        $encrypted = openssl_encrypt(
            $json_data,
            'AES-256-CBC',
            $this->encryption_key,
            0,
            substr(hash('sha256', $this->encryption_key), 0, 16)
        );
        
        return [
            'encrypted' => true,
            'data' => base64_encode($encrypted)
        ];
    }

    /**
     * Authenticate API request
     */
    public function authenticate_api_request($authenticated, $request) {
        // Check API key if required
        if (get_option('wplm_require_api_key', false)) {
            $api_key = $request->get_header('X-API-Key');
            if (empty($api_key) || $api_key !== get_option('wplm_api_key')) {
                return false;
            }
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit($request)) {
            return false;
        }
        
        return $authenticated;
    }

    /**
     * Check rate limiting
     */
    private function check_rate_limit($request) {
        $client_ip = $this->get_client_ip();
        $key = 'wplm_rate_limit_' . md5($client_ip);
        $count = get_transient($key) ?: 0;
        
        if ($count > 100) { // Max 100 requests per hour
            return false;
        }
        
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }
}
