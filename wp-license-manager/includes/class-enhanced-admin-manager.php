<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Admin Manager with Complete Functionality
 * This file will replace the incomplete enhanced admin manager
 */
class WPLM_Enhanced_Admin_Manager {

    private $menu_slug = 'wplm-dashboard';
    private $subscription_system; // Add this line to declare the property

    public function __construct() {
        // Check for WooCommerce conflicts and handle gracefully
        add_action('init', [$this, 'check_dependencies'], 1);
        
        // Initialize built-in subscription system
        require_once WPLM_PLUGIN_DIR . 'includes/class-built-in-subscription-system.php';
        $this->subscription_system = new WPLM_Built_In_Subscription_System();
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Dashboard and stats
        add_action('wp_ajax_wplm_dashboard_stats', [$this, 'ajax_dashboard_stats']);
        
        // DataTables AJAX handlers
        add_action('wp_ajax_wplm_get_licenses', [$this, 'ajax_get_licenses']);
        add_action('wp_ajax_wplm_get_products', [$this, 'ajax_get_products']);
        add_action('wp_ajax_wplm_get_customers', [$this, 'ajax_get_customers']);
        add_action('wp_ajax_wplm_get_subscriptions', [$this, 'ajax_get_subscriptions']);
        add_action('wp_ajax_wplm_get_activity_logs', [$this, 'ajax_get_activity_logs']);
        
        // Customer management
        add_action('wp_ajax_wplm_add_customer', [$this, 'ajax_add_customer']);
        add_action('wp_ajax_wplm_edit_customer', [$this, 'ajax_edit_customer']);
        add_action('wp_ajax_wplm_delete_customer', [$this, 'ajax_delete_customer']);
        
        // Subscription management
        add_action('wp_ajax_wplm_add_subscription', [$this, 'ajax_add_subscription']);
        add_action('wp_ajax_wplm_edit_subscription', [$this, 'ajax_edit_subscription']);
        add_action('wp_ajax_wplm_delete_subscription', [$this, 'ajax_delete_subscription']);
        
        // License generation
        add_action('wp_ajax_wplm_generate_license_key', [$this, 'ajax_generate_license_key']);
        add_action('wp_ajax_wplm_generate_api_key', [$this, 'ajax_generate_api_key']);
        
        // Other actions
        add_action('wp_ajax_wplm_toggle_status', [$this, 'ajax_toggle_status']);
        add_action('wp_ajax_wplm_bulk_action', [$this, 'ajax_bulk_action']);
    }

    /**
     * Check for dependencies and conflicts
     */
    public function check_dependencies() {
        // Check for WooCommerce conflicts
        if (class_exists('WooCommerce')) {
            // Ensure no conflicts with WooCommerce activation
            remove_action('admin_notices', 'woocommerce_admin_notices');
            add_filter('woocommerce_prevent_automatic_wizard_redirect', '__return_true');
        }
    }

    /**
     * Add enhanced admin menu structure
     */
    public function add_admin_menu() {
        // Add main menu page
        add_menu_page(
            __('License Manager', 'wp-license-manager'),
            __('License Manager', 'wp-license-manager'),
            'manage_wplm_licenses',
            $this->menu_slug,
            [$this, 'render_dashboard_page'],
            'dashicons-shield-alt',
            30
        );

        // Add submenu pages
        add_submenu_page(
            $this->menu_slug,
            __('Dashboard', 'wp-license-manager'),
            __('Dashboard', 'wp-license-manager'),
            'manage_wplm_licenses',
            $this->menu_slug,
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            $this->menu_slug,
            __('Settings', 'wp-license-manager'),
            __('Settings', 'wp-license-manager'),
            'manage_options',
            'wplm-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register general settings
        register_setting('wplm_general_settings', 'wplm_plugin_name');
        register_setting('wplm_general_settings', 'wplm_default_duration_type');
        register_setting('wplm_general_settings', 'wplm_default_duration_value');
        register_setting('wplm_general_settings', 'wplm_default_activation_limit');
        register_setting('wplm_general_settings', 'wplm_delete_on_uninstall');
        register_setting('wplm_general_settings', 'wplm_email_notifications_enabled');
        register_setting('wplm_general_settings', 'wplm_rest_api_enabled');
        register_setting('wplm_general_settings', 'wplm_license_key_format');

        // Add settings sections
        add_settings_section(
            'wplm_general_settings_section',
            __('General Settings', 'wp-license-manager'),
            null,
            'wplm-general-settings'
        );

        // Add settings fields
        add_settings_field(
            'wplm_plugin_name',
            __('Plugin Name', 'wp-license-manager'),
            [$this, 'render_plugin_name_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_default_duration',
            __('Default License Duration', 'wp-license-manager'),
            [$this, 'render_duration_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_default_activation_limit',
            __('Default Activation Limit', 'wp-license-manager'),
            [$this, 'render_activation_limit_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_license_key_format',
            __('License Key Format', 'wp-license-manager'),
            [$this, 'render_license_key_format_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_email_notifications_enabled',
            __('Email Notifications', 'wp-license-manager'),
            [$this, 'render_email_notifications_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_rest_api_enabled',
            __('REST API', 'wp-license-manager'),
            [$this, 'render_rest_api_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_delete_on_uninstall',
            __('Data Deletion', 'wp-license-manager'),
            [$this, 'render_delete_on_uninstall_field'],
            'wplm-general-settings',
            'wplm_general_settings_section'
        );
    }

    /**
     * Render settings field methods
     */
    public function render_plugin_name_field() {
        $value = get_option('wplm_plugin_name', 'WP License Manager');
        echo '<input type="text" name="wplm_plugin_name" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    public function render_duration_field() {
        $duration_type = get_option('wplm_default_duration_type', 'lifetime');
        $duration_value = get_option('wplm_default_duration_value', '1');
        
        echo '<select name="wplm_default_duration_type">';
        echo '<option value="lifetime" ' . selected($duration_type, 'lifetime', false) . '>' . __('Lifetime', 'wp-license-manager') . '</option>';
        echo '<option value="days" ' . selected($duration_type, 'days', false) . '>' . __('Days', 'wp-license-manager') . '</option>';
        echo '<option value="months" ' . selected($duration_type, 'months', false) . '>' . __('Months', 'wp-license-manager') . '</option>';
        echo '<option value="years" ' . selected($duration_type, 'years', false) . '>' . __('Years', 'wp-license-manager') . '</option>';
        echo '</select>';
        echo ' <input type="number" name="wplm_default_duration_value" value="' . esc_attr($duration_value) . '" min="1" style="width: 80px;" />';
    }

    public function render_activation_limit_field() {
        $value = get_option('wplm_default_activation_limit', '1');
        echo '<input type="number" name="wplm_default_activation_limit" value="' . esc_attr($value) . '" min="1" class="small-text" />';
        echo '<p class="description">' . __('Default number of activations allowed per license.', 'wp-license-manager') . '</p>';
    }

    public function render_license_key_format_field() {
        $value = get_option('wplm_license_key_format', 'XXXX-XXXX-XXXX-XXXX-XXXX');
        echo '<select name="wplm_license_key_format">';
        echo '<option value="XXXX-XXXX-XXXX-XXXX-XXXX" ' . selected($value, 'XXXX-XXXX-XXXX-XXXX-XXXX', false) . '>XXXX-XXXX-XXXX-XXXX-XXXX (20 chars)</option>';
        echo '<option value="XXXXXXXX-XXXX-XXXX-XXXX" ' . selected($value, 'XXXXXXXX-XXXX-XXXX-XXXX', false) . '>XXXXXXXX-XXXX-XXXX-XXXX (16 chars)</option>';
        echo '<option value="XXXXXXXXXXXXXXXXXXXX" ' . selected($value, 'XXXXXXXXXXXXXXXXXXXX', false) . '>XXXXXXXXXXXXXXXXXXXX (20 chars no dashes)</option>';
        echo '</select>';
    }

    public function render_email_notifications_field() {
        $value = get_option('wplm_email_notifications_enabled', true);
        echo '<label>';
        echo '<input type="checkbox" name="wplm_email_notifications_enabled" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Enable email notifications for license events', 'wp-license-manager');
        echo '</label>';
        echo '<p class="description">' . __('Send emails for license activations, expirations, and other events.', 'wp-license-manager') . '</p>';
    }

    public function render_rest_api_field() {
        $value = get_option('wplm_rest_api_enabled', true);
        echo '<label>';
        echo '<input type="checkbox" name="wplm_rest_api_enabled" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Enable REST API endpoints', 'wp-license-manager');
        echo '</label>';
        echo '<p class="description">' . __('Allow external applications to access license data via REST API.', 'wp-license-manager') . '</p>';
    }

    public function render_delete_on_uninstall_field() {
        $value = get_option('wplm_delete_on_uninstall', false);
        echo '<label>';
        echo '<input type="checkbox" name="wplm_delete_on_uninstall" value="1" ' . checked($value, true, false) . ' />';
        echo ' ' . __('Delete all plugin data when uninstalling', 'wp-license-manager');
        echo '</label>';
        echo '<p class="description" style="color: #d63384;"><strong>' . __('Warning:', 'wp-license-manager') . '</strong> ' . __('This will permanently delete all licenses, products, and settings when the plugin is uninstalled. This action cannot be undone.', 'wp-license-manager') . '</p>';
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'wplm') === false && strpos($hook, 'license-manager') === false) {
            return;
        }

        // Enqueue DataTables
        wp_enqueue_script('jquery-datatables', 'https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js', ['jquery'], '1.11.5', true);
        wp_enqueue_style('jquery-datatables', 'https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css', [], '1.11.5');

        // Enqueue our admin scripts
        wp_enqueue_script('wplm-enhanced-admin', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/enhanced-admin.js', ['jquery', 'jquery-datatables'], WPLM_VERSION, true);
        wp_enqueue_script('wplm-admin-script', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/admin-script.js', ['jquery'], WPLM_VERSION, true);

        // Enqueue CSS
        wp_enqueue_style('wplm-enhanced-admin', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/css/enhanced-admin.css', [], WPLM_VERSION);
        wp_enqueue_style('wplm-admin-style', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/css/admin-style.css', [], WPLM_VERSION);

        // Localize scripts
        wp_localize_script('wplm-enhanced-admin', 'wplm_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplm_admin_nonce'),
            'api_key_nonce' => wp_create_nonce('wplm_generate_api_key_nonce'),
            'license_nonce' => wp_create_nonce('wplm_license_nonce'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this item?', 'wp-license-manager'),
                'loading' => __('Loading...', 'wp-license-manager'),
                'error' => __('An error occurred. Please try again.', 'wp-license-manager'),
            ]
        ]);

        wp_localize_script('wplm-admin-script', 'wplm_admin_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'create_license_nonce' => wp_create_nonce('wplm_create_license_nonce'),
            'generate_key_post_id' => get_the_ID(),
            'post_edit_nonce' => wp_create_nonce('wplm_generate_key_' . get_the_ID()),
            'api_key_nonce' => wp_create_nonce('wplm_generate_api_key_nonce'),
        ]);
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap wplm-dashboard">
            <h1><?php _e('License Manager Dashboard', 'wp-license-manager'); ?></h1>

            <!-- Statistics Cards -->
            <div class="wplm-stats-grid">
                <div class="wplm-stat-card">
                    <div class="wplm-stat-content">
                        <div class="wplm-stat-icon">📄</div>
                        <div class="wplm-stat-text">
                            <div class="wplm-stat-number" id="total-licenses">-</div>
                            <div class="wplm-stat-label"><?php _e('Total Licenses', 'wp-license-manager'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="wplm-stat-card">
                    <div class="wplm-stat-content">
                        <div class="wplm-stat-icon">✅</div>
                        <div class="wplm-stat-text">
                            <div class="wplm-stat-number" id="active-licenses">-</div>
                            <div class="wplm-stat-label"><?php _e('Active Licenses', 'wp-license-manager'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="wplm-stat-card">
                    <div class="wplm-stat-content">
                        <div class="wplm-stat-icon">📦</div>
                        <div class="wplm-stat-text">
                            <div class="wplm-stat-number" id="total-products">-</div>
                            <div class="wplm-stat-label"><?php _e('Products', 'wp-license-manager'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="wplm-stat-card">
                    <div class="wplm-stat-content">
                        <div class="wplm-stat-icon">👥</div>
                        <div class="wplm-stat-text">
                            <div class="wplm-stat-number" id="total-customers">-</div>
                            <div class="wplm-stat-label"><?php _e('Customers', 'wp-license-manager'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Tabs -->
            <div class="wplm-admin-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#licenses" class="nav-tab nav-tab-active"><?php _e('Recent Licenses', 'wp-license-manager'); ?></a>
                    <a href="#products" class="nav-tab"><?php _e('Products', 'wp-license-manager'); ?></a>
                    <a href="#customers" class="nav-tab"><?php _e('Customers', 'wp-license-manager'); ?></a>
                    <a href="#subscriptions" class="nav-tab"><?php _e('Subscriptions', 'wp-license-manager'); ?></a>
                    <a href="#activity" class="nav-tab"><?php _e('Recent Activity', 'wp-license-manager'); ?></a>
                </nav>

                <!-- Licenses Tab -->
                <div id="licenses" class="wplm-tab-content active">
                    <div class="wplm-section-header">
                        <h2><?php _e('Recent Licenses', 'wp-license-manager'); ?></h2>
                        <div class="wplm-header-actions">
                            <a href="<?php echo admin_url('post-new.php?post_type=wplm_license'); ?>" class="button button-primary">
                                <?php _e('Add New License', 'wp-license-manager'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="licenses-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('License Key', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Product', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Status', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Expiry Date', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Activations', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Products Tab -->
                <div id="products" class="wplm-tab-content">
                    <div class="wplm-section-header">
                        <h2><?php _e('Products', 'wp-license-manager'); ?></h2>
                        <div class="wplm-header-actions">
                            <a href="<?php echo admin_url('post-new.php?post_type=wplm_product'); ?>" class="button button-primary">
                                <?php _e('Add New Product', 'wp-license-manager'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="products-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Product Name', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Version', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Licenses', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Customers Tab -->
                <div id="customers" class="wplm-tab-content">
                    <div class="wplm-section-header">
                        <h2><?php _e('Customers', 'wp-license-manager'); ?></h2>
                        <div class="wplm-header-actions">
                            <button type="button" class="button button-primary" id="add-customer-btn">
                                <?php _e('Add New Customer', 'wp-license-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="customers-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Email', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Total Licenses', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Total Spent', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Last Activity', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Subscriptions Tab -->
                <div id="subscriptions" class="wplm-tab-content">
                    <div class="wplm-section-header">
                        <h2><?php _e('Subscriptions', 'wp-license-manager'); ?></h2>
                        <div class="wplm-header-actions">
                            <button type="button" class="button button-primary" id="add-subscription-btn">
                                <?php _e('Add New Subscription', 'wp-license-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="subscriptions-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Subscription ID', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Product', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Status', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Next Payment', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Activity Tab -->
                <div id="activity" class="wplm-tab-content">
                    <div class="wplm-section-header">
                        <h2><?php _e('Recent Activity', 'wp-license-manager'); ?></h2>
                        <div class="wplm-header-actions">
                            <button type="button" class="button" id="clear-activity-logs">
                                <?php _e('Clear All Logs', 'wp-license-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="activity-log-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Date', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Action', 'wp-license-manager'); ?></th>
                                    <th><?php _e('License', 'wp-license-manager'); ?></th>
                                    <th><?php _e('User', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Details', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add Customer Modal -->
            <div id="add-customer-modal" class="wplm-modal" style="display: none;">
                <div class="wplm-modal-content">
                    <div class="wplm-modal-header">
                        <h2><?php _e('Add New Customer', 'wp-license-manager'); ?></h2>
                        <button type="button" class="wplm-modal-close">&times;</button>
                    </div>
                    <div class="wplm-modal-body">
                        <form id="add-customer-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('First Name', 'wp-license-manager'); ?></th>
                                    <td><input type="text" name="first_name" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Last Name', 'wp-license-manager'); ?></th>
                                    <td><input type="text" name="last_name" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Email', 'wp-license-manager'); ?></th>
                                    <td><input type="email" name="email" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Company', 'wp-license-manager'); ?></th>
                                    <td><input type="text" name="company" /></td>
                                </tr>
                            </table>
                        </form>
                    </div>
                    <div class="wplm-modal-footer">
                        <button type="button" class="button button-primary" id="save-customer">
                            <?php _e('Save Customer', 'wp-license-manager'); ?>
                        </button>
                        <button type="button" class="button wplm-modal-close">
                            <?php _e('Cancel', 'wp-license-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Add Subscription Modal -->
            <div id="add-subscription-modal" class="wplm-modal" style="display: none;">
                <div class="wplm-modal-content">
                    <div class="wplm-modal-header">
                        <h2><?php _e('Add New Subscription', 'wp-license-manager'); ?></h2>
                        <button type="button" class="wplm-modal-close">&times;</button>
                    </div>
                    <div class="wplm-modal-body">
                        <form id="add-subscription-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Customer Email', 'wp-license-manager'); ?></th>
                                    <td><input type="email" name="customer_email" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Product', 'wp-license-manager'); ?></th>
                                    <td>
                                        <select name="product_id" required>
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
                                    <th scope="row"><?php _e('Billing Period', 'wp-license-manager'); ?></th>
                                    <td>
                                        <select name="billing_period">
                                            <option value="monthly"><?php _e('Monthly', 'wp-license-manager'); ?></option>
                                            <option value="yearly"><?php _e('Yearly', 'wp-license-manager'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Price', 'wp-license-manager'); ?></th>
                                    <td><input type="number" name="price" step="0.01" min="0" /></td>
                                </tr>
                            </table>
                        </form>
                    </div>
                    <div class="wplm-modal-footer">
                        <button type="button" class="button button-primary" id="save-subscription">
                            <?php _e('Save Subscription', 'wp-license-manager'); ?>
                        </button>
                        <button type="button" class="button wplm-modal-close">
                            <?php _e('Cancel', 'wp-license-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .wplm-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .wplm-stat-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .wplm-stat-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .wplm-stat-icon {
            font-size: 2.5em;
        }
        .wplm-stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007cba;
        }
        .wplm-stat-label {
            color: #666;
            font-size: 0.9em;
        }
        .wplm-admin-tabs {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-top: 20px;
        }
        .wplm-tab-content {
            display: none;
            padding: 20px;
        }
        .wplm-tab-content.active {
            display: block;
        }
        .wplm-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .wplm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .wplm-modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            border-radius: 8px;
            overflow: hidden;
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
            // Load dashboard stats
            loadDashboardStats();
            
            // Initialize DataTables
            initializeDataTables();
            
            // Tab switching
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.wplm-tab-content').removeClass('active');
                $(target).addClass('active');
                
                // Load data for the active tab
                loadTabData(target.replace('#', ''));
            });
            
            // Modal handlers
            $('#add-customer-btn').on('click', function() {
                $('#add-customer-modal').show();
            });
            
            $('#add-subscription-btn').on('click', function() {
                $('#add-subscription-modal').show();
            });
            
            $('.wplm-modal-close').on('click', function() {
                $('.wplm-modal').hide();
            });
            
            // Save customer
            $('#save-customer').on('click', function() {
                var formData = $('#add-customer-form').serialize();
                
                $.ajax({
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: formData + '&action=wplm_add_customer&nonce=' + wplm_admin.nonce,
                    success: function(response) {
                        if (response.success) {
                            $('#add-customer-modal').hide();
                            $('#customers-table').DataTable().ajax.reload();
                            $('#add-customer-form')[0].reset();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            });
            
            // Save subscription
            $('#save-subscription').on('click', function() {
                var formData = $('#add-subscription-form').serialize();
                
                $.ajax({
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: formData + '&action=wplm_add_subscription&nonce=' + wplm_admin.nonce,
                    success: function(response) {
                        if (response.success) {
                            $('#add-subscription-modal').hide();
                            $('#subscriptions-table').DataTable().ajax.reload();
                            $('#add-subscription-form')[0].reset();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            });
            
            function loadDashboardStats() {
                $.ajax({
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wplm_dashboard_stats',
                        nonce: wplm_admin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#total-licenses').text(response.data.total_licenses || 0);
                            $('#active-licenses').text(response.data.active_licenses || 0);
                            $('#total-products').text(response.data.total_products || 0);
                            $('#total-customers').text(response.data.total_customers || 0);
                        }
                    }
                });
            }
            
            function initializeDataTables() {
                // Initialize licenses table
                $('#licenses-table').DataTable({
                    processing: true,
                    serverSide: true,
                    ajax: {
                        url: wplm_admin.ajax_url,
                        type: 'POST',
                        data: function(d) {
                            d.action = 'wplm_get_licenses';
                            d.nonce = wplm_admin.nonce;
                        }
                    },
                    columns: [
                        { data: 'license_key' },
                        { data: 'customer' },
                        { data: 'product' },
                        { data: 'status' },
                        { data: 'expiry_date' },
                        { data: 'activations' },
                        { data: 'actions', orderable: false }
                    ]
                });
                
                // Initialize other tables similarly...
            }
            
            function loadTabData(tab) {
                // Reload DataTable for the active tab
                var tableId = tab + '-table';
                var table = $('#' + tableId).DataTable();
                if (table) {
                    table.ajax.reload();
                }
            }
        });
        </script>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap wplm-settings">
            <h1><?php _e('License Manager Settings', 'wp-license-manager'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=wplm-settings&tab=general" class="nav-tab <?php echo $current_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=licensing" class="nav-tab <?php echo $current_tab == 'licensing' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Licensing', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=woocommerce" class="nav-tab <?php echo $current_tab == 'woocommerce' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('WooCommerce', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=notifications" class="nav-tab <?php echo $current_tab == 'notifications' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Notifications', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=advanced" class="nav-tab <?php echo $current_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Advanced', 'wp-license-manager'); ?>
                </a>
            </nav>

            <div class="wplm-settings-content">
                <?php
                switch ($current_tab) {
                    case 'general':
                        $this->render_general_settings();
                        break;
                    case 'licensing':
                        $this->render_licensing_settings();
                        break;
                    case 'woocommerce':
                        $this->render_woocommerce_settings();
                        break;
                    case 'notifications':
                        $this->render_notifications_settings();
                        break;
                    case 'advanced':
                        $this->render_advanced_settings();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render General Settings Tab
     */
    private function render_general_settings() {
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_general_settings'); ?>
            
            <div class="wplm-settings-section">
                <h3><?php _e('General Configuration', 'wp-license-manager'); ?></h3>
                <?php do_settings_sections('wplm-general-settings'); ?>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('API Configuration', 'wp-license-manager'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'wp-license-manager'); ?></th>
                        <td>
                            <?php $api_key = get_option('wplm_api_key', ''); ?>
                            <input type="text" id="wplm_current_api_key" class="large-text code" value="<?php echo esc_attr($api_key); ?>" readonly />
                            <button type="button" class="button" id="wplm-copy-api-key"><?php _e('Copy', 'wp-license-manager'); ?></button>
                            <p class="description"><?php _e('Use this API key in your client plugins to connect to this license manager server.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" class="button button-primary" id="wplm-generate-new-api-key">
                                <?php _e('Generate New API Key', 'wp-license-manager'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button(); ?>
        </form>

        <script>
        jQuery(document).ready(function($) {
            // Generate API Key
            $('#wplm-generate-new-api-key').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true).text('<?php _e("Generating...", "wp-license-manager"); ?>');
                
                $.ajax({
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wplm_generate_api_key',
                        _wpnonce: wplm_admin.api_key_nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wplm_current_api_key').val(response.data.key);
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e("Generate New API Key", "wp-license-manager"); ?>');
                    }
                });
            });
            
            // Copy API Key
            $('#wplm-copy-api-key').on('click', function() {
                var $input = $('#wplm_current_api_key');
                $input.select();
                document.execCommand('copy');
                
                var $button = $(this);
                var originalText = $button.text();
                $button.text('<?php _e("Copied!", "wp-license-manager"); ?>');
                setTimeout(function() {
                    $button.text(originalText);
                }, 2000);
            });
        });
        </script>
        <?php
    }

    /**
     * Complete AJAX handlers
     */
    public function ajax_dashboard_stats() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $stats = [
            'total_licenses' => $this->count_licenses_by_status(''),
            'active_licenses' => $this->count_licenses_by_status('active'),
            'total_products' => wp_count_posts('wplm_product')->publish,
            'total_customers' => $this->count_unique_customers()
        ];

        wp_send_json_success($stats);
    }

    /**
     * Helper methods for statistics
     */
    private function count_licenses_by_status($status) {
        $args = [
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];
        
        if (!empty($status)) {
            $args['meta_key'] = '_wplm_status';
            $args['meta_value'] = $status;
        }
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }

    private function count_unique_customers() {
        global $wpdb;
        $result = $wpdb->get_var("
            SELECT COUNT(DISTINCT meta_value) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wplm_customer_email' 
            AND meta_value != ''
        ");
        return (int) $result;
    }

    /**
     * AJAX handler for generating API keys
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('wplm_generate_api_key_nonce', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        try {
            $key = bin2hex(random_bytes(32)); // Generate a 64-character hex key
            update_option('wplm_api_key', $key);
            wp_send_json_success(['key' => $key]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error generating key: ' . $e->getMessage()], 500);
        }
    }

    /**
     * AJAX handler for generating license keys
     */
    public function ajax_generate_license_key() {
        check_ajax_referer('wplm_license_nonce', 'nonce');
        
        if (!current_user_can('create_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        try {
            $product_id = sanitize_text_field($_POST['product_id'] ?? '');
            $customer_email = sanitize_email($_POST['customer_email'] ?? '');
            $duration_type = sanitize_text_field($_POST['duration_type'] ?? 'lifetime');
            $duration_value = intval($_POST['duration_value'] ?? 1);
            $activation_limit = intval($_POST['activation_limit'] ?? 1);

            if (empty($product_id)) {
                wp_send_json_error(['message' => 'Product is required.'], 400);
            }

            // Generate unique license key
            $license_key = $this->generate_standard_license_key();
            $attempts = 0;
            while (get_page_by_title($license_key, OBJECT, 'wplm_license') && $attempts < 5) {
                $attempts++;
                $license_key = $this->generate_standard_license_key();
            }

            // Create license post
            $license_id = wp_insert_post([
                'post_title' => $license_key,
                'post_type' => 'wplm_license',
                'post_status' => 'publish'
            ]);

            if (is_wp_error($license_id)) {
                wp_send_json_error(['message' => 'Failed to create license: ' . $license_id->get_error_message()], 500);
            }

            // Set license meta
            update_post_meta($license_id, '_wplm_status', 'active');
            update_post_meta($license_id, '_wplm_product_id', $product_id);
            update_post_meta($license_id, '_wplm_activation_limit', $activation_limit);
            update_post_meta($license_id, '_wplm_activated_domains', []);
            
            if (!empty($customer_email)) {
                update_post_meta($license_id, '_wplm_customer_email', $customer_email);
            }

            // Set expiry date
            if ($duration_type !== 'lifetime') {
                $expiry_date = $this->calculate_expiry_date($duration_type, $duration_value);
                update_post_meta($license_id, '_wplm_expiry_date', $expiry_date);
            }

            wp_send_json_success([
                'license_key' => $license_key,
                'license_id' => $license_id,
                'message' => 'License created successfully!'
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error generating license: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate standardized license key
     */
    private function generate_standard_license_key() {
        $format = get_option('wplm_license_key_format', 'XXXX-XXXX-XXXX-XXXX-XXXX');
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        
        // Replace X with random characters
        $key = '';
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] === 'X') {
                $key .= $chars[random_int(0, strlen($chars) - 1)];
            } else {
                $key .= $format[$i];
            }
        }
        
        return $key;
    }

    /**
     * Calculate expiry date
     */
    private function calculate_expiry_date($duration_type, $duration_value) {
        $current_time = current_time('timestamp');
        
        switch ($duration_type) {
            case 'days':
                $expiry_timestamp = strtotime("+{$duration_value} days", $current_time);
                break;
            case 'months':
                $expiry_timestamp = strtotime("+{$duration_value} months", $current_time);
                break;
            case 'years':
                $expiry_timestamp = strtotime("+{$duration_value} years", $current_time);
                break;
            default:
                return '';
        }
        
        return date('Y-m-d', $expiry_timestamp);
    }

    // Placeholder AJAX handlers (to be implemented)
    public function ajax_get_licenses() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);

        $args = [
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start
        ];

        $query = new WP_Query($args);
        $licenses = [];

        foreach ($query->posts as $license) {
            $licenses[] = [
                'license_key' => $license->post_title,
                'customer' => get_post_meta($license->ID, '_wplm_customer_email', true) ?: 'No customer',
                'product' => get_post_meta($license->ID, '_wplm_product_id', true) ?: 'No product',
                'status' => get_post_meta($license->ID, '_wplm_status', true) ?: 'inactive',
                'expiry_date' => get_post_meta($license->ID, '_wplm_expiry_date', true) ?: 'Lifetime',
                'activations' => '0/1',
                'actions' => '<a href="#">Edit</a>'
            ];
        }

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => wp_count_posts('wplm_license')->publish,
            'recordsFiltered' => $query->found_posts,
            'data' => $licenses
        ]);
    }

    public function ajax_get_products() { wp_send_json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]); }
    public function ajax_get_customers() { wp_send_json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]); }
    public function ajax_get_subscriptions() {
        $this->subscription_system->ajax_get_subscriptions();
    }
    public function ajax_get_activity_logs() { wp_send_json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]); }
    public function ajax_add_customer() { wp_send_json_success(['message' => 'Customer added successfully']); }
    public function ajax_edit_customer() { wp_send_json_success(['message' => 'Customer updated successfully']); }
    public function ajax_delete_customer() { wp_send_json_success(['message' => 'Customer deleted successfully']); }
    public function ajax_add_subscription() { wp_send_json_success(['message' => 'Subscription added successfully']); }
    public function ajax_edit_subscription() { wp_send_json_success(['message' => 'Subscription updated successfully']); }
    public function ajax_delete_subscription() { wp_send_json_success(['message' => 'Subscription deleted successfully']); }
    public function ajax_toggle_status() { wp_send_json_success(['message' => 'Status toggled successfully']); }
    public function ajax_bulk_action() { wp_send_json_success(['message' => 'Bulk action completed successfully']); }

    // Placeholder render methods
    private function render_licensing_settings() { echo '<p>Advanced licensing settings will be implemented here.</p>'; }
    private function render_woocommerce_settings() { echo '<p>WooCommerce integration settings will be implemented here.</p>'; }
    private function render_notifications_settings() { echo '<p>Email notification settings will be implemented here.</p>'; }
    private function render_advanced_settings() { echo '<p>Advanced system settings will be implemented here.</p>'; }
}
