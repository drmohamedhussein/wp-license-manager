<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the admin interface, meta boxes, and custom columns.
 */
class WPLM_Admin_Manager {

    private $products_map = null;

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_post_meta']);
        add_action('wp_ajax_wplm_generate_key', [$this, 'ajax_generate_key']);
        
        // Admin menu handled by Enhanced Admin Manager to prevent duplicates
        // add_action('admin_menu', [$this, 'add_enhanced_admin_menu']);
        // Settings handled by Enhanced Admin Manager to prevent duplicates
        // add_action('admin_init', [$this, 'register_main_settings']);

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

        add_action('wp_ajax_wplm_get_customer_details', [$this, 'ajax_get_customer_details']);
        add_action('wp_ajax_wplm_filter_activity_log', [$this, 'ajax_filter_activity_log']);
        add_action('wp_ajax_wplm_clear_activity_log', [$this, 'ajax_clear_activity_log']);
        add_action('wp_ajax_wplm_filter_subscriptions', [$this, 'ajax_filter_subscriptions']);
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
            $all_products[] = [
                'id' => $prod_id,
                'title' => '[WPLM] ' . $product->post_title,
                'type' => 'wplm',
            ];
        }

        foreach ($woocommerce_products as $product) {
            $all_products[] = [
                'id' => $product->get_id(),
                'title' => '[WC] ' . $product->get_name(),
                'type' => 'woocommerce',
            ];
        }
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="wplm_status"><?php _e('Status', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="wplm_status" id="wplm_status">
                            <option value="active" <?php selected($status, 'active'); ?>><?php _e('Active', 'wp-license-manager'); ?></option>
                            <option value="inactive" <?php selected($status, 'inactive'); ?>><?php _e('Inactive', 'wp-license-manager'); ?></option>
                            <option value="expired" <?php selected($status, 'expired'); ?>><?php _e('Expired', 'wp-license-manager'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_product_id"><?php _e('Product', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="wplm_product_id" id="wplm_product_id">
                            <option value=""><?php _e('-- Select a Product --', 'wp-license-manager'); ?></option>
                            <?php foreach ($all_products as $product) : ?>
                                <option value="<?php echo esc_attr($product['type'] . '|' . $product['id']); ?>" <?php selected($current_product_value, $product['type'] . '|' . $product['id']); ?>>
                                    <?php echo esc_html($product['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Select a product from either WP License Manager or WooCommerce.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_customer_email"><?php _e('Customer Email', 'wp-license-manager'); ?></label></th>
                    <td><input type="email" id="wplm_customer_email" name="wplm_customer_email" value="<?php echo esc_attr($customer_email); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="wplm_duration_type"><?php _e('License Duration', 'wp-license-manager'); ?></label></th>
                    <td>
                        <?php 
                        $duration_type = get_post_meta($post->ID, '_wplm_duration_type', true) ?: 'lifetime';
                        $duration_value = get_post_meta($post->ID, '_wplm_duration_value', true) ?: 1;
                        ?>
                        <select id="wplm_duration_type" name="wplm_duration_type" style="width: 120px;">
                            <option value="lifetime" <?php selected($duration_type, 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                            <option value="days" <?php selected($duration_type, 'days'); ?>><?php _e('Days', 'wp-license-manager'); ?></option>
                            <option value="months" <?php selected($duration_type, 'months'); ?>><?php _e('Months', 'wp-license-manager'); ?></option>
                            <option value="years" <?php selected($duration_type, 'years'); ?>><?php _e('Years', 'wp-license-manager'); ?></option>
                        </select>
                        <input type="number" id="wplm_duration_value" name="wplm_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" style="width: 80px; margin-left: 10px;" />
                        <p class="description"><?php _e('Select duration type and value. Lifetime means no expiry.', 'wp-license-manager'); ?></p>
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
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_activation_limit"><?php _e('Activation Limit', 'wp-license-manager'); ?></label></th>
                    <td><input type="number" id="wplm_activation_limit" name="wplm_activation_limit" value="<?php echo esc_attr($activation_limit ?: 1); ?>" class="small-text"></td>
                </tr>
                <tr>
                    <th><?php _e('Activated Domains', 'wp-license-manager'); ?></th>
                    <td>
                        <?php
                        if (!empty($activated_domains) && is_array($activated_domains)) {
                            echo '<ul>';
                            foreach ($activated_domains as $domain) {
                                echo '<li>' . esc_html($domain) . '</li>';
                            }
                            echo '</ul>';
                        } else {
                            _e('No domains activated yet.', 'wp-license-manager');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Generate License', 'wp-license-manager'); ?></th>
                    <td>
                        <button type="button" id="wplm-generate-key" class="button">
                            <?php _e('Generate Key', 'wp-license-manager'); ?>
                        </button>
                        <span id="wplm-generated-key" style="margin-left:1rem; font-weight:bold;"></span>
                    </td>
                </tr>
            </tbody>
        </table>
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
        <table class="form-table">
            <tbody>
                <tr>
                    <th><?php _e('License Overview', 'wp-license-manager'); ?></th>
                    <td>
                        <p><strong><?php _e('Total Licenses Generated:', 'wp-license-manager'); ?></strong> <?php echo esc_html($total_licenses); ?></p>
                        <p><strong><?php _e('Currently Activated Licenses:', 'wp-license-manager'); ?></strong> <?php echo esc_html($active_licenses_count); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_product_id"><?php _e('Product ID', 'wp-license-manager'); ?></label></th>
                    <td><input type="text" id="wplm_product_id" name="wplm_product_id" value="<?php echo esc_attr($product_id ?: $post->post_name); ?>" class="regular-text">
                    <p class="description"><?php _e('A unique ID for this product (e.g., "my-awesome-plugin"). Used in the API.', 'wp-license-manager'); ?></p></td>
                </tr>
                <?php if (!empty($wc_product_link)): ?>
                <tr>
                    <th><?php _e('Linked WooCommerce Product', 'wp-license-manager'); ?></th>
                    <td><a href="<?php echo esc_url($wc_product_link); ?>" target="_blank"><?php echo esc_html($wc_product->get_name()); ?></a></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><label for="wplm_current_version"><?php _e('Current Version', 'wp-license-manager'); ?></label></th>
                    <td><input type="text" id="wplm_current_version" name="wplm_current_version" value="<?php echo esc_attr($current_version); ?>" class="regular-text">
                    <p class="description"><?php _e('The latest version number for the plugin (e.g., "1.2.3").', 'wp-license-manager'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="wplm_download_url"><?php _e('Download URL', 'wp-license-manager'); ?></label></th>
                    <td><input type="url" id="wplm_download_url" name="wplm_download_url" value="<?php echo esc_attr($download_url); ?>" class="large-text">
                    <p class="description"><?php _e('The URL for downloading the latest version of the plugin.', 'wp-license-manager'); ?></p></td>
                </tr>
            </tbody>
        </table>
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
            </tbody>
        </table>
        <?php
    }

    /**
     * Save meta box data.
     */
    public function save_post_meta($post_id) {
        // Skip if this is an AJAX key generation request to avoid duplicate posts
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'wplm_generate_key') {
            return;
        }
        
        if (!isset($_POST['wplm_nonce']) || (!wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_license_meta') && !wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_product_meta') && !wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_woocommerce_license_meta'))) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_wplm_license', $post_id) && !current_user_can('edit_wplm_product', $post_id) && !current_user_can('edit_posts', $post_id)) {
            return;
        }

        // Determine which nonce was used to verify the request
        $is_license_meta = isset($_POST['wplm_nonce']) && wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_license_meta');
        $is_product_meta = isset($_POST['wplm_nonce']) && wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_product_meta');
        $is_woocommerce_meta = isset($_POST['wplm_nonce']) && wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_woocommerce_license_meta');

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
        if (isset($_POST['wplm_status'])) {
            update_post_meta($post_id, '_wplm_status', sanitize_text_field($_POST['wplm_status']));
        }
        if (isset($_POST['wplm_product_id'])) {
                // Handle product ID based on selected type (WPLM or WooCommerce)
                $selected_product = sanitize_text_field($_POST['wplm_product_id']);
                $parts = explode('|', $selected_product);
                $product_type = $parts[0] ?? '';
                $product_identifier = $parts[1] ?? '';

                update_post_meta($post_id, '_wplm_product_type', $product_type); // Store the type
                update_post_meta($post_id, '_wplm_product_id', $product_identifier); // Store the ID/slug
        }
        if (isset($_POST['wplm_customer_email'])) {
            update_post_meta($post_id, '_wplm_customer_email', sanitize_email($_POST['wplm_customer_email']));
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
            update_post_meta($post_id, '_wplm_activation_limit', intval($_POST['wplm_activation_limit']));
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
                            $new_wplm_product_slug = sanitize_title($new_wplm_product_title);
                            
                            // Ensure slug uniqueness
                            $original_slug = $new_wplm_product_slug;
                            $counter = 1;
                            while (get_page_by_title($new_wplm_product_slug, OBJECT, 'wplm_product')) {
                                $new_wplm_product_slug = $original_slug . '-' . $counter++;
                            }

                            $new_wplm_product_id = wp_insert_post([
                                'post_title'  => $new_wplm_product_title,
                                'post_name'   => $new_wplm_product_slug,
                                'post_type'   => 'wplm_product',
                                'post_status' => 'publish',
                            ], true);

                            if (!is_wp_error($new_wplm_product_id)) {
                                // Set _wplm_product_id on the NEW WPLM product to its own ID
                                update_post_meta($new_wplm_product_id, '_wplm_product_id', $new_wplm_product_id);
                                // Link the newly created WPLM product back to the WooCommerce product by ID
                                update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $new_wplm_product_id);
                                // Also store the WC product ID on the WPLM product
                                update_post_meta($new_wplm_product_id, '_wplm_wc_product_id', $post_id);

                                // Sync version if available immediately after creation
                                if (!empty($current_version)) {
                                    update_post_meta($new_wplm_product_id, '_wplm_current_version', $current_version);
                                }
                            } else {
                                error_log('WPLM Error: Failed to create new WPLM product for WC product ' . $post_id . ': ' . $new_wplm_product_id->get_error_message());
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

        if ($is_new_post) {
            // For new posts, verify the generic create license nonce and capability
            error_log('WPLM Debug: Attempting new license generation. Post ID: ' . $post_id . ', Nonce: ' . $nonce . ', Expected Nonce: ' . wp_create_nonce('wplm_create_license_nonce'));
            if (!wp_verify_nonce($nonce, 'wplm_create_license_nonce')) {
                error_log('WPLM Error: Invalid nonce for new license. Sent: ' . $nonce . ', Expected: ' . wp_create_nonce('wplm_create_license_nonce'));
                wp_send_json_error(['message' => 'Invalid nonce for new license.'], 403);
            }
            if (!current_user_can('create_wplm_licenses')) {
                error_log('WPLM Error: Permission denied to create licenses for user ' . get_current_user_id());
                wp_send_json_error(['message' => 'Permission denied to create licenses.'], 403);
            }
        } else {
            // For existing posts, verify the post-specific nonce and edit capability
            error_log('WPLM Debug: Attempting existing license update. Post ID: ' . $post_id . ', Nonce: ' . $nonce . ', Expected Nonce: ' . wp_create_nonce('wplm_generate_key_' . $post_id));
        if (!wp_verify_nonce($nonce, 'wplm_generate_key_' . $post_id)) {
                error_log('WPLM Error: Invalid nonce for existing license. Sent: ' . $nonce . ', Expected: ' . wp_create_nonce('wplm_generate_key_' . $post_id));
                wp_send_json_error(['message' => 'Invalid nonce for existing license.'], 403);
            }
            if (!current_user_can('edit_wplm_license', $post_id)) {
                error_log('WPLM Error: Permission denied to edit license ' . $post_id . ' for user ' . get_current_user_id());
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
            error_log('WPLM Error: Failed to generate a unique license key after multiple attempts in ajax_generate_key.');
            wp_send_json_error(['message' => __('Failed to generate a unique license key.', 'wp-license-manager')], 500);
        }

        if ($is_new_post) {
            // Create a new post with the generated key as title
            $new_post_args = [
                'post_title'  => $new_license_key,
                'post_type'   => 'wplm_license',
                'post_status' => 'publish',
            ];
            $new_post_id = wp_insert_post($new_post_args, true);

            if (is_wp_error($new_post_id)) {
                error_log('WPLM Error: Failed to create new license post: ' . $new_post_id->get_error_message());
                wp_send_json_error(['message' => 'Failed to create new license post: ' . $new_post_id->get_error_message()], 500);
            }
            $post_id = $new_post_id; // Use the new post ID for response
        } else {
            // Update the existing post title with the generated key
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $new_license_key,
            ]);
        }

        wp_send_json_success(['license_key' => $new_license_key, 'post_id' => $post_id]);
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
                    'type' => 'virtual',
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
            
            // Enqueue dashboard styles
            if ($hook_suffix === 'toplevel_page_wplm-dashboard') {
                wp_enqueue_style('wplm-dashboard-style', plugin_dir_url(__FILE__) . '../assets/css/admin-dashboard.css', ['wplm-admin-style'], WPLM_VERSION);
            }
            
            // Enqueue RTL styles if needed
            if (is_rtl()) {
                wp_enqueue_style('wplm-admin-style-rtl', plugin_dir_url(__FILE__) . '../assets/css/admin-style-rtl.css', ['wplm-admin-style'], WPLM_VERSION);
            }
            
            // Enqueue admin script
            wp_enqueue_script('wplm-admin-script', plugin_dir_url(__FILE__) . '../assets/js/admin-script.js', ['jquery', 'wp-i18n'], WPLM_VERSION, true);
            wp_localize_script('wplm-admin-script', 'wplm_admin_vars', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wplm_admin_nonce'),
                'loading_text' => __('Loading...', 'wp-license-manager'),
                'error_loading_text' => __('Error loading details.', 'wp-license-manager'),
                'active_text' => __('Active', 'wp-license-manager'),
                'inactive_text' => __('Inactive', 'wp-license-manager'),
                'expired_text' => __('Expired', 'wp-license-manager'),
                // Customer Details Modal Specific
                'wc_customer_text' => __('WC Customer', 'wp-license-manager'),
                'total_licenses_text' => __('Total Licenses', 'wp-license-manager'),
                'total_value_text' => __('Total Value', 'wp-license-manager'),
                'registered_on_text' => __('Registered On', 'wp-license-manager'),
                'licenses_text' => __('Licenses', 'wp-license-manager'),
                'license_key_text' => __('License Key', 'wp-license-manager'),
                'product_text' => __('Product', 'wp-license-manager'),
                'status_text' => __('Status', 'wp-license-manager'),
                'expiry_text' => __('Expiry', 'wp-license-manager'),
                'activations_text' => __('Activations', 'wp-license-manager'),
                'no_licenses_text' => __('No licenses found for this customer.', 'wp-license-manager'),
                'generate_key_post_id' => isset($_GET['post']) ? absint($_GET['post']) : 0,
                'create_license_nonce' => wp_create_nonce('wplm_generate_license_key'),
                'post_edit_nonce' => wp_create_nonce('update-post_' . (isset($_GET['post']) ? absint($_GET['post']) : 0)),
                'edit_post_url' => admin_url('post.php'),
                'generating_text' => __('Generating...', 'wp-license-manager'),
                'generate_api_key_nonce' => wp_create_nonce('wplm_generate_api_key'),
                // Bulk Operations Strings
                'confirm_operation' => __('Are you sure you want to perform this bulk operation?', 'wp-license-manager'),
                'processing' => __('Processing...', 'wp-license-manager'),
                'error' => __('An unexpected error occurred.', 'wp-license-manager'),
                'select_licenses' => __('Please select at least one license to perform this action.', 'wp-license-manager'),
                'invalid_extension_value' => __('Please enter a valid positive number for extension.', 'wp-license-manager'),
                'invalid_expiry_date' => __('Please enter a valid expiry date.', 'wp-license-manager'),
                'invalid_activation_limit' => __('Please enter a valid non-negative number for activation limit.', 'wp-license-manager'),
                'select_new_product' => __('Please select a new product.', 'wp-license-manager'),
                'select_new_customer' => __('Please select a new customer.', 'wp-license-manager'),
                // Bulk Update Tab Strings
                'preview_changes' => __('Preview Changes', 'wp-license-manager'),
                'licenses_to_be_updated' => __('Licenses to be updated', 'wp-license-manager'),
                'changes_to_be_applied' => __('The following changes will be applied', 'wp-license-manager'),
                'status_change_to' => __('Status changed to', 'wp-license-manager'),
                'activation_limit_change_to' => __('Activation Limit changed to', 'wp-license-manager'),
                'extend_expiry_by' => __('Extend Expiry by', 'wp-license-manager'),
                'affected_licenses' => __('Affected Licenses', 'wp-license-manager'),
                'no_licenses_match_criteria' => __('No licenses match the specified criteria.', 'wp-license-manager'),
                'error_loading_preview' => __('Error loading preview.', 'wp-license-manager'),
                'confirm_apply_updates' => __('Apply bulk updates to the selected licenses?', 'wp-license-manager'),
                'updating_text' => __('Updating...', 'wp-license-manager'),
                'apply_updates_text' => __('Apply Updates', 'wp-license-manager'),
                'success_text' => __('Success', 'wp-license-manager'),
                'error_text' => __('Error', 'wp-license-manager'),
                'error_applying_updates' => __('Error applying updates.', 'wp-license-manager'),
                // Activity Log Strings
                'filtering_text' => __('Filtering...', 'wp-license-manager'),
                'filter_failed_text' => __('Filter failed', 'wp-license-manager'),
                'filter_error_text' => __('Filter error occurred', 'wp-license-manager'),
                'confirm_clear_log_text' => __('Are you sure you want to clear the activity log? This action cannot be undone.', 'wp-license-manager'),
            ]);

            if (is_rtl()) {
                wp_enqueue_style('wplm-admin-style-rtl', plugin_dir_url(__FILE__) . '../assets/css/admin-style-rtl.css', ['wplm-admin-style'], WPLM_VERSION);
            }
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
                        // Create new WPLM product
                        $product_post_id = wp_insert_post([
                            'post_title'  => $product_title,
                            'post_name'   => sanitize_title($product_title),
                            'post_type'   => 'wplm_product',
                            'post_status' => 'publish',
                        ], true);
                        
                        if (!is_wp_error($product_post_id)) {
                            update_post_meta($product_post_id, '_wplm_product_id', $product_id_slug);
                            update_post_meta($product_post_id, '_wplm_current_version', $current_version);
                            update_post_meta($product_post_id, '_wplm_product_type', 'wplm');
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
                $license_post = get_page_by_title($license_key, OBJECT, 'wplm_license');
                if ($license_post) {
                    $license_post_id = $license_post->ID;
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
                        update_post_meta($product_id_slug, '_wplm_wc_current_version', $current_version);
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
            wp_update_post([
                'ID' => $product_id,
                'post_title' => $plugin_name
            ]);
        } else {
            $product_id = wp_insert_post([
                'post_title' => $plugin_name,
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
        global $wpdb;

        // Total licenses
        $total_licenses = wp_count_posts('wplm_license')->publish;

        // Active, Inactive, Expired, Revoked licenses using a single query
        $license_statuses = $wpdb->get_results(
            "SELECT pm.meta_value as status, COUNT(p.ID) as count
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             AND pm.meta_key = '_wplm_license_status'
             GROUP BY pm.meta_value",
            ARRAY_A
        );

        $active_licenses = 0;
        $inactive_licenses = 0;
        $expired_licenses = 0;
        $revoked_licenses = 0;

        foreach ($license_statuses as $status_data) {
            if ($status_data['status'] === 'active') {
                $active_licenses = $status_data['count'];
            } elseif ($status_data['status'] === 'inactive') {
                $inactive_licenses = $status_data['count'];
            } elseif ($status_data['status'] === 'expired') {
                $expired_licenses = $status_data['count'];
            } elseif ($status_data['status'] === 'revoked') {
                $revoked_licenses = $status_data['count'];
            }
        }

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

        // Unique customers - optimized
        $customer_emails_query = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wplm_customer_email' AND meta_value != ''"
        );
        $total_customers = count($customer_emails_query);

        // New customers this month - optimized
        $current_month_start = date('Y-m-01 00:00:00');
        $new_customers_month_query = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wplm_customer_email' AND pm.meta_value != ''
             AND p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             AND p.post_date >= %s",
            $current_month_start
        ));
        $new_customers_this_month = count($new_customers_month_query);

        return [
            'total_licenses' => $total_licenses,
            'active_licenses' => $active_licenses,
            'inactive_licenses' => $inactive_licenses,
            'expired_licenses' => $expired_licenses,
            'revoked_licenses' => $revoked_licenses,
            'total_products' => $total_products,
            'wc_products' => $wc_products,
            'total_customers' => $total_customers,
            'new_customers_this_month' => $new_customers_this_month,
        ];
    }
    
    /**
     * Render the subscriptions management page
     */
    public function render_subscriptions_page() {
        ?>
        <div class="wrap wplm-subscriptions-wrap">
            <h1 class="wp-heading-inline"><?php _e('Subscription Management', 'wp-license-manager'); ?></h1>
            <div class="wplm-admin-notices"></div> <!-- Unified notification area -->
            
            <?php if (!class_exists('WCS_Subscription')): ?>
                <div class="notice notice-warning">
                    <p><?php _e('WooCommerce Subscriptions plugin is not active. Some features may be limited.', 'wp-license-manager'); ?></p>
                </div>
            <?php else: ?>
                <div class="wplm-subscription-stats-grid">
                    <?php $this->render_subscription_stats(); ?>
                </div>

                <div class="wplm-subscription-filters">
                    <select id="wplm-subscription-status-filter">
                        <option value=""><?php _e('All Statuses', 'wp-license-manager'); ?></option>
                        <option value="active"><?php _e('Active', 'wp-license-manager'); ?></option>
                        <option value="pending"><?php _e('Pending', 'wp-license-manager'); ?></option>
                        <option value="on-hold"><?php _e('On Hold', 'wp-license-manager'); ?></option>
                        <option value="cancelled"><?php _e('Cancelled', 'wp-license-manager'); ?></option>
                        <option value="expired"><?php _e('Expired', 'wp-license-manager'); ?></option>
                    </select>
                    <input type="search" id="wplm-subscription-search" placeholder="<?php _e('Search subscriptions...', 'wp-license-manager'); ?>">
                    <button type="button" class="button" id="wplm-filter-subscriptions"><?php _e('Filter', 'wp-license-manager'); ?></button>
                </div>

                <div class="wplm-subscriptions-table-wrap">
                    <table class="wp-list-table widefat fixed striped wplm-subscriptions-table">
                        <thead>
                            <tr>
                                <th><?php _e('ID', 'wp-license-manager'); ?></th>
                                <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                <th><?php _e('Product', 'wp-license-manager'); ?></th>
                                <th><?php _e('Status', 'wp-license-manager'); ?></th>
                                <th><?php _e('Start Date', 'wp-license-manager'); ?></th>
                                <th><?php _e('Next Payment', 'wp-license-manager'); ?></th>
                                <th><?php _e('Actions', 'wp-license-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="wplm-subscriptions-list">
                            <tr>
                                <td colspan="7" class="wplm-loading-cell">
                                    <div class="wplm-loading-spinner"><span class="wplm-spinner"></span> <?php _e('Loading subscriptions...', 'wp-license-manager'); ?></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="wplm-pagination" id="wplm-subscriptions-pagination"></div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            /* Custom styles for Subscription page */
            .wplm-subscriptions-wrap {
                margin: 20px 0;
            }
            .wplm-subscription-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }
            .wplm-subscription-stats-grid .wplm-stat-item {
                background: #fff;
                border: 1px solid #e1e1e1;
                border-radius: 8px;
                padding: 15px;
                text-align: center;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .wplm-subscription-stats-grid .wplm-stat-item h3 {
                margin: 0 0 5px 0;
                font-size: 24px;
                font-weight: 700;
                color: #1d2327;
            }
            .wplm-subscription-stats-grid .wplm-stat-item p {
                margin: 0;
                font-size: 13px;
                color: #646970;
            }
            .wplm-subscription-filters {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #e1e1e1;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                align-items: center;
            }
            .wplm-subscription-filters select,
            .wplm-subscription-filters input[type="search"] {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                flex-grow: 1;
                max-width: 250px;
            }
            .wplm-subscriptions-table-wrap {
                background: #fff;
                border: 1px solid #e1e1e1;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .wplm-subscriptions-table th,
            .wplm-subscriptions-table td {
                padding: 12px 15px;
                text-align: left;
                border-bottom: 1px solid #f1f1f1;
            }
            .wplm-subscriptions-table th {
                background: #f8f9fa;
                font-weight: 600;
                color: #1d2327;
            }
            .wplm-subscriptions-table tbody tr:last-child td {
                border-bottom: none;
            }
            .wplm-loading-cell {
                text-align: center;
                padding: 50px;
            }
            .wplm-loading-spinner {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                font-size: 16px;
                color: #555;
            }
            .wplm-loading-spinner .wplm-spinner {
                border-color: #f3f3f3; /* Light grey */
                border-top-color: #3498db; /* Blue */
                border-width: 3px;
                width: 20px;
                height: 20px;
                -webkit-animation: spin 2s linear infinite;
                animation: spin 2s linear infinite;
            }
            @-webkit-keyframes spin {
                0% { -webkit-transform: rotate(0deg); }
                100% { -webkit-transform: rotate(360deg); }
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            /* Responsive adjustments */
            @media (max-width: 768px) {
                .wplm-subscription-filters {
                    flex-direction: column;
                    align-items: stretch;
                }
                .wplm-subscription-filters select,
                .wplm-subscription-filters input[type="search"] {
                    max-width: 100%;
                }
                .wplm-subscriptions-table th,
                .wplm-subscriptions-table td {
                    padding: 10px;
                    font-size: 13px;
                }
            }

            /* Dark mode support */
            @media (prefers-color-scheme: dark) {
                .wplm-subscription-stats-grid .wplm-stat-item,
                .wplm-subscription-filters,
                .wplm-subscriptions-table-wrap {
                    background: #1e1e1e;
                    border-color: #3c3c3c;
                    color: #e1e1e1;
                }
                .wplm-subscription-stats-grid .wplm-stat-item h3,
                .wplm-subscriptions-table th {
                    color: #e1e1e1;
                }
                .wplm-subscriptions-table td {
                    color: #ccc;
                }
                .wplm-subscription-filters select,
                .wplm-subscription-filters input[type="search"] {
                    background: #2c2c2c;
                    border-color: #3c3c3c;
                    color: #e1e1e1;
                }
            }
        </style>
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
     * Helper to get subscription data with filters.
     *
     * @param array $filters Filters for subscriptions (status, search).
     * @return array Formatted subscription data.
     */
    private function get_subscription_data($filters = []) {
        if (!class_exists('WCS_Subscription')) {
            return [];
        }

        $args = [
            'subscriptions_per_page' => -1, // Get all to filter manually if needed
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [],
        ];

        if (!empty($filters['status'])) {
            $args['subscription_status'] = sanitize_text_field($filters['status']);
        }

        $subscriptions = wcs_get_subscriptions($args);
        $formatted_subscriptions = [];

        foreach ($subscriptions as $subscription) {
            // Basic search filtering
            if (!empty($filters['search'])) {
                $search_term = strtolower(sanitize_text_field($filters['search']));
                $customer = new WC_Customer($subscription->get_customer_id());
                $product = $subscription->get_product();

                $match = false;
                if (stripos($subscription->get_id(), $search_term) !== false) $match = true;
                if (stripos($customer->get_billing_email(), $search_term) !== false) $match = true;
                if (stripos($customer->get_display_name(), $search_term) !== false) $match = true;
                if ($product && stripos($product->get_name(), $search_term) !== false) $match = true;

                if (!$match) continue;
            }

            $customer = new WC_Customer($subscription->get_customer_id());
            $product = $subscription->get_product();
            $product_name = $product ? $product->get_name() : __('N/A', 'wp-license-manager');

            $formatted_subscriptions[] = [
                'id' => $subscription->get_id(),
                'customer_name' => $customer->get_display_name(),
                'customer_edit_link' => get_edit_post_link($customer->get_id()), // Assuming customer is a WP user
                'product_name' => $product_name,
                'status_slug' => $subscription->get_status(),
                'status_text' => wc_get_order_status_name($subscription->get_status()),
                'start_date' => wc_format_datetime($subscription->get_date('start')), // Format date
                'next_payment_date' => $subscription->get_date('next_payment') ? wc_format_datetime($subscription->get_date('next_payment')) : __('N/A', 'wp-license-manager'),
                'can_cancel' => $subscription->can_be_cancelled(),
                'can_reactivate' => $subscription->can_be_reactivated(),
                // Add more data as needed for the modal
            ];
        }

        return $formatted_subscriptions;
    }

    /**
     * AJAX handler for filtering subscriptions.
     */
    public function ajax_filter_subscriptions() {
        check_ajax_referer('wplm_filter_subscriptions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $filters = isset($_POST['status']) ? ['status' => sanitize_text_field($_POST['status'])] : [];
        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        if (!empty($search_term)) {
            $filters['search'] = $search_term;
        }

        $subscriptions = $this->get_subscription_data($filters);

        wp_send_json_success(['subscriptions' => $subscriptions]);
    }
}
