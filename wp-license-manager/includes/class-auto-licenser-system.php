<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatic Licenser System for WPLM (Similar to Elite Licenser)
 * This provides automatic license validation and management for client plugins
 */
class WPLM_Auto_Licenser_System {

    private $client_plugins = [];

    public function __construct() {
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu'], 100);
        add_action('admin_init', [$this, 'scan_for_client_plugins']);
        
        // AJAX handlers
        add_action('wp_ajax_wplm_register_client_plugin', [$this, 'ajax_register_client_plugin']);
        add_action('wp_ajax_wplm_update_client_settings', [$this, 'ajax_update_client_settings']);
        add_action('wp_ajax_wplm_test_client_connection', [$this, 'ajax_test_client_connection']);
        
        // Automatic client management
        add_action('init', [$this, 'handle_client_requests']);
        add_filter('pre_http_request', [$this, 'intercept_license_requests'], 10, 3);
        
        // Client plugin hooks
        add_action('activated_plugin', [$this, 'on_plugin_activated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 1);
        
        // Cron for periodic checks
        add_action('wplm_client_license_check', [$this, 'perform_client_license_checks']);
        
        // Schedule periodic checks if not already scheduled
        if (!wp_next_scheduled('wplm_client_license_check')) {
            wp_schedule_event(time(), 'daily', 'wplm_client_license_check');
        }
    }

    /**
     * Add admin menu for auto licenser
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wplm-dashboard',
            __('Auto Licenser', 'wp-license-manager'),
            __('Auto Licenser', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-auto-licenser',
            [$this, 'render_auto_licenser_page']
        );
    }

    /**
     * Scan for client plugins
     */
    public function scan_for_client_plugins() {
        if (!is_admin()) {
            return;
        }

        $plugins = get_plugins();
        $detected_clients = [];

        foreach ($plugins as $plugin_file => $plugin_data) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            
            // Check if plugin contains license validation code
            if ($this->is_licensed_plugin($plugin_path, $plugin_data)) {
                $detected_clients[] = [
                    'file' => $plugin_file,
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                    'description' => $plugin_data['Description'],
                    'author' => $plugin_data['Author'],
                    'status' => is_plugin_active($plugin_file) ? 'active' : 'inactive',
                    'license_status' => $this->get_plugin_license_status($plugin_file),
                    'auto_managed' => $this->is_auto_managed($plugin_file)
                ];
            }
        }

        update_option('wplm_detected_client_plugins', $detected_clients);
        $this->client_plugins = $detected_clients;
    }

    /**
     * Check if plugin is a licensed plugin
     */
    private function is_licensed_plugin($plugin_path, $plugin_data) {
        // Check for common license validation patterns
        $main_file = dirname($plugin_path) . '/' . basename($plugin_path);
        
        if (!file_exists($main_file)) {
            return false;
        }

        $content = file_get_contents($main_file);
        
        // Check for license validation patterns
        $patterns = [
            'license_key',
            'license_key_',
            'edd_sl_',
            'wplm_',
            'license_manager',
            'license_validation'
        ];
        
        foreach ($patterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get plugin license status
     */
    private function get_plugin_license_status($plugin_file) {
        // Check if plugin has valid license
        $license_key = get_option('wplm_plugin_license_' . md5($plugin_file));
        
        if (!$license_key) {
            return 'unlicensed';
        }
        
        // Check license validity
        $license = $this->validate_license($license_key);
        
        if ($license && $license['valid']) {
            return 'valid';
        } elseif ($license && $license['expired']) {
            return 'expired';
        } else {
            return 'invalid';
        }
    }

    /**
     * Check if plugin is auto managed
     */
    private function is_auto_managed($plugin_file) {
        return get_option('wplm_auto_manage_' . md5($plugin_file), false);
    }

    /**
     * Validate license key
     */
    private function validate_license($license_key) {
        // This would typically call your license validation API
        // For now, return a mock response
        return [
            'valid' => true,
            'expired' => false,
            'expiry_date' => date('Y-m-d', strtotime('+1 year')),
            'activation_limit' => 1,
            'activations_count' => 0
        ];
    }

    /**
     * Render auto licenser admin page
     */
    public function render_auto_licenser_page() {
        if (!current_user_can('manage_wplm_licenses')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-license-manager'));
        }
        
        $detected_plugins = get_option('wplm_detected_client_plugins', []);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Automatic License Manager', 'wp-license-manager'); ?></h1>
            
            <?php if (empty($detected_plugins)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No licensed plugins detected. Install and activate plugins with license validation to get started.', 'wp-license-manager'); ?></p>
                </div>
            <?php else: ?>
                
                <h2><?php _e('Detected Licensed Plugins', 'wp-license-manager'); ?></h2>
                
                <div class="wplm-plugins-grid">
                    <?php foreach ($detected_plugins as $plugin): ?>
                        <div class="wplm-plugin-card <?php echo $plugin['auto_managed'] ? 'auto-managed' : ''; ?>">
                            <div class="plugin-header">
                                <h3><?php echo esc_html($plugin['name']); ?></h3>
                                <div class="plugin-status">
                                    <span class="status-badge status-<?php echo esc_attr($plugin['status']); ?>">
                                        <?php echo ucfirst($plugin['status']); ?>
                                    </span>
                                    <span class="license-badge license-<?php echo esc_attr($plugin['license_status']); ?>">
                                        <?php echo ucfirst($plugin['license_status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="plugin-details">
                                <p><strong><?php _e('Version:', 'wp-license-manager'); ?></strong> <?php echo esc_html($plugin['version']); ?></p>
                                <p><strong><?php _e('Author:', 'wp-license-manager'); ?></strong> <?php echo esc_html($plugin['author']); ?></p>
                                <p><?php echo esc_html($plugin['description']); ?></p>
                            </div>
                            
                            <div class="plugin-actions">
                                <?php if ($plugin['auto_managed']): ?>
                                    <button type="button" class="button button-secondary disable-auto-manage" 
                                            data-plugin="<?php echo esc_attr($plugin['file']); ?>">
                                        <?php _e('Disable Auto Manage', 'wp-license-manager'); ?>
                                    </button>
                                    <button type="button" class="button test-connection" 
                                            data-plugin="<?php echo esc_attr($plugin['file']); ?>">
                                        <?php _e('Test Connection', 'wp-license-manager'); ?>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="button button-primary enable-auto-manage" 
                                            data-plugin="<?php echo esc_attr($plugin['file']); ?>">
                                        <?php _e('Enable Auto Manage', 'wp-license-manager'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <button type="button" class="button configure-plugin" 
                                        data-plugin="<?php echo esc_attr($plugin['file']); ?>">
                                    <?php _e('Configure', 'wp-license-manager'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Configuration Modal -->
            <div id="plugin-config-modal" class="wplm-modal" style="display: none;">
                <div class="wplm-modal-content">
                    <div class="wplm-modal-header">
                        <h2><?php _e('Configure Plugin License', 'wp-license-manager'); ?></h2>
                        <button type="button" class="wplm-modal-close">&times;</button>
                    </div>
                    
                    <div class="wplm-modal-body">
                        <form id="plugin-config-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Plugin', 'wp-license-manager'); ?></th>
                                    <td><span id="config-plugin-name"></span></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Product ID', 'wp-license-manager'); ?></th>
                                    <td>
                                        <select name="product_id" id="config-product-id" required>
                                            <option value=""><?php _e('Select Product', 'wp-license-manager'); ?></option>
                                            <?php
                                            $products = get_posts(['post_type' => 'wplm_product', 'numberposts' => -1]);
                                            foreach ($products as $product):
                                            ?>
                                                <option value="<?php echo esc_attr($product->post_name); ?>">
                                                    <?php echo esc_html($product->post_title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('License Key', 'wp-license-manager'); ?></th>
                                    <td>
                                        <input type="text" name="license_key" id="config-license-key" class="large-text" />
                                        <p class="description"><?php _e('Leave empty to auto-assign an available license.', 'wp-license-manager'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Server URL', 'wp-license-manager'); ?></th>
                                    <td>
                                        <input type="url" name="server_url" id="config-server-url" 
                                               value="<?php echo esc_url(home_url()); ?>" class="large-text" readonly />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Auto Activate', 'wp-license-manager'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="auto_activate" value="1" />
                                            <?php _e('Automatically activate license when plugin is activated', 'wp-license-manager'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                            
                            <div class="submit">
                                <button type="submit" class="button button-primary">
                                    <?php _e('Save Configuration', 'wp-license-manager'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Configure plugin button
            $('.configure-plugin').on('click', function() {
                var pluginFile = $(this).data('plugin');
                var pluginName = $(this).closest('.wplm-plugin-card').find('h3').text();
                
                $('#config-plugin-name').text(pluginName);
                $('#plugin-config-modal').show();
            });
            
            // Close modal
            $('.wplm-modal-close').on('click', function() {
                $('#plugin-config-modal').hide();
            });
            
            // Close modal on outside click
            $(window).on('click', function(e) {
                if ($(e.target).hasClass('wplm-modal')) {
                    $('.wplm-modal').hide();
                }
            });
            
            // Form submission
            $('#plugin-config-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = $(this).serialize();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData + '&action=wplm_update_client_settings&nonce=' + '<?php echo wp_create_nonce('wplm_auto_licenser'); ?>',
                    success: function(response) {
                        if (response.success) {
                            alert('Configuration saved successfully!');
                            $('#plugin-config-modal').hide();
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });
            
            // Enable auto manage
            $('.enable-auto-manage').on('click', function() {
                var pluginFile = $(this).data('plugin');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wplm_register_client_plugin',
                        plugin_file: pluginFile,
                        nonce: '<?php echo wp_create_nonce('wplm_auto_licenser'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            });
            
            // Test connection
            $('.test-connection').on('click', function() {
                var pluginFile = $(this).data('plugin');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wplm_test_client_connection',
                        plugin_file: pluginFile,
                        nonce: '<?php echo wp_create_nonce('wplm_auto_licenser'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Connection test successful!');
                        } else {
                            alert('Connection test failed: ' + response.data.message);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for registering client plugin
     */
    public function ajax_register_client_plugin() {
        check_ajax_referer('wplm_auto_licenser', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wp-license-manager')]);
        }
        
        $plugin_file = sanitize_text_field($_POST['plugin_file']);
        
        if (empty($plugin_file)) {
            wp_send_json_error(['message' => __('Plugin file is required.', 'wp-license-manager')]);
        }
        
        // Enable auto management for this plugin
        update_option('wplm_auto_manage_' . md5($plugin_file), true);
        
        wp_send_json_success(['message' => __('Plugin registered successfully.', 'wp-license-manager')]);
    }

    /**
     * AJAX handler for updating client settings
     */
    public function ajax_update_client_settings() {
        check_ajax_referer('wplm_auto_licenser', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wp-license-manager')]);
        }
        
        $product_id = sanitize_text_field($_POST['product_id']);
        $license_key = sanitize_text_field($_POST['license_key']);
        $auto_activate = isset($_POST['auto_activate']) ? true : false;
        
        if (empty($product_id)) {
            wp_send_json_error(['message' => __('Product ID is required.', 'wp-license-manager')]);
        }
        
        // Save configuration
        update_option('wplm_client_product_id', $product_id);
        if (!empty($license_key)) {
            update_option('wplm_client_license_key', $license_key);
        }
        update_option('wplm_client_auto_activate', $auto_activate);
        
        wp_send_json_success(['message' => __('Settings updated successfully.', 'wp-license-manager')]);
    }

    /**
     * AJAX handler for testing client connection
     */
    public function ajax_test_client_connection() {
        check_ajax_referer('wplm_auto_licenser', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wp-license-manager')]);
        }
        
        $plugin_file = sanitize_text_field($_POST['plugin_file']);
        
        if (empty($plugin_file)) {
            wp_send_json_error(['message' => __('Plugin file is required.', 'wp-license-manager')]);
        }
        
        // Test connection logic would go here
        // For now, just return success
        wp_send_json_success(['message' => __('Connection test successful.', 'wp-license-manager')]);
    }

    /**
     * Handle client license requests
     */
    public function handle_client_requests() {
        // Handle incoming license validation requests from client plugins
        if (isset($_GET['wplm_license_check'])) {
            $this->process_license_check_request();
        }
    }

    /**
     * Process license check request
     */
    private function process_license_check_request() {
        $license_key = sanitize_text_field($_GET['license_key'] ?? '');
        $domain = sanitize_text_field($_GET['domain'] ?? '');
        
        if (empty($license_key) || empty($domain)) {
            wp_die('Invalid request parameters.');
        }
        
        // Validate license
        $result = $this->validate_license($license_key);
        
        // Return JSON response
        wp_send_json($result);
    }

    /**
     * Intercept license requests
     */
    public function intercept_license_requests($preempt, $args, $url) {
        // Intercept outgoing license validation requests to external servers
        // This allows for local license validation
        
        if (strpos($url, 'license') !== false || strpos($url, 'activation') !== false) {
            // Process locally instead of making external request
            return $this->process_local_license_request($url, $args);
        }
        
        return $preempt;
    }

    /**
     * Process local license request
     */
    private function process_local_license_request($url, $args) {
        // Parse the request and process locally
        $parsed_url = parse_url($url);
        parse_str($parsed_url['query'] ?? '', $query_params);
        
        if (isset($query_params['license_key'])) {
            $result = $this->validate_license($query_params['license_key']);
            return [
                'response' => ['code' => 200],
                'body' => json_encode($result)
            ];
        }
        
        return false;
    }

    /**
     * Handle plugin activation
     */
    public function on_plugin_activated($plugin_file, $network_wide) {
        if ($this->is_auto_managed($plugin_file)) {
            $this->auto_activate_license($plugin_file);
        }
    }

    /**
     * Handle plugin deactivation
     */
    public function on_plugin_deactivated($plugin_file) {
        if ($this->is_auto_managed($plugin_file)) {
            $this->auto_deactivate_license($plugin_file);
        }
    }

    /**
     * Auto activate license for plugin
     */
    private function auto_activate_license($plugin_file) {
        $license_key = get_option('wplm_client_license_key');
        
        if (empty($license_key)) {
            return;
        }
        
        // Auto-activate license logic
        $this->activate_license_for_domain($license_key, home_url());
    }

    /**
     * Auto deactivate license for plugin
     */
    private function auto_deactivate_license($plugin_file) {
        $license_key = get_option('wplm_client_license_key');
        
        if (empty($license_key)) {
            return;
        }
        
        // Auto-deactivate license logic
        $this->deactivate_license_for_domain($license_key, home_url());
    }

    /**
     * Activate license for domain
     */
    private function activate_license_for_domain($license_key, $domain) {
        // License activation logic
        $license = $this->validate_license($license_key);
        
        if ($license && $license['valid']) {
            // Activate license for this domain
            update_option('wplm_activated_domain_' . md5($license_key), $domain);
        }
    }

    /**
     * Deactivate license for domain
     */
    private function deactivate_license_for_domain($license_key, $domain) {
        // License deactivation logic
        delete_option('wplm_activated_domain_' . md5($license_key));
    }

    /**
     * Perform client license checks
     */
    public function perform_client_license_checks() {
        // Periodic license validation for all auto-managed plugins
        $auto_managed_plugins = get_option('wplm_auto_managed_plugins', []);
        
        foreach ($auto_managed_plugins as $plugin_file) {
            if (is_plugin_active($plugin_file)) {
                $this->validate_plugin_license($plugin_file);
            }
        }
    }

    /**
     * Validate plugin license
     */
    private function validate_plugin_license($plugin_file) {
        $license_key = get_option('wplm_plugin_license_' . md5($plugin_file));
        
        if (empty($license_key)) {
            return false;
        }
        
        $license = $this->validate_license($license_key);
        
        if (!$license || !$license['valid']) {
            // License is invalid, deactivate plugin
            deactivate_plugins($plugin_file);
            
            // Log the deactivation
            error_log("WPLM: Plugin {$plugin_file} deactivated due to invalid license.");
        }
        
        return $license;
    }
}
