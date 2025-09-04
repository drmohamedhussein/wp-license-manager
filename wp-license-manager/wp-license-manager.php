<?php
/**
 * Plugin Name:       WP License Manager
 * Plugin URI:        https://licenseswp.com/
 * Description:       A comprehensive server for managing and validating software license keys via REST API with WooCommerce integration.
 * Version:           2.0.0
 * Author:            WPDev Ltd.
 * Author URI:        https://wpdevltd.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-license-manager
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 * Network:           false
 * Update URI:        https://licenseswp.com/
 */

// Exit if accessed directly, and ensure minimum PHP version.
if (!defined('ABSPATH')) {
    exit;
}

// Emergency deactivation check - if this file exists, deactivate the plugin
if (file_exists(__DIR__ . '/EMERGENCY_DEACTIVATE')) {
    add_action('admin_init', function() {
        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>WP License Manager has been emergency deactivated. Delete the EMERGENCY_DEACTIVATE file to reactivate.</p></div>';
        });
    });
    return;
}

// Minimum PHP version check
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        $message = sprintf(
            __('WP License Manager requires PHP version %s or higher. Your current PHP version is %s. Please upgrade your PHP version.', 'wp-license-manager'),
            '7.4',
            PHP_VERSION
        );
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    });
    return; // Stop plugin execution
}

define('WPLM_VERSION', '2.0.0');
define('WPLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPLM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPLM_PLUGIN_FILE', __FILE__); // Define plugin file for hooks

/**
 * Helper function to replace deprecated get_page_by_title
 */
function wplm_get_post_by_title($title, $post_type = 'post') {
    $query = new WP_Query([
        'post_type' => $post_type,
        'post_status' => 'publish',
        'title' => $title,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ]);
    
    if ($query->have_posts()) {
        return get_post($query->posts[0]);
    }
    
    return null;
}

/**
 * The main plugin class.
 */
final class WP_License_Manager {

    /**
     * The single instance of the class.
     * @var WP_License_Manager
     */
    private static $_instance = null;

    /**
     * Main WP_License_Manager Instance.
     * Ensures only one instance of the class is loaded.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        // Enable error logging for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ini_set('log_errors', 1);
            ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
        }
        
        // Core required files
        $core_files = [
            'includes/class-cpt-manager.php',
            'includes/class-admin-manager.php',
            'includes/class-api-manager.php',
            'includes/cli.php',
        ];

        foreach ($core_files as $file) {
            $file_path = WPLM_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                try {
                    require_once $file_path;
                } catch (Exception $e) {
                    $this->log_error('Failed to include core file: ' . $file . ' - ' . $e->getMessage());
                    // For core files, we need to show an admin notice instead of crashing
                    add_action('admin_notices', function() use ($file, $e) {
                        echo '<div class="notice notice-error"><p>WP License Manager: Failed to load core file ' . esc_html($file) . '. Error: ' . esc_html($e->getMessage()) . '</p></div>';
                    });
                }
            } else {
                $this->log_error('Core file missing: ' . $file);
                add_action('admin_notices', function() use ($file) {
                    echo '<div class="notice notice-error"><p>WP License Manager: Core file missing: ' . esc_html($file) . '. Please reinstall the plugin.</p></div>';
                });
            }
        }

        // Optional enhanced features
        $optional_files = [
            'includes/class-notification-manager.php',
            'includes/class-activity-logger.php',
            'includes/class-subscription-manager.php',
            'includes/class-built-in-subscription-system.php',
            'includes/class-customer-management-system.php',
            'includes/class-orders-management-system.php',
            'includes/class-enhanced-admin-manager.php',
            'includes/class-advanced-licensing.php',
            'includes/class-enhanced-api-manager.php',
            'includes/class-import-export-manager.php',
            'includes/class-rest-api-manager.php',
            'includes/class-analytics-dashboard.php',
            'includes/class-auto-licenser-system.php',
            'includes/class-bulk-operations-manager.php',
            'includes/class-email-notification-system.php',
            // Enhanced Subscription System Classes
            'includes/class-enhanced-subscription-manager.php',
            'includes/class-enhanced-subscription-product.php',
            'includes/class-subscription-synchronizer.php',
            'includes/class-subscription-switcher.php',
            'includes/class-enhanced-subscription-admin.php',
            'includes/class-subscription-frontend.php',
            // New Enhanced Subscription Admin Interface
            'includes/class-subscription-admin-interface.php',
            'includes/class-enhanced-woocommerce-subscription-integration.php',
            'includes/class-enhanced-subscription-management.php',
            // WooCommerce Product Fields and Subscription Integration
            'includes/class-woocommerce-product-fields.php',
            'includes/class-woocommerce-subscription-integration.php',
        // DPMFW Integration Classes
        'includes/class-wplm-dpmfw-database.php',
        'includes/class-wplm-dpmfw-subscription-manager.php',
        'includes/class-wplm-dpmfw-woocommerce-integration.php',
        'includes/class-wplm-dpmfw-helper-functions.php',
        'includes/class-wplm-dpmfw-admin-interface.php',
        
        // Comprehensive Systems
        'includes/class-comprehensive-subscription-system.php',
        'includes/class-comprehensive-product-system.php',
        'includes/class-comprehensive-license-system.php',
        'includes/class-comprehensive-billing-system.php',
        'includes/class-comprehensive-customer-portal.php',
        ];

        foreach ($optional_files as $file) {
            $file_path = WPLM_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                try {
                    require_once $file_path;
                } catch (Exception $e) {
                    $this->log_error('Failed to include optional file: ' . $file . ' - ' . $e->getMessage());
                    // Optional files failing shouldn't crash the plugin
                }
            }
        }
        
        // Include WooCommerce integration if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $wc_files = [
                'includes/class-woocommerce-integration.php',
                'includes/class-woocommerce-variations.php',
                'includes/class-woocommerce-sync.php',
            ];

            foreach ($wc_files as $file) {
                $file_path = WPLM_PLUGIN_DIR . $file;
                if (file_exists($file_path)) {
                    try {
                        require_once $file_path;
                    } catch (Exception $e) {
                        $this->log_error('Failed to include WooCommerce integration file: ' . $file . ' - ' . $e->getMessage());
                        // WooCommerce integration failing shouldn't crash the plugin
                    }
                }
            }
        }
        
        // Include utility scripts
        $utility_files = [
            'fix-product-duplicates.php',
            'update-product-prefixes.php',
            'cleanup-duplicates.php',
            'fix-capabilities.php',
            'debug-woocommerce-integration.php',
        ];
        
        // Include automatic plugin deactivator
        $auto_deactivator_file = WPLM_PLUGIN_DIR . 'includes/class-automatic-plugin-deactivator.php';
        if (file_exists($auto_deactivator_file)) {
            try {
                require_once $auto_deactivator_file;
            } catch (Exception $e) {
                $this->log_error('Failed to include automatic plugin deactivator: ' . $e->getMessage());
            }
        }
        
        // Include PHP 8.0+ compatibility fix
        $compatibility_file = WPLM_PLUGIN_DIR . 'fix-php8-compatibility.php';
        if (file_exists($compatibility_file)) {
            try {
                require_once $compatibility_file;
            } catch (Exception $e) {
                $this->log_error('Failed to include PHP 8.0+ compatibility fix: ' . $e->getMessage());
            }
        }

        foreach ($utility_files as $file) {
            $file_path = WPLM_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                try {
                    require_once $file_path;
                } catch (Exception $e) {
                    $this->log_error('Failed to include utility file: ' . $file . ' - ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init']);
        add_action('init', [$this, 'load_plugin_textdomain_wplm'], 10); // Explicitly hook to init with priority
        register_activation_hook(WPLM_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WPLM_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(WPLM_PLUGIN_FILE, ['WP_License_Manager', 'uninstall']);

        // Schedule license expiry check cron job
        add_action('wplm_check_expiring_licenses_daily', [$this, '_check_expiring_licenses']);
        
        // Add whitelabel filters
        add_filter('plugin_row_meta', [$this, 'filter_plugin_row_meta'], 10, 2);
        add_filter('all_plugins', [$this, 'filter_plugin_data']);
        add_filter('plugin_action_links_' . plugin_basename(WPLM_PLUGIN_FILE), [$this, 'add_plugin_action_links']);
        
        // Additional filter to ensure plugin details link is properly overridden
        add_filter('plugin_row_meta', [$this, 'override_plugin_details_link'], 5, 2);
        
        // Force plugin data refresh on admin_init
        add_action('admin_init', [$this, 'force_plugin_data_refresh'], 1);
        
        // Additional approach: Hook into the specific WordPress function that generates plugin links
        add_filter('plugin_install_action_links', [$this, 'filter_plugin_install_links'], 10, 2);
        
        // JavaScript approach: Override the link after page load
        add_action('admin_footer', [$this, 'add_plugin_details_override_js']);
        
        // Prevent WordPress from checking the wrong repository for updates
        add_filter('http_request_args', [$this, 'prevent_wrong_repo_check'], 10, 2);
    }

    /**
     * Initialize the plugin.
     */
    public function init() {
        try {
            // Initialize core components
            if (class_exists('WPLM_CPT_Manager')) {
                new WPLM_CPT_Manager();
            } else {
                $this->log_error('WPLM_CPT_Manager class not found');
            }
            
            // WPLM_Admin_Manager is now replaced by WPLM_Enhanced_Admin_Manager
            // Only initialize if Enhanced Admin Manager is not available
            if (!class_exists('WPLM_Enhanced_Admin_Manager') && class_exists('WPLM_Admin_Manager')) {
                new WPLM_Admin_Manager(); // Fallback admin manager
            }
            
            if (class_exists('WPLM_API_Manager')) {
                new WPLM_API_Manager();
            } else {
                $this->log_error('WPLM_API_Manager class not found');
            }

        // Initialize optional enhanced components
        if (class_exists('WPLM_Notification_Manager')) {
            new WPLM_Notification_Manager(); // Initialize notification manager
        }
        
        if (class_exists('WPLM_Activity_Logger')) {
            new WPLM_Activity_Logger(); // Initialize activity logger
        }
        
        if (class_exists('WPLM_Subscription_Manager')) {
            new WPLM_Subscription_Manager(); // Initialize subscription manager
        }
        
        if (class_exists('WPLM_Built_In_Subscription_System')) {
            new WPLM_Built_In_Subscription_System(); // Initialize built-in subscription system
        }
        
        if (class_exists('WPLM_Customer_Management_System')) {
            new WPLM_Customer_Management_System(); // Initialize customer management system
        }
        
        if (class_exists('WPLM_Orders_Management_System')) {
            new WPLM_Orders_Management_System(); // Initialize orders management system
        }
        
        if (class_exists('WPLM_Enhanced_Admin_Manager')) {
            new WPLM_Enhanced_Admin_Manager(); // Initialize enhanced admin interface (main menu interface)
        }
        
        if (class_exists('WPLM_Advanced_Licensing')) {
            new WPLM_Advanced_Licensing(); // Initialize advanced licensing system
        }
        
        if (class_exists('WPLM_Enhanced_API_Manager')) {
            new WPLM_Enhanced_API_Manager(); // Initialize enhanced API manager
        }
        
        if (class_exists('WPLM_Import_Export_Manager')) {
            new WPLM_Import_Export_Manager(); // Initialize import/export manager
        }
        
        if (class_exists('WPLM_Email_Notification_System')) {
            new WPLM_Email_Notification_System(); // Initialize email notification system
        }
        
        if (class_exists('WPLM_Bulk_Operations_Manager')) {
            new WPLM_Bulk_Operations_Manager(); // Initialize bulk operations manager
        }
        
        if (class_exists('WPLM_REST_API_Manager')) {
            new WPLM_REST_API_Manager(); // Initialize REST API manager
        }
        
        if (class_exists('WPLM_Analytics_Dashboard')) {
            new WPLM_Analytics_Dashboard(); // Initialize analytics dashboard
        }
        
        if (class_exists('WPLM_Automatic_Plugin_Deactivator')) {
            new WPLM_Automatic_Plugin_Deactivator(); // Initialize automatic plugin deactivator
        }
        
        if (class_exists('WPLM_Auto_Licenser_System')) {
            new WPLM_Auto_Licenser_System(); // Initialize auto licenser system
        }
        
        // Digital downloads system removed - using products instead
        
        // Initialize Enhanced Subscription Admin Interface
        if (class_exists('WPLM_Subscription_Admin_Interface')) {
            new WPLM_Subscription_Admin_Interface(); // Initialize subscription admin interface
        }
        
        // Initialize Enhanced Subscription Management
        if (class_exists('WPLM_Enhanced_Subscription_Management')) {
            new WPLM_Enhanced_Subscription_Management(); // Initialize enhanced subscription management
        }
        
        // Initialize Enhanced WooCommerce Subscription Integration
        if (class_exists('WPLM_Enhanced_WooCommerce_Subscription_Integration')) {
            new WPLM_Enhanced_WooCommerce_Subscription_Integration(); // Initialize enhanced WooCommerce subscription integration
        }
        
        // Initialize WooCommerce Product Fields (only if WooCommerce is active)
        if (class_exists('WPLM_WooCommerce_Product_Fields') && class_exists('WooCommerce')) {
            // Use init hook to ensure WooCommerce is fully loaded
            add_action('init', function() {
                if (class_exists('WooCommerce') && function_exists('woocommerce_wp_checkbox')) {
                    new WPLM_WooCommerce_Product_Fields(); // Initialize WooCommerce product fields
                }
            }, 20);
        }
        
        // Initialize WooCommerce Subscription Integration (only if WooCommerce is active)
        if (class_exists('WPLM_WooCommerce_Subscription_Integration') && class_exists('WooCommerce')) {
            new WPLM_WooCommerce_Subscription_Integration(); // Initialize WooCommerce subscription integration
        }
        
        // Initialize DPMFW Integration Classes
        if (class_exists('WPLM_DPMFW_Database')) {
            new WPLM_DPMFW_Database(); // Initialize DPMFW database integration
        }
        
        if (class_exists('WPLM_DPMFW_Subscription_Manager')) {
            new WPLM_DPMFW_Subscription_Manager(); // Initialize DPMFW subscription manager
        }
        
        if (class_exists('WPLM_DPMFW_WooCommerce_Integration')) {
            new WPLM_DPMFW_WooCommerce_Integration(); // Initialize DPMFW WooCommerce integration
        }
        
        if (class_exists('WPLM_DPMFW_Helper_Functions')) {
            new WPLM_DPMFW_Helper_Functions(); // Initialize DPMFW helper functions
        }

        if (class_exists('WPLM_DPMFW_Admin_Interface')) {
            new WPLM_DPMFW_Admin_Interface(); // Initialize DPMFW admin interface
        }
        
        // Initialize Comprehensive Systems
        if (class_exists('WPLM_Comprehensive_Subscription_System')) {
            new WPLM_Comprehensive_Subscription_System(); // Initialize comprehensive subscription system
        }
        
        if (class_exists('WPLM_Comprehensive_Product_System')) {
            new WPLM_Comprehensive_Product_System(); // Initialize comprehensive product system
        }
        
        if (class_exists('WPLM_Comprehensive_License_System')) {
            new WPLM_Comprehensive_License_System(); // Initialize comprehensive license system
        }
        
        if (class_exists('WPLM_Comprehensive_Billing_System')) {
            new WPLM_Comprehensive_Billing_System(); // Initialize comprehensive billing system
        }
        
        if (class_exists('WPLM_Comprehensive_Customer_Portal')) {
            new WPLM_Comprehensive_Customer_Portal(); // Initialize comprehensive customer portal
        }

        // Initialize WooCommerce integration if WooCommerce is active
        if (class_exists('WooCommerce')) {
            if (class_exists('WPLM_WooCommerce_Integration')) {
                new WPLM_WooCommerce_Integration();
            }
            
            if (class_exists('WPLM_WooCommerce_Variations')) {
                new WPLM_WooCommerce_Variations();
            }
            
            if (class_exists('WPLM_WooCommerce_Sync')) {
                new WPLM_WooCommerce_Sync();
            }
        }
        } catch (Exception $e) {
            $this->log_error('Plugin initialization failed: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>WP License Manager initialization failed: ' . esc_html($e->getMessage()) . '. Please check error logs or create an EMERGENCY_DEACTIVATE file in the plugin directory to deactivate.</p></div>';
            });
        }
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        try {
            // Prevent WooCommerce conflicts during activation
            if (function_exists('add_filter')) {
                add_filter('woocommerce_prevent_automatic_wizard_redirect', '__return_true');
                add_filter('woocommerce_enable_setup_wizard', '__return_false');
                add_filter('woocommerce_show_admin_notice', '__return_false');
            }
            
            // Delete WooCommerce redirect transient
            if (function_exists('delete_transient')) {
                delete_transient('wc_activation_redirect');
                delete_transient('_wc_activation_redirect');
            }
            
            // Add custom capabilities to Administrator role on activation
            $this->add_custom_capabilities();

            // Generate initial API key if one doesn't exist
            if (empty(get_option('wplm_api_key', ''))) {
                if (function_exists('random_bytes')) {
                    $api_key = bin2hex(random_bytes(32)); // Generate a 64-character hex key
                } else {
                    // Fallback for older PHP versions
                    $api_key = md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
                }
                update_option('wplm_api_key', $api_key);
            }

            // Schedule daily event for checking expiring licenses
            if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wplm_check_expiring_licenses_daily')) {
                wp_schedule_event(time(), 'daily', 'wplm_check_expiring_licenses_daily');
            }
            
            // Ensure rewrite rules are flushed (do this last)
            if (function_exists('flush_rewrite_rules')) {
                flush_rewrite_rules();
            }
            
        } catch (Exception $e) {
            // Log error but don't prevent activation
            if (function_exists('error_log')) {
                error_log('WPLM Activation Warning: ' . $e->getMessage());
            }
        }
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Clear scheduled cron job on deactivation
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('wplm_check_expiring_licenses_daily');
        }
        
        // Flush rewrite rules on deactivation
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }

        // Remove custom capabilities from Administrator role on deactivation
        $this->remove_custom_capabilities();
    }

    /**
     * Plugin uninstallation hook.
     * Deletes all plugin data if the option is checked.
     * This method must be static as it's called via register_uninstall_hook.
     */
    public static function uninstall() {
        // Clear scheduled cron job on uninstall
        wp_clear_scheduled_hook('wplm_check_expiring_licenses_daily');

        // Check the option to see if data should be deleted.
        $delete_data = get_option('wplm_delete_on_uninstall', false);

        if ($delete_data) {
            // Delete all license posts
            $licenses = get_posts([
                'post_type' => 'wplm_license',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'post_status' => 'any',
            ]);
            foreach ($licenses as $license_id) {
                wp_delete_post($license_id, true); // true for permanent delete
            }

            // Delete all product posts
            $products = get_posts([
                'post_type' => 'wplm_product',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'post_status' => 'any',
            ]);
            foreach ($products as $product_id) {
                wp_delete_post($product_id, true); // true for permanent delete
            }

            // Delete all plugin options
            delete_option('wplm_api_key');
            delete_option('wplm_delete_on_uninstall');
            delete_option('wplm_export_import_type');

            // Ensure rewrite rules are flushed after deleting CPTs
            flush_rewrite_rules();
        }
    }

    /**
     * Add custom capabilities to Administrator role.
     */
    private function add_custom_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $capabilities = [
                // License Capabilities
                'edit_wplm_license',
                'read_wplm_license',
                'delete_wplm_license',
                'edit_wplm_licenses',
                'read_wplm_licenses',
                'edit_others_wplm_licenses',
                'publish_wplm_licenses',
                'read_private_wplm_licenses',
                'delete_private_wplm_licenses',
                'delete_published_wplm_licenses',
                'delete_others_wplm_licenses',
                'edit_published_wplm_licenses',
                'create_wplm_licenses',
                'delete_wplm_licenses',
                'manage_wplm_licenses', // Added for export/import and general management

                // Product Capabilities
                'edit_wplm_product',
                'read_wplm_product',
                'delete_wplm_product',
                'edit_wplm_products',
                'read_wplm_products',
                'edit_others_wplm_products',
                'publish_wplm_products',
                'read_private_wplm_products',
                'delete_private_wplm_products',
                'delete_published_wplm_products',
                'delete_others_wplm_products',
                'edit_published_wplm_products',
                'create_wplm_products',
                'delete_wplm_products',

                // Subscription Capabilities
                'edit_wplm_subscription',
                'read_wplm_subscription',
                'delete_wplm_subscription',
                'edit_wplm_subscriptions',
                'read_wplm_subscriptions',
                'edit_others_wplm_subscriptions',
                'publish_wplm_subscriptions',
                'read_private_wplm_subscriptions',
                'delete_private_wplm_subscriptions',
                'delete_published_wplm_subscriptions',
                'delete_others_wplm_subscriptions',
                'edit_published_wplm_subscriptions',
                'create_wplm_subscriptions',
                'delete_wplm_subscriptions',
                'manage_wplm_subscriptions',

                // API Key Capability
                'manage_wplm_api_key',
            ];

            foreach ($capabilities as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    /**
     * Remove custom capabilities from Administrator role.
     */
    private function remove_custom_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $capabilities = [
                // License Capabilities
                'edit_wplm_license',
                'read_wplm_license',
                'delete_wplm_license',
                'edit_wplm_licenses',
                'read_wplm_licenses',
                'edit_others_wplm_licenses',
                'publish_wplm_licenses',
                'read_private_wplm_licenses',
                'delete_private_wplm_licenses',
                'delete_published_wplm_licenses',
                'delete_others_wplm_licenses',
                'edit_published_wplm_licenses',
                'create_wplm_licenses',
                'delete_wplm_licenses',
                'manage_wplm_licenses',

                // Product Capabilities
                'edit_wplm_product',
                'read_wplm_product',
                'delete_wplm_product',
                'edit_wplm_products',
                'read_wplm_products',
                'edit_others_wplm_products',
                'publish_wplm_products',
                'read_private_wplm_products',
                'delete_private_wplm_products',
                'delete_published_wplm_products',
                'delete_others_wplm_products',
                'edit_published_wplm_products',
                'create_wplm_products',
                'delete_wplm_products',

                // Subscription Capabilities
                'edit_wplm_subscription',
                'read_wplm_subscription',
                'delete_wplm_subscription',
                'edit_wplm_subscriptions',
                'read_wplm_subscriptions',
                'edit_others_wplm_subscriptions',
                'publish_wplm_subscriptions',
                'read_private_wplm_subscriptions',
                'delete_private_wplm_subscriptions',
                'delete_published_wplm_subscriptions',
                'delete_others_wplm_subscriptions',
                'edit_published_wplm_subscriptions',
                'create_wplm_subscriptions',
                'delete_wplm_subscriptions',
                'manage_wplm_subscriptions',

                // API Key Capability
                'manage_wplm_api_key',
            ];

            foreach ($capabilities as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    /**
     * Log errors for debugging purposes.
     */
    private function log_error($message) {
        if (function_exists('error_log')) {
            error_log('WP License Manager Error: ' . $message);
        }
        
        // Also store in WordPress option for admin viewing
        $errors = get_option('wplm_error_log', []);
        $errors[] = [
            'message' => $message,
            'time' => current_time('mysql'),
            'trace' => wp_debug_backtrace_summary()
        ];
        
        // Keep only last 50 errors
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }
        
        update_option('wplm_error_log', $errors);
    }

    /**
     * Load plugin textdomain for internationalization.
     */
    public function load_plugin_textdomain_wplm() {
        load_plugin_textdomain('wp-license-manager', false, dirname(plugin_basename(WPLM_PLUGIN_FILE)) . '/languages/');
    }

    /**
     * Cron job callback to check for expiring licenses.
     */
    public function _check_expiring_licenses() {
        // Only proceed if required classes exist
        if (!class_exists('WPLM_Notification_Manager') || !class_exists('WPLM_Activity_Logger')) {
            return;
        }
        
        $notifications = new WPLM_Notification_Manager();
        $logger = new WPLM_Activity_Logger();
        $today = current_time('mysql');

        // Get licenses expiring within 7 days or already expired
        $expiring_licenses = new WP_Query([
            'post_type'      => 'wplm_license',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_wplm_status',
                    'value'   => 'active',
                    'compare' => '=',
                ],
                [
                    'key'     => '_wplm_expiry_date',
                    'value'   => '',
                    'compare' => '!=', // Exclude lifetime licenses
                ],
                [
                    'key'     => '_wplm_expiry_date',
                    'value'   => date('Y-m-d', strtotime('+7 days', strtotime($today))),
                    'compare' => '<=',
                    'type'    => 'DATE',
                ],
            ],
            'fields'         => 'ids',
        ]);

        if ($expiring_licenses->have_posts()) {
            foreach ($expiring_licenses->posts as $license_id) {
                $expiry_date = get_post_meta($license_id, '_wplm_expiry_date', true);
                $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
                $license_key = get_the_title($license_id);

                if (empty($customer_email)) {
                    continue; // Skip if no customer email
                }

                // If expired, update status and send final notification
                if (strtotime($expiry_date) < strtotime($today)) {
                    update_post_meta($license_id, '_wplm_status', 'expired');
                    $notifications->send_expiry_notification($customer_email, $license_key, $expiry_date, true);
                    $logger->log($license_id, 'license_expired', 'License ' . $license_key . ' expired and status updated.', ['expiry_date' => $expiry_date]);
                } else {
                    // Send expiring soon notification
                    $notifications->send_expiry_notification($customer_email, $license_key, $expiry_date);
                    $logger->log($license_id, 'license_expiring_soon', 'License ' . $license_key . ' is expiring soon.', ['expiry_date' => $expiry_date]);
                }
            }
        }
    }

    /**
     * Filter plugin row meta to apply whitelabel settings
     */
    public function filter_plugin_row_meta($plugin_meta, $plugin_file) {
        if ($plugin_file === plugin_basename(WPLM_PLUGIN_FILE)) {
            $options = get_option('wplm_whitelabel_options', []);
            
            // Update plugin name in meta if custom name is set
            if (!empty($options['plugin_name'])) {
                foreach ($plugin_meta as $key => $meta) {
                    if (strpos($meta, 'WP License Manager') !== false) {
                        $plugin_meta[$key] = str_replace('WP License Manager', $options['plugin_name'], $meta);
                    }
                }
            }
            
            // Replace "View Details" link with whitelabel website URL
            if (!empty($options['plugin_website'])) {
                foreach ($plugin_meta as $key => $meta) {
                    // Check if this is a "View Details" link
                    if (strpos($meta, 'View Details') !== false && strpos($meta, 'href=') !== false) {
                        // Extract the plugin name for the aria-label
                        $plugin_name = !empty($options['plugin_name']) ? $options['plugin_name'] : 'WP License Manager';
                        
                        // Replace with custom "View Details" link
                        $plugin_meta[$key] = sprintf(
                            '<a href="%s" target="_blank" aria-label="More information about %s">View Details</a>',
                            esc_url($options['plugin_website']),
                            esc_attr($plugin_name)
                        );
                    }
                }
            }
        }
        
        return $plugin_meta;
    }

    /**
     * Override plugin details link specifically for whitelabel website
     */
    public function override_plugin_details_link($plugin_meta, $plugin_file) {
        if ($plugin_file === plugin_basename(WPLM_PLUGIN_FILE)) {
            $options = get_option('wplm_whitelabel_options', []);
            
            // Debug logging (only log once per session to avoid spam)
            if (defined('WP_DEBUG') && WP_DEBUG && !get_transient('wplm_debug_meta_logged_' . $plugin_file)) {
                error_log('WPLM Debug - Plugin file: ' . $plugin_file);
                error_log('WPLM Debug - Whitelabel options: ' . print_r($options, true));
                error_log('WPLM Debug - Plugin meta before: ' . print_r($plugin_meta, true));
                set_transient('wplm_debug_meta_logged_' . $plugin_file, true, HOUR_IN_SECONDS);
            }
            
            // Only proceed if we have a custom website URL
            if (!empty($options['plugin_website'])) {
                $plugin_name = !empty($options['plugin_name']) ? $options['plugin_name'] : 'WP License Manager';
                
                // Look for and replace the "View Details" link
                foreach ($plugin_meta as $key => $meta) {
                    // Check if this contains a "View Details" link
                    if (strpos($meta, 'View Details') !== false) {
                        // Replace with our custom link
                        $plugin_meta[$key] = sprintf(
                            '<a href="%s" target="_blank" aria-label="More information about %s">View Details</a>',
                            esc_url($options['plugin_website']),
                            esc_attr($plugin_name)
                        );
                        
                        // Debug logging (only in debug mode)
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('WPLM: Overriding plugin details link to: ' . $options['plugin_website']);
                            error_log('WPLM Debug - Plugin meta after: ' . print_r($plugin_meta, true));
                        }
                        
                        break; // Only replace the first occurrence
                    }
                }
            } else {
                // Debug logging when no website URL is set
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WPLM Debug - No plugin_website URL found in whitelabel options');
                }
            }
        }
        
        return $plugin_meta;
    }

    /**
     * Force plugin data refresh to ensure whitelabel settings are applied
     */
    public function force_plugin_data_refresh() {
        // Only run on plugins page
        if (!isset($_GET['page']) || $_GET['page'] !== 'plugins.php') {
            return;
        }
        
        // Clear any cached plugin data
        wp_cache_delete('plugins', 'plugins');
        
        // Force WordPress to reload plugin data
        if (function_exists('wp_get_plugins')) {
            wp_get_plugins(true); // Force refresh
        }
        
        // Also clear the plugin data cache for our specific plugin
        $plugin_file = plugin_basename(WPLM_PLUGIN_FILE);
        wp_cache_delete($plugin_file, 'plugin_data');
        
        // Force refresh of our plugin data
        get_plugin_data(WPLM_PLUGIN_FILE, false, true);
    }

    /**
     * Filter plugin install action links (alternative approach)
     */
    public function filter_plugin_install_links($action_links, $plugin) {
        // This filter is not needed for our use case, but keeping it for compatibility
        return $action_links;
    }

    /**
     * Add JavaScript to override plugin details link
     */
    public function add_plugin_details_override_js() {
        // Only run on plugins page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }
        
        $options = get_option('wplm_whitelabel_options', []);
        if (empty($options['plugin_website'])) {
            return;
        }
        
        $plugin_name = !empty($options['plugin_name']) ? $options['plugin_name'] : 'WP License Manager';
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Find our plugin row by multiple methods
            var pluginRow = $('tr[data-plugin*="wp-license-manager"]');
            
            if (pluginRow.length === 0) {
                // Try finding by plugin name
                pluginRow = $('tr').filter(function() {
                    var pluginTitle = $(this).find('td.plugin-title strong').text();
                    return pluginTitle.indexOf('<?php echo esc_js($plugin_name); ?>') !== -1 || 
                           pluginTitle.indexOf('WP License Manager') !== -1;
                });
            }
            
            if (pluginRow.length === 0) {
                // Try finding by author
                pluginRow = $('tr').filter(function() {
                    var authorText = $(this).find('td.plugin-title .plugin-author').text();
                    return authorText.indexOf('WPDev Ltd.') !== -1;
                });
            }
            
            if (pluginRow.length > 0) {
                console.log('WPLM: Found plugin row');
                
                // Find the "View Details" link - be more specific
                var viewDetailsLink = pluginRow.find('a').filter(function() {
                    var linkText = $(this).text().toLowerCase();
                    var linkHref = $(this).attr('href') || '';
                    return (linkText.indexOf('view details') !== -1 || linkText.indexOf('details') !== -1) &&
                           (linkHref.indexOf('wordpress.org') !== -1 || linkHref.indexOf('plugin') !== -1);
                });
                
                if (viewDetailsLink.length > 0) {
                    // Replace the href
                    viewDetailsLink.attr('href', '<?php echo esc_js($options['plugin_website']); ?>');
                    viewDetailsLink.attr('target', '_blank');
                    viewDetailsLink.attr('aria-label', 'More information about <?php echo esc_js($plugin_name); ?>');
                    
                    console.log('WPLM: Plugin details link overridden via JavaScript to: <?php echo esc_js($options['plugin_website']); ?>');
                } else {
                    console.log('WPLM: View Details link not found in plugin row');
                    // Try to find any link that might be the details link
                    var allLinks = pluginRow.find('a');
                    console.log('WPLM: Found ' + allLinks.length + ' links in plugin row');
                    allLinks.each(function() {
                        console.log('WPLM: Link text: "' + $(this).text() + '", href: "' + $(this).attr('href') + '"');
                    });
                }
            } else {
                console.log('WPLM: Plugin row not found');
            }
        });
        </script>
        <?php
    }

    /**
     * Prevent WordPress from checking the wrong repository for updates
     */
    public function prevent_wrong_repo_check($args, $url) {
        // Check if this is a request to WordPress.org plugin repository
        if (strpos($url, 'api.wordpress.org/plugins/update-check') !== false) {
            // Get our plugin data
            $plugin_file = plugin_basename(WPLM_PLUGIN_FILE);
            $plugin_data = get_plugin_data(WPLM_PLUGIN_FILE);
            
            // If this is our plugin, modify the request to prevent conflicts
            if (isset($args['body']['plugins']) && is_string($args['body']['plugins'])) {
                $plugins_data = json_decode($args['body']['plugins'], true);
                if (isset($plugins_data[$plugin_file])) {
                    // Ensure our plugin has the correct UpdateURI
                    $plugins_data[$plugin_file]['UpdateURI'] = 'https://licenseswp.com/';
                    $args['body']['plugins'] = json_encode($plugins_data);
                }
            }
        }
        
        return $args;
    }

    /**
     * Filter plugin data to apply whitelabel settings
     */
    public function filter_plugin_data($plugins) {
        $plugin_file = plugin_basename(WPLM_PLUGIN_FILE);
        
        if (isset($plugins[$plugin_file])) {
            $options = get_option('wplm_whitelabel_options', []);
            
            // Debug logging (only log once per session to avoid spam)
            if (defined('WP_DEBUG') && WP_DEBUG && !get_transient('wplm_debug_logged_' . $plugin_file)) {
                error_log('WPLM Debug - Filtering plugin data for: ' . $plugin_file);
                error_log('WPLM Debug - Whitelabel options: ' . print_r($options, true));
                error_log('WPLM Debug - Plugin data before: ' . print_r($plugins[$plugin_file], true));
                set_transient('wplm_debug_logged_' . $plugin_file, true, HOUR_IN_SECONDS);
            }
            
            // Update plugin name
            if (!empty($options['plugin_name'])) {
                $plugins[$plugin_file]['Name'] = $options['plugin_name'];
            }
            
            // Update plugin description
            if (!empty($options['plugin_description'])) {
                $plugins[$plugin_file]['Description'] = $options['plugin_description'];
            }
            
            // Update plugin author
            if (!empty($options['plugin_author'])) {
                $plugins[$plugin_file]['Author'] = $options['plugin_author'];
            }
            
            // Update plugin URI - this affects the "View Details" link
            if (!empty($options['plugin_website'])) {
                $plugins[$plugin_file]['PluginURI'] = $options['plugin_website'];
            } else {
                // Fallback to default if no whitelabel website is set
                $plugins[$plugin_file]['PluginURI'] = 'https://licenseswp.com/';
            }
            
            // Update author URI
            if (!empty($options['plugin_author_website'])) {
                $plugins[$plugin_file]['AuthorURI'] = $options['plugin_author_website'];
            } else {
                // Fallback to default if no whitelabel author website is set
                $plugins[$plugin_file]['AuthorURI'] = 'https://wpdevltd.com/';
            }
            
            // Always set UpdateURI to prevent WordPress from checking the wrong repository
            $update_uri = !empty($options['plugin_website']) ? $options['plugin_website'] : 'https://licenseswp.com/';
            $plugins[$plugin_file]['UpdateURI'] = $update_uri;
            
            // Debug logging after changes (only log once per session)
            if (defined('WP_DEBUG') && WP_DEBUG && !get_transient('wplm_debug_logged_after_' . $plugin_file)) {
                error_log('WPLM Debug - Plugin data after: ' . print_r($plugins[$plugin_file], true));
                set_transient('wplm_debug_logged_after_' . $plugin_file, true, HOUR_IN_SECONDS);
            }
        }
        
        return $plugins;
    }

    /**
     * Add action links to plugin page
     */
    public function add_plugin_action_links($links) {
        $plugin_name = self::get_plugin_name();
        $settings_link = '<a href="' . admin_url('admin.php?page=wplm-settings') . '">' . __('Settings', 'wp-license-manager') . '</a>';
        $dashboard_link = '<a href="' . admin_url('admin.php?page=wplm-dashboard') . '">' . __('Dashboard', 'wp-license-manager') . '</a>';
        
        array_unshift($links, $settings_link, $dashboard_link);
        return $links;
    }

    /**
     * Get plugin name from whitelabel settings
     */
    public static function get_plugin_name() {
        $options = get_option('wplm_whitelabel_options', []);
        return !empty($options['plugin_name']) ? $options['plugin_name'] : 'LicensesWP';
    }
}

/**
 * Begins execution of the plugin.
 */
function wp_license_manager() {
    return WP_License_Manager::instance();
}

// Let's get this party started.
wp_license_manager();
