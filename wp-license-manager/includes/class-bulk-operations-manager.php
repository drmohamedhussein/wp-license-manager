<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bulk Operations Manager for WPLM
 */
class WPLM_Bulk_Operations_Manager {

    public function __construct() {
        // Admin page hooks
        add_action('admin_menu', [$this, 'add_admin_menu'], 100);
        
        // AJAX handlers
        add_action('wp_ajax_wplm_bulk_create_licenses', [$this, 'ajax_bulk_create_licenses']);
        add_action('wp_ajax_wplm_bulk_update_licenses', [$this, 'ajax_bulk_update_licenses']);
        add_action('wp_ajax_wplm_bulk_delete_licenses', [$this, 'ajax_bulk_delete_licenses']);
        add_action('wp_ajax_wplm_bulk_activate_licenses', [$this, 'ajax_bulk_activate_licenses']);
        add_action('wp_ajax_wplm_bulk_deactivate_licenses', [$this, 'ajax_bulk_deactivate_licenses']);
        add_action('wp_ajax_wplm_bulk_extend_licenses', [$this, 'ajax_bulk_extend_licenses']);
        add_action('wp_ajax_wplm_bulk_generate_from_wc_orders', [$this, 'ajax_bulk_generate_from_wc_orders']);
        add_action('wp_ajax_wplm_scan_wc_orders', [$this, 'ajax_scan_wc_orders']);
        add_action('wp_ajax_wplm_generate_wc_licenses', [$this, 'ajax_generate_wc_licenses']);
        add_action('wp_ajax_wplm_bulk_set_expiry', [$this, 'ajax_bulk_set_expiry']);
        add_action('wp_ajax_wplm_bulk_set_activation_limit', [$this, 'ajax_bulk_set_activation_limit']);
        add_action('wp_ajax_wplm_bulk_change_product', [$this, 'ajax_bulk_change_product']);
        add_action('wp_ajax_wplm_bulk_transfer_customer', [$this, 'ajax_bulk_transfer_customer']);
        add_action('wp_ajax_wplm_get_licenses_for_bulk', [$this, 'ajax_get_licenses_for_bulk']);
        add_action('wp_ajax_wplm_preview_bulk_update', [$this, 'ajax_preview_bulk_update']);
        add_action('wp_ajax_wplm_bulk_update_licenses', [$this, 'ajax_bulk_update_licenses']);
        
        // Form submission handlers
        add_action('admin_post_wplm_bulk_operation', [$this, 'handle_bulk_operation']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add admin menu for bulk operations
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wplm-dashboard',
            __('Bulk Operations', 'wp-license-manager'),
            __('Bulk Operations', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-bulk-operations',
            [$this, 'render_bulk_operations_page']
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wplm-bulk-operations') === false) {
            return;
        }

        wp_enqueue_script('wplm-bulk-operations', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/bulk-operations.js', ['jquery'], WPLM_VERSION, true);
        wp_localize_script('wplm-bulk-operations', 'wplm_bulk', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplm_bulk_operations'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete the selected licenses? This action cannot be undone.', 'wp-license-manager'),
                'confirm_operation' => __('Are you sure you want to perform this bulk operation?', 'wp-license-manager'),
                'processing' => __('Processing...', 'wp-license-manager'),
                'success' => __('Operation completed successfully!', 'wp-license-manager'),
                'error' => __('An error occurred during the operation.', 'wp-license-manager'),
                'select_licenses' => __('Please select at least one license.', 'wp-license-manager'),
                'invalid_expiry_date' => __('Invalid expiry date provided.', 'wp-license-manager'),
                'invalid_activation_limit' => __('Activation limit cannot be negative.', 'wp-license-manager'),
                'select_new_product' => __('Please select a new product.', 'wp-license-manager'),
                'select_new_customer' => __('Please select a new customer.', 'wp-license-manager'),
                'invalid_extension_value' => __('Please enter a valid extension value.', 'wp-license-manager'),
                'invalid_action_or_confirmation' => __('Invalid action or action handled by dedicated confirmation.', 'wp-license-manager'),
            ]
        ]);
    }

    /**
     * Render bulk operations page
     */
    public function render_bulk_operations_page() {
        ?>
        <div class="wrap wplm-bulk-operations">
            <h1><?php _e('Bulk Operations', 'wp-license-manager'); ?></h1>
            
            <div class="wplm-bulk-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#bulk-create" class="nav-tab nav-tab-active"><?php _e('Bulk Create', 'wp-license-manager'); ?></a>
                    <a href="#bulk-manage" class="nav-tab"><?php _e('Bulk Manage', 'wp-license-manager'); ?></a>
                    <a href="#wc-orders" class="nav-tab"><?php _e('WooCommerce Orders', 'wp-license-manager'); ?></a>
                    <a href="#bulk-update" class="nav-tab"><?php _e('Bulk Update', 'wp-license-manager'); ?></a>
                </nav>
            </div>

            <div class="wplm-bulk-content">
                <!-- Bulk Create Tab -->
                <div id="bulk-create" class="wplm-tab-content active">
                    <?php $this->render_bulk_create_section(); ?>
                </div>

                <!-- Bulk Manage Tab -->
                <div id="bulk-manage" class="wplm-tab-content">
                    <?php $this->render_bulk_manage_section(); ?>
                </div>

                <!-- WooCommerce Orders Tab -->
                <div id="wc-orders" class="wplm-tab-content">
                    <?php $this->render_wc_orders_section(); ?>
                </div>

                <!-- Bulk Update Tab -->
                <div id="bulk-update" class="wplm-tab-content">
                    <?php $this->render_bulk_update_section(); ?>
                </div>
            </div>
        </div>

        <style>
        .wplm-bulk-operations .nav-tab-wrapper { margin-bottom: 25px; border-bottom: 1px solid #c3c4c7; padding-bottom: 0; }
        .wplm-bulk-operations .nav-tab-wrapper a.nav-tab { padding: 10px 15px; font-size: 15px; color: #555;
            border: 1px solid #c3c4c7; border-bottom: none; background: #f0f0f0; margin-right: 5px; border-radius: 4px 4px 0 0; }
        .wplm-bulk-operations .nav-tab-wrapper a.nav-tab-active { background: #fff; border-bottom: 1px solid #fff; color: #007cba; }
        .wplm-tab-content { display: none; background: white; padding: 20px; border: 1px solid #c3c4c7; border-top: none; border-radius: 0 0 4px 4px; }
        .wplm-tab-content.active { display: block; }
        .wplm-bulk-section { background: white; padding: 20px; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 20px; }
        .wplm-bulk-form { max-width: 800px; }
        .wplm-progress-bar { width: 100%; height: 25px; background: #e0e0e0; border-radius: 12px; overflow: hidden; margin: 15px 0; display: none; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1); }
        .wplm-progress-fill { height: 100%; background: linear-gradient(90deg, #007cba, #00a0d2); transition: width 0.4s ease-out; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.9em; }
        .wplm-result-box { margin-top: 25px; padding: 15px 20px; border-radius: 4px; display: none; font-size: 1.0em; }
        .wplm-result-box.success { background: #e5f6e5; border: 1px solid #a3d9a3; color: #1e6c1e; }
        .wplm-result-box.error { background: #ffebeb; border: 1px solid #ea9999; color: #cc0000; }
        .wplm-license-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-top: 20px; }
        .wplm-license-item { border: 1px solid #e0e0e0; padding: 15px; border-radius: 4px; background: #fefefe; box-shadow: 0 1px 1px rgba(0,0,0,0.04); transition: all 0.2s ease; }
        .wplm-license-item:hover { border-color: #007cba; box-shadow: 0 2px 5px rgba(0,124,186,0.2); transform: translateY(-2px); }
        .wplm-license-item label { display: block; cursor: pointer; }
        .wplm-license-item input[type="checkbox"] { margin-right: 10px; }
        .wplm-license-item strong { color: #007cba; }
        .wplm-license-item .status-active { color: #46b450; font-weight: bold; }
        .wplm-license-item .status-inactive, .wplm-license-item .status-expired, .wplm-license-item .status-revoked { color: #dc3232; font-weight: bold; }
        .wplm-filter-controls { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; padding: 15px; border: 1px solid #eee; background: #fcfcfc; border-radius: 4px; }
        .wplm-filter-controls label { font-weight: 600; margin-bottom: 5px; display: block; }
        .wplm-filter-controls select, .wplm-filter-controls input[type="date"], .wplm-filter-controls input[type="number"] { width: 100%; padding: 8px; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.07); }
        .wplm-filter-controls button { margin-top: 10px; }
        .wplm-action-buttons { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
        .wplm-action-buttons .button { margin-right: 10px; margin-bottom: 10px; }
        .wplm-bulk-action-options { background: #f9f9f9; border: 1px solid #e0e0e0; padding: 20px; border-radius: 4px; margin-top: 20px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); }
        .wplm-bulk-action-options h4 { margin-top: 0; margin-bottom: 15px; color: #007cba; }
        .wplm-bulk-action-options label { display: block; margin-bottom: 10px; font-weight: 600; }
        .wplm-bulk-action-options input[type="number"], .wplm-bulk-action-options input[type="date"], .wplm-bulk-action-options select { padding: 8px; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.07); margin-left: 5px; }
        .wplm-bulk-action-options .button-primary, .wplm-bulk-action-options .button { margin-top: 15px; margin-right: 10px; }
        .wplm-select2-product-search, .wplm-select2-customer-search { min-width: 250px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.wplm-tab-content').removeClass('active');
                $(target).addClass('active');
            });
        });
        </script>
        <?php
    }

    /**
     * Render bulk create section
     */
    private function render_bulk_create_section() {
        $products = get_posts(['post_type' => 'wplm_product', 'numberposts' => -1]);
        ?>
        <div class="wplm-bulk-section">
            <h2><?php _e('Bulk Create Licenses', 'wp-license-manager'); ?></h2>
            <p><?php _e('Generate multiple licenses at once with the same settings.', 'wp-license-manager'); ?></p>
            
            <form class="wplm-bulk-form" id="bulk-create-form">
                <?php wp_nonce_field('wplm_bulk_operations', 'bulk_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bulk_create_license_count"><?php esc_html_e('Number of Licenses', 'wp-license-manager'); ?></label></th>
                        <td>
                            <input type="number" id="bulk_create_license_count" name="license_count" min="1" max="1000" value="10" required />
                            <p class="description"><?php esc_html_e('How many licenses to create (max 1000).', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_product_id"><?php esc_html_e('Product', 'wp-license-manager'); ?></label></th>
                        <td>
                            <select id="bulk_create_product_id" name="product_id" class="wplm-select2-product-search" style="width:100%;" required>
                                <option value=""><?php esc_html_e('Select Product', 'wp-license-manager'); ?></option>
                                <!-- Options will be loaded via Select2 AJAX -->
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_customer_email"><?php esc_html_e('Customer (Optional)', 'wp-license-manager'); ?></label></th>
                        <td>
                            <select id="bulk_create_customer_id" name="customer_id" class="wplm-select2-customer-search" style="width:100%;">
                                <option value=""><?php esc_html_e('Select Customer', 'wp-license-manager'); ?></option>
                                <!-- Options will be loaded via Select2 AJAX -->
                            </select>
                            <p class="description"><?php esc_html_e('Leave empty to create unassigned licenses.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_duration_type"><?php esc_html_e('Duration', 'wp-license-manager'); ?></label></th>
                        <td>
                            <select id="bulk_create_duration_type" name="duration_type">
                                <option value="lifetime"><?php esc_html_e('Lifetime', 'wp-license-manager'); ?></option>
                                <option value="days"><?php esc_html_e('Days', 'wp-license-manager'); ?></option>
                                <option value="months"><?php esc_html_e('Months', 'wp-license-manager'); ?></option>
                                <option value="years"><?php esc_html_e('Years', 'wp-license-manager'); ?></option>
                            </select>
                            <input type="number" id="bulk_create_duration_value" name="duration_value" min="1" value="1" style="width: 80px;" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_activation_limit"><?php esc_html_e('Activation Limit', 'wp-license-manager'); ?></label></th>
                        <td>
                            <input type="number" id="bulk_create_activation_limit" name="activation_limit" min="1" value="1" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_status"><?php esc_html_e('Status', 'wp-license-manager'); ?></label></th>
                        <td>
                            <select id="bulk_create_status" name="status">
                                <option value="active"><?php esc_html_e('Active', 'wp-license-manager'); ?></option>
                                <option value="inactive"><?php esc_html_e('Inactive', 'wp-license-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="submit" class="button-primary" id="bulk-create-submit"><?php esc_html_e('Create Licenses', 'wp-license-manager'); ?></button>
                </p>
            </form>
            
            <div class="wplm-progress-bar">
                <div class="wplm-progress-fill" style="width: 0%;"></div>
            </div>
            
            <div class="wplm-result-box" id="create-result"></div>
        </div>
        <?php
    }

    /**
     * Render bulk manage section
     */
    private function render_bulk_manage_section() {
        ?>
        <div class="wplm-bulk-section">
            <h2><?php _e('Bulk Manage Licenses', 'wp-license-manager'); ?></h2>
            <p><?php _e('Select multiple licenses and perform bulk operations.', 'wp-license-manager'); ?></p>
            
            <div class="wplm-license-selector">
                <h3><?php _e('Select Licenses', 'wp-license-manager'); ?></h3>
                <div class="wplm-filter-controls">
                    <label for="license-status-filter">
                        <?php _e('Filter by Status:', 'wp-license-manager'); ?>
                        <select id="license-status-filter" name="filter_status">
                            <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                            <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                            <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                            <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                            <option value="pending"><?php _e('Pending', 'wp-license-manager'); ?></option>
                            <option value="revoked"><?php _e('Revoked', 'wp-license-manager'); ?></option>
                        </select>
                    </label>
                    
                    <label for="license-product-filter">
                        <?php _e('Filter by Product:', 'wp-license-manager'); ?>
                        <select id="license-product-filter" name="filter_product" class="wplm-select2-product-search" style="width:200px;">
                            <option value=""><?php _e('All Products', 'wp-license-manager'); ?></option>
                        </select>
                    </label>

                    <label for="license-customer-filter">
                        <?php _e('Filter by Customer:', 'wp-license-manager'); ?>
                        <select id="license-customer-filter" name="filter_customer" class="wplm-select2-customer-search" style="width:200px;">
                            <option value=""><?php _e('All Customers', 'wp-license-manager'); ?></option>
                        </select>
                    </label>

                    <label for="license-expiry-from">
                        <?php _e('Expiry From:', 'wp-license-manager'); ?>
                        <input type="date" id="license-expiry-from" name="filter_expiry_from" />
                    </label>

                    <label for="license-expiry-to">
                        <?php _e('Expiry To:', 'wp-license-manager'); ?>
                        <input type="date" id="license-expiry-to" name="filter_expiry_to" />
                    </label>
                    
                    <button type="button" id="load-licenses" class="button button-primary"><?php _e('Load Licenses', 'wp-license-manager'); ?></button>
                    <button type="button" id="select-all-licenses" class="button"><?php _e('Select All', 'wp-license-manager'); ?></button>
                    <button type="button" id="deselect-all-licenses" class="button"><?php _e('Deselect All', 'wp-license-manager'); ?></button>
                </div>
                
                <div id="license-list" class="wplm-license-grid">
                    <p><?php _e('Click "Load Licenses" to view available licenses.', 'wp-license-manager'); ?></p>
                </div>
            </div>
            
            <div class="wplm-bulk-actions" style="margin-top: 30px;">
                <h3><?php _e('Bulk Actions', 'wp-license-manager'); ?></h3>
                <div class="wplm-action-buttons">
                    <button type="button" class="button bulk-action" data-action="activate"><?php _e('Activate Selected', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action" data-action="deactivate"><?php _e('Deactivate Selected', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action" data-action="extend"><?php _e('Extend Expiry', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action" data-action="set_expiry"><?php _e('Set Expiry Date', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action" data-action="set_activation_limit"><?php _e('Set Activation Limit', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action" data-action="change_product"><?php _e('Change Product', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action" data-action="transfer_customer"><?php _e('Transfer to Customer', 'wp-license-manager'); ?></button>
                    <button type="button" class="button bulk-action button-link-delete" data-action="delete"><?php _e('Delete Selected', 'wp-license-manager'); ?></button>
                </div>
                
                <div id="extend-options" class="wplm-bulk-action-options" style="display: none;">
                    <h4><?php _e('Extend Expiry Date', 'wp-license-manager'); ?></h4>
                    <label>
                        <?php _e('Add:', 'wp-license-manager'); ?>
                        <input type="number" id="extend-value" min="1" value="30" style="width: 80px;" />
                        <select id="extend-unit">
                            <option value="days"><?php _e('Days', 'wp-license-manager'); ?></option>
                            <option value="months"><?php _e('Months', 'wp-license-manager'); ?></option>
                            <option value="years"><?php _e('Years', 'wp-license-manager'); ?></option>
                        </select>
                    </label>
                    <button type="button" id="confirm-extend" class="button-primary"><?php _e('Confirm Extension', 'wp-license-manager'); ?></button>
                    <button type="button" class="button" id="cancel-action"><?php _e('Cancel', 'wp-license-manager'); ?></button>
                </div>

                <div id="set-expiry-options" class="wplm-bulk-action-options" style="display: none;">
                    <h4><?php _e('Set New Expiry Date', 'wp-license-manager'); ?></h4>
                    <label>
                        <?php _e('New Expiry Date:', 'wp-license-manager'); ?>
                        <input type="date" id="new-expiry-date" />
                    </label>
                    <button type="button" id="confirm-set-expiry" class="button-primary"><?php _e('Confirm Set Expiry', 'wp-license-manager'); ?></button>
                    <button type="button" class="button" id="cancel-action"><?php _e('Cancel', 'wp-license-manager'); ?></button>
                </div>

                <div id="set-activation-limit-options" class="wplm-bulk-action-options" style="display: none;">
                    <h4><?php _e('Set New Activation Limit', 'wp-license-manager'); ?></h4>
                    <label>
                        <?php _e('New Limit:', 'wp-license-manager'); ?>
                        <input type="number" id="new-activation-limit-value" min="0" value="1" />
                    </label>
                    <button type="button" id="confirm-set-activation-limit" class="button-primary"><?php _e('Confirm Set Limit', 'wp-license-manager'); ?></button>
                    <button type="button" class="button" id="cancel-action"><?php _e('Cancel', 'wp-license-manager'); ?></button>
                </div>

                <div id="change-product-options" class="wplm-bulk-action-options" style="display: none;">
                    <h4><?php _e('Change Product for Licenses', 'wp-license-manager'); ?></h4>
                    <label>
                        <?php _e('New Product:', 'wp-license-manager'); ?>
                        <select id="new-product-id" class="wplm-select2-product-search" style="width:200px;">
                            <option value=""><?php _e('Select New Product', 'wp-license-manager'); ?></option>
                        </select>
                    </label>
                    <button type="button" id="confirm-change-product" class="button-primary"><?php _e('Confirm Change Product', 'wp-license-manager'); ?></button>
                    <button type="button" class="button" id="cancel-action"><?php _e('Cancel', 'wp-license-manager'); ?></button>
                </div>

                <div id="transfer-customer-options" class="wplm-bulk-action-options" style="display: none;">
                    <h4><?php _e('Transfer Licenses to Customer', 'wp-license-manager'); ?></h4>
                    <label>
                        <?php _e('New Customer:', 'wp-license-manager'); ?>
                        <select id="new-customer-id" class="wplm-select2-customer-search" style="width:200px;">
                            <option value=""><?php _e('Select New Customer', 'wp-license-manager'); ?></option>
                        </select>
                    </label>
                    <button type="button" id="confirm-transfer-customer" class="button-primary"><?php _e('Confirm Transfer', 'wp-license-manager'); ?></button>
                    <button type="button" class="button" id="cancel-action"><?php _e('Cancel', 'wp-license-manager'); ?></button>
                </div>

            </div>
            
            <div class="wplm-result-box" id="manage-result"></div>
        </div>
        <?php
    }

    /**
     * Render WooCommerce orders section
     */
    private function render_wc_orders_section() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-warning"><p>' . __('WooCommerce is not active. This feature requires WooCommerce.', 'wp-license-manager') . '</p></div>';
            return;
        }
        ?>
        <div class="wplm-bulk-section">
            <h2><?php _e('Generate Licenses from WooCommerce Orders', 'wp-license-manager'); ?></h2>
            <p><?php _e('Generate licenses for existing WooCommerce orders that contain licensed products.', 'wp-license-manager'); ?></p>
            
            <form id="wc-bulk-form">
                <?php wp_nonce_field('wplm_bulk_operations', 'wc_bulk_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Order Status', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="order_status[]" multiple>
                                <option value="wc-completed" selected><?php _e('Completed', 'wp-license-manager'); ?></option>
                                <option value="wc-processing"><?php _e('Processing', 'wp-license-manager'); ?></option>
                                <option value="wc-on-hold"><?php _e('On Hold', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('Select order statuses to process.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Date Range', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="date" name="date_from" />
                            <?php _e('to', 'wp-license-manager'); ?>
                            <input type="date" name="date_to" />
                            <p class="description"><?php _e('Leave empty to process all orders.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Options', 'wp-license-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="skip_existing" checked />
                                <?php _e('Skip orders that already have licenses', 'wp-license-manager'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="send_emails" />
                                <?php _e('Send license emails to customers', 'wp-license-manager'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="scan-orders" class="button"><?php _e('Scan Orders', 'wp-license-manager'); ?></button>
                    <button type="submit" class="button-primary" disabled><?php _e('Generate Licenses', 'wp-license-manager'); ?></button>
                </p>
            </form>
            
            <div id="order-scan-results" style="margin-top: 20px;"></div>
            <div class="wplm-progress-bar">
                <div class="wplm-progress-fill" style="width: 0%;"></div>
            </div>
            <div class="wplm-result-box" id="wc-result"></div>
        </div>
        <?php
    }

    /**
     * Render bulk update section
     */
    private function render_bulk_update_section() {
        ?>
        <div class="wplm-bulk-section">
            <h2><?php _e('Bulk Update License Properties', 'wp-license-manager'); ?></h2>
            <p><?php _e('Update multiple license properties in bulk using filters.', 'wp-license-manager'); ?></p>
            
            <form id="bulk-update-form">
                <?php wp_nonce_field('wplm_bulk_operations', 'update_nonce'); ?>
                
                <h3><?php _e('Filter Licenses', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Current Status', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="filter_status">
                                <option value=""><?php _e('Any Status', 'wp-license-manager'); ?></option>
                                <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                                <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Product', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="filter_product">
                                <option value=""><?php _e('Any Product', 'wp-license-manager'); ?></option>
                                <?php
                                $products = get_posts(['post_type' => 'wplm_product', 'numberposts' => -1]);
                                foreach ($products as $product):
                                ?>
                                    <option value="<?php echo esc_attr($product->post_name); ?>"><?php echo esc_html($product->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Customer Email', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="email" name="filter_customer" placeholder="<?php _e('Leave empty for all customers', 'wp-license-manager'); ?>" />
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e('Update Properties', 'wp-license-manager'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('New Status', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="new_status">
                                <option value=""><?php _e('Keep Current', 'wp-license-manager'); ?></option>
                                <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                                <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Activation Limit', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="number" name="new_activation_limit" min="1" placeholder="<?php _e('Keep current', 'wp-license-manager'); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Extend Expiry', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="number" name="extend_value" min="1" placeholder="0" style="width: 80px;" />
                            <select name="extend_unit">
                                <option value="days"><?php _e('Days', 'wp-license-manager'); ?></option>
                                <option value="months"><?php _e('Months', 'wp-license-manager'); ?></option>
                                <option value="years"><?php _e('Years', 'wp-license-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('Add time to current expiry date. Leave 0 to skip.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="preview-update" class="button"><?php _e('Preview Changes', 'wp-license-manager'); ?></button>
                    <button type="submit" class="button-primary" disabled><?php _e('Apply Updates', 'wp-license-manager'); ?></button>
                </p>
            </form>
            
            <div id="update-preview" style="margin-top: 20px;"></div>
            <div class="wplm-result-box" id="update-result"></div>
        </div>
        <?php
    }

    /**
     * AJAX: Bulk create licenses
     */
    public function ajax_bulk_create_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $count = intval($_POST['license_count']);
        $product_id = intval($_POST['product_id']); // Now expects product ID
        $customer_id = intval($_POST['customer_id'] ?? 0); // New: customer ID
        $duration_type = sanitize_text_field($_POST['duration_type']);
        $duration_value = intval($_POST['duration_value']);
        $activation_limit = intval($_POST['activation_limit']);
        $status = sanitize_text_field($_POST['status']);

        if ($count < 1 || $count > 1000) {
            wp_send_json_error(['message' => __('Invalid license count. Must be between 1 and 1000.', 'wp-license-manager')]);
        }

        if (empty($product_id)) {
            wp_send_json_error(['message' => __('Product is required.', 'wp-license-manager')]);
        }

        $customer_email = '';
        if ($customer_id > 0) {
            $customer_post = get_post($customer_id);
            if ($customer_post && $customer_post->post_type === 'wplm_customer') {
                $customer_email = get_post_meta($customer_id, '_wplm_customer_email', true);
            } else {
                wp_send_json_error(['message' => __('Invalid customer selected.', 'wp-license-manager')]);
            }
        }

        $created_licenses = [];
        $errors = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $license_key = $this->generate_license_key();
                
                // Ensure uniqueness
                $attempts = 0;
                while ( $attempts < 5 ) {
                    $existing_license = new WP_Query([
                        'post_type'      => 'wplm_license',
                        'posts_per_page' => 1,
                        'title'          => $license_key,
                        'fields'         => 'ids',
                        'exact'          => true,
                    ]);
                    if ( !$existing_license->have_posts() ) {
                        break;
                    }
                    $attempts++;
                    $license_key = $this->generate_license_key();
                }

                if ( $attempts === 5 ) {
                    $errors[] = sprintf(__('Failed to generate a unique license key after multiple attempts for license %d.', 'wp-license-manager'), $i + 1);
                    continue;
                }

                $license_id = wp_insert_post([
                    'post_title' => $license_key,
                    'post_type' => 'wplm_license',
                    'post_status' => 'publish'
                ]);

                if (is_wp_error($license_id)) {
                    $errors[] = sprintf(__('Failed to create license %d: %s', 'wp-license-manager'), $i + 1, $license_id->get_error_message());
                    continue;
                }

                // Set license meta
                update_post_meta($license_id, '_wplm_status', $status);
                update_post_meta($license_id, '_wplm_product_id', $product_id); // Store actual WPLM product ID
                update_post_meta($license_id, '_wplm_activation_limit', $activation_limit);
                update_post_meta($license_id, '_wplm_activated_domains', []);
                
                if (!empty($customer_email)) {
                    update_post_meta($license_id, '_wplm_customer_email', $customer_email);
                    update_post_meta($license_id, '_wplm_customer_id', $customer_id); // Link to customer CPT

                    // Also update customer's license keys list
                    $customer_license_keys = get_post_meta($customer_id, '_wplm_license_keys', true);
                    if (!is_array($customer_license_keys)) {
                        $customer_license_keys = [];
                    }
                    $customer_license_keys[] = $license_key;
                    update_post_meta($customer_id, '_wplm_license_keys', $customer_license_keys);
                }

                // Set expiry date
                if ($duration_type !== 'lifetime') {
                    $expiry_date = $this->calculate_expiry_date($duration_type, $duration_value);
                    update_post_meta($license_id, '_wplm_expiry_date', $expiry_date);
                }

                $created_licenses[] = [
                    'id' => $license_id,
                    'key' => $license_key
                ];

                // Log activity
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_created', 'License created via bulk operation', [
                        'product_id' => $product_id,
                        'customer_email' => $customer_email,
                        'bulk_operation' => true
                    ]);
                }

            } catch (Exception $e) {
                $errors[] = sprintf(__('Error creating license %d: %s', 'wp-license-manager'), $i + 1, $e->getMessage());
            }
        }

        $result = [
            'created_count' => count($created_licenses),
            'error_count' => count($errors),
            'licenses' => $created_licenses,
            'errors' => $errors
        ];

        if (count($created_licenses) > 0) {
            $result['message'] = sprintf(__('Successfully created %d licenses.', 'wp-license-manager'), count($created_licenses));
            if (count($errors) > 0) {
                $result['message'] .= ' ' . sprintf(__('%d errors occurred.', 'wp-license-manager'), count($errors));
            }
            wp_send_json_success($result);
        } else {
            wp_send_json_error([
                'message' => __('No licenses were created.', 'wp-license-manager'),
                'errors' => $errors
            ]);
        }
    }

    /**
     * Generate standardized license key
     */
    private function generate_license_key() {
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
     * AJAX: Bulk activate licenses
     */
    public function ajax_bulk_activate_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                update_post_meta($license_id, '_wplm_status', 'active');
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_activated', 'License activated via bulk operation');
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully activated %d licenses.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX: Bulk deactivate licenses
     */
    public function ajax_bulk_deactivate_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                update_post_meta($license_id, '_wplm_status', 'inactive');
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_deactivated', 'License deactivated via bulk operation');
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully deactivated %d licenses.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX: Bulk delete licenses
     */
    public function ajax_bulk_delete_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('delete_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        $deleted_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                if (wp_delete_post($license_id, true)) {
                    $deleted_count++;
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully deleted %d licenses.', 'wp-license-manager'), $deleted_count),
            'deleted_count' => $deleted_count
        ]);
    }

    /**
     * AJAX: Bulk extend licenses
     */
    public function ajax_bulk_extend_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        $extend_value = intval($_POST['extend_value']);
        $extend_unit = sanitize_text_field($_POST['extend_unit']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        if ($extend_value < 1) {
            wp_send_json_error(['message' => __('Invalid extension value.', 'wp-license-manager')]);
        }

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                $current_expiry = get_post_meta($license_id, '_wplm_expiry_date', true);
                
                if (empty($current_expiry)) {
                    // If no expiry date, set from today
                    $base_date = current_time('timestamp');
                } else {
                    $base_date = strtotime($current_expiry);
                }

                $new_expiry_timestamp = strtotime("+{$extend_value} {$extend_unit}", $base_date);
                $new_expiry_date = date('Y-m-d', $new_expiry_timestamp);

                update_post_meta($license_id, '_wplm_expiry_date', $new_expiry_date);
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_extended', "License expiry extended by {$extend_value} {$extend_unit}", [
                        'new_expiry_date' => $new_expiry_date,
                        'extension' => "{$extend_value} {$extend_unit}"
                    ]);
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully extended %d licenses.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX handler to scan WooCommerce orders for licensed products.
     */
    public function ajax_scan_wc_orders() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
            return;
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => __('WooCommerce is not active. This feature requires WooCommerce.', 'wp-license-manager')]);
            return;
        }

        $order_statuses = array_map('sanitize_text_field', $_POST['order_status'] ?? []);
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $skip_existing = filter_var($_POST['skip_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $args = [
            'limit' => -1,
            'status' => $order_statuses,
            'return' => 'ids',
            'type' => 'shop_order',
        ];

        if (!empty($date_from) && !empty($date_to)) {
            $args['date_query'] = [
                [
                    'after' => $date_from,
                    'before' => $date_to,
                    'inclusive' => true,
                ],
            ];
        }

        $order_ids = wc_get_orders($args);
        $scannable_orders = [];
        $products_to_license = []; // [order_id => [product_id => count]]
        $total_licenses_to_generate = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            $order_has_licensed_products = false;
            $order_licensed_products_count = 0;

            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $quantity = $item->get_quantity();
                $wplm_product_id = get_post_meta($product_id, '_wplm_wc_linked_wplm_product_id', true);
                
                if ($wplm_product_id) {
                    // Check if a license already exists for this order item combination if skip_existing is true
                    if ($skip_existing) {
                        $existing_licenses_for_item = get_posts([
                            'post_type' => 'wplm_license',
                            'meta_query' => [
                                'relation' => 'AND',
                                [
                                    'key' => '_wplm_order_id',
                                    'value' => $order_id,
                                    'compare' => '=',
                                ],
                                [
                                    'key' => '_wplm_wc_product_id',
                                    'value' => $product_id,
                                    'compare' => '=',
                                ],
                            ],
                            'fields' => 'ids',
                            'posts_per_page' => -1,
                        ]);

                        // If licenses already exist for this order item, skip this item.
                        // A more robust check might involve quantity matching.
                        if (!empty($existing_licenses_for_item) && count($existing_licenses_for_item) >= $quantity) {
                            continue;
                        }
                    }

                    $order_has_licensed_products = true;
                    $order_licensed_products_count += $quantity;
                    $products_to_license[$order_id][$wplm_product_id] = ($products_to_license[$order_id][$wplm_product_id] ?? 0) + $quantity;
                    $total_licenses_to_generate += $quantity;
                }
            }

            if ($order_has_licensed_products) {
                $scannable_orders[] = [
                    'order_id' => $order_id,
                    'order_number' => $order->get_order_number(),
                    'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                    'customer_email' => $order->get_billing_email(),
                    'total_products' => $order_licensed_products_count,
                ];
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Scan complete. Found %d orders with licensed products, ready to generate %d licenses.', 'wp-license-manager'), count($scannable_orders), $total_licenses_to_generate),
            'scannable_orders' => $scannable_orders,
            'products_to_license' => $products_to_license,
            'total_licenses_to_generate' => $total_licenses_to_generate,
        ]);
    }

    /**
     * AJAX handler to generate licenses for scanned WooCommerce orders.
     */
    public function ajax_generate_wc_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
            return;
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => __('WooCommerce is not active. This feature requires WooCommerce.', 'wp-license-manager')]);
            return;
        }

        if (!class_exists('WPLM_License_Manager')) {
            wp_send_json_error(['message' => __('License Manager not available.', 'wp-license-manager')]);
            return;
        }
        
        $license_manager = new WPLM_License_Manager();

        $orders_data = json_decode(stripslashes($_POST['orders_data']), true);
        $send_emails = filter_var($_POST['send_emails'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $generated_licenses_count = 0;
        $failed_licenses_count = 0;
        $generated_licenses_details = [];

        foreach ($orders_data as $order_id => $products_data) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $failed_licenses_count += array_sum($products_data); // Count all licenses for this failed order
                continue;
            }

            $customer_email = $order->get_billing_email();
            
            foreach ($products_data as $wplm_product_id => $quantity) {
                $product_post = get_post($wplm_product_id);
                if (!$product_post || $product_post->post_type !== 'wplm_product') {
                    $failed_licenses_count += $quantity;
                    continue;
                }

                $license_duration_type = get_post_meta($wplm_product_id, '_wplm_license_duration_type', true);
                $license_duration_value = get_post_meta($wplm_product_id, '_wplm_license_duration_value', true);
                $activation_limit = get_post_meta($wplm_product_id, '_wplm_activation_limit', true);

                for ($i = 0; $i < $quantity; $i++) {
                    try {
                        $license_args = [
                            'product_id' => $wplm_product_id,
                            'customer_email' => $customer_email,
                            'status' => 'active',
                            'activation_limit' => empty($activation_limit) ? 0 : intval($activation_limit),
                            'duration_type' => $license_duration_type,
                            'duration_value' => intval($license_duration_value),
                        ];

                        $new_license_id = $license_manager->generate_license($license_args);
                        
                        if (is_wp_error($new_license_id)) {
                            throw new Exception($new_license_id->get_error_message());
                        }

                        // Link license to order and original WC product (if available)
                        update_post_meta($new_license_id, '_wplm_order_id', $order_id);

                        // Find original WC product ID for the WPLM product_id
                        $original_wc_product_id = get_posts([
                            'post_type' => 'product',
                            'meta_key' => '_wplm_wc_linked_wplm_product_id',
                            'meta_value' => $wplm_product_id,
                            'fields' => 'ids',
                            'posts_per_page' => 1,
                        ]);

                        if (!empty($original_wc_product_id)) {
                            update_post_meta($new_license_id, '_wplm_wc_product_id', $original_wc_product_id[0]);
                        }
                        
                        $generated_licenses_count++;
                        $generated_licenses_details[] = [
                            'license_id' => $new_license_id,
                            'license_key' => get_the_title($new_license_id),
                            'product_name' => $product_post->post_title,
                            'order_id' => $order_id,
                            'customer_email' => $customer_email,
                        ];

                        // Send license email
                        if ($send_emails && class_exists('WPLM_Notification_Manager')) {
                            $notification_manager = new WPLM_Notification_Manager();
                            $notification_manager->send_license_delivery_email($customer_email, get_the_title($new_license_id), $order_id, $order);
                        }

                    } catch (Exception $e) {
                        $failed_licenses_count++;
                        error_log('WPLM License Generation Error (Order ID: ' . $order_id . ', Product ID: ' . $wplm_product_id . '): ' . $e->getMessage());
                    }
                }
            }
        }

        $message = sprintf(
            __('License generation complete: %d licenses generated, %d failed.', 'wp-license-manager'),
            $generated_licenses_count, $failed_licenses_count
        );

        if ($failed_licenses_count > 0) {
            wp_send_json_error(['message' => $message, 'details' => $generated_licenses_details]);
        } else {
            wp_send_json_success(['message' => $message, 'details' => $generated_licenses_details]);
        }
    }

    /**
     * AJAX: Bulk set expiry date for licenses
     */
    public function ajax_bulk_set_expiry() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        $new_expiry_date = sanitize_text_field($_POST['new_expiry_date']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        if (empty($new_expiry_date) || !strtotime($new_expiry_date)) {
            wp_send_json_error(['message' => __('Invalid expiry date provided.', 'wp-license-manager')]);
        }

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                update_post_meta($license_id, '_wplm_expiry_date', $new_expiry_date);
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_set_expiry', sprintf(__('License expiry date set to %s via bulk operation.', 'wp-license-manager'), $new_expiry_date), [
                        'new_expiry_date' => $new_expiry_date
                    ]);
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully set expiry date for %d licenses.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX: Bulk set activation limit for licenses
     */
    public function ajax_bulk_set_activation_limit() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        $new_limit = intval($_POST['new_limit']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        if ($new_limit < 0) {
            wp_send_json_error(['message' => __('Activation limit cannot be negative.', 'wp-license-manager')]);
        }

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                update_post_meta($license_id, '_wplm_activation_limit', $new_limit);
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_set_activation_limit', sprintf(__('License activation limit set to %d via bulk operation.', 'wp-license-manager'), $new_limit), [
                        'new_activation_limit' => $new_limit
                    ]);
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully set activation limit for %d licenses.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX: Bulk change product for licenses
     */
    public function ajax_bulk_change_product() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        $new_product_id = intval($_POST['new_product_id']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        if ($new_product_id <= 0 || get_post_type($new_product_id) !== 'wplm_product') {
            wp_send_json_error(['message' => __('Invalid new product selected.', 'wp-license-manager')]);
        }
        $new_product_title = get_the_title($new_product_id);

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                update_post_meta($license_id, '_wplm_product_id', $new_product_id);
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_change_product', sprintf(__('License product changed to %s (ID: %d) via bulk operation.', 'wp-license-manager'), $new_product_title, $new_product_id), [
                        'new_product_id' => $new_product_id,
                        'new_product_name' => $new_product_title
                    ]);
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully changed product for %d licenses.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX: Bulk transfer licenses to another customer
     */
    public function ajax_bulk_transfer_customer() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        $new_customer_id = intval($_POST['new_customer_id']);
        
        if (empty($license_ids)) {
            wp_send_json_error(['message' => __('No licenses selected.', 'wp-license-manager')]);
        }

        if ($new_customer_id <= 0 || get_post_type($new_customer_id) !== 'wplm_customer') {
            wp_send_json_error(['message' => __('Invalid new customer selected.', 'wp-license-manager')]);
        }
        $new_customer_email = get_post_meta($new_customer_id, '_wplm_customer_email', true);
        $new_customer_name = get_the_title($new_customer_id);

        $updated_count = 0;
        foreach ($license_ids as $license_id) {
            if (get_post_type($license_id) === 'wplm_license') {
                // Remove license from old customer (if any)
                $old_customer_id = get_post_meta($license_id, '_wplm_customer_id', true);
                $old_license_key = get_the_title($license_id);
                if ($old_customer_id && $old_customer_id !== $new_customer_id) {
                    $old_customer_licenses = get_post_meta($old_customer_id, '_wplm_license_keys', true);
                    if (is_array($old_customer_licenses)) {
                        $old_customer_licenses = array_diff($old_customer_licenses, [$old_license_key]);
                        update_post_meta($old_customer_id, '_wplm_license_keys', array_values($old_customer_licenses));
                    }
                }

                // Assign to new customer
                update_post_meta($license_id, '_wplm_customer_id', $new_customer_id);
                update_post_meta($license_id, '_wplm_customer_email', $new_customer_email);

                // Add license to new customer's license keys list
                $new_customer_licenses = get_post_meta($new_customer_id, '_wplm_license_keys', true);
                if (!is_array($new_customer_licenses)) {
                    $new_customer_licenses = [];
                }
                if (!in_array($old_license_key, $new_customer_licenses)) {
                    $new_customer_licenses[] = $old_license_key;
                    update_post_meta($new_customer_id, '_wplm_license_keys', $new_customer_licenses);
                }
                
                $updated_count++;
                
                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log($license_id, 'license_bulk_transfer_customer', sprintf(__('License transferred to customer %s (ID: %d) via bulk operation.', 'wp-license-manager'), $new_customer_name, $new_customer_id), [
                        'new_customer_id' => $new_customer_id,
                        'new_customer_name' => $new_customer_name,
                        'old_customer_id' => $old_customer_id
                    ]);
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully transferred %d licenses to new customer.', 'wp-license-manager'), $updated_count),
            'updated_count' => $updated_count
        ]);
    }

    /**
     * AJAX: Get licenses for bulk management
     */
    public function ajax_get_licenses_for_bulk() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $status = sanitize_text_field($_POST['status'] ?? '');
        $product_id = intval($_POST['product'] ?? 0);
        $customer_id = intval($_POST['customer'] ?? 0);
        $expiry_from = sanitize_text_field($_POST['expiry_from'] ?? '');
        $expiry_to = sanitize_text_field($_POST['expiry_to'] ?? '');

        $args = [
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [],
        ];

        if (!empty($status)) {
            $args['meta_query'][] = [
                'key' => '_wplm_status',
                'value' => $status,
                'compare' => '=',
            ];
        }

        if ($product_id > 0) {
            $args['meta_query'][] = [
                'key' => '_wplm_product_id',
                'value' => $product_id,
                'compare' => '=',
            ];
        }

        if ($customer_id > 0) {
            $args['meta_query'][] = [
                'key' => '_wplm_customer_id',
                'value' => $customer_id,
                'compare' => '=',
            ];
        }

        if (!empty($expiry_from) && !empty($expiry_to)) {
            $args['meta_query'][] = [
                'key' => '_wplm_expiry_date',
                'value' => [$expiry_from, $expiry_to],
                'compare' => 'BETWEEN',
                'type' => 'DATE',
            ];
        } else if (!empty($expiry_from)) {
            $args['meta_query'][] = [
                'key' => '_wplm_expiry_date',
                'value' => $expiry_from,
                'compare' => '>=',
                'type' => 'DATE',
            ];
        } else if (!empty($expiry_to)) {
            $args['meta_query'][] = [
                'key' => '_wplm_expiry_date',
                'value' => $expiry_to,
                'compare' => '<=',
                'type' => 'DATE',
            ];
        }

        // Ensure meta_query is only added if there are actual clauses.
        if (empty($args['meta_query'])) {
            unset($args['meta_query']);
        }

        $licenses = get_posts($args);
        $formatted_licenses = [];

        foreach ($licenses as $license) {
            $license_id = $license->ID;
            $product_id = get_post_meta($license_id, '_wplm_product_id', true);
            $customer_id = get_post_meta($license_id, '_wplm_customer_id', true);

            $product_title = $product_id ? get_the_title($product_id) : __('N/A', 'wp-license-manager');
            $customer_email = $customer_id ? get_post_meta($customer_id, '_wplm_customer_email', true) : __('N/A', 'wp-license-manager');
            $customer_name = $customer_id ? get_the_title($customer_id) : __('N/A', 'wp-license-manager');

            $formatted_licenses[] = [
                'id' => $license_id,
                'key' => $license->post_title,
                'product' => $product_title,
                'product_id' => $product_id,
                'customer' => $customer_name,
                'customer_id' => $customer_id,
                'customer_email' => $customer_email,
                'status' => get_post_meta($license_id, '_wplm_status', true),
                'expiry' => get_post_meta($license_id, '_wplm_expiry_date', true),
                'activations' => count(get_post_meta($license_id, '_wplm_activated_domains', true)),
                'activation_limit' => get_post_meta($license_id, '_wplm_activation_limit', true),
            ];
        }

        wp_send_json_success([
            'licenses' => $formatted_licenses,
            'message' => sprintf(__('Found %d licenses.', 'wp-license-manager'), count($formatted_licenses))
        ]);
    }

    /**
     * AJAX handler to preview licenses for bulk update.
     */
    public function ajax_preview_bulk_update() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $filters = isset($_POST['filters']) ? map_deep(wp_unslash($_POST['filters']), 'sanitize_text_field') : [];

        $licenses_data = $this->get_filtered_licenses($filters);

        wp_send_json_success(['licenses' => $licenses_data]);
    }

    /**
     * Handles AJAX request for bulk updating licenses (status, activation limit, expiry).
     */
    public function ajax_bulk_update_licenses() {
        check_ajax_referer('wplm_bulk_operations', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $filters = isset($_POST['filters']) ? map_deep(wp_unslash($_POST['filters']), 'sanitize_text_field') : [];
        $update_data = isset($_POST['update_data']) ? map_deep(wp_unslash($_POST['update_data']), 'sanitize_text_field') : [];

        if (empty($update_data)) {
            wp_send_json_error(['message' => __('No update data provided.', 'wp-license-manager')]);
        }

        $licenses_to_update = $this->get_filtered_licenses($filters);

        if (empty($licenses_to_update)) {
            wp_send_json_error(['message' => __('No licenses found matching the criteria.', 'wp-license-manager')]);
        }

        $updated_count = 0;
        foreach ($licenses_to_update as $license) {
            $license_id = $license->license_id;
            $current_license_data = WPLM_Database::get_license_by('id', $license_id);

            if (!$current_license_data) {
                continue;
            }

            $update_fields = [];
            $activity_log_message = sprintf(__('Bulk update applied to License ID: %s. Changes: ', 'wp-license-manager'), $license_id);
            $changes_made = [];

            // Update Status
            if (!empty($update_data['new_status']) && $update_data['new_status'] !== $current_license_data->license_status) {
                $update_fields['license_status'] = $update_data['new_status'];
                $changes_made[] = sprintf(__('Status changed from %s to %s', 'wp-license-manager'), $current_license_data->license_status, $update_data['new_status']);
            }

            // Update Activation Limit
            if (!empty($update_data['new_activation_limit']) && is_numeric($update_data['new_activation_limit'])) {
                $new_limit = absint($update_data['new_activation_limit']);
                if ($new_limit !== (int)$current_license_data->activation_limit) {
                    $update_fields['activation_limit'] = $new_limit;
                    $changes_made[] = sprintf(__('Activation limit changed from %d to %d', 'wp-license-manager'), (int)$current_license_data->activation_limit, $new_limit);
                }
            }

            // Extend Expiry Date
            if (!empty($update_data['extend_expiry_days']) && is_numeric($update_data['extend_expiry_days'])) {
                $days_to_add = absint($update_data['extend_expiry_days']);
                if ($days_to_add > 0) {
                    $current_expiry_timestamp = strtotime($current_license_data->expire_date);
                    $new_expiry_timestamp = strtotime("+{$days_to_add} days", $current_expiry_timestamp);
                    $new_expiry_date = date('Y-m-d H:i:s', $new_expiry_timestamp);

                    if ($new_expiry_date !== $current_license_data->expire_date) {
                        $update_fields['expire_date'] = $new_expiry_date;
                        $changes_made[] = sprintf(__('Expiry date extended by %d days (from %s to %s)', 'wp-license-manager'), $days_to_add, $current_license_data->expire_date, $new_expiry_date);
                    }
                }
            }

            if (!empty($update_fields)) {
                WPLM_Database::update_license($license_id, $update_fields);
                WPLM_Activity_Logger::log_activity(sprintf('%s%s', $activity_log_message, implode(', ', $changes_made)), $license_id, 'license', 'bulk_update');
                $updated_count++;
            }
        }

        wp_send_json_success(['message' => sprintf(__('%d licenses updated successfully.', 'wp-license-manager'), $updated_count)]);
    }

    /**
     * Helper function to get filtered licenses based on various criteria.
     */
    private function get_filtered_licenses($filters) {
        $args = [
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [],
        ];

        if (!empty($filters['license_status'])) {
            $args['meta_query'][] = [
                'key' => '_wplm_status',
                'value' => sanitize_text_field($filters['license_status']),
                'compare' => '=',
            ];
        }

        if (!empty($filters['product_id'])) {
            $args['meta_query'][] = [
                'key' => '_wplm_product_id',
                'value' => absint($filters['product_id']),
                'compare' => '=',
            ];
        }

        $licenses_data = [];
        $licenses = get_posts($args);

        foreach ($licenses as $license) {
            $license_id = $license->ID;
            $product_id = get_post_meta($license_id, '_wplm_product_id', true);
            $customer_id = get_post_meta($license_id, '_wplm_customer_id', true);

            $product_title = $product_id ? get_the_title($product_id) : __('N/A', 'wp-license-manager');
            $customer_email = $customer_id ? get_post_meta($customer_id, '_wplm_customer_email', true) : __('N/A', 'wp-license-manager');
            $customer_name = $customer_id ? get_the_title($customer_id) : __('N/A', 'wp-license-manager');

            $licenses_data[] = ('object' === $this->return_format) ? (object)[
                'license_id' => $license_id,
                'license_key' => $license->post_title,
                'product' => $product_title,
                'product_id' => $product_id,
                'customer' => $customer_name,
                'customer_id' => $customer_id,
                'customer_email' => $customer_email,
                'license_status' => get_post_meta($license_id, '_wplm_status', true),
                'expire_date' => get_post_meta($license_id, '_wplm_expiry_date', true),
                'activation_count' => count(get_post_meta($license_id, '_wplm_activated_domains', true)),
                'activation_limit' => get_post_meta($license_id, '_wplm_activation_limit', true),
            ] : [
                'license_id' => $license_id,
                'license_key' => $license->post_title,
                'product' => $product_title,
                'product_id' => $product_id,
                'customer' => $customer_name,
                'customer_id' => $customer_id,
                'customer_email' => $customer_email,
                'status' => get_post_meta($license_id, '_wplm_status', true),
                'expiry' => get_post_meta($license_id, '_wplm_expiry_date', true),
                'activations' => count(get_post_meta($license_id, '_wplm_activated_domains', true)),
                'activation_limit' => get_post_meta($license_id, '_wplm_activation_limit', true),
            ];
        }
        return $licenses_data;
    }
}
