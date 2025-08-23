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
        
        // Look for license-related code patterns
        $patterns = [
            'license_key',
            'activation_url',
            'license_server',
            'validate_license',
            'activate_license',
            'wplm_client',
            'elite_licenser',
            'software_license'
        ];

        foreach ($patterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }

        // Check for license headers
        $license_headers = [
            'License Server',
            'License Key',
            'Activation URL',
            'Product ID'
        ];

        foreach ($license_headers as $header) {
            if (isset($plugin_data[$header])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get plugin license status
     */
    private function get_plugin_license_status($plugin_file) {
        $status_option = 'wplm_client_status_' . sanitize_key($plugin_file);
        return get_option($status_option, 'unlicensed');
    }

    /**
     * Check if plugin is auto-managed
     */
    private function is_auto_managed($plugin_file) {
        $managed_plugins = get_option('wplm_auto_managed_plugins', []);
        return in_array($plugin_file, $managed_plugins);
    }

    /**
     * Render auto licenser page
     */
    public function render_auto_licenser_page() {
        $detected_plugins = get_option('wplm_detected_client_plugins', []);
        ?>
        <div class="wrap wplm-auto-licenser">
            <h1><?php _e('Automatic Licenser System', 'wp-license-manager'); ?></h1>
            <p><?php _e('Automatically manage license validation for client plugins installed on this site.', 'wp-license-manager'); ?></p>

            <div class="wplm-auto-licenser-controls">
                <button type="button" id="scan-plugins" class="button"><?php _e('Scan for Licensed Plugins', 'wp-license-manager'); ?></button>
                <button type="button" id="auto-configure-all" class="button button-primary"><?php _e('Auto Configure All', 'wp-license-manager'); ?></button>
            </div>

            <?php if (empty($detected_plugins)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No licensed plugins detected. Click "Scan for Licensed Plugins" to search for plugins that require license validation.', 'wp-license-manager'); ?></p>
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
                                            <input type="checkbox" name="auto_activate" value="1" checked />
                                            <?php _e('Automatically activate license for this domain', 'wp-license-manager'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                            
                            <input type="hidden" name="plugin_file" id="config-plugin-file" />
                        </form>
                    </div>
                    
                    <div class="wplm-modal-footer">
                        <button type="button" class="button button-primary" id="save-plugin-config">
                            <?php _e('Save Configuration', 'wp-license-manager'); ?>
                        </button>
                        <button type="button" class="button wplm-modal-close">
                            <?php _e('Cancel', 'wp-license-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .wplm-auto-licenser-controls {
            margin: 20px 0;
        }
        
        .wplm-plugins-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .wplm-plugin-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .wplm-plugin-card.auto-managed {
            border-color: #007cba;
            box-shadow: 0 2px 4px rgba(0,124,186,0.2);
        }
        
        .plugin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .plugin-header h3 {
            margin: 0;
            color: #333;
        }
        
        .plugin-status {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .status-badge, .license-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
        }
        
        .status-active { background: #28a745; color: white; }
        .status-inactive { background: #6c757d; color: white; }
        .license-licensed { background: #28a745; color: white; }
        .license-unlicensed { background: #dc3545; color: white; }
        .license-expired { background: #ffc107; color: black; }
        
        .plugin-details {
            margin-bottom: 15px;
        }
        
        .plugin-details p {
            margin: 5px 0;
            font-size: 13px;
        }
        
        .plugin-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .wplm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 100000;
        }
        
        .wplm-modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            margin: 50px auto;
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        
        .wplm-modal-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .wplm-modal-header h2 {
            margin: 0;
        }
        
        .wplm-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
        }
        
        .wplm-modal-body {
            padding: 20px;
        }
        
        .wplm-modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            text-align: right;
        }
        
        .wplm-modal-footer .button {
            margin-left: 10px;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Scan plugins
            $('#scan-plugins').on('click', function() {
                location.reload();
            });
            
            // Configure plugin
            $('.configure-plugin').on('click', function() {
                var pluginFile = $(this).data('plugin');
                var pluginCard = $(this).closest('.wplm-plugin-card');
                var pluginName = pluginCard.find('h3').text();
                
                $('#config-plugin-name').text(pluginName);
                $('#config-plugin-file').val(pluginFile);
                $('#plugin-config-modal').show();
            });
            
            // Close modal
            $('.wplm-modal-close').on('click', function() {
                $('#plugin-config-modal').hide();
            });
            
            // Save configuration
            $('#save-plugin-config').on('click', function() {
                var formData = $('#plugin-config-form').serialize();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData + '&action=wplm_update_client_settings&nonce=' + '<?php echo wp_create_nonce('wplm_auto_licenser'); ?>',
                    success: function(response) {
                        if (response.success) {
                            $('#plugin-config-modal').hide();
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
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
                        enabled: true,
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
            
            // Disable auto manage
            $('.disable-auto-manage').on('click', function() {
                var pluginFile = $(this).data('plugin');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wplm_register_client_plugin',
                        plugin_file: pluginFile,
                        enabled: false,
                        nonce: '<?php echo wp_create_nonce('wplm_auto_licenser'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle client license requests
     */
    public function handle_client_requests() {
        // Intercept API requests from client plugins
        if (isset($_REQUEST['wplm_client_request'])) {
            $this->process_client_request();
        }
    }

    /**
     * Process client plugin requests
     */
    private function process_client_request() {
        $action = sanitize_text_field($_REQUEST['action'] ?? '');
        $license_key = sanitize_text_field($_REQUEST['license_key'] ?? '');
        $product_id = sanitize_text_field($_REQUEST['product_id'] ?? '');
        $domain = sanitize_text_field($_REQUEST['domain'] ?? $_SERVER['HTTP_HOST'] ?? '');

        switch ($action) {
            case 'activate':
                $this->handle_activation_request($license_key, $product_id, $domain);
                break;
            case 'deactivate':
                $this->handle_deactivation_request($license_key, $domain);
                break;
            case 'validate':
                $this->handle_validation_request($license_key, $product_id, $domain);
                break;
            case 'info':
                $this->handle_info_request($license_key);
                break;
            default:
                wp_send_json_error(['message' => 'Invalid action']);
        }
    }

    /**
     * Handle activation requests
     */
    private function handle_activation_request($license_key, $product_id, $domain) {
        // Use the REST API manager for consistency
        if (class_exists('WPLM_REST_API_Manager')) {
            $api_manager = new WPLM_REST_API_Manager();
            $request = new WP_REST_Request('POST', '/wplm/v1/activate');
            $request->set_param('license_key', $license_key);
            $request->set_param('product_id', $product_id);
            $request->set_param('domain', $domain);
            
            $response = $api_manager->activate_license($request);
            
            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => $response->get_error_message(),
                    'code' => $response->get_error_code()
                ]);
            } else {
                wp_send_json_success($response->get_data());
            }
        } else {
            wp_send_json_error(['message' => 'License system not available']);
        }
    }

    /**
     * Handle deactivation requests
     */
    private function handle_deactivation_request($license_key, $domain) {
        if (class_exists('WPLM_REST_API_Manager')) {
            $api_manager = new WPLM_REST_API_Manager();
            $request = new WP_REST_Request('POST', '/wplm/v1/deactivate');
            $request->set_param('license_key', $license_key);
            $request->set_param('domain', $domain);
            
            $response = $api_manager->deactivate_license($request);
            
            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => $response->get_error_message(),
                    'code' => $response->get_error_code()
                ]);
            } else {
                wp_send_json_success($response->get_data());
            }
        } else {
            wp_send_json_error(['message' => 'License system not available']);
        }
    }

    /**
     * Handle validation requests
     */
    private function handle_validation_request($license_key, $product_id, $domain) {
        if (class_exists('WPLM_REST_API_Manager')) {
            $api_manager = new WPLM_REST_API_Manager();
            $request = new WP_REST_Request('POST', '/wplm/v1/validate');
            $request->set_param('license_key', $license_key);
            $request->set_param('product_id', $product_id);
            $request->set_param('domain', $domain);
            
            $response = $api_manager->validate_license($request);
            wp_send_json_success($response->get_data());
        } else {
            wp_send_json_error(['message' => 'License system not available']);
        }
    }

    /**
     * Handle info requests
     */
    private function handle_info_request($license_key) {
        if (class_exists('WPLM_REST_API_Manager')) {
            $api_manager = new WPLM_REST_API_Manager();
            $request = new WP_REST_Request('POST', '/wplm/v1/info');
            $request->set_param('license_key', $license_key);
            
            $response = $api_manager->get_license_info($request);
            
            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => $response->get_error_message(),
                    'code' => $response->get_error_code()
                ]);
            } else {
                wp_send_json_success($response->get_data());
            }
        } else {
            wp_send_json_error(['message' => 'License system not available']);
        }
    }

    /**
     * Intercept HTTP requests from client plugins
     */
    public function intercept_license_requests($preempt, $args, $url) {
        // Check if this is a license validation request to an external server
        if (strpos($url, 'license') !== false && strpos($url, home_url()) === false) {
            // Check if we manage this request
            $managed_plugins = get_option('wplm_auto_managed_plugins', []);
            
            // For now, let the request proceed normally
            // In the future, we could intercept and redirect to local validation
        }
        
        return $preempt;
    }

    /**
     * AJAX: Register/unregister client plugin
     */
    public function ajax_register_client_plugin() {
        check_ajax_referer('wplm_auto_licenser', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $plugin_file = sanitize_text_field($_POST['plugin_file']);
        $enabled = filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN);
        
        $managed_plugins = get_option('wplm_auto_managed_plugins', []);
        
        if ($enabled) {
            if (!in_array($plugin_file, $managed_plugins)) {
                $managed_plugins[] = $plugin_file;
            }
        } else {
            $managed_plugins = array_diff($managed_plugins, [$plugin_file]);
        }
        
        update_option('wplm_auto_managed_plugins', $managed_plugins);
        
        wp_send_json_success(['message' => $enabled ? 'Auto management enabled.' : 'Auto management disabled.']);
    }

    /**
     * AJAX: Update client plugin settings
     */
    public function ajax_update_client_settings() {
        check_ajax_referer('wplm_auto_licenser', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $plugin_file = sanitize_text_field($_POST['plugin_file']);
        $product_id = sanitize_text_field($_POST['product_id']);
        $license_key = sanitize_text_field($_POST['license_key']);
        $server_url = esc_url_raw($_POST['server_url']);
        $auto_activate = filter_var($_POST['auto_activate'], FILTER_VALIDATE_BOOLEAN);

        // Save configuration
        $config_option = 'wplm_client_config_' . sanitize_key($plugin_file);
        update_option($config_option, [
            'product_id' => $product_id,
            'license_key' => $license_key,
            'server_url' => $server_url,
            'auto_activate' => $auto_activate,
            'configured_at' => current_time('mysql')
        ]);

        // If auto-activate is enabled and license key is provided, activate it
        if ($auto_activate && !empty($license_key) && !empty($product_id)) {
            $domain = $_SERVER['HTTP_HOST'] ?? home_url();
            $this->handle_activation_request($license_key, $product_id, $domain);
        }

        wp_send_json_success(['message' => 'Configuration saved successfully.']);
    }

    /**
     * Perform periodic license checks for managed plugins
     */
    public function perform_client_license_checks() {
        $managed_plugins = get_option('wplm_auto_managed_plugins', []);
        
        foreach ($managed_plugins as $plugin_file) {
            $config_option = 'wplm_client_config_' . sanitize_key($plugin_file);
            $config = get_option($config_option, []);
            
            if (!empty($config['license_key']) && !empty($config['product_id'])) {
                // Validate license status
                $domain = $_SERVER['HTTP_HOST'] ?? home_url();
                // You could add validation logic here
            }
        }
    }

    /**
     * Handle plugin activation
     */
    public function on_plugin_activated($plugin, $network_wide) {
        // Check if this is a managed plugin and auto-configure if needed
        $managed_plugins = get_option('wplm_auto_managed_plugins', []);
        
        if (in_array($plugin, $managed_plugins)) {
            // Auto-configure the plugin
            $this->auto_configure_plugin($plugin);
        }
    }

    /**
     * Handle plugin deactivation
     */
    public function on_plugin_deactivated($plugin) {
        // Handle deactivation of managed plugins
        $config_option = 'wplm_client_config_' . sanitize_key($plugin);
        $config = get_option($config_option, []);
        
        if (!empty($config['license_key']) && $config['auto_activate']) {
            // Optionally deactivate the license
            $domain = $_SERVER['HTTP_HOST'] ?? home_url();
            // You could add deactivation logic here
        }
    }

    /**
     * Auto-configure a plugin
     */
    private function auto_configure_plugin($plugin_file) {
        $config_option = 'wplm_client_config_' . sanitize_key($plugin_file);
        $config = get_option($config_option, []);
        
        if (empty($config) || empty($config['license_key'])) {
            return;
        }

        // Here you would typically modify the plugin's configuration files
        // or database options to set up the license information
        
        // This is a simplified example - actual implementation would depend
        // on how the client plugin stores its license information
    }
}
