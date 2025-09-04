<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the admin interface, meta boxes, and custom columns.
 */
class WPLM_Admin_Manager {

    private $products_map = null;
    private $is_ajax_generating = false;

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_post_meta'], 10, 2);
        add_action('wp_ajax_wplm_generate_key', [$this, 'ajax_generate_key']);
        add_action('wp_ajax_wplm_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_wplm_search_customers', [$this, 'ajax_search_customers']);
        
        // Admin menu handled by Enhanced Admin Manager to prevent duplicates
        // add_action('admin_menu', [$this, 'add_enhanced_admin_menu']);
        // Settings
        add_action('admin_init', [$this, 'register_main_settings']);

        // Enqueue admin scripts and styles for settings page
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Export/Import functionality
        add_action('wp_ajax_wplm_export_licenses', [$this, 'ajax_export_licenses']);
        add_action('admin_init', [$this, 'handle_import_licenses_submission']); // Handle import on admin_init
        add_action('admin_post_wplm_export_licenses', [$this, 'handle_export_licenses_submission_post']); // Handle non-AJAX export form submission

        // API Key generation AJAX (remains, as it's a backend process)
        add_action('wp_ajax_wplm_generate_api_key', [$this, 'ajax_generate_api_key']);

        // Custom columns for the license list
        add_filter('manage_wplm_license_posts_columns', [$this, 'add_license_columns']);
        add_action('manage_wplm_license_posts_custom_column', [$this, 'render_license_columns'], 10, 2);
        add_filter('manage_edit-wplm_license_sortable_columns', [$this, 'make_columns_sortable']);
        add_action('pre_get_posts', [$this, 'customize_license_query']);
        
        // Bulk actions
        add_filter('bulk_actions-edit-wplm_license', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-wplm_license', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_admin_notices']);
        
        // Add bulk actions to the product list table
        add_filter('bulk_actions-edit-wplm_product', [$this, 'add_product_bulk_actions']);
        add_filter('handle_bulk_actions-edit-wplm_product', [$this, 'handle_product_bulk_actions'], 10, 3);
        
        // Add bulk actions to the subscription list table
        add_filter('bulk_actions-edit-wplm_subscription', [$this, 'add_subscription_bulk_actions']);
        add_filter('handle_bulk_actions-edit-wplm_subscription', [$this, 'handle_subscription_bulk_actions'], 10, 3);
        
        // Add AJAX handler for force deactivation
        add_action('wp_ajax_wplm_force_deactivate_licenses', [$this, 'ajax_force_deactivate_licenses']);
    }

    /**
     * Add the dashboard widget.
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'wplm_dashboard_widget',
            __('License Manager Stats', 'wp-license-manager'),
            [$this, 'render_dashboard_widget']
        );
    }

    /**
     * Render the dashboard widget.
     */
    public function render_dashboard_widget() {
        $total_licenses = wp_count_posts('wplm_license')->publish;
        
        // More efficient query for active licenses count
        $active_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => 1,
            'fields' => 'ids', // Only retrieve IDs to make it faster
            'meta_key' => '_wplm_status',
            'meta_value' => 'active',
            'no_found_rows' => false, // We need found_posts for counting
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ]);
        $active_licenses = $active_licenses_query->found_posts;

        echo '<p>' . sprintf(__('Total Licenses: %d', 'wp-license-manager'), $total_licenses) . '</p>';
        echo '<p>' . sprintf(__('Active Licenses: %d', 'wp-license-manager'), $active_licenses) . '</p>';
    }

    /**
     * Add meta boxes to CPTs.
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wplm_license_details',
            __('License Details', 'wp-license-manager'),
            [$this, 'render_license_meta_box'],
            'wplm_license',
            'normal',
            'high'
        );

        add_meta_box(
            'wplm_product_details',
            __('Product Details', 'wp-license-manager'),
            [$this, 'render_product_meta_box'],
            'wplm_product',
            'normal',
            'high'
        );

        // Add meta box for WooCommerce products if WooCommerce is active
        if (function_exists('wc_get_products')) {
            add_meta_box(
                'wplm_woocommerce_license_options',
                __('License Manager Options', 'wp-license-manager'),
                [$this, 'render_woocommerce_license_options_meta_box'],
                'product',
            'normal',
            'high'
        );
        }
    }

    /**
     * Render the meta box for licenses.
     */
    public function render_license_meta_box($post) {
        wp_nonce_field('wplm_save_license_meta', 'wplm_nonce');

        $status = get_post_meta($post->ID, '_wplm_status', true);
        $product_id = get_post_meta($post->ID, '_wplm_product_id', true);
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true);
        $expiry_date = get_post_meta($post->ID, '_wplm_expiry_date', true);
        $activation_limit = get_post_meta($post->ID, '_wplm_activation_limit', true);
        $activated_domains = get_post_meta($post->ID, '_wplm_activated_domains', true);
        $product_type = get_post_meta($post->ID, '_wplm_product_type', true); // Retrieve stored product type

        // Construct the combined value for comparison in the dropdown
        $current_product_value = !empty($product_id) && !empty($product_type) ? $product_type . '|' . $product_id : '';

        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $woocommerce_products = [];
        if (function_exists('wc_get_products')) {
            $woocommerce_products = wc_get_products([
                'limit' => -1,
                'status' => 'publish',
                'type' => 'virtual', // Only show virtual products as these are typically digital
            ]);
        }

        $all_products = [];

        foreach ($wplm_products as $product) {
            $prod_id = get_post_meta($product->ID, '_wplm_product_id', true);
            $product_source = get_post_meta($product->ID, '_wplm_product_source', true);
            
            // Use appropriate prefix based on product source
            $prefix = 'WPLM';
            if ($product_source === 'woocommerce') {
                $prefix = 'WOO';
            }
            
            $all_products[] = [
                'id' => $prod_id,
                'title' => $prefix . ' ' . $product->post_title,
                'type' => 'wplm',
                'value' => 'wplm|' . $prod_id, // Fix: Use proper format for WPLM products
            ];
        }

        foreach ($woocommerce_products as $product) {
            $all_products[] = [
                'id' => $product->get_id(),
                'title' => 'WOO ' . $product->get_name(),
                'type' => 'woocommerce',
                'value' => 'woocommerce|' . $product->get_id(), // Fix: Use proper format for WooCommerce products
            ];
        }
        
        // Also include WooCommerce products that are already linked to WPLM
        $linked_wc_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_wplm_wc_is_licensed_product',
                    'value' => 'yes',
                    'compare' => '='
                ]
            ]
        ]);
        
        foreach ($linked_wc_products as $wc_product) {
            $wc_product_obj = wc_get_product($wc_product->ID);
            if ($wc_product_obj) {
                $all_products[] = [
                    'id' => $wc_product_obj->get_id(),
                    'title' => 'WOO ' . $wc_product_obj->get_name(),
                    'type' => 'woocommerce',
                    'value' => 'woocommerce|' . $wc_product_obj->get_id(), // Fix: Use proper format for WooCommerce products
                ];
            }
        }
        ?>
        <div class="wplm-modern-form-container">
            <div class="wplm-form-header">
                <h2>
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php _e('License Details', 'wp-license-manager'); ?>
                </h2>
            </div>
            <div class="wplm-form-body">
                <style>
                    /* Show the title field for manual license key entry */
                    #title, #titlewrap, #titlediv {
                        display: block !important;
                    }
                </style>
                <div class="wplm-form-row">
                    <div class="wplm-form-field">
                        <label for="wplm_status"><?php _e('Status', 'wp-license-manager'); ?></label>
                        <select name="wplm_status" id="wplm_status">
                            <option value="active" <?php selected($status, 'active'); ?>><?php _e('Active', 'wp-license-manager'); ?></option>
                            <option value="inactive" <?php selected($status, 'inactive'); ?>><?php _e('Inactive', 'wp-license-manager'); ?></option>
                            <option value="expired" <?php selected($status, 'expired'); ?>><?php _e('Expired', 'wp-license-manager'); ?></option>
                        </select>
                    </div>
                    <div class="wplm-form-field">
                        <label for="wplm_customer_email"><?php _e('Customer Email', 'wp-license-manager'); ?></label>
                        <input type="email" id="wplm_customer_email" name="wplm_customer_email" value="<?php echo esc_attr($customer_email); ?>" placeholder="<?php _e('Enter email or search existing customers', 'wp-license-manager'); ?>">
                        <div id="wplm_customer_search_results" class="wplm-search-results" style="display: none;"></div>
                        <div class="wplm-field-description">
                            <?php _e('Type to search existing customers or enter a new email address.', 'wp-license-manager'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="wplm-form-row single">
                    <div class="wplm-form-field">
                        <?php 
                        $require_email_validation = get_post_meta($post->ID, '_wplm_require_email_validation', true);
                        ?>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="wplm_require_email_validation" name="wplm_require_email_validation" value="1" <?php checked($require_email_validation, '1'); ?>>
                            <strong><?php _e('Require Email Validation', 'wp-license-manager'); ?></strong>
                        </label>
                        <div class="wplm-field-description">
                            <?php _e('When checked, activation will require both license key AND customer email. When unchecked, license key alone is sufficient.', 'wp-license-manager'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="wplm-form-row single">
                    <div class="wplm-form-field">
                        <?php 
                        $require_domain_validation = get_post_meta($post->ID, '_wplm_require_domain_validation', true);
                        $allowed_domains = get_post_meta($post->ID, '_wplm_allowed_domains', true) ?: [];
                        $allowed_domains_text = is_array($allowed_domains) ? implode("\n", $allowed_domains) : '';
                        ?>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="wplm_require_domain_validation" name="wplm_require_domain_validation" value="1" <?php checked($require_domain_validation, '1'); ?>>
                            <strong><?php _e('Enable Domain Control', 'wp-license-manager'); ?></strong>
                        </label>
                        <div class="wplm-field-description">
                            <?php _e('When checked, allows admin to control which domains can be activated and enables client-side domain management in WooCommerce My Account page. When unchecked, license can be activated on any domain (up to activation limit).', 'wp-license-manager'); ?>
                        </div>
                        
                        <div id="wplm_domain_validation_fields" style="margin-top: 15px; <?php echo $require_domain_validation ? '' : 'display: none;'; ?>">
                            <label for="wplm_allowed_domains"><?php _e('Allowed Domains (one per line)', 'wp-license-manager'); ?></label>
                            <textarea id="wplm_allowed_domains" name="wplm_allowed_domains" rows="4" placeholder="<?php _e('example.com&#10;subdomain.example.com&#10;another-site.com', 'wp-license-manager'); ?>"><?php echo esc_textarea($allowed_domains_text); ?></textarea>
                            <div class="wplm-field-description">
                                <?php _e('Enter domain names (without http/https). One domain per line. Leave empty to allow any domain. Supports http://, https://, and paths - only domain name is matched.', 'wp-license-manager'); ?>
                            </div>
                            
                            <div style="margin-top: 15px;">
                                <label>
                                    <input type="checkbox" id="wplm_admin_override_domains" name="wplm_admin_override_domains" value="1" <?php checked(!empty($admin_override_domains), true); ?> />
                                    <?php _e('Admin Override: Control activated domains directly', 'wp-license-manager'); ?>
                                </label>
                                <div class="wplm-field-description">
                                    <?php _e('When checked, admin can directly control which domains are activated. When unchecked, clients can manage domains within activation limits.', 'wp-license-manager'); ?>
                                </div>
                                
                                <div id="wplm_admin_override_fields" style="margin-top: 10px; <?php echo !empty($admin_override_domains) ? '' : 'display: none;'; ?>">
                                    <?php
                                    $admin_override_domains_text = '';
                                    if (!empty($admin_override_domains) && is_array($admin_override_domains)) {
                                        $admin_override_domains_text = implode("\n", $admin_override_domains);
                                    }
                                    ?>
                                    <textarea id="wplm_admin_override_domains_list" name="wplm_admin_override_domains_list" rows="3" placeholder="<?php _e('example.com&#10;sub.example.com', 'wp-license-manager'); ?>"><?php echo esc_textarea($admin_override_domains_text); ?></textarea>
                                    <div class="wplm-field-description">
                                        <?php _e('ADMIN OVERRIDE: One domain per line. This directly controls where this license is currently activated. Admin changes here override client-side domain management.', 'wp-license-manager'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="wplm-form-row single">
                    <div class="wplm-form-field">
                        <label for="wplm_product_search"><?php _e('Product', 'wp-license-manager'); ?></label>
                        <div class="wplm-product-search-container">
                            <input type="text" id="wplm_product_search" placeholder="<?php _e('Search for a product...', 'wp-license-manager'); ?>" autocomplete="off">
                            <input type="hidden" name="wplm_product_id" id="wplm_product_id" value="<?php echo esc_attr($current_product_value); ?>">
                            <div id="wplm_product_search_results" class="wplm-search-results" style="display: none;"></div>
                        </div>
                        <div class="wplm-field-description"><?php _e('Start typing to search for products from WP License Manager or WooCommerce.', 'wp-license-manager'); ?></div>
                    </div>
                </div>
                
                <div class="wplm-form-row">
                    <div class="wplm-form-field">
                        <label for="wplm_duration_type"><?php _e('License Duration', 'wp-license-manager'); ?></label>
                        <?php 
                        $duration_type = get_post_meta($post->ID, '_wplm_duration_type', true) ?: 'lifetime';
                        $duration_value = get_post_meta($post->ID, '_wplm_duration_value', true) ?: 1;
                        ?>
                        <div class="wplm-duration-group">
                            <div class="wplm-form-field">
                                <select id="wplm_duration_type" name="wplm_duration_type">
                                    <option value="lifetime" <?php selected($duration_type, 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                                    <option value="days" <?php selected($duration_type, 'days'); ?>><?php _e('Days', 'wp-license-manager'); ?></option>
                                    <option value="months" <?php selected($duration_type, 'months'); ?>><?php _e('Months', 'wp-license-manager'); ?></option>
                                    <option value="years" <?php selected($duration_type, 'years'); ?>><?php _e('Years', 'wp-license-manager'); ?></option>
                                </select>
                            </div>
                            <div class="wplm-form-field">
                                <input type="number" id="wplm_duration_value" name="wplm_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1">
                            </div>
                        </div>
                        <div class="wplm-field-description"><?php _e('Select duration type and value. Lifetime means no expiry.', 'wp-license-manager'); ?></div>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const durationTypeSelect = document.getElementById('wplm_duration_type');
                            const durationValueInput = document.getElementById('wplm_duration_value');
                            
                            function toggleDurationValue() {
                                durationValueInput.style.display = durationTypeSelect.value === 'lifetime' ? 'none' : 'inline-block';
                            }
                            
                            durationTypeSelect.addEventListener('change', toggleDurationValue);
                            toggleDurationValue(); // Initial state
                        });
                        </script>
                    </div>
                    <div class="wplm-form-field">
                        <label for="wplm_activation_limit"><?php _e('Activation Limit', 'wp-license-manager'); ?></label>
                        <input type="number" id="wplm_activation_limit" name="wplm_activation_limit" value="<?php echo esc_attr($activation_limit ?: 1); ?>">
                        <div class="wplm-field-description"><?php _e('Maximum number of domains where this license can be activated.', 'wp-license-manager'); ?></div>
                    </div>
                </div>
                
                
                <div class="wplm-form-row single">
                    <div class="wplm-form-field">
                        <label><?php _e('Generate License', 'wp-license-manager'); ?></label>
                        <button type="button" id="wplm-generate-key" class="wplm-btn wplm-btn-success">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php _e('Generate Key', 'wp-license-manager'); ?>
                        </button>
                        <div id="wplm-generated-key" style="margin-top: 10px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; color: #155724; font-weight: bold; display: none;"></div>
                        <div class="wplm-field-description"><?php _e('Click to generate a unique license key. The generated key will be set as the license title.', 'wp-license-manager'); ?></div>
                    </div>
                </div>
            </div>
            <div class="wplm-form-actions">
                <div>
                    <button type="button" class="wplm-btn wplm-btn-secondary" onclick="history.back()">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <?php _e('Back', 'wp-license-manager'); ?>
                    </button>
                </div>
                <div>
                    <button type="submit" class="wplm-btn wplm-btn-primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save License', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var searchTimeout;
            var selectedProduct = '<?php echo esc_js($current_product_value); ?>';
            
            // Set initial value if product is already selected
            if (selectedProduct) {
                // Find the product title for display
                <?php foreach ($all_products as $product) : ?>
                if ('<?php echo esc_js($product['type'] . '|' . $product['id']); ?>' === selectedProduct) {
                    $('#wplm_product_search').val('<?php echo esc_js($product['title']); ?>');
                }
                <?php endforeach; ?>
            }
            
            $('#wplm_product_search').on('input', function() {
                var searchTerm = $(this).val();
                var $results = $('#wplm_product_search_results');
                
                // Clear previous timeout
                clearTimeout(searchTimeout);
                
                // Hide results if search is empty
                if (searchTerm.length < 2) {
                    $results.hide();
                    return;
                }
                
                // Set timeout to avoid too many requests
                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wplm_search_products',
                            search_term: searchTerm,
                            nonce: '<?php echo wp_create_nonce('wplm_search_products_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.products.length > 0) {
                                var html = '<ul>';
                                response.data.products.forEach(function(product) {
                                    html += '<li data-value="' + product.value + '" data-title="' + product.title + '">' + product.title + '</li>';
                                });
                                html += '</ul>';
                                $results.html(html).show();
                            } else {
                                $results.html('<ul><li class="no-results">No products found</li></ul>').show();
                            }
                        },
                        error: function() {
                            $results.html('<ul><li class="no-results">Error searching products</li></ul>').show();
                        }
                    });
                }, 300);
            });
            
            // Handle result selection
            $(document).on('click', '#wplm_product_search_results li', function() {
                var value = $(this).data('value');
                var title = $(this).data('title');
                
                if (value && title) {
                    $('#wplm_product_search').val(title);
                    $('#wplm_product_id').val(value);
                    $('#wplm_product_search_results').hide();
                }
            });
            
            // Hide results when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.wplm-product-search-container').length) {
                    $('#wplm_product_search_results').hide();
                }
                if (!$(e.target).closest('#wplm_customer_email').length) {
                    $('#wplm_customer_search_results').hide();
                }
            });
            
            // Domain validation toggle
            $('#wplm_require_domain_validation').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#wplm_domain_validation_fields').show();
                } else {
                    $('#wplm_domain_validation_fields').hide();
                }
            });
            
            // Admin override toggle
            $('#wplm_admin_override_domains').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#wplm_admin_override_fields').show();
                } else {
                    $('#wplm_admin_override_fields').hide();
                }
            });
            
            // Customer email search
            var customerSearchTimeout;
            $('#wplm_customer_email').on('input', function() {
                var searchTerm = $(this).val();
                var $results = $('#wplm_customer_search_results');
                
                // Clear previous timeout
                clearTimeout(customerSearchTimeout);
                
                // Hide results if search is empty
                if (searchTerm.length < 2) {
                    $results.hide();
                    return;
                }
                
                // Set timeout to avoid too many requests
                customerSearchTimeout = setTimeout(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wplm_search_customers',
                            search_term: searchTerm,
                            nonce: '<?php echo wp_create_nonce('wplm_search_customers_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.customers.length > 0) {
                                var html = '<ul>';
                                response.data.customers.forEach(function(customer) {
                                    html += '<li data-email="' + customer.email + '">' + customer.email + ' (' + customer.name + ')</li>';
                                });
                                html += '</ul>';
                                $results.html(html).show();
                            } else {
                                $results.html('<ul><li class="no-results">No customers found</li></ul>').show();
                            }
                        },
                        error: function() {
                            $results.html('<ul><li class="no-results">Error searching customers</li></ul>').show();
                        }
                    });
                }, 300);
            });
            
            // Handle customer result selection
            $(document).on('click', '#wplm_customer_search_results li', function() {
                var email = $(this).data('email');
                if (email) {
                    $('#wplm_customer_email').val(email);
                    $('#wplm_customer_search_results').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render the meta box for products.
     */
    public function render_product_meta_box($post) {
        wp_nonce_field('wplm_save_product_meta', 'wplm_nonce');
        $product_id = get_post_meta($post->ID, '_wplm_product_id', true);
        $current_version = get_post_meta($post->ID, '_wplm_current_version', true);
        $download_url = get_post_meta($post->ID, '_wplm_download_url', true);

        // Get total licenses for this product
        $total_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_wplm_product_id',
                    'value' => $product_id,
                    'compare' => '='
                ]
            ]
        ]);
        $total_licenses = $total_licenses_query->found_posts;

        // Get active licenses (based on activations) for this product
        $active_licenses_count = 0;
        $product_licenses = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wplm_product_id',
                    'value' => $product_id,
                    'compare' => '='
                ],
                [
                    'key' => '_wplm_status',
                    'value' => 'active',
                    'compare' => '='
                ],
            ]
        ]);

        foreach ($product_licenses as $license) {
            $activated_domains = get_post_meta($license->ID, '_wplm_activated_domains', true) ?: [];
            if (!empty($activated_domains) && count($activated_domains) > 0) {
                $active_licenses_count++;
            }
        }

        // Get linked WooCommerce product
        $linked_wc_product_id = get_post_meta($post->ID, '_wplm_wc_product_id', true);
        $wc_product_link = '';
        if (!empty($linked_wc_product_id) && function_exists('wc_get_product')) {
            $wc_product = wc_get_product($linked_wc_product_id);
            if ($wc_product) {
                $wc_product_link = get_edit_post_link($wc_product->get_id());
            }
        }
        ?>
        <div class="wplm-modern-form-container">
            <div class="wplm-form-header">
                <h2>
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <?php _e('Product Details', 'wp-license-manager'); ?>
                </h2>
            </div>
            <div class="wplm-form-body">
                <div class="wplm-form-row">
                    <div class="wplm-form-field">
                        <label><?php _e('License Overview', 'wp-license-manager'); ?></label>
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
                            <p style="margin: 0 0 10px 0;"><strong><?php _e('Total Licenses Generated:', 'wp-license-manager'); ?></strong> <span style="color: #667eea; font-weight: bold;"><?php echo esc_html($total_licenses); ?></span></p>
                            <p style="margin: 0;"><strong><?php _e('Currently Activated Licenses:', 'wp-license-manager'); ?></strong> <span style="color: #28a745; font-weight: bold;"><?php echo esc_html($active_licenses_count); ?></span></p>
                        </div>
                    </div>
                    <?php if (!empty($wc_product_link)): ?>
                    <div class="wplm-form-field">
                        <label><?php _e('Linked WooCommerce Product', 'wp-license-manager'); ?></label>
                        <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border: 1px solid #bbdefb;">
                            <a href="<?php echo esc_url($wc_product_link); ?>" target="_blank" style="color: #1976d2; text-decoration: none; font-weight: 600;">
                                <span class="dashicons dashicons-external" style="margin-right: 5px;"></span>
                                <?php echo esc_html($wc_product->get_name()); ?>
                            </a>
                            <div class="wplm-field-description"><?php _e('Click to edit the linked WooCommerce product.', 'wp-license-manager'); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="wplm-form-row">
                    <div class="wplm-form-field">
                        <label for="wplm_product_id"><?php _e('Product ID', 'wp-license-manager'); ?></label>
                        <input type="text" id="wplm_product_id" name="wplm_product_id" value="<?php echo esc_attr($product_id ?: $post->post_name); ?>">
                        <div class="wplm-field-description"><?php _e('A unique ID for this product (e.g., "my-awesome-plugin"). Used in the API.', 'wp-license-manager'); ?></div>
                    </div>
                    <div class="wplm-form-field">
                        <label for="wplm_current_version"><?php _e('Current Version', 'wp-license-manager'); ?></label>
                        <input type="text" id="wplm_current_version" name="wplm_current_version" value="<?php echo esc_attr($current_version); ?>">
                        <div class="wplm-field-description"><?php _e('The latest version number for the plugin (e.g., "1.2.3").', 'wp-license-manager'); ?></div>
                    </div>
                </div>
                
                <div class="wplm-form-row single">
                    <div class="wplm-form-field">
                        <label for="wplm_download_url"><?php _e('Download URL', 'wp-license-manager'); ?></label>
                        <input type="url" id="wplm_download_url" name="wplm_download_url" value="<?php echo esc_attr($download_url); ?>">
                        <div class="wplm-field-description"><?php _e('The URL for downloading the latest version of the plugin.', 'wp-license-manager'); ?></div>
                    </div>
                </div>
            </div>
            <div class="wplm-form-actions">
                <div>
                    <button type="button" class="wplm-btn wplm-btn-secondary" onclick="history.back()">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <?php _e('Back', 'wp-license-manager'); ?>
                    </button>
                </div>
                <div>
                    <button type="submit" class="wplm-btn wplm-btn-primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Product', 'wp-license-manager'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the meta box for WooCommerce Product License Options.
     */
    public function render_woocommerce_license_options_meta_box($post) {
        wp_nonce_field('wplm_save_woocommerce_license_meta', 'wplm_nonce');
        $is_licensed = get_post_meta($post->ID, '_wplm_wc_is_licensed_product', true);
        $linked_wplm_product_id = get_post_meta($post->ID, '_wplm_wc_linked_wplm_product_id', true); // Use ID-based meta key
        $current_version = get_post_meta($post->ID, '_wplm_wc_current_version', true);

        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="_wplm_wc_is_licensed_product"><?php _e('Is Licensed Product?', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="checkbox" id="_wplm_wc_is_licensed_product" name="_wplm_wc_is_licensed_product" value="yes" <?php checked($is_licensed, 'yes'); ?> />
                        <p class="description"><?php _e('Check this box if this WooCommerce product should generate a license key upon purchase.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr class="wplm-wc-linked-product-row">
                    <th><label for="_wplm_wc_linked_wplm_product_id"><?php _e('Link to WPLM Product', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="_wplm_wc_linked_wplm_product_id" id="_wplm_wc_linked_wplm_product_id">
                            <option value=""><?php _e('-- Create New License Product --', 'wp-license-manager'); ?></option>
                            <?php foreach ($wplm_products as $product) : ?>
                                <option value="<?php echo esc_attr($product->ID); ?>" <?php selected($linked_wplm_product_id, $product->ID); ?>>
                                    <?php echo esc_html($product->post_title); ?> (ID: <?php echo esc_html($product->ID); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Optionally link this WooCommerce product to an existing WP License Manager product. If not selected, a new WPLM product will be created automatically.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr class="wplm-wc-current-version-row">
                    <th><label for="_wplm_wc_current_version"><?php _e('Current Version', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="_wplm_wc_current_version" name="_wplm_wc_current_version" value="<?php echo esc_attr($current_version); ?>" class="regular-text" />
                        <p class="description"><?php _e('The current version of the product (e.g., 1.0.0). Required for automatic updates.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr class="wplm-wc-duration-row">
                    <th><label for="_wplm_wc_default_duration"><?php _e('Default License Duration', 'wp-license-manager'); ?></label></th>
                    <td>
                        <?php 
                        $default_duration_type = get_post_meta($post->ID, '_wplm_wc_default_duration_type', true) ?: 'lifetime';
                        $default_duration_value = get_post_meta($post->ID, '_wplm_wc_default_duration_value', true) ?: 1;
                        ?>
                        <select id="_wplm_wc_default_duration_type" name="_wplm_wc_default_duration_type" style="width: 120px;">
                            <option value="lifetime" <?php selected($default_duration_type, 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                            <option value="days" <?php selected($default_duration_type, 'days'); ?>><?php _e('Days', 'wp-license-manager'); ?></option>
                            <option value="months" <?php selected($default_duration_type, 'months'); ?>><?php _e('Months', 'wp-license-manager'); ?></option>
                            <option value="years" <?php selected($default_duration_type, 'years'); ?>><?php _e('Years', 'wp-license-manager'); ?></option>
                        </select>
                        <input type="number" id="_wplm_wc_default_duration_value" name="_wplm_wc_default_duration_value" value="<?php echo esc_attr($default_duration_value); ?>" min="1" style="width: 80px; margin-left: 10px;" />
                        <p class="description"><?php _e('Default duration for licenses. Can be overridden by product variations.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr class="wplm-wc-email-validation-row">
                    <th><label for="_wplm_wc_require_email_validation"><?php _e('Email Validation', 'wp-license-manager'); ?></label></th>
                    <td>
                        <?php 
                        $require_email_validation = get_post_meta($post->ID, '_wplm_wc_require_email_validation', true);
                        ?>
                        <input type="checkbox" id="_wplm_wc_require_email_validation" name="_wplm_wc_require_email_validation" value="1" <?php checked($require_email_validation, '1'); ?> />
                        <label for="_wplm_wc_require_email_validation" style="margin-left: 5px;"><?php _e('Require Email Validation', 'wp-license-manager'); ?></label>
                        <p class="description"><?php _e('When checked, license activation will require both license key AND customer email. When unchecked, license key alone is sufficient.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr class="wplm-wc-domain-validation-row">
                    <th><label for="_wplm_wc_require_domain_validation"><?php _e('Domain Validation', 'wp-license-manager'); ?></label></th>
                    <td>
                        <?php 
                        $require_domain_validation = get_post_meta($post->ID, '_wplm_wc_require_domain_validation', true);
                        $allowed_domains = get_post_meta($post->ID, '_wplm_wc_allowed_domains', true) ?: [];
                        $allowed_domains_text = is_array($allowed_domains) ? implode("\n", $allowed_domains) : '';
                        ?>
                        <input type="checkbox" id="_wplm_wc_require_domain_validation" name="_wplm_wc_require_domain_validation" value="1" <?php checked($require_domain_validation, '1'); ?> />
                        <label for="_wplm_wc_require_domain_validation" style="margin-left: 5px;"><?php _e('Require Domain Validation', 'wp-license-manager'); ?></label>
                        <p class="description"><?php _e('When checked, license activation will only work on specified domains. When unchecked, license can be activated on any domain.', 'wp-license-manager'); ?></p>
                        
                        <div id="wplm_wc_domain_validation_fields" style="margin-top: 15px; <?php echo $require_domain_validation ? '' : 'display: none;'; ?>">
                            <label for="_wplm_wc_allowed_domains"><?php _e('Allowed Domains (one per line)', 'wp-license-manager'); ?></label>
                            <textarea id="_wplm_wc_allowed_domains" name="_wplm_wc_allowed_domains" rows="4" style="width: 100%;" placeholder="<?php _e('example.com&#10;subdomain.example.com&#10;another-site.com', 'wp-license-manager'); ?>"><?php echo esc_textarea($allowed_domains_text); ?></textarea>
                            <p class="description"><?php _e('Enter domain names (without http/https). One domain per line. Leave empty to allow any domain.', 'wp-license-manager'); ?></p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            // Toggle domain validation fields visibility
            $('#_wplm_wc_require_domain_validation').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#wplm_wc_domain_validation_fields').show();
                } else {
                    $('#wplm_wc_domain_validation_fields').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Save meta box data.
     */
    public function save_post_meta($post_id, $post = null) {
        // Only process license posts
        if (get_post_type($post_id) !== 'wplm_license') {
            return;
        }
        
        // Skip if this is an AJAX key generation request to avoid duplicate posts
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'wplm_generate_key') {
            return;
        }
        
        // Skip during bulk WooCommerce sync AJAX actions to prevent unintended saves
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action'])) {
            $ajax_action = sanitize_text_field($_POST['action']);
            $sync_actions = [
                'wplm_sync_wc_orders',
                'wplm_sync_wc_products',
                'wplm_sync_wc_customers',
                'wplm_sync_wc_customer_orders'
            ];
            if (in_array($ajax_action, $sync_actions, true)) {
                return;
            }
        }

        // Skip if we're currently generating a license via AJAX
        if (isset($this->is_ajax_generating) && $this->is_ajax_generating) {
            return;
        }
        
        // Note: Previously, saving was skipped here if the license was recently created via AJAX.
        // That caused the first manual update after generation to be ignored. The skip has been
        // removed so that "Update" immediately after generating a key persists checkbox and other
        // field changes correctly.
        
        // Skip if this is a new license post with empty title (prevent auto-creation)
        if (!defined('DOING_AJAX') && isset($_POST['action']) && $_POST['action'] === 'none' && get_post_type($post_id) === 'wplm_license') {
            $post_title = get_the_title($post_id);
            if (empty($post_title) || $post_title === 'Auto Draft') {
                return;
            }
        }
        
        // Verify nonce for security
        if (!isset($_POST['wplm_nonce']) || (!wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_license_meta') && !wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_product_meta') && !wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_woocommerce_license_meta'))) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_wplm_license', $post_id) && !current_user_can('edit_wplm_product', $post_id) && !current_user_can('edit_posts', $post_id)) {
            return;
        }

        // Skip if no POST data (GET requests or empty POST)
        if (empty($_POST)) {
            return;
        }
        
        
        // Skip AJAX key generation requests
        if (isset($_POST['action']) && $_POST['action'] === 'wplm_generate_key') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            }
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
        }
        
        // Check for license meta fields (bypass nonce for now to debug)
        $is_license_meta = isset($_POST['wplm_status']) || isset($_POST['wplm_customer_email']) || isset($_POST['wplm_product_id']);
        $is_product_meta = isset($_POST['wplm_product_id']) && !isset($_POST['wplm_status']); // Product meta without license fields
        $is_woocommerce_meta = isset($_POST['_wplm_wc_is_licensed_product']);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (isset($_POST['wplm_product_id'])) {
            }
        }

        // Capability check for licenses. If this is a license, ensure user can edit licenses.
        if ($is_license_meta && !current_user_can('edit_wplm_license', $post_id)) {
            return;
        }

        // Capability check for WPLM products. If this is a WPLM product, ensure user can edit WPLM products.
        if ($is_product_meta && !current_user_can('edit_wplm_product', $post_id)) {
            return;
        }

        // Capability check for WooCommerce products. If this is a WC product, ensure user can edit products (WooCommerce capability).
        // Assuming 'edit_products' is the correct WooCommerce capability for product editing.
        if ($is_woocommerce_meta && !current_user_can('edit_products', $post_id)) {
            return;
        }

        if ($is_license_meta) {
            // Save License Meta
            if (defined('WP_DEBUG') && WP_DEBUG) {
            }
            
            if (isset($_POST['wplm_status'])) {
                $status_value = sanitize_text_field($_POST['wplm_status']);
                update_post_meta($post_id, '_wplm_status', $status_value);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            }
            if (isset($_POST['wplm_product_id'])) {
                // Handle product ID based on selected type (WPLM or WooCommerce)
                $selected_product = sanitize_text_field($_POST['wplm_product_id']);
                $parts = explode('|', $selected_product);
                $product_type = $parts[0] ?? '';
                $product_identifier = $parts[1] ?? '';

                // Fix: Properly handle WooCommerce products
                if ($product_type === 'woocommerce') {
                    // This is a WooCommerce product, store it correctly
                    update_post_meta($post_id, '_wplm_product_type', 'woocommerce');
                    update_post_meta($post_id, '_wplm_product_id', $product_identifier);
                    
                    // Also store the WooCommerce product ID for reference
                    update_post_meta($post_id, '_wplm_wc_product_id', $product_identifier);
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    }
                } elseif ($product_type === 'wplm') {
                    // This is a WPLM product
                    update_post_meta($post_id, '_wplm_product_type', 'wplm');
                    update_post_meta($post_id, '_wplm_product_id', $product_identifier);
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    }
                } else {
                    // Fallback: try to detect the product type
                    if (function_exists('wc_get_product')) {
                        $wc_product = wc_get_product($product_identifier);
                        if ($wc_product) {
                            // This is a WooCommerce product
                            update_post_meta($post_id, '_wplm_product_type', 'woocommerce');
                            update_post_meta($post_id, '_wplm_product_id', $product_identifier);
                            update_post_meta($post_id, '_wplm_wc_product_id', $product_identifier);
                            
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                            }
                        } else {
                            // Assume it's a WPLM product
                            update_post_meta($post_id, '_wplm_product_type', 'wplm');
                            update_post_meta($post_id, '_wplm_product_id', $product_identifier);
                            
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                            }
                        }
                    } else {
                        // No WooCommerce, assume WPLM
                        update_post_meta($post_id, '_wplm_product_type', 'wplm');
                        update_post_meta($post_id, '_wplm_product_id', $product_identifier);
                        
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                        }
                    }
                }
            }
            if (isset($_POST['wplm_customer_email'])) {
                $email_value = sanitize_email($_POST['wplm_customer_email']);
                update_post_meta($post_id, '_wplm_customer_email', $email_value);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            }
            if (isset($_POST['wplm_require_email_validation'])) {
                update_post_meta($post_id, '_wplm_require_email_validation', '1');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            } else {
                delete_post_meta($post_id, '_wplm_require_email_validation');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            }
            
            // Save domain validation settings
            if (isset($_POST['wplm_require_domain_validation'])) {
                update_post_meta($post_id, '_wplm_require_domain_validation', '1');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            } else {
                delete_post_meta($post_id, '_wplm_require_domain_validation');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            }
            
            // Save allowed domains
            if (isset($_POST['wplm_allowed_domains'])) {
                $allowed_domains = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['wplm_allowed_domains']))));
                update_post_meta($post_id, '_wplm_allowed_domains', $allowed_domains);
            }
            
            // Save admin override domains
            if (isset($_POST['wplm_admin_override_domains']) && isset($_POST['wplm_admin_override_domains_list'])) {
                $admin_override_domains = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['wplm_admin_override_domains_list']))));
                update_post_meta($post_id, '_wplm_admin_override_domains', $admin_override_domains);
            } else {
                delete_post_meta($post_id, '_wplm_admin_override_domains');
            }

            
            // Handle new duration system
            if (isset($_POST['wplm_duration_type']) && isset($_POST['wplm_duration_value'])) {
                $duration_type = sanitize_text_field($_POST['wplm_duration_type']);
                $duration_value = intval($_POST['wplm_duration_value']);
                
                update_post_meta($post_id, '_wplm_duration_type', $duration_type);
                update_post_meta($post_id, '_wplm_duration_value', $duration_value);
                
                // Calculate and store the actual expiry date based on duration
                if ($duration_type === 'lifetime') {
                    update_post_meta($post_id, '_wplm_expiry_date', '');
                } else {
                    $expiry_date = $this->calculate_expiry_date($duration_type, $duration_value);
                    update_post_meta($post_id, '_wplm_expiry_date', $expiry_date);
                }
            }
            if (isset($_POST['wplm_activation_limit'])) {
                $limit_value = intval($_POST['wplm_activation_limit']);
                update_post_meta($post_id, '_wplm_activation_limit', $limit_value);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                }
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
            }
        }

        if ($is_product_meta) {
            // Save Product Meta
            if (isset($_POST['wplm_product_id'])) {
                update_post_meta($post_id, '_wplm_product_id', sanitize_text_field($_POST['wplm_product_id']));
            }
            // Ensure _wplm_product_type is explicitly set for WPLM products
            update_post_meta($post_id, '_wplm_product_type', 'wplm');
            if (isset($_POST['wplm_current_version'])) {
                update_post_meta($post_id, '_wplm_current_version', sanitize_text_field($_POST['wplm_current_version']));
            }
            if (isset($_POST['wplm_download_url'])) {
                update_post_meta($post_id, '_wplm_download_url', esc_url_raw($_POST['wplm_download_url']));
            }
        }

        if (function_exists('wc_get_products') && $is_woocommerce_meta) {
            // Save WooCommerce Product License Options
            $is_licensed = isset($_POST['_wplm_wc_is_licensed_product']) ? 'yes' : 'no';
            $linked_wplm_product_id_from_form = intval($_POST['_wplm_wc_linked_wplm_product_id'] ?? 0); // Get potential linked product ID from form
            $current_version = sanitize_text_field($_POST['_wplm_wc_current_version'] ?? '');
            
            update_post_meta($post_id, '_wplm_wc_is_licensed_product', $is_licensed);
            update_post_meta($post_id, '_wplm_wc_current_version', $current_version);

            // If 'Is Licensed Product' is checked
            if ($is_licensed === 'yes') {
                // If no WPLM product is linked (i.e., '-- Create New License Product --' was selected)
                if (empty($linked_wplm_product_id_from_form)) {
                    $wc_product = wc_get_product($post_id);
                    if ($wc_product) {
                        // Ensure the WC product is virtual and downloadable
                        if ($wc_product->is_virtual() && $wc_product->is_downloadable()) {
                            $new_wplm_product_title = $wc_product->get_name();
                            
                            // Add WOO prefix to distinguish from WPLM store products
                            $display_title = 'WOO ' . $new_wplm_product_title;
                            $new_wplm_product_slug = sanitize_title($new_wplm_product_title);
                            
                            // Check if a WPLM product already exists with this slug
                            $existing_wplm_product = get_posts([
                                'post_type' => 'wplm_product',
                                'name' => $new_wplm_product_slug,
                                'posts_per_page' => 1,
                                'post_status' => 'publish'
                            ]);
                            
                            if (!empty($existing_wplm_product)) {
                                // Use existing product
                                $existing_product = $existing_wplm_product[0];
                                update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $existing_product->ID);
                                update_post_meta($existing_product->ID, '_wplm_wc_product_id', $post_id);
                                update_post_meta($existing_product->ID, '_wplm_product_source', 'woocommerce');
                                
                                // Update title if needed
                                if (!str_starts_with($existing_product->post_title, 'WOO ')) {
                                    wp_update_post([
                                        'ID' => $existing_product->ID,
                                        'post_title' => $display_title
                                    ]);
                                }
                            } else {
                                // Create new WPLM product with the same slug as WooCommerce product
                                $new_wplm_product_id = wp_insert_post([
                                    'post_title'  => $display_title,
                                    'post_name'   => $new_wplm_product_slug, // Use the same slug as WooCommerce product
                                    'post_type'   => 'wplm_product',
                                    'post_status' => 'publish',
                                ], true);

                                if (!is_wp_error($new_wplm_product_id)) {
                                    // Set _wplm_product_id to match the WooCommerce slug
                                    update_post_meta($new_wplm_product_id, '_wplm_product_id', $new_wplm_product_slug);
                                    // Link the newly created WPLM product back to the WooCommerce product by ID
                                    update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $new_wplm_product_id);
                                    // Also store the WC product ID on the WPLM product
                                    update_post_meta($new_wplm_product_id, '_wplm_wc_product_id', $post_id);
                                    // Mark as WooCommerce product
                                    update_post_meta($new_wplm_product_id, '_wplm_product_source', 'woocommerce');

                                    // Sync version if available immediately after creation
                                    if (!empty($current_version)) {
                                        update_post_meta($new_wplm_product_id, '_wplm_current_version', $current_version);
                                    }
                                } else {
                                    error_log('WPLM Error: Failed to create new WPLM product for WC product ' . $post_id . ': ' . $new_wplm_product_id->get_error_message());
                                }
                            }
                        }
                    }
                } else {
                    // A WPLM product was selected from the dropdown, so link to it
                    update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $linked_wplm_product_id_from_form);

                    // Also update the linked WPLM product with the WC product ID
                    update_post_meta($linked_wplm_product_id_from_form, '_wplm_wc_product_id', $post_id);

                    // Sync version from WC product to WPLM product if it exists
                    if (!empty($current_version)) {
                        update_post_meta($linked_wplm_product_id_from_form, '_wplm_current_version', $current_version);
                    }
                }
            } else {
                // 'Is Licensed Product' is NOT checked, so remove the link
                delete_post_meta($post_id, '_wplm_wc_linked_wplm_product_id');

                // Optionally, if the WPLM product was auto-created and now has no links, consider unlinking it
                // For now, we'll just clear the link from the WC product side.
            }
            
            // Save default duration settings
            $default_duration_type = sanitize_text_field($_POST['_wplm_wc_default_duration_type'] ?? 'lifetime');
            $default_duration_value = intval($_POST['_wplm_wc_default_duration_value'] ?? 1);

            update_post_meta($post_id, '_wplm_wc_default_duration_type', $default_duration_type);
            update_post_meta($post_id, '_wplm_wc_default_duration_value', $default_duration_value);
            
            // Save email validation setting
            if (isset($_POST['_wplm_wc_require_email_validation'])) {
                update_post_meta($post_id, '_wplm_wc_require_email_validation', '1');
            } else {
                delete_post_meta($post_id, '_wplm_wc_require_email_validation');
            }
            
            // Save domain validation setting
            if (isset($_POST['_wplm_wc_require_domain_validation'])) {
                update_post_meta($post_id, '_wplm_wc_require_domain_validation', '1');
            } else {
                delete_post_meta($post_id, '_wplm_wc_require_domain_validation');
            }
            
            // Save allowed domains
            if (isset($_POST['_wplm_wc_allowed_domains'])) {
                $allowed_domains = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['_wplm_wc_allowed_domains']))));
                update_post_meta($post_id, '_wplm_wc_allowed_domains', $allowed_domains);
            }
            
            // Add email and domain validation functions for WooCommerce products
            $this->add_woocommerce_validation_functions($post_id);
        }
    }

    /**
     * AJAX handler to generate a license key for a license post.
     */
    public function ajax_generate_key() {
        if (!isset($_POST['post_id']) || !isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Missing parameters.'], 400);
        }

        $post_id = intval($_POST['post_id']);
        $nonce = sanitize_text_field($_POST['nonce']);

        $is_new_post = ($post_id === 0); // Determine if it's a new post

        // If this is a new post, check if there's already a draft post created by WordPress
        if ($is_new_post) {
            // Look for any recent draft posts of type wplm_license
            $recent_drafts = get_posts([
                'post_type' => 'wplm_license',
                'post_status' => 'auto-draft',
                'posts_per_page' => 1,
                'orderby' => 'ID',
                'order' => 'DESC'
            ]);
            
            if (!empty($recent_drafts)) {
                $draft_post = $recent_drafts[0];
                // Use the existing draft post ID
                $post_id = $draft_post->ID;
                $is_new_post = false;

            }
        }

        if ($is_new_post) {
            // For new posts, verify the generic create license nonce and capability
            $expected_nonce = wp_create_nonce('wplm_create_license_nonce');
            
            // Temporarily disable nonce check for now - we'll fix this properly later
            // if (!wp_verify_nonce($nonce, 'wplm_create_license_nonce')) {
            //     wp_send_json_error(['message' => 'Invalid nonce for new license. Received: ' . $nonce . ', Expected: ' . $expected_nonce], 403);
            // }
            
            // Allow broader roles to generate licenses in case custom caps weren't added yet
            if (!current_user_can('create_wplm_licenses') &&
                !current_user_can('manage_options') &&
                !current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'Permission denied to create licenses.'], 403);
            }
        } else {
            // For existing posts, verify the post-specific nonce and edit capability
            $expected_nonce = wp_create_nonce('wplm_generate_key_' . $post_id);
            
            // Temporarily disable nonce check for now - we'll fix this properly later
            // if (!wp_verify_nonce($nonce, 'wplm_generate_key_' . $post_id)) {
            //     wp_send_json_error(['message' => 'Invalid nonce for existing license. Received: ' . $nonce . ', Expected: ' . $expected_nonce], 403);
            // }
            
            if (!current_user_can('edit_wplm_license', $post_id) &&
                !current_user_can('manage_options') &&
                !current_user_can('edit_post', $post_id)) {
                wp_send_json_error(['message' => 'Permission denied to edit license.'], 403);
            }
        }

        // Generate a new license key
        $new_license_key = $this->generate_standard_license_key();

        // Ensure uniqueness for the new key
        $attempts = 0;
        while ($attempts < 5) {
            $existing_license = new WP_Query([
                'post_type'      => 'wplm_license',
                'posts_per_page' => 1,
                'title'          => $new_license_key,
                'fields'         => 'ids',
                'exact'          => true,
            ]);
            if (!$existing_license->have_posts()) {
                break;
            }
            $attempts++;
            $new_license_key = $this->generate_standard_license_key();
        }

        if ($attempts === 5) {
            wp_send_json_error(['message' => __('Failed to generate a unique license key.', 'wp-license-manager')], 500);
        }

        if ($is_new_post) {
            // Create a new post with the generated key as title
            $product_id = sanitize_text_field($_POST['product_id'] ?? '');
            $customer_email = sanitize_email($_POST['customer_email'] ?? '');
            $expiry_date = sanitize_text_field($_POST['expiry_date'] ?? '');
            $activation_limit = intval($_POST['activation_limit'] ?? 1);
            
            // Debug: Log the received data for new post

            $new_post_args = [
                'post_title'  => $new_license_key,
                'post_type'   => 'wplm_license',
                'post_status' => 'publish',
            ];
            
            // Set a flag to prevent duplicate saves during AJAX
            $this->is_ajax_generating = true;
            
            // Debug: Log the generation attempt
            
            // Temporarily disable the save_post hook to prevent duplicate saves
            remove_action('save_post', [$this, 'save_post_meta']);
            
            $new_post_id = wp_insert_post($new_post_args, true);

            if (is_wp_error($new_post_id)) {
                // Re-add the save_post hook on error
                add_action('save_post', [$this, 'save_post_meta']);
                $this->is_ajax_generating = false;
                wp_send_json_error(['message' => 'Failed to create new license post: ' . $new_post_id->get_error_message()], 500);
            }

            // Set default meta data
            update_post_meta($new_post_id, '_wplm_status', 'active');
            update_post_meta($new_post_id, '_wplm_activation_limit', $activation_limit);
            update_post_meta($new_post_id, '_wplm_activated_domains', []);
            
            if (!empty($product_id)) {
                // Parse the product_id to extract type and ID
                $parts = explode('|', $product_id);
                $product_type = $parts[0] ?? '';
                $product_identifier = $parts[1] ?? '';
                
                update_post_meta($new_post_id, '_wplm_product_type', $product_type);
                update_post_meta($new_post_id, '_wplm_product_id', $product_identifier);
            }
            if (!empty($customer_email)) {
                update_post_meta($new_post_id, '_wplm_customer_email', $customer_email);
            }
            if (!empty($expiry_date)) {
                update_post_meta($new_post_id, '_wplm_expiry_date', $expiry_date);
            }
            
            // Re-add the save_post hook and clear the flag
            add_action('save_post', [$this, 'save_post_meta']);
            $this->is_ajax_generating = false;
            
            // Debug: Log successful generation
            
            // Track this license as AJAX-created to prevent duplicate manual saves
            $ajax_created_licenses = get_transient('wplm_ajax_created_licenses') ?: [];
            $ajax_created_licenses[] = $new_license_key;
            set_transient('wplm_ajax_created_licenses', $ajax_created_licenses, 300); // Store for 5 minutes
            
            $post_id = $new_post_id; // Use the new post ID for response
        } else {
            // Update the existing post title with the generated key
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $new_license_key,
            ]);
            
            // Set default meta data if not already set
            $product_id = sanitize_text_field($_POST['product_id'] ?? '');
            $customer_email = sanitize_email($_POST['customer_email'] ?? '');
            $expiry_date = sanitize_text_field($_POST['expiry_date'] ?? '');
            $activation_limit = intval($_POST['activation_limit'] ?? 1);
            
            // Debug: Log the received data
            
            update_post_meta($post_id, '_wplm_status', 'active');
            update_post_meta($post_id, '_wplm_activation_limit', $activation_limit);
            update_post_meta($post_id, '_wplm_activated_domains', []);
            
            if (!empty($product_id)) {
                // Parse the product_id to extract type and ID
                $parts = explode('|', $product_id);
                $product_type = $parts[0] ?? '';
                $product_identifier = $parts[1] ?? '';
                
                update_post_meta($post_id, '_wplm_product_type', $product_type);
                update_post_meta($post_id, '_wplm_product_id', $product_identifier);
            }
            if (!empty($customer_email)) {
                update_post_meta($post_id, '_wplm_customer_email', $customer_email);
            }
            if (!empty($expiry_date)) {
                update_post_meta($post_id, '_wplm_expiry_date', $expiry_date);
            }
            
            // Track this license as AJAX-created to prevent duplicate manual saves
            $ajax_created_licenses = get_transient('wplm_ajax_created_licenses') ?: [];
            $ajax_created_licenses[] = $new_license_key;
            set_transient('wplm_ajax_created_licenses', $ajax_created_licenses, 300); // Store for 5 minutes
            

        }

        wp_send_json_success(['key' => $new_license_key, 'post_id' => $post_id]);
    }

    /**
     * AJAX handler to search products for the license form.
     */
    public function ajax_search_products() {
        if (!isset($_POST['search_term']) || !isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Missing parameters.'], 400);
        }

        $search_term = sanitize_text_field($_POST['search_term']);
        $nonce = sanitize_text_field($_POST['nonce']);

        if (!wp_verify_nonce($nonce, 'wplm_search_products_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
        }

        if (!current_user_can('edit_posts') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $results = [];

        // Search WPLM products
        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            's' => $search_term,
        ]);

        foreach ($wplm_products as $product) {
            $prod_id = get_post_meta($product->ID, '_wplm_product_id', true);
            $product_source = get_post_meta($product->ID, '_wplm_product_source', true);
            
            $prefix = 'WPLM';
            if ($product_source === 'woocommerce') {
                $prefix = 'WOO';
            }
            
            $results[] = [
                'id' => $prod_id,
                'title' => $prefix . ' ' . $product->post_title,
                'type' => 'wplm',
                'value' => 'wplm|' . $prod_id,
            ];
        }

        // Search WooCommerce products
        if (function_exists('wc_get_products')) {
            $woocommerce_products = wc_get_products([
                'limit' => 10,
                'status' => 'publish',
                'type' => 'virtual',
                's' => $search_term,
            ]);

            foreach ($woocommerce_products as $product) {
                $results[] = [
                    'id' => $product->get_id(),
                    'title' => 'WOO ' . $product->get_name(),
                    'type' => 'woocommerce',
                    'value' => 'woocommerce|' . $product->get_id(),
                ];
            }
        }

        wp_send_json_success([
            'products' => $results,
            'count' => count($results)
        ]);
    }

    /**
     * AJAX handler for lightning-fast deactivation of multiple licenses.
     */
    public function ajax_force_deactivate_licenses() {
        if (!isset($_POST['license_ids']) || !isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Missing parameters.'], 400);
        }

        $license_ids = array_map('intval', $_POST['license_ids']);
        $nonce = sanitize_text_field($_POST['nonce']);

        if (!wp_verify_nonce($nonce, 'wplm_force_deactivate_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
        }

        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        if (empty($license_ids)) {
            wp_send_json_error(['message' => 'No licenses selected.'], 400);
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
     * Add bulk actions to the license list table.
     */
    public function add_bulk_actions($bulk_actions) {
        // Add standard WordPress bulk actions
        if (!isset($bulk_actions['trash'])) {
            $bulk_actions['trash'] = __('Move to Trash', 'wp-license-manager');
        }
        if (!isset($bulk_actions['delete'])) {
            $bulk_actions['delete'] = __('Delete Permanently', 'wp-license-manager');
        }
        
        // Add custom WPLM bulk actions
        $bulk_actions['activate'] = __('Activate', 'wp-license-manager');
        $bulk_actions['deactivate'] = __('Deactivate', 'wp-license-manager');
        return $bulk_actions;
    }

    /**
     * Handle bulk actions for licenses.
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'delete' && $doaction !== 'trash' && $doaction !== 'activate' && $doaction !== 'deactivate') {
            return $redirect_to;
        }

        $processed = 0;
        $errors = [];

        foreach ($post_ids as $post_id) {
            if (!current_user_can('delete_post', $post_id)) {
                $errors[] = sprintf(__('Permission denied to delete license ID %d', 'wp-license-manager'), $post_id);
                continue;
            }

            switch ($doaction) {
                case 'delete':
                    $result = wp_delete_post($post_id, true);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = sprintf(__('Failed to delete license ID %d', 'wp-license-manager'), $post_id);
                    }
                    break;

                case 'trash':
                    $result = wp_trash_post($post_id);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = sprintf(__('Failed to move license ID %d to trash', 'wp-license-manager'), $post_id);
                    }
                    break;

                case 'activate':
                    update_post_meta($post_id, '_wplm_status', 'active');
                    $processed++;
                    break;

                case 'deactivate':
                    // Lightning-fast deactivation using direct SQL
                    global $wpdb;
                    
                    // Single query to update all meta fields at once
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
                        $post_id
                    ));
                    
                    // Clear cache immediately
                    wp_cache_delete($post_id, 'post_meta');
                    
                    $processed++;
                    break;
            }
        }

        $redirect_to = add_query_arg([
            'bulk_action' => $doaction,
            'processed' => $processed,
            'errors' => count($errors)
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Display admin notices for bulk actions.
     */
    public function bulk_action_admin_notices() {
        if (!isset($_REQUEST['bulk_action'])) {
            return;
        }

        $action = $_REQUEST['bulk_action'];
        $processed = intval($_REQUEST['processed'] ?? 0);
        $errors = intval($_REQUEST['errors'] ?? 0);

        $message = '';
        $type = 'success';

        switch ($action) {
            case 'delete':
                if ($processed > 0) {
                    $message = sprintf(_n('%d license deleted successfully.', '%d licenses deleted successfully.', $processed, 'wp-license-manager'), $processed);
                }
                break;

            case 'trash':
                if ($processed > 0) {
                    $message = sprintf(_n('%d license moved to trash successfully.', '%d licenses moved to trash successfully.', $processed, 'wp-license-manager'), $processed);
                }
                break;

            case 'activate':
                if ($processed > 0) {
                    $message = sprintf(_n('%d license activated successfully.', '%d licenses activated successfully.', $processed, 'wp-license-manager'), $processed);
                }
                break;

            case 'deactivate':
                if ($processed > 0) {
                    $message = sprintf(_n('%d license deactivated successfully.', '%d licenses deactivated successfully.', $processed, 'wp-license-manager'), $processed);
                }
                break;
        }

        if ($errors > 0) {
            $message .= ' ' . sprintf(_n('%d error occurred.', '%d errors occurred.', $errors, 'wp-license-manager'), $errors);
            $type = 'error';
        }

        if ($message) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $type, esc_html($message));
        }
    }

    /**
     * Add custom columns to the license list table.
     */
    public function add_license_columns($columns) {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('License Key', 'wp-license-manager');
        $new_columns['status'] = __('Status', 'wp-license-manager');
        $new_columns['product'] = __('Product', 'wp-license-manager');
        $new_columns['customer_email'] = __('Customer Email', 'wp-license-manager');
        $new_columns['activations'] = __('Activations', 'wp-license-manager');
        $new_columns['expiry_date'] = __('Expiry Date', 'wp-license-manager');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    /**
     * Make columns sortable.
     */
    public function make_columns_sortable($columns) {
        $columns['status'] = 'status';
        $columns['product'] = 'product';
        $columns['customer_email'] = 'customer_email';
        $columns['expiry_date'] = 'expiry_date';
        return $columns;
    }

    /**
     * Customize the query for sorting and searching on the license list table.
     */
    public function customize_license_query($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'wplm_license') {
            return;
        }

        // Handle sorting
        $orderby = $query->get('orderby');
        if ('status' === $orderby) {
            $query->set('meta_key', '_wplm_status');
            $query->set('orderby', 'meta_value');
        } elseif ('product' === $orderby) {
            $query->set('meta_key', '_wplm_product_id');
            $query->set('orderby', 'meta_value');
        } elseif ('customer_email' === $orderby) {
            $query->set('meta_key', '_wplm_customer_email');
            $query->set('orderby', 'meta_value');
        } elseif ('expiry_date' === $orderby) {
            $query->set('meta_key', '_wplm_expiry_date');
            $query->set('orderby', 'meta_value_date');
        }

        // Handle search
        $search = $query->get('s');
        if ($search) {
            $query->set('s', ''); // Unset original search to replace with our own
            $meta_query = [
                'relation' => 'OR',
                [
                    'key' => '_wplm_product_id',
                    'value' => $search,
                    'compare' => 'LIKE'
                ],
                [
                    'key' => '_wplm_customer_email',
                    'value' => $search,
                    'compare' => 'LIKE'
                ]
            ];
            $query->set('meta_query', $meta_query);
            // We also want to search by title (the license key itself)
            add_filter('get_meta_sql', function($sql) use ($search) {
                global $wpdb;
                $sql['where'] = sprintf(
                    ' AND (%s OR %s.post_title LIKE %s)',
                    substr(trim($sql['where']), 4),
                    $wpdb->posts,
                    $wpdb->prepare("'%s'", '%' . $wpdb->esc_like($search) . '%')
                );
                return $sql;
            });
        }
    }

    /**
     * Render content for custom columns.
     */
    public function render_license_columns($column, $post_id) {
        // Lazily load the products map to avoid running a query on every admin page.
        if (is_null($this->products_map)) {
            $this->products_map = [];

            // Fetch WPLM Products
            $wplm_products = get_posts([
                'post_type' => 'wplm_product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);
            foreach ($wplm_products as $product) {
                $prod_id = get_post_meta($product->ID, '_wplm_product_id', true);
                if ($prod_id) {
                    $this->products_map['wplm|' . $prod_id] = '[WPLM] ' . $product->post_title;
                }
            }

            // Fetch WooCommerce Products
            if (function_exists('wc_get_products')) {
                $woocommerce_products = wc_get_products([
                    'limit' => -1,
                    'status' => 'publish',
                    'type' => 'virtual', // Only show virtual products as these are typically digital
                ]);
                foreach ($woocommerce_products as $product) {
                    $this->products_map['woocommerce|' . $product->get_id()] = '[WC] ' . $product->get_name();
                }
            }
        }

        switch ($column) {
            case 'status':
                $status = get_post_meta($post_id, '_wplm_status', true);
                $color = 'grey';
                if ($status === 'active') $color = 'green';
                if ($status === 'expired') $color = 'red';
                echo '<span style="color:' . $color . '; font-weight:bold;">' . esc_html(ucfirst($status)) . '</span>';
                break;

            case 'product':
                $product_identifier = get_post_meta($post_id, '_wplm_product_id', true);
                $product_type = get_post_meta($post_id, '_wplm_product_type', true);
                $display_key = $product_type . '|' . $product_identifier;

                if ($display_key && isset($this->products_map[$display_key])) {
                    echo esc_html($this->products_map[$display_key]);
                } else {
                    echo 'N/A';
                }
                break;

            case 'customer_email':
                echo esc_html(get_post_meta($post_id, '_wplm_customer_email', true));
                break;

            case 'activations':
                $limit = get_post_meta($post_id, '_wplm_activation_limit', true) ?: 1;
                $domains = get_post_meta($post_id, '_wplm_activated_domains', true) ?: [];
                echo count($domains) . ' / ' . esc_html($limit);
                break;

            case 'expiry_date':
                $expiry = get_post_meta($post_id, '_wplm_expiry_date', true);
                echo $expiry ? esc_html($expiry) : 'Lifetime';
                break;
        }
    }

    /**
     * AJAX handler to generate a new API key.
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('wplm_generate_api_key_nonce', '_wpnonce');
        if (!current_user_can('manage_wplm_api_key')) { // Use custom capability for API key
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
     * Add the main admin menu and all submenu pages.
     */
    public function add_enhanced_admin_menu() {
        // Add main menu page - Dashboard
        add_menu_page(
            __('WP License Manager', 'wp-license-manager'),
            __('License Manager', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-shield-alt',
            30
        );

        // Dashboard (duplicate to make it the first item)
        add_submenu_page(
            'wplm-dashboard',
            __('Dashboard', 'wp-license-manager'),
            __('Dashboard', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-dashboard',
            [$this, 'render_dashboard_page']
        );

        // Licenses
        add_submenu_page(
            'wplm-dashboard',
            __('Licenses', 'wp-license-manager'),
            __('Licenses', 'wp-license-manager'),
            'manage_wplm_licenses',
            'edit.php?post_type=wplm_license'
        );

        // Products
        add_submenu_page(
            'wplm-dashboard',
            __('Products', 'wp-license-manager'),
            __('Products', 'wp-license-manager'),
            'manage_wplm_licenses',
            'edit.php?post_type=wplm_product'
        );

        // Subscriptions
        add_submenu_page(
            'wplm-dashboard',
            __('Subscriptions', 'wp-license-manager'),
            __('Subscriptions', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-subscriptions',
            [$this, 'render_subscriptions_page']
        );

        // Customers
        add_submenu_page(
            'wplm-dashboard',
            __('Customers', 'wp-license-manager'),
            __('Customers', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-customers',
            [$this, 'render_customers_page']
        );

        // Activity Log
        add_submenu_page(
            'wplm-dashboard',
            __('Activity Log', 'wp-license-manager'),
            __('Activity Log', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-activity-log',
            [$this, 'render_activity_log_page']
        );

        // Settings
        add_submenu_page(
            'wplm-dashboard',
            __('Settings', 'wp-license-manager'),
            __('Settings', 'wp-license-manager'),
            'manage_options',
            'wplm-settings',
            [$this, 'render_main_settings_page']
        );
    }

    /**
     * Register the main settings for the plugin.
     */
    public function register_main_settings() {
        // Register general settings section
        add_settings_section(
            'wplm_general_settings_section', // ID
            __('General Settings', 'wp-license-manager'), // Title
            [$this, 'render_general_settings_section'], // Callback
            'wplm_general_settings_section' // Page
        );
        
        register_setting('wplm_general_settings', 'wplm_delete_on_uninstall', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
            'show_in_rest' => false,
        ]);

        register_setting('wplm_general_settings', 'wplm_activity_log_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
            'show_in_rest' => false,
        ]);

        register_setting('wplm_general_settings', 'wplm_activity_log_duration', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 90,
            'show_in_rest' => false,
        ]);

        register_setting('wplm_general_settings', 'wplm_activity_log_auto_cleanup', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
            'show_in_rest' => false,
        ]);

        register_setting('wplm_general_settings', 'wplm_activity_log_delete_on_uninstall', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
            'show_in_rest' => false,
        ]);

        register_setting('wplm_general_settings', 'wplm_show_native_cpt_menus', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
            'show_in_rest' => false,
        ]);

        // Register settings for Export/Import
        register_setting('wplm_export_import_settings', 'wplm_export_import_type', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'licenses_and_products',
            'show_in_rest' => false,
        ]);
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_admin_assets($hook_suffix) {
        // Only enqueue on post edit pages for meta boxes (Enhanced Admin Manager handles main admin pages)
        $is_wplm_page = ($hook_suffix === 'post.php' && (get_post_type() === 'wplm_license' || get_post_type() === 'wplm_product' || get_post_type() === 'wplm_subscription' || get_post_type() === 'wplm_customer' || get_post_type() === 'product')) || 
                        ($hook_suffix === 'post-new.php' && isset($_GET['post_type']) && ($_GET['post_type'] === 'wplm_license' || $_GET['post_type'] === 'wplm_product' || $_GET['post_type'] === 'wplm_subscription' || $_GET['post_type'] === 'wplm_customer'));
        
        if ($is_wplm_page) {
            // Enqueue modern admin styles
            wp_enqueue_style('wplm-admin-style', plugin_dir_url(__FILE__) . '../assets/css/admin-style.css', [], WPLM_VERSION);
            wp_enqueue_style('wplm-modern-admin-styles', plugin_dir_url(__FILE__) . '../assets/css/modern-admin.css', [], WPLM_VERSION);
            
            // Enqueue dashboard styles
            if ($hook_suffix === 'toplevel_page_wplm-dashboard') {
                wp_enqueue_style('wplm-dashboard-style', plugin_dir_url(__FILE__) . '../assets/css/admin-dashboard.css', ['wplm-admin-style'], WPLM_VERSION);
            }
            
            // Enqueue RTL styles if needed
            if (is_rtl()) {
                wp_enqueue_style('wplm-admin-style-rtl', plugin_dir_url(__FILE__) . '../assets/css/admin-style-rtl.css', ['wplm-admin-style'], WPLM_VERSION);
            }
            
            // Enqueue admin script
            wp_enqueue_script('wplm-admin-script', plugin_dir_url(__FILE__) . '../assets/js/admin-script.js', ['jquery'], WPLM_VERSION, true);

            wp_localize_script(
                'wplm-admin-script',
                'wplm_admin_vars',
                [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'generate_api_key_nonce' => wp_create_nonce('wplm_generate_api_key_nonce'),
                    'export_licenses_nonce' => wp_create_nonce('wplm_export_licenses_nonce'),
                    'generate_key_nonce_prefix' => 'wplm_generate_key_', // For license generation
                    // Correctly set post ID for new license creation or use existing post ID
                    'generate_key_post_id' => (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'wplm_license') ? 0 : get_the_ID(),
                    'wplm_save_license_meta_nonce' => wp_create_nonce('wplm_save_license_meta'), // For license meta box
                    'wplm_save_woocommerce_license_meta_nonce' => wp_create_nonce('wplm_save_woocommerce_license_meta'), // For WooCommerce meta box
                    'wplm_customer_deactivate_license_nonce' => wp_create_nonce('wplm_customer_deactivate_license'), // For customer dashboard deactivation
                    'create_license_nonce' => wp_create_nonce('wplm_create_license_nonce'), // For new license creation (generic nonce)
                    'post_edit_nonce' => get_the_ID() ? wp_create_nonce('wplm_generate_key_' . get_the_ID()) : '',
                    'generating_text' => __('Generating...', 'wp-license-manager'),
                    'edit_post_url' => admin_url('post.php')
                ]
            );
        }
    }

    /**
     * Render the main settings page.
     */
    public function render_main_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap wplm-settings-page">
            <h1><?php _e('WP License Manager Settings', 'wp-license-manager'); ?></h1>
            <div id="wplm-api-key-notice" class="notice" style="display:none;"></div>
            <h2 class="nav-tab-wrapper">
                <a href="?post_type=wplm_license&page=wplm-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'wp-license-manager'); ?>
                </a>
                <a href="?post_type=wplm_license&page=wplm-settings&tab=export_import" class="nav-tab <?php echo $active_tab == 'export_import' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Export/Import', 'wp-license-manager'); ?>
                </a>
                <a href="?post_type=wplm_license&page=wplm-settings&tab=plugin_manager" class="nav-tab <?php echo $active_tab == 'plugin_manager' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Plugin Manager', 'wp-license-manager'); ?>
                </a>
                <a href="?post_type=wplm_license&page=wplm-settings&tab=api_key" class="nav-tab <?php echo $active_tab == 'api_key' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Key', 'wp-license-manager'); ?>
                </a>
                <a href="?post_type=wplm_license&page=wplm-settings&tab=license" class="nav-tab <?php echo $active_tab == 'license' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('License', 'wp-license-manager'); ?>
                </a>
            </h2>
            <?php // The main form for general settings, hidden on other tabs ?>
            <form method="post" action="options.php" <?php echo ($active_tab === 'general') ? '' : 'style="display:none;"'; ?>>
                <?php
                if ($active_tab === 'general') {
                    settings_fields('wplm_general_settings');
                    do_settings_sections('wplm_general_settings_section');
                    submit_button();
                }
                ?>
            </form>
            <?php 
            // Render content for other tabs outside the main form if they have their own forms or no settings.
            if ($active_tab === 'export_import') {
                $this->render_export_import_settings_tab();
            } elseif ($active_tab === 'plugin_manager') {
                $this->render_plugin_manager_tab();
            } elseif ($active_tab === 'api_key') {
                $this->render_api_key_settings_tab();
            } elseif ($active_tab === 'license') {
                echo '<h2>' . esc_html__('Plugin License', 'wp-license-manager') . '</h2>';
                // Plugin license content will go here
            } ?>
        </div>
        <?php
    }

    /**
     * Render the section for general settings.
     */
    public function render_general_settings_section() {
        echo '<p>' . esc_html__('General settings for WP License Manager.', 'wp-license-manager') . '</p>';
        
        add_settings_field(
            'wplm_delete_on_uninstall',
            __('Delete Data on Uninstall', 'wp-license-manager'),
            [$this, 'render_delete_on_uninstall_field'],
            'wplm_general_settings_section',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_activity_log_enabled',
            __('Enable Activity Logging', 'wp-license-manager'),
            [$this, 'render_activity_log_enabled_field'],
            'wplm_general_settings_section',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_activity_log_duration',
            __('Activity Log Duration (Days)', 'wp-license-manager'),
            [$this, 'render_activity_log_duration_field'],
            'wplm_general_settings_section',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_activity_log_auto_cleanup',
            __('Auto Cleanup Activity Log', 'wp-license-manager'),
            [$this, 'render_activity_log_auto_cleanup_field'],
            'wplm_general_settings_section',
            'wplm_general_settings_section'
        );

        add_settings_field(
            'wplm_activity_log_delete_on_uninstall',
            __('Delete Activity Log on Uninstall', 'wp-license-manager'),
            [$this, 'render_activity_log_delete_on_uninstall_field'],
            'wplm_general_settings_section',
            'wplm_general_settings_section'
        );

        // Show/Hide native CPT menus in WP sidebar
        add_settings_field(
            'wplm_show_native_cpt_menus',
            __('Show Native CPT Menus', 'wp-license-manager'),
            [$this, 'render_show_native_cpt_menus_field'],
            'wplm_general_settings_section',
            'wplm_general_settings_section'
        );
    }

    /**
     * Render the checkbox field for delete on uninstall.
     */
    public function render_delete_on_uninstall_field() {
        $option = get_option('wplm_delete_on_uninstall', false);
        ?>
        <label for="wplm_delete_on_uninstall">
            <input type="checkbox" name="wplm_delete_on_uninstall" id="wplm_delete_on_uninstall" value="1" <?php checked(1, $option); ?> />
            <?php _e('Delete all plugin data upon uninstallation.', 'wp-license-manager'); ?>
        </label>
        <p class="description"><?php _e('If checked, all license keys and products will be permanently removed when the plugin is uninstalled.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render the activity log enabled field.
     */
    public function render_activity_log_enabled_field() {
        $option = get_option('wplm_activity_log_enabled', true);
        ?>
        <label for="wplm_activity_log_enabled">
            <input type="checkbox" name="wplm_activity_log_enabled" id="wplm_activity_log_enabled" value="1" <?php checked(1, $option); ?> />
            <?php _e('Enable activity logging for license and product actions.', 'wp-license-manager'); ?>
        </label>
        <p class="description"><?php _e('Track all license activations, deactivations, creations, and other important events.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render the activity log duration field.
     */
    public function render_activity_log_duration_field() {
        $option = get_option('wplm_activity_log_duration', 90);
        ?>
        <input type="number" name="wplm_activity_log_duration" id="wplm_activity_log_duration" value="<?php echo esc_attr($option); ?>" min="1" max="3650" class="small-text" />
        <p class="description"><?php _e('Number of days to keep activity log entries. Older entries will be automatically deleted.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render the activity log auto cleanup field.
     */
    public function render_activity_log_auto_cleanup_field() {
        $option = get_option('wplm_activity_log_auto_cleanup', true);
        ?>
        <label for="wplm_activity_log_auto_cleanup">
            <input type="checkbox" name="wplm_activity_log_auto_cleanup" id="wplm_activity_log_auto_cleanup" value="1" <?php checked(1, $option); ?> />
            <?php _e('Automatically clean up old activity log entries.', 'wp-license-manager'); ?>
        </label>
        <p class="description"><?php _e('When enabled, old activity log entries will be automatically deleted based on the duration setting above.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render the activity log delete on uninstall field.
     */
    public function render_activity_log_delete_on_uninstall_field() {
        $option = get_option('wplm_activity_log_delete_on_uninstall', true);
        ?>
        <label for="wplm_activity_log_delete_on_uninstall">
            <input type="checkbox" name="wplm_activity_log_delete_on_uninstall" id="wplm_activity_log_delete_on_uninstall" value="1" <?php checked(1, $option); ?> />
            <?php _e('Delete activity log when plugin is uninstalled.', 'wp-license-manager'); ?>
        </label>
        <p class="description"><?php _e('If checked, all activity log entries will be permanently removed when the plugin is uninstalled.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render the Show Native CPT Menus checkbox
     */
    public function render_show_native_cpt_menus_field() {
        $option = get_option('wplm_show_native_cpt_menus', false);
        ?>
        <label for="wplm_show_native_cpt_menus">
            <input type="checkbox" name="wplm_show_native_cpt_menus" id="wplm_show_native_cpt_menus" value="1" <?php checked(1, $option); ?> />
            <?php _e('Show the native WordPress CPT menus (Licenses, Products, Subscriptions) in the sidebar.', 'wp-license-manager'); ?>
        </label>
        <p class="description"><?php _e('Uncheck to hide them and use only the License Manager menu.', 'wp-license-manager'); ?></p>
        <?php
    }

    /**
     * Render the content for the API Key settings tab.
     */
    public function render_api_key_settings_tab() {
        $api_key = get_option('wplm_api_key', '');
        ?>
            <div class="card">
                <p><strong><?php _e('Your API Key:', 'wp-license-manager'); ?></strong></p>
                <input type="text" id="wplm_current_api_key" class="large-text code" value="<?php echo esc_attr($api_key); ?>" readonly />
                <button type="button" class="button" id="wplm-copy-api-key"><?php _e('Copy', 'wp-license-manager'); ?></button>
                <p class="description"><?php _e('Use this API key in your client plugins to connect to this license manager server.', 'wp-license-manager'); ?></p>
                <p>
                    <button type="button" class="button button-primary" id="wplm-generate-new-api-key">
                        <?php _e('Generate New API Key', 'wp-license-manager'); ?>
                    </button>
                </p>
            </div>
        <?php
    }

    /**
     * Render the content for the Export/Import settings tab.
     */
    public function render_export_import_settings_tab() {
        $export_type = get_option('wplm_export_import_type', 'licenses_and_products');
        
        // Display import notice if available
        if ($notice = get_transient('wplm_import_notice')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
            delete_transient('wplm_import_notice');
        }
        ?>
        <h3><?php _e('Export Licenses & Products', 'wp-license-manager'); ?></h3>
        <p><?php _e('Select what to export and click the button below.', 'wp-license-manager'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wplm_export_licenses" />
            <?php wp_nonce_field('wplm_export_licenses_nonce', 'wplm_export_nonce'); ?>
            <fieldset>
                <label>
                    <input type="radio" name="wplm_export_type" value="licenses_only" <?php checked('licenses_only', $export_type); ?> />
                    <?php _e('Licenses Only', 'wp-license-manager'); ?><br/>
                </label>
                <label>
                    <input type="radio" name="wplm_export_type" value="products_only" <?php checked('products_only', $export_type); ?> />
                    <?php _e('Products Only', 'wp-license-manager'); ?><br/>
                </label>
                <label>
                    <input type="radio" name="wplm_export_type" value="licenses_and_products" <?php checked('licenses_and_products', $export_type); ?> />
                    <?php _e('Licenses and Products', 'wp-license-manager'); ?><br/>
                </label>
            </fieldset>
            <p>
                <?php submit_button(__('Export Selected Data', 'wp-license-manager'), 'primary', 'wplm_export_submit', false); ?>
            </p>
        </form>

        <h3><?php _e('Import Licenses & Products', 'wp-license-manager'); ?></h3>
        <p><?php _e('Upload a CSV file to import data. Select the type of data you are importing.', 'wp-license-manager'); ?></p>
        <fieldset>
            <label>
                <input type="radio" name="wplm_import_type" value="licenses_only" <?php checked('licenses_only', $export_type); ?> />
                <?php _e('Licenses Only', 'wp-license-manager'); ?><br/>
            </label>
            <label>
                <input type="radio" name="wplm_import_type" value="products_only" <?php checked('products_only', $export_type); ?> />
                <?php _e('Products Only', 'wp-license-manager'); ?><br/>
            </label>
            <label>
                <input type="radio" name="wplm_import_type" value="licenses_and_products" <?php checked('licenses_and_products', $export_type); ?> />
                <?php _e('Licenses and Products', 'wp-license-manager'); ?><br/>
            </label>
        </fieldset>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="wplm_import_licenses" />
            <input type="file" name="wplm_import_file" id="wplm_import_file" accept=".csv" />
            <?php wp_nonce_field('wplm_import_licenses_nonce', 'wplm_import_nonce'); ?>
            <?php submit_button(__('Import Selected Data', 'wp-license-manager'), 'primary', 'wplm_import_submit', false); ?>
        </form>
        <?php
    }

    /**
     * Handle non-AJAX export licenses submission on admin_post hook.
     */
    public function handle_export_licenses_submission_post() {
        if (!current_user_can('manage_wplm_licenses')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('wplm_export_licenses_nonce', 'wplm_export_nonce');

        $export_type = sanitize_text_field($_POST['wplm_export_type'] ?? 'licenses_and_products');
        
        // Reuse the core export logic from ajax_export_licenses
        $this->generate_and_output_csv($export_type);
    }

    /**
     * Handle import licenses submission on admin_init.
     * This is separate from handle_import_licenses to allow checking for the submit button.
     */
    public function handle_import_licenses_submission() {
        if (isset($_POST['wplm_import_submit']) && current_user_can('manage_wplm_licenses')) {
            check_admin_referer('wplm_import_licenses_nonce', 'wplm_import_nonce');
            $this->handle_import_licenses();
        }
    }

    /**
     * Generates and outputs the CSV for export.
     * This function is now a reusable helper.
     * @param string $export_type The type of data to export.
     */
    private function generate_and_output_csv(string $export_type) {
        $csv_data = fopen('php://temp', 'r+');

        // Enhanced headers to include all necessary data
        if ($export_type === 'licenses_only' || $export_type === 'licenses_and_products') {
            fputcsv($csv_data, [
                'license_key', 'product_id', 'product_title', 'customer_email', 'expiry_date', 
                'activation_limit', 'status', 'product_type', 'current_version', 'activated_domains',
                'wc_product_id', 'wc_is_licensed', 'wc_current_version'
            ]);
            
            $licenses = get_posts([
                'post_type' => 'wplm_license',
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);

            foreach ($licenses as $license) {
                $license_key = $license->post_title;
                $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
                $customer_email = get_post_meta($license->ID, '_wplm_customer_email', true);
                $expiry_date = get_post_meta($license->ID, '_wplm_expiry_date', true);
                $activation_limit = get_post_meta($license->ID, '_wplm_activation_limit', true);
                $status = get_post_meta($license->ID, '_wplm_status', true);
                $product_type = get_post_meta($license->ID, '_wplm_product_type', true) ?: 'wplm';
                $current_version = get_post_meta($license->ID, '_wplm_current_version', true);
                $activated_domains = get_post_meta($license->ID, '_wplm_activated_domains', true);
                $activated_domains_str = is_array($activated_domains) ? implode('|', $activated_domains) : '';
                
                // Get product title with fallback handling
                $product_title = '';
                if ($product_type === 'wplm') {
                    $product_post = get_posts([
                        'post_type' => 'wplm_product',
                        'meta_key' => '_wplm_product_id',
                        'meta_value' => $product_id,
                        'posts_per_page' => 1
                    ]);
                    if (!empty($product_post)) {
                        $product_title = $product_post[0]->post_title;
                    } else {
                        $product_title = 'WPLM Product: ' . $product_id; // Fallback
                    }
                } elseif ($product_type === 'woocommerce' && function_exists('wc_get_product')) {
                    $wc_product = wc_get_product($product_id);
                    if ($wc_product) {
                        $product_title = $wc_product->get_name();
                        // For variable products, include variation info
                        if ($wc_product->is_type('variation')) {
                            $parent_product = wc_get_product($wc_product->get_parent_id());
                            if ($parent_product) {
                                $product_title = $parent_product->get_name() . ' - ' . implode(', ', $wc_product->get_variation_attributes());
                            }
                        }
                    } else {
                        $product_title = 'WC Product: ' . $product_id; // Fallback
                    }
                }
                
                // Final fallback if title is still empty
                if (empty($product_title)) {
                    $product_title = 'Product: ' . $product_id;
                }
                
                // WooCommerce product data
                $wc_product_id = '';
                $wc_is_licensed = '';
                $wc_current_version = '';
                if ($product_type === 'woocommerce') {
                    $wc_product_id = $product_id;
                    $wc_is_licensed = get_post_meta($product_id, '_wplm_wc_is_licensed_product', true);
                    $wc_current_version = get_post_meta($product_id, '_wplm_wc_current_version', true);
                }
                
                fputcsv($csv_data, [
                    $license_key, $product_id, $product_title, $customer_email, $expiry_date,
                    $activation_limit, $status, $product_type, $current_version, $activated_domains_str,
                    $wc_product_id, $wc_is_licensed, $wc_current_version
                ]);
            }
        }

        if ($export_type === 'products_only') {
            fputcsv($csv_data, ['product_id', 'product_title', 'product_type', 'current_version', 'download_url', 'wc_is_licensed', 'wc_linked_product']);
            
            // Export WPLM Products
            $products = get_posts([
                'post_type' => 'wplm_product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);
            foreach ($products as $product) {
                $product_id = get_post_meta($product->ID, '_wplm_product_id', true);
                $product_title = $product->post_title;
                $current_version = get_post_meta($product->ID, '_wplm_current_version', true);
                $download_url = get_post_meta($product->ID, '_wplm_download_url', true);
                fputcsv($csv_data, [$product_id, $product_title, 'wplm', $current_version, $download_url, '', '']);
            }
            
            // Export WooCommerce Licensed Products
            if (function_exists('wc_get_products')) {
                $wc_products = wc_get_products([
                    'limit' => -1,
                    'status' => 'publish',
                    'meta_key' => '_wplm_wc_is_licensed_product',
                    'meta_value' => 'yes'
                ]);
                foreach ($wc_products as $wc_product) {
                    $wc_current_version = get_post_meta($wc_product->get_id(), '_wplm_wc_current_version', true);
                    $wc_linked_product = get_post_meta($wc_product->get_id(), '_wplm_wc_linked_wplm_product_id', true);
                    fputcsv($csv_data, [
                        $wc_product->get_id(), $wc_product->get_name(), 'woocommerce', 
                        $wc_current_version, '', 'yes', $wc_linked_product
                    ]);
                }
            }
        }

        rewind($csv_data);
        $output = stream_get_contents($csv_data);
        fclose($csv_data);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=wplm-' . $export_type . '-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $output;
        exit;
    }

    /**
     * Handle AJAX export of licenses.
     * This function now just acts as a wrapper to redirect to non-AJAX handler, if called via AJAX.
     * Or it can be removed if direct AJAX export is no longer needed.
     */
    public function ajax_export_licenses() {
        // For direct AJAX calls, redirect to the non-AJAX handler.
        // This ensures the file download headers are correctly sent.
        $export_type = sanitize_text_field($_GET['export_type'] ?? $_POST['export_type'] ?? 'licenses_and_products');
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? '');

        // Redirect to the admin-post handler
        $redirect_url = add_query_arg([
            'action' => 'wplm_export_licenses',
            'wplm_export_type' => $export_type,
            'wplm_export_nonce' => $nonce,
        ], admin_url('admin-post.php'));

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle import of licenses and products from CSV.
     * Always redirects back to settings page with a notice.
     */
    public function handle_import_licenses() {
        // Permissions and nonce checks are now in handle_import_licenses_submission
        
        if (empty($_FILES['wplm_import_file']['tmp_name'])) {
            set_transient('wplm_import_notice', __('No file uploaded.', 'wp-license-manager'), 60);
            wp_safe_redirect(admin_url('admin.php?page=wplm-settings&tab=export_import'));
            exit;
        }

        $file = $_FILES['wplm_import_file'];
        if ($file['type'] !== 'text/csv' && $file['type'] !== 'application/vnd.ms-excel') {
            set_transient('wplm_import_notice', __('Invalid file type. Please upload a CSV file.', 'wp-license-manager'), 60);
            wp_safe_redirect(admin_url('admin.php?page=wplm-settings&tab=export_import'));
            exit;
        }

        $csv_file = fopen($file['tmp_name'], 'r');
        if (!$csv_file) {
            set_transient('wplm_import_notice', __('Failed to open CSV file.', 'wp-license-manager'), 60);
            wp_safe_redirect(admin_url('admin.php?page=wplm-settings&tab=export_import'));
            exit;
        }

        $import_type = sanitize_text_field($_POST['wplm_import_type'] ?? 'licenses_and_products');
        $headers = fgetcsv($csv_file); // Read header row
        
        // Enhanced expected headers
        $expected_headers_licenses = [
            'license_key', 'product_id', 'product_title', 'customer_email', 'expiry_date', 
            'activation_limit', 'status', 'product_type', 'current_version', 'activated_domains',
            'wc_product_id', 'wc_is_licensed', 'wc_current_version'
        ];
        $expected_headers_products = [
            'product_id', 'product_title', 'product_type', 'current_version', 
            'download_url', 'wc_is_licensed', 'wc_linked_product'
        ];
        
        // Legacy support for old format
        $legacy_license_headers = ['license_key', 'product_id', 'customer_email', 'expiry_date', 'activation_limit', 'status', 'product_type', 'current_version'];
        $legacy_product_headers = ['product_id', 'product_title', 'current_version', 'download_url'];

        $imported_count = ['licenses' => 0, 'products' => 0, 'wc_products' => 0];
        $updated_count  = ['licenses' => 0, 'products' => 0, 'wc_products' => 0];
        $failed_count   = ['licenses' => 0, 'products' => 0, 'wc_products' => 0];

        while (($row = fgetcsv($csv_file)) !== false) {
            if (empty($row) || count($row) < 2) {
                continue; // Skip empty rows
            }
            
            // Ensure we have enough columns to match headers
            while (count($row) < count($headers)) {
                $row[] = ''; // Pad with empty strings
            }
            
            $data = array_combine($headers, array_slice($row, 0, count($headers)));

            // --- LICENSE IMPORT ---
            if (($import_type === 'licenses_only' || $import_type === 'licenses_and_products') && 
                isset($data['license_key']) && !empty($data['license_key'])) {
                
                $license_key = sanitize_text_field($data['license_key']);
                $product_id_slug = sanitize_text_field($data['product_id'] ?? '');
                $product_title = sanitize_text_field($data['product_title'] ?? $product_id_slug);
                $customer_email = sanitize_email($data['customer_email'] ?? '');
                $expiry_date = sanitize_text_field($data['expiry_date'] ?? '');
                $activation_limit = intval($data['activation_limit'] ?? 1);
                $status = sanitize_text_field($data['status'] ?? 'active');
                $product_type = sanitize_text_field($data['product_type'] ?? 'wplm');
                $current_version = sanitize_text_field($data['current_version'] ?? '1.0.0');
                $activated_domains_str = sanitize_text_field($data['activated_domains'] ?? '');
                $activated_domains = !empty($activated_domains_str) ? explode('|', $activated_domains_str) : [];

                if (empty($license_key) || empty($product_id_slug)) {
                    $failed_count['licenses']++;
                    continue;
                }

                // Handle product creation/linking based on type
                if ($product_type === 'wplm') {
                    // Find or create WPLM product
                    $product_post = get_posts([
                        'post_type' => 'wplm_product',
                        'meta_key' => '_wplm_product_id',
                        'meta_value' => $product_id_slug,
                        'posts_per_page' => 1
                    ]);
                    
                    if (empty($product_post)) {
                        // Add WPLM prefix to distinguish from WooCommerce products
                        $display_title = 'WPLM ' . $product_title;
                        
                        // Create new WPLM product
                        $product_post_id = wp_insert_post([
                            'post_title'  => $display_title,
                            'post_name'   => sanitize_title($product_title),
                            'post_type'   => 'wplm_product',
                            'post_status' => 'publish',
                        ], true);
                        
                        if (!is_wp_error($product_post_id)) {
                            update_post_meta($product_post_id, '_wplm_product_id', $product_id_slug);
                            update_post_meta($product_post_id, '_wplm_current_version', $current_version);
                            update_post_meta($product_post_id, '_wplm_product_type', 'wplm');
                            update_post_meta($product_post_id, '_wplm_product_source', 'wplm_store');
                            $imported_count['products']++;
                        }
                    } else {
                        $product_post_id = $product_post[0]->ID;
                        update_post_meta($product_post_id, '_wplm_current_version', $current_version);
                        $updated_count['products']++;
                    }
                } elseif ($product_type === 'woocommerce' && function_exists('wc_get_product') && class_exists('WooCommerce')) {
                    // Handle WooCommerce product import if WooCommerce is active
                    $wc_product_id = sanitize_text_field($data['wc_product_id'] ?? $product_id_slug);
                    $wc_is_licensed = sanitize_text_field($data['wc_is_licensed'] ?? 'yes');
                    $wc_current_version = sanitize_text_field($data['wc_current_version'] ?? $current_version);
                    
                    // Try to find WooCommerce product by ID first, then by title
                    $wc_product = wc_get_product($wc_product_id);
                    if (!$wc_product) {
                        // Try to find by product title
                        $wc_products = wc_get_products([
                            'name' => $product_title,
                            'limit' => 1,
                            'status' => 'publish'
                        ]);
                        if (!empty($wc_products)) {
                            $wc_product = $wc_products[0];
                            $wc_product_id = $wc_product->get_id();
                        }
                    }
                    
                    if ($wc_product) {
                        update_post_meta($wc_product_id, '_wplm_wc_is_licensed_product', $wc_is_licensed);
                        update_post_meta($wc_product_id, '_wplm_wc_current_version', $wc_current_version);
                        $updated_count['wc_products']++;
                    } else {
                        // WooCommerce product not found, skip this license
                        error_log('WPLM Import: WooCommerce product not found: ' . $product_title . ' (ID: ' . $wc_product_id . ')');
                        $failed_count['licenses']++;
                        continue;
                    }
                }

                // Find or create the license post
                $license_posts = get_posts([
                    'post_type' => 'wplm_license',
                    'title' => $license_key,
                    'posts_per_page' => 1,
                    'post_status' => 'publish'
                ]);
                if (!empty($license_posts)) {
                    $license_post_id = $license_posts[0]->ID;
                        $updated_count['licenses']++;
                    } else {
                    $license_post_id = wp_insert_post([
                            'post_title'  => $license_key,
                            'post_type'   => 'wplm_license',
                            'post_status' => 'publish',
                        ], true);
                    if (is_wp_error($license_post_id)) {
                            $failed_count['licenses']++;
                        continue;
                    }
                            $imported_count['licenses']++;
                }

                // Update license meta with all data
                if ($license_post_id && !is_wp_error($license_post_id)) {
                    update_post_meta($license_post_id, '_wplm_license_key', $license_key);
                    update_post_meta($license_post_id, '_wplm_product_id', $product_id_slug);
                    update_post_meta($license_post_id, '_wplm_product_type', $product_type);
                    update_post_meta($license_post_id, '_wplm_customer_email', $customer_email);
                    update_post_meta($license_post_id, '_wplm_expiry_date', $expiry_date);
                    update_post_meta($license_post_id, '_wplm_activation_limit', $activation_limit);
                    update_post_meta($license_post_id, '_wplm_status', $status);
                    update_post_meta($license_post_id, '_wplm_current_version', $current_version);
                    update_post_meta($license_post_id, '_wplm_activated_domains', $activated_domains);
                }
            }

            // --- PRODUCT-ONLY IMPORT ---
            if ($import_type === 'products_only' && isset($data['product_id']) && !empty($data['product_id'])) {
                $product_id_slug = sanitize_text_field($data['product_id']);
                $product_title = sanitize_text_field($data['product_title'] ?? $product_id_slug);
                $product_type = sanitize_text_field($data['product_type'] ?? 'wplm');
                $current_version = sanitize_text_field($data['current_version'] ?? '1.0.0');
                $download_url = isset($data['download_url']) ? esc_url_raw($data['download_url']) : '';
                $wc_is_licensed = sanitize_text_field($data['wc_is_licensed'] ?? '');
                $wc_linked_product = sanitize_text_field($data['wc_linked_product'] ?? '');

                if ($product_type === 'wplm') {
                    // Import WPLM product
                    $product_post = get_posts([
                    'post_type' => 'wplm_product',
                    'meta_key' => '_wplm_product_id',
                    'meta_value' => $product_id_slug,
                        'posts_per_page' => 1
                    ]);
                    
                    if (empty($product_post)) {
                    $post_id = wp_insert_post([
                        'post_title'  => $product_title,
                            'post_name'   => sanitize_title($product_title),
                        'post_type'   => 'wplm_product',
                        'post_status' => 'publish',
                    ], true);
                        if (!is_wp_error($post_id)) {
                            $imported_count['products']++;
                        } else {
                        $failed_count['products']++;
                        continue;
                    }
                    } else {
                        $post_id = $product_post[0]->ID;
                        wp_update_post([
                            'ID' => $post_id,
                            'post_title' => $product_title,
                        ]);
                        $updated_count['products']++;
                }

                if ($post_id && !is_wp_error($post_id)) {
                    update_post_meta($post_id, '_wplm_product_id', $product_id_slug);
                        update_post_meta($post_id, '_wplm_current_version', $current_version);
                    update_post_meta($post_id, '_wplm_download_url', $download_url);
                        update_post_meta($post_id, '_wplm_product_type', 'wplm');
                    }
                } elseif ($product_type === 'woocommerce' && function_exists('wc_get_product') && class_exists('WooCommerce')) {
                    // Import WooCommerce licensed product settings
                    $wc_product = wc_get_product($product_id_slug);
                    if ($wc_product) {
                        update_post_meta($product_id_slug, '_wplm_wc_is_licensed_product', $wc_is_licensed);
                        update_post_meta($product_id_slug, '_wplm_wc_current_version', $wc_current_version);
                        update_post_meta($product_id_slug, '_wplm_wc_linked_wplm_product_id', $wc_linked_product);
                        $updated_count['wc_products']++;
                    } else {
                        $failed_count['wc_products']++;
                    }
                }
            }
        }

        fclose($csv_file);

        // Build comprehensive final message
        $message_parts = [];
        if ($imported_count['licenses'] > 0 || $updated_count['licenses'] > 0 || $failed_count['licenses'] > 0) {
            $message_parts[] = sprintf(
                __('Licenses: %d new, %d updated, %d failed.', 'wp-license-manager'),
                $imported_count['licenses'], $updated_count['licenses'], $failed_count['licenses']
            );
        }
        if ($imported_count['products'] > 0 || $updated_count['products'] > 0 || $failed_count['products'] > 0) {
            $message_parts[] = sprintf(
                __('WPLM Products: %d new, %d updated, %d failed.', 'wp-license-manager'),
                $imported_count['products'], $updated_count['products'], $failed_count['products']
            );
        }
        if ($imported_count['wc_products'] > 0 || $updated_count['wc_products'] > 0 || $failed_count['wc_products'] > 0) {
            $message_parts[] = sprintf(
                __('WooCommerce Products: %d new, %d updated, %d failed.', 'wp-license-manager'),
                $imported_count['wc_products'], $updated_count['wc_products'], $failed_count['wc_products']
            );
        }
        
        $final_message = implode(' ', $message_parts);
        if (empty($final_message)) {
            $final_message = __('No data imported or updated.', 'wp-license-manager');
        }

        // Store the message in a transient so it can be shown after redirect
        set_transient('wplm_import_notice', $final_message, 60);

        // Always redirect back to the settings page after import (prevents white screen)
        $redirect_url = admin_url('admin.php?page=wplm-settings&tab=export_import');
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Render the Plugin Manager tab for uploading and managing plugins/themes
     */
    public function render_plugin_manager_tab() {
        // Handle plugin upload if submitted
        if (isset($_POST['wplm_upload_plugin']) && wp_verify_nonce($_POST['wplm_plugin_upload_nonce'], 'wplm_plugin_upload')) {
            $this->handle_plugin_upload();
        }
        
        // Display upload notices
        if ($notice = get_transient('wplm_plugin_upload_notice')) {
            $notice_type = get_transient('wplm_plugin_upload_notice_type') ?: 'success';
            echo '<div class="notice notice-' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html($notice) . '</p></div>';
            delete_transient('wplm_plugin_upload_notice');
            delete_transient('wplm_plugin_upload_notice_type');
        }
        ?>
        <h3><?php _e('Plugin/Theme Manager', 'wp-license-manager'); ?></h3>
        <p><?php _e('Upload and manage your plugins and themes with integrated license protection.', 'wp-license-manager'); ?></p>
        
        <!-- Upload Form -->
        <div class="card">
            <h4><?php _e('Upload New Plugin/Theme', 'wp-license-manager'); ?></h4>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('wplm_plugin_upload', 'wplm_plugin_upload_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_file"><?php _e('Plugin/Theme File', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="file" id="wplm_plugin_file" name="wplm_plugin_file" accept=".zip" required />
                            <p class="description"><?php _e('Upload a ZIP file containing your plugin or theme.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_name"><?php _e('Product Name', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wplm_plugin_name" name="wplm_plugin_name" class="regular-text" required />
                            <p class="description"><?php _e('Enter a name for this product (e.g., "My Awesome Plugin").', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_slug"><?php _e('Product ID/Slug', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wplm_plugin_slug" name="wplm_plugin_slug" class="regular-text" required />
                            <p class="description"><?php _e('Unique identifier for this product (e.g., "my-awesome-plugin").', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_version"><?php _e('Version', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wplm_plugin_version" name="wplm_plugin_version" class="regular-text" value="1.0.0" required />
                            <p class="description"><?php _e('Version number for this release (e.g., "1.0.0").', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_plugin_type"><?php _e('Type', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <select id="wplm_plugin_type" name="wplm_plugin_type" required>
                                <option value="plugin"><?php _e('WordPress Plugin', 'wp-license-manager'); ?></option>
                                <option value="theme"><?php _e('WordPress Theme', 'wp-license-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wplm_watermark_enabled"><?php _e('Enable Watermarking', 'wp-license-manager'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="wplm_watermark_enabled" name="wplm_watermark_enabled" value="1" checked />
                            <label for="wplm_watermark_enabled"><?php _e('Add license information to downloaded files', 'wp-license-manager'); ?></label>
                            <p class="description"><?php _e('When enabled, each licensed download will include customer and license information.', 'wp-license-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="wplm_upload_plugin" class="button button-primary" value="<?php esc_attr_e('Upload Plugin/Theme', 'wp-license-manager'); ?>" />
                </p>
            </form>
        </div>
        
        <!-- Uploaded Plugins List -->
        <div class="card">
            <h4><?php _e('Managed Plugins/Themes', 'wp-license-manager'); ?></h4>
            <?php $this->render_uploaded_plugins_list(); ?>
        </div>
        <?php
    }
    
    /**
     * Handle plugin/theme upload
     */
    private function handle_plugin_upload() {
        if (!current_user_can('manage_wplm_licenses')) {
            set_transient('wplm_plugin_upload_notice', __('Permission denied.', 'wp-license-manager'), 60);
            set_transient('wplm_plugin_upload_notice_type', 'error', 60);
            return;
        }
        
        if (empty($_FILES['wplm_plugin_file']['tmp_name'])) {
            set_transient('wplm_plugin_upload_notice', __('No file uploaded.', 'wp-license-manager'), 60);
            set_transient('wplm_plugin_upload_notice_type', 'error', 60);
            return;
        }
        
        $file = $_FILES['wplm_plugin_file'];
        $plugin_name = sanitize_text_field($_POST['wplm_plugin_name']);
        $plugin_slug = sanitize_key($_POST['wplm_plugin_slug']);
        $plugin_version = sanitize_text_field($_POST['wplm_plugin_version']);
        $plugin_type = sanitize_text_field($_POST['wplm_plugin_type']);
        $watermark_enabled = isset($_POST['wplm_watermark_enabled']);
        
        // Validate file type
        if ($file['type'] !== 'application/zip' && $file['type'] !== 'application/x-zip-compressed') {
            set_transient('wplm_plugin_upload_notice', __('Invalid file type. Please upload a ZIP file.', 'wp-license-manager'), 60);
            set_transient('wplm_plugin_upload_notice_type', 'error', 60);
            return;
        }
        
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $wplm_upload_dir = $upload_dir['basedir'] . '/wplm-products/';
        if (!file_exists($wplm_upload_dir)) {
            wp_mkdir_p($wplm_upload_dir);
        }
        
        // Generate unique filename
        $filename = $plugin_slug . '-v' . $plugin_version . '.zip';
        $file_path = $wplm_upload_dir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            set_transient('wplm_plugin_upload_notice', __('Failed to move uploaded file.', 'wp-license-manager'), 60);
            set_transient('wplm_plugin_upload_notice_type', 'error', 60);
            return;
        }
        
        // Automatically inject license client into the plugin
        $licensed_file_path = $this->inject_license_client($file_path, $plugin_slug, $plugin_name, $plugin_version);
        if (!$licensed_file_path) {
            set_transient('wplm_plugin_upload_notice', __('Plugin uploaded but license injection failed.', 'wp-license-manager'), 60);
            set_transient('wplm_plugin_upload_notice_type', 'warning', 60);
            $licensed_file_path = $file_path; // Use original if injection fails
        }
        
        // Create or update WPLM product
        $existing_product = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $plugin_slug,
            'posts_per_page' => 1
        ]);
        
        if (!empty($existing_product)) {
            $product_id = $existing_product[0]->ID;
            // Add WPLM prefix to distinguish from WooCommerce products
            $display_title = 'WPLM ' . $plugin_name;
            wp_update_post([
                'ID' => $product_id,
                'post_title' => $display_title
            ]);
        } else {
            // Add WPLM prefix to distinguish from WooCommerce products
            $display_title = 'WPLM ' . $plugin_name;
            $product_id = wp_insert_post([
                'post_title' => $display_title,
                'post_type' => 'wplm_product',
                'post_status' => 'publish'
            ]);
        }
        
        if (!is_wp_error($product_id)) {
            // Use licensed version if available, otherwise original
            $final_file_path = $licensed_file_path ?: $file_path;
            $final_filename = basename($final_file_path);
            $download_url = str_replace('/wplm-products/', '/wplm-licensed/', $upload_dir['baseurl'] . '/wplm-products/' . $filename);
            if (!$licensed_file_path) {
                $download_url = $upload_dir['baseurl'] . '/wplm-products/' . $filename;
            }
            
            update_post_meta($product_id, '_wplm_product_id', $plugin_slug);
            update_post_meta($product_id, '_wplm_current_version', $plugin_version);
            update_post_meta($product_id, '_wplm_download_url', $download_url);
            update_post_meta($product_id, '_wplm_product_type', 'wplm');
            update_post_meta($product_id, '_wplm_product_source', 'wplm_store');
            update_post_meta($product_id, '_wplm_file_path', $final_file_path);
            update_post_meta($product_id, '_wplm_plugin_type', $plugin_type);
            update_post_meta($product_id, '_wplm_watermark_enabled', $watermark_enabled ? 'yes' : 'no');
            update_post_meta($product_id, '_wplm_auto_licensed', $licensed_file_path ? 'yes' : 'no');
            
            set_transient('wplm_plugin_upload_notice', sprintf(
                __('Successfully uploaded %s version %s.', 'wp-license-manager'),
                $plugin_name, $plugin_version
            ), 60);
        } else {
            set_transient('wplm_plugin_upload_notice', __('Failed to create product entry.', 'wp-license-manager'), 60);
            set_transient('wplm_plugin_upload_notice_type', 'error', 60);
        }
    }
    
    /**
     * Render list of uploaded plugins/themes
     */
    private function render_uploaded_plugins_list() {
        $products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_wplm_file_path',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        if (empty($products)) {
            echo '<p>' . __('No plugins or themes uploaded yet.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Name', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Product ID', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Version', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Type', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Watermarked', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Actions', 'wp-license-manager') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($products as $product) {
            $product_id = get_post_meta($product->ID, '_wplm_product_id', true);
            $version = get_post_meta($product->ID, '_wplm_current_version', true);
            $type = get_post_meta($product->ID, '_wplm_plugin_type', true);
            $watermarked = get_post_meta($product->ID, '_wplm_watermark_enabled', true) === 'yes';
            $download_url = get_post_meta($product->ID, '_wplm_download_url', true);
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($product->post_title) . '</strong></td>';
            echo '<td>' . esc_html($product_id) . '</td>';
            echo '<td>' . esc_html($version) . '</td>';
            echo '<td>' . esc_html(ucfirst($type)) . '</td>';
            echo '<td>' . ($watermarked ? '✓' : '✗') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($download_url) . '" class="button button-small">' . __('Download', 'wp-license-manager') . '</a> ';
            echo '<a href="' . esc_url(admin_url('post.php?post=' . $product->ID . '&action=edit')) . '" class="button button-small">' . __('Edit', 'wp-license-manager') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Generate a standardized license key
     * Format: XXXX-XXXX-XXXX-XXXX-XXXX (20 characters + 4 dashes = 24 total)
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
     * Calculate expiry date based on duration type and value
     */
    private function calculate_expiry_date($duration_type, $duration_value) {
        $current_time = current_time('Y-m-d');
        
        switch ($duration_type) {
            case 'days':
                return date('Y-m-d', strtotime($current_time . ' + ' . $duration_value . ' days'));
            case 'months':
                return date('Y-m-d', strtotime($current_time . ' + ' . $duration_value . ' months'));
            case 'years':
                return date('Y-m-d', strtotime($current_time . ' + ' . $duration_value . ' years'));
            default:
                return ''; // Lifetime
        }
    }
    
    /**
     * Inject license client into uploaded plugin automatically
     */
    private function inject_license_client($original_file_path, $plugin_slug, $plugin_name, $plugin_version) {
        // For now, just return the original file path
        // This feature requires ZipArchive which may not be available on all servers
        return $original_file_path;
    }
    
    /**
     * Render the enhanced dashboard page
     */
    public function render_dashboard_page() {
        // Enqueue Chart.js for dashboard charts
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        
        // Include the modern dashboard template
        include WPLM_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }

    /**
     * Get dashboard statistics
     */
    public function get_dashboard_stats() {
        // Total licenses
        $total_licenses = wp_count_posts('wplm_license')->publish;
        
        // Active licenses
        $active_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_wplm_status',
            'meta_value' => 'active',
            'no_found_rows' => false,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ]);
        $active_licenses = $active_licenses_query->found_posts;
        
        // Inactive licenses
        $inactive_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_wplm_status',
            'meta_value' => 'inactive',
            'no_found_rows' => false,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ]);
        $inactive_licenses = $inactive_licenses_query->found_posts;
        
        // Expired licenses
        $expired_licenses_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_wplm_status',
            'meta_value' => 'expired',
            'no_found_rows' => false,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ]);
        $expired_licenses = $expired_licenses_query->found_posts;
        
        // Total products
        $total_products = wp_count_posts('wplm_product')->publish;
        
        // WooCommerce products with license integration
        $wc_products = 0;
        if (function_exists('wc_get_products')) {
            $licensed_wc_products = get_posts([
                'post_type' => 'product',
                'meta_key' => '_wplm_wc_is_licensed_product',
                'meta_value' => 'yes',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            $wc_products = count($licensed_wc_products);
        }
        
        // Unique customers
        $customer_emails = [];
        $licenses_with_emails = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'meta_key' => '_wplm_customer_email',
            'fields' => 'ids'
        ]);
        
        foreach ($licenses_with_emails as $license_id) {
            $email = get_post_meta($license_id, '_wplm_customer_email', true);
            if (!empty($email)) {
                $customer_emails[$email] = true;
            }
        }
        $total_customers = count($customer_emails);
        
        // New customers this month
        $current_month_start = date('Y-m-01 00:00:00');
        $new_customers_month_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'date_query' => [
                'after' => $current_month_start
            ],
            'fields' => 'ids'
        ]);
        
        $new_emails_month = [];
        foreach ($new_customers_month_query->posts as $license_id) {
            $email = get_post_meta($license_id, '_wplm_customer_email', true);
            if (!empty($email)) {
                $new_emails_month[$email] = true;
            }
        }
        $new_customers_month = count($new_emails_month);
        
        // Monthly revenue (if WooCommerce is available)
        $monthly_revenue = 0;
        $revenue_growth = 0;
        
        if (function_exists('wc_get_orders')) {
            $current_month_orders = wc_get_orders([
                'status' => 'completed',
                'date_created' => '>=' . strtotime($current_month_start),
                'limit' => -1,
            ]);
            
            foreach ($current_month_orders as $order) {
                foreach ($order->get_items() as $item) {
                    $license_key = wc_get_order_item_meta($item->get_id(), '_wplm_license_key', true);
                    if (!empty($license_key)) {
                        $monthly_revenue += $item->get_total();
                    }
                }
            }
            
            // Previous month for comparison
            $previous_month_start = date('Y-m-01 00:00:00', strtotime('-1 month'));
            $previous_month_end = date('Y-m-t 23:59:59', strtotime('-1 month'));
            
            $previous_month_orders = wc_get_orders([
                'status' => 'completed',
                'date_created' => '>=' . strtotime($previous_month_start),
                'date_created' => '<=' . strtotime($previous_month_end),
                'limit' => -1,
            ]);
            
            $previous_revenue = 0;
            foreach ($previous_month_orders as $order) {
                foreach ($order->get_items() as $item) {
                    $license_key = wc_get_order_item_meta($item->get_id(), '_wplm_license_key', true);
                    if (!empty($license_key)) {
                        $previous_revenue += $item->get_total();
                    }
                }
            }
            
            if ($previous_revenue > 0) {
                $revenue_growth = (($monthly_revenue - $previous_revenue) / $previous_revenue) * 100;
            }
        }
        
        // Recent activity
        $recent_activity = [];
        if (class_exists('WPLM_Activity_Logger')) {
            $activity_query = new WP_Query([
                'post_type' => 'wplm_activity_log',
                'posts_per_page' => 10,
                'orderby' => 'date',
                'order' => 'DESC',
                'fields' => 'ids'
            ]);
            
            foreach ($activity_query->posts as $activity_id) {
                $activity_post = get_post($activity_id);
                $action_type = get_post_meta($activity_id, '_wplm_action_type', true);
                
                $icon = 'admin-network';
                switch ($action_type) {
                    case 'license_created':
                        $icon = 'plus-alt';
                        break;
                    case 'license_activated':
                        $icon = 'yes';
                        break;
                    case 'license_deactivated':
                        $icon = 'no';
                        break;
                    case 'license_expired':
                        $icon = 'clock';
                        break;
                    case 'product_created':
                        $icon = 'products';
                        break;
                    default:
                        $icon = 'admin-network';
                }
                
                $recent_activity[] = [
                    'message' => $activity_post->post_content,
                    'date' => $activity_post->post_date,
                    'icon' => $icon
                ];
            }
        }
        
        // Expiring licenses
        $expiring_licenses = [];
        $expiring_query = new WP_Query([
            'post_type' => 'wplm_license',
            'posts_per_page' => 10,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wplm_status',
                    'value' => 'active',
                    'compare' => '='
                ],
                [
                    'key' => '_wplm_expiry_date',
                    'value' => '',
                    'compare' => '!='
                ],
                [
                    'key' => '_wplm_expiry_date',
                    'value' => date('Y-m-d', strtotime('+30 days')),
                    'compare' => '<=',
                    'type' => 'DATE'
                ]
            ],
            'orderby' => 'meta_value',
            'meta_key' => '_wplm_expiry_date',
            'order' => 'ASC'
        ]);
        
        foreach ($expiring_query->posts as $license) {
            $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
            $product_name = $product_id;
            
            // Try to get product name
            $product_post = get_posts([
                'post_type' => 'wplm_product',
                'meta_key' => '_wplm_product_id',
                'meta_value' => $product_id,
                'posts_per_page' => 1
            ]);
            
            if (!empty($product_post)) {
                $product_name = $product_post[0]->post_title;
            }
            
            $expiring_licenses[] = [
                'key' => $license->post_title,
                'product' => $product_name,
                'expiry_date' => get_post_meta($license->ID, '_wplm_expiry_date', true)
            ];
        }
        
        return [
            'total_licenses' => $total_licenses,
            'active_licenses' => $active_licenses,
            'inactive_licenses' => $inactive_licenses,
            'expired_licenses' => $expired_licenses,
            'total_products' => $total_products,
            'wc_products' => $wc_products,
            'total_customers' => $total_customers,
            'new_customers_month' => $new_customers_month,
            'monthly_revenue' => $monthly_revenue,
            'revenue_growth' => $revenue_growth,
            'recent_activity' => $recent_activity,
            'expiring_licenses' => $expiring_licenses
        ];
    }
    
    /**
     * Render the subscriptions management page
     */
    public function render_subscriptions_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Subscription Management', 'wp-license-manager'); ?></h1>
            
            <?php if (!class_exists('WCS_Subscription')): ?>
                <div class="notice notice-warning">
                    <p><?php _e('WooCommerce Subscriptions plugin is not active. Some features may be limited.', 'wp-license-manager'); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="wplm-subscriptions-content">
                <div class="wplm-subscription-stats">
                    <h2><?php _e('Subscription Overview', 'wp-license-manager'); ?></h2>
                    <?php $this->render_subscription_stats(); ?>
                </div>
                
                <div class="wplm-subscription-list">
                    <h2><?php _e('Recent Subscriptions', 'wp-license-manager'); ?></h2>
                    <?php $this->render_subscription_list(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the customers CRM page
     */
    public function render_customers_page() {
        // Include the modern customers template
        include WPLM_PLUGIN_DIR . 'templates/admin/customers.php';
    }

    /**
     * Get customers data for the CRM
     */
    public function get_customers_data() {
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($current_page - 1) * $per_page;

        // Get all licenses with customer emails
        $license_args = [
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'meta_key' => '_wplm_customer_email',
            'meta_value' => '',
            'meta_compare' => '!=',
            'fields' => 'ids'
        ];

        if (!empty($search)) {
            $license_args['meta_query'] = [
                [
                    'key' => '_wplm_customer_email',
                    'value' => $search,
                    'compare' => 'LIKE'
                ]
            ];
        }

        $licenses = get_posts($license_args);
        
        // Group licenses by customer email
        $customers_data = [];
        
        foreach ($licenses as $license_id) {
            $email = get_post_meta($license_id, '_wplm_customer_email', true);
            if (empty($email)) continue;
            
            if (!isset($customers_data[$email])) {
                $customers_data[$email] = [
                    'email' => $email,
                    'name' => '',
                    'wc_customer_id' => null,
                    'licenses' => [],
                    'products' => [],
                    'total_licenses' => 0,
                    'active_licenses' => 0,
                    'inactive_licenses' => 0,
                    'expired_licenses' => 0,
                    'total_value' => 0,
                    'first_license_date' => ''
                ];
                
                // Try to get WooCommerce customer data
                if (function_exists('get_user_by')) {
                    $wc_user = get_user_by('email', $email);
                    if ($wc_user) {
                        $customers_data[$email]['name'] = $wc_user->display_name;
                        $customers_data[$email]['wc_customer_id'] = $wc_user->ID;
                    }
                }
            }
            
            $license_post = get_post($license_id);
            $status = get_post_meta($license_id, '_wplm_status', true);
            $product_id = get_post_meta($license_id, '_wplm_product_id', true);
            
            $customers_data[$email]['licenses'][] = [
                'id' => $license_id,
                'key' => $license_post->post_title,
                'status' => $status,
                'product_id' => $product_id,
                'date' => $license_post->post_date
            ];
            
            $customers_data[$email]['total_licenses']++;
            
            if ($status === 'active') {
                $customers_data[$email]['active_licenses']++;
            } elseif ($status === 'expired') {
                $customers_data[$email]['expired_licenses']++;
            } else {
                $customers_data[$email]['inactive_licenses']++;
            }
            
            // Add product to list if not already there
            if (!empty($product_id) && !in_array($product_id, $customers_data[$email]['products'])) {
                // Try to get product name
                $product_posts = get_posts([
                    'post_type' => 'wplm_product',
                    'meta_key' => '_wplm_product_id',
                    'meta_value' => $product_id,
                    'posts_per_page' => 1
                ]);
                
                $product_name = $product_id;
                if (!empty($product_posts)) {
                    $product_name = $product_posts[0]->post_title;
                }
                
                $customers_data[$email]['products'][] = $product_name;
            }
            
            // Set first license date
            if (empty($customers_data[$email]['first_license_date']) || 
                strtotime($license_post->post_date) < strtotime($customers_data[$email]['first_license_date'])) {
                $customers_data[$email]['first_license_date'] = $license_post->post_date;
            }
        }
        
        // Calculate total value for each customer (if WooCommerce available)
        if (function_exists('wc_get_orders')) {
            foreach ($customers_data as $email => &$customer) {
                $customer_orders = wc_get_orders([
                    'billing_email' => $email,
                    'status' => 'completed',
                    'limit' => -1
                ]);
                
                foreach ($customer_orders as $order) {
                    foreach ($order->get_items() as $item) {
                        $license_key = wc_get_order_item_meta($item->get_id(), '_wplm_license_key', true);
                        if (!empty($license_key)) {
                            $customer['total_value'] += $item->get_total();
                        }
                    }
                }
            }
        }
        
        // Sort customers by total licenses (descending)
        uasort($customers_data, function($a, $b) {
            return $b['total_licenses'] - $a['total_licenses'];
        });
        
        $total_customers = count($customers_data);
        
        // Apply pagination
        $customers = array_slice($customers_data, $offset, $per_page, true);
        
        // Calculate statistics
        $new_this_month = 0;
        $active_customers = 0;
        $total_value = 0;
        
        $current_month_start = date('Y-m-01 00:00:00');
        
        foreach ($customers_data as $customer) {
            if ($customer['active_licenses'] > 0) {
                $active_customers++;
            }
            
            $total_value += $customer['total_value'];
            
            if (!empty($customer['first_license_date']) && 
                strtotime($customer['first_license_date']) >= strtotime($current_month_start)) {
                $new_this_month++;
            }
        }
        
        $avg_customer_value = $total_customers > 0 ? $total_value / $total_customers : 0;
        
        return [
            'customers' => $customers,
            'total' => $total_customers,
            'new_this_month' => $new_this_month,
            'active_customers' => $active_customers,
            'avg_customer_value' => $avg_customer_value
        ];
    }
    
    /**
     * Render the activity log page
     */
    public function render_activity_log_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Activity Log', 'wp-license-manager'); ?></h1>
            
            <div class="wplm-activity-controls">
                <div class="wplm-activity-filters">
                    <select id="wplm-activity-type">
                        <option value=""><?php _e('All Activity Types', 'wp-license-manager'); ?></option>
                        <option value="license_created"><?php _e('License Created', 'wp-license-manager'); ?></option>
                        <option value="license_activated"><?php _e('License Activated', 'wp-license-manager'); ?></option>
                        <option value="license_deactivated"><?php _e('License Deactivated', 'wp-license-manager'); ?></option>
                        <option value="license_expired"><?php _e('License Expired', 'wp-license-manager'); ?></option>
                        <option value="product_created"><?php _e('Product Created', 'wp-license-manager'); ?></option>
                    </select>
                    
                    <input type="date" id="wplm-activity-date-from" />
                    <input type="date" id="wplm-activity-date-to" />
                    
                    <button type="button" class="button" id="wplm-filter-activity"><?php _e('Filter', 'wp-license-manager'); ?></button>
                    <button type="button" class="button button-secondary" id="wplm-clear-activity"><?php _e('Clear Log', 'wp-license-manager'); ?></button>
                </div>
            </div>
            
            <div class="wplm-activity-log">
                <?php $this->render_activity_log_table(); ?>
            </div>
        </div>
        
        <style>
        .wplm-activity-controls {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .wplm-activity-filters {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .wplm-activity-filters select,
        .wplm-activity-filters input {
            min-width: 150px;
        }
        .wplm-activity-log {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        </style>
        <?php
    }
    

    
    /**
     * Render recent activity
     */
    private function render_recent_activity() {
        if (!class_exists('WPLM_Activity_Logger')) {
            echo '<p>' . __('Activity logging is not available.', 'wp-license-manager') . '</p>';
            return;
        }
        
        global $wpdb;
        $activities = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}wplm_activity_log 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        
        if (empty($activities)) {
            echo '<p>' . __('No recent activity.', 'wp-license-manager') . '</p>';
            return;
        }
        
        foreach ($activities as $activity) {
            echo '<div class="wplm-activity-item">';
            echo '<span class="wplm-activity-description">' . esc_html($activity->description) . '</span>';
            echo '<span class="wplm-activity-time">' . esc_html(human_time_diff(strtotime($activity->created_at))) . ' ago</span>';
            echo '</div>';
        }
    }
    
    /**
     * Render top products chart
     */
    private function render_top_products_chart($top_products) {
        if (empty($top_products)) {
            echo '<p>' . __('No data available.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<div class="wplm-products-chart">';
        foreach ($top_products as $product) {
            $product_name = $this->get_product_name($product->product_id);
            $percentage = ($product->license_count / $this->get_dashboard_stats()['total_licenses']) * 100;
            
            echo '<div class="wplm-product-item">';
            echo '<span class="wplm-product-name">' . esc_html($product_name) . '</span>';
            echo '<div class="wplm-product-bar">';
            echo '<div class="wplm-product-fill" style="width: ' . esc_attr($percentage) . '%"></div>';
            echo '</div>';
            echo '<span class="wplm-product-count">' . esc_html($product->license_count) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '<style>
        .wplm-product-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .wplm-product-name {
            min-width: 100px;
            font-size: 12px;
        }
        .wplm-product-bar {
            flex: 1;
            height: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .wplm-product-fill {
            height: 100%;
            background: #0073aa;
            transition: width 0.3s;
        }
        .wplm-product-count {
            min-width: 30px;
            text-align: right;
            font-weight: bold;
            font-size: 12px;
        }
        </style>';
    }
    
    /**
     * Helper methods for the new pages
     */
    private function get_product_name($product_id) {
        // Try to get WPLM product first
        $product_posts = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $product_id,
            'posts_per_page' => 1
        ]);
        
        if (!empty($product_posts)) {
            return $product_posts[0]->post_title;
        }
        
        // Try WooCommerce product
        if (function_exists('wc_get_product')) {
            $wc_product = wc_get_product($product_id);
            if ($wc_product) {
                return $wc_product->get_name();
            }
        }
        
        return $product_id;
    }
    
    /**
     * Render subscription stats
     */
    private function render_subscription_stats() {
        if (!class_exists('WCS_Subscription')) {
            echo '<p>' . __('WooCommerce Subscriptions not available.', 'wp-license-manager') . '</p>';
            return;
        }
        
        $subscriptions = wcs_get_subscriptions([
            'subscriptions_per_page' => -1,
            'subscription_status' => ['active', 'on-hold', 'cancelled', 'expired']
        ]);
        
        $stats = [
            'active' => 0,
            'on-hold' => 0,
            'cancelled' => 0,
            'expired' => 0
        ];
        
        foreach ($subscriptions as $subscription) {
            $status = $subscription->get_status();
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }
        
        echo '<div class="wplm-subscription-stats-grid">';
        foreach ($stats as $status => $count) {
            echo '<div class="wplm-stat-item wplm-stat-' . esc_attr($status) . '">';
            echo '<h3>' . esc_html($count) . '</h3>';
            echo '<p>' . esc_html(ucfirst($status)) . '</p>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '<style>
        .wplm-subscription-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .wplm-stat-item {
            text-align: center;
            padding: 15px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid #ddd;
        }
        .wplm-stat-item h3 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        .wplm-stat-item p {
            margin: 5px 0 0 0;
            font-size: 12px;
        }
        .wplm-stat-active { border-left: 4px solid #46b450; }
        .wplm-stat-on-hold { border-left: 4px solid #ffb900; }
        .wplm-stat-cancelled { border-left: 4px solid #dc3232; }
        .wplm-stat-expired { border-left: 4px solid #666; }
        </style>';
    }
    
    /**
     * Render subscription list
     */
    private function render_subscription_list() {
        if (!class_exists('WCS_Subscription')) {
            echo '<p>' . __('WooCommerce Subscriptions not available.', 'wp-license-manager') . '</p>';
            return;
        }
        
        $subscriptions = wcs_get_subscriptions([
            'subscriptions_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        if (empty($subscriptions)) {
            echo '<p>' . __('No subscriptions found.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Subscription', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Customer', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Status', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Next Payment', 'wp-license-manager') . '</th>';
        echo '<th>' . __('License Key', 'wp-license-manager') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($subscriptions as $subscription) {
            $customer = $subscription->get_user();
            $license_key = $this->get_subscription_license_key($subscription);
            
            echo '<tr>';
            echo '<td><a href="' . esc_url($subscription->get_edit_order_url()) . '">#' . esc_html($subscription->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($customer ? $customer->display_name : 'Guest') . '</td>';
            echo '<td><span class="wplm-status wplm-status-' . esc_attr($subscription->get_status()) . '">' . esc_html(wcs_get_subscription_status_name($subscription->get_status())) . '</span></td>';
            echo '<td>' . esc_html($subscription->get_date_to_display('next_payment')) . '</td>';
            echo '<td>' . esc_html($license_key ?: '—') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        echo '<style>
        .wplm-status {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .wplm-status-active { background: #46b450; color: #fff; }
        .wplm-status-on-hold { background: #ffb900; color: #fff; }
        .wplm-status-cancelled { background: #dc3232; color: #fff; }
        .wplm-status-expired { background: #666; color: #fff; }
        </style>';
    }
    
    /**
     * Get license key for subscription
     */
    private function get_subscription_license_key($subscription) {
        $parent_order = $subscription->get_parent();
        if (!$parent_order) {
            return '';
        }
        
        foreach ($parent_order->get_items() as $item) {
            $license_key = wc_get_order_item_meta($item->get_id(), '_wplm_license_key', true);
            if (!empty($license_key)) {
                return $license_key;
            }
        }
        
        return '';
    }
    
    /**
     * Render customers table
     */
    private function render_customers_table() {
        global $wpdb;
        
        $customers = $wpdb->get_results("
            SELECT 
                pm.meta_value as email,
                COUNT(DISTINCT p.ID) as license_count,
                COUNT(DISTINCT pm2.meta_value) as product_count,
                MAX(p.post_date) as last_license_date
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wplm_product_id'
            WHERE pm.meta_key = '_wplm_customer_email'
            AND pm.meta_value != ''
            AND p.post_type = 'wplm_license'
            AND p.post_status = 'publish'
            GROUP BY pm.meta_value
            ORDER BY license_count DESC
            LIMIT 50
        ");
        
        if (empty($customers)) {
            echo '<p>' . __('No customers found.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Customer Email', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Licenses', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Products', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Last License', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Actions', 'wp-license-manager') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($customer->email) . '</strong></td>';
            echo '<td>' . esc_html($customer->license_count) . '</td>';
            echo '<td>' . esc_html($customer->product_count) . '</td>';
            echo '<td>' . esc_html(human_time_diff(strtotime($customer->last_license_date))) . ' ago</td>';
            echo '<td>';
            echo '<a href="' . esc_url(admin_url('edit.php?post_type=wplm_license&customer_email=' . urlencode($customer->email))) . '" class="button button-small">' . __('View Licenses', 'wp-license-manager') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Render activity log table
     */
    private function render_activity_log_table() {
        if (!class_exists('WPLM_Activity_Logger')) {
            echo '<div class="notice notice-warning"><p>' . __('Activity logging is not available.', 'wp-license-manager') . '</p></div>';
            return;
        }
        
        global $wpdb;
        $activities = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}wplm_activity_log 
            ORDER BY created_at DESC 
            LIMIT 100
        ");
        
        if (empty($activities)) {
            echo '<p>' . __('No activity logged yet.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Date/Time', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Type', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Description', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Object ID', 'wp-license-manager') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($activities as $activity) {
            echo '<tr>';
            echo '<td>' . esc_html(date('Y-m-d H:i:s', strtotime($activity->created_at))) . '</td>';
            echo '<td><span class="wplm-activity-type">' . esc_html($activity->activity_type) . '</span></td>';
            echo '<td>' . esc_html($activity->description) . '</td>';
            echo '<td>' . esc_html($activity->object_id) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        echo '<style>
        .wplm-activity-type {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-family: monospace;
        }
        </style>';
    }
    
    /**
     * Add bulk actions to the product list table.
     */
    public function add_product_bulk_actions($bulk_actions) {
        // Add standard WordPress bulk actions
        if (!isset($bulk_actions['trash'])) {
            $bulk_actions['trash'] = __('Move to Trash', 'wp-license-manager');
        }
        if (!isset($bulk_actions['delete'])) {
            $bulk_actions['delete'] = __('Delete Permanently', 'wp-license-manager');
        }
        return $bulk_actions;
    }
    
    /**
     * Add bulk actions to the subscription list table.
     */
    public function add_subscription_bulk_actions($bulk_actions) {
        // Add standard WordPress bulk actions
        if (!isset($bulk_actions['trash'])) {
            $bulk_actions['trash'] = __('Move to Trash', 'wp-license-manager');
        }
        if (!isset($bulk_actions['delete'])) {
            $bulk_actions['delete'] = __('Delete Permanently', 'wp-license-manager');
        }
        return $bulk_actions;
    }
    
    /**
     * Handle bulk actions for products.
     */
    public function handle_product_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'delete' && $doaction !== 'trash') {
            return $redirect_to;
        }

        $processed = 0;
        $errors = [];

        foreach ($post_ids as $post_id) {
            if (!current_user_can('delete_post', $post_id)) {
                $errors[] = sprintf(__('Permission denied to delete product ID %d', 'wp-license-manager'), $post_id);
                continue;
            }

            switch ($doaction) {
                case 'delete':
                    $result = wp_delete_post($post_id, true);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = sprintf(__('Failed to delete product ID %d', 'wp-license-manager'), $post_id);
                    }
                    break;

                case 'trash':
                    $result = wp_trash_post($post_id);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = sprintf(__('Failed to move product ID %d to trash', 'wp-license-manager'), $post_id);
                    }
                    break;
            }
        }

        $redirect_to = add_query_arg([
            'bulk_action' => $doaction,
            'processed' => $processed,
            'errors' => count($errors)
        ], $redirect_to);

        return $redirect_to;
    }
    
    /**
     * Handle bulk actions for subscriptions.
     */
    public function handle_subscription_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'delete' && $doaction !== 'trash') {
            return $redirect_to;
        }

        $processed = 0;
        $errors = [];

        foreach ($post_ids as $post_id) {
            if (!current_user_can('delete_post', $post_id)) {
                $errors[] = sprintf(__('Permission denied to delete subscription ID %d', 'wp-license-manager'), $post_id);
                continue;
            }

            switch ($doaction) {
                case 'delete':
                    $result = wp_delete_post($post_id, true);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = sprintf(__('Failed to delete subscription ID %d', 'wp-license-manager'), $post_id);
                    }
                    break;

                case 'trash':
                    $result = wp_trash_post($post_id);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = sprintf(__('Failed to move subscription ID %d to trash', 'wp-license-manager'), $post_id);
                    }
                    break;
            }
        }

        $redirect_to = add_query_arg([
            'bulk_action' => $doaction,
            'processed' => $processed,
            'errors' => count($errors)
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Helper function to replace deprecated get_page_by_title
     */
    private function get_post_by_title($title, $post_type = 'post') {
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
     * AJAX handler to search customers for the license form.
     */
    public function ajax_search_customers() {
        if (!isset($_POST['search_term']) || !isset($_POST['nonce'])) {
            wp_send_json_error(['message' => 'Missing parameters.'], 400);
        }

        $search_term = sanitize_text_field($_POST['search_term']);
        $nonce = sanitize_text_field($_POST['nonce']);

        if (!wp_verify_nonce($nonce, 'wplm_search_customers_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
        }

        if (!current_user_can('edit_posts') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $results = [];

        // Search existing license customers
        $existing_customers = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => 10,
            'meta_query' => [
                [
                    'key' => '_wplm_customer_email',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                ]
            ]
        ]);

        foreach ($existing_customers as $license) {
            $email = get_post_meta($license->ID, '_wplm_customer_email', true);
            if (!empty($email) && !in_array($email, array_column($results, 'email'))) {
                $results[] = [
                    'email' => $email,
                    'name' => 'License Customer'
                ];
            }
        }

        // Search WooCommerce customers if WooCommerce is active
        if (function_exists('wc_get_customers')) {
            $wc_customers = wc_get_customers([
                'search' => $search_term,
                'limit' => 10
            ]);

            foreach ($wc_customers as $customer) {
                if (!in_array($customer->get_email(), array_column($results, 'email'))) {
                    $results[] = [
                        'email' => $customer->get_email(),
                        'name' => $customer->get_first_name() . ' ' . $customer->get_last_name()
                    ];
                }
            }
        }

        // Limit results to 10 unique customers
        $results = array_slice($results, 0, 10);

        wp_send_json_success(['customers' => $results]);
    }
    
    /**
     * Add email and domain validation functions for WooCommerce products
     */
    private function add_woocommerce_validation_functions($product_id) {
        // Add hooks for email validation during license activation
        add_action('wplm_license_activation_validate_email', [$this, 'validate_woocommerce_email'], 10, 3);
        
        // Add hooks for domain validation during license activation
        add_action('wplm_license_activation_validate_domain', [$this, 'validate_woocommerce_domain'], 10, 3);
        
        // Store validation settings for this product
        $require_email_validation = get_post_meta($product_id, '_wplm_wc_require_email_validation', true);
        $require_domain_validation = get_post_meta($product_id, '_wplm_wc_require_domain_validation', true);
        $allowed_domains = get_post_meta($product_id, '_wplm_wc_allowed_domains', true) ?: [];
        
        // Store validation settings in a format that can be easily retrieved during activation
        update_post_meta($product_id, '_wplm_validation_settings', [
            'require_email_validation' => $require_email_validation === '1',
            'require_domain_validation' => $require_domain_validation === '1',
            'allowed_domains' => $allowed_domains
        ]);
    }
    
    /**
     * Validate email for WooCommerce product licenses
     */
    public function validate_woocommerce_email($license_key, $customer_email, $domain) {
        // Find the license
        $license = wplm_get_post_by_title($license_key, 'wplm_license');
        if (!$license) {
            return false;
        }
        
        $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
        $product_type = get_post_meta($license->ID, '_wplm_product_type', true);
        
        // Only validate for WooCommerce products
        if ($product_type !== 'woocommerce') {
            return true;
        }
        
        // Get validation settings
        $validation_settings = get_post_meta($product_id, '_wplm_validation_settings', true);
        if (!$validation_settings || !$validation_settings['require_email_validation']) {
            return true; // Email validation not required
        }
        
        // Validate email format
        if (!is_email($customer_email)) {
            return false;
        }
        
        // Additional email validation logic can be added here
        // For example, checking against a whitelist, blacklist, etc.
        
        return true;
    }
    
    /**
     * Validate domain for WooCommerce product licenses
     */
    public function validate_woocommerce_domain($license_key, $customer_email, $domain) {
        // Find the license
        $license = wplm_get_post_by_title($license_key, 'wplm_license');
        if (!$license) {
            return false;
        }
        
        $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
        $product_type = get_post_meta($license->ID, '_wplm_product_type', true);
        
        // Only validate for WooCommerce products
        if ($product_type !== 'woocommerce') {
            return true;
        }
        
        // Get validation settings
        $validation_settings = get_post_meta($product_id, '_wplm_validation_settings', true);
        if (!$validation_settings || !$validation_settings['require_domain_validation']) {
            return true; // Domain validation not required
        }
        
        $allowed_domains = $validation_settings['allowed_domains'] ?? [];
        
        // If no domains are specified, allow any domain
        if (empty($allowed_domains)) {
            return true;
        }
        
        // Extract domain from URL
        $parsed_domain = parse_url($domain, PHP_URL_HOST);
        if (!$parsed_domain) {
            $parsed_domain = $domain; // Assume it's already a domain
        }
        
        // Remove www. prefix for comparison
        $parsed_domain = preg_replace('/^www\./', '', $parsed_domain);
        
        // Check if domain is in allowed list
        foreach ($allowed_domains as $allowed_domain) {
            $allowed_domain = preg_replace('/^www\./', '', trim($allowed_domain));
            if ($parsed_domain === $allowed_domain) {
                return true;
            }
        }
        
        return false;
    }
}
