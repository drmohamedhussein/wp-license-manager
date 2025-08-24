<?php
/**
 * Plugin Name:       WP License Manager
 * Description:       A server for managing and validating software license keys via a REST API.
 * Version:           2.0.0
 * Author:            Your Name
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-license-manager
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
define('WPLM_PLUGIN_FILE', __FILE__); // Define plugin file for hooks
define('WPLM_URL', plugin_dir_url(__FILE__)); // Define plugin URL for assets

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
            'includes/class-enhanced-admin-manager.php',
            'includes/class-advanced-licensing.php',
            'includes/class-enhanced-api-manager.php',
            'includes/class-import-export-manager.php',
            'includes/class-enhanced-digital-downloads.php',
            'includes/class-rest-api-manager.php',
            'includes/class-analytics-dashboard.php',
            'includes/class-bulk-operations-manager.php',
            'includes/class-automatic-licenser.php', // Corrected class name
            'includes/class-email-notification-system.php',
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
            
            if (class_exists('WPLM_Admin_Manager')) {
                new WPLM_Admin_Manager(); // Initialize admin manager (meta boxes and AJAX only - no menu)
            } else {
                $this->log_error('WPLM_Admin_Manager class not found');
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
            
            // Initialize enhanced admin interface (split into focused classes)
            if (class_exists('WPLM_Enhanced_Admin_Manager_Core')) {
                new WPLM_Enhanced_Admin_Manager_Core(); // Core functionality, dependencies, settings
            }
            
            if (class_exists('WPLM_Enhanced_Admin_Manager_UI')) {
                new WPLM_Enhanced_Admin_Manager_UI(); // Admin menu, dashboard, settings UI
            }
            
            if (class_exists('WPLM_Enhanced_Admin_Manager_AJAX')) {
                new WPLM_Enhanced_Admin_Manager_AJAX(); // AJAX handlers, statistics, license generation
            }
            
            // Initialize advanced licensing system (split into focused classes)
            if (class_exists('WPLM_Advanced_Licensing_Core')) {
                new WPLM_Advanced_Licensing_Core(); // Core functionality, database, security
            }
            
            if (class_exists('WPLM_Advanced_Licensing_API')) {
                new WPLM_Advanced_Licensing_API(); // REST API endpoints, encryption
            }
            
            if (class_exists('WPLM_Advanced_Licensing_Admin')) {
                new WPLM_Advanced_Licensing_Admin(); // Admin interface, meta boxes
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
            
            if (class_exists('WPLM_Automatic_Licenser')) {
                new WPLM_Automatic_Licenser(); // Initialize automatic licenser system
            }
            
            if (class_exists('WPLM_Enhanced_Digital_Downloads')) {
                new WPLM_Enhanced_Digital_Downloads(); // Initialize enhanced digital downloads system
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
            if (empty(get_option('wplm_api_key'))) {
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
                'edit_others_wplm_licenses',
                'publish_wplm_licenses',
                'read_private_wplm_licenses',
                'delete_private_wplm_licenses',
                'delete_published_wplm_licenses',
                'delete_others_wplm_licenses',
                'edit_published_wplm_licenses',
                'create_wplm_licenses',
                'manage_wplm_licenses', // Added for export/import and general management

                // Product Capabilities
                'edit_wplm_product',
                'read_wplm_product',
                'delete_wplm_product',
                'edit_wplm_products',
                'edit_others_wplm_products',
                'publish_wplm_products',
                'read_private_wplm_products',
                'delete_private_wplm_products',
                'delete_published_wplm_products',
                'delete_others_wplm_products',
                'edit_published_wplm_products',
                'create_wplm_products',

                // Subscription Capabilities
                'edit_wplm_subscription',
                'read_wplm_subscription',
                'delete_wplm_subscription',
                'edit_wplm_subscriptions',
                'edit_others_wplm_subscriptions',
                'publish_wplm_subscriptions',
                'read_private_wplm_subscriptions',
                'delete_private_wplm_subscriptions',
                'delete_published_wplm_subscriptions',
                'delete_others_wplm_subscriptions',
                'edit_published_wplm_subscriptions',
                'create_wplm_subscriptions',
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
                'edit_others_wplm_licenses',
                'publish_wplm_licenses',
                'read_private_wplm_licenses',
                'delete_private_wplm_licenses',
                'delete_published_wplm_licenses',
                'delete_others_wplm_licenses',
                'edit_published_wplm_licenses',
                'create_wplm_licenses',
                'manage_wplm_licenses',

                // Product Capabilities
                'edit_wplm_product',
                'read_wplm_product',
                'delete_wplm_product',
                'edit_wplm_products',
                'edit_others_wplm_products',
                'publish_wplm_products',
                'read_private_wplm_products',
                'delete_private_wplm_products',
                'delete_published_wplm_products',
                'delete_others_wplm_products',
                'edit_published_wplm_products',
                'create_wplm_products',

                // Subscription Capabilities
                'edit_wplm_subscription',
                'read_wplm_subscription',
                'delete_wplm_subscription',
                'edit_wplm_subscriptions',
                'edit_others_wplm_subscriptions',
                'publish_wplm_subscriptions',
                'read_private_wplm_subscriptions',
                'delete_private_wplm_subscriptions',
                'delete_published_wplm_subscriptions',
                'delete_others_wplm_subscriptions',
                'edit_published_wplm_subscriptions',
                'create_wplm_subscriptions',
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
}

/**
 * Begins execution of the plugin.
 */
function wp_license_manager() {
    return WP_License_Manager::instance();
}

// Let's get this party started.
wp_license_manager();