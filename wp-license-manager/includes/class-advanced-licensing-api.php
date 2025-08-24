        add_filter('wplm_license_check_payload', [$this, 'add_security_payload'], 10, 2);
    }

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
    private function perform_enhanced_validation($params) {
        // Sanitize and validate input parameters
        $license_key = sanitize_text_field($params['license_key'] ?? '');
        $domain = sanitize_text_field($params['domain'] ?? '');
        $product_id = sanitize_text_field($params['product_id'] ?? '');
        $fingerprint = sanitize_text_field($params['fingerprint'] ?? '');
        $ip_address = sanitize_text_field($params['ip_address'] ?? $this->get_client_ip());
        $user_agent = sanitize_text_field($params['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $request_data = $params['request_data'] ?? [];

        // Initial validation of required fields
        if (empty($license_key) || empty($domain) || empty($product_id)) {
            $this->log_security_incident('unknown', 'missing_params', 'high', esc_html__('Missing required parameters for validation.', 'wplm'), $params);
            return ['valid' => false, 'error' => 'MISSING_PARAMS', 'message' => esc_html__('Missing required parameters.', 'wplm')];
        }

        // Basic validation - check if license exists
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            $this->log_security_incident($license_key, 'invalid_license', 'high', esc_html__('License key not found.', 'wplm'), $params);
            return ['valid' => false, 'error' => 'INVALID_LICENSE', 'message' => esc_html__('License not found.', 'wplm')];
        }
        
        $license_id = $license_post->ID;
        
        // Check license status
        $status = get_post_meta($license_id, '_wplm_status', true);
        if ($status !== 'active') {
            $this->log_security_incident($license_key, 'license_inactive', 'medium', sprintf(esc_html__('License status is %s.', 'wplm'), esc_html($status)), $params);
            return ['valid' => false, 'error' => 'LICENSE_INACTIVE', 'message' => esc_html__('License is not active.', 'wplm')];
        }
        
        // Check expiry
        $expiry_date = get_post_meta($license_id, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
            $this->log_security_incident($license_key, 'license_expired', 'medium', esc_html__('License has expired.', 'wplm'), $params);
            return ['valid' => false, 'error' => 'LICENSE_EXPIRED', 'message' => esc_html__('License has expired.', 'wplm')];
        }
        
        // Check product association
        $license_product_id = get_post_meta($license_id, '_wplm_product_id', true);
        if ($product_id && $license_product_id !== $product_id) {
            $this->log_security_incident($license_key, 'product_mismatch', 'medium', sprintf(esc_html__('Product ID mismatch. Expected: %s, Received: %s.', 'wplm'), esc_html($license_product_id), esc_html($product_id)), $params);
            return ['valid' => false, 'error' => 'PRODUCT_MISMATCH', 'message' => esc_html__('License not valid for this product.', 'wplm')];
        }
        
        // Advanced domain validation
        $domain_validation = $this->validate_domain_advanced($license_post, $domain, $fingerprint);
        if (!$domain_validation['valid']) {
            $this->log_security_incident($license_key, 'domain_validation_failed', 'high', sprintf(esc_html__('Domain validation failed for %s. Reason: %s', 'wplm'), esc_html($domain), esc_html($domain_validation['message'] ?? 'Unknown reason')), $params);
            return $domain_validation; // Returns specific error from validate_domain_advanced
        }
        
        // Check activation limits
        $activation_limit = get_post_meta($license_id, '_wplm_activation_limit', true) ?: 1;
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
        
        if (!in_array($domain, $activated_domains)) {
            if ($activation_limit !== -1 && count($activated_domains) >= $activation_limit) {
                $this->log_security_incident($license_key, 'activation_limit_exceeded', 'high', sprintf(esc_html__('Activation limit exceeded for license %s on domain %s.', 'wplm'), $license_key, $domain), $params);
                return ['valid' => false, 'error' => 'ACTIVATION_LIMIT_EXCEEDED', 'message' => esc_html__('Activation limit exceeded.', 'wplm')];
            }
        }
        
        // Fingerprint validation
        if (!empty($fingerprint)) {
            $stored_fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];
            if (isset($stored_fingerprints[$domain]) && $stored_fingerprints[$domain] !== $fingerprint) {
                $this->log_security_incident($license_key, 'fingerprint_mismatch', 'high', sprintf(esc_html__('Fingerprint mismatch detected for license %s on domain %s.', 'wplm'), $license_key, $domain), $params);
                return ['valid' => false, 'error' => 'FINGERPRINT_MISMATCH', 'message' => esc_html__('Site fingerprint mismatch.', 'wplm')];
            }
        }
        
        // Rate limiting check
        if (!$this->check_rate_limit($license_key, $domain)) {
            $this->log_security_incident($license_key, 'rate_limit_exceeded', 'medium', sprintf(esc_html__('Rate limit exceeded for license %s on domain %s.', 'wplm'), $license_key, $domain), $params);
            return ['valid' => false, 'error' => 'RATE_LIMIT_EXCEEDED', 'message' => esc_html__('Too many requests, please try again later.', 'wplm')];
        }
        
        // All checks passed
        return [
            'valid' => true,
            'license_key' => $license_key,
            'product_id' => $license_product_id,
            'customer_email' => get_post_meta($license_id, '_wplm_customer_email', true) ?: '',
            'expiry_date' => $expiry_date,
            'activation_limit' => absint($activation_limit),
            'activated_domains' => $activated_domains,
            'license_type' => get_post_meta($license_id, '_wplm_license_type', true),
            'features' => $this->get_license_features($license_id),
            'server_time' => current_time('mysql'),
            'next_check' => $this->calculate_next_check_time($license_id)
        ];
    }

    /**
     * Advanced domain validation
     */
    private function validate_domain_advanced($license_post, $domain, $fingerprint) {
        // Validate $license_post
        if (!is_a($license_post, 'WP_Post') || 'wplm_license' !== $license_post->post_type) {
            error_log(esc_html__('WPLM_Advanced_Licensing: Invalid license post object provided for domain validation.', 'wplm'));
            return ['valid' => false, 'error' => 'INVALID_LICENSE_OBJECT', 'message' => esc_html__('Invalid license object for domain validation.', 'wplm')];
        }

        // Sanitize domain and fingerprint
        $sanitized_domain = sanitize_text_field($domain);
        $sanitized_fingerprint = sanitize_text_field($fingerprint);

        // Get license type
        $license_type_id = get_post_meta($license_post->ID, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        
        if (!$license_type) {
            error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: License type not found for license ID %d.', 'wplm'), $license_post->ID));
            return ['valid' => false, 'error' => 'LICENSE_TYPE_NOT_FOUND', 'message' => esc_html__('License type configuration missing.', 'wplm')];
        }
        
        // Check if domain is localhost/staging
        if ($this->is_localhost_or_staging($sanitized_domain)) {
            if (!$license_type->allow_localhost) {
                return ['valid' => false, 'error' => 'LOCALHOST_NOT_ALLOWED', 'message' => esc_html__('Localhost not allowed for this license.', 'wplm')];
            }
        }
        
        // Check subdomain limits
        if ($this->is_subdomain($sanitized_domain)) {
            $main_domain = $this->extract_main_domain($sanitized_domain);
            $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
            $subdomain_count = 0;
            
            foreach ($activated_domains as $activated_domain) {
                $sanitized_activated_domain = sanitize_text_field($activated_domain); // Sanitize before use
                if ($this->is_subdomain($sanitized_activated_domain) && $this->extract_main_domain($sanitized_activated_domain) === $main_domain) {
                    $subdomain_count++;
                }
            }
            
            if ($license_type->max_subdomains !== -1 && $subdomain_count >= $license_type->max_subdomains) {
                return ['valid' => false, 'error' => 'SUBDOMAIN_LIMIT_EXCEEDED', 'message' => esc_html__('Subdomain limit exceeded.', 'wplm')];
            }
        }
        
        return ['valid' => true];
    }

    /**
     * Generate advanced fingerprint
     */
    public function generate_advanced_fingerprint($data, $license_key) {
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_domain = sanitize_text_field($data['domain'] ?? '');
        
        // Collect system information securely
        $php_version = PHP_VERSION; // No sanitization needed for constants
        $wp_version = get_bloginfo('version'); // No sanitization needed, built-in WP function
        $server_software = sanitize_text_field($_SERVER['SERVER_SOFTWARE'] ?? '');
        $document_root = sanitize_text_field($_SERVER['DOCUMENT_ROOT'] ?? '');
        $timezone = date_default_timezone_get(); // No sanitization needed
        
        // Limit extensions to prevent bloat and potential sensitive data
        $extensions = array_slice(array_map('sanitize_key', get_loaded_extensions()), 0, 10); 

        $fingerprint_data = [
            'php_version' => $php_version,
            'wp_version' => $wp_version,
            'server_software' => $server_software,
            'document_root' => $document_root,
            'timezone' => $timezone,
            'extensions' => $extensions,
            'domain' => $sanitized_domain,
            'ip_hash' => hash('sha256', $this->get_client_ip() ?: ''), // Hash IP to prevent direct exposure
            'license_key_hash' => hash('sha256', $sanitized_license_key)
        ];
        
        $serialized_data = serialize($fingerprint_data);
        if (false === $serialized_data) {
            error_log(esc_html__('WPLM_Advanced_Licensing: Failed to serialize fingerprint data.', 'wplm'));
            return ''; // Return empty string on failure
        }

        $fingerprint = hash('sha256', $serialized_data);
        if (false === $fingerprint) {
            error_log(esc_html__('WPLM_Advanced_Licensing: Failed to hash serialized fingerprint data.', 'wplm'));
            return ''; // Return empty string on failure
        }

        return $fingerprint;
    }

    /**
     * Track license usage
     */
    public function track_license_usage($license_key, $validation_result) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        // Sanitize input parameters
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_domain = sanitize_text_field($validation_result['domain'] ?? ($_POST['domain'] ?? ''));
        $sanitized_fingerprint = sanitize_text_field($validation_result['fingerprint'] ?? ($_POST['fingerprint'] ?? ''));
        $sanitized_ip_address = sanitize_text_field($this->get_client_ip());
        $sanitized_user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        $status = $validation_result['valid'] ? 'active' : 'invalid';

        if (empty($sanitized_license_key) || empty($sanitized_domain)) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'license_usage_track_failed',
                    esc_html__('Failed to track license usage: Missing license key or domain.', 'wplm'),
                    ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain]
                );
            }
            error_log(esc_html__('WPLM_Advanced_Licensing: Failed to track license usage, missing key or domain.', 'wplm'));
            return; // Exit if essential data is missing
        }
        
        // Check if entry exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE license_key = %s AND domain = %s",
            $sanitized_license_key, $sanitized_domain
        ));
        
        if ($existing) {
            // Update existing entry
            $update_data = [
                    'last_check' => current_time('mysql'),
                    'check_count' => $existing->check_count + 1,
                'fingerprint' => $sanitized_fingerprint,
                'ip_address' => $sanitized_ip_address,
                'user_agent' => $sanitized_user_agent,
                'status' => $status
            ];
            $update_format = ['%s', '%d', '%s', '%s', '%s', '%s'];
            $where_format = ['%d'];

            $update_result = $wpdb->update(
                $table,
                $update_data,
                ['id' => $existing->id],
                $update_format,
                $where_format
            );

            if (false === $update_result) {
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        0,
                        'license_usage_update_failed',
                        sprintf(esc_html__('Failed to update license usage for license %s on domain %s. DB Error: %s', 'wplm'), $sanitized_license_key, $sanitized_domain, $wpdb->last_error),
                        ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain, 'db_error' => $wpdb->last_error]
                    );
                }
                error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: DB error updating license usage for %s on %s: %s', 'wplm'), $sanitized_license_key, $sanitized_domain, $wpdb->last_error));
            } else if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'license_usage_updated',
                    sprintf(esc_html__('License usage updated for license %s on domain %s. Status: %s', 'wplm'), $sanitized_license_key, $sanitized_domain, $status),
                    ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain, 'status' => $status]
                );
            }
        } else {
            // Insert new entry
            $insert_data = [
                'license_key' => $sanitized_license_key,
                'domain' => $sanitized_domain,
                'ip_address' => $sanitized_ip_address,
                'user_agent' => $sanitized_user_agent,
                'fingerprint' => $sanitized_fingerprint,
                    'last_check' => current_time('mysql'),
                'status' => $status
            ];
            $insert_format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];

            $insert_result = $wpdb->insert(
                $table,
                $insert_data,
                $insert_format
            );

            if (false === $insert_result) {
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        0,
                        'license_usage_insert_failed',
                        sprintf(esc_html__('Failed to insert license usage for license %s on domain %s. DB Error: %s', 'wplm'), $sanitized_license_key, $sanitized_domain, $wpdb->last_error),
                        ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain, 'db_error' => $wpdb->last_error]
                    );
                }
                error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: DB error inserting license usage for %s on %s: %s', 'wplm'), $sanitized_license_key, $sanitized_domain, $wpdb->last_error));
            } else if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'license_usage_inserted',
                    sprintf(esc_html__('New license usage inserted for license %s on domain %s. Status: %s', 'wplm'), $sanitized_license_key, $sanitized_domain, $status),
                    ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain, 'status' => $status]
                );
            }
        }
        
        // Detect suspicious activity
        $this->detect_suspicious_activity($sanitized_license_key, $sanitized_domain, $validation_result);
    }

    /**
     * Detect suspicious activity
     */
    private function detect_suspicious_activity($license_key, $domain, $validation_result) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        $incidents_table = $wpdb->prefix . 'wplm_security_incidents';

        // Sanitize input parameters
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_domain = sanitize_text_field($domain);
        $current_ip = $this->get_client_ip();
        $current_user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        // --- Detection Logic ---

        // 1. Rapid requests from different IPs (potential brute-force/sharing)
        $recent_ips = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT ip_address FROM `{$table}` 
             WHERE license_key = %s AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $sanitized_license_key
        ), ARRAY_A);
        
        if (count($recent_ips) > apply_filters('wplm_suspicious_multiple_ip_threshold', 5)) {
            $this->log_security_incident(
                $sanitized_license_key,
                'multiple_ips',
                'high',
                esc_html__('Multiple IPs detected checking license within 1 hour.', 'wplm'),
                [
                'ip_count' => count($recent_ips),
                    'ips' => array_column($recent_ips, 'ip_address'),
                    'current_ip' => $current_ip,
                    'domain' => $sanitized_domain
                ]
            );
            if (class_exists('WPLM_Notification_Manager')) {
                WPLM_Notification_Manager::add_notification(
                    sprintf(esc_html__('Security Alert: Multiple IPs detected for license %s.', 'wplm'), $sanitized_license_key),
                    'security_incident',
                    ['license_key' => $sanitized_license_key]
                );
            }
        }
        
        // 2. Excessive failed validations from a single license key
        $failed_checks = absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` 
             WHERE license_key = %s AND status != 'active' AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $sanitized_license_key
        )));
        
        if ($failed_checks > apply_filters('wplm_suspicious_failed_check_threshold', 10)) {
            $this->log_security_incident(
                $sanitized_license_key,
                'excessive_failures',
                'medium',
                esc_html__('Excessive validation failures detected for license.', 'wplm'),
                [
                    'failure_count' => $failed_checks,
                    'current_ip' => $current_ip,
                    'domain' => $sanitized_domain
                ]
            );
            if (class_exists('WPLM_Notification_Manager')) {
                WPLM_Notification_Manager::add_notification(
                    sprintf(esc_html__('Security Notice: Excessive failed validations for license %s.', 'wplm'), $sanitized_license_key),
                    'security_incident',
                    ['license_key' => $sanitized_license_key]
                );
            }
        }

        // 3. Domain mismatch across multiple activations of the same license (if not allowed by license type)
        // This logic is partially handled in perform_enhanced_validation when checking activation limits.
        // Additional check: if a license is registered to a new domain, and the license type doesn't support multiple domains,
        // or if it's a primary domain change, this should be flagged.
        $license_post = get_page_by_title($sanitized_license_key, OBJECT, 'wplm_license');
        if ($license_post) {
            $license_id = $license_post->ID;
            $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
            
            // Check if the current domain is new and if license type allows it
            if (!in_array($sanitized_domain, $activated_domains, true)) {
                $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
                $license_type = $this->get_license_type($license_type_id);

                if ($license_type && $license_type->max_domains == 1 && !empty($activated_domains)) {
                    // Single domain license, but trying to activate on a new domain
                    $this->log_security_incident(
                        $sanitized_license_key,
                        'domain_change_single_license',
                        'high',
                        esc_html__('Attempt to use single-domain license on a new domain.', 'wplm'),
                        [
                            'previous_domains' => $activated_domains,
                            'new_domain' => $sanitized_domain,
                            'current_ip' => $current_ip
                        ]
                    );
                    if (class_exists('WPLM_Notification_Manager')) {
                        WPLM_Notification_Manager::add_notification(
                            sprintf(esc_html__('Security Alert: Single-domain license %s used on a new domain %s.', 'wplm'), $sanitized_license_key, $sanitized_domain),
                            'security_incident',
                            ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain]
                        );
                    }
                }
            }
        }

        // 4. Unusual user agent changes for the same license/domain over a short period
        // This is more complex and would require tracking user agents per domain, potentially beyond simple DB columns
        // For now, we can log if a new user agent is detected on a known license/domain combination frequently.
        $last_user_agent = $wpdb->get_var($wpdb->prepare(
            "SELECT user_agent FROM `{$table}` 
             WHERE license_key = %s AND domain = %s 
             ORDER BY last_check DESC LIMIT 1 OFFSET 1", // Get second to last entry
            $sanitized_license_key, $sanitized_domain
        ));

        if (!empty($last_user_agent) && $last_user_agent !== $current_user_agent) {
            $recent_usage_records = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT user_agent FROM `{$table}` 
                 WHERE license_key = %s AND domain = %s AND last_check > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                $sanitized_license_key, $sanitized_domain
            ), ARRAY_A);

            if (count($recent_usage_records) > apply_filters('wplm_suspicious_user_agent_threshold', 3)) {
                $this->log_security_incident(
                    $sanitized_license_key,
                    'user_agent_change',
                    'low',
                    esc_html__('Multiple user agents detected for same license and domain.', 'wplm'),
                    [
                        'user_agents' => array_column($recent_usage_records, 'user_agent'),
                        'current_user_agent' => $current_user_agent,
                        'domain' => $sanitized_domain
                    ]
                );
                 if (class_exists('WPLM_Notification_Manager')) {
                    WPLM_Notification_Manager::add_notification(
                        sprintf(esc_html__('Security Notice: Unusual user agent activity for license %s on domain %s.', 'wplm'), $sanitized_license_key, $sanitized_domain),
                        'security_incident',
                        ['license_key' => $sanitized_license_key, 'domain' => $sanitized_domain]
                    );
                }
            }
        }

        // 5. Cross-product license attempts (already partly handled in perform_enhanced_validation, but can be aggregated here)
        if (!($validation_result['valid'] ?? false) && ($validation_result['error'] ?? '') === 'PRODUCT_MISMATCH') {
            $mismatch_count = absint($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$incidents_table}` 
                 WHERE license_key = %s AND incident_type = 'product_mismatch' AND incident_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                $sanitized_license_key
            )));

            if ($mismatch_count > apply_filters('wplm_suspicious_product_mismatch_threshold', 5)) {
                $this->log_security_incident(
                    $sanitized_license_key,
                    'repeated_product_mismatch',
                    'high',
                    esc_html__('Repeated product mismatch attempts for license.', 'wplm'),
                    [
                        'mismatch_count' => $mismatch_count,
                        'current_product_id' => $validation_result['product_id'] ?? '',
                        'expected_product_id' => get_post_meta(get_page_by_title($sanitized_license_key, OBJECT, 'wplm_license')->ID, '_wplm_product_id', true) ?? '',
                        'domain' => $sanitized_domain
                    ]
                );
                 if (class_exists('WPLM_Notification_Manager')) {
                    WPLM_Notification_Manager::add_notification(
                        sprintf(esc_html__('Security Alert: Repeated product mismatch for license %s.', 'wplm'), $sanitized_license_key),
                        'security_incident',
                        ['license_key' => $sanitized_license_key]
                    );
                }
            }
        }
    }

    /**
     * Log security incident
     */
    private function log_security_incident($license_key, $incident_type, $severity, $description, $additional_data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_security_incidents';
        
        // Sanitize all incoming data
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_incident_type = sanitize_key($incident_type);
        $sanitized_severity = sanitize_key($severity);
        $sanitized_description = sanitize_text_field($description);
        $sanitized_ip_address = sanitize_text_field($this->get_client_ip());
        $sanitized_user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        // Recursively sanitize additional_data array
        $sanitized_additional_data = $this->sanitize_recursive_data($additional_data);

        $insert_data = [
            'license_key' => $sanitized_license_key,
            'incident_type' => $sanitized_incident_type,
            'severity' => $sanitized_severity,
            'description' => $sanitized_description,
            'ip_address' => $sanitized_ip_address,
            'user_agent' => $sanitized_user_agent,
            'additional_data' => json_encode($sanitized_additional_data)
        ];
        $insert_format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        $insert_result = $wpdb->insert(
            $table,
            $insert_data,
            $insert_format
        );

        if (false === $insert_result) {
            error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: Failed to log security incident for license %s. DB Error: %s', 'wplm'), $sanitized_license_key, $wpdb->last_error));
        } else {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0,
                    'security_incident_logged',
                    sprintf(esc_html__('Security incident logged for license %s: %s (Severity: %s)', 'wplm'), $sanitized_license_key, $sanitized_description, $sanitized_severity),
                    ['license_key' => $sanitized_license_key, 'incident_type' => $sanitized_incident_type, 'severity' => $sanitized_severity, 'ip_address' => $sanitized_ip_address]
                );
            }
            if (class_exists('WPLM_Notification_Manager')) {
                WPLM_Notification_Manager::add_notification(
                    sprintf(esc_html__('Security Incident: %s for license %s. (Severity: %s)', 'wplm'), $sanitized_description, $sanitized_license_key, strtoupper($sanitized_severity)),
                    'security_incident',
                    ['license_key' => $sanitized_license_key, 'incident_type' => $sanitized_incident_type, 'severity' => $sanitized_severity]
                );
            }
        }
        
        // Trigger action for external handling
        do_action('wplm_security_incident', $sanitized_license_key, $sanitized_incident_type, $sanitized_severity, $sanitized_description, $sanitized_additional_data);
    }

    /**
     * Recursively sanitize data
     */
    private function sanitize_recursive_data($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize_recursive_data($value);
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->sanitize_recursive_data($value);
            }
        } else {
            $data = sanitize_text_field($data);
        }
        return $data;
    }

    /**
     * Handle suspicious activity
     */
    public function handle_suspicious_activity($license_key, $incident_type, $data) {
        // Implement automated responses to suspicious activity
        switch ($incident_type) {
            case 'multiple_ips':
                // Temporarily throttle the license
                $this->throttle_license($license_key, 3600); // 1 hour
                break;
                
            case 'fingerprint_mismatch':
                // Flag for manual review
                $this->flag_license_for_review($license_key, 'Fingerprint mismatch detected');
                break;
                
            case 'excessive_failures':
                // Block license temporarily
                $this->block_license_temporarily($license_key, 1800); // 30 minutes
                break;
        }
    }

    /**
     * Create API response
     */
    private function create_api_response($data) {
        $response = [
            'success' => $data['valid'] ?? false,
            'data' => $data,
            'timestamp' => time(),
            'api_version' => $this->api_version
        ];
        
        // Add signature for response integrity
        $response['signature'] = $this->sign_response($response);
        
        return new WP_REST_Response($response, $data['valid'] ? 200 : 400);
    }

    /**
     * Sign response for integrity
     */
    private function sign_response($data) {
        $json = json_encode($data);
        return hash_hmac('sha256', $json, $this->encryption_key);
    }

    /**
     * Get encryption key
     */
    private function get_encryption_key() {
        $key = get_option('wplm_encryption_key');
        if (!$key) {
            $key = wp_generate_password(64, false);
            update_option('wplm_encryption_key', $key);
        }
        return $key;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Check rate limit
     */
    public function check_rate_limit($license_key, $domain) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        // Get recent checks count
        $recent_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT check_count FROM $table 
             WHERE license_key = %s AND domain = %s AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $license_key, $domain
        ));
        
        // Allow max 60 checks per hour per domain
        return ($recent_checks ?? 0) < 60;
    }

    /**
     * Helper methods
     */
    private function is_localhost_or_staging($domain) {
        $localhost_patterns = [
            'localhost', '127.0.0.1', '::1', '0.0.0.0',
            '.local', '.test', '.dev', '.staging'
        ];
        
        foreach ($localhost_patterns as $pattern) {
            if (strpos($domain, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function is_subdomain($domain) {
        return substr_count($domain, '.') > 1;
    }

    private function extract_main_domain($domain) {
        $parts = explode('.', $domain);
        return implode('.', array_slice($parts, -2));
    }

    private function get_license_type($type_id) {
        if (!$type_id) return null;
        
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $type_id
        ));
    }

    private function get_license_features($license_id) {
        $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        
        if ($license_type && !empty($license_type->features)) {
            return json_decode($license_type->features, true);
        }
        
        return ['updates', 'support']; // Default features
    }

    private function calculate_next_check_time($license_id) {
        $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        
        $interval_hours = 24; // Default 24 hours
        if ($license_type && !empty($license_type->check_interval)) {
            $interval_hours = $license_type->check_interval;
        }
        
        return date('Y-m-d H:i:s', time() + ($interval_hours * 3600));
    }

    private function throttle_license($license_key, $duration) {
        update_option('wplm_throttled_' . $license_key, time() + $duration);
    }

    private function flag_license_for_review($license_key, $reason) {
        update_option('wplm_flagged_' . $license_key, ['reason' => $reason, 'time' => time()]);
    }

    private function block_license_temporarily($license_key, $duration) {
        update_option('wplm_blocked_' . $license_key, time() + $duration);
    }

    /**
     * Meta box and admin interface methods
     */
    public function add_license_type_meta_box() {
        add_meta_box(
            'wplm_license_type',
            __('License Type & Advanced Settings', 'wp-license-manager'),
            [$this, 'render_license_type_meta_box'],
            'wplm_license',
            'side',
            'high'
        );
    }

    public function render_license_type_meta_box($post) {
        wp_nonce_field('wplm_license_type_meta', 'wplm_license_type_nonce');
        
        $license_type_id = get_post_meta($post->ID, '_wplm_license_type', true);
        $license_types = $this->get_all_license_types();
        
        ?>
        <p>
            <label for="wplm_license_type"><?php _e('License Type:', 'wp-license-manager'); ?></label>
            <select name="wplm_license_type" id="wplm_license_type" style="width: 100%;">
                <option value=""><?php _e('Default', 'wp-license-manager'); ?></option>
                <?php foreach ($license_types as $type): ?>
                    <option value="<?php echo esc_attr($type->id); ?>" <?php selected($license_type_id, $type->id); ?>>
                        <?php echo esc_html($type->type_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        
        <?php if ($license_type_id): ?>
            <div class="wplm-license-type-info">
                <?php $type = $this->get_license_type($license_type_id); ?>
                <?php if ($type): ?>
                    <p><strong><?php _e('Max Domains:', 'wp-license-manager'); ?></strong> 
                       <?php echo $type->max_domains == -1 ? __('Unlimited', 'wp-license-manager') : $type->max_domains; ?>
                    </p>
                    <p><strong><?php _e('Check Interval:', 'wp-license-manager'); ?></strong> 
                       <?php echo $type->check_interval; ?> hours
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    public function save_license_type_meta($post_id) {
        if (!isset($_POST['wplm_license_type_nonce']) || !wp_verify_nonce($_POST['wplm_license_type_nonce'], 'wplm_license_type_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $license_type_id = intval($_POST['wplm_license_type'] ?? 0);
        update_post_meta($post_id, '_wplm_license_type', $license_type_id);
    }

    private function get_all_license_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        
        return $wpdb->get_results("SELECT * FROM $table WHERE status = 'active' ORDER BY type_name");
    }

    /**
     * Perform license health check
     */
    public function perform_license_health_check() {
        global $wpdb;
        
        // Check for licenses that haven't been validated recently
        $stale_licenses = $wpdb->get_results("
            SELECT l.post_title as license_key, l.ID as license_id,
                   MAX(u.last_check) as last_check
            FROM {$wpdb->posts} l
            LEFT JOIN {$wpdb->prefix}wplm_license_usage u ON l.post_title = u.license_key
            WHERE l.post_type = 'wplm_license' 
            AND l.post_status = 'publish'
            GROUP BY l.ID
            HAVING last_check IS NULL OR last_check < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        foreach ($stale_licenses as $license) {
            // Mark as potentially inactive
            update_post_meta($license->license_id, '_wplm_health_status', 'stale');
            
            // Log health check
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license->license_id,
                    'license_health_check',
                    sprintf('License %s marked as stale - no recent validation', $license->license_key),
                    ['last_check' => $license->last_check]
                );
            }
        }
    }

    /**
     * API endpoint methods
     */
    public function api_activate_license(WP_REST_Request $request) {
        // Implementation for license activation
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $domain = sanitize_text_field($request->get_param('domain'));
        $fingerprint = sanitize_text_field($request->get_param('fingerprint')); // Get fingerprint
        
        // Perform activation logic
        $result = $this->activate_license_on_domain($license_key, $domain, $fingerprint);
        
        return $this->create_api_response($result);
    }

    public function api_deactivate_license(WP_REST_Request $request) {
        // Implementation for license deactivation
        $license_key = sanitize_text_field($request->get_param('license_key'));
        $domain = sanitize_text_field($request->get_param('domain'));
        
        $result = $this->deactivate_license_on_domain($license_key, $domain);
        
        return $this->create_api_response($result);
    }

    public function api_license_info(WP_REST_Request $request) {
        // Implementation for license info
        $license_key = sanitize_text_field($request->get_param('license_key'));
        
        $result = $this->get_license_info($license_key);
        
        return $this->create_api_response($result);
    }

    public function check_admin_permissions() {
        return current_user_can('manage_wplm_licenses');
    }

    // Placeholder methods - implement as needed
    private function activate_license_on_domain($license_key, $domain, $fingerprint = '') {
        // Sanitize input parameters
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_domain = sanitize_text_field($domain);
        $sanitized_fingerprint = sanitize_text_field($fingerprint);

        $license_post = get_page_by_title($sanitized_license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            // The log_security_incident for 'license not found' should ideally be handled by perform_enhanced_validation
            // If this method is called directly without prior validation, log it.
            $this->log_security_incident($sanitized_license_key, 'activation_failed', 'medium', esc_html__('License not found during activation attempt.', 'wplm'), ['domain' => $sanitized_domain]);
            return ['valid' => false, 'message' => esc_html__('License not found.', 'wplm')];
        }

        $license_id = $license_post->ID;

        // Perform enhanced validation as a prerequisite
        $validation_params = [
            'license_key' => $sanitized_license_key,
            'domain' => $sanitized_domain,
            'product_id' => get_post_meta($license_id, '_wplm_product_id', true),
            'fingerprint' => $sanitized_fingerprint,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_data' => []
        ];
        $pre_activation_validation = $this->perform_enhanced_validation($validation_params);

        if (!$pre_activation_validation['valid']) {
            // If pre-activation validation fails, return its error. Logging is already done by perform_enhanced_validation.
            return $pre_activation_validation;
        }

        // Get current activated domains and fingerprints
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
        $stored_fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];

        $updated = false;
        // Add new domain if not already activated
        if (!in_array($sanitized_domain, $activated_domains, true)) {
            $activated_domains[] = $sanitized_domain;
            if (update_post_meta($license_id, '_wplm_activated_domains', $activated_domains)) {
                $updated = true;
            } else {
                $this->log_security_incident($sanitized_license_key, 'activation_failed_db', 'medium', esc_html__('Failed to update activated domains in DB during activation.', 'wplm'), ['domain' => $sanitized_domain]);
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $license_id,
                        'license_activation_failed_db',
                        sprintf(esc_html__('Failed to update activated domains for license %s on domain %s.', 'wplm'), $sanitized_license_key, $sanitized_domain),
                        ['domain' => $sanitized_domain, 'error' => 'DB_UPDATE_FAILED']
                    );
                }
                return ['valid' => false, 'message' => esc_html__('Failed to record domain activation.', 'wplm')];
            }
        }

        // Update fingerprint if provided or if domain was just added
        if (!empty($sanitized_fingerprint) && (!isset($stored_fingerprints[$sanitized_domain]) || $stored_fingerprints[$sanitized_domain] !== $sanitized_fingerprint)) {
            $stored_fingerprints[$sanitized_domain] = $sanitized_fingerprint;
            if (update_post_meta($license_id, '_wplm_fingerprints', $stored_fingerprints)) {
                $updated = true;
            } else {
                $this->log_security_incident($sanitized_license_key, 'activation_failed_db', 'medium', esc_html__('Failed to update fingerprints in DB during activation.', 'wplm'), ['domain' => $sanitized_domain]);
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $license_id,
                        'license_activation_failed_db',
                        sprintf(esc_html__('Failed to update fingerprints for license %s on domain %s.', 'wplm'), $sanitized_license_key, $sanitized_domain),
                        ['domain' => $sanitized_domain, 'error' => 'DB_UPDATE_FAILED']
                    );
                }
                return ['valid' => false, 'message' => esc_html__('Failed to record fingerprint.', 'wplm')];
            }
        }

        if ($updated) {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license_id,
                    'license_activated',
                    sprintf(esc_html__('License %s activated or updated on domain %s.', 'wplm'), $sanitized_license_key, $sanitized_domain),
                    ['domain' => $sanitized_domain, 'fingerprint' => $sanitized_fingerprint, 'ip_address' => $this->get_client_ip(), 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '']
                );
            }
            return ['valid' => true, 'message' => esc_html__('License activated successfully.', 'wplm')];
        } else {
            // If no changes were actually made but it's a valid call, consider it successful as it's already active/fingerprinted.
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license_id,
                    'license_already_active',
                    sprintf(esc_html__('License %s already active on domain %s.', 'wplm'), $sanitized_license_key, $sanitized_domain),
                    ['domain' => $sanitized_domain, 'fingerprint' => $sanitized_fingerprint, 'ip_address' => $this->get_client_ip(), 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '']
                );
            }
            return ['valid' => true, 'message' => esc_html__('License already active on this domain.', 'wplm')];
        }
    }

    private function deactivate_license_on_domain($license_key, $domain) {
        // Implementation for license deactivation
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            $this->log_security_incident($license_key, 'deactivation_failed', 'medium', esc_html__('License not found during deactivation attempt.', 'wplm'));
            return ['valid' => false, 'message' => esc_html__('License not found.', 'wplm')];
        }

        $license_id = $license_post->ID;
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];

        $key = array_search($domain, $activated_domains, true);
        if (false !== $key) {
            unset($activated_domains[$key]);
            update_post_meta($license_id, '_wplm_activated_domains', array_values($activated_domains));

            // Remove associated fingerprint
            $stored_fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];
            if (isset($stored_fingerprints[$domain])) {
                unset($stored_fingerprints[$domain]);
                update_post_meta($license_id, '_wplm_fingerprints', $stored_fingerprints);
            }

            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license_id,
                    'license_deactivated',
                    sprintf(esc_html__('License %s deactivated from domain %s.', 'wplm'), sanitize_text_field($license_key), sanitize_text_field($domain)),
                    ['domain' => sanitize_text_field($domain)]
                );
            }
}
