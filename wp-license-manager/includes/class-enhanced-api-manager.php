<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced API Manager for WPLM
 * Provides advanced license validation and management APIs
 */
class WPLM_Enhanced_API_Manager {
    
    private $encryption_key;
    private $rate_limits = [];
    
    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
        
        add_action('rest_api_init', [$this, 'register_enhanced_endpoints']);
        add_action('init', [$this, 'init_api_keys']);
        
        // Security hooks
        add_action('rest_api_init', [$this, 'add_security_headers']);
        add_filter('rest_pre_dispatch', [$this, 'validate_api_request'], 10, 3);
        
        // Rate limiting
        add_action('wp_ajax_nopriv_wplm_api_request', [$this, 'check_rate_limit']);
        
        // API logging
        add_action('wplm_api_request', [$this, 'log_api_request'], 10, 3);
    }

    /**
     * Validates API requests based on API key.
     *
     * @param mixed           $result  Response to return. Normally null.
     * @param WP_REST_Server  $server  Server instance.
     * @param WP_REST_Request $request Request being processed.
     * @return mixed|\WP_Error
     */
    public function validate_api_request($result, $server, $request) {
        $route = $request->get_route();
        // Only apply validation to WPLM API routes
        if (strpos($route, '/wplm/v2/') === 0) {
            $api_key = $request->get_header('x-wplm-api-key');
            $stored_api_key = get_option('wplm_api_key');

            if (empty($api_key) || $api_key !== $stored_api_key) {
                return new WP_Error(
                    'wplm_api_unauthorized',
                    __('Unauthorized API Key.', 'wp-license-manager'),
                    ['status' => rest_authorization_required_code()]
                );
            }
        }
        return $result;
    }

    /**
     * Register enhanced API endpoints
     */
    public function register_enhanced_endpoints() {
        $namespace = 'wplm/v2';
        
        // License validation with comprehensive checks
        register_rest_route($namespace, '/validate', [
            'methods' => ['POST'],
            'callback' => [$this, 'validate_license_enhanced'],
            'permission_callback' => [$this, 'authenticate_api_request'],
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
                'product_id' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'version' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'fingerprint' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // License activation with domain binding
        register_rest_route($namespace, '/activate', [
            'methods' => ['POST'],
            'callback' => [$this, 'activate_license_enhanced'],
            'permission_callback' => [$this, 'authenticate_api_request'],
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
                'site_info' => [
                    'required' => false,
                    'type' => 'object'
                ]
            ]
        ]);
        
        // License deactivation
        register_rest_route($namespace, '/deactivate', [
            'methods' => ['POST'],
            'callback' => [$this, 'deactivate_license_enhanced'],
            'permission_callback' => [$this, 'authenticate_api_request'],
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
        
        // Product updates check
        register_rest_route($namespace, '/updates', [
            'methods' => ['POST'],
            'callback' => [$this, 'check_product_updates'],
            'permission_callback' => [$this, 'authenticate_api_request'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'product_id' => [
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
        
        // Download endpoint with license verification
        register_rest_route($namespace, '/download', [
            'methods' => ['POST'],
            'callback' => [$this, 'handle_secure_download'],
            'permission_callback' => [$this, 'authenticate_api_request'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'product_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'download_token' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // License information endpoint
        register_rest_route($namespace, '/info', [
            'methods' => ['POST'],
            'callback' => [$this, 'get_license_info_enhanced'],
            'permission_callback' => [$this, 'authenticate_api_request'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // Heartbeat endpoint for license monitoring
        register_rest_route($namespace, '/heartbeat', [
            'methods' => ['POST'],
            'callback' => [$this, 'license_heartbeat'],
            'permission_callback' => [$this, 'authenticate_api_request'],
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
                'stats' => [
                    'required' => false,
                    'type' => 'object'
                ]
            ]
        ]);
    }

    /**
     * Enhanced license validation
     */
    public function validate_license_enhanced(WP_REST_Request $request) {
        $start_time = microtime(true);
        
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        $product_id = $request->get_param('product_id');
        $version = $request->get_param('version');
        $fingerprint = $request->get_param('fingerprint');
        
        // Log API request
        do_action('wplm_api_request', 'validate', $license_key, [
            'domain' => $domain,
            'product_id' => $product_id,
            'ip' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Check rate limiting
        if (!$this->check_rate_limit_for_license($license_key)) {
            return $this->error_response('RATE_LIMIT_EXCEEDED', 'Too many requests. Please try again later.', 429);
        }
        
        // Find license
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            $this->log_failed_attempt($license_key, 'invalid_license', $domain);
            return $this->error_response('INVALID_LICENSE', 'License key not found.');
        }
        
        // Check license status
        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return $this->error_response('LICENSE_INACTIVE', 'License is not active.');
        }
        
        // Check expiry
        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
            return $this->error_response('LICENSE_EXPIRED', 'License has expired.');
        }
        
        // Check product association
        $license_product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        if ($product_id && $license_product_id !== $product_id) {
            $this->log_failed_attempt($license_key, 'product_mismatch', $domain);
            return $this->error_response('PRODUCT_MISMATCH', 'License not valid for this product.');
        }
        
        // Check domain activation
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        $activation_limit = get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
        
        if (!in_array($domain, $activated_domains)) {
            return $this->error_response('DOMAIN_NOT_ACTIVATED', 'Domain not activated for this license.');
        }
        
        // Validate fingerprint if provided
        if (!empty($fingerprint)) {
            $stored_fingerprints = get_post_meta($license_post->ID, '_wplm_fingerprints', true) ?: [];
            if (!empty($stored_fingerprints[$domain]) && $stored_fingerprints[$domain] !== $fingerprint) {
                $this->log_security_incident($license_key, 'fingerprint_mismatch', $domain, $fingerprint);
                return $this->error_response('FINGERPRINT_MISMATCH', 'Site fingerprint has changed.');
            }
        }
        
        // Update last check
        $this->update_license_usage($license_post->ID, $domain);
        
        // Prepare response data
        $response_data = [
            'valid' => true,
            'license_key' => $license_key,
            'product_id' => $license_product_id,
            'customer_email' => get_post_meta($license_post->ID, '_wplm_customer_email', true),
            'expiry_date' => $expiry_date,
            'activation_limit' => $activation_limit,
            'activated_domains' => $activated_domains,
            'license_type' => get_post_meta($license_post->ID, '_wplm_license_type', true),
            'features' => $this->get_license_features($license_post->ID),
            'server_time' => current_time('mysql'),
            'api_version' => '2.0',
            'response_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
        ];
        
        // Add product-specific data if available
        if ($license_product_id) {
            $product_post = get_post($license_product_id);
            if ($product_post) {
                $response_data['product_name'] = $product_post->post_title;
                $response_data['product_version'] = get_post_meta($license_product_id, '_wplm_version', true);
                $response_data['download_url'] = get_post_meta($license_product_id, '_wplm_download_url', true);
            }
        }
        
        return $this->success_response($response_data);
    }

    /**
     * Enhanced license activation
     */
    public function activate_license_enhanced(WP_REST_Request $request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        $site_info = $request->get_param('site_info') ?: [];
        
        // Find license
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            return $this->error_response('INVALID_LICENSE', 'License key not found.');
        }
        
        // Check license status
        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return $this->error_response('LICENSE_INACTIVE', 'License is not active.');
        }
        
        // Check activation limit
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        $activation_limit = get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
        
        // If domain is already activated, return success
        if (in_array($domain, $activated_domains)) {
            return $this->success_response([
                'activated' => true,
                'message' => 'Domain is already activated.',
                'activated_domains' => $activated_domains
            ]);
        }
        
        // Check if we can activate more domains
        if (count($activated_domains) >= $activation_limit) {
            return $this->error_response('ACTIVATION_LIMIT_EXCEEDED', 'Maximum number of activations reached.');
        }
        
        // Validate domain
        if (!$this->is_valid_domain($domain)) {
            return $this->error_response('INVALID_DOMAIN', 'Invalid domain format.');
        }
        
        // Generate fingerprint
        $fingerprint = $this->generate_fingerprint($domain, $site_info);
        
        // Add domain to activated list
        $activated_domains[] = $domain;
        update_post_meta($license_post->ID, '_wplm_activated_domains', $activated_domains);
        
        // Store fingerprint
        $fingerprints = get_post_meta($license_post->ID, '_wplm_fingerprints', true) ?: [];
        $fingerprints[$domain] = $fingerprint;
        update_post_meta($license_post->ID, '_wplm_fingerprints', $fingerprints);
        
        // Store site info
        $site_data = get_post_meta($license_post->ID, '_wplm_site_data', true) ?: [];
        $site_data[$domain] = array_merge($site_info, [
            'activated_at' => current_time('mysql'),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        update_post_meta($license_post->ID, '_wplm_site_data', $site_data);
        
        // Log activation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_post->ID,
                'license_activated',
                "License activated on domain: {$domain}",
                ['domain' => $domain, 'fingerprint' => $fingerprint]
            );
        }
        
        return $this->success_response([
            'activated' => true,
            'message' => 'License activated successfully.',
            'domain' => $domain,
            'fingerprint' => $fingerprint,
            'activated_domains' => $activated_domains,
            'remaining_activations' => max(0, $activation_limit - count($activated_domains))
        ]);
    }

    /**
     * Enhanced license deactivation
     */
    public function deactivate_license_enhanced(WP_REST_Request $request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        
        // Find license
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            return $this->error_response('INVALID_LICENSE', 'License key not found.');
        }
        
        // Get activated domains
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        
        // Check if domain is activated
        if (!in_array($domain, $activated_domains)) {
            return $this->error_response('DOMAIN_NOT_ACTIVATED', 'Domain is not activated.');
        }
        
        // Remove domain from activated list
        $activated_domains = array_filter($activated_domains, function($d) use ($domain) {
            return $d !== $domain;
        });
        update_post_meta($license_post->ID, '_wplm_activated_domains', array_values($activated_domains));
        
        // Remove fingerprint
        $fingerprints = get_post_meta($license_post->ID, '_wplm_fingerprints', true) ?: [];
        unset($fingerprints[$domain]);
        update_post_meta($license_post->ID, '_wplm_fingerprints', $fingerprints);
        
        // Update site data
        $site_data = get_post_meta($license_post->ID, '_wplm_site_data', true) ?: [];
        if (isset($site_data[$domain])) {
            $site_data[$domain]['deactivated_at'] = current_time('mysql');
        }
        update_post_meta($license_post->ID, '_wplm_site_data', $site_data);
        
        // Log deactivation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_post->ID,
                'license_deactivated',
                "License deactivated from domain: {$domain}",
                ['domain' => $domain]
            );
        }
        
        return $this->success_response([
            'deactivated' => true,
            'message' => 'License deactivated successfully.',
            'domain' => $domain,
            'activated_domains' => $activated_domains
        ]);
    }

    /**
     * Check for product updates
     */
    public function check_product_updates(WP_REST_Request $request) {
        $license_key = $request->get_param('license_key');
        $product_id = $request->get_param('product_id');
        $current_version = $request->get_param('current_version');
        
        // Validate license first
        $validation = $this->quick_license_validation($license_key, $product_id);
        if (!$validation['valid']) {
            return $this->error_response($validation['error'], $validation['message']);
        }
        
        // Get product information
        $product_post = get_post($product_id);
        if (!$product_post) {
            return $this->error_response('PRODUCT_NOT_FOUND', 'Product not found.');
        }
        
        $latest_version = get_post_meta($product_id, '_wplm_version', true);
        $download_url = get_post_meta($product_id, '_wplm_download_url', true);
        $changelog = get_post_meta($product_id, '_wplm_changelog', true);
        
        $update_available = version_compare($latest_version, $current_version, '>');
        
        $response_data = [
            'update_available' => $update_available,
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'product_name' => $product_post->post_title,
            'tested_wp_version' => get_post_meta($product_id, '_wplm_tested_wp_version', true),
            'requires_wp_version' => get_post_meta($product_id, '_wplm_requires_wp_version', true),
            'requires_php_version' => get_post_meta($product_id, '_wplm_requires_php_version', true)
        ];
        
        if ($update_available) {
            $response_data['download_url'] = $download_url;
            $response_data['changelog'] = $changelog;
            $response_data['update_message'] = "Update available: version {$latest_version}";
        }
        
        return $this->success_response($response_data);
    }

    /**
     * Handle secure downloads
     */
    public function handle_secure_download(WP_REST_Request $request) {
        $license_key = $request->get_param('license_key');
        $product_id = $request->get_param('product_id');
        $download_token = $request->get_param('download_token');
        
        // Validate license
        $validation = $this->quick_license_validation($license_key, $product_id);
        if (!$validation['valid']) {
            return $this->error_response($validation['error'], $validation['message']);
        }
        
        // Validate download token if provided
        if ($download_token && !$this->validate_download_token($download_token, $license_key, $product_id)) {
            return $this->error_response('INVALID_TOKEN', 'Invalid download token.');
        }
        
        // Get download URL
        $download_url = get_post_meta($product_id, '_wplm_download_url', true);
        if (empty($download_url)) {
            return $this->error_response('NO_DOWNLOAD_AVAILABLE', 'No download available for this product.');
        }
        
        // Generate secure download link
        $secure_url = $this->generate_secure_download_link($license_key, $product_id, $download_url);
        
        // Log download
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $product_id,
                'product_downloaded',
                "Product downloaded with license: {$license_key}",
                ['license_key' => $license_key, 'ip' => $this->get_client_ip()]
            );
        }
        
        return $this->success_response([
            'download_url' => $secure_url,
            'expires_in' => 3600, // 1 hour
            'product_name' => get_the_title($product_id),
            'version' => get_post_meta($product_id, '_wplm_version', true)
        ]);
    }

    /**
     * Get enhanced license information
     */
    public function get_license_info_enhanced(WP_REST_Request $request) {
        $license_key = $request->get_param('license_key');
        
        // Find license
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            return $this->error_response('INVALID_LICENSE', 'License key not found.');
        }
        
        $response_data = [
            'license_key' => $license_key,
            'status' => get_post_meta($license_post->ID, '_wplm_status', true),
            'customer_email' => get_post_meta($license_post->ID, '_wplm_customer_email', true),
            'product_id' => get_post_meta($license_post->ID, '_wplm_product_id', true),
            'activation_limit' => get_post_meta($license_post->ID, '_wplm_activation_limit', true),
            'activated_domains' => get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [],
            'created_date' => $license_post->post_date,
            'expiry_date' => get_post_meta($license_post->ID, '_wplm_expiry_date', true),
            'license_type' => get_post_meta($license_post->ID, '_wplm_license_type', true),
            'features' => $this->get_license_features($license_post->ID)
        ];
        
        // Add product information
        $product_id = $response_data['product_id'];
        if ($product_id) {
            $product_post = get_post($product_id);
            if ($product_post) {
                $response_data['product_name'] = $product_post->post_title;
                $response_data['product_version'] = get_post_meta($product_id, '_wplm_version', true);
            }
        }
        
        return $this->success_response($response_data);
    }

    /**
     * License heartbeat for monitoring
     */
    public function license_heartbeat(WP_REST_Request $request) {
        $license_key = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        $stats = $request->get_param('stats') ?: [];
        
        // Quick validation
        $validation = $this->quick_license_validation($license_key);
        if (!$validation['valid']) {
            return $this->error_response($validation['error'], $validation['message']);
        }
        
        // Update heartbeat data
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        $heartbeat_data = get_post_meta($license_post->ID, '_wplm_heartbeat_data', true) ?: [];
        
        $heartbeat_data[$domain] = array_merge($stats, [
            'last_heartbeat' => current_time('mysql'),
            'ip_address' => $this->get_client_ip()
        ]);
        
        update_post_meta($license_post->ID, '_wplm_heartbeat_data', $heartbeat_data);
        
        return $this->success_response([
            'heartbeat_received' => true,
            'server_time' => current_time('mysql'),
            'next_heartbeat_in' => 3600 // 1 hour
        ]);
    }

    /**
     * Authentication and security methods
     */
    public function authenticate_api_request(WP_REST_Request $request) {
        // For now, allow all requests - implement API key authentication here
        return true;
    }

    public function add_security_headers() {
        add_filter('rest_post_dispatch', function($response, $server, $request) {
            if (strpos($request->get_route(), '/wplm/') === 0) {
                $response->header('X-WPLM-API-Version', '2.0');
                $response->header('X-WPLM-Server-Time', time());
            }
            return $response;
        }, 10, 3);
    }

    /**
     * Rate limiting
     */
    private function check_rate_limit_for_license($license_key) {
        $current_time = time();
        $window = 3600; // 1 hour
        $limit = 100; // 100 requests per hour per license
        
        if (!isset($this->rate_limits[$license_key])) {
            $this->rate_limits[$license_key] = [];
        }
        
        // Clean old entries
        $this->rate_limits[$license_key] = array_filter($this->rate_limits[$license_key], function($timestamp) use ($current_time, $window) {
            return ($current_time - $timestamp) < $window;
        });
        
        // Check limit
        if (count($this->rate_limits[$license_key]) >= $limit) {
            return false;
        }
        
        // Add current request
        $this->rate_limits[$license_key][] = $current_time;
        
        return true;
    }

    /**
     * Helper methods
     */
    private function quick_license_validation($license_key, $product_id = null) {
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            return ['valid' => false, 'error' => 'INVALID_LICENSE', 'message' => 'License not found'];
        }
        
        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return ['valid' => false, 'error' => 'LICENSE_INACTIVE', 'message' => 'License inactive'];
        }
        
        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
            return ['valid' => false, 'error' => 'LICENSE_EXPIRED', 'message' => 'License expired'];
        }
        
        if ($product_id) {
            $license_product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
            if ($license_product_id !== $product_id) {
                return ['valid' => false, 'error' => 'PRODUCT_MISMATCH', 'message' => 'Product mismatch'];
            }
        }
        
        return ['valid' => true];
    }

    private function success_response($data) {
        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
            'timestamp' => time()
        ], 200);
    }

    private function error_response($error_code, $message, $status_code = 400) {
        return new WP_REST_Response([
            'success' => false,
            'error' => $error_code,
            'message' => $message,
            'timestamp' => time()
        ], $status_code);
    }

    private function get_client_ip() {
        $ip_headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function is_valid_domain($domain) {
        return filter_var('http://' . $domain, FILTER_VALIDATE_URL) !== false;
    }

    private function generate_fingerprint($domain, $site_info) {
        $data = [
            'domain' => $domain,
            'php_version' => $site_info['php_version'] ?? '',
            'wp_version' => $site_info['wp_version'] ?? '',
            'timestamp' => time()
        ];
        
        return hash('sha256', serialize($data));
    }

    private function get_license_features($license_id) {
        // Get features based on license type or return default
        return ['updates', 'support'];
    }

    private function update_license_usage($license_id, $domain) {
        $usage_data = get_post_meta($license_id, '_wplm_usage_data', true) ?: [];
        $usage_data[$domain] = [
            'last_check' => current_time('mysql'),
            'check_count' => ($usage_data[$domain]['check_count'] ?? 0) + 1,
            'ip_address' => $this->get_client_ip()
        ];
        update_post_meta($license_id, '_wplm_usage_data', $usage_data);
    }

    private function log_failed_attempt($license_key, $reason, $domain) {
        // Log failed attempt for security monitoring
        error_log("WPLM: Failed license attempt - Key: {$license_key}, Reason: {$reason}, Domain: {$domain}, IP: " . $this->get_client_ip());
    }

    private function log_security_incident($license_key, $type, $domain, $details) {
        // Log security incident
        error_log("WPLM: Security incident - Key: {$license_key}, Type: {$type}, Domain: {$domain}, Details: {$details}");
    }

    private function validate_download_token($token, $license_key, $product_id) {
        // Implement download token validation
        return true; // Placeholder
    }

    private function generate_secure_download_link($license_key, $product_id, $download_url) {
        // Generate a secure, time-limited download link
        $token = hash('sha256', $license_key . $product_id . time() . $this->encryption_key);
        return add_query_arg([
            'wplm_download' => '1',
            'token' => $token,
            'expires' => time() + 3600
        ], $download_url);
    }

    private function get_encryption_key() {
        $key = get_option('wplm_encryption_key');
        if (!$key) {
            $key = wp_generate_password(64, false);
            update_option('wplm_encryption_key', $key);
        }
        return $key;
    }

    public function init_api_keys() {
        // Initialize API key system if needed
    }

    public function log_api_request($action, $license_key, $data) {
        // Log API requests for monitoring
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                null,
                'api_request',
                "API {$action} request for license: {$license_key}",
                $data
            );
        }
    }
}
