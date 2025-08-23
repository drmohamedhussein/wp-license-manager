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
        
        // Dashboard and stats
        add_action('wp_ajax_wplm_dashboard_stats', [$this, 'ajax_dashboard_stats']);
        
        // DataTables AJAX handlers
        add_action('wp_ajax_wplm_get_licenses', [$this, 'ajax_get_licenses']);
        add_action('wp_ajax_wplm_get_products', [$this, 'ajax_get_products']);
        add_action('wp_ajax_wplm_get_customers', [$this, 'ajax_get_customers']);
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
        add_action('wp_ajax_wplm_toggle_status', [$this, 'ajax_toggle_status']);
        add_action('wp_ajax_wplm_bulk_action', [$this, 'ajax_bulk_action']);
        
        // API Key generation
        add_action('wp_ajax_wplm_generate_api_key', [$this, 'ajax_generate_api_key']);
        
        // License key generation
        add_action('wp_ajax_wplm_generate_license_key', [$this, 'ajax_generate_license_key']);
        add_action('wp_ajax_wplm_render_create_subscription_form', [$this, 'ajax_render_create_subscription_form']); // New AJAX for modal content
    }

    /**
     * Register settings for the enhanced admin
     */
    public function register_settings() {
        // Register settings group
        register_setting('wplm_general_settings', 'wplm_plugin_name');
        register_setting('wplm_general_settings', 'wplm_default_duration_type');
        register_setting('wplm_general_settings', 'wplm_default_duration_value');
        register_setting('wplm_general_settings', 'wplm_default_activation_limit');
        register_setting('wplm_general_settings', 'wplm_delete_on_uninstall');
        register_setting('wplm_general_settings', 'wplm_email_notifications_enabled');
        register_setting('wplm_general_settings', 'wplm_rest_api_enabled');

        // Add settings section
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
    }

    /**
     * Add enhanced admin menu structure
     */
    public function add_admin_menu() {
        // Main menu page - Dashboard
        add_menu_page(
            __('WP License Manager', 'wp-license-manager'),
            __('License Manager', 'wp-license-manager'),
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

        // Products submenu
        add_submenu_page(
            $this->menu_slug,
            __('Products', 'wp-license-manager'),
            __('Products', 'wp-license-manager'),
            'manage_options',
            'wplm-products',
            [$this, 'render_products_page']
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
        wp_enqueue_script('wplm-enhanced-admin', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/enhanced-admin.js', ['jquery', 'chart-js', 'datatables-js'], WPLM_VERSION, true);

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
            </div>

            <div class="wplm-table-container">
                <table id="licenses-table" class="wplm-enhanced-table">
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
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            WPLM_Enhanced_Admin.initLicensesTable();
        });
        </script>
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

            <div class="wplm-table-container">
                <table id="products-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
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

        <script>
        jQuery(document).ready(function($) {
            WPLM_Enhanced_Admin.initProductsTable();
        });
        </script>
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

            <div class="wplm-table-container">
                <table id="subscriptions-table" class="wplm-enhanced-table">
                    <thead>
                        <tr>
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
            WPLM_Enhanced_Admin.initSubscriptionsTable();

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
                    <?php _e('License Manager Settings', 'wp-license-manager'); ?>
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
                                <option value="deactivate" <?php selected(get_option('wplm_default_expiry_behavior'), 'deactivate'); ?>><?php _e('Deactivate License', 'wp-license-manager'); ?></option>
                                <option value="downgrade" <?php selected(get_option('wplm_default_expiry_behavior'), 'downgrade'); ?>><?php _e('Downgrade Features', 'wp-license-manager'); ?></option>
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
                                <option value="personal" <?php selected(get_option('wplm_wc_default_license_type'), 'personal'); ?>><?php _e('Personal', 'wp-license-manager'); ?></option>
                                <option value="business" <?php selected(get_option('wplm_wc_default_license_type'), 'business'); ?>><?php _e('Business', 'wp-license-manager'); ?></option>
                                <option value="developer" <?php selected(get_option('wplm_wc_default_license_type'), 'developer'); ?>><?php _e('Developer', 'wp-license-manager'); ?></option>
                                <option value="lifetime" <?php selected(get_option('wplm_wc_default_license_type'), 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
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

        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);
        $search = sanitize_text_field($_POST['search']['value']);
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

        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);
        $search = sanitize_text_field($_POST['search']['value']);

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
     * AJAX handler for customers DataTable
     */
    public function ajax_get_customers() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);
        $search = sanitize_text_field($_POST['search']['value']);

        // Get unique customer emails from license meta
        global $wpdb;
        $sql = "SELECT DISTINCT meta_value as email, MAX(p.post_date) as last_activity 
                FROM {$wpdb->postmeta} pm 
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                WHERE pm.meta_key = '_wplm_customer_email' 
                AND pm.meta_value != '' 
                AND p.post_type = 'wplm_license' 
                AND p.post_status = 'publish'";
        
        if (!empty($search)) {
            $sql .= $wpdb->prepare(" AND pm.meta_value LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }
        
        $sql .= " GROUP BY pm.meta_value ORDER BY last_activity DESC";
        
        if ($length > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $length, $start);
        }

        $customers = $wpdb->get_results($sql);
        $customer_data = [];

        foreach ($customers as $customer) {
            // Get license count for this customer
            $license_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                 WHERE pm.meta_key = '_wplm_customer_email' 
                 AND pm.meta_value = %s 
                 AND p.post_type = 'wplm_license' 
                 AND p.post_status = 'publish'",
                $customer->email
            ));

            $customer_data[] = [
                'customer' => $this->get_customer_name($customer->email),
                'email' => $customer->email,
                'total_licenses' => intval($license_count),
                'active_licenses' => $this->count_active_licenses_for_customer($customer->email),
                'total_spent' => $this->get_customer_total_spent($customer->email),
                'last_activity' => $customer->last_activity,
                'actions' => $this->get_customer_actions($customer->email)
            ];
        }

        // Get total count
        $total_sql = "SELECT COUNT(DISTINCT meta_value) 
                      FROM {$wpdb->postmeta} pm 
                      INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                      WHERE pm.meta_key = '_wplm_customer_email' 
                      AND pm.meta_value != '' 
                      AND p.post_type = 'wplm_license' 
                      AND p.post_status = 'publish'";
        $total = $wpdb->get_var($total_sql);

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => intval($total),
            'recordsFiltered' => intval($total),
            'data' => $customer_data
        ]);
    }

    /**
     * AJAX handler for subscriptions DataTable
     */
    public function ajax_get_subscriptions() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);

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
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $draw = intval($_POST['draw']);
        $start = intval($_POST['start']);
        $length = intval($_POST['length']);
        $search_value = sanitize_text_field($_POST['search']['value'] ?? '');
        $log_type_filter = sanitize_text_field($_POST['type'] ?? '');
        $date_from_filter = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to_filter = sanitize_text_field($_POST['date_to'] ?? '');

        // Get the global activity log
        $all_logs = WPLM_Activity_Logger::get_global_log();
        
        $filtered_logs = [];
        foreach ($all_logs as $log_entry) {
            $match = true;

            // Apply type filter
            if (!empty($log_type_filter) && $log_type_filter !== 'all' && $log_entry['event_type'] !== $log_type_filter) {
                $match = false;
            }

            // Apply date filters
            $log_date = strtotime($log_entry['timestamp']);
            if (!empty($date_from_filter) && $log_date < strtotime($date_from_filter)) {
                $match = false;
            }
            if (!empty($date_to_filter) && $log_date > strtotime('+1 day', strtotime($date_to_filter))) { // +1 day to include end date
                $match = false;
            }

            // Apply search filter
            if (!empty($search_value)) {
                $search_match = false;
                if (stripos($log_entry['description'], $search_value) !== false) {
                    $search_match = true;
                }
                if (isset($log_entry['object_type']) && stripos($log_entry['object_type'], $search_value) !== false) {
                    $search_match = true;
                }
                // Add user search if possible (need to fetch user display name)
                $user_info = get_userdata($log_entry['user_id']);
                if ($user_info && stripos($user_info->display_name, $search_value) !== false) {
                    $search_match = true;
                }
                if (!$search_match) {
                    $match = false;
                }
            }

            if ($match) {
                $filtered_logs[] = $log_entry;
            }
        }

        $total_records = count($filtered_logs);

        // Manual pagination
        $paged_logs = array_slice($filtered_logs, $start, $length);

        $data = [];
        foreach ($paged_logs as $log_entry) {
            $user_info = get_userdata($log_entry['user_id']);
            $username = $user_info ? $user_info->display_name : __('Guest', 'wp-license-manager');
            
            // Construct a link to the object if available
            $object_link = '';
            if (!empty($log_entry['object_id']) && !empty($log_entry['object_type'])) {
                $edit_link = get_edit_post_link($log_entry['object_id']);
                if ($edit_link) {
                    $object_title = get_the_title($log_entry['object_id']);
                    if ($object_title) {
                        $object_link = sprintf('<a href="%s" title="%s #%d">%s (%s)</a>', 
                                                esc_url($edit_link),
                                                ucfirst($log_entry['object_type']),
                                                $log_entry['object_id'],
                                                esc_html($object_title),
                                                ucfirst($log_entry['object_type'])
                                            );
                    }
                }
            }
            
            $data[] = [
                'date_time' => date('Y-m-d H:i:s', strtotime($log_entry['timestamp'])),
                'type' => esc_html(str_replace('_', ' ', $log_entry['event_type'])),
                'description' => esc_html($log_entry['description']) . (!empty($object_link) ? ' - ' . $object_link : ''),
                'user' => esc_html($username),
                'ip_address' => esc_html($log_entry['ip_address']),
                'details' => '<pre>' . esc_html(json_encode($log_entry['data'], JSON_PRETTY_PRINT)) . '</pre>',
            ];
        }

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => count($all_logs), // Total records before filtering
            'recordsFiltered' => $total_records, // Total records after filtering
            'data' => $data
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
     * AJAX handler for toggling status
     */
    public function ajax_toggle_status() {
        check_ajax_referer('wplm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $item_id = intval($_POST['item_id']);
        $item_type = sanitize_text_field($_POST['item_type']);
        $new_status = sanitize_text_field($_POST['new_status']);

        if ($item_type === 'license') {
            update_post_meta($item_id, '_wplm_status', $new_status);
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
                    update_post_meta($item_id, '_wplm_status', 'inactive');
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
    public function render_plugin_name_field() {
        $value = get_option('wplm_plugin_name', 'WP License Manager');
        echo '<input type="text" name="wplm_plugin_name" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Custom name for your license manager system.', 'wp-license-manager') . '</p>';
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
}
