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
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
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
            'includes/class-admin-manager-meta-boxes.php',
            'includes/class-admin-manager-ajax.php',
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
                    // Log error but don't show admin notice during activation
                }
            } else {
                $this->log_error('Core file missing: ' . $file);
                // Log error but don't show admin notice during activation
            }
        }

        // Optional enhanced features
        $optional_files = [
            'includes/class-notification-manager.php',
            'includes/class-activity-logger.php',
            'includes/class-subscription-manager.php',
            'includes/class-subscription-manager-core.php',
            'includes/class-built-in-subscription-system.php',
            'includes/class-customer-management-system.php',
            'includes/class-customer-management-system-core.php',
            'includes/class-enhanced-admin-manager-core.php',
            'includes/class-enhanced-admin-manager-ui.php',
            'includes/class-enhanced-admin-manager-ajax.php',
            'includes/class-advanced-licensing-core.php',
            'includes/class-advanced-licensing-api.php',
            'includes/class-advanced-licensing-admin.php',
            'includes/class-enhanced-api-manager.php',
            'includes/class-import-export-manager.php',
            'includes/class-import-export-manager-export.php',
            'includes/class-enhanced-digital-downloads.php',
            'includes/class-rest-api-manager.php',
            'includes/class-analytics-dashboard.php',
            'includes/class-bulk-operations-manager.php',
            'includes/class-bulk-operations-manager-ui.php',
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
            
            // Initialize admin manager components (split into focused classes)
            if (class_exists('WPLM_Admin_Manager_Meta_Boxes')) {
                new WPLM_Admin_Manager_Meta_Boxes(); // Initialize meta boxes and basic admin functionality
            } else {
                $this->log_error('WPLM_Admin_Manager_Meta_Boxes class not found');
            }
            
            if (class_exists('WPLM_Admin_Manager_AJAX')) {
                new WPLM_Admin_Manager_AJAX(); // Initialize AJAX handlers
            } else {
                $this->log_error('WPLM_Admin_Manager_AJAX class not found');
            }
            
            if (class_exists('WPLM_API_Manager')) {
                new WPLM_API_Manager();
            } else {
                $this->log_error('WPLM_API_Manager class not found');
            }

            // Initialize enhanced features if available
            if (class_exists('WPLM_Enhanced_Admin_Manager_Core')) {
                new WPLM_Enhanced_Admin_Manager_Core();
            }

            if (class_exists('WPLM_Advanced_Licensing_Core')) {
                new WPLM_Advanced_Licensing_Core();
            }

            if (class_exists('WPLM_Subscription_Manager_Core')) {
                new WPLM_Subscription_Manager_Core();
            }

            if (class_exists('WPLM_Customer_Management_System_Core')) {
                new WPLM_Customer_Management_System_Core();
            }

            if (class_exists('WPLM_Enhanced_Digital_Downloads')) {
                new WPLM_Enhanced_Digital_Downloads();
            }

            if (class_exists('WPLM_Enhanced_API_Manager')) {
                new WPLM_Enhanced_API_Manager();
            }

            if (class_exists('WPLM_REST_API_Manager')) {
                new WPLM_REST_API_Manager();
            }

            if (class_exists('WPLM_Analytics_Dashboard')) {
                new WPLM_Analytics_Dashboard();
            }

            if (class_exists('WPLM_Bulk_Operations_Manager')) {
                new WPLM_Bulk_Operations_Manager();
            }

            if (class_exists('WPLM_Automatic_Licenser')) {
                new WPLM_Automatic_Licenser();
            }

            if (class_exists('WPLM_Email_Notification_System')) {
                new WPLM_Email_Notification_System();
            }

            if (class_exists('WPLM_Notification_Manager')) {
                new WPLM_Notification_Manager();
            }

            if (class_exists('WPLM_Activity_Logger')) {
                new WPLM_Activity_Logger();
            }

            if (class_exists('WPLM_Import_Export_Manager')) {
                new WPLM_Import_Export_Manager();
            }

            if (class_exists('WPLM_Import_Export_Manager_Export')) {
                new WPLM_Import_Export_Manager_Export();
            }

            // Initialize WooCommerce integration if available
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

            // Initialize CLI if available
            if (class_exists('WPLM_CLI')) {
                if (defined('WP_CLI') && WP_CLI) {
                    WP_CLI::add_command('wplm', 'WPLM_CLI');
                }
            }

        } catch (Exception $e) {
            $this->log_error('Error initializing plugin: ' . $e->getMessage());
        }
    }

    /**
     * Load plugin textdomain.
     */
    public function load_plugin_textdomain_wplm() {
        load_plugin_textdomain(
            'wp-license-manager',
            false,
            dirname(plugin_basename(WPLM_PLUGIN_FILE)) . '/languages/'
        );
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        try {
            // Create database tables
            $this->create_tables();
            
            // Set default options
            $this->set_default_options();
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            // Schedule cron jobs
            if (!wp_next_scheduled('wplm_check_expiring_licenses_daily')) {
                wp_schedule_event(time(), 'daily', 'wplm_check_expiring_licenses_daily');
            }
            
            // Log activation
            $this->log_error('Plugin activated successfully');
            
        } catch (Exception $e) {
            $this->log_error('Error during activation: ' . $e->getMessage());
        }
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        try {
            // Clear scheduled cron jobs
            wp_clear_scheduled_hook('wplm_check_expiring_licenses_daily');
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            // Log deactivation
            $this->log_error('Plugin deactivated successfully');
            
        } catch (Exception $e) {
            $this->log_error('Error during deactivation: ' . $e->getMessage());
        }
    }

    /**
     * Plugin uninstall.
     */
    public static function uninstall() {
        try {
            // Remove all plugin data
            global $wpdb;
            
            // Drop custom tables
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_licenses");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_products");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_customers");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_orders");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_order_items");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_subscriptions");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_license_usage");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_security_incidents");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wplm_license_types");
            
            // Remove options
            delete_option('wplm_version');
            delete_option('wplm_settings');
            delete_option('wplm_license_types');
            delete_option('wplm_api_keys');
            
            // Remove custom post types and meta
            $post_types = ['wplm_license', 'wplm_product', 'wplm_customer', 'wplm_order'];
            foreach ($post_types as $post_type) {
                $posts = get_posts(['post_type' => $post_type, 'numberposts' => -1, 'post_status' => 'any']);
                foreach ($posts as $post) {
                    wp_delete_post($post->ID, true);
                }
            }
            
            // Log uninstall
            error_log('WP License Manager uninstalled successfully');
            
        } catch (Exception $e) {
            error_log('Error during uninstall: ' . $e->getMessage());
        }
    }

    /**
     * Create database tables.
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Licenses table
        $table_licenses = $wpdb->prefix . 'wplm_licenses';
        $sql_licenses = "CREATE TABLE $table_licenses (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            product_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'active',
            activation_limit int(11) NOT NULL DEFAULT 1,
            activations_count int(11) NOT NULL DEFAULT 0,
            expiry_date datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY product_id (product_id),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Products table
        $table_products = $wpdb->prefix . 'wplm_products';
        $sql_products = "CREATE TABLE $table_products (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            price decimal(10,2) DEFAULT 0.00,
            status varchar(50) NOT NULL DEFAULT 'publish',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) $charset_collate;";
        
        // Customers table
        $table_customers = $wpdb->prefix . 'wplm_customers';
        $sql_customers = "CREATE TABLE $table_customers (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            email varchar(255) NOT NULL,
            first_name varchar(100),
            last_name varchar(100),
            company varchar(255),
            phone varchar(50),
            address text,
            city varchar(100),
            state varchar(100),
            postcode varchar(20),
            country varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // Orders table
        $table_orders = $wpdb->prefix . 'wplm_orders';
        $sql_orders = "CREATE TABLE $table_orders (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_number varchar(50) NOT NULL,
            customer_id bigint(20) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            total decimal(10,2) NOT NULL DEFAULT 0.00,
            payment_method varchar(100),
            payment_status varchar(50) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_number (order_number),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Order items table
        $table_order_items = $wpdb->prefix . 'wplm_order_items';
        $sql_order_items = "CREATE TABLE $table_order_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            quantity int(11) NOT NULL DEFAULT 1,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            total decimal(10,2) NOT NULL DEFAULT 0.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id)
        ) $charset_collate;";
        
        // Subscriptions table
        $table_subscriptions = $wpdb->prefix . 'wplm_subscriptions';
        $sql_subscriptions = "CREATE TABLE $table_subscriptions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'active',
            billing_period varchar(20) NOT NULL DEFAULT 'monthly',
            billing_interval int(11) NOT NULL DEFAULT 1,
            trial_end datetime DEFAULT NULL,
            next_payment datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY product_id (product_id),
            KEY status (status)
        ) $charset_collate;";
        
        // License usage table
        $table_license_usage = $wpdb->prefix . 'wplm_license_usage';
        $sql_license_usage = "CREATE TABLE $table_license_usage (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            domain varchar(255) NOT NULL,
            ip_address varchar(45),
            user_agent text,
            fingerprint varchar(255),
            last_check datetime DEFAULT CURRENT_TIMESTAMP,
            check_count int(11) NOT NULL DEFAULT 1,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY license_key (license_key),
            KEY domain (domain),
            KEY status (status)
        ) $charset_collate;";
        
        // Security incidents table
        $table_security_incidents = $wpdb->prefix . 'wplm_security_incidents';
        $sql_security_incidents = "CREATE TABLE $table_security_incidents (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            incident_type varchar(100) NOT NULL,
            severity varchar(50) NOT NULL DEFAULT 'medium',
            description text,
            ip_address varchar(45),
            user_agent text,
            additional_data longtext,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY license_key (license_key),
            KEY incident_type (incident_type),
            KEY severity (severity),
            KEY status (status)
        ) $charset_collate;";
        
        // License types table
        $table_license_types = $wpdb->prefix . 'wplm_license_types';
        $sql_license_types = "CREATE TABLE $table_license_types (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text,
            features longtext,
            price decimal(10,2) DEFAULT 0.00,
            duration int(11) DEFAULT NULL,
            duration_unit varchar(20) DEFAULT 'days',
            activation_limit int(11) DEFAULT 1,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug),
            KEY status (status)
        ) $charset_collate;";
        
        // Activity logs table
        $table_activity_logs = $wpdb->prefix . 'wplm_activity_logs';
        $sql_activity_logs = "CREATE TABLE $table_activity_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            object_id bigint(20) NOT NULL DEFAULT 0,
            event_type varchar(100) NOT NULL,
            description text,
            data longtext,
            user_id bigint(20) NOT NULL DEFAULT 0,
            ip_address varchar(45),
            user_agent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY object_id (object_id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_licenses);
        dbDelta($sql_products);
        dbDelta($sql_customers);
        dbDelta($sql_orders);
        dbDelta($sql_order_items);
        dbDelta($sql_subscriptions);
        dbDelta($sql_license_usage);
        dbDelta($sql_security_incidents);
        dbDelta($sql_license_types);
        dbDelta($sql_activity_logs);
    }

    /**
     * Set default options.
     */
    private function set_default_options() {
        // Set version
        update_option('wplm_version', WPLM_VERSION);
        
        // Set default settings
        $default_settings = [
            'license_check_interval' => 24,
            'max_activation_attempts' => 5,
            'enable_security_logging' => true,
            'enable_analytics' => true,
            'enable_email_notifications' => true,
            'default_license_duration' => 365,
            'default_license_duration_unit' => 'days',
            'enable_automatic_licensing' => false,
            'enable_woocommerce_sync' => true,
        ];
        
        update_option('wplm_settings', $default_settings);
        
        // Set default license types
        $default_license_types = [
            [
                'name' => 'Personal',
                'slug' => 'personal-license',
                'description' => 'Personal use license',
                'features' => ['Single domain', 'Basic support'],
                'price' => 29.99,
                'duration' => 365,
                'duration_unit' => 'days',
                'activation_limit' => 1
            ],
            [
                'name' => 'Business',
                'slug' => 'business-license',
                'description' => 'Business use license',
                'features' => ['Up to 5 domains', 'Priority support', 'Updates'],
                'price' => 99.99,
                'duration' => 365,
                'duration_unit' => 'days',
                'activation_limit' => 5
            ],
            [
                'name' => 'Developer',
                'slug' => 'developer-license',
                'description' => 'Developer license',
                'features' => ['Unlimited domains', 'Priority support', 'Updates', 'Source code'],
                'price' => 199.99,
                'duration' => 365,
                'duration_unit' => 'days',
                'activation_limit' => -1
            ],
            [
                'name' => 'Lifetime',
                'slug' => 'lifetime-license',
                'description' => 'Lifetime license',
                'features' => ['Unlimited domains', 'Lifetime support', 'Lifetime updates'],
                'price' => 499.99,
                'duration' => null,
                'duration_unit' => 'lifetime',
                'activation_limit' => -1
            ]
        ];
        
        // Check if license types already exist to avoid duplicates
        $existing_types = get_option('wplm_license_types', []);
        if (empty($existing_types)) {
            update_option('wplm_license_types', $default_license_types);
            
            // Insert default license types into database table
            $this->insert_default_license_types($default_license_types);
        }
    }

    /**
     * Check for expiring licenses.
     */
    public function _check_expiring_licenses() {
        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'wplm_licenses';
            $expiring_licenses = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date <= %s",
                date('Y-m-d H:i:s', strtotime('+7 days'))
            ));
            
            foreach ($expiring_licenses as $license) {
                // Update status to expiring
                $wpdb->update(
                    $table,
                    ['status' => 'expiring'],
                    ['id' => $license->id]
                );
                
                // Send notification email
                $this->send_expiry_notification($license);
            }
            
        } catch (Exception $e) {
            $this->log_error('Error checking expiring licenses: ' . $e->getMessage());
        }
    }

    /**
     * Insert default license types into database
     */
    private function insert_default_license_types($license_types) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wplm_license_types';
        
        foreach ($license_types as $license_type) {
            $wpdb->insert(
                $table_name,
                [
                    'name' => $license_type['name'],
                    'slug' => $license_type['slug'],
                    'description' => $license_type['description'],
                    'features' => json_encode($license_type['features']),
                    'price' => $license_type['price'],
                    'duration' => $license_type['duration'],
                    'duration_unit' => $license_type['duration_unit'],
                    'activation_limit' => $license_type['activation_limit'],
                    'status' => 'active'
                ],
                [
                    '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s'
                ]
            );
        }
        
        // Now add unique constraint after data is inserted
        $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY `slug` (`slug`)");
    }

    /**
     * Send expiry notification.
     */
    private function send_expiry_notification($license) {
        try {
            $customer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wplm_customers WHERE id = %d",
                $license->customer_id
            ));
            
            if ($customer) {
                $subject = 'Your license is expiring soon';
                $message = sprintf(
                    'Hello %s, your license for %s will expire on %s. Please renew to continue using the software.',
                    $customer->first_name ?: $customer->email,
                    $license->product_id,
                    $license->expiry_date
                );
                
                wp_mail($customer->email, $subject, $message);
            }
            
        } catch (Exception $e) {
            $this->log_error('Error sending expiry notification: ' . $e->getMessage());
        }
    }

    /**
     * Log error.
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP License Manager: ' . $message);
        }
    }
}

// Initialize the plugin
WP_License_Manager::instance();