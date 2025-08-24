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
                $insert_result = $wpdb->insert($table, $type);
                if (false === $insert_result) {
                    error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: Failed to insert default license type %s. Error: %s', 'wplm'), esc_html($type['type_slug']), $wpdb->last_error));
                }
            }
        }
    }

    /**
     * Initialize license types system
     */
    private function init_license_types() {
        // Add license type meta box to license edit screen
        add_action('add_meta_boxes', [$this, 'add_license_type_meta_box']);
        add_action('save_post', [$this, 'save_license_type_meta']);
    }

    /**
     * Setup security measures
     */
    private function setup_security_measures() {
        // Rate limiting for license checks
        add_action('wplm_before_license_check', [$this, 'check_rate_limit'], 10, 2);
        
        // IP whitelist/blacklist
        add_filter('wplm_allow_license_check', [$this, 'check_ip_restrictions'], 10, 3);
        
        // Domain validation
        add_filter('wplm_validate_domain', [$this, 'advanced_domain_validation'], 10, 3);
        
        // Anti-tampering measures
        add_filter('wplm_license_check_payload', [$this, 'add_security_payload'], 10, 2);
    }

    /**
     * Add security payload to license check requests
     */
    public function add_security_payload($payload, $license_key) {
        if (!is_array($payload)) {
            $payload = [];
        }
        
        // Add timestamp for request validation
        $payload['timestamp'] = time();
        
        // Add client fingerprint if available
        $payload['client_fingerprint'] = $this->generate_client_fingerprint();
        
        // Add request hash for integrity
        $payload['request_hash'] = $this->generate_request_hash($payload, $license_key);
        
        return $payload;
    }

    /**
     * Generate client fingerprint
     */
    private function generate_client_fingerprint() {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $this->get_client_ip()
        ];
        
        return hash('sha256', implode('|', $components));
    }

    /**
     * Generate request hash for integrity
     */
    private function generate_request_hash($payload, $license_key) {
        $data = $license_key . '|' . json_encode($payload) . '|' . $this->encryption_key;
        return hash('sha256', $data);
    }

    /**
     * Enhanced license validation
     */
    public function enhanced_license_validation($is_valid, $license_key, $domain) {
        // Basic validation already passed, add advanced checks
        if (!$is_valid) {
            return false;
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit($license_key, $domain)) {
            return false;
        }
        
        // Check IP restrictions
        if (!$this->check_ip_restrictions($license_key, $domain)) {
            return false;
        }
        
        return true;
    }

    /**
     * Track license usage
     */
    public function track_license_usage($license_id, $domain) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        $data = [
            'license_key' => get_the_title($license_id),
            'domain' => sanitize_text_field($domain),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fingerprint' => $this->generate_client_fingerprint(),
            'last_check' => current_time('mysql'),
            'check_count' => 1,
            'status' => 'active'
        ];
        
        $wpdb->insert($table, $data);
    }

    /**
     * Handle suspicious activity
     */
    public function handle_suspicious_activity($license_key, $activity_type, $details) {
        $this->log_security_incident($license_key, $activity_type, 'high', $details);
        
        // Log to activity logger if available
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(0, 'suspicious_activity', $details, ['license_key' => $license_key, 'type' => $activity_type]);
        }
    }

    /**
     * Generate advanced fingerprint
     */
    public function generate_advanced_fingerprint($base_fingerprint, $license_key) {
        $components = [
            $base_fingerprint,
            $license_key,
            $this->encryption_key,
            time()
        ];
        
        return hash('sha256', implode('|', $components));
    }

    /**
     * Perform license health check
     */
    public function perform_license_health_check() {
        // Check for expired licenses
        $expired_licenses = get_posts([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_expiry_date',
            'meta_value' => current_time('mysql'),
            'meta_compare' => '<',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        foreach ($expired_licenses as $license_id) {
            update_post_meta($license_id, '_wplm_status', 'expired');
        }
    }

    /**
     * Check rate limiting
     */
    public function check_rate_limit($license_key, $domain) {
        $key = 'wplm_rate_limit_' . md5($license_key . $domain);
        $count = get_transient($key) ?: 0;
        
        if ($count > 100) { // Max 100 requests per hour
            return false;
        }
        
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Check IP restrictions
     */
    public function check_ip_restrictions($license_key, $domain) {
        $client_ip = $this->get_client_ip();
        
        // Check blacklist
        $blacklist = get_option('wplm_ip_blacklist', []);
        if (in_array($client_ip, $blacklist)) {
            return false;
        }
        
        // Check whitelist if enabled
        $whitelist = get_option('wplm_ip_whitelist', []);
        if (!empty($whitelist) && !in_array($client_ip, $whitelist)) {
            return false;
        }
        
        return true;
    }

    /**
     * Advanced domain validation
     */
    public function advanced_domain_validation($is_valid, $domain, $license_key) {
        if (!$is_valid) {
            return false;
        }
        
        // Check for suspicious domain patterns
        $suspicious_patterns = ['localhost', '127.0.0.1', '0.0.0.0', 'test', 'demo'];
        foreach ($suspicious_patterns as $pattern) {
            if (stripos($domain, $pattern) !== false) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Log security incident
     */
    private function log_security_incident($license_key, $incident_type, $severity, $description, $additional_data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_security_incidents';
        
        $data = [
            'license_key' => sanitize_text_field($license_key),
            'incident_type' => sanitize_key($incident_type),
            'severity' => sanitize_key($severity),
            'description' => sanitize_textarea_field($description),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'additional_data' => json_encode($additional_data),
            'status' => 'active'
        ];
        
        $wpdb->insert($table, $data);
    }

    /**
     * Get encryption key for secure operations
     */
    private function get_encryption_key() {
        $key = get_option('wplm_encryption_key', '');
        
        if (empty($key)) {
            // Generate a new encryption key if none exists
            if (function_exists('random_bytes')) {
                $key = bin2hex(random_bytes(32)); // 64 character hex string
            } else {
                // Fallback for older PHP versions
                $key = md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
            }
            
            // Store the key
            update_option('wplm_encryption_key', $key);
        }
        
        return $key;
    }

    /**
     * Sanitize recursive data structures
     */
    private function sanitize_recursive_data($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize_recursive_data'], $data);
        } elseif (is_string($data)) {
            return sanitize_text_field($data);
        } elseif (is_numeric($data)) {
            return is_float($data) ? floatval($data) : intval($data);
        } elseif (is_bool($data)) {
            return (bool) $data;
        }
        return $data;
    }

    /**
     * Get license type information
     */
    private function get_license_type($type_id) {
        if (empty($type_id)) {
            return null;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($type_id)));
    }

    /**
     * Get license features
     */
    private function get_license_features($license_id) {
        $features = get_post_meta($license_id, '_wplm_features', true);
        if (is_array($features)) {
            return array_map('sanitize_text_field', $features);
        }
        return [];
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
     * Get license information
     */
    private function get_license_info($license_key) {
        // Sanitize license key
        $sanitized_license_key = sanitize_text_field($license_key);

        $license_post = get_page_by_title($sanitized_license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            error_log(sprintf(esc_html__('WPLM_Advanced_Licensing: License not found for info request: %s.', 'wplm'), $sanitized_license_key));
            return ['valid' => false, 'error' => 'LICENSE_NOT_FOUND', 'message' => esc_html__('License not found.', 'wplm')];
        }

        $license_id = $license_post->ID;
        
        // Gather all relevant license data with sanitization and validation
        $product_id = sanitize_text_field(get_post_meta($license_id, '_wplm_product_id', true));
        $product_type = sanitize_key(get_post_meta($license_id, '_wplm_product_type', true));
        $customer_email = sanitize_email(get_post_meta($license_id, '_wplm_customer_email', true));
        $expiry_date = sanitize_text_field(get_post_meta($license_id, '_wplm_expiry_date', true));
        $activation_limit = intval(get_post_meta($license_id, '_wplm_activation_limit', true));
        $status = sanitize_key(get_post_meta($license_id, '_wplm_status', true));
        $current_version = sanitize_text_field(get_post_meta($license_id, '_wplm_current_version', true));
        
        // Activated domains and fingerprints require recursive sanitization
        $raw_activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
        $activated_domains = array_map('sanitize_text_field', $raw_activated_domains);

        $raw_fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];
        $fingerprints = $this->sanitize_recursive_data($raw_fingerprints);
        
        $license_type_id = absint(get_post_meta($license_id, '_wplm_license_type', true));
        $license_type = $this->get_license_type($license_type_id);
        $features = $this->get_license_features($license_id);

        // Log access to license info
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_id,
                'license_info_accessed',
                sprintf(esc_html__('License info accessed for license %s.', 'wplm'), $sanitized_license_key),
                ['license_key' => $sanitized_license_key, 'accessed_by_ip' => $this->get_client_ip()]
            );
        }

        return [
            'valid' => true,
            'license_key' => $sanitized_license_key,
            'license_id' => $license_id,
            'product_id' => $product_id,
            'product_type' => $product_type,
            'customer_email' => $customer_email,
            'expiry_date' => $expiry_date,
            'activation_limit' => $activation_limit,
            'status' => $status,
            'current_version' => $current_version,
            'activated_domains' => $activated_domains,
            'fingerprints' => $fingerprints,
            'license_type' => $license_type ? json_decode(json_encode($license_type), true) : null, // Convert object to array
            'features' => $features,
            'created_at' => $license_post->post_date,
            'modified_at' => $license_post->post_modified,
            'server_time' => current_time('mysql'),
        ];
    }
}
