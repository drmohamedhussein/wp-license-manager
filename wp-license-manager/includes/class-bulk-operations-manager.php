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
        .wplm-bulk-operations .nav-tab-wrapper { margin-bottom: 20px; }
        .wplm-tab-content { display: none; }
        .wplm-tab-content.active { display: block; }
        .wplm-bulk-section { background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; }
        .wplm-bulk-form { max-width: 600px; }
        .wplm-progress-bar { width: 100%; height: 20px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin: 10px 0; display: none; }
        .wplm-progress-fill { height: 100%; background: linear-gradient(90deg, #007cba, #00a0d2); transition: width 0.3s ease; }
        .wplm-result-box { margin-top: 20px; padding: 15px; border-radius: 5px; display: none; }
        .wplm-result-box.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .wplm-result-box.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .wplm-license-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 20px; }
        .wplm-license-item { border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9; }
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
                        <th scope="row"><?php _e('Number of Licenses', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="number" name="license_count" min="1" max="1000" value="10" required />
                            <p class="description"><?php _e('How many licenses to create (max 1000).', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Product', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="product_id" required>
                                <option value=""><?php _e('Select Product', 'wp-license-manager'); ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo esc_attr($product->post_name); ?>"><?php echo esc_html($product->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Customer Email', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="email" name="customer_email" />
                            <p class="description"><?php _e('Leave empty to create unassigned licenses.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Duration', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="duration_type">
                                <option value="lifetime"><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                                <option value="days"><?php _e('Days', 'wp-license-manager'); ?></option>
                                <option value="months"><?php _e('Months', 'wp-license-manager'); ?></option>
                                <option value="years"><?php _e('Years', 'wp-license-manager'); ?></option>
                            </select>
                            <input type="number" name="duration_value" min="1" value="1" style="width: 80px;" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Activation Limit', 'wp-license-manager'); ?></th>
                        <td>
                            <input type="number" name="activation_limit" min="1" value="1" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Status', 'wp-license-manager'); ?></th>
                        <td>
                            <select name="status">
                                <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="submit" class="button-primary"><?php _e('Create Licenses', 'wp-license-manager'); ?></button>
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
                    <label>
                        <?php _e('Filter by Status:', 'wp-license-manager'); ?>
                        <select id="license-status-filter">
                            <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                            <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                            <option value="inactive"><?php _e('Inactive', 'wp-license-manager'); ?></option>
                            <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                        </select>
                    </label>
                    
                    <label>
                        <?php _e('Filter by Product:', 'wp-license-manager'); ?>
                        <select id="license-product-filter">
                            <option value=""><?php _e('All Products', 'wp-license-manager'); ?></option>
                            <?php
                            $products = get_posts(['post_type' => 'wplm_product', 'numberposts' => -1]);
                            foreach ($products as $product):
                            ?>
                                <option value="<?php echo esc_attr($product->post_name); ?>"><?php echo esc_html($product->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <button type="button" id="load-licenses" class="button"><?php _e('Load Licenses', 'wp-license-manager'); ?></button>
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
                    <button type="button" class="button bulk-action button-link-delete" data-action="delete"><?php _e('Delete Selected', 'wp-license-manager'); ?></button>
                </div>
                
                <div id="extend-options" style="display: none; margin-top: 15px; padding: 15px; background: #f0f0f0; border-radius: 5px;">
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
                    <button type="button" id="cancel-extend" class="button"><?php _e('Cancel', 'wp-license-manager'); ?></button>
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
        $product_id = sanitize_text_field($_POST['product_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
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
                // Get license key before deactivating
                $license_key = get_the_title($license_id);
                $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
                
                update_post_meta($license_id, '_wplm_status', 'inactive');
                // Free activations by clearing active domains and related metadata
                update_post_meta($license_id, '_wplm_activated_domains', []);
                $fingerprints = get_post_meta($license_id, '_wplm_fingerprints', true);
                if (!empty($fingerprints)) {
                    update_post_meta($license_id, '_wplm_fingerprints', []);
                }
                $site_data = get_post_meta($license_id, '_wplm_site_data', true);
                if (!empty($site_data)) {
                    update_post_meta($license_id, '_wplm_site_data', []);
                }
                $updated_count++;
                
                // Trigger automatic plugin deactivation for each domain
                foreach ($activated_domains as $domain) {
                    do_action('wplm_license_deactivated_for_plugin_deactivation', $license_id, $domain, $license_key);
                }
                
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

    // Additional AJAX handlers for other bulk operations would go here...
}
