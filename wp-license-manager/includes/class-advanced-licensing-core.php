<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Licensing - Core Functionality
 * Part 1 of the split WPLM_Advanced_Licensing class
 */
class WPLM_Advanced_Licensing_Core {

    private $encryption_key;
    private $api_version = '2.0';

    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
        
        add_action('init', [$this, 'init']);
        
        // Advanced license validation hooks
        add_filter('wplm_validate_license_request', [$this, 'enhanced_license_validation'], 10, 3);
        add_filter('wplm_license_response', [$this, 'encrypt_license_response'], 10, 2);
        
        // Security and anti-piracy measures
        add_action('wplm_license_activated', [$this, 'track_license_usage'], 10, 2);
        add_action('wplm_suspicious_activity', [$this, 'handle_suspicious_activity'], 10, 3);
        
        // License fingerprinting
        add_filter('wplm_generate_fingerprint', [$this, 'generate_advanced_fingerprint'], 10, 2);
        
        // Periodic license health checks
        if (!wp_next_scheduled('wplm_license_health_check')) {
            wp_schedule_event(time(), 'daily', 'wplm_license_health_check');
        }
        add_action('wplm_license_health_check', [$this, 'perform_license_health_check']);
    }

    /**
     * Initialize advanced licensing features
     */
    public function init() {
        // Create advanced tables if needed
        $this->maybe_create_advanced_tables();
        
        // Initialize license types
        $this->init_license_types();
        
        // Setup security measures
        $this->setup_security_measures();
    }

    /**
     * Create advanced licensing tables
     */
    private function maybe_create_advanced_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // License usage tracking table
        $usage_table = $wpdb->prefix . 'wplm_license_usage';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql_usage = "CREATE TABLE {$usage_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                license_key varchar(255) NOT NULL,
                domain varchar(255) NOT NULL,
                ip_address varchar(45) NOT NULL,
                user_agent text,
                fingerprint varchar(64) NOT NULL,
                last_check datetime NOT NULL,
                check_count int(11) NOT NULL DEFAULT 1,
                product_version varchar(50),
                php_version varchar(20),
                wp_version varchar(20),
                status varchar(20) DEFAULT 'active',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY license_key (license_key),
                KEY domain (domain),
                KEY fingerprint (fingerprint),
                KEY last_check (last_check)
        ) {$charset_collate};";
        dbDelta($sql_usage);
        
        if ($wpdb->last_error) {
            error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: Error creating license usage table. Error: %s', 'wplm'), $wpdb->last_error));
        }
        
        // Security incidents table
        $security_table = $wpdb->prefix . 'wplm_security_incidents';
        $sql_security = "CREATE TABLE {$security_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                license_key varchar(255) NOT NULL,
                incident_type varchar(50) NOT NULL,
                severity varchar(20) NOT NULL DEFAULT 'medium',
                description text,
                ip_address varchar(45),
                user_agent text,
                additional_data longtext,
                status varchar(20) DEFAULT 'active',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY license_key (license_key),
                KEY incident_type (incident_type),
                KEY severity (severity),
                KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_security);
        
        if ($wpdb->last_error) {
            error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: Error creating security incidents table. Error: %s', 'wplm'), $wpdb->last_error));
        }
        
        // License types table
        $types_table = $wpdb->prefix . 'wplm_license_types';
        $sql_types = "CREATE TABLE {$types_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                type_name varchar(100) NOT NULL,
                type_slug varchar(100) NOT NULL,
                description text,
                max_domains int(11) NOT NULL DEFAULT 1,
                max_subdomains int(11) NOT NULL DEFAULT 0,
                allow_localhost tinyint(1) NOT NULL DEFAULT 1,
                allow_staging tinyint(1) NOT NULL DEFAULT 1,
                check_interval int(11) NOT NULL DEFAULT 24,
                grace_period int(11) NOT NULL DEFAULT 7,
                features longtext,
                pricing_data longtext,
                status varchar(20) DEFAULT 'active',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY type_slug (type_slug)
        ) {$charset_collate};";
        dbDelta($sql_types);
        
        if ($wpdb->last_error) {
            error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: Error creating license types table. Error: %s', 'wplm'), $wpdb->last_error));
        }
        
        // Only insert default types if the table was just created or is empty
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$types_table}") == 0) {
            $this->insert_default_license_types();
        }
    }

    /**
     * Insert default license types
     */
    private function insert_default_license_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        
        $default_types = [
            [
                'type_name' => esc_html__('Personal License', 'wplm'),
                'type_slug' => 'personal',
                'description' => esc_html__('Single site license for personal use', 'wplm'),
                'max_domains' => 1,
                'max_subdomains' => 0,
                'features' => json_encode(['updates', 'support'])
            ],
            [
                'type_name' => esc_html__('Business License', 'wplm'), 
                'type_slug' => 'business',
                'description' => esc_html__('Multi-site license for business use', 'wplm'),
                'max_domains' => 5,
                'max_subdomains' => 2,
                'features' => json_encode(['updates', 'support', 'premium_addons'])
            ],
            [
                'type_name' => esc_html__('Developer License', 'wplm'),
                'type_slug' => 'developer', 
                'description' => esc_html__('Unlimited sites for developers', 'wplm'),
                'max_domains' => -1,
                'max_subdomains' => -1,
                'features' => json_encode(['updates', 'support', 'premium_addons', 'white_label'])
            ],
            [
                'type_name' => esc_html__('Lifetime License', 'wplm'),
                'type_slug' => 'lifetime',
                'description' => esc_html__('Lifetime access license', 'wplm'),
                'max_domains' => 3,
                'max_subdomains' => 1,
                'check_interval' => 168, // Weekly
                'features' => json_encode(['updates', 'support', 'lifetime_access'])
            ]
        ];
        
        foreach ($default_types as $type) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE type_slug = %s",
                $type['type_slug']
            ));
            
            if (!$exists) {
                $wpdb->insert($table, $type);
            }
        }
    }

    /**
     * Initialize license types
     */
    private function init_license_types() {
        // Add meta box for license types
        add_action('add_meta_boxes', [$this, 'add_license_type_meta_box']);
        add_action('save_post', [$this, 'save_license_type_meta']);
    }

    /**
     * Setup security measures
     */
    private function setup_security_measures() {
        // Add rate limiting
        add_filter('wplm_pre_license_validation', [$this, 'check_rate_limit'], 10, 2);
        
        // Add domain validation
        add_filter('wplm_validate_domain', [$this, 'validate_domain_advanced'], 10, 3);
    }

    /**
     * Enhanced license validation
     */
    public function enhanced_license_validation($validation_result, $license_key, $params) {
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }
        
        // Perform additional validation
        $enhanced_result = $this->perform_enhanced_validation($params);
        
        if (is_wp_error($enhanced_result)) {
            return $enhanced_result;
        }
        
        return $validation_result;
    }

    /**
     * Perform enhanced validation
     */
    private function perform_enhanced_validation($params) {
        $domain = sanitize_text_field($params['domain'] ?? '');
        $fingerprint = sanitize_text_field($params['fingerprint'] ?? '');
        $license_key = sanitize_text_field($params['license_key'] ?? '');
        
        if (empty($domain)) {
            return new WP_Error('wplm_missing_domain', esc_html__('Domain is required for validation.', 'wplm'));
        }
        
        if (empty($fingerprint)) {
            return new WP_Error('wplm_missing_fingerprint', esc_html__('Fingerprint is required for validation.', 'wplm'));
        }
        
        // Check if domain is localhost or staging
        if (!$this->is_localhost_or_staging($domain)) {
            // Additional domain validation for production domains
            if (!$this->validate_domain_format($domain)) {
                return new WP_Error('wplm_invalid_domain', esc_html__('Invalid domain format.', 'wplm'));
            }
        }
        
        // Check fingerprint format
        if (!$this->validate_fingerprint_format($fingerprint)) {
            return new WP_Error('wplm_invalid_fingerprint', esc_html__('Invalid fingerprint format.', 'wplm'));
        }
        
        return true;
    }

    /**
     * Validate domain format
     */
    private function validate_domain_format($domain) {
        // Basic domain validation
        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return true;
        }
        
        // Allow IP addresses
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        return false;
    }

    /**
     * Validate fingerprint format
     */
    private function validate_fingerprint_format($fingerprint) {
        // Check if fingerprint is a valid hash (64 characters for SHA256)
        return preg_match('/^[a-f0-9]{64}$/i', $fingerprint);
    }

    /**
     * Validate domain with advanced rules
     */
    public function validate_domain_advanced($license_post, $domain, $fingerprint) {
        if (!$license_post) {
            return new WP_Error('wplm_license_not_found', esc_html__('License not found.', 'wplm'));
        }
        
        $license_type_id = get_post_meta($license_post->ID, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        
        if (!$license_type) {
            return new WP_Error('wplm_invalid_license_type', esc_html__('Invalid license type.', 'wplm'));
        }
        
        // Check domain limits
        $current_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        $max_domains = $license_type->max_domains;
        
        if ($max_domains > 0 && count($current_domains) >= $max_domains) {
            return new WP_Error('wplm_domain_limit_reached', esc_html__('Domain activation limit reached for this license type.', 'wplm'));
        }
        
        // Check if domain is already activated
        if (in_array($domain, $current_domains)) {
            return true; // Domain already activated
        }
        
        // Check subdomain limits
        if ($this->is_subdomain($domain)) {
            $max_subdomains = $license_type->max_subdomains;
            if ($max_subdomains >= 0) {
                $subdomain_count = $this->count_subdomains($current_domains);
                if ($subdomain_count >= $max_subdomains) {
                    return new WP_Error('wplm_subdomain_limit_reached', esc_html__('Subdomain activation limit reached for this license type.', 'wplm'));
                }
            }
        }
        
        // Check localhost/staging restrictions
        if ($this->is_localhost_or_staging($domain)) {
            if (!$license_type->allow_localhost) {
                return new WP_Error('wplm_localhost_not_allowed', esc_html__('Localhost/staging domains not allowed for this license type.', 'wplm'));
            }
        }
        
        return true;
    }

    /**
     * Generate advanced fingerprint
     */
    public function generate_advanced_fingerprint($data, $license_key) {
        $fingerprint_data = [
            'license_key' => $license_key,
            'domain' => $data['domain'] ?? '',
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $data['user_agent'] ?? '',
            'product_version' => $data['product_version'] ?? '',
            'php_version' => $data['php_version'] ?? '',
            'wp_version' => $data['wp_version'] ?? '',
            'timestamp' => time(),
            'salt' => $this->encryption_key
        ];
        
        // Create a unique fingerprint
        $fingerprint_string = implode('|', array_filter($fingerprint_data));
        return hash('sha256', $fingerprint_string);
    }

    /**
     * Track license usage
     */
    public function track_license_usage($license_key, $validation_result) {
        global $wpdb;
        
        $license_key = sanitize_text_field($license_key);
        $domain = sanitize_text_field($validation_result['domain'] ?? '');
        $fingerprint = sanitize_text_field($validation_result['fingerprint'] ?? '');
        
        if (empty($license_key) || empty($domain)) {
            return false;
        }
        
        $table_name = $wpdb->prefix . 'wplm_license_usage';
        
        // Check if usage record exists
        $existing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE license_key = %s AND domain = %s",
            $license_key,
            $domain
        ));
        
        $usage_data = [
            'license_key' => $license_key,
            'domain' => $domain,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'fingerprint' => $fingerprint,
            'last_check' => current_time('mysql'),
            'product_version' => sanitize_text_field($validation_result['product_version'] ?? ''),
            'php_version' => sanitize_text_field($validation_result['php_version'] ?? ''),
            'wp_version' => sanitize_text_field($validation_result['wp_version'] ?? '')
        ];
        
        if ($existing_record) {
            // Update existing record
            $usage_data['check_count'] = $existing_record->check_count + 1;
            $wpdb->update(
                $table_name,
                $usage_data,
                ['id' => $existing_record->id]
            );
        } else {
            // Insert new record
            $usage_data['check_count'] = 1;
            $wpdb->insert($table_name, $usage_data);
        }
        
        // Detect suspicious activity
        $this->detect_suspicious_activity($license_key, $domain, $validation_result);
        
        return true;
    }

    /**
     * Detect suspicious activity
     */
    private function detect_suspicious_activity($license_key, $domain, $validation_result) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_license_usage';
        
        // Check for rapid successive checks
        $recent_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
            WHERE license_key = %s AND domain = %s 
            AND last_check >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $license_key,
            $domain
        ));
        
        if ($recent_checks > 10) {
            $this->log_security_incident(
                $license_key,
                'excessive_checks',
                'high',
                sprintf('Excessive license checks detected: %d checks in 1 hour', $recent_checks),
                ['check_count' => $recent_checks, 'domain' => $domain]
            );
        }
        
        // Check for multiple domains from same IP
        $ip_address = $this->get_client_ip();
        $domains_from_ip = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT domain) FROM {$table_name} 
            WHERE ip_address = %s AND last_check >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $ip_address
        ));
        
        if ($domains_from_ip > 5) {
            $this->log_security_incident(
                $license_key,
                'multiple_domains_same_ip',
                'medium',
                sprintf('Multiple domains activated from same IP: %d domains', $domains_from_ip),
                ['ip_address' => $ip_address, 'domain_count' => $domains_from_ip]
            );
        }
        
        // Check for fingerprint mismatch
        $expected_fingerprint = $this->generate_advanced_fingerprint($validation_result, $license_key);
        if ($fingerprint !== $expected_fingerprint) {
            $this->log_security_incident(
                $license_key,
                'fingerprint_mismatch',
                'high',
                'License fingerprint mismatch detected',
                [
                    'expected_fingerprint' => $expected_fingerprint,
                    'received_fingerprint' => $fingerprint,
                    'domain' => $domain
                ]
            );
        }
    }

    /**
     * Log security incident
     */
    private function log_security_incident($license_key, $incident_type, $severity, $description, $additional_data = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_security_incidents';
        
        $incident_data = [
            'license_key' => sanitize_text_field($license_key),
            'incident_type' => sanitize_key($incident_type),
            'severity' => sanitize_key($severity),
            'description' => sanitize_textarea_field($description),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'additional_data' => json_encode($this->sanitize_recursive_data($additional_data))
        ];
        
        $wpdb->insert($table_name, $incident_data);
        
        // Log to WordPress error log
        error_log(sprintf(
            'WPLM Security Incident: %s - %s (Severity: %s) for license %s',
            $incident_type,
            $description,
            $severity,
            $license_key
        ));
        
        // Trigger action for other plugins to hook into
        do_action('wplm_security_incident_logged', $license_key, $incident_type, $severity, $description, $additional_data);
    }

    /**
     * Sanitize recursive data
     */
    private function sanitize_recursive_data($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize_recursive_data($value);
            }
        } elseif (is_string($data)) {
            $data = sanitize_text_field($data);
        }
        
        return $data;
    }

    /**
     * Handle suspicious activity
     */
    public function handle_suspicious_activity($license_key, $incident_type, $data) {
        switch ($incident_type) {
            case 'excessive_checks':
                $this->throttle_license($license_key, 3600); // 1 hour
                break;
                
            case 'fingerprint_mismatch':
                $this->flag_license_for_review($license_key, 'Fingerprint mismatch detected');
                break;
                
            case 'multiple_domains_same_ip':
                $this->block_license_temporarily($license_key, 86400); // 24 hours
                break;
                
            default:
                // Log unknown incident type
                $this->log_security_incident($license_key, $incident_type, 'medium', 'Unknown incident type', $data);
                break;
        }
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
     * Check rate limit
     */
    public function check_rate_limit($license_key, $domain) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_license_usage';
        
        // Check recent requests
        $recent_requests = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
            WHERE license_key = %s AND domain = %s 
            AND last_check >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
            $license_key,
            $domain
        ));
        
        if ($recent_requests > 5) {
            return new WP_Error('wplm_rate_limit_exceeded', esc_html__('Rate limit exceeded. Please wait before making another request.', 'wplm'));
        }
        
        return true;
    }

    /**
     * Check if domain is localhost or staging
     */
    private function is_localhost_or_staging($domain) {
        $localhost_patterns = [
            '/^localhost$/i',
            '/^127\.0\.0\.1$/',
            '/^::1$/',
            '/^192\.168\./',
            '/^10\./',
            '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
            '/\.local$/i',
            '/\.test$/i',
            '/\.dev$/i',
            '/\.staging$/i'
        ];
        
        foreach ($localhost_patterns as $pattern) {
            if (preg_match($pattern, $domain)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if domain is a subdomain
     */
    private function is_subdomain($domain) {
        $parts = explode('.', $domain);
        return count($parts) > 2;
    }

    /**
     * Extract main domain
     */
    private function extract_main_domain($domain) {
        $parts = explode('.', $domain);
        if (count($parts) > 2) {
            return implode('.', array_slice($parts, -2));
        }
        return $domain;
    }

    /**
     * Count subdomains in activated domains
     */
    private function count_subdomains($domains) {
        $subdomain_count = 0;
        foreach ($domains as $domain) {
            if ($this->is_subdomain($domain)) {
                $subdomain_count++;
            }
        }
        return $subdomain_count;
    }

    /**
     * Get license type
     */
    private function get_license_type($type_id) {
        global $wpdb;
        
        if (empty($type_id)) {
            return false;
        }
        
        $table = $wpdb->prefix . 'wplm_license_types';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($type_id)));
    }

    /**
     * Get license features
     */
    private function get_license_features($license_id) {
        $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        
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
        $license_type = $this->get_license_type($license_type_id);
        
        if (!$license_type) {
            return time() + (24 * 60 * 60); // Default to 24 hours
        }
        
        $check_interval = $license_type->check_interval;
        return time() + ($check_interval * 60 * 60);
    }

    /**
     * Throttle license
     */
    private function throttle_license($license_key, $duration) {
        update_option("wplm_throttled_{$license_key}", time() + $duration);
    }

    /**
     * Flag license for review
     */
    private function flag_license_for_review($license_key, $reason) {
        update_post_meta_by_key("wplm_flagged_{$license_key}", [
            'reason' => $reason,
            'flagged_at' => current_time('mysql'),
            'status' => 'pending_review'
        ]);
    }

    /**
     * Block license temporarily
     */
    private function block_license_temporarily($license_key, $duration) {
        update_option("wplm_blocked_{$license_key}", time() + $duration);
        
        // Log the blocking
        $this->log_security_incident(
            $license_key,
            'license_temporarily_blocked',
            'high',
            sprintf('License temporarily blocked for %d seconds', $duration),
            ['duration' => $duration, 'blocked_until' => date('Y-m-d H:i:s', time() + $duration)]
        );
    }

    /**
     * Perform license health check
     */
    public function perform_license_health_check() {
        global $wpdb;
        
        // Check for expired throttles
        $throttled_keys = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'wplm_throttled_%'"
        );
        
        foreach ($throttled_keys as $option) {
            $expiry_time = (int) $option->option_value;
            if (time() > $expiry_time) {
                delete_option($option->option_name);
            }
        }
        
        // Check for expired blocks
        $blocked_keys = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'wplm_blocked_%'"
        );
        
        foreach ($blocked_keys as $option) {
            $expiry_time = (int) $option->option_value;
            if (time() > $expiry_time) {
                delete_option($option->option_name);
                
                // Extract license key from option name
                $license_key = str_replace('wplm_blocked_', '', $option->option_name);
                
                // Log unblocking
                $this->log_security_incident(
                    $license_key,
                    'license_unblocked',
                    'low',
                    'License automatically unblocked after expiry',
                    ['blocked_until' => date('Y-m-d H:i:s', $expiry_time)]
                );
            }
        }
        
        // Log health check completion
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'license_health_check_completed',
                'License health check completed',
                ['throttled_count' => count($throttled_keys), 'blocked_count' => count($blocked_keys)]
            );
        }
    }
}