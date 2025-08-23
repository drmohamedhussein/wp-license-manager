<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Licensing System for WPLM
 * Inspired by Elite Licenser and other professional licensing systems
 */
class WPLM_Advanced_Licensing {

    private $encryption_key;
    private $api_version = '2.0';

    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
        
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_api_endpoints']);
        
        // Advanced license validation hooks
        add_filter('wplm_validate_license_request', [$this, 'enhanced_license_validation'], 10, 3);
        add_filter('wplm_license_response', [$this, 'encrypt_license_response'], 10, 2);
        
        // Security and anti-piracy measures
        add_action('wplm_license_activated', [$this, 'track_license_usage'], 10, 2);
        add_action('wplm_suspicious_activity', [$this, 'handle_suspicious_activity'], 10, 3);
        
        // License fingerprinting
        add_filter('wplm_generate_fingerprint', [$this, 'generate_advanced_fingerprint'], 10, 2);
        
        // Remote kill switch
        add_action('wp_ajax_nopriv_wplm_remote_disable', [$this, 'handle_remote_disable']);
        
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
        
        // License usage tracking table
        $usage_table = $wpdb->prefix . 'wplm_license_usage';
        if ($wpdb->get_var("SHOW TABLES LIKE '$usage_table'") !== $usage_table) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $usage_table (
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
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        // Security incidents table
        $security_table = $wpdb->prefix . 'wplm_security_incidents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$security_table'") !== $security_table) {
            $sql = "CREATE TABLE $security_table (
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
            ) $charset_collate;";
            
            dbDelta($sql);
        }
        
        // License types table
        $types_table = $wpdb->prefix . 'wplm_license_types';
        if ($wpdb->get_var("SHOW TABLES LIKE '$types_table'") !== $types_table) {
            $sql = "CREATE TABLE $types_table (
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
            ) $charset_collate;";
            
            dbDelta($sql);
            
            // Insert default license types
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
                'type_name' => 'Personal License',
                'type_slug' => 'personal',
                'description' => 'Single site license for personal use',
                'max_domains' => 1,
                'max_subdomains' => 0,
                'features' => json_encode(['updates', 'support'])
            ],
            [
                'type_name' => 'Business License', 
                'type_slug' => 'business',
                'description' => 'Multi-site license for business use',
                'max_domains' => 5,
                'max_subdomains' => 2,
                'features' => json_encode(['updates', 'support', 'premium_addons'])
            ],
            [
                'type_name' => 'Developer License',
                'type_slug' => 'developer', 
                'description' => 'Unlimited sites for developers',
                'max_domains' => -1,
                'max_subdomains' => -1,
                'features' => json_encode(['updates', 'support', 'premium_addons', 'white_label'])
            ],
            [
                'type_name' => 'Lifetime License',
                'type_slug' => 'lifetime',
                'description' => 'Lifetime access license',
                'max_domains' => 3,
                'max_subdomains' => 1,
                'check_interval' => 168, // Weekly
                'features' => json_encode(['updates', 'support', 'lifetime_access'])
            ]
        ];
        
        foreach ($default_types as $type) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE type_slug = %s",
                $type['type_slug']
            ));
            
            if (!$exists) {
                $wpdb->insert($table, $type);
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
        $license_key = $params['license_key'];
        $domain = $params['domain'];
        $product_id = $params['product_id'];
        $fingerprint = $params['fingerprint'];
        
        // Basic validation
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            $this->log_security_incident($license_key, 'invalid_license', 'high', 'License key not found', $params);
            return ['valid' => false, 'error' => 'INVALID_LICENSE', 'message' => 'License not found'];
        }
        
        // Check license status
        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
            return ['valid' => false, 'error' => 'LICENSE_INACTIVE', 'message' => 'License is not active'];
        }
        
        // Check expiry
        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
            return ['valid' => false, 'error' => 'LICENSE_EXPIRED', 'message' => 'License has expired'];
        }
        
        // Check product association
        $license_product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        if ($product_id && $license_product_id !== $product_id) {
            $this->log_security_incident($license_key, 'product_mismatch', 'medium', 'Product ID mismatch', $params);
            return ['valid' => false, 'error' => 'PRODUCT_MISMATCH', 'message' => 'License not valid for this product'];
        }
        
        // Advanced domain validation
        $domain_validation = $this->validate_domain_advanced($license_post, $domain, $fingerprint);
        if (!$domain_validation['valid']) {
            return $domain_validation;
        }
        
        // Check activation limits
        $activation_limit = get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        
        if (!in_array($domain, $activated_domains)) {
            if (count($activated_domains) >= $activation_limit) {
                return ['valid' => false, 'error' => 'ACTIVATION_LIMIT_EXCEEDED', 'message' => 'Activation limit exceeded'];
            }
        }
        
        // Fingerprint validation
        if (!empty($fingerprint)) {
            $stored_fingerprints = get_post_meta($license_post->ID, '_wplm_fingerprints', true) ?: [];
            if (!empty($stored_fingerprints[$domain]) && $stored_fingerprints[$domain] !== $fingerprint) {
                $this->log_security_incident($license_key, 'fingerprint_mismatch', 'high', 'Fingerprint mismatch detected', $params);
                return ['valid' => false, 'error' => 'FINGERPRINT_MISMATCH', 'message' => 'Site fingerprint mismatch'];
            }
        }
        
        // Rate limiting check
        if (!$this->check_rate_limit($license_key, $domain)) {
            return ['valid' => false, 'error' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests'];
        }
        
        // All checks passed
        return [
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
            'next_check' => $this->calculate_next_check_time($license_post->ID)
        ];
    }

    /**
     * Advanced domain validation
     */
    private function validate_domain_advanced($license_post, $domain, $fingerprint) {
        // Get license type
        $license_type_id = get_post_meta($license_post->ID, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        
        // Check if domain is localhost/staging
        if ($this->is_localhost_or_staging($domain)) {
            if (!$license_type || !$license_type->allow_localhost) {
                return ['valid' => false, 'error' => 'LOCALHOST_NOT_ALLOWED', 'message' => 'Localhost not allowed for this license'];
            }
        }
        
        // Check subdomain limits
        if ($this->is_subdomain($domain)) {
            $main_domain = $this->extract_main_domain($domain);
            $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
            $subdomain_count = 0;
            
            foreach ($activated_domains as $activated_domain) {
                if ($this->is_subdomain($activated_domain) && $this->extract_main_domain($activated_domain) === $main_domain) {
                    $subdomain_count++;
                }
            }
            
            if ($license_type && $license_type->max_subdomains !== -1 && $subdomain_count >= $license_type->max_subdomains) {
                return ['valid' => false, 'error' => 'SUBDOMAIN_LIMIT_EXCEEDED', 'message' => 'Subdomain limit exceeded'];
            }
        }
        
        return ['valid' => true];
    }

    /**
     * Generate advanced fingerprint
     */
    public function generate_advanced_fingerprint($data, $license_key) {
        $fingerprint_data = [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
            'timezone' => date_default_timezone_get(),
            'extensions' => array_slice(get_loaded_extensions(), 0, 10), // Limit to prevent bloat
            'domain' => $data['domain'] ?? '',
            'ip_hash' => hash('sha256', $this->get_client_ip()),
            'license_key_hash' => hash('sha256', $license_key)
        ];
        
        return hash('sha256', serialize($fingerprint_data));
    }

    /**
     * Track license usage
     */
    public function track_license_usage($license_key, $validation_result) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        $domain = $validation_result['domain'] ?? ($_POST['domain'] ?? ''); // Safely get domain
        $fingerprint = $validation_result['fingerprint'] ?? ($_POST['fingerprint'] ?? ''); // Safely get fingerprint
        $ip_address = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Check if entry exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE license_key = %s AND domain = %s",
            $license_key, $domain
        ));
        
        if ($existing) {
            // Update existing entry
            $wpdb->update(
                $table,
                [
                    'last_check' => current_time('mysql'),
                    'check_count' => $existing->check_count + 1,
                    'fingerprint' => $fingerprint,
                    'ip_address' => $ip_address,
                    'user_agent' => $user_agent,
                    'status' => $validation_result['valid'] ? 'active' : 'invalid'
                ],
                ['id' => $existing->id],
                ['%s', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Insert new entry
            $wpdb->insert(
                $table,
                [
                    'license_key' => $license_key,
                    'domain' => $domain,
                    'ip_address' => $ip_address,
                    'user_agent' => $user_agent,
                    'fingerprint' => $fingerprint,
                    'last_check' => current_time('mysql'),
                    'status' => $validation_result['valid'] ? 'active' : 'invalid'
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }
        
        // Detect suspicious activity
        $this->detect_suspicious_activity($license_key, $domain, $validation_result);
    }

    /**
     * Detect suspicious activity
     */
    private function detect_suspicious_activity($license_key, $domain, $validation_result) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_usage';
        
        // Check for rapid requests from different IPs
        $recent_ips = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT ip_address FROM $table 
             WHERE license_key = %s AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $license_key
        ));
        
        if (count($recent_ips) > 5) {
            $this->log_security_incident($license_key, 'multiple_ips', 'high', 'Multiple IPs detected within 1 hour', [
                'ip_count' => count($recent_ips),
                'ips' => array_column($recent_ips, 'ip_address')
            ]);
        }
        
        // Check for too many failed validations
        $failed_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
             WHERE license_key = %s AND status = 'invalid' AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $license_key
        ));
        
        if ($failed_checks > 10) {
            $this->log_security_incident($license_key, 'excessive_failures', 'medium', 'Excessive validation failures', [
                'failure_count' => $failed_checks
            ]);
        }
    }

    /**
     * Log security incident
     */
    private function log_security_incident($license_key, $incident_type, $severity, $description, $additional_data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_security_incidents';
        
        $wpdb->insert(
            $table,
            [
                'license_key' => $license_key,
                'incident_type' => $incident_type,
                'severity' => $severity,
                'description' => $description,
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'additional_data' => json_encode($additional_data)
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        // Trigger action for external handling
        do_action('wplm_security_incident', $license_key, $incident_type, $severity, $description, $additional_data);
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
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            $this->log_security_incident($license_key, 'activation_failed', 'medium', 'License not found');
            return ['valid' => false, 'message' => __('License not found', 'wp-license-manager')];
        }

        $license_id = $license_post->ID;
        $status = get_post_meta($license_id, '_wplm_status', true);
        if ($status !== 'active') {
            $this->log_security_incident($license_key, 'activation_failed', 'medium', 'License inactive');
            return ['valid' => false, 'message' => __('License is not active', 'wp-license-manager')];
        }

        $expiry_date = get_post_meta($license_id, '_wplm_expiry_date', true);
        if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
            $this->log_security_incident($license_key, 'activation_failed', 'medium', 'License expired');
            return ['valid' => false, 'message' => __('License has expired', 'wp-license-manager')];
        }

        // Check activation limits
        $activation_limit = get_post_meta($license_id, '_wplm_activation_limit', true) ?: 1;
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];

        if (!in_array($domain, $activated_domains)) {
            if ($activation_limit !== -1 && count($activated_domains) >= $activation_limit) {
                $this->log_security_incident($license_key, 'activation_limit_exceeded', 'high', 'Activation limit exceeded');
                return ['valid' => false, 'message' => __('Activation limit exceeded', 'wp-license-manager')];
            }
            // Add new domain
            $activated_domains[] = $domain;
            update_post_meta($license_id, '_wplm_activated_domains', $activated_domains);
        }

        // Store fingerprint if provided
        if (!empty($fingerprint)) {
            $stored_fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];
            $stored_fingerprints[$domain] = $fingerprint;
            update_post_meta($license_id, '_wplm_fingerprints', $stored_fingerprints);
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $license_id,
                'license_activated',
                sprintf('License %s activated on domain %s', $license_key, $domain),
                ['domain' => $domain, 'fingerprint' => $fingerprint]
            );
        }

        return ['valid' => true, 'message' => __('License activated successfully', 'wp-license-manager')];
    }

    private function deactivate_license_on_domain($license_key, $domain) {
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            $this->log_security_incident($license_key, 'deactivation_failed', 'medium', 'License not found');
            return ['valid' => false, 'message' => __('License not found', 'wp-license-manager')];
        }

        $license_id = $license_post->ID;
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
        $stored_fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];

        $domain_index = array_search($domain, $activated_domains);

        if ($domain_index !== false) {
            // Remove domain from activated list
            unset($activated_domains[$domain_index]);
            update_post_meta($license_id, '_wplm_activated_domains', array_values($activated_domains)); // Re-index array

            // Remove associated fingerprint
            if (isset($stored_fingerprints[$domain])) {
                unset($stored_fingerprints[$domain]);
                update_post_meta($license_id, '_wplm_fingerprints', $stored_fingerprints);
            }

            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license_id,
                    'license_deactivated',
                    sprintf('License %s deactivated on domain %s', $license_key, $domain),
                    ['domain' => $domain]
                );
            }
            return ['valid' => true, 'message' => __('License deactivated successfully', 'wp-license-manager')];
        } else {
            $this->log_security_incident($license_key, 'deactivation_failed', 'medium', 'Domain not found for this license');
            return ['valid' => false, 'message' => __('Domain not found for this license', 'wp-license-manager')];
        }
    }

    private function get_license_info($license_key) {
        $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
        if (!$license_post) {
            return ['valid' => false, 'error' => 'LICENSE_NOT_FOUND', 'message' => __('License not found', 'wp-license-manager')];
        }

        $license_id = $license_post->ID;
        
        // Gather all relevant license data
        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_type = get_post_meta($license_id, '_wplm_product_type', true);
        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        $expiry_date = get_post_meta($license_id, '_wplm_expiry_date', true);
        $activation_limit = get_post_meta($license_id, '_wplm_activation_limit', true);
        $status = get_post_meta($license_id, '_wplm_status', true);
        $current_version = get_post_meta($license_id, '_wplm_current_version', true);
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
        $fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true) ?: [];
        $license_type_id = get_post_meta($license_id, '_wplm_license_type', true);
        $license_type = $this->get_license_type($license_type_id);
        $features = $this->get_license_features($license_id);

        return [
            'valid' => true,
            'license_key' => $license_key,
            'license_id' => $license_id,
            'product_id' => $product_id,
            'product_type' => $product_type,
            'customer_email' => $customer_email,
            'expiry_date' => $expiry_date,
            'activation_limit' => intval($activation_limit),
            'status' => $status,
            'current_version' => $current_version,
            'activated_domains' => $activated_domains,
            'fingerprints' => $fingerprints,
            'license_type' => $license_type ? json_decode(json_encode($license_type), true) : null, // Convert object to array
            'features' => $features,
            'created_at' => $license_post->post_date,
            'modified_at' => $license_post->post_modified,
            'server_time' => current_time('mysql'),
            'next_check_time' => $this->calculate_next_check_time($license_id)
        ];
    }
}
