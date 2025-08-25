<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Admin Manager - UI and Rendering
 * Part 2 of the split WPLM_Enhanced_Admin_Manager class
 */
class WPLM_Enhanced_Admin_Manager_UI {

    private $menu_slug = 'wplm-dashboard';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
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
                                    <th><?php _e('Type', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Status', 'wp-license-manager'); ?></th>
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
                            <a href="<?php echo admin_url('post-new.php?post_type=wplm_customer'); ?>" class="button button-primary">
                                <?php _e('Add New Customer', 'wp-license-manager'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="customers-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Customer Name', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Email', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Total Spent', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Subscriptions Tab -->
                <div id="subscriptions" class="wplm-tab-content">
                    <div class="wplm-section-header">
                        <h2><?php _e('Subscriptions', 'wp-license-manager'); ?></h2>
                        <div class="wplm-header-actions">
                            <a href="<?php echo admin_url('post-new.php?post_type=wplm_subscription'); ?>" class="button button-primary">
                                <?php _e('Add New Subscription', 'wp-license-manager'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="subscriptions-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Plan', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Status', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Next Payment', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Activity Tab -->
                <div id="activity" class="wplm-tab-content">
                    <div class="wplm-section-header">
                        <h2><?php _e('Recent Activity', 'wp-license-manager'); ?></h2>
                    </div>
                    <div class="wplm-table-wrapper">
                        <table id="activity-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Date', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Action', 'wp-license-manager'); ?></th>
                                    <th><?php _e('User', 'wp-license-manager'); ?></th>
                                    <th><?php _e('Details', 'wp-license-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="4"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching functionality
            $('.wplm-admin-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                // Update active tab
                $('.wplm-admin-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                // Show target content
                $('.wplm-tab-content').removeClass('active');
                $(target).addClass('active');
            });

            // Load dashboard stats
            loadDashboardStats();
        });

        function loadDashboardStats() {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_dashboard_stats',
                    nonce: '<?php echo wp_create_nonce("wplm_dashboard_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        jQuery('#total-licenses').text(data.total_licenses || 0);
                        jQuery('#active-licenses').text(data.active_licenses || 0);
                        jQuery('#total-products').text(data.total_products || 0);
                        jQuery('#total-customers').text(data.total_customers || 0);
                    }
                }
            });
        }
        </script>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('License Manager Settings', 'wp-license-manager'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wplm_settings');
                do_settings_sections('wplm_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
