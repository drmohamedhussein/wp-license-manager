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
    }

    /**
     * Register public AJAX hooks.
     * These are prefixed with 'wplm_' to avoid conflicts.
     */
    private function add_ajax_hooks(): void {
        $actions = ['validate', 'activate', 'deactivate', 'info', 'update_check'];
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

        if (empty($api_key) || empty($this->stored_api_key) || !hash_equals($this->stored_api_key, $api_key)) {
            wp_send_json_error(['message' => 'API key is invalid or missing.'], 403);
        }
    }

    /**
     * Handles plugin/theme update check requests.
     */
    public function ajax_update_check(): void {
        $this->check_api_key();

        $product_slug = sanitize_text_field($_POST['product_slug'] ?? '');
        $client_version = sanitize_text_field($_POST['version'] ?? ''); // Version sent by client

        if (empty($product_slug)) {
            wp_send_json_error(['message' => esc_html__('Product slug is required.', 'wplm')], 400);
            WPLM_Activity_Logger::log(0, 'update_check_failed', esc_html__('Update check failed: Missing product slug.', 'wplm'), ['product_slug' => $product_slug]);
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
            wp_send_json_error(['message' => esc_html__('Product not found.', 'wplm')], 404);
            WPLM_Activity_Logger::log(0, 'update_check_failed', esc_html__('Update check failed: Product not found.', 'wplm'), ['product_slug' => $product_slug]);
            return; // Add return to prevent further execution after error
        }
        $product_post = $product_posts[0];

        $latest_version = get_post_meta($product_post->ID, '_wplm_current_version', true);
        $download_url = get_post_meta($product_post->ID, '_wplm_download_url', true);

        if (empty($latest_version) || empty($download_url)) {
            wp_send_json_error(['message' => esc_html__('Product is missing version or download URL information.', 'wplm')], 404);
            WPLM_Activity_Logger::log($product_post->ID, 'update_check_failed', esc_html__('Update check failed: Missing version or download URL for product.', 'wplm'), ['product_slug' => $product_slug]);
            return; // Add return to prevent further execution after error
        }

        // Compare client version with latest version
        if (!empty($client_version) && version_compare($latest_version, $client_version, '<=')) {
            wp_send_json_success(['message' => esc_html__('No update available.', 'wplm'), 'latest_version' => $latest_version]);
            WPLM_Activity_Logger::log($product_post->ID, 'update_check_no_update', sprintf(esc_html__('No update available for %1$s. Client version: %2$s, Latest version: %3$s', 'wplm'), $product_slug, $client_version, $latest_version), ['client_version' => $client_version, 'latest_version' => $latest_version]);
        } else {
            wp_send_json_success([
                'message' => esc_html__('Update available.', 'wplm'),
                'latest_version' => $latest_version,
                'package' => esc_url_raw($download_url),
            ]);
            WPLM_Activity_Logger::log($product_post->ID, 'update_check_available', sprintf(esc_html__('Update available for %1$s. Client version: %2$s, Latest version: %3$s', 'wplm'), $product_slug, $client_version, $latest_version), ['client_version' => $client_version, 'latest_version' => $latest_version, 'package' => esc_url_raw($download_url)]);
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
            WPLM_Activity_Logger::log(0, 'license_validation_failed', sprintf(esc_html__('License validation failed: %s', 'wplm'), $license_post->get_error_message()), ['license_key' => sanitize_text_field($_POST['license_key'] ?? ''), 'product_id' => sanitize_text_field($_POST['product_id'] ?? '')]);
            return; // Exit after sending error
        }
        wp_send_json_success(['message' => esc_html__('License is valid.', 'wplm')]);
        WPLM_Activity_Logger::log($license_post->ID, 'license_validated', sprintf(esc_html__('License %s validated successfully.', 'wplm'), $license_post->post_title), ['license_key' => sanitize_text_field($_POST['license_key'] ?? ''), 'product_id' => sanitize_text_field($_POST['product_id'] ?? '')]);
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

        $domain = sanitize_text_field($_POST['domain']);
        if (empty($domain) || !filter_var($domain, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => esc_html__('Invalid or missing domain.', 'wplm')], 400);
            WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed', esc_html__('License activation failed: Invalid or missing domain.', 'wplm'), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
            return; // Exit after sending error
        }

        $activation_limit = (int) get_post_meta($license_post->ID, '_wplm_activation_limit', true) ?: 1;
        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];

        if (!in_array($domain, $activated_domains)) {
            if (count($activated_domains) >= $activation_limit) {
                wp_send_json_error(['message' => esc_html__('This license has reached its activation limit.', 'wplm')], 403);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_limit_reached', sprintf(esc_html__('License activation failed for %1$s: Activation limit reached.', 'wplm'), $domain), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
                return; // Exit after sending error
            }
            $activated_domains[] = $domain; // Add the domain to the array
            if (false === update_post_meta($license_post->ID, '_wplm_activated_domains', $activated_domains)) { // Now attempt to update
                wp_send_json_error(['message' => esc_html__('Failed to update activated domains.', 'wplm')], 500);
                WPLM_Activity_Logger::log($license_post->ID, 'license_activation_failed', sprintf(esc_html__('License activation failed for %1$s: Failed to update activated domains.', 'wplm'), $domain), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
                return;
            }
            WPLM_Activity_Logger::log($license_post->ID, 'license_activated', sprintf(esc_html__('License %1$s activated on domain: %2$s', 'wplm'), $license_post->post_title, $domain), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
        } else {
            WPLM_Activity_Logger::log($license_post->ID, 'license_already_active', sprintf(esc_html__('License %1$s already active on domain: %2$s', 'wplm'), $license_post->post_title, $domain), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
        }

        $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
        wp_send_json_success([
            'message' => esc_html__('License activated successfully.', 'wplm'),
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
            WPLM_Activity_Logger::log(0, 'license_deactivation_failed', 'License deactivation failed: ' . $license_post->get_error_message(), $_POST);
            return; // Exit after sending error
        }

        $domain = sanitize_text_field($_POST['domain']);
        if (empty($domain) || !filter_var($domain, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => esc_html__('Invalid or missing domain.', 'wplm')], 400);
            WPLM_Activity_Logger::log($license_post->ID, 'license_deactivation_failed', esc_html__('License deactivation failed: Invalid or missing domain.', 'wplm'), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
            return; // Exit after sending error
        }

        $activated_domains = get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: [];

        if (in_array($domain, $activated_domains)) {
            $updated_domains = array_diff($activated_domains, [$domain]);
            if (false === update_post_meta($license_post->ID, '_wplm_activated_domains', array_values($updated_domains))) {
                wp_send_json_error(['message' => esc_html__('Failed to update activated domains.', 'wplm')], 500);
                WPLM_Activity_Logger::log($license_post->ID, 'license_deactivation_failed', sprintf(esc_html__('License deactivation failed for %1$s: Failed to update activated domains.', 'wplm'), $domain), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
                return;
            }
            WPLM_Activity_Logger::log($license_post->ID, 'license_deactivated', sprintf(esc_html__('License %1$s deactivated on domain: %2$s', 'wplm'), $license_post->post_title, $domain), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
        } else {
            wp_send_json_error(['message' => esc_html__('Domain not found for this license.', 'wplm')], 400);
            WPLM_Activity_Logger::log($license_post->ID, 'license_deactivation_failed', sprintf(esc_html__('License deactivation failed: Domain %1$s not found for license %2$s.', 'wplm'), $domain, $license_post->post_title), ['license_key' => $license_post->post_title, 'product_id' => sanitize_text_field($_POST['product_id'] ?? ''), 'domain' => $domain]);
            return; // Exit after sending error
        }

        wp_send_json_success(['message' => esc_html__('License deactivated successfully.', 'wplm')]);
    }

    /**
     * Handles license info requests for updates
     */
    public function ajax_info(): void {
        $this->check_api_key();
        $license_post = $this->_get_license_from_request($_POST);
        if (is_wp_error($license_post)) {
            wp_send_json_error(['message' => $license_post->get_error_message()], 400);
            WPLM_Activity_Logger::log(0, 'license_info_failed', sprintf(esc_html__('License info retrieval failed: %s', 'wplm'), $license_post->get_error_message()), ['license_key' => sanitize_text_field($_POST['license_key'] ?? ''), 'product_id' => sanitize_text_field($_POST['product_id'] ?? '')]);
            return;
        }

        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        if (empty($product_id)) {
            wp_send_json_error(['message' => esc_html__('Product ID is required.', 'wplm')], 400);
            WPLM_Activity_Logger::log($license_post->ID, 'license_info_failed', esc_html__('License info retrieval failed: Missing product ID.', 'wplm'), ['license_key' => $license_post->post_title]);
            return;
        }

        $product_posts = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $product_id,
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ]);

        if (empty($product_posts)) {
            wp_send_json_error(['message' => esc_html__('Product not found.', 'wplm')], 404);
            WPLM_Activity_Logger::log($license_post->ID, 'license_info_failed', esc_html__('License info retrieval failed: Product not found.', 'wplm'), ['license_key' => $license_post->post_title, 'product_id' => $product_id]);
            return;
        }

        $product_post = $product_posts[0];
        $version = get_post_meta($product_post->ID, '_wplm_current_version', true);
        $download_url = get_post_meta($product_post->ID, '_wplm_download_url', true);

        wp_send_json_success([
            'message' => esc_html__('License info retrieved.', 'wplm'),
            'version' => $version ?: '1.0.0',
            'package' => esc_url_raw($download_url) ?: '',
        ]);
        WPLM_Activity_Logger::log($license_post->ID, 'license_info_retrieved', sprintf(esc_html__('License %1$s info retrieved for product %2$s.', 'wplm'), $license_post->post_title, $product_id), ['license_key' => $license_post->post_title, 'product_id' => $product_id]);
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
            return new WP_Error('missing_params', esc_html__('License key and product ID are required.', 'wplm'));
        }

        $license_posts = get_posts([
            'post_type' => 'wplm_license', 'title' => $license_key, 'post_status' => 'publish', 'posts_per_page' => 1,
        ]);

        if (empty($license_posts)) {
            return new WP_Error('invalid_license', esc_html__('License key not found.', 'wplm'));
        }

        $license_post = $license_posts[0];
        
        $stored_product_id = get_post_meta($license_post->ID, '_wplm_product_id', true);
        if ($stored_product_id !== $product_id) {
            return new WP_Error('product_mismatch', esc_html__('This license is not valid for the specified product.', 'wplm'));
        }

        $status = get_post_meta($license_post->ID, '_wplm_status', true);
        if ($status !== 'active') {
             return new WP_Error('license_not_active', esc_html__('This license is not active.', 'wplm'));
        }

        if ($check_expiry) {
            $expiry_date = get_post_meta($license_post->ID, '_wplm_expiry_date', true);
            if (!empty($expiry_date) && strtotime($expiry_date) < time()) {
                return new WP_Error('license_expired', esc_html__('This license has expired.', 'wplm'));
            }
        }

        return $license_post;
    }
}
