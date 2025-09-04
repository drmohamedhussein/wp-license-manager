<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the public-facing AJAX API endpoints.
 */
final class WPLM_API_Manager {

    private ?string $stored_api_key = null;

    public function __construct() {
        $this->add_ajax_hooks();
        
        // Ensure sessions are closed before AJAX requests
        add_action('wp_ajax_wplm_validate', [$this, 'close_sessions_before_request'], 1);
        add_action('wp_ajax_nopriv_wplm_validate', [$this, 'close_sessions_before_request'], 1);
        add_action('wp_ajax_wplm_activate', [$this, 'close_sessions_before_request'], 1);
        add_action('wp_ajax_nopriv_wplm_activate', [$this, 'close_sessions_before_request'], 1);
        add_action('wp_ajax_wplm_deactivate', [$this, 'close_sessions_before_request'], 1);
        add_action('wp_ajax_nopriv_wplm_deactivate', [$this, 'close_sessions_before_request'], 1);
    }
    
    /**
     * Close any active sessions before AJAX requests
     */
    public function close_sessions_before_request() {
        if (session_id()) {
            session_write_close();
        }
    }

    /**
     * Register public AJAX hooks.
     * These are prefixed with 'wplm_' to avoid conflicts.
     */
    private function add_ajax_hooks(): void {
        $actions = ['validate', 'activate', 'deactivate', 'remove_domain', 'info', 'update_check'];
        foreach ($actions as $action) {
            // Register both authenticated and non-authenticated versions
            add_action('wp_ajax_wplm_' . $action, [$this, 'ajax_' . $action]);
            add_action('wp_ajax_nopriv_wplm_' . $action, [$this, 'ajax_' . $action]);
        }
    }

    /**
     * Check the API key from the POST data. Terminates on failure.
     */
    private function check_api_key(): void {
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        
        if (is_null($this->stored_api_key)) {
            $this->stored_api_key = get_option('wplm_api_key', '');
        }

        // Check rate limiting first
        if (!$this->check_rate_limit()) {
            wp_send_json_error(['message' => 'Rate limit exceeded. Please try again later.'], 429);
            return;
        }

        // Only log API key validation failures, not successful validations
        if (empty($api_key) || empty($this->stored_api_key) || !hash_equals($this->stored_api_key, $api_key)) {
            error_log("WPLM API: API key validation failed - Key: " . substr($api_key, 0, 10) . "...");
            wp_send_json_error(['message' => 'API key is invalid or missing.'], 403);
        }
        
        // Only log successful validation once per minute to reduce log spam
        $last_log = get_transient('wplm_api_success_log');
        if (!$last_log) {
            error_log("WPLM API: API key validation successful");
            set_transient('wplm_api_success_log', time(), 60); // 1 minute
        }
    }
    
    /**
     * Check rate limiting for API requests
     */
    private function check_rate_limit(): bool {
        $client_ip = $this->get_client_ip();
        $rate_limit_key = 'wplm_api_rate_limit_' . md5($client_ip);
        
        $requests = get_transient($rate_limit_key) ?: 0;
        
        // Allow 60 requests per minute
        if ($requests >= 60) {
            return false;
        }
        
        // Increment request count
        set_transient($rate_limit_key, $requests + 1, 60);
        
        return true;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP', 
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
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
     * Handles plugin/theme update check requests.
     */
    public function ajax_update_check(): void {
        $this->check_api_key();

        $product_slug = sanitize_text_field($_POST['product_slug'] ?? '');
        $client_version = sanitize_text_field($_POST['version'] ?? ''); // Version sent by client

        if (empty($product_slug)) {
            wp_send_json_error(['message' => 'Product slug is required.'], 400);
            WPLM_Activity_Logger::log(0, 'update_check_failed', 'Update check failed: Missing product slug.', $_POST);
            return;
        }

        $product_posts = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $product_slug,
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ]);

        if (empty($product_posts)) {
            wp_send_json_error(['message' => 'Product not found.'], 404);
            WPLM_Activity_Logger::log(0, 'update_check_failed', 'Update check failed: Product not found.', ['product_slug' => $product_slug]);
            return; // Add return to prevent further execution after error
        }
        $product_post = $product_posts[0];

        $latest_version = get_post_meta($product_post->ID, '_wplm_current_version', true);
        $download_url = get_post_meta($product_post->ID, '_wplm_download_url', true);

        if (empty($latest_version) || empty($download_url)) {
            wp_send_json_error(['message' => 'Product is missing version or download URL information.'], 404);
            WPLM_Activity_Logger::log($product_post->ID, 'update_check_failed', 'Update check failed: Missing version or download URL for product.', ['product_slug' => $product_slug]);
            return; // Add return to prevent further execution after error
        }

        // Compare client version with latest version
        if (!empty($client_version) && version_compare($latest_version, $client_version, '<=')) {
            wp_send_json_success(['message' => 'No update available.', 'latest_version' => $latest_version]);
            WPLM_Activity_Logger::log($product_post->ID, 'update_check_no_update', 'Update check: No update available for ' . $product_slug . '. Client version: ' . $client_version . ', Latest version: ' . $latest_version, ['client_version' => $client_version, 'latest_version' => $latest_version]);
        } else {
            wp_send_json_success([
                'message' => 'Update available.',
                'latest_version' => $latest_version,
                'package' => $download_url,
            ]);
            WPLM_Activity_Logger::log($product_post->ID, 'update_check_available', 'Update check: Update available for ' . $product_slug . '. Client version: ' . $client_version . ', Latest version: ' . $latest_version, ['client_version' => $client_version, 'latest_version' => $latest_version, 'package' => $download_url]);
        }
    }

    /**
     * Handles license validation requests.
     */
    public function ajax_validate(): void {
        $this->check_api_key();
        $license_post = $this->_get_license_from_request($_POST);
        if (is_wp_error($license_post)) {
            wp_send_json_error(['message' => $license_post->get_error_message()], 400);
            WPLM_Activity_Logger::log(0, 'license_validation_failed', 'License validation failed: ' . $license_post->get_error_message(), $_POST);
            return; // Exit after sending error
        }
        // Enforce domain-level activation: the calling domain must be present in activated domains
        $domain = sanitize_text_field($_POST['domain'] ?? '');
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        if (!empty($domain)) {
            $activation_limit = (int) get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
            // If activation limit is unlimited (-1), skip domain enforcement; otherwise require membership
            if ($activation_limit !== -1 && !in_array($domain, $activated_domains, true)) {
                wp_send_json_error(['message' => 'Domain is not activated for this license.'], 403);
                WPLM_Activity_Logger::log($license_post->ID, 'license_validation_failed_domain', 'Validation failed for domain not activated: ' . $domain, $_POST);
                return;
            }
        }

        wp_send_json_success(['message' => 'License is valid.']);
        WPLM_Activity_Logger::log($license_post->ID, 'license_validated', 'License ' . $license_post->post_title . ' validated successfully.', $_POST);
    }

    /**
     * Handles license activation requests.
     */
    public function ajax_activate(): void {
        $this->check_api_key();
        $license_post = $this->_get_license_from_request($_POST);
        if (is_wp_error($license_post)) {
            wp_send_json_error(['message' => $license_post->get_error_message()], 400);
            WPLM_Activity_Logger::log(0, 'license_activation_failed', 'License activation failed: ' . $license_post->get_error_message(), $_POST);
            return; // Exit after sending error
        }

        // Check email validation requirement
        $require_email_validation = get_post_meta($license_post->ID, '_wplm_require_email_validation', true);
        $customer_email = get_post_meta($license_post->ID, '_wplm_customer_email', true);
        $provided_email = sanitize_email($_POST['customer_email'] ?? '');
        
        if ($require_email_validation === '1' && !empty($customer_email)) {
            // Email validation is required and customer email is set
            if (empty($provided_email)) {
                wp_send_json_error(['message' => 'Customer email is required for this license.'], 400);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_email_required', 'License activation failed: Email required but not provided.', $_POST);
                return;
            }
            
            if ($provided_email !== $customer_email) {
                wp_send_json_error(['message' => 'Invalid customer email for this license.'], 400);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_email_mismatch', 'License activation failed: Email mismatch. Expected: ' . $customer_email . ', Provided: ' . $provided_email, $_POST);
                return;
            }
        }
        
        // Check domain validation requirement
        $require_domain_validation = get_post_meta($license_post->ID, '_wplm_require_domain_validation', true);
        $allowed_domains = get_post_meta($license_post->ID, '_wplm_allowed_domains', true) ?: [];
        $requesting_domain = sanitize_text_field($_POST['domain'] ?? '');
        
        // Apply WooCommerce-specific validation functions
        $this->apply_woocommerce_validation($license_post, $provided_email, $requesting_domain);
        
        if ($require_domain_validation === '1' && !empty($allowed_domains)) {
            // Domain validation is required and allowed domains are set
            if (empty($requesting_domain)) {
                wp_send_json_error(['message' => 'Domain information is required for this license.'], 400);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_domain_required', 'License activation failed: Domain required but not provided.', $_POST);
                return;
            }
            
            // Normalize domain - extract clean domain name from URL
            $normalized_domain = $this->normalize_domain($requesting_domain);
            
            // Check if domain is in allowed list
            $domain_allowed = false;
            foreach ($allowed_domains as $allowed_domain) {
                $clean_allowed = trim($allowed_domain);
                if (empty($clean_allowed)) continue;
                
                // Normalize allowed domain too
                $normalized_allowed = $this->normalize_domain($clean_allowed);
                
                if ($normalized_domain === $normalized_allowed) {
                    $domain_allowed = true;
                    break;
                }
            }
            
            if (!$domain_allowed) {
                wp_send_json_error(['message' => 'Domain is not authorized for this license.'], 403);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_domain_mismatch', 'License activation failed: Domain not authorized. Requested: ' . $normalized_domain . ', Allowed: ' . implode(', ', $allowed_domains), $_POST);
                return;
            }
        }

        $domain = $this->normalize_domain(sanitize_text_field($_POST['domain']));
        $activation_limit = (int) get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        
        // Check if admin has set activated domains (admin override)
        $admin_override_domains = get_post_meta($license_post->ID, '_wplm_admin_override_domains', true);
        $is_admin_override = !empty($admin_override_domains) && is_array($admin_override_domains) && count($admin_override_domains) > 0;
        
        if ($is_admin_override) {
            // Admin has set specific domains - check if domain is in admin override OR allowed domains
            $normalized_admin_domains = array_map([$this, 'normalize_domain'], $admin_override_domains);
            $normalized_allowed_domains = array_map([$this, 'normalize_domain'], $allowed_domains);
            
            // Domain must be in either admin override domains OR allowed domains
            $domain_in_admin_override = in_array($domain, $normalized_admin_domains);
            $domain_in_allowed = in_array($domain, $normalized_allowed_domains);
            
            if (!$domain_in_admin_override && !$domain_in_allowed) {
                wp_send_json_error(['message' => 'Domain is not authorized for this license.'], 403);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_domain_not_authorized', 'License activation failed: Domain not authorized. Requested: ' . $domain . ', Admin override: ' . implode(', ', $normalized_admin_domains) . ', Allowed: ' . implode(', ', $normalized_allowed_domains), $_POST);
                return;
            }
        }

        if (!in_array($domain, $activated_domains)) {
            if (count($activated_domains) >= $activation_limit) {
                wp_send_json_error(['message' => 'This license has reached its activation limit.'], 403);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_limit_reached', 'License activation failed for ' . $domain . ': Activation limit reached.', $_POST);
                return; // Exit after sending error
            }
            $activated_domains[] = $domain;
            update_post_meta($license_post->ID, '_wplm_activated_domains', $activated_domains);
            WPLM_Activity_Logger::log($license_post->ID, 'license_activated', 'License ' . $license_post->post_title . ' activated on domain: ' . $domain, $_POST);
        } else {
            WPLM_Activity_Logger::log($license_post->ID, 'license_already_active', 'License ' . $license_post->post_title . ' already active on domain: ' . $domain, $_POST);
        }

        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        wp_send_json_success([
            'message' => 'License activated successfully.',
            'expires_on' => $expiry_date ?: 'lifetime',
            'activation_limit' => $activation_limit,
            'activations_count' => count($activated_domains),
        ]);
    }

    /**
     * Handles license deactivation requests.
     */
    public function ajax_deactivate(): void {
        $this->check_api_key();
        $license_post = $this->_get_license_from_request($_POST, false);
        if (is_wp_error($license_post)) {
            wp_send_json_error(['message' => $license_post->get_error_message()], 400);
            return; // Exit after sending error
        }

        $domain = sanitize_text_field($_POST['domain']);
        $license_key = get_the_title($license_post->ID);
        $product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        
        // Lightning-fast deactivation using direct SQL
        global $wpdb;
        
        // Single query to clear all activation data
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} 
             SET meta_value = CASE meta_key
                 WHEN '_wplm_activated_domains' THEN %s
                 WHEN '_wplm_fingerprints' THEN %s
                 WHEN '_wplm_site_data' THEN %s
                 WHEN '_wplm_status' THEN %s
             END
             WHERE post_id = %d 
             AND meta_key IN ('_wplm_activated_domains', '_wplm_fingerprints', '_wplm_site_data', '_wplm_status')",
            serialize([]), // activated_domains
            serialize([]), // fingerprints
            serialize([]), // site_data
            'inactive', // status
            $license_post->ID
        ));
        
        if ($result !== false) {
            // Clear cache immediately
            wp_cache_delete($license_post->ID, 'post_meta');
            
            // Trigger hooks for automatic deactivation
            do_action('wplm_license_deactivated', $license_post->ID, $domain, $license_key);
            do_action('wplm_license_status_changed', $license_post->ID, 'active', 'inactive');
            
            // Notify client sites about license deactivation (but don't deactivate plugins)
            $this->notify_client_sites_deactivation($license_key, $product_id, $domain);
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
        }
            
            wp_send_json_success(['message' => 'License deactivated successfully and plugins deactivated.']);
        } else {
            wp_send_json_error(['message' => 'Deactivation failed.'], 500);
        }
    }
    
    /**
     * Force immediate plugin deactivation for this license
     */
    private function force_immediate_plugin_deactivation($license_key, $product_id, $domain) {
        // Check if automatic plugin deactivator exists
        if (class_exists('WPLM_Automatic_Plugin_Deactivator')) {
            $deactivator = new WPLM_Automatic_Plugin_Deactivator();
            if (method_exists($deactivator, 'force_immediate_deactivation')) {
                $deactivator->force_immediate_deactivation($license_key, $product_id, $domain);
            }
        }
        
    }
    
    /**
     * Notify client sites about license deactivation
     */
    private function notify_client_sites_deactivation($license_key, $product_id, $domain) {
        // Get all activated domains for this license
        $license_query = new WP_Query([
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'title' => $license_key,
            'posts_per_page' => 1
        ]);
        
        if ($license_query->have_posts()) {
            $license_post = $license_query->posts[0];
            $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
            
            // Send notification to each activated domain
            foreach ($activated_domains as $activated_domain) {
                $this->send_deactivation_notification($license_key, $product_id, $activated_domain);
            }
        }
        
        // Log the deactivation
        error_log("WPLM: License $license_key deactivated for product $product_id on domain $domain");
    }
    
    /**
     * Send deactivation notification to a specific domain
     */
    private function send_deactivation_notification($license_key, $product_id, $domain) {
        // Skip if this is the same domain (same-site)
        if ($domain === $this->normalize_domain(home_url())) {
            error_log("WPLM: Skipping same-site notification for domain: $domain");
            return;
        }
        
        // For cross-site notifications, we'll rely on the client's validation checks
        // The client will detect the license is inactive on next validation
        error_log("WPLM: License $license_key deactivated - client site $domain will detect on next validation");
        
        // Optional: Send webhook notification if configured
        do_action('wplm_license_deactivated_notification', $license_key, $product_id, $domain);
    }

    /**
     * Handles domain removal requests (for manual deactivation)
     */
    public function ajax_remove_domain(): void {
        $this->check_api_key();
        $license_post = $this->_get_license_from_request($_POST, false);
        if (is_wp_error($license_post)) {
            wp_send_json_error(['message' => $license_post->get_error_message()], 400);
            return;
        }

        $domain = sanitize_text_field($_POST['domain']);
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];
        
        // Remove the domain from activated domains
        $updated_domains = array_diff($activated_domains, [$domain]);
        update_post_meta($license_post->ID, '_wplm_activated_domains', $updated_domains);
        
        // Log the domain removal
        WPLM_Activity_Logger::log($license_post->ID, 'domain_removed', 'Domain removed from license: ' . $domain, $_POST);
        
        wp_send_json_success(['message' => 'Domain removed successfully.']);
    }

    /**
     * Handles license info requests for updates
     */
    public function ajax_info(): void {
        $this->check_api_key();
        $license_post = $this->_get_license_from_request($_POST);
        if (is_wp_error($license_post)) {
            wp_send_json_error(['message' => $license_post->get_error_message()], 400);
            return;
        }

        $product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        $product_posts = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $product_id,
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ]);

        if (empty($product_posts)) {
            wp_send_json_error(['message' => 'Product not found.'], 404);
            return;
        }

        $product_post = $product_posts[0];
        $version = get_post_meta($product_post->ID, '_wplm_current_version', true);
        $download_url = get_post_meta($product_post->ID, '_wplm_download_url', true);

        wp_send_json_success([
            'message' => 'License info retrieved.',
            'version' => $version ?: '1.0.0',
            'package' => $download_url ?: '',
        ]);
    }

    /**
     * A helper function to find and validate a license from a request.
     *
     * @param array $data The request data.
     * @param bool $check_expiry Whether to check license expiry.
     * @return WP_Post|WP_Error The license post object or a WP_Error on failure.
     */
    private function _get_license_from_request(array $data, bool $check_expiry = true): WP_Post|WP_Error {
        $license_key = sanitize_text_field($data['license_key'] ?? '');
        $product_id = sanitize_text_field($data['product_id'] ?? '');

        if (empty($license_key) || empty($product_id)) {
            return new WP_Error('missing_params', 'License key and product ID are required.');
        }

        // Use WP_Query to reliably find the license by its title (the key)
        $license_post = wplm_get_post_by_title($license_key, 'wplm_license');
        if (!$license_post) {
            return new WP_Error('invalid_license', 'License key not found.');
        }
        
        $stored_product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        if ($stored_product_id !== $product_id) {
            return new WP_Error('product_mismatch', 'This license is not valid for the specified product.');
        }

        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
             return new WP_Error('license_not_active', 'This license is not active.');
        }

        if ($check_expiry) {
            $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
            if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
                return new WP_Error('license_expired', 'This license has expired.');
            }
        }

        return $license_post;
    }
    
    /**
     * Apply WooCommerce-specific validation functions
     */
    private function apply_woocommerce_validation($license_post, $customer_email, $domain) {
        $product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        $product_type = get_post_meta($license_post->ID, '_wplm_product_type', true);
        
        // Only apply validation for WooCommerce products
        if ($product_type !== 'woocommerce') {
            return;
        }
        
        // Get validation settings
        $validation_settings = get_post_meta($product_id, '_wplm_validation_settings', true);
        if (!$validation_settings) {
            return; // No validation settings
        }
        
        // Apply email validation
        if ($validation_settings['require_email_validation']) {
            if (!is_email($customer_email)) {
                wp_send_json_error(['message' => 'Invalid email format for this WooCommerce product license.'], 400);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_wc_email_invalid', 'WooCommerce license activation failed: Invalid email format.', ['email' => $customer_email]);
                return;
            }
        }
        
        // Apply domain validation
        if ($validation_settings['require_domain_validation']) {
            $allowed_domains = $validation_settings['allowed_domains'] ?? [];
            
            if (!empty($allowed_domains)) {
                // Extract domain from URL
                $parsed_domain = parse_url($domain, PHP_URL_HOST);
                if (!$parsed_domain) {
                    $parsed_domain = $domain; // Assume it's already a domain
                }
                
                // Remove www. prefix for comparison
                $parsed_domain = preg_replace('/^www\./', '', $parsed_domain);
                
                // Check if domain is in allowed list
                $domain_allowed = false;
                foreach ($allowed_domains as $allowed_domain) {
                    $allowed_domain = preg_replace('/^www\./', '', trim($allowed_domain));
                    if ($parsed_domain === $allowed_domain) {
                        $domain_allowed = true;
                        break;
                    }
                }
                
                if (!$domain_allowed) {
                    wp_send_json_error(['message' => 'Domain not allowed for this WooCommerce product license. Allowed domains: ' . implode(', ', $allowed_domains)], 400);
                    WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed_wc_domain_not_allowed', 'WooCommerce license activation failed: Domain not allowed.', ['domain' => $domain, 'allowed_domains' => $allowed_domains]);
                    return;
                }
            }
        }
    }
    
    /**
     * Normalize domain by extracting clean domain name from URL
     * Handles http://, https://, www., and paths
     */
    private function normalize_domain($domain_input) {
        if (empty($domain_input)) {
            return '';
        }
        
        // Remove protocol (http:// or https://)
        $domain = preg_replace('#^https?://#', '', trim($domain_input));
        
        // Remove path and query parameters
        $domain = preg_replace('#/.*$#', '', $domain);
        
        // Remove www. prefix for consistent comparison
        $domain = preg_replace('/^www\./', '', $domain);
        
        // Convert to lowercase
        $domain = strtolower($domain);
        
        return $domain;
    }
}
