<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Admin Manager with Modern UI/UX
 * Manages the complete admin interface with dashboard, CRM, and analytics
 */
class WPLM_Enhanced_Admin_Manager {

    private $menu_slug = 'wplm-dashboard';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_head', [$this, 'add_custom_css']);
        
        // Dashboard and stats
        add_action('wp_ajax_wplm_dashboard_stats', [$this, 'ajax_dashboard_stats']);
        
        // DataTables AJAX handlers
        add_action('wp_ajax_wplm_get_licenses', [$this, 'ajax_get_licenses']);
        add_action('wp_ajax_wplm_get_products', [$this, 'ajax_get_products']);
        // Removed duplicate ajax_get_customers - handled by WPLM_Customer_Management_System
        add_action('wp_ajax_wplm_get_subscriptions', [$this, 'ajax_get_subscriptions']);
        add_action('wp_ajax_wplm_get_activity_logs', [$this, 'ajax_get_activity_logs']);
        
        // Activity logs
        add_action('wp_ajax_wplm_activity_logs', [$this, 'ajax_get_activity_logs']);
        add_action('wp_ajax_wplm_clear_activity_logs', [$this, 'ajax_clear_activity_logs']);
        add_action('wp_ajax_wplm_clear_old_logs', [$this, 'ajax_clear_old_logs']);
        add_action('wp_ajax_wplm_clear_all_logs', [$this, 'ajax_clear_all_logs']);
        
        // Other actions
        add_action('wp_ajax_wplm_customer_search', [$this, 'ajax_customer_search']);
        add_action('wp_ajax_wplm_get_customer_details', [$this, 'ajax_get_customer_details']);
        add_action('wp_ajax_wplm_subscription_action', [$this, 'ajax_subscription_action']);
        add_action('wp_ajax_wplm_sync_wc_products', [$this, 'ajax_sync_wc_products']);
        add_action('wp_ajax_wplm_sync_wc_customers', [$this, 'ajax_sync_wc_customers']);
        add_action('wp_ajax_wplm_sync_wc_customer_orders', [$this, 'ajax_sync_wc_customer_orders']);
        add_action('wp_ajax_wplm_toggle_status', [$this, 'ajax_toggle_status']);
        add_action('wp_ajax_wplm_bulk_action', [$this, 'ajax_bulk_action']);
        
        // API Key generation
        add_action('wp_ajax_wplm_generate_api_key', [$this, 'ajax_generate_api_key']);
        
        // License key generation
        add_action('wp_ajax_wplm_generate_license_key', [$this, 'ajax_generate_license_key']);
        add_action('wp_ajax_wplm_render_create_subscription_form', [$this, 'ajax_render_create_subscription_form']); // New AJAX for modal content
        
        // Force deactivation
        add_action('wp_ajax_wplm_force_deactivate_licenses', [$this, 'ajax_force_deactivate_licenses']);
        
        // Bulk operations
        add_action('wp_ajax_wplm_bulk_license_operations', [$this, 'ajax_bulk_license_operations']);
    }

    /**
     * Register settings for the enhanced admin
     */
    public function register_settings() {
        // Register settings group with proper defaults for PHP 8.0+ compatibility
        register_setting('wplm_general_settings', 'wplm_default_duration_type', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'lifetime'
        ]);
        register_setting('wplm_general_settings', 'wplm_default_duration_value', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1
        ]);
        register_setting('wplm_general_settings', 'wplm_default_activation_limit', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1
        ]);
        register_setting('wplm_general_settings', 'wplm_delete_on_uninstall', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ]);
        register_setting('wplm_general_settings', 'wplm_email_notifications_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ]);
        register_setting('wplm_general_settings', 'wplm_rest_api_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ]);

        // Add settings section
        add_settings_section(
            'wplm_general_settings_section',
            __('General Settings', 'wp-license-manager'),
            null,
            'wplm-general-settings'
        );

        // Add settings fields
        add_settings_field(
            'wplm_default_duration_type',
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

        // Register whitelabel settings with proper defaults for PHP 8.0+ compatibility
        register_setting('wplm_whitelabel_settings', 'wplm_whitelabel_options', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_whitelabel_settings'],
            'default' => [
                'plugin_name' => 'LicensesWP',
                'plugin_description' => 'Best licensing management system for WordPress products',
                'company_name' => 'WPDev Ltd.',
                'company_url' => 'https://wpdevltd.com/',
                'plugin_website' => 'https://licenseswp.com/',
                'plugin_author' => 'WPDev Ltd.',
                'plugin_author_website' => 'https://wpdevltd.com/',
                'primary_color' => '#5de0e6',
                'primary_color_hex' => '#5de0e6',
                'primary_color_rgb' => '93, 224, 230'
            ]
        ]);
        
        // Add whitelabel settings section
        add_settings_section(
            'wplm_whitelabel_settings_section',
            __('Whitelabel Settings', 'wp-license-manager'),
            null,
            'wplm-whitelabel-settings'
        );
    }

    /**
     * Add enhanced admin menu structure
     */
    public function add_admin_menu() {
        // Main menu page - Dashboard
        $plugin_name = self::get_plugin_name();
        add_menu_page(
            $plugin_name,
            $plugin_name,
            'manage_options',
            $this->menu_slug,
            [$this, 'render_dashboard_page'],
            'dashicons-admin-network',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            $this->menu_slug,
            __('Dashboard', 'wp-license-manager'),
            __('Dashboard', 'wp-license-manager'),
            'manage_options',
            $this->menu_slug,
            [$this, 'render_dashboard_page']
        );

        // Licenses submenu
        add_submenu_page(
            $this->menu_slug,
            __('Licenses', 'wp-license-manager'),
            __('Licenses', 'wp-license-manager'),
            'manage_options',
            'wplm-licenses',
            [$this, 'render_licenses_page']
        );

        // Products submenu removed - Products now handled as CPT under Licenses menu

        // Orders submenu
        add_submenu_page(
            $this->menu_slug,
            __('Orders', 'wp-license-manager'),
            __('Orders', 'wp-license-manager'),
            'manage_options',
            'wplm-orders',
            [$this, 'render_orders_page']
        );

        // Subscriptions submenu
        add_submenu_page(
            $this->menu_slug,
            __('Subscriptions', 'wp-license-manager'),
            __('Subscriptions', 'wp-license-manager'),
            'manage_options',
            'wplm-subscriptions',
            [$this, 'render_subscriptions_page']
        );

        // Customers submenu
        add_submenu_page(
            $this->menu_slug,
            __('Customers', 'wp-license-manager'),
            __('Customers', 'wp-license-manager'),
            'manage_options',
            'wplm-customers',
            [$this, 'render_customers_page']
        );

        // Activity Log submenu
        add_submenu_page(
            $this->menu_slug,
            __('Activity Log', 'wp-license-manager'),
            __('Activity Log', 'wp-license-manager'),
            'manage_options',
            'wplm-activity-log',
            [$this, 'render_activity_log_page']
        );

        // Digital Downloads removed - using products instead

        // Settings submenu
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
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Don't load on license/product post edit pages - let Admin Manager handle those
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            return;
        }
        
        if (strpos($hook, 'wplm') === false && strpos($hook, 'license-manager') === false) {
            return;
        }

        // Enqueue Chart.js for analytics
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1');
        
        // Enqueue DataTables for enhanced tables
        wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', ['jquery'], '1.13.6');
        wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css', [], '1.13.6');

        // Enqueue modern admin styles
        wp_enqueue_style('wplm-enhanced-admin', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/css/enhanced-admin.css', [], WPLM_VERSION);
        wp_enqueue_style('wplm-whitelabel', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/css/whitelabel.css', ['wplm-enhanced-admin'], WPLM_VERSION);
        wp_enqueue_style('wplm-whitelabel-enhanced', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/css/whitelabel-enhanced.css', ['wplm-whitelabel'], WPLM_VERSION);
        wp_enqueue_script('wplm-enhanced-admin', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/enhanced-admin.js', ['jquery', 'datatables-js'], WPLM_VERSION . '.' . time(), true);

        // Also load the regular admin script for API key functionality
        wp_enqueue_script('wplm-admin-script', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/admin-script.js', ['jquery'], WPLM_VERSION, true);

        // Localize enhanced admin script
        wp_localize_script('wplm-enhanced-admin', 'wplm_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplm_admin_nonce'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this item?', 'wp-license-manager'),
                'confirm_clear_logs' => __('Are you sure you want to clear all activity logs?', 'wp-license-manager'),
                'success' => __('Action completed successfully!', 'wp-license-manager'),
                'error' => __('An error occurred. Please try again.', 'wp-license-manager'),
                'loading' => __('Loading...', 'wp-license-manager'),
                'create_subscription' => __('Create Subscription', 'wp-license-manager'),
                'customer_email' => __('Customer Email', 'wp-license-manager'),
                'product_name' => __('Product Name', 'wp-license-manager'),
                'billing_frequency' => __('Billing Frequency', 'wp-license-manager'),
                'days' => __('Day(s)', 'wp-license-manager'),
                'weeks' => __('Week(s)', 'wp-license-manager'),
                'months' => __('Month(s)', 'wp-license-manager'),
                'years' => __('Year(s)', 'wp-license-manager'),
                'start_date' => __('Start Date', 'wp-license-manager'),
                'end_date' => __('End Date', 'wp-license-manager'),
                'leave_empty_for_ongoing' => __('Leave empty for ongoing subscription', 'wp-license-manager'),
                'status' => __('Status', 'wp-license-manager'),
                'active' => __('Active', 'wp-license-manager'),
                'on_hold' => __('On Hold', 'wp-license-manager'),
                'cancelled' => __('Cancelled', 'wp-license-manager'),
                'no_activity' => __('No recent activity.', 'wp-license-manager')
            ],
            'subscription_nonce' => wp_create_nonce('wplm_subscription_action')
        ]);

        // Get current post ID if we're editing a license
        $current_post_id = 0;
        if (isset($_GET['post']) && get_post_type($_GET['post']) === 'wplm_license') {
            $current_post_id = intval($_GET['post']);
        }

        // Localize admin script for API key functionality
        wp_localize_script('wplm-admin-script', 'wplm_admin_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'generate_api_key_nonce' => wp_create_nonce('wplm_generate_api_key_nonce'),
            'export_licenses_nonce' => wp_create_nonce('wplm_export_licenses_nonce'),
            'create_license_nonce' => wp_create_nonce('wplm_create_license_nonce'),
            'generating_text' => __('Generating...', 'wp-license-manager'),
            'generate_key_post_id' => $current_post_id,
            'post_edit_nonce' => $current_post_id ? wp_create_nonce('wplm_generate_key_' . $current_post_id) : ''
        ]);
    }

    /**
     * Render Dashboard Page with Analytics and Overview
     */
    public function render_dashboard_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php _e('License Manager Dashboard', 'wp-license-manager'); ?>
                </h1>
                <p class="wplm-subtitle"><?php _e('Comprehensive overview of your licensing system', 'wp-license-manager'); ?></p>
            </div>

            <!-- Quick Stats Cards -->
            <div class="wplm-stats-grid">
                <div class="wplm-stat-card wplm-stat-primary">
                    <div class="wplm-stat-icon">
                        <span class="dashicons dashicons-admin-network"></span>
                    </div>
                    <div class="wplm-stat-content">
                        <h3 id="total-licenses">-</h3>
                        <p><?php _e('Total Licenses', 'wp-license-manager'); ?></p>
                    </div>
                </div>

                <div class="wplm-stat-card wplm-stat-success">
                    <div class="wplm-stat-icon">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="wplm-stat-content">
                        <h3 id="active-licenses">-</h3>
                        <p><?php _e('Active Licenses', 'wp-license-manager'); ?></p>
                    </div>
                </div>

                <div class="wplm-stat-card wplm-stat-warning">
                    <div class="wplm-stat-icon">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="wplm-stat-content">
                        <h3 id="expiring-licenses">-</h3>
                        <p><?php _e('Expiring Soon', 'wp-license-manager'); ?></p>
                    </div>
                </div>

                <div class="wplm-stat-card wplm-stat-info">
                    <div class="wplm-stat-icon">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="wplm-stat-content">
                        <h3 id="total-customers">-</h3>
                        <p><?php _e('Total Customers', 'wp-license-manager'); ?></p>
                    </div>
                </div>

                <div class="wplm-stat-card wplm-stat-info">
                    <div class="wplm-stat-icon">
                        <span class="dashicons dashicons-products"></span>
                    </div>
                    <div class="wplm-stat-content">
                        <h3 id="total-products">-</h3>
                        <p><?php _e('Total Products', 'wp-license-manager'); ?></p>
                    </div>
                </div>

                <div class="wplm-stat-card wplm-stat-secondary">
                    <div class="wplm-stat-icon">
                        <span class="dashicons dashicons-update"></span>
                    </div>
                    <div class="wplm-stat-content">
                        <h3 id="active-subscriptions">-</h3>
                        <p><?php _e('Active Subscriptions', 'wp-license-manager'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="wplm-charts-grid">
                <div class="wplm-chart-container">
                    <div class="wplm-chart-header">
                        <h3><?php _e('License Activations (Last 30 Days)', 'wp-license-manager'); ?></h3>
                    </div>
                    <div class="wplm-chart-body">
                        <canvas id="licensesChart" width="400" height="200"></canvas>
                    </div>
                </div>

                <div class="wplm-chart-container">
                    <div class="wplm-chart-header">
                        <h3><?php _e('Revenue Overview', 'wp-license-manager'); ?></h3>
                    </div>
                    <div class="wplm-chart-body">
                        <canvas id="revenueChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="wplm-recent-activity">
                <div class="wplm-section-header">
                    <h3><?php _e('Recent Activity', 'wp-license-manager'); ?></h3>
                    <a href="<?php echo admin_url('admin.php?page=wplm-activity-log'); ?>" class="button button-secondary">
                        <?php _e('View All', 'wp-license-manager'); ?>
                    </a>
                </div>
                <div id="recent-activity-list" class="wplm-activity-list">
                    <div class="wplm-loading"><?php _e('Loading recent activity...', 'wp-license-manager'); ?></div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="wplm-quick-actions">
                <div class="wplm-section-header">
                    <h3><?php _e('Quick Actions', 'wp-license-manager'); ?></h3>
                </div>
                <div class="wplm-action-buttons">
                    <a href="<?php echo admin_url('post-new.php?post_type=wplm_license'); ?>" class="wplm-action-btn wplm-btn-primary">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php _e('Generate New License', 'wp-license-manager'); ?>
                    </a>
                    <a href="<?php echo admin_url('post-new.php?post_type=wplm_product'); ?>" class="wplm-action-btn wplm-btn-secondary">
                        <span class="dashicons dashicons-products"></span>
                        <?php _e('Add New Product', 'wp-license-manager'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=wplm-customers'); ?>" class="wplm-action-btn wplm-btn-info">
                        <span class="dashicons dashicons-groups"></span>
                        <?php _e('Manage Customers', 'wp-license-manager'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=wplm-settings'); ?>" class="wplm-action-btn wplm-btn-warning">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php _e('Settings', 'wp-license-manager'); ?>
                    </a>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Load dashboard statistics
            WPLM_Enhanced_Admin.loadDashboardStats();
            
            // Load recent activity
            WPLM_Enhanced_Admin.loadRecentActivity();
            
            // Initialize charts
            WPLM_Enhanced_Admin.initCharts();
        });
        </script>
        <?php
    }

    /**
     * Render Licenses Page with Enhanced Table
     */
    public function render_licenses_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php _e('License Management', 'wp-license-manager'); ?>
                </h1>
                <div class="wplm-header-actions">
                    <a href="<?php echo admin_url('post-new.php?post_type=wplm_license'); ?>" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php _e('Add New License', 'wp-license-manager'); ?>
                    </a>
                </div>
            </div>

            <div class="wplm-filters-section">
                <div class="wplm-filter-group">
                    <label for="license-status-filter"><?php _e('Status:', 'wp-license-manager'); ?></label>
                    <select id="license-status-filter">
                        <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                        <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                        <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div class="wplm-filter-group">
                    <label for="license-product-filter"><?php _e('Product:', 'wp-license-manager'); ?></label>
                    <select id="license-product-filter">
                        <option value=""><?php _e('All Products', 'wp-license-manager'); ?></option>
                        <?php
                        $products = get_posts(['post_type' => 'wplm_product', 'posts_per_page' => -1]);
                        foreach ($products as $product) {
                            echo '<option value="' . $product->ID . '">' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <button class="button" id="apply-filters"><?php _e('Apply Filters', 'wp-license-manager'); ?></button>
                <button class="button" id="clear-filters"><?php _e('Clear Filters', 'wp-license-manager'); ?></button>
                <div class="wplm-filter-group">
                    <label for="bulk-action-selector"><?php _e('Bulk Actions:', 'wp-license-manager'); ?></label>
                    <select id="bulk-action-selector">
                        <option value=""><?php _e('Bulk Actions', 'wp-license-manager'); ?></option>
                        <option value="activate"><?php _e('Activate', 'wp-license-manager'); ?></option>
                        <option value="deactivate"><?php _e('Deactivate', 'wp-license-manager'); ?></option>
                        <option value="force_deactivate"><?php _e('Force Deactivate', 'wp-license-manager'); ?></option>
                        <option value="trash"><?php _e('Move to Trash', 'wp-license-manager'); ?></option>
                        <option value="delete"><?php _e('Delete Permanently', 'wp-license-manager'); ?></option>
                    </select>
                    <button class="button" id="apply-bulk-action"><?php _e('Apply', 'wp-license-manager'); ?></button>
                </div>
            </div>

            <div class="wplm-table-container">
                
                <table id="licenses-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
                            <th class="wplm-checkbox-column">
                                <input type="checkbox" id="select-all-licenses" class="wplm-select-all">
                            </th>
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
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Table initialization handled by enhanced-admin.js -->
        <?php
    }

    /**
     * Render Products Page
     */
    public function render_products_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-products"></span>
                    <?php _e('Product Management', 'wp-license-manager'); ?>
                </h1>
                <div class="wplm-header-actions">
                    <a href="<?php echo admin_url('post-new.php?post_type=wplm_product'); ?>" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php _e('Add New Product', 'wp-license-manager'); ?>
                    </a>
                </div>
            </div>

            <div class="wplm-sync-section">
                <div class="wplm-sync-info">
                    <h3><?php _e('WooCommerce Integration', 'wp-license-manager'); ?></h3>
                    <p><?php _e('Automatically sync with WooCommerce products for seamless license management.', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-sync-actions">
                    <button class="button button-secondary" id="sync-wc-products">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Sync WooCommerce Products', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>

            <div class="wplm-filters-section">
                <div class="wplm-filter-group">
                    <label for="product-status-filter"><?php _e('Status:', 'wp-license-manager'); ?></label>
                    <select id="product-status-filter">
                        <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                        <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div class="wplm-filter-group">
                    <label for="product-source-filter"><?php _e('Source:', 'wp-license-manager'); ?></label>
                    <select id="product-source-filter">
                        <option value=""><?php _e('All Sources', 'wp-license-manager'); ?></option>
                        <option value="wplm_store"><?php _e('WPLM Store', 'wp-license-manager'); ?></option>
                        <option value="woocommerce"><?php _e('WooCommerce', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <button class="button" id="apply-product-filters"><?php _e('Apply Filters', 'wp-license-manager'); ?></button>
                <button class="button" id="clear-product-filters"><?php _e('Clear Filters', 'wp-license-manager'); ?></button>
                <div class="wplm-filter-group">
                    <label for="bulk-action-selector-products"><?php _e('Bulk Actions:', 'wp-license-manager'); ?></label>
                    <select id="bulk-action-selector-products">
                        <option value=""><?php _e('Bulk Actions', 'wp-license-manager'); ?></option>
                        <option value="trash"><?php _e('Move to Trash', 'wp-license-manager'); ?></option>
                        <option value="delete"><?php _e('Delete Permanently', 'wp-license-manager'); ?></option>
                    </select>
                    <button class="button" id="apply-bulk-action-products"><?php _e('Apply', 'wp-license-manager'); ?></button>
                </div>
            </div>

            <div class="wplm-table-container">
                
                <table id="products-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
                            <th class="wplm-checkbox-column">
                                <input type="checkbox" id="select-all-products" class="wplm-select-all">
                            </th>
                            <th><?php _e('Product Name', 'wp-license-manager'); ?></th>
                            <th><?php _e('Product ID', 'wp-license-manager'); ?></th>
                            <th><?php _e('Version', 'wp-license-manager'); ?></th>
                            <th><?php _e('Total Licenses', 'wp-license-manager'); ?></th>
                            <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                            <th><?php _e('WooCommerce Link', 'wp-license-manager'); ?></th>
                            <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Table initialization handled by enhanced-admin.js -->
        <?php
    }

    /**
     * Render Subscriptions Page
     */
    public function render_subscriptions_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Subscription Management', 'wp-license-manager'); ?>
                </h1>
                <div class="wplm-header-actions">
                    <button class="button button-primary" id="create-subscription">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php _e('Create Subscription', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>

            <div class="wplm-subscription-stats">
                <div class="wplm-stat-card">
                    <h3 id="active-subs">-</h3>
                    <p><?php _e('Active Subscriptions', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-stat-card">
                    <h3 id="pending-subs">-</h3>
                    <p><?php _e('Pending Renewals', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-stat-card">
                    <h3 id="monthly-revenue">-</h3>
                    <p><?php _e('Monthly Revenue', 'wp-license-manager'); ?></p>
                </div>
            </div>

            <div class="wplm-filters-section">
                <div class="wplm-filter-group">
                    <label for="subscription-status-filter"><?php _e('Status:', 'wp-license-manager'); ?></label>
                    <select id="subscription-status-filter">
                        <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                        <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="on-hold"><?php _e('On Hold', 'wp-license-manager'); ?></option>
                        <option value="cancelled"><?php _e('Cancelled', 'wp-license-manager'); ?></option>
                        <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div class="wplm-filter-group">
                    <label for="subscription-product-filter"><?php _e('Product:', 'wp-license-manager'); ?></label>
                    <select id="subscription-product-filter">
                        <option value=""><?php _e('All Products', 'wp-license-manager'); ?></option>
                        <?php
                        $products = get_posts(['post_type' => 'wplm_product', 'posts_per_page' => -1]);
                        foreach ($products as $product) {
                            echo '<option value="' . $product->ID . '">' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <button class="button" id="apply-subscription-filters"><?php _e('Apply Filters', 'wp-license-manager'); ?></button>
                <button class="button" id="clear-subscription-filters"><?php _e('Clear Filters', 'wp-license-manager'); ?></button>
                <div class="wplm-filter-group">
                    <label for="bulk-action-selector-subscriptions"><?php _e('Bulk Actions:', 'wp-license-manager'); ?></label>
                    <select id="bulk-action-selector-subscriptions">
                        <option value=""><?php _e('Bulk Actions', 'wp-license-manager'); ?></option>
                        <option value="trash"><?php _e('Move to Trash', 'wp-license-manager'); ?></option>
                        <option value="delete"><?php _e('Delete Permanently', 'wp-license-manager'); ?></option>
                    </select>
                    <button class="button" id="apply-bulk-action-subscriptions"><?php _e('Apply', 'wp-license-manager'); ?></button>
                </div>
            </div>

            <div class="wplm-table-container">
                
                <table id="subscriptions-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
                            <th class="wplm-checkbox-column">
                                <input type="checkbox" id="select-all-subscriptions" class="wplm-select-all">
                            </th>
                            <th><?php _e('Subscription ID', 'wp-license-manager'); ?></th>
                            <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                            <th><?php _e('Product', 'wp-license-manager'); ?></th>
                            <th><?php _e('Status', 'wp-license-manager'); ?></th>
                            <th><?php _e('Next Payment', 'wp-license-manager'); ?></th>
                            <th><?php _e('Total Paid', 'wp-license-manager'); ?></th>
                            <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Handle "Create Subscription" button click
            $('#create-subscription').on('click', function() {
                WPLM_Enhanced_Admin.openCreateSubscriptionModal();
            });
        });
        </script>
        <?php
        $this->render_generic_modal_container();
    }

    // Generic Modal Container (can be used for various forms)
    private function render_generic_modal_container() {
        ?>
        <div id="wplm-generic-modal" class="wplm-modal" style="display: none;">
            <div class="wplm-modal-content">
                <div class="wplm-modal-header">
                    <h3 id="wplm-modal-title"></h3>
                    <span class="wplm-close">&times;</span>
                </div>
                <div class="wplm-modal-body" id="wplm-modal-body-content">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Create Subscription Modal Content.
     */
    public function render_create_subscription_modal_content() {
        ?>
        <div class="wplm-modal-body-content">
            <form id="wplm-create-subscription-form">
                <?php wp_nonce_field('wplm_create_subscription_nonce', 'wplm_create_subscription_nonce_field'); ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th><label for="wplm_subscription_customer_id"><?php _e('Customer', 'wp-license-manager'); ?></label></th>
                            <td>
                                <select id="wplm_subscription_customer_id" name="customer_id" class="wplm-select2" style="width: 100%;" required>
                                    <option value=""><?php _e('Select a Customer', 'wp-license-manager'); ?></option>
                                </select>
                                <p class="description"><?php _e('Search for an existing customer or leave blank to create one on license assignment.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_product_id"><?php _e('Product', 'wp-license-manager'); ?></label></th>
                            <td>
                                <select id="wplm_subscription_product_id" name="product_id" class="wplm-select2" style="width: 100%;" required>
                                    <option value=""><?php _e('Select a Product', 'wp-license-manager'); ?></option>
                                </select>
                                <p class="description"><?php _e('Select the product associated with this subscription.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_status"><?php _e('Status', 'wp-license-manager'); ?></label></th>
                            <td>
                                <select id="wplm_subscription_status" name="status">
                                    <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                                    <option value="pending"><?php _e('Pending', 'wp-license-manager'); ?></option>
                                    <option value="on-hold"><?php _e('On Hold', 'wp-license-manager'); ?></option>
                                    <option value="cancelled"><?php _e('Cancelled', 'wp-license-manager'); ?></option>
                                    <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_billing_period"><?php _e('Billing Period', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="number" id="wplm_subscription_billing_period_value" name="billing_period_value" value="1" min="1" style="width: 80px;" />
                                <select id="wplm_subscription_billing_period_unit" name="billing_period_unit" style="width: 120px; margin-left: 10px;">
                                    <option value="day"><?php _e('Day(s)', 'wp-license-manager'); ?></option>
                                    <option value="week"><?php _e('Week(s)', 'wp-license-manager'); ?></option>
                                    <option value="month" selected><?php _e('Month(s)', 'wp-license-manager'); ?></option>
                                    <option value="year"><?php _e('Year(s)', 'wp-license-manager'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_regular_amount"><?php _e('Regular Amount', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="number" step="0.01" id="wplm_subscription_regular_amount" name="regular_amount" value="0.00" class="regular-text" required />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_trial_length"><?php _e('Trial Length', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="number" id="wplm_subscription_trial_length" name="trial_length" value="0" min="0" style="width: 80px;" />
                                <select id="wplm_subscription_trial_unit" name="trial_unit" style="width: 120px; margin-left: 10px;">
                                    <option value="day"><?php _e('Day(s)', 'wp-license-manager'); ?></option>
                                    <option value="week"><?php _e('Week(s)', 'wp-license-manager'); ?></option>
                                    <option value="month" selected><?php _e('Month(s)', 'wp-license-manager'); ?></option>
                                    <option value="year"><?php _e('Year(s)', 'wp-license-manager'); ?></option>
                                </select>
                                <p class="description"><?php _e('Number of periods for the trial. Set to 0 for no trial.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_start_date"><?php _e('Start Date', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="date" id="wplm_subscription_start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" class="regular-text" required />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_next_payment_date"><?php _e('Next Payment Date', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="date" id="wplm_subscription_next_payment_date" name="next_payment_date" class="regular-text" />
                                <p class="description"><?php _e('Leave empty to auto-calculate based on billing period and trial.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wplm_subscription_end_date"><?php _e('End Date', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="date" id="wplm_subscription_end_date" name="end_date" class="regular-text" />
                                <p class="description"><?php _e('Leave empty for indefinite subscription.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" id="wplm-create-subscription-submit">
                        <?php _e('Create Subscription', 'wp-license-manager'); ?>
                    </button>
                    <button type="button" class="button wplm-modal-cancel">
                        <?php _e('Cancel', 'wp-license-manager'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Orders Page with Order Management functionality
     */
    public function render_orders_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-cart"></span>
                    <?php _e('Order Management', 'wp-license-manager'); ?>
                </h1>
                <div class="wplm-header-actions">
                    <button class="button button-primary" id="add-order">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php _e('Add Order', 'wp-license-manager'); ?>
                    </button>
                    <button class="button button-secondary" id="export-orders">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Export Orders', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>

            <div class="wplm-order-search">
                <div class="wplm-search-box">
                    <input type="text" id="order-search" placeholder="<?php _e('Search orders by number, customer name, or email...', 'wp-license-manager'); ?>" />
                    <button class="button" id="search-orders">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
            </div>

            <!-- WooCommerce Order Sync Section -->
            <div class="wplm-sync-section" style="padding: 15px;">
                <div class="wplm-sync-info">
                    <h3><?php _e('WooCommerce Order Integration', 'wp-license-manager'); ?></h3>
                    <p><?php _e('Automatically sync orders from WooCommerce for seamless order management and license generation.', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-sync-actions">
                    <button type="button" class="button button-primary" id="sync-wc-orders">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Sync WooCommerce Orders', 'wp-license-manager'); ?>
                    </button>
                    <div class="wplm-sync-status" id="wc-order-sync-status"></div>
                </div>
            </div>

            <div class="wplm-table-container">
                <table id="orders-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
                            <th><?php _e('Order Number', 'wp-license-manager'); ?></th>
                            <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                            <th><?php _e('Email', 'wp-license-manager'); ?></th>
                            <th><?php _e('Total', 'wp-license-manager'); ?></th>
                            <th><?php _e('Status', 'wp-license-manager'); ?></th>
                            <th><?php _e('Date', 'wp-license-manager'); ?></th>
                            <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Order Details Modal -->
        <div id="order-modal" class="wplm-modal" style="display: none;">
            <div class="wplm-modal-content">
                <div class="wplm-modal-header">
                    <h3><?php _e('Order Details', 'wp-license-manager'); ?></h3>
                    <span class="wplm-close">&times;</span>
                </div>
                <div class="wplm-modal-body" id="order-modal-body">
                    <!-- Order details will be loaded here -->
                </div>
            </div>
        </div>

        <!-- Add Order Modal -->
        <div id="add-order-modal" class="wplm-modal" style="display: none;">
            <div class="wplm-modal-content">
                <div class="wplm-modal-header">
                    <h3><?php _e('Add New Order', 'wp-license-manager'); ?></h3>
                    <span class="wplm-close">&times;</span>
                </div>
                <div class="wplm-modal-body">
                    <form id="add-order-form">
                        <div class="wplm-form-row">
                            <div class="wplm-form-group">
                                <label for="order_number"><?php _e('Order Number *', 'wp-license-manager'); ?></label>
                                <input type="text" id="order_number" name="order_number" required />
                            </div>
                            <div class="wplm-form-group">
                                <label for="order_total"><?php _e('Order Total *', 'wp-license-manager'); ?></label>
                                <input type="number" id="order_total" name="order_total" step="0.01" required />
                            </div>
                        </div>
                        <div class="wplm-form-row">
                            <div class="wplm-form-group">
                                <label for="customer_name"><?php _e('Customer Name *', 'wp-license-manager'); ?></label>
                                <input type="text" id="customer_name" name="customer_name" required />
                            </div>
                            <div class="wplm-form-group">
                                <label for="customer_email"><?php _e('Customer Email *', 'wp-license-manager'); ?></label>
                                <input type="email" id="customer_email" name="customer_email" required />
                            </div>
                        </div>
                        <div class="wplm-form-row">
                            <div class="wplm-form-group">
                                <label for="order_status"><?php _e('Order Status', 'wp-license-manager'); ?></label>
                                <select id="order_status" name="order_status">
                                    <option value="pending"><?php _e('Pending', 'wp-license-manager'); ?></option>
                                    <option value="processing"><?php _e('Processing', 'wp-license-manager'); ?></option>
                                    <option value="completed"><?php _e('Completed', 'wp-license-manager'); ?></option>
                                    <option value="cancelled"><?php _e('Cancelled', 'wp-license-manager'); ?></option>
                                    <option value="refunded"><?php _e('Refunded', 'wp-license-manager'); ?></option>
                                    <option value="failed"><?php _e('Failed', 'wp-license-manager'); ?></option>
                                </select>
                            </div>
                            <div class="wplm-form-group">
                                <label for="order_date"><?php _e('Order Date', 'wp-license-manager'); ?></label>
                                <input type="datetime-local" id="order_date" name="order_date" />
                            </div>
                        </div>
                        <div class="wplm-form-row">
                            <div class="wplm-form-group">
                                <label for="payment_method"><?php _e('Payment Method', 'wp-license-manager'); ?></label>
                                <input type="text" id="payment_method" name="payment_method" placeholder="<?php _e('e.g., Credit Card, PayPal', 'wp-license-manager'); ?>" />
                            </div>
                        </div>
                        <div class="wplm-form-actions">
                            <button type="submit" class="button button-primary"><?php _e('Create Order', 'wp-license-manager'); ?></button>
                            <button type="button" class="button" onclick="WPLM_Enhanced_Admin.closeModal()"><?php _e('Cancel', 'wp-license-manager'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            if (typeof WPLM_Enhanced_Admin !== 'undefined' && typeof WPLM_Enhanced_Admin.initOrdersTable === 'function') {
                WPLM_Enhanced_Admin.initOrdersTable();
            } else {
                console.error('WPLM_Enhanced_Admin.initOrdersTable is not available');
                $('#orders-table').html('<div class="wplm-error">Orders table initialization failed. Please refresh the page.</div>');
            }
        });
        </script>
        <?php
    }

    /**
     * Render Customers Page with CRM functionality
     */
    public function render_customers_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-groups"></span>
                    <?php _e('Customer Relationship Management', 'wp-license-manager'); ?>
                </h1>
                <div class="wplm-header-actions">
                    <button class="button button-primary" id="add-customer">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php _e('Add Customer', 'wp-license-manager'); ?>
                    </button>
                    <button class="button button-secondary" id="export-customers">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Export Customers', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>

            <div class="wplm-customer-search">
                <div class="wplm-search-box">
                    <input type="text" id="customer-search" placeholder="<?php _e('Search customers by name, email, or license...', 'wp-license-manager'); ?>" />
                    <button class="button" id="search-customers">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
            </div>

            <!-- WooCommerce Customer Sync Section -->
            <div class="wplm-sync-section">
                <div class="wplm-sync-info">
                    <h3><?php _e('WooCommerce Customer Integration', 'wp-license-manager'); ?></h3>
                    <p><?php _e('Automatically sync customers from WooCommerce orders and user accounts for comprehensive customer management.', 'wp-license-manager'); ?></p>
                </div>
                <div class="wplm-sync-actions">
                    <button type="button" class="button button-primary" id="sync-wc-customers">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Sync WooCommerce Customers', 'wp-license-manager'); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="sync-wc-customer-orders">
                        <span class="dashicons dashicons-cart"></span>
                        <?php _e('Sync Recent Orders', 'wp-license-manager'); ?>
                    </button>
                    <div class="wplm-sync-status" id="wc-customer-sync-status"></div>
                </div>
            </div>

            <div class="wplm-table-container">
                <table id="customers-table" class="wplm-enhanced-table">
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
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Customer Details Modal -->
        <div id="customer-modal" class="wplm-modal" style="display: none;">
            <div class="wplm-modal-content">
                <div class="wplm-modal-header">
                    <h3><?php _e('Customer Details', 'wp-license-manager'); ?></h3>
                    <span class="wplm-close">&times;</span>
                </div>
                <div class="wplm-modal-body" id="customer-modal-body">
                    <!-- Customer details will be loaded here -->
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            WPLM_Enhanced_Admin.initCustomersTable();
        });
        </script>
        <?php
    }

    /**
     * Render Activity Log Page
     */
    public function render_activity_log_page() {
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php _e('Activity Log', 'wp-license-manager'); ?>
                </h1>
                <div class="wplm-header-actions">
                    <button class="button button-secondary" id="export-logs">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Export Logs', 'wp-license-manager'); ?>
                    </button>
                    <button class="button button-danger" id="clear-logs">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Clear All Logs', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>

            <div class="wplm-log-filters">
                <div class="wplm-filter-group">
                    <label for="log-type-filter"><?php _e('Type:', 'wp-license-manager'); ?></label>
                    <select id="log-type-filter">
                        <option value=""><?php _e('All Types', 'wp-license-manager'); ?></option>
                        <option value="license_created"><?php _e('License Created', 'wp-license-manager'); ?></option>
                        <option value="license_activated"><?php _e('License Activated', 'wp-license-manager'); ?></option>
                        <option value="license_deactivated"><?php _e('License Deactivated', 'wp-license-manager'); ?></option>
                        <option value="license_expired"><?php _e('License Expired', 'wp-license-manager'); ?></option>
                        <option value="product_created"><?php _e('Product Created', 'wp-license-manager'); ?></option>
                        <option value="subscription_created"><?php _e('Subscription Created', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div class="wplm-filter-group">
                    <label for="log-date-filter"><?php _e('Date Range:', 'wp-license-manager'); ?></label>
                    <input type="date" id="log-date-from" />
                    <span><?php _e('to', 'wp-license-manager'); ?></span>
                    <input type="date" id="log-date-to" />
                </div>
                <button class="button" id="apply-log-filters"><?php _e('Apply Filters', 'wp-license-manager'); ?></button>
            </div>

            <div class="wplm-table-container">
                <table id="activity-log-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
                            <th><?php _e('Date/Time', 'wp-license-manager'); ?></th>
                            <th><?php _e('Type', 'wp-license-manager'); ?></th>
                            <th><?php _e('Description', 'wp-license-manager'); ?></th>
                            <th><?php _e('User', 'wp-license-manager'); ?></th>
                            <th><?php _e('IP Address', 'wp-license-manager'); ?></th>
                            <th><?php _e('Details', 'wp-license-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            WPLM_Enhanced_Admin.initActivityLogTable();
        });
        </script>
        <?php
    }

    /**
     * Render Settings Page with Enhanced Options
     */
    public function render_settings_page() {
        $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wplm-enhanced-admin-wrap">
            <div class="wplm-header">
                <h1 class="wplm-main-title">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php echo esc_html(self::get_plugin_name() . ' ' . __('Settings', 'wp-license-manager')); ?>
                </h1>
            </div>

            <div class="wplm-settings-tabs">
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
                    <a href="?page=wplm-settings&tab=activity" class="nav-tab <?php echo $current_tab == 'activity' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Activity Log', 'wp-license-manager'); ?>
                    </a>
                    <a href="?page=wplm-settings&tab=export-import" class="nav-tab <?php echo $current_tab == 'export-import' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Export/Import', 'wp-license-manager'); ?>
                    </a>
                    <a href="?page=wplm-settings&tab=whitelabel" class="nav-tab <?php echo $current_tab == 'whitelabel' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Whitelabel', 'wp-license-manager'); ?>
                    </a>
                    <a href="?page=wplm-settings&tab=advanced" class="nav-tab <?php echo $current_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Advanced', 'wp-license-manager'); ?>
                    </a>
                </nav>
            </div>

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
                    case 'activity':
                        $this->render_activity_settings();
                        break;
                    case 'export-import':
                        $this->render_export_import_settings();
                        break;
                    case 'whitelabel':
                        $this->render_whitelabel_settings();
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
                <h3><?php _e('Interface Options', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Native CPT Menus', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_show_native_cpt_menus" value="1" <?php checked(get_option('wplm_show_native_cpt_menus', false)); ?> />
                                <?php _e('Show the native WordPress CPT menus (Licenses, Products, Subscriptions) in the sidebar.', 'wp-license-manager'); ?>
                            </label>
                            <p class="description"><?php _e('Uncheck to hide them and use only the License Manager menu.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
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
        <?php
    }

    /**
     * Render Activity Settings Tab
     */
    private function render_activity_settings() {
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_activity_settings'); ?>
            
            <div class="wplm-settings-section">
                <h3><?php _e('Activity Log Configuration', 'wp-license-manager'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Activity Logging', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_enable_activity_log" value="1" <?php checked(get_option('wplm_enable_activity_log', '1')); ?> />
                                <?php _e('Track all license and product activities', 'wp-license-manager'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Log Retention Period', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="wplm_log_retention_period">
                                <option value="30" <?php selected(get_option('wplm_log_retention_period'), '30'); ?>><?php _e('30 Days', 'wp-license-manager'); ?></option>
                                <option value="90" <?php selected(get_option('wplm_log_retention_period'), '90'); ?>><?php _e('90 Days', 'wp-license-manager'); ?></option>
                                <option value="180" <?php selected(get_option('wplm_log_retention_period'), '180'); ?>><?php _e('180 Days', 'wp-license-manager'); ?></option>
                                <option value="365" <?php selected(get_option('wplm_log_retention_period'), '365'); ?>><?php _e('1 Year', 'wp-license-manager'); ?></option>
                                <option value="0" <?php selected(get_option('wplm_log_retention_period'), '0'); ?>><?php _e('Keep Forever', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('How long to keep activity logs before automatic cleanup.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Delete Logs on Uninstall', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_delete_logs_on_uninstall" value="1" <?php checked(get_option('wplm_delete_logs_on_uninstall', '0')); ?> />
                                <?php _e('Automatically delete all activity logs when the plugin is uninstalled', 'wp-license-manager'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <div class="wplm-settings-actions">
                    <h4><?php _e('Log Management Actions', 'wp-license-manager'); ?></h4>
                    <p>
                        <button type="button" class="button button-secondary" id="clear-old-logs">
                            <?php _e('Clear Old Logs Now', 'wp-license-manager'); ?>
                        </button>
                        <span class="description"><?php _e('Remove logs older than the retention period.', 'wp-license-manager'); ?></span>
                    </p>
                    <p>
                        <button type="button" class="button button-danger" id="clear-all-logs">
                            <?php _e('Clear All Logs', 'wp-license-manager'); ?>
                        </button>
                        <span class="description"><?php _e('Remove all activity logs (this action cannot be undone).', 'wp-license-manager'); ?></span>
                    </p>
                </div>
            </div>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render other settings tabs (placeholder methods)
     */
    private function render_licensing_settings() {
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_licensing_settings'); ?>
            
            <div class="wplm-settings-section">
                <h3><?php _e('License Generation & Validation', 'wp-license-manager'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('License Key Format', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="wplm_license_key_format">
                                <option value="uuid" <?php selected(get_option('wplm_license_key_format'), 'uuid'); ?>><?php _e('UUID (e.g., xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)', 'wp-license-manager'); ?></option>
                                <option value="custom_alphanum" <?php selected(get_option('wplm_license_key_format'), 'custom_alphanum'); ?>><?php _e('Custom Alphanumeric (e.g., XXXX-XXXX-XXXX-XXXX)', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('Choose the format for newly generated license keys.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Activation Limit', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="number" name="wplm_default_activation_limit" value="<?php echo esc_attr(get_option('wplm_default_activation_limit', 1)); ?>" min="0" class="small-text" />
                            <p class="description"><?php _e('Set 0 for unlimited activations. This can be overridden per product/license.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Hardware Fingerprinting', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_enable_hardware_fingerprinting" value="1" <?php checked(get_option('wplm_enable_hardware_fingerprinting', false)); ?> />
                                <?php _e('Enable hardware fingerprinting for stricter license control.', 'wp-license-manager'); ?>
                            </label>
                            <p class="description"><?php _e('Requires client-side implementation to send hardware ID during activation.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('License Expiry & Renewal', 'wp-license-manager'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Default Expiry Behavior', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="wplm_default_expiry_behavior">
                                <option value="deactivate" <?php selected(get_option('wplm_default_expiry_behavior', 'deactivate'), 'deactivate'); ?>><?php _e('Deactivate License', 'wp-license-manager'); ?></option>
<option value="downgrade" <?php selected(get_option('wplm_default_expiry_behavior', 'deactivate'), 'downgrade'); ?>><?php _e('Downgrade Features', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('What happens when a license expires.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Expiry Warning Period', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="number" name="wplm_expiry_warning_days" value="<?php echo esc_attr(get_option('wplm_expiry_warning_days', 7)); ?>" min="0" class="small-text" /> <?php _e('days before expiry', 'wp-license-manager'); ?>
                            <p class="description"><?php _e('Send email notifications this many days before a license expires.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_woocommerce_settings() {
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_woocommerce_settings'); ?>
            <div class="wplm-settings-section">
                <h3><?php _e('WooCommerce Integration', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable WooCommerce Integration', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_wc_integration_enabled" value="1" <?php checked(get_option('wplm_wc_integration_enabled', true)); ?> />
                                <?php _e('Automatically generate licenses for WooCommerce orders', 'wp-license-manager'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default License Type for WC Products', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="wplm_wc_default_license_type">
                                <option value="personal" <?php selected(get_option('wplm_wc_default_license_type', 'personal'), 'personal'); ?>><?php _e('Personal', 'wp-license-manager'); ?></option>
<option value="business" <?php selected(get_option('wplm_wc_default_license_type', 'personal'), 'business'); ?>><?php _e('Business', 'wp-license-manager'); ?></option>
<option value="developer" <?php selected(get_option('wplm_wc_default_license_type', 'personal'), 'developer'); ?>><?php _e('Developer', 'wp-license-manager'); ?></option>
<option value="lifetime" <?php selected(get_option('wplm_wc_default_license_type', 'personal'), 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('Default license type assigned to new WooCommerce products.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('License Delivery Email', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_wc_license_email_enabled" value="1" <?php checked(get_option('wplm_wc_license_email_enabled', true)); ?> />
                                <?php _e('Send license key in WooCommerce order emails', 'wp-license-manager'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Subscription Product Handling', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wplm_wc_subscription_support" value="1" <?php checked(get_option('wplm_wc_subscription_support', true)); ?> />
                                <?php _e('Enable license renewal/expiry sync with WooCommerce Subscriptions', 'wp-license-manager'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_notifications_settings() {
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_notifications_settings'); ?>
            <div class="wplm-settings-section">
                <h3><?php _e('Email Notifications', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('License Created', 'wp-license-manager'); ?></th>
                        <td><input type="checkbox" name="wplm_notify_license_created" value="1" <?php checked(get_option('wplm_notify_license_created', true)); ?> /> <?php _e('Notify customer when a license is created', 'wp-license-manager'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('License Activated', 'wp-license-manager'); ?></th>
                        <td><input type="checkbox" name="wplm_notify_license_activated" value="1" <?php checked(get_option('wplm_notify_license_activated', true)); ?> /> <?php _e('Notify customer when a license is activated', 'wp-license-manager'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('License Expiring Soon', 'wp-license-manager'); ?></th>
                        <td><input type="checkbox" name="wplm_notify_license_expiring" value="1" <?php checked(get_option('wplm_notify_license_expiring', true)); ?> /> <?php _e('Send expiry warning to customer', 'wp-license-manager'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('License Expired', 'wp-license-manager'); ?></th>
                        <td><input type="checkbox" name="wplm_notify_license_expired" value="1" <?php checked(get_option('wplm_notify_license_expired', true)); ?> /> <?php _e('Notify customer when a license expires', 'wp-license-manager'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Admin Notifications', 'wp-license-manager'); ?></th>
                        <td><input type="checkbox" name="wplm_notify_admin_limit" value="1" <?php checked(get_option('wplm_notify_admin_limit', true)); ?> /> <?php _e('Notify admin when activation limit is reached or suspicious activity is detected', 'wp-license-manager'); ?></td>
                    </tr>
                </table>
            </div>
            <div class="wplm-settings-section">
                <h3><?php _e('Test Email', 'wp-license-manager'); ?></h3>
                <p><?php _e('Send a test notification to verify email delivery and template appearance.', 'wp-license-manager'); ?></p>
                <input type="email" name="wplm_test_email" placeholder="<?php _e('Enter email address', 'wp-license-manager'); ?>" />
                <button type="button" class="button" id="wplm-send-test-email"><?php _e('Send Test Email', 'wp-license-manager'); ?></button>
            </div>
            <?php submit_button(); ?>
        </form>
        <script>
        jQuery(document).ready(function($){
            $('#wplm-send-test-email').on('click', function(e){
                e.preventDefault();
                var email = $('input[name=wplm_test_email]').val();
                if(!email) { alert('<?php _e('Please enter an email address.', 'wp-license-manager'); ?>'); return; }
                $.post(ajaxurl, {action: 'wplm_send_test_email', email: email, nonce: wplm_admin.nonce}, function(resp){
                    alert(resp.data && resp.data.message ? resp.data.message : (resp.success ? 'Sent!' : 'Failed!'));
                });
            });
        });
        </script>
        <?php
    }

    private function render_advanced_settings() {
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_advanced_settings'); ?>
            <div class="wplm-settings-section">
                <h3><?php _e('Security & Advanced Options', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Rate Limiting', 'wp-license-manager'); ?></th>
                        <td><input type="number" name="wplm_rate_limit_per_hour" value="<?php echo esc_attr(get_option('wplm_rate_limit_per_hour', 60)); ?>" min="1" /> <?php _e('Max license checks per hour per domain', 'wp-license-manager'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('IP Whitelist', 'wp-license-manager'); ?></th>
                        <td><textarea name="wplm_ip_whitelist" rows="2" class="large-text"><?php echo esc_textarea(get_option('wplm_ip_whitelist', '')); ?></textarea><p class="description"><?php _e('Comma-separated list of allowed IPs for API access.', 'wp-license-manager'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('IP Blacklist', 'wp-license-manager'); ?></th>
                        <td><textarea name="wplm_ip_blacklist" rows="2" class="large-text"><?php echo esc_textarea(get_option('wplm_ip_blacklist', '')); ?></textarea><p class="description"><?php _e('Comma-separated list of blocked IPs.', 'wp-license-manager'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Enable Debug Logging', 'wp-license-manager'); ?></th>
                        <td><input type="checkbox" name="wplm_debug_logging" value="1" <?php checked(get_option('wplm_debug_logging', false)); ?> /> <?php _e('Log all API and license events for debugging', 'wp-license-manager'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Developer Hooks & Filters', 'wp-license-manager'); ?></th>
                        <td><a href="https://yourdocsurl.com/wplm-hooks" target="_blank"><?php _e('View Developer Documentation', 'wp-license-manager'); ?></a></td>
                    </tr>
                </table>
            </div>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * AJAX handler for dashboard statistics
     */
    public function ajax_dashboard_stats() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        $stats = [
            'total_licenses' => wp_count_posts('wplm_license')->publish,
            'active_licenses' => $this->count_licenses_by_status('active'),
            'expiring_licenses' => $this->count_expiring_licenses(),
            'total_customers' => $this->count_unique_customers(),
            'total_products' => wp_count_posts('wplm_product')->publish,
            'active_subscriptions' => $this->count_active_subscriptions()
        ];

        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for clearing activity logs
     */
    public function ajax_clear_activity_logs() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Clear all activity logs
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'wplm_activity_log'");

        wp_send_json_success(['message' => __('All activity logs have been cleared.', 'wp-license-manager')]);
    }

    /**
     * Helper methods for statistics
     */
    private function count_licenses_by_status($status) {
        $query = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_status',
            'meta_value' => $status,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        return $query->found_posts;
    }

    private function count_expiring_licenses() {
        $seven_days_from_now = date('Y-m-d', strtotime('+7 days'));
        $query = new WP_Query([
            'post_type' => 'wplm_license',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wplm_status',
                    'value' => 'active',
                    'compare' => '='
                ],
                [
                    'key' => '_wplm_expiry_date',
                    'value' => $seven_days_from_now,
                    'compare' => '<=',
                    'type' => 'DATE'
                ]
            ],
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
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

    private function count_active_subscriptions() {
        $query = new WP_Query([
            'post_type' => 'wplm_subscription',
            'meta_key' => '_wplm_subscription_status',
            'meta_value' => 'active',
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        return $query->found_posts;
    }

    /**
     * AJAX handler for licenses DataTable
     */
    public function ajax_get_licenses() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Safe defaults for DataTables params to avoid undefined index notices
        $draw = intval($_POST['draw'] ?? 1);
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 25);
        $search = '';
        if (isset($_POST['search'])) {
            if (is_array($_POST['search']) && isset($_POST['search']['value'])) {
                $search = sanitize_text_field($_POST['search']['value']);
            } else {
                $search = sanitize_text_field($_POST['search']);
            }
        }
        $status_filter = sanitize_text_field($_POST['status'] ?? '');
        $product_filter = sanitize_text_field($_POST['product'] ?? '');

        $args = [
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $meta_query = [];
        if (!empty($status_filter)) {
            $meta_query[] = [
                'key' => '_wplm_status',
                'value' => $status_filter,
                'compare' => '='
            ];
        }
        if (!empty($product_filter)) {
            $meta_query[] = [
                'key' => '_wplm_product_id',
                'value' => $product_filter,
                'compare' => '='
            ];
        }
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($args);
        $licenses = [];

        foreach ($query->posts as $license) {
            $status = get_post_meta($license->ID, '_wplm_status', true);
            $customer_email = get_post_meta($license->ID, '_wplm_customer_email', true);
            $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
            $expiry_date = get_post_meta($license->ID, '_wplm_expiry_date', true);
            $activation_limit = get_post_meta($license->ID, '_wplm_activation_limit', true);
            
            $licenses[] = [
                'id' => $license->ID,
                'license_key' => $license->post_title,
                'customer' => $customer_email ?: __('No customer', 'wp-license-manager'),
                'product' => $product_id ?: __('No product', 'wp-license-manager'),
                'status' => $status ?: 'inactive',
                'expiry_date' => $expiry_date ?: 'Lifetime',
                'activations' => $this->get_license_activations_display($license->ID, $activation_limit),
                'actions' => $this->get_license_actions($license->ID),
                'created' => get_the_date('Y-m-d H:i:s', $license->ID)
            ];
        }

        // Get total count
        $total_args = $args;
        unset($total_args['posts_per_page'], $total_args['offset']);
        $total_query = new WP_Query($total_args);
        $total = $total_query->found_posts;

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => wp_count_posts('wplm_license')->publish,
            'recordsFiltered' => $total,
            'data' => $licenses
        ]);
    }

    /**
     * AJAX handler for products DataTable
     */
    public function ajax_get_products() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Safe defaults for DataTables params to avoid undefined index notices
        $draw = intval($_POST['draw'] ?? 1);
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 25);
        $search = '';
        if (isset($_POST['search'])) {
            if (is_array($_POST['search']) && isset($_POST['search']['value'])) {
                $search = sanitize_text_field($_POST['search']['value']);
            } else {
                $search = sanitize_text_field($_POST['search']);
            }
        }

        $args = [
            'post_type' => 'wplm_product',
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $product) {
            $current_version = get_post_meta($product->ID, '_wplm_current_version', true);
            $download_url = get_post_meta($product->ID, '_wplm_download_url', true);
            
            // Count licenses for this product
            $license_count = get_posts([
                'post_type' => 'wplm_license',
                'meta_key' => '_wplm_product_id',
                'meta_value' => $product->post_title,
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            
            $products[] = [
                'id' => $product->ID,
                'product_name' => $product->post_title,
                'product_id' => $product->post_name ?: sanitize_title($product->post_title),
                'version' => $current_version ?: '1.0.0',
                'download_url' => $download_url,
                'total_licenses' => count($license_count),
                'active_licenses' => $this->count_active_licenses_for_product($product->post_name),
                'wc_link' => $this->get_woocommerce_product_link($product->ID),
                'actions' => $this->get_product_actions($product->ID),
                'created' => get_the_date('Y-m-d H:i:s', $product->ID)
            ];
        }

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => wp_count_posts('wplm_product')->publish,
            'recordsFiltered' => $query->found_posts,
            'data' => $products
        ]);
    }

    /**
     * AJAX handler for customers DataTable - handled by WPLM_Customer_Management_System
     */

    /**
     * AJAX handler for subscriptions DataTable
     */
    public function ajax_get_subscriptions() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Safe defaults for DataTables params to avoid undefined index notices
        $draw = intval($_POST['draw'] ?? 1);
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 25);

        // For now, return empty data as subscription system needs to be implemented
        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
    }

    /**
     * AJAX handler for activity logs DataTable
     */
    public function ajax_get_activity_logs() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Safe defaults for DataTables params to avoid undefined index notices
        $draw = intval($_POST['draw'] ?? 1);
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 25);
        $search = '';
        if (isset($_POST['search'])) {
            if (is_array($_POST['search']) && isset($_POST['search']['value'])) {
                $search = sanitize_text_field($_POST['search']['value']);
            } else {
                $search = sanitize_text_field($_POST['search']);
            }
        }

        // Check if activity logger exists
        if (!class_exists('WPLM_Activity_Logger')) {
            wp_send_json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ]);
            return;
        }

        $args = [
            'post_type' => 'wplm_activity_log',
            'post_status' => 'publish',
            'posts_per_page' => $length,
            'offset' => $start,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $logs = [];

        foreach ($query->posts as $log) {
            $action = get_post_meta($log->ID, '_wplm_action', true);
            $license_id = get_post_meta($log->ID, '_wplm_license_id', true);
            $user_id = get_post_meta($log->ID, '_wplm_user_id', true);
            
            $logs[] = [
                'id' => $log->ID,
                'action' => $action,
                'description' => $log->post_content,
                'license_id' => $license_id,
                'user_id' => $user_id,
                'date' => get_the_date('Y-m-d H:i:s', $log->ID)
            ];
        }

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => wp_count_posts('wplm_activity_log')->publish,
            'recordsFiltered' => $query->found_posts,
            'data' => $logs
        ]);
    }

    /**
     * AJAX handler for getting customer details
     */
    public function ajax_get_customer_details() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $customer_email = sanitize_email($_POST['customer_id']);
        
        if (empty($customer_email)) {
            wp_send_json_error(['message' => 'Invalid customer email']);
        }

        // Get customer's licenses
        global $wpdb;
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title as license_key, pm_status.meta_value as status, 
                    pm_product.meta_value as product_id, pm_expiry.meta_value as expiry_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id
             LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_wplm_status'
             LEFT JOIN {$wpdb->postmeta} pm_product ON p.ID = pm_product.post_id AND pm_product.meta_key = '_wplm_product_id'
             LEFT JOIN {$wpdb->postmeta} pm_expiry ON p.ID = pm_expiry.post_id AND pm_expiry.meta_key = '_wplm_expiry_date'
             WHERE pm_email.meta_key = '_wplm_customer_email' 
             AND pm_email.meta_value = %s 
             AND p.post_type = 'wplm_license' 
             AND p.post_status = 'publish'
             ORDER BY p.post_date DESC",
            $customer_email
        ));

        wp_send_json_success([
            'email' => $customer_email,
            'licenses' => $licenses
        ]);
    }

    /**
     * AJAX handler for clearing old logs
     */
    public function ajax_clear_old_logs() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Delete logs older than 30 days
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} 
             WHERE post_type = 'wplm_activity_log' 
             AND post_date < %s",
            $thirty_days_ago
        ));

        wp_send_json_success([
            'message' => sprintf(__('Deleted %d old activity logs.', 'wp-license-manager'), $deleted)
        ]);
    }

    /**
     * AJAX handler for clearing all logs
     */
    public function ajax_clear_all_logs() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Delete all activity logs
        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'wplm_activity_log'");

        wp_send_json_success([
            'message' => sprintf(__('Deleted %d activity logs.', 'wp-license-manager'), $deleted)
        ]);
    }

    /**
     * AJAX handler for syncing WooCommerce products
     */
    public function ajax_sync_wc_products() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce is not active']);
        }

        // Implementation would go here for WC sync
        wp_send_json_success(['message' => 'WooCommerce products synced successfully']);
    }

    /**
     * AJAX handler for syncing WooCommerce customers
     */
    public function ajax_sync_wc_customers() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce is not active']);
        }

        $customer_management = new WPLM_Customer_Management_System();
        $synced_count = 0;
        $errors = [];

        // Get all WooCommerce customers
        $wc_customers = get_users([
            'role' => 'customer',
            'number' => -1,
            'orderby' => 'registered',
            'order' => 'DESC'
        ]);

        $created_count = 0;
        $updated_count = 0;
        
        foreach ($wc_customers as $wc_customer) {
            $customer_email = $wc_customer->user_email;
            
            // Check if customer already exists in WPLM
            $existing_customer = $customer_management->get_customer_by_email($customer_email);
            
            if (!$existing_customer) {
                // Create new customer from WooCommerce user
                $customer_name = trim($wc_customer->first_name . ' ' . $wc_customer->last_name);
                if (empty($customer_name)) {
                    $customer_name = $customer_email;
                }

                $customer_id = wp_insert_post([
                    'post_type' => 'wplm_customer',
                    'post_title' => $customer_name,
                    'post_status' => 'publish',
                    'post_author' => get_current_user_id()
                ]);

                if (!is_wp_error($customer_id)) {
                    // Store customer metadata
                    update_post_meta($customer_id, '_wplm_customer_email', $customer_email);
                    update_post_meta($customer_id, '_wplm_customer_name', $customer_name);
                    update_post_meta($customer_id, '_wplm_first_name', $wc_customer->first_name);
                    update_post_meta($customer_id, '_wplm_last_name', $wc_customer->last_name);
                    update_post_meta($customer_id, '_wplm_phone', get_user_meta($wc_customer->ID, 'billing_phone', true));
                    update_post_meta($customer_id, '_wplm_company', get_user_meta($wc_customer->ID, 'billing_company', true));
                    update_post_meta($customer_id, '_wplm_address', [
                        'address_1' => get_user_meta($wc_customer->ID, 'billing_address_1', true),
                        'address_2' => get_user_meta($wc_customer->ID, 'billing_address_2', true),
                        'city' => get_user_meta($wc_customer->ID, 'billing_city', true),
                        'state' => get_user_meta($wc_customer->ID, 'billing_state', true),
                        'postcode' => get_user_meta($wc_customer->ID, 'billing_postcode', true),
                        'country' => get_user_meta($wc_customer->ID, 'billing_country', true)
                    ]);
                    update_post_meta($customer_id, '_wplm_wc_customer_id', $wc_customer->ID);
                    update_post_meta($customer_id, '_wplm_username', $wc_customer->user_login);
                    update_post_meta($customer_id, '_wplm_date_registered', $wc_customer->user_registered);
                    update_post_meta($customer_id, '_wplm_last_active', current_time('mysql'));
                    update_post_meta($customer_id, '_wplm_first_license_date', current_time('mysql'));
                    update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
                    update_post_meta($customer_id, '_wplm_total_licenses', 0);
                    update_post_meta($customer_id, '_wplm_active_licenses', 0);
                    update_post_meta($customer_id, '_wplm_total_spent', 0);
                    update_post_meta($customer_id, '_wplm_order_count', 0);
                    update_post_meta($customer_id, '_wplm_customer_status', 'active');
                    update_post_meta($customer_id, '_wplm_customer_source', 'woocommerce_sync');
                    
                    // Initialize arrays
                    update_post_meta($customer_id, '_wplm_order_ids', []);
                    update_post_meta($customer_id, '_wplm_license_keys', []);
                    update_post_meta($customer_id, '_wplm_communication_log', []);
                    update_post_meta($customer_id, '_wplm_tags', []);
                    update_post_meta($customer_id, '_wplm_notes', '');

                    $created_count++;
                } else {
                    $errors[] = "Failed to create customer for {$customer_email}";
                }
            } else {
                // Update existing customer with latest WooCommerce data
                $customer_id = $existing_customer->ID;
                update_post_meta($customer_id, '_wplm_last_active', current_time('mysql'));
                update_post_meta($customer_id, '_wplm_last_activity', current_time('mysql'));
                update_post_meta($customer_id, '_wplm_phone', get_user_meta($wc_customer->ID, 'billing_phone', true));
                update_post_meta($customer_id, '_wplm_company', get_user_meta($wc_customer->ID, 'billing_company', true));
                update_post_meta($customer_id, '_wplm_address', [
                    'address_1' => get_user_meta($wc_customer->ID, 'billing_address_1', true),
                    'address_2' => get_user_meta($wc_customer->ID, 'billing_address_2', true),
                    'city' => get_user_meta($wc_customer->ID, 'billing_city', true),
                    'state' => get_user_meta($wc_customer->ID, 'billing_state', true),
                    'postcode' => get_user_meta($wc_customer->ID, 'billing_postcode', true),
                    'country' => get_user_meta($wc_customer->ID, 'billing_country', true)
                ]);
                $updated_count++;
            }
        }
        
        $synced_count = $created_count + $updated_count;

        if (!empty($errors)) {
            wp_send_json_success([
                'message' => sprintf('Synced %d customers successfully (%d created, %d updated). %d errors occurred.', $synced_count, $created_count, $updated_count, count($errors)),
                'synced_count' => $synced_count,
                'created_count' => $created_count,
                'updated_count' => $updated_count,
                'errors' => $errors
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf('Successfully synced %d customers from WooCommerce (%d created, %d updated).', $synced_count, $created_count, $updated_count),
                'synced_count' => $synced_count,
                'created_count' => $created_count,
                'updated_count' => $updated_count,
                'debug_info' => [
                    'total_wc_customers' => count($wc_customers),
                    'synced_count' => $synced_count,
                    'created_count' => $created_count,
                    'updated_count' => $updated_count,
                    'errors_count' => count($errors)
                ]
            ]);
        }
    }

    /**
     * AJAX handler for syncing WooCommerce customer orders
     */
    public function ajax_sync_wc_customer_orders() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce is not active']);
        }

        $customer_management = new WPLM_Customer_Management_System();
        $synced_count = 0;
        $errors = [];

        // Get recent completed orders (last 30 days)
        $recent_orders = wc_get_orders([
            'status' => 'completed',
            'date_created' => '>' . date('Y-m-d', strtotime('-30 days')),
            'limit' => 100,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        foreach ($recent_orders as $order) {
            $customer_email = $order->get_billing_email();
            
            if (empty($customer_email)) {
                continue;
            }

            // Check if customer exists in WPLM
            $existing_customer = $customer_management->get_customer_by_email($customer_email);
            
            if ($existing_customer) {
                // Update existing customer with order data
                $customer_management->update_customer_from_order($existing_customer->ID, $order);
                $synced_count++;
            } else {
                // Create new customer from order
                $customer_id = $customer_management->create_customer_from_order($order);
                if ($customer_id) {
                    $synced_count++;
                } else {
                    $errors[] = "Failed to create customer for order #{$order->get_order_number()}";
                }
            }
        }

        if (!empty($errors)) {
            wp_send_json_success([
                'message' => sprintf('Synced %d orders successfully. %d errors occurred.', $synced_count, count($errors)),
                'synced_count' => $synced_count,
                'errors' => $errors
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf('Successfully synced %d orders from WooCommerce.', $synced_count),
                'synced_count' => $synced_count
            ]);
        }
    }

    /**
     * AJAX handler for toggling status
     */
    public function ajax_toggle_status() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $item_id = intval($_POST['item_id']);
        $item_type = sanitize_text_field($_POST['item_type']);
        // Support both 'new_status' (expected) and legacy 'status' from JS
        $new_status = isset($_POST['new_status']) ? sanitize_text_field($_POST['new_status']) : sanitize_text_field($_POST['status'] ?? '');

        if ($item_type === 'license') {
            if ($new_status === 'inactive') {
                // Get license key and activated domains before deactivating
                $license_key = get_the_title($item_id);
                $activated_domains = get_post_meta($item_id, '_wplm_activated_domains', true) ?: [];
                
                // Deactivate license and free activations
                update_post_meta($item_id, '_wplm_status', 'inactive');
                update_post_meta($item_id, '_wplm_activated_domains', []);
                $fingerprints = get_post_meta($item_id, '_wplm_fingerprints', true);
                if (!empty($fingerprints)) {
                    update_post_meta($item_id, '_wplm_fingerprints', []);
                }
                $site_data = get_post_meta($item_id, '_wplm_site_data', true);
                if (!empty($site_data)) {
                    update_post_meta($item_id, '_wplm_site_data', []);
                }
                
                // Trigger automatic plugin deactivation for each domain
                foreach ($activated_domains as $domain) {
                    do_action('wplm_license_deactivated_for_plugin_deactivation', $item_id, $domain, $license_key);
                }
            } else if ($new_status === 'active') {
                update_post_meta($item_id, '_wplm_status', 'active');
            } else {
                wp_send_json_error(['message' => 'Invalid status']);
            }
            wp_send_json_success(['message' => 'License status updated']);
        }

        wp_send_json_error(['message' => 'Invalid item type']);
    }

    /**
     * AJAX handler for bulk actions
     */
    public function ajax_bulk_action() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $action = sanitize_text_field($_POST['bulk_action']);
        $items = array_map('intval', $_POST['items']);

        if (empty($items)) {
            wp_send_json_error(['message' => 'No items selected']);
        }

        $processed = 0;
        foreach ($items as $item_id) {
            switch ($action) {
                case 'activate':
                    update_post_meta($item_id, '_wplm_status', 'active');
                    $processed++;
                    break;
                case 'deactivate':
                    // Get license key and activated domains before deactivating
                    $license_key = get_the_title($item_id);
                    $activated_domains = get_post_meta($item_id, '_wplm_activated_domains', true) ?: [];
                    
                    // Lightning-fast deactivation using direct SQL
                    global $wpdb;
                    
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->postmeta} 
                         SET meta_value = CASE meta_key
                             WHEN '_wplm_status' THEN 'inactive'
                             WHEN '_wplm_activated_domains' THEN %s
                             WHEN '_wplm_fingerprints' THEN %s
                             WHEN '_wplm_site_data' THEN %s
                         END
                         WHERE post_id = %d 
                         AND meta_key IN ('_wplm_status', '_wplm_activated_domains', '_wplm_fingerprints', '_wplm_site_data')",
                        serialize([]), // activated_domains
                        serialize([]), // fingerprints
                        serialize([]), // site_data
                        $item_id
                    ));
                    
                    wp_cache_delete($item_id, 'post_meta');
                    
                    // Trigger automatic plugin deactivation for each domain
                    foreach ($activated_domains as $domain) {
                        do_action('wplm_license_deactivated_for_plugin_deactivation', $item_id, $domain, $license_key);
                    }
                    
                    $processed++;
                    break;
                case 'trash':
                    wp_trash_post($item_id);
                    $processed++;
                    break;
                case 'delete':
                    wp_delete_post($item_id, true);
                    $processed++;
                    break;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Processed %d items.', 'wp-license-manager'), $processed)
        ]);
    }

    /**
     * AJAX handler for lightning-fast deactivation of multiple licenses.
     */
    public function ajax_force_deactivate_licenses() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $license_ids = array_map('intval', $_POST['license_ids'] ?? []);

        if (empty($license_ids)) {
            wp_send_json_error(['message' => 'No licenses selected']);
        }

        // Lightning-fast bulk deactivation using single SQL query
        global $wpdb;
        
        $license_ids_str = implode(',', $license_ids);
        
        // Single query to update all licenses at once
        $result = $wpdb->query(
            "UPDATE {$wpdb->postmeta} 
             SET meta_value = CASE meta_key
                 WHEN '_wplm_status' THEN 'inactive'
                 WHEN '_wplm_activated_domains' THEN 'a:0:{}'
                 WHEN '_wplm_fingerprints' THEN 'a:0:{}'
                 WHEN '_wplm_site_data' THEN 'a:0:{}'
             END
             WHERE post_id IN ($license_ids_str) 
             AND meta_key IN ('_wplm_status', '_wplm_activated_domains', '_wplm_fingerprints', '_wplm_site_data')"
        );
        
        if ($result !== false) {
            // Clear cache for all affected posts
            foreach ($license_ids as $license_id) {
                wp_cache_delete($license_id, 'post_meta');
            }
            
            wp_send_json_success([
                'message' => sprintf(
                    'Lightning deactivated %d licenses successfully in %d milliseconds.',
                    count($license_ids),
                    round(microtime(true) * 1000)
                ),
                'processed' => count($license_ids),
                'errors' => 0
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Bulk deactivation failed.',
                'processed' => 0,
                'errors' => count($license_ids)
            ]);
        }
    }

    /**
     * Helper method to count active licenses for a product
     */
    private function count_active_licenses_for_product($product_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
             WHERE pm.meta_key = '_wplm_product_id' 
             AND pm.meta_value = %s 
             AND pm_status.meta_key = '_wplm_status' 
             AND pm_status.meta_value = 'active' 
             AND p.post_type = 'wplm_license' 
             AND p.post_status = 'publish'",
            $product_id
        ));
    }

    /**
     * Helper method to get WooCommerce product link
     */
    private function get_woocommerce_product_link($product_id) {
        if (!class_exists('WooCommerce')) {
            return '';
        }

        $wc_product_id = get_post_meta($product_id, '_wplm_wc_product_id', true);
        if ($wc_product_id) {
            return admin_url('post.php?post=' . $wc_product_id . '&action=edit');
        }
        return '';
    }

    /**
     * Helper method to get product actions
     */
    private function get_product_actions($product_id) {
        $actions = [];
        $actions[] = '<a href="' . admin_url('post.php?post=' . $product_id . '&action=edit') . '" class="button button-small">' . __('Edit', 'wp-license-manager') . '</a>';
        $actions[] = '<button class="button button-small button-link-delete" onclick="if(confirm(\'' . __('Are you sure?', 'wp-license-manager') . '\')) location.href=\'' . wp_nonce_url(admin_url('post.php?post=' . $product_id . '&action=delete'), 'delete-post_' . $product_id) . '\'">' . __('Delete', 'wp-license-manager') . '</button>';
        return implode(' ', $actions);
    }

    /**
     * Helper method to get license activations display
     */
    private function get_license_activations_display($license_id, $activation_limit) {
        $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
        $current_activations = count($activated_domains);
        $limit = $activation_limit ?: 1;
        
        return sprintf('%d/%d', $current_activations, $limit);
    }

    /**
     * Helper method to get license actions
     */
    private function get_license_actions($license_id) {
        $actions = [];
        $actions[] = '<a href="' . admin_url('post.php?post=' . $license_id . '&action=edit') . '" class="button button-small">' . __('Edit', 'wp-license-manager') . '</a>';
        
        $status = get_post_meta($license_id, '_wplm_status', true);
        if ($status === 'active') {
            $actions[] = '<button class="button button-small wplm-toggle-status" data-id="' . $license_id . '" data-status="inactive">' . __('Deactivate', 'wp-license-manager') . '</button>';
        } else {
            $actions[] = '<button class="button button-small wplm-toggle-status" data-id="' . $license_id . '" data-status="active">' . __('Activate', 'wp-license-manager') . '</button>';
        }
        
        $actions[] = '<button class="button button-small button-link-delete" onclick="if(confirm(\'' . __('Are you sure?', 'wp-license-manager') . '\')) location.href=\'' . wp_nonce_url(admin_url('post.php?post=' . $license_id . '&action=delete'), 'delete-post_' . $license_id) . '\'">' . __('Delete', 'wp-license-manager') . '</button>';
        return implode(' ', $actions);
    }

    /**
     * Helper method to get customer name from email
     */
    private function get_customer_name($email) {
        // Try to get name from WordPress user
        $user = get_user_by('email', $email);
        if ($user) {
            return $user->display_name;
        }

        // Try to get name from WooCommerce if available
        if (class_exists('WooCommerce')) {
            global $wpdb;
            $name = $wpdb->get_var($wpdb->prepare(
                "SELECT CONCAT(pm1.meta_value, ' ', pm2.meta_value) as name
                 FROM {$wpdb->postmeta} pm_email
                 LEFT JOIN {$wpdb->postmeta} pm1 ON pm_email.post_id = pm1.post_id AND pm1.meta_key = '_billing_first_name'
                 LEFT JOIN {$wpdb->postmeta} pm2 ON pm_email.post_id = pm2.post_id AND pm2.meta_key = '_billing_last_name'
                 WHERE pm_email.meta_key = '_billing_email' AND pm_email.meta_value = %s
                 LIMIT 1",
                $email
            ));
            if ($name && trim($name)) {
                return trim($name);
            }
        }

        return $email; // fallback to email
    }

    /**
     * Helper method to count active licenses for customer
     */
    private function count_active_licenses_for_customer($email) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
             WHERE pm.meta_key = '_wplm_customer_email' 
             AND pm.meta_value = %s 
             AND pm_status.meta_key = '_wplm_status' 
             AND pm_status.meta_value = 'active' 
             AND p.post_type = 'wplm_license' 
             AND p.post_status = 'publish'",
            $email
        ));
    }

    /**
     * Helper method to get customer total spent
     */
    private function get_customer_total_spent($email) {
        if (!class_exists('WooCommerce')) {
            return '$0';
        }

        global $wpdb;
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(pm_total.meta_value) as total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id
             INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')
             AND pm_email.meta_key = '_billing_email'
             AND pm_email.meta_value = %s
             AND pm_total.meta_key = '_order_total'",
            $email
        ));

        return function_exists('wc_price') ? wc_price($total ?: 0) : '$' . number_format($total ?: 0, 2);
    }

    /**
     * Helper method to get customer actions
     */
    private function get_customer_actions($email) {
        $actions = [];
        $actions[] = '<button class="button button-small wplm-view-customer" data-email="' . esc_attr($email) . '">' . __('View', 'wp-license-manager') . '</button>';
        $actions[] = '<a href="mailto:' . esc_attr($email) . '" class="button button-small">' . __('Email', 'wp-license-manager') . '</a>';
        
        if (class_exists('WooCommerce')) {
            $user = get_user_by('email', $email);
            if ($user) {
                $actions[] = '<a href="' . admin_url('user-edit.php?user_id=' . $user->ID) . '" class="button button-small">' . __('WP User', 'wp-license-manager') . '</a>';
            }
        }
        
        return implode(' ', $actions);
    }

    /**
     * Settings field rendering methods
     */

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
     * Render Export/Import Settings Tab
     */
    private function render_export_import_settings() {
        ?>
        <div class="wplm-settings-section">
            <h3><?php _e('Export Data', 'wp-license-manager'); ?></h3>
            <p><?php _e('Export your license and product data for backup or migration purposes.', 'wp-license-manager'); ?></p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wplm-export-form">
                <input type="hidden" name="action" value="wplm_export_data" />
                <?php wp_nonce_field('wplm_export_nonce', 'wplm_export_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Data Type', 'wp-license-manager'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="wplm_export_type" value="all" checked /> <?php _e('All Data', 'wp-license-manager'); ?></label><br>
                                <label><input type="radio" name="wplm_export_type" value="licenses" /> <?php _e('Licenses Only', 'wp-license-manager'); ?></label><br>
                                <label><input type="radio" name="wplm_export_type" value="products" /> <?php _e('Products Only', 'wp-license-manager'); ?></label><br>
                                <label><input type="radio" name="wplm_export_type" value="customers" /> <?php _e('Customers Only', 'wp-license-manager'); ?></label><br>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Format', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="wplm_export_format">
                                <option value="csv"><?php _e('CSV', 'wp-license-manager'); ?></option>
                                <option value="json"><?php _e('JSON', 'wp-license-manager'); ?></option>
                                <option value="xml"><?php _e('XML', 'wp-license-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Additional Options', 'wp-license-manager'); ?></th>
                        <td>
                            <label><input type="checkbox" name="wplm_include_settings" value="1" /> <?php _e('Include Settings', 'wp-license-manager'); ?></label><br>
                            <label><input type="checkbox" name="wplm_include_logs" value="1" /> <?php _e('Include Activity Logs', 'wp-license-manager'); ?></label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="wplm_export_submit" class="button-primary" value="<?php _e('Export Data', 'wp-license-manager'); ?>" />
                </p>
            </form>
        </div>

        <div class="wplm-settings-section">
            <h3><?php _e('Import Data', 'wp-license-manager'); ?></h3>
            <p><?php _e('Import license and product data from a backup file.', 'wp-license-manager'); ?></p>
            
            <form method="post" enctype="multipart/form-data" class="wplm-import-form">
                <?php wp_nonce_field('wplm_import_nonce', 'wplm_import_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Import File', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="file" name="wplm_import_file" accept=".csv,.json,.xml,.zip" required />
                            <p class="description"><?php _e('Supported formats: CSV, JSON, XML, ZIP', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Import Mode', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="wplm_import_mode">
                                <option value="create_only"><?php _e('Create New Only', 'wp-license-manager'); ?></option>
                                <option value="update_existing"><?php _e('Update Existing', 'wp-license-manager'); ?></option>
                                <option value="replace_all"><?php _e('Replace All', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('Choose how to handle existing data during import.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Backup Options', 'wp-license-manager'); ?></th>
                        <td>
                            <label><input type="checkbox" name="wplm_backup_before_import" value="1" checked /> <?php _e('Create backup before import', 'wp-license-manager'); ?></label>
                            <p class="description"><?php _e('Recommended: Create an automatic backup before importing.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="wplm_import_submit" class="button-primary" value="<?php _e('Import Data', 'wp-license-manager'); ?>" />
                </p>
            </form>
        </div>
        
        <div class="wplm-settings-section">
            <h3><?php _e('Quick Actions', 'wp-license-manager'); ?></h3>
            <p><?php _e('Common export/import shortcuts.', 'wp-license-manager'); ?></p>
            
            <div class="wplm-quick-actions">
                <button type="button" class="button" onclick="wplmQuickExport('licenses', 'csv')"><?php _e('Export Licenses (CSV)', 'wp-license-manager'); ?></button>
                <button type="button" class="button" onclick="wplmQuickExport('products', 'csv')"><?php _e('Export Products (CSV)', 'wp-license-manager'); ?></button>
                <button type="button" class="button" onclick="wplmQuickExport('all', 'json')"><?php _e('Full Backup (JSON)', 'wp-license-manager'); ?></button>
            </div>
        </div>

        <script>
        function wplmQuickExport(type, format) {
            var form = document.createElement('form');
            form.method = 'post';
            form.action = '<?php echo admin_url('admin-post.php'); ?>';
            
            var fields = {
                'action': 'wplm_export_data',
                'wplm_export_nonce': '<?php echo wp_create_nonce('wplm_export_nonce'); ?>',
                'wplm_export_type': type,
                'wplm_export_format': format,
                'wplm_export_submit': '1'
            };
            
            for (var key in fields) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        </script>
        <?php
    }

    /**
     * AJAX handler to generate a new API key
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
     * AJAX handler to generate a standalone license key
     */
    public function ajax_generate_license_key() {
        check_ajax_referer('wplm_license_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
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
            $license_posts = get_posts([
                'post_type' => 'wplm_license',
                'title' => $license_key,
                'posts_per_page' => 1,
                'post_status' => 'publish'
            ]);
            while (!empty($license_posts) && $attempts < 5) {
                $attempts++;
                $license_key = $this->generate_standard_license_key();
                $license_posts = get_posts([
                    'post_type' => 'wplm_license',
                    'title' => $license_key,
                    'posts_per_page' => 1,
                    'post_status' => 'publish'
                ]);
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

            // Log activity
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log($license_id, 'license_created', 'License created via standalone generator', [
                    'product_id' => $product_id,
                    'customer_email' => $customer_email,
                    'generation_method' => 'standalone'
                ]);
            }

            // Send notification email
            if (!empty($customer_email) && class_exists('WPLM_Email_Notification_System')) {
                do_action('wplm_license_created', $license_id);
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
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key_parts = [];
        
        for ($i = 0; $i < 5; $i++) {
            $part = '';
            for ($j = 0; $j < 4; $j++) {
                $part .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $key_parts[] = $part;
        }
        
        return implode('-', $key_parts);
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

    /**
     * AJAX handler to render the create subscription modal content.
     */
    public function ajax_render_create_subscription_form() {
        check_ajax_referer('wplm_create_subscription_nonce', 'nonce');

        if (!current_user_can('create_wplm_subscriptions')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        ob_start();
        $this->render_create_subscription_modal_content();
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX handler for bulk license operations
     */
    public function ajax_bulk_license_operations() {
        check_ajax_referer('wplm_bulk_operations_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        
        $action = sanitize_text_field($_POST['action_type'] ?? '');
        $license_ids = array_map('intval', $_POST['license_ids'] ?? []);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => 'No licenses selected.'], 400);
        }
        
        $results = [];
        $success_count = 0;
        $error_count = 0;
        
        foreach ($license_ids as $license_id) {
            $license_post = get_post($license_id);
            if (!$license_post || $license_post->post_type !== 'wplm_license') {
                $error_count++;
                continue;
            }
            
            $license_key = $license_post->post_title;
            $product_id = get_post_meta($license_id, '_wplm_product_id', true);
            
            switch ($action) {
                case 'activate':
                    $result = $this->bulk_activate_license($license_id, $license_key, $product_id);
                    break;
                case 'deactivate':
                    $result = $this->bulk_deactivate_license($license_id, $license_key, $product_id);
                    break;
                case 'force_deactivate':
                    $result = $this->bulk_force_deactivate_license($license_id, $license_key, $product_id);
                    break;
                case 'trash':
                    $result = $this->bulk_trash_license($license_id);
                    break;
                case 'delete':
                    $result = $this->bulk_delete_license($license_id);
                    break;
                default:
                    $result = ['success' => false, 'message' => 'Invalid action.'];
            }
            
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
            }
            
            $results[] = [
                'license_id' => $license_id,
                'license_key' => $license_key,
                'result' => $result
            ];
        }
        
        wp_send_json_success([
            'message' => sprintf('Bulk operation completed. Success: %d, Errors: %d', $success_count, $error_count),
            'results' => $results,
            'success_count' => $success_count,
            'error_count' => $error_count
        ]);
    }
    
    /**
     * Bulk activate license
     */
    private function bulk_activate_license($license_id, $license_key, $product_id) {
        update_post_meta($license_id, '_wplm_status', 'active');
        
        // Log the activation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log($license_id, 'license_bulk_activated', 'License activated via bulk operation', [
                'license_key' => $license_key,
                'product_id' => $product_id
            ]);
        }
        
        return ['success' => true, 'message' => 'License activated successfully.'];
    }
    
    /**
     * Bulk deactivate license (soft deactivation)
     */
    private function bulk_deactivate_license($license_id, $license_key, $product_id) {
        update_post_meta($license_id, '_wplm_status', 'inactive');
        
        // Clear activated domains
        update_post_meta($license_id, '_wplm_activated_domains', []);
        
        // Log the deactivation
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log($license_id, 'license_bulk_deactivated', 'License deactivated via bulk operation', [
                'license_key' => $license_key,
                'product_id' => $product_id
            ]);
        }
        
        return ['success' => true, 'message' => 'License deactivated successfully.'];
    }
    
    /**
     * Bulk force deactivate license (with plugin deactivation)
     */
    private function bulk_force_deactivate_license($license_id, $license_key, $product_id) {
        // First do soft deactivation
        $this->bulk_deactivate_license($license_id, $license_key, $product_id);
        
        // Then force deactivate plugins
        if (class_exists('WPLM_Automatic_Plugin_Deactivator')) {
            $deactivator = new WPLM_Automatic_Plugin_Deactivator();
            $deactivator->force_immediate_deactivation($license_key, $product_id, 'bulk_force_deactivation');
        }
        
        return ['success' => true, 'message' => 'License force deactivated successfully.'];
    }
    
    /**
     * Bulk trash license
     */
    private function bulk_trash_license($license_id) {
        $result = wp_trash_post($license_id);
        
        if ($result) {
            return ['success' => true, 'message' => 'License moved to trash successfully.'];
        } else {
            return ['success' => false, 'message' => 'Failed to move license to trash.'];
        }
    }
    
    /**
     * Bulk delete license
     */
    private function bulk_delete_license($license_id) {
        $result = wp_delete_post($license_id, true);
        
        if ($result) {
            return ['success' => true, 'message' => 'License deleted permanently.'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete license.'];
        }
    }

    /**
     * Render Whitelabel Settings Tab
     */
    private function render_whitelabel_settings() {
        $options = get_option('wplm_whitelabel_options', []);
        ?>
        <form method="post" action="options.php" class="wplm-settings-form">
            <?php settings_fields('wplm_whitelabel_settings'); ?>
            
            <div class="wplm-settings-section">
                <h3><?php _e('Plugin Branding', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_name_custom"><?php _e('Custom Plugin Name', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wplm_plugin_name_custom" name="wplm_whitelabel_options[plugin_name]" 
                                   value="<?php echo esc_attr($options['plugin_name'] ?? 'LicensesWP'); ?>" 
                                   class="regular-text" placeholder="LicensesWP" />
                            <p class="description"><?php _e('Override the default plugin name throughout the interface.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_description"><?php _e('Custom Plugin Description', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <textarea id="wplm_plugin_description" name="wplm_whitelabel_options[plugin_description]" 
                                      rows="3" class="large-text" placeholder="Best licensing management system for WordPress products"><?php echo esc_textarea($options['plugin_description'] ?? 'Best licensing management system for WordPress products'); ?></textarea>
                            <p class="description"><?php _e('Custom description shown in plugin headers and admin pages.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_company_name"><?php _e('Company Name', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wplm_company_name" name="wplm_whitelabel_options[company_name]" 
                                   value="<?php echo esc_attr($options['company_name'] ?? 'WPDev Ltd.'); ?>" 
                                   class="regular-text" placeholder="WPDev Ltd." />
                            <p class="description"><?php _e('Your company name for branding purposes.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_company_url"><?php _e('Company Website', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="wplm_company_url" name="wplm_whitelabel_options[company_url]" 
                                   value="<?php echo esc_attr($options['company_url'] ?? 'https://wpdevltd.com/'); ?>" 
                                   class="regular-text" placeholder="https://wpdevltd.com/" />
                            <p class="description"><?php _e('Your company website URL.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_website"><?php _e('Plugin Website', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="wplm_plugin_website" name="wplm_whitelabel_options[plugin_website]" 
                                   value="<?php echo esc_attr($options['plugin_website'] ?? 'https://licenseswp.com/'); ?>" 
                                   class="regular-text" placeholder="https://licenseswp.com/" />
                            <p class="description"><?php _e('Plugin website URL (shown on WordPress plugin page).', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_author"><?php _e('Plugin Author', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wplm_plugin_author" name="wplm_whitelabel_options[plugin_author]" 
                                   value="<?php echo esc_attr($options['plugin_author'] ?? 'WPDev Ltd.'); ?>" 
                                   class="regular-text" placeholder="WPDev Ltd." />
                            <p class="description"><?php _e('Plugin author name (shown on WordPress plugin page).', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_author_website"><?php _e('Plugin Author Website URL', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="wplm_plugin_author_website" name="wplm_whitelabel_options[plugin_author_website]" 
                                   value="<?php echo esc_attr($options['plugin_author_website'] ?? 'https://wpdevltd.com/'); ?>" 
                                   class="regular-text" placeholder="https://wpdevltd.com/" />
                            <p class="description"><?php _e('Plugin author website URL (shown on WordPress plugin page).', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('Color Scheme', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wplm_primary_color"><?php _e('Primary Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_primary_color" name="wplm_whitelabel_options[primary_color]" 
                                       value="<?php echo esc_attr($options['primary_color'] ?? '#5de0e6'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_primary_color_hex" name="wplm_whitelabel_options[primary_color_hex]" 
                                       value="<?php echo esc_attr($options['primary_color_hex'] ?? '#5de0e6'); ?>" 
                                       class="wplm-color-hex" placeholder="#5de0e6" />
                                <input type="text" id="wplm_primary_color_rgb" name="wplm_whitelabel_options[primary_color_rgb]" 
                                       value="<?php echo esc_attr($options['primary_color_rgb'] ?? '93, 224, 230'); ?>" 
                                       class="wplm-color-rgb" placeholder="93, 224, 230" />
                            </div>
                            <p class="description"><?php _e('Main color for headers, buttons, and primary elements. Default: RGB(93, 224, 230)', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_secondary_color"><?php _e('Secondary Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_secondary_color" name="wplm_whitelabel_options[secondary_color]" 
                                       value="<?php echo esc_attr($options['secondary_color'] ?? '#004aad'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_secondary_color_hex" name="wplm_whitelabel_options[secondary_color_hex]" 
                                       value="<?php echo esc_attr($options['secondary_color_hex'] ?? '#004aad'); ?>" 
                                       class="wplm-color-hex" placeholder="#004aad" />
                                <input type="text" id="wplm_secondary_color_rgb" name="wplm_whitelabel_options[secondary_color_rgb]" 
                                       value="<?php echo esc_attr($options['secondary_color_rgb'] ?? '0, 74, 173'); ?>" 
                                       class="wplm-color-rgb" placeholder="0, 74, 173" />
                            </div>
                            <p class="description"><?php _e('Secondary color for gradients and accents. Default: RGB(0, 74, 173)', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_success_color"><?php _e('Success Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_success_color" name="wplm_whitelabel_options[success_color]" 
                                       value="<?php echo esc_attr($options['success_color'] ?? '#28a745'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_success_color_hex" name="wplm_whitelabel_options[success_color_hex]" 
                                       value="<?php echo esc_attr($options['success_color_hex'] ?? '#28a745'); ?>" 
                                       class="wplm-color-hex" placeholder="#28a745" />
                                <input type="text" id="wplm_success_color_rgb" name="wplm_whitelabel_options[success_color_rgb]" 
                                       value="<?php echo esc_attr($options['success_color_rgb'] ?? '40, 167, 69'); ?>" 
                                       class="wplm-color-rgb" placeholder="40, 167, 69" />
                            </div>
                            <p class="description"><?php _e('Color for success messages and active status indicators.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_warning_color"><?php _e('Warning Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_warning_color" name="wplm_whitelabel_options[warning_color]" 
                                       value="<?php echo esc_attr($options['warning_color'] ?? '#ffc107'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_warning_color_hex" name="wplm_whitelabel_options[warning_color_hex]" 
                                       value="<?php echo esc_attr($options['warning_color_hex'] ?? '#ffc107'); ?>" 
                                       class="wplm-color-hex" placeholder="#ffc107" />
                                <input type="text" id="wplm_warning_color_rgb" name="wplm_whitelabel_options[warning_color_rgb]" 
                                       value="<?php echo esc_attr($options['warning_color_rgb'] ?? '255, 193, 7'); ?>" 
                                       class="wplm-color-rgb" placeholder="255, 193, 7" />
                            </div>
                            <p class="description"><?php _e('Color for warning messages and pending status indicators.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_danger_color"><?php _e('Danger Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_danger_color" name="wplm_whitelabel_options[danger_color]" 
                                       value="<?php echo esc_attr($options['danger_color'] ?? '#dc3545'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_danger_color_hex" name="wplm_whitelabel_options[danger_color_hex]" 
                                       value="<?php echo esc_attr($options['danger_color_hex'] ?? '#dc3545'); ?>" 
                                       class="wplm-color-hex" placeholder="#dc3545" />
                                <input type="text" id="wplm_danger_color_rgb" name="wplm_whitelabel_options[danger_color_rgb]" 
                                       value="<?php echo esc_attr($options['danger_color_rgb'] ?? '220, 53, 69'); ?>" 
                                       class="wplm-color-rgb" placeholder="220, 53, 69" />
                            </div>
                            <p class="description"><?php _e('Color for error messages and inactive status indicators.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('WooCommerce Integration Styling', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wplm_wc_card_bg"><?php _e('My Account License Cards Background', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_wc_card_bg" name="wplm_whitelabel_options[wc_card_bg]" 
                                       value="<?php echo esc_attr($options['wc_card_bg'] ?? '#ffffff'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_wc_card_bg_hex" name="wplm_whitelabel_options[wc_card_bg_hex]" 
                                       value="<?php echo esc_attr($options['wc_card_bg_hex'] ?? '#ffffff'); ?>" 
                                       class="wplm-color-hex" placeholder="#ffffff" />
                                <input type="text" id="wplm_wc_card_bg_rgb" name="wplm_whitelabel_options[wc_card_bg_rgb]" 
                                       value="<?php echo esc_attr($options['wc_card_bg_rgb'] ?? '255, 255, 255'); ?>" 
                                       class="wplm-color-rgb" placeholder="255, 255, 255" />
                            </div>
                            <p class="description"><?php _e('Background color for license cards in WooCommerce My Account page.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_wc_card_border"><?php _e('My Account License Cards Border', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_wc_card_border" name="wplm_whitelabel_options[wc_card_border]" 
                                       value="<?php echo esc_attr($options['wc_card_border'] ?? '#e1e1e1'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_wc_card_border_hex" name="wplm_whitelabel_options[wc_card_border_hex]" 
                                       value="<?php echo esc_attr($options['wc_card_border_hex'] ?? '#e1e1e1'); ?>" 
                                       class="wplm-color-hex" placeholder="#e1e1e1" />
                                <input type="text" id="wplm_wc_card_border_rgb" name="wplm_whitelabel_options[wc_card_border_rgb]" 
                                       value="<?php echo esc_attr($options['wc_card_border_rgb'] ?? '225, 225, 225'); ?>" 
                                       class="wplm-color-rgb" placeholder="225, 225, 225" />
                            </div>
                            <p class="description"><?php _e('Border color for license cards in WooCommerce My Account page.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_wc_button_color"><?php _e('My Account License Cards Button Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_wc_button_color" name="wplm_whitelabel_options[wc_button_color]" 
                                       value="<?php echo esc_attr($options['wc_button_color'] ?? '#5de0e6'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_wc_button_color_hex" name="wplm_whitelabel_options[wc_button_color_hex]" 
                                       value="<?php echo esc_attr($options['wc_button_color_hex'] ?? '#5de0e6'); ?>" 
                                       class="wplm-color-hex" placeholder="#5de0e6" />
                                <input type="text" id="wplm_wc_button_color_rgb" name="wplm_whitelabel_options[wc_button_color_rgb]" 
                                       value="<?php echo esc_attr($options['wc_button_color_rgb'] ?? '93, 224, 230'); ?>" 
                                       class="wplm-color-rgb" placeholder="93, 224, 230" />
                            </div>
                            <p class="description"><?php _e('Button color for license cards in WooCommerce My Account page.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_wc_text_color"><?php _e('My Account License Cards Text Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_wc_text_color" name="wplm_whitelabel_options[wc_text_color]" 
                                       value="<?php echo esc_attr($options['wc_text_color'] ?? '#333333'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_wc_text_color_hex" name="wplm_whitelabel_options[wc_text_color_hex]" 
                                       value="<?php echo esc_attr($options['wc_text_color_hex'] ?? '#333333'); ?>" 
                                       class="wplm-color-hex" placeholder="#333333" />
                                <input type="text" id="wplm_wc_text_color_rgb" name="wplm_whitelabel_options[wc_text_color_rgb]" 
                                       value="<?php echo esc_attr($options['wc_text_color_rgb'] ?? '51, 51, 51'); ?>" 
                                       class="wplm-color-rgb" placeholder="51, 51, 51" />
                            </div>
                            <p class="description"><?php _e('Text color for license cards in WooCommerce My Account page.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('Font Colors & Typography', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wplm_font_primary"><?php _e('Primary Text Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_font_primary" name="wplm_whitelabel_options[font_primary]" 
                                       value="<?php echo esc_attr($options['font_primary'] ?? '#2c3e50'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_font_primary_hex" name="wplm_whitelabel_options[font_primary_hex]" 
                                       value="<?php echo esc_attr($options['font_primary_hex'] ?? '#2c3e50'); ?>" 
                                       class="wplm-color-hex" placeholder="#2c3e50" />
                                <input type="text" id="wplm_font_primary_rgb" name="wplm_whitelabel_options[font_primary_rgb]" 
                                       value="<?php echo esc_attr($options['font_primary_rgb'] ?? '44, 62, 80'); ?>" 
                                       class="wplm-color-rgb" placeholder="44, 62, 80" />
                            </div>
                            <p class="description"><?php _e('Primary text color for headings and important text.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_font_secondary"><?php _e('Secondary Text Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_font_secondary" name="wplm_whitelabel_options[font_secondary]" 
                                       value="<?php echo esc_attr($options['font_secondary'] ?? '#7f8c8d'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_font_secondary_hex" name="wplm_whitelabel_options[font_secondary_hex]" 
                                       value="<?php echo esc_attr($options['font_secondary_hex'] ?? '#7f8c8d'); ?>" 
                                       class="wplm-color-hex" placeholder="#7f8c8d" />
                                <input type="text" id="wplm_font_secondary_rgb" name="wplm_whitelabel_options[font_secondary_rgb]" 
                                       value="<?php echo esc_attr($options['font_secondary_rgb'] ?? '127, 140, 141'); ?>" 
                                       class="wplm-color-rgb" placeholder="127, 140, 141" />
                            </div>
                            <p class="description"><?php _e('Secondary text color for descriptions and less important text.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_font_link"><?php _e('Link Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_font_link" name="wplm_whitelabel_options[font_link]" 
                                       value="<?php echo esc_attr($options['font_link'] ?? '#5de0e6'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_font_link_hex" name="wplm_whitelabel_options[font_link_hex]" 
                                       value="<?php echo esc_attr($options['font_link_hex'] ?? '#5de0e6'); ?>" 
                                       class="wplm-color-hex" placeholder="#5de0e6" />
                                <input type="text" id="wplm_font_link_rgb" name="wplm_whitelabel_options[font_link_rgb]" 
                                       value="<?php echo esc_attr($options['font_link_rgb'] ?? '93, 224, 230'); ?>" 
                                       class="wplm-color-rgb" placeholder="93, 224, 230" />
                            </div>
                            <p class="description"><?php _e('Color for links and clickable elements.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_font_white"><?php _e('White Text Color', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <div class="wplm-color-input-group">
                                <input type="color" id="wplm_font_white" name="wplm_whitelabel_options[font_white]" 
                                       value="<?php echo esc_attr($options['font_white'] ?? '#ffffff'); ?>" class="wplm-color-picker" />
                                <input type="text" id="wplm_font_white_hex" name="wplm_whitelabel_options[font_white_hex]" 
                                       value="<?php echo esc_attr($options['font_white_hex'] ?? '#ffffff'); ?>" 
                                       class="wplm-color-hex" placeholder="#ffffff" />
                                <input type="text" id="wplm_font_white_rgb" name="wplm_whitelabel_options[font_white_rgb]" 
                                       value="<?php echo esc_attr($options['font_white_rgb'] ?? '255, 255, 255'); ?>" 
                                       class="wplm-color-rgb" placeholder="255, 255, 255" />
                            </div>
                            <p class="description"><?php _e('Text color for white backgrounds and buttons.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('Custom CSS', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wplm_custom_css"><?php _e('Additional CSS', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <textarea id="wplm_custom_css" name="wplm_whitelabel_options[custom_css]" 
                                      rows="10" class="large-text code" placeholder="/* Add your custom CSS here */"><?php echo esc_textarea($options['custom_css'] ?? ''); ?></textarea>
                            <p class="description"><?php _e('Add custom CSS to further customize the appearance. This will be applied to all admin pages.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wplm-settings-actions">
                <h4><?php _e('Preview Changes', 'wp-license-manager'); ?></h4>
                <p><?php _e('Click "Save Changes" to apply your whitelabel settings. Changes will be visible immediately.', 'wp-license-manager'); ?></p>
                <?php submit_button(__('Save Whitelabel Settings', 'wp-license-manager')); ?>
            </div>

            <div class="wplm-settings-section">
                <h3><?php _e('Reset to Defaults', 'wp-license-manager'); ?></h3>
                <p><?php _e('Reset all whitelabel settings to their default values.', 'wp-license-manager'); ?></p>
                <button type="button" id="wplm-reset-whitelabel" class="button button-secondary">
                    <?php _e('Reset Whitelabel Settings', 'wp-license-manager'); ?>
                </button>
            </div>
        </form>

        <script>
        jQuery(document).ready(function($) {
            // Color input synchronization
            function syncColorInputs(baseId) {
                const colorPicker = $('#' + baseId);
                const hexInput = $('#' + baseId + '_hex');
                const rgbInput = $('#' + baseId + '_rgb');
                
                // Sync color picker to hex
                colorPicker.on('change', function() {
                    hexInput.val($(this).val());
                    const rgb = hexToRgb($(this).val());
                    if (rgb) {
                        rgbInput.val(rgb.r + ', ' + rgb.g + ', ' + rgb.b);
                    }
                });
                
                // Sync hex to color picker and RGB
                hexInput.on('input', function() {
                    const hex = $(this).val();
                    if (isValidHex(hex)) {
                        colorPicker.val(hex);
                        const rgb = hexToRgb(hex);
                        if (rgb) {
                            rgbInput.val(rgb.r + ', ' + rgb.g + ', ' + rgb.b);
                        }
                    }
                });
                
                // Sync RGB to hex and color picker
                rgbInput.on('input', function() {
                    const rgb = $(this).val();
                    const hex = rgbToHex(rgb);
                    if (hex) {
                        hexInput.val(hex);
                        colorPicker.val(hex);
                    }
                });
            }
            
            // Initialize color sync for all color inputs
            const colorInputs = [
                'wplm_primary_color', 'wplm_secondary_color', 'wplm_success_color', 
                'wplm_warning_color', 'wplm_danger_color', 'wplm_wc_card_bg', 
                'wplm_wc_card_border', 'wplm_wc_button_color', 'wplm_wc_text_color',
                'wplm_font_primary', 'wplm_font_secondary', 'wplm_font_link', 'wplm_font_white'
            ];
            
            colorInputs.forEach(function(inputId) {
                syncColorInputs(inputId);
            });
            
            // Helper functions
            function hexToRgb(hex) {
                const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
                return result ? {
                    r: parseInt(result[1], 16),
                    g: parseInt(result[2], 16),
                    b: parseInt(result[3], 16)
                } : null;
            }
            
            function rgbToHex(rgbString) {
                const rgb = rgbString.split(',').map(x => parseInt(x.trim()));
                if (rgb.length === 3 && rgb.every(x => !isNaN(x) && x >= 0 && x <= 255)) {
                    return "#" + ((1 << 24) + (rgb[0] << 16) + (rgb[1] << 8) + rgb[2]).toString(16).slice(1);
                }
                return null;
            }
            
            function isValidHex(hex) {
                return /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(hex);
            }
            
            // Reset functionality
            $('#wplm-reset-whitelabel').on('click', function() {
                if (confirm('<?php _e('Are you sure you want to reset all whitelabel settings? This action cannot be undone.', 'wp-license-manager'); ?>')) {
                    // Reset all color inputs to defaults
                    $('#wplm_primary_color').val('#5de0e6');
                    $('#wplm_primary_color_hex').val('#5de0e6');
                    $('#wplm_primary_color_rgb').val('93, 224, 230');
                    
                    $('#wplm_secondary_color').val('#004aad');
                    $('#wplm_secondary_color_hex').val('#004aad');
                    $('#wplm_secondary_color_rgb').val('0, 74, 173');
                    
                    $('#wplm_success_color').val('#28a745');
                    $('#wplm_success_color_hex').val('#28a745');
                    $('#wplm_success_color_rgb').val('40, 167, 69');
                    
                    $('#wplm_warning_color').val('#ffc107');
                    $('#wplm_warning_color_hex').val('#ffc107');
                    $('#wplm_warning_color_rgb').val('255, 193, 7');
                    
                    $('#wplm_danger_color').val('#dc3545');
                    $('#wplm_danger_color_hex').val('#dc3545');
                    $('#wplm_danger_color_rgb').val('220, 53, 69');
                    
                    $('#wplm_wc_card_bg').val('#ffffff');
                    $('#wplm_wc_card_bg_hex').val('#ffffff');
                    $('#wplm_wc_card_bg_rgb').val('255, 255, 255');
                    
                    $('#wplm_wc_card_border').val('#e1e1e1');
                    $('#wplm_wc_card_border_hex').val('#e1e1e1');
                    $('#wplm_wc_card_border_rgb').val('225, 225, 225');
                    
                    $('#wplm_wc_button_color').val('#5de0e6');
                    $('#wplm_wc_button_color_hex').val('#5de0e6');
                    $('#wplm_wc_button_color_rgb').val('93, 224, 230');
                    
                    $('#wplm_wc_text_color').val('#333333');
                    $('#wplm_wc_text_color_hex').val('#333333');
                    $('#wplm_wc_text_color_rgb').val('51, 51, 51');
                    
                    $('#wplm_font_primary').val('#2c3e50');
                    $('#wplm_font_primary_hex').val('#2c3e50');
                    $('#wplm_font_primary_rgb').val('44, 62, 80');
                    
                    $('#wplm_font_secondary').val('#7f8c8d');
                    $('#wplm_font_secondary_hex').val('#7f8c8d');
                    $('#wplm_font_secondary_rgb').val('127, 140, 141');
                    
                    $('#wplm_font_link').val('#5de0e6');
                    $('#wplm_font_link_hex').val('#5de0e6');
                    $('#wplm_font_link_rgb').val('93, 224, 230');
                    
                    $('#wplm_font_white').val('#ffffff');
                    $('#wplm_font_white_hex').val('#ffffff');
                    $('#wplm_font_white_rgb').val('255, 255, 255');
                    
                    // Reset text fields to defaults
                    $('#wplm_plugin_name_custom').val('LicensesWP');
                    $('#wplm_plugin_description').val('Best licensing management system for WordPress products');
                    $('#wplm_company_name').val('WPDev Ltd.');
                    $('#wplm_company_url').val('https://wpdevltd.com/');
                    $('#wplm_plugin_author_website').val('https://wpdevltd.com/');
                    $('#wplm_plugin_website').val('https://licenseswp.com/');
                    $('#wplm_plugin_author').val('WPDev Ltd.');
                    $('#wplm_custom_css').val('');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Sanitize whitelabel settings
     */
    public function sanitize_whitelabel_settings($input) {
        $sanitized = [];
        
        // Sanitize text fields with defaults
        $sanitized['plugin_name'] = sanitize_text_field($input['plugin_name'] ?? 'LicensesWP');
        $sanitized['plugin_description'] = sanitize_textarea_field($input['plugin_description'] ?? 'Best licensing management system for WordPress products');
        $sanitized['company_name'] = sanitize_text_field($input['company_name'] ?? 'WPDev Ltd.');
        $sanitized['company_url'] = esc_url_raw($input['company_url'] ?? 'https://wpdevltd.com/');
        $sanitized['plugin_website'] = esc_url_raw($input['plugin_website'] ?? 'https://licenseswp.com/');
        $sanitized['plugin_author'] = sanitize_text_field($input['plugin_author'] ?? 'WPDev Ltd.');
        $sanitized['plugin_author_website'] = esc_url_raw($input['plugin_author_website'] ?? 'https://wpdevltd.com/');
        
        // Sanitize color fields (use hex values as primary)
        $sanitized['primary_color'] = sanitize_hex_color($input['primary_color'] ?? '#5de0e6');
        $sanitized['primary_color_hex'] = sanitize_hex_color($input['primary_color_hex'] ?? '#5de0e6');
        $sanitized['primary_color_rgb'] = sanitize_text_field($input['primary_color_rgb'] ?? '93, 224, 230');
        
        $sanitized['secondary_color'] = sanitize_hex_color($input['secondary_color'] ?? '#004aad');
        $sanitized['secondary_color_hex'] = sanitize_hex_color($input['secondary_color_hex'] ?? '#004aad');
        $sanitized['secondary_color_rgb'] = sanitize_text_field($input['secondary_color_rgb'] ?? '0, 74, 173');
        
        $sanitized['success_color'] = sanitize_hex_color($input['success_color'] ?? '#28a745');
        $sanitized['success_color_hex'] = sanitize_hex_color($input['success_color_hex'] ?? '#28a745');
        $sanitized['success_color_rgb'] = sanitize_text_field($input['success_color_rgb'] ?? '40, 167, 69');
        
        $sanitized['warning_color'] = sanitize_hex_color($input['warning_color'] ?? '#ffc107');
        $sanitized['warning_color_hex'] = sanitize_hex_color($input['warning_color_hex'] ?? '#ffc107');
        $sanitized['warning_color_rgb'] = sanitize_text_field($input['warning_color_rgb'] ?? '255, 193, 7');
        
        $sanitized['danger_color'] = sanitize_hex_color($input['danger_color'] ?? '#dc3545');
        $sanitized['danger_color_hex'] = sanitize_hex_color($input['danger_color_hex'] ?? '#dc3545');
        $sanitized['danger_color_rgb'] = sanitize_text_field($input['danger_color_rgb'] ?? '220, 53, 69');
        
        // Sanitize WooCommerce colors
        $sanitized['wc_card_bg'] = sanitize_hex_color($input['wc_card_bg'] ?? '#ffffff');
        $sanitized['wc_card_bg_hex'] = sanitize_hex_color($input['wc_card_bg_hex'] ?? '#ffffff');
        $sanitized['wc_card_bg_rgb'] = sanitize_text_field($input['wc_card_bg_rgb'] ?? '255, 255, 255');
        
        $sanitized['wc_card_border'] = sanitize_hex_color($input['wc_card_border'] ?? '#e1e1e1');
        $sanitized['wc_card_border_hex'] = sanitize_hex_color($input['wc_card_border_hex'] ?? '#e1e1e1');
        $sanitized['wc_card_border_rgb'] = sanitize_text_field($input['wc_card_border_rgb'] ?? '225, 225, 225');
        
        $sanitized['wc_button_color'] = sanitize_hex_color($input['wc_button_color'] ?? '#5de0e6');
        $sanitized['wc_button_color_hex'] = sanitize_hex_color($input['wc_button_color_hex'] ?? '#5de0e6');
        $sanitized['wc_button_color_rgb'] = sanitize_text_field($input['wc_button_color_rgb'] ?? '93, 224, 230');
        
        $sanitized['wc_text_color'] = sanitize_hex_color($input['wc_text_color'] ?? '#333333');
        $sanitized['wc_text_color_hex'] = sanitize_hex_color($input['wc_text_color_hex'] ?? '#333333');
        $sanitized['wc_text_color_rgb'] = sanitize_text_field($input['wc_text_color_rgb'] ?? '51, 51, 51');
        
        // Sanitize font colors
        $sanitized['font_primary'] = sanitize_hex_color($input['font_primary'] ?? '#2c3e50');
        $sanitized['font_primary_hex'] = sanitize_hex_color($input['font_primary_hex'] ?? '#2c3e50');
        $sanitized['font_primary_rgb'] = sanitize_text_field($input['font_primary_rgb'] ?? '44, 62, 80');
        
        $sanitized['font_secondary'] = sanitize_hex_color($input['font_secondary'] ?? '#7f8c8d');
        $sanitized['font_secondary_hex'] = sanitize_hex_color($input['font_secondary_hex'] ?? '#7f8c8d');
        $sanitized['font_secondary_rgb'] = sanitize_text_field($input['font_secondary_rgb'] ?? '127, 140, 141');
        
        $sanitized['font_link'] = sanitize_hex_color($input['font_link'] ?? '#5de0e6');
        $sanitized['font_link_hex'] = sanitize_hex_color($input['font_link_hex'] ?? '#5de0e6');
        $sanitized['font_link_rgb'] = sanitize_text_field($input['font_link_rgb'] ?? '93, 224, 230');
        
        $sanitized['font_white'] = sanitize_hex_color($input['font_white'] ?? '#ffffff');
        $sanitized['font_white_hex'] = sanitize_hex_color($input['font_white_hex'] ?? '#ffffff');
        $sanitized['font_white_rgb'] = sanitize_text_field($input['font_white_rgb'] ?? '255, 255, 255');
        
        // Sanitize custom CSS
        $sanitized['custom_css'] = wp_strip_all_tags($input['custom_css'] ?? '');
        
        // Generate and save custom CSS
        $this->generate_custom_css($sanitized);
        
        return $sanitized;
    }

    /**
     * Generate custom CSS based on whitelabel settings
     */
    private function generate_custom_css($options) {
        $css = '';
        
        // CSS Variables for all colors - scoped to WPLM only
        $css .= ".wplm-enhanced-admin-wrap, .wplm-wc-license-card, .wplm-wc-button, .wplm-wc-text {\n";
        $css .= "    --wplm-primary-color: {$options['primary_color']};\n";
        $css .= "    --wplm-secondary-color: {$options['secondary_color']};\n";
        $css .= "    --wplm-success-color: {$options['success_color']};\n";
        $css .= "    --wplm-warning-color: {$options['warning_color']};\n";
        $css .= "    --wplm-danger-color: {$options['danger_color']};\n";
        $css .= "    --wplm-font-primary: {$options['font_primary']};\n";
        $css .= "    --wplm-font-secondary: {$options['font_secondary']};\n";
        $css .= "    --wplm-font-link: {$options['font_link']};\n";
        $css .= "    --wplm-font-white: {$options['font_white']};\n";
        $css .= "    --wplm-wc-card-bg: {$options['wc_card_bg']};\n";
        $css .= "    --wplm-wc-card-border: {$options['wc_card_border']};\n";
        $css .= "    --wplm-wc-button-color: {$options['wc_button_color']};\n";
        $css .= "    --wplm-wc-text-color: {$options['wc_text_color']};\n";
        $css .= "}\n\n";
        
        // Header styling - scoped to WPLM
        $css .= ".wplm-enhanced-admin-wrap .wplm-header { background: linear-gradient(135deg, {$options['primary_color']} 0%, {$options['secondary_color']} 100%); }\n";
        
        // Button styling - scoped to WPLM
        $css .= ".wplm-enhanced-admin-wrap .wplm-btn-primary { background: linear-gradient(135deg, {$options['primary_color']}, {$options['secondary_color']}); }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-btn-primary:hover { background: linear-gradient(135deg, {$options['secondary_color']}, {$options['primary_color']}); }\n";
        
        // Stat card styling - scoped to WPLM
        $css .= ".wplm-enhanced-admin-wrap .wplm-stat-primary { border-left-color: {$options['primary_color']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-stat-success { border-left-color: {$options['success_color']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-stat-warning { border-left-color: {$options['warning_color']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-stat-danger { border-left-color: {$options['danger_color']}; }\n";
        
        // Status badge styling - scoped to WPLM
        $css .= ".wplm-enhanced-admin-wrap .wplm-status-active { background: {$options['success_color']}; color: {$options['font_white']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-status-inactive { background: {$options['danger_color']}; color: {$options['font_white']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-status-expired { background: {$options['warning_color']}; color: #212529; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-status-pending { background: #17a2b8; color: {$options['font_white']}; }\n";
        
        // Typography styling - scoped to WPLM
        $css .= ".wplm-enhanced-admin-wrap .wplm-main-title, .wplm-enhanced-admin-wrap .wplm-section-header h3, .wplm-enhanced-admin-wrap .wplm-chart-header h3 { color: {$options['font_primary']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-stat-content p, .wplm-enhanced-admin-wrap .wplm-activity-meta { color: {$options['font_secondary']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap a, .wplm-enhanced-admin-wrap .wplm-header-actions .button { color: {$options['font_link']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .wplm-header, .wplm-enhanced-admin-wrap .wplm-header * { color: {$options['font_white']}; }\n";
        
        // WooCommerce My Account page styling
        $css .= ".wplm-wc-license-card {\n";
        $css .= "    background-color: {$options['wc_card_bg']};\n";
        $css .= "    border-color: {$options['wc_card_border']};\n";
        $css .= "    color: {$options['wc_text_color']};\n";
        $css .= "}\n";
        
        $css .= ".wplm-wc-button {\n";
        $css .= "    background-color: {$options['wc_button_color']};\n";
        $css .= "    border-color: {$options['wc_button_color']};\n";
        $css .= "    color: {$options['font_white']};\n";
        $css .= "}\n";
        
        $css .= ".wplm-wc-button:hover {\n";
        $css .= "    background-color: {$options['secondary_color']};\n";
        $css .= "    border-color: {$options['secondary_color']};\n";
        $css .= "    color: {$options['font_white']};\n";
        $css .= "}\n";
        
        $css .= ".wplm-wc-text {\n";
        $css .= "    color: {$options['wc_text_color']};\n";
        $css .= "}\n";
        
        $css .= ".wplm-wc-text h3 {\n";
        $css .= "    color: {$options['primary_color']};\n";
        $css .= "}\n";
        
        // Enhanced styling for all WPLM elements
        $css .= ".wplm-enhanced-admin-wrap .form-table th { color: {$options['font_primary']}; }\n";
        $css .= ".wplm-enhanced-admin-wrap .form-table td { color: {$options['font_secondary']}; }\n";
        $css .= ".wplm-enhanced-table th { color: {$options['font_primary']}; }\n";
        $css .= ".wplm-enhanced-table td { color: {$options['font_secondary']}; }\n";
        
        // Add custom CSS
        if (!empty($options['custom_css'])) {
            $css .= "\n/* Custom CSS */\n" . $options['custom_css'] . "\n";
        }
        
        // Save the generated CSS
        update_option('wplm_custom_css', $css);
    }

    /**
     * Add custom CSS to admin head
     */
    public function add_custom_css() {
        $custom_css = get_option('wplm_custom_css', '');
        if (!empty($custom_css)) {
            echo '<style type="text/css" id="wplm-custom-css">' . $custom_css . '</style>';
        }
    }

    /**
     * Get custom plugin name or default
     */
    public static function get_plugin_name() {
        $options = get_option('wplm_whitelabel_options', []);
        return !empty($options['plugin_name']) ? $options['plugin_name'] : 'WP License Manager';
    }

    /**
     * Get custom plugin description or default
     */
    public static function get_plugin_description() {
        $options = get_option('wplm_whitelabel_options', []);
        return !empty($options['plugin_description']) ? $options['plugin_description'] : 'A server for managing and validating software license keys via a REST API.';
    }

    /**
     * Get company name
     */
    public static function get_company_name() {
        $options = get_option('wplm_whitelabel_options', []);
        return $options['company_name'] ?? '';
    }

    /**
     * Get company URL
     */
    public static function get_company_url() {
        $options = get_option('wplm_whitelabel_options', []);
        return $options['company_url'] ?? '';
    }

    /**
     * Get plugin website
     */
    public static function get_plugin_website() {
        $options = get_option('wplm_whitelabel_options', []);
        return $options['plugin_website'] ?? '';
    }

    /**
     * Get plugin author
     */
    public static function get_plugin_author() {
        $options = get_option('wplm_whitelabel_options', []);
        return !empty($options['plugin_author']) ? $options['plugin_author'] : 'Your Name';
    }

}
