<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Admin Manager - Core Functionality
 * Part 1 of the split WPLM_Enhanced_Admin_Manager class
 */
class WPLM_Enhanced_Admin_Manager_Core {

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
        <div class="wrap">
            <h1><?php esc_html_e('Enhanced License Manager Dashboard', 'wp-license-manager'); ?></h1>
            <p><?php esc_html_e('Welcome to the enhanced license management system.', 'wp-license-manager'); ?></p>
            
            <div class="wplm-dashboard-overview">
                <h2><?php esc_html_e('System Overview', 'wp-license-manager'); ?></h2>
                <p><?php esc_html_e('This enhanced system provides advanced licensing features and management capabilities.', 'wp-license-manager'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('License Manager Settings', 'wp-license-manager'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wplm_general_settings');
                do_settings_sections('wplm-general-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render plugin name field
     */
    public function render_plugin_name_field() {
        $value = get_option('wplm_plugin_name', 'WP License Manager');
        ?>
        <input type="text" id="wplm_plugin_name" name="wplm_plugin_name" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('The name of your license management system.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render duration field
     */
    public function render_duration_field() {
        $duration_type = get_option('wplm_default_duration_type', 'days');
        $duration_value = get_option('wplm_default_duration_value', '365');
        ?>
        <input type="number" id="wplm_default_duration_value" name="wplm_default_duration_value" value="<?php echo esc_attr($duration_value); ?>" class="small-text" min="1" />
        <select id="wplm_default_duration_type" name="wplm_default_duration_type">
            <option value="days" <?php selected($duration_type, 'days'); ?>><?php esc_html_e('Days', 'wp-license-manager'); ?></option>
            <option value="weeks" <?php selected($duration_type, 'weeks'); ?>><?php esc_html_e('Weeks', 'wp-license-manager'); ?></option>
            <option value="months" <?php selected($duration_type, 'months'); ?>><?php esc_html_e('Months', 'wp-license-manager'); ?></option>
            <option value="years" <?php selected($duration_type, 'years'); ?>><?php esc_html_e('Years', 'wp-license-manager'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Default license duration for new licenses.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render activation limit field
     */
    public function render_activation_limit_field() {
        $value = get_option('wplm_default_activation_limit', '1');
        ?>
        <input type="number" id="wplm_default_activation_limit" name="wplm_default_activation_limit" value="<?php echo esc_attr($value); ?>" class="small-text" min="1" />
        <p class="description"><?php esc_html_e('Default activation limit for new licenses.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render license key format field
     */
    public function render_license_key_format_field() {
        $value = get_option('wplm_license_key_format', 'XXXX-XXXX-XXXX-XXXX');
        ?>
        <input type="text" id="wplm_license_key_format" name="wplm_license_key_format" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Format for license keys (use X for random characters).', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render email notifications field
     */
    public function render_email_notifications_field() {
        $value = get_option('wplm_email_notifications_enabled', '1');
        ?>
        <label>
            <input type="checkbox" id="wplm_email_notifications_enabled" name="wplm_email_notifications_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Enable email notifications for license events.', 'wp-license-manager'); ?>
        </label>
        <?php
    }

    /**
     * Render REST API field
     */
    public function render_rest_api_field() {
        $value = get_option('wplm_rest_api_enabled', '1');
        ?>
        <label>
            <input type="checkbox" id="wplm_rest_api_enabled" name="wplm_rest_api_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Enable REST API for license validation.', 'wp-license-manager'); ?>
        </label>
        <?php
    }

    /**
     * Render delete on uninstall field
     */
    public function render_delete_on_uninstall_field() {
        $value = get_option('wplm_delete_on_uninstall', '0');
        ?>
        <label>
            <input type="checkbox" id="wplm_delete_on_uninstall" name="wplm_delete_on_uninstall" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Delete all plugin data when uninstalling (WARNING: This cannot be undone).', 'wp-license-manager'); ?>
        </label>
        <?php
    }
}
