<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bulk Operations Manager - UI Rendering
 * Part 1 of the split WPLM_Bulk_Operations_Manager class
 */
class WPLM_Bulk_Operations_Manager_UI {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu'], 100);
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

        <?php $this->render_styles_and_scripts(); ?>
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
                            <select id="bulk_create_product_id" name="product_id" required>
                                <option value=""><?php esc_html_e('-- Select Product --', 'wp-license-manager'); ?></option>
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo esc_attr($product->ID); ?>">
                                        <?php echo esc_html($product->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_customer_email"><?php esc_html_e('Customer Email', 'wp-license-manager'); ?></label></th>
                        <td>
                            <input type="email" id="bulk_create_customer_email" name="customer_email" class="regular-text" required />
                            <p class="description"><?php esc_html_e('Email address for the customer.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_duration_type"><?php esc_html_e('License Duration', 'wp-license-manager'); ?></label></th>
                        <td>
                            <select id="bulk_create_duration_type" name="duration_type">
                                <option value="lifetime"><?php esc_html_e('Lifetime', 'wp-license-manager'); ?></option>
                                <option value="days"><?php esc_html_e('Days', 'wp-license-manager'); ?></option>
                                <option value="months"><?php esc_html_e('Months', 'wp-license-manager'); ?></option>
                                <option value="years"><?php esc_html_e('Years', 'wp-license-manager'); ?></option>
                            </select>
                            <input type="number" id="bulk_create_duration_value" name="duration_value" min="1" value="1" style="width: 80px;" />
                            <p class="description"><?php esc_html_e('Duration of the license.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bulk_create_activation_limit"><?php esc_html_e('Activation Limit', 'wp-license-manager'); ?></label></th>
                        <td>
                            <input type="number" id="bulk_create_activation_limit" name="activation_limit" min="1" value="1" />
                            <p class="description"><?php esc_html_e('Maximum number of domains this license can be activated on.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary" id="bulk-create-submit">
                        <?php esc_html_e('Create Licenses', 'wp-license-manager'); ?>
                    </button>
                </p>
            </form>
            
            <div class="wplm-progress-bar" id="bulk-create-progress">
                <div class="wplm-progress-fill"></div>
            </div>
            
            <div class="wplm-result-box" id="bulk-create-result"></div>
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
            <p><?php _e('Select licenses and perform bulk operations on them.', 'wp-license-manager'); ?></p>
            
            <div class="wplm-filter-controls">
                <div>
                    <label for="filter_status"><?php _e('Status', 'wp-license-manager'); ?></label>
                    <select id="filter_status">
                        <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                        <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                        <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="filter_product"><?php _e('Product', 'wp-license-manager'); ?></label>
                    <select id="filter_product">
                        <option value=""><?php _e('All Products', 'wp-license-manager'); ?></option>
                        <?php
                        $products = get_posts(['post_type' => 'wplm_product', 'numberposts' => -1]);
                        foreach ($products as $product) {
                            echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label for="filter_customer"><?php _e('Customer Email', 'wp-license-manager'); ?></label>
                    <input type="email" id="filter_customer" placeholder="<?php esc_attr_e('Search by email...', 'wp-license-manager'); ?>" />
                </div>
                <div>
                    <button type="button" class="button" id="filter-licenses">
                        <?php _e('Filter Licenses', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
            
            <div class="wplm-action-buttons">
                <button type="button" class="button button-primary" id="bulk-activate">
                    <?php _e('Activate Selected', 'wp-license-manager'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bulk-deactivate">
                    <?php _e('Deactivate Selected', 'wp-license-manager'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bulk-delete">
                    <?php _e('Delete Selected', 'wp-license-manager'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bulk-extend">
                    <?php _e('Extend Selected', 'wp-license-manager'); ?>
                </button>
            </div>
            
            <div class="wplm-license-grid" id="license-grid">
                <p><?php _e('Use the filter controls above to load licenses for bulk operations.', 'wp-license-manager'); ?></p>
            </div>
            
            <div class="wplm-progress-bar" id="bulk-manage-progress">
                <div class="wplm-progress-fill"></div>
            </div>
            
            <div class="wplm-result-box" id="bulk-manage-result"></div>
        </div>
        <?php
    }

    /**
     * Render WooCommerce orders section
     */
    private function render_wc_orders_section() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="wplm-bulk-section"><p>' . __('WooCommerce is not active. This feature requires WooCommerce to be installed and activated.', 'wp-license-manager') . '</p></div>';
            return;
        }
        ?>
        <div class="wplm-bulk-section">
            <h2><?php _e('Generate Licenses from WooCommerce Orders', 'wp-license-manager'); ?></h2>
            <p><?php _e('Scan WooCommerce orders and generate licenses for products that don\'t have them yet.', 'wp-license-manager'); ?></p>
            
            <div class="wplm-filter-controls">
                <div>
                    <label for="wc_order_status"><?php _e('Order Status', 'wp-license-manager'); ?></label>
                    <select id="wc_order_status">
                        <option value="completed"><?php _e('Completed', 'wp-license-manager'); ?></option>
                        <option value="processing"><?php _e('Processing', 'wp-license-manager'); ?></option>
                        <option value="on-hold"><?php _e('On Hold', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="wc_date_from"><?php _e('Date From', 'wp-license-manager'); ?></label>
                    <input type="date" id="wc_date_from" />
                </div>
                <div>
                    <label for="wc_date_to"><?php _e('Date To', 'wp-license-manager'); ?></label>
                    <input type="date" id="wc_date_to" />
                </div>
                <div>
                    <button type="button" class="button button-primary" id="scan-wc-orders">
                        <?php _e('Scan Orders', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
            
            <div id="wc-orders-results" style="display: none;">
                <h3><?php _e('Orders Found', 'wp-license-manager'); ?></h3>
                <div id="wc-orders-list"></div>
                
                <div class="wplm-bulk-action-options">
                    <h4><?php _e('License Generation Options', 'wp-license-manager'); ?></h4>
                    <label>
                        <input type="checkbox" id="wc_auto_create_products" value="1" checked />
                        <?php _e('Automatically create WPLM products for WooCommerce products', 'wp-license-manager'); ?>
                    </label>
                    <label>
                        <input type="checkbox" id="wc_auto_create_customers" value="1" checked />
                        <?php _e('Automatically create WPLM customers for WooCommerce customers', 'wp-license-manager'); ?>
                    </label>
                    <br>
                    <button type="button" class="button button-primary" id="generate-wc-licenses">
                        <?php _e('Generate Licenses', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
            
            <div class="wplm-progress-bar" id="wc-orders-progress">
                <div class="wplm-progress-fill"></div>
            </div>
            
            <div class="wplm-result-box" id="wc-orders-result"></div>
        </div>
        <?php
    }

    /**
     * Render bulk update section
     */
    private function render_bulk_update_section() {
        ?>
        <div class="wplm-bulk-section">
            <h2><?php _e('Bulk Update Licenses', 'wp-license-manager'); ?></h2>
            <p><?php _e('Update multiple licenses with new values at once.', 'wp-license-manager'); ?></p>
            
            <div class="wplm-filter-controls">
                <div>
                    <label for="update_filter_status"><?php _e('Status', 'wp-license-manager'); ?></label>
                    <select id="update_filter_status">
                        <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                        <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                        <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                    </select>
                </div>
                <div>
                    <label for="update_filter_product"><?php _e('Product', 'wp-license-manager'); ?></label>
                    <select id="update_filter_product">
                        <option value=""><?php _e('All Products', 'wp-license-manager'); ?></option>
                        <?php
                        $products = get_posts(['post_type' => 'wplm_product', 'numberposts' => -1]);
                        foreach ($products as $product) {
                            echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <button type="button" class="button" id="update-filter-licenses">
                        <?php _e('Filter Licenses', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
            
            <div class="wplm-bulk-action-options">
                <h4><?php _e('Update Options', 'wp-license-manager'); ?></h4>
                
                <label>
                    <?php _e('Set Expiry Date:', 'wp-license-manager'); ?>
                    <input type="date" id="bulk_expiry_date" />
                    <button type="button" class="button" id="bulk-set-expiry">
                        <?php _e('Set Expiry', 'wp-license-manager'); ?>
                    </button>
                </label>
                
                <label>
                    <?php _e('Set Activation Limit:', 'wp-license-manager'); ?>
                    <input type="number" id="bulk_activation_limit" min="1" value="1" />
                    <button type="button" class="button" id="bulk-set-activation-limit">
                        <?php _e('Set Limit', 'wp-license-manager'); ?>
                    </button>
                </label>
                
                <label>
                    <?php _e('Change Product:', 'wp-license-manager'); ?>
                    <select id="bulk_new_product">
                        <option value=""><?php _e('-- Select New Product --', 'wp-license-manager'); ?></option>
                        <?php
                        foreach ($products as $product) {
                            echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                    <button type="button" class="button" id="bulk-change-product">
                        <?php _e('Change Product', 'wp-license-manager'); ?>
                    </button>
                </label>
                
                <label>
                    <?php _e('Transfer Customer:', 'wp-license-manager'); ?>
                    <input type="email" id="bulk_new_customer" placeholder="<?php esc_attr_e('New customer email', 'wp-license-manager'); ?>" />
                    <button type="button" class="button" id="bulk-transfer-customer">
                        <?php _e('Transfer Customer', 'wp-license-manager'); ?>
                    </button>
                </label>
            </div>
            
            <div class="wplm-license-grid" id="update-license-grid">
                <p><?php _e('Use the filter controls above to load licenses for bulk updates.', 'wp-license-manager'); ?></p>
            </div>
            
            <div class="wplm-progress-bar" id="bulk-update-progress">
                <div class="wplm-progress-fill"></div>
            </div>
            
            <div class="wplm-result-box" id="bulk-update-result"></div>
        </div>
        <?php
    }

    /**
     * Render styles and scripts
     */
    private function render_styles_and_scripts() {
        ?>
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

            // Duration type change handler
            $('#bulk_create_duration_type').on('change', function() {
                var durationType = $(this).val();
                var durationValue = $('#bulk_create_duration_value');
                
                if (durationType === 'lifetime') {
                    durationValue.prop('disabled', true);
                } else {
                    durationValue.prop('disabled', false);
                }
            });
        });
        </script>
        <?php
    }
}