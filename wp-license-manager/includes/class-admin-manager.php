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
        add_action('admin_init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu_pages']);
        add_action('add_meta_boxes', [$this, 'add_license_product_meta_boxes']);
        add_action('save_post', [$this, 'save_license_meta']);
        add_action('save_post', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wplm_generate_key', [$this, 'ajax_generate_key']);
        add_action('wp_ajax_wplm_search_products', [$this, 'ajax_search_products']); // New AJAX for product search
        add_action('wp_ajax_wplm_scan_orders', [$this, 'ajax_scan_orders']); // AJAX for scanning WC orders
        add_action('wp_ajax_wplm_scan_orders_new', [$this, 'ajax_scan_orders_new']); // New AJAX for scanning WC orders
        add_action('wp_ajax_wplm_create_customer_profile', [$this, 'ajax_create_customer_profile']); // AJAX for creating new customer profiles
        add_action('wp_ajax_wplm_search_woocommerce_products', [$this, 'ajax_search_woocommerce_products']); // AJAX for searching WooCommerce products
        add_action('wp_ajax_wplm_filter_activity_log', [$this, 'ajax_filter_activity_log']); // AJAX for filtering activity log
        add_action('wp_ajax_wplm_clear_activity_log', [$this, 'ajax_clear_activity_log']); // AJAX for clearing activity log
        add_action('wp_ajax_wplm_get_activity_logs', [$this, 'ajax_get_activity_logs']); // AJAX for getting activity logs for datatable
    }

    public function init() {
        // No-op for now
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu_pages() {
        add_menu_page(
            __('WP License Manager', 'wp-license-manager'),
            __('License Manager', 'wp-license-manager'),
            'manage_options',
            'wplm-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-admin-network',
            20
        );
        
        add_submenu_page(
            'wplm-dashboard',
            __('Licenses', 'wp-license-manager'),
            __('Licenses', 'wp-license-manager'),
            'manage_wplm_licenses',
            'edit.php?post_type=wplm_license'
        );

        add_submenu_page(
            'wplm-dashboard',
            __('Products', 'wp-license-manager'),
            __('Products', 'wp-license-manager'),
            'manage_wplm_products',
            'edit.php?post_type=wplm_product'
        );
        
        if (class_exists('WooCommerce')) {
            add_submenu_page(
                'wplm-dashboard',
                __('WooCommerce Sync', 'wp-license-manager'),
                __('WC Sync', 'wp-license-manager'),
                'manage_wplm_licenses',
                'wplm-woocommerce-sync',
                [$this, 'render_woocommerce_sync_page']
            );
        }
    }

    /**
     * Add license and product meta boxes.
     */
    public function add_license_product_meta_boxes() {
        add_meta_box(
            'wplm_license_meta',
            __('License Details', 'wp-license-manager'),
            [$this, 'render_license_meta_box'],
            'wplm_license',
            'normal',
            'high'
        );

        add_meta_box(
            'wplm_product_meta',
            __('Product Details', 'wp-license-manager'),
            [$this, 'render_product_meta_box'],
            'wplm_product',
            'normal',
            'high'
        );
        
        if (class_exists('WooCommerce')) {
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
        if ($post->ID === 0) {
            wp_nonce_field('wplm_create_license_nonce', 'wplm_generate_key_nonce');
        }
        $status = get_post_meta($post->ID, '_wplm_status', true);
        $product_id = get_post_meta($post->ID, '_wplm_product_id', true);
        $customer_email = get_post_meta($post->ID, '_wplm_customer_email', true);
        $expiry_date = get_post_meta($post->ID, '_wplm_expiry_date', true);
        $activation_limit = get_post_meta($post->ID, '_wplm_activation_limit', true);
        $activated_domains = get_post_meta($post->ID, '_wplm_activated_domains', true);
        $product_type = get_post_meta($post->ID, '_wplm_product_type', true);
        $current_product_value = !empty($product_id) && !empty($product_type) ? $product_type . '|' . $product_id : '';
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
                    <th><label for="wplm_product_id_select2"><?php _e('Product', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="wplm_product_id" id="wplm_product_id_select2" class="wplm-select2-product-search" data-current-value="<?php echo esc_attr($current_product_value); ?>">
                            <?php
                            if (!empty($current_product_value)) {
                                $parts = explode('|', $current_product_value);
                                $p_type = $parts[0] ?? '';
                                $p_id = $parts[1] ?? '';
                                $product_title = '';
                                if ($p_type === 'wplm') {
                                    $wplm_product = get_post($p_id);
                                    if ($wplm_product) {
                                        $product_title = '[WPLM] ' . $wplm_product->post_title;
                                    }
                                } elseif ($p_type === 'woocommerce' && function_exists('wc_get_product')) {
                                    $wc_product = wc_get_product($p_id);
                                    if ($wc_product) {
                                        $product_title = '[WC] ' . $wc_product->get_name();
                                    }
                                }
                                if (!empty($product_title)) {
                                    echo '<option value="' . esc_attr($current_product_value) . '" selected="selected">' . esc_html($product_title) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <p class="description"><?php _e('Search for a product from either WP License Manager or WooCommerce.', 'wp-license-manager'); ?></p>
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
                        $duration_type = get_post_meta($post->ID, '_wplm_duration_type', true);
                        $duration_value = get_post_meta($post->ID, '_wplm_duration_value', true);
                        ?>
                        <select name="wplm_duration_type" id="wplm_duration_type">
                            <option value="lifetime" <?php selected($duration_type, 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                            <option value="days" <?php selected($duration_type, 'days'); ?>><?php _e('Days', 'wp-license-manager'); ?></option>
                            <option value="months" <?php selected($duration_type, 'months'); ?>><?php _e('Months', 'wp-license-manager'); ?></option>
                            <option value="years" <?php selected($duration_type, 'years'); ?>><?php _e('Years', 'wp-license-manager'); ?></option>
                        </select>
                        <input type="number" id="wplm_duration_value" name="wplm_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" style="<?php echo ($duration_type === 'lifetime' ? 'display:none;' : ''); ?>" class="small-text" placeholder="<?php _e('e.g., 30', 'wp-license-manager'); ?>">
                        <p class="description"><?php _e('Set the duration of the license. Leave blank or set to 0 for lifetime.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_expiry_date"><?php _e('Expiry Date', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="wplm_expiry_date" name="wplm_expiry_date" value="<?php echo esc_attr($expiry_date); ?>" class="wplm-datepicker regular-text" autocomplete="off" />
                        <p class="description"><?php _e('Format: YYYY-MM-DD. Leave empty for lifetime.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_activation_limit"><?php _e('Activation Limit', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="number" id="wplm_activation_limit" name="wplm_activation_limit" value="<?php echo esc_attr($activation_limit); ?>" min="0" class="small-text" />
                        <p class="description"><?php _e('Number of times this license can be activated. Set to 0 for unlimited.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Activated Domains', 'wp-license-manager'); ?></th>
                    <td>
                        <?php if (!empty($activated_domains) && is_array($activated_domains)) : ?>
                            <ul class="wplm-activated-domains-list">
                                <?php foreach ($activated_domains as $domain) : ?>
                                    <li><?php echo esc_html($domain); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p><?php _e('No domains activated yet.', 'wp-license-manager'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_current_version"><?php _e('Current Version (for client update checks)', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="wplm_current_version" name="wplm_current_version" value="<?php echo esc_attr(get_post_meta($post->ID, '_wplm_current_version', true)); ?>" class="regular-text" placeholder="1.0.0" />
                        <p class="description"><?php _e('This version string will be sent to the client during update checks. e.g., 1.0.0', 'wp-license-manager'); ?></p>
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
        $product_type = get_post_meta($post->ID, '_wplm_product_type', true);
        $wc_product_id = get_post_meta($post->ID, '_wplm_wc_product_id', true);

        // Get total licenses created for this product
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

        // Get total activations for this product
        $total_activations_count = 0;
        $product_licenses_for_activations = get_posts([
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
                ]
            ]
        ]);

        foreach ($product_licenses_for_activations as $license_post) {
            $activated_domains_count = count(get_post_meta($license_post->ID, '_wplm_activated_domains', true) ?: []);
            $total_activations_count += $activated_domains_count;
        }
        
        // WooCommerce product link
        $wc_product_link = '';
        $wc_product = null;
        if (!empty($wc_product_id) && function_exists('wc_get_product')) {
            $wc_product = wc_get_product($wc_product_id);
            if ($wc_product) {
                $wc_product_link = get_edit_post_link($wc_product_id);
            }
        }
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="wplm_product_id"><?php _e('Product ID (Slug)', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="wplm_product_id" name="wplm_product_id" value="<?php echo esc_attr($product_id); ?>" class="regular-text" <?php echo ($post->post_status == 'publish' && !empty($product_id)) ? 'readonly' : ''; ?> />
                        <p class="description"><?php _e('Unique ID for this product. Cannot be changed after publishing. Use alphanumeric characters and hyphens.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_current_version"><?php _e('Current Version', 'wp-license-manager'); ?></label></th>
                    <td><input type="text" id="wplm_current_version" name="wplm_current_version" value="<?php echo esc_attr($current_version); ?>" class="regular-text" placeholder="1.0.0" /></td>
                </tr>
                <tr>
                    <th><label for="wplm_download_url"><?php _e('Download URL', 'wp-license-manager'); ?></label></th>
                    <td><input type="url" id="wplm_download_url" name="wplm_download_url" value="<?php echo esc_url($download_url); ?>" class="regular-text" placeholder="https://example.com/your-product.zip" /></td>
                </tr>
                <tr>
                    <th><label for="wplm_product_type"><?php _e('Product Type', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="wplm_product_type" id="wplm_product_type">
                            <option value="wplm" <?php selected($product_type, 'wplm'); ?>><?php _e('WPLM Native', 'wp-license-manager'); ?></option>
                            <option value="woocommerce" <?php selected($product_type, 'woocommerce'); ?> <?php echo !class_exists('WooCommerce') ? 'disabled' : ''; ?>><?php _e('WooCommerce Product', 'wp-license-manager'); ?></option>
                        </select>
                        <?php if (!class_exists('WooCommerce')) : ?>
                            <p class="description"><?php _e('Install and activate WooCommerce to link products.', 'wp-license-manager'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="wplm-wc-product-field" style="<?php echo ($product_type === 'woocommerce') ? '' : 'display:none;'; ?>">
                    <th><label for="wplm_wc_product_id"><?php _e('Link WooCommerce Product', 'wp-license-manager'); ?></label></th>
                    <td>
                        <?php if (!empty($wc_product_link) && $wc_product) : ?>
                            <a href="<?php echo esc_url($wc_product_link); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html($wc_product->get_name()); ?> (#<?php echo esc_html($wc_product_id); ?>)
                            </a>
                            <p class="description"><?php _e('WooCommerce product is linked. To change, edit the WooCommerce product directly.', 'wp-license-manager'); ?></p>
                        <?php else : ?>
                            <select name="wplm_wc_product_id" id="wplm_wc_product_id" class="wplm-select2-wc-product-search" style="width: 100%;" data-placeholder="<?php _e('Search for a WooCommerce product', 'wp-license-manager'); ?>">
                                <option value=""></option>
                                <?php
                                if (class_exists('WooCommerce') && !empty($wc_product_id)) {
                                    $selected_wc_product = wc_get_product($wc_product_id);
                                    if ($selected_wc_product) {
                                        echo '<option value="' . esc_attr($wc_product_id) . '" selected="selected">' . esc_html($selected_wc_product->get_name()) . ' (#'.esc_html($wc_product_id).')</option>';
                                    }
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Link this WPLM product to an existing WooCommerce product. Ensure the WooCommerce product is "Virtual".', 'wp-license-manager'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Total Licenses Generated', 'wp-license-manager'); ?></th>
                    <td><?php echo $total_licenses; ?></td>
                </tr>
                <tr>
                    <th><?php _e('Total Activations', 'wp-license-manager'); ?></th>
                    <td><?php echo $total_activations_count; ?></td>
                </tr>
                <?php if (!empty($wc_product_link) && $wc_product): ?>
                <tr>
                    <th><?php _e('Linked WooCommerce Product', 'wp-license-manager'); ?></th>
                    <td><a href="<?php echo esc_url($wc_product_link); ?>" target="_blank"><?php echo esc_html($wc_product->get_name()); ?></a></td>
                </tr>
                <?php else: // If no WooCommerce product is linked, display a message. ?>
                <tr>
                    <th><?php _e('Linked WooCommerce Product', 'wp-license-manager'); ?></th>
                    <td><?php _e('Not linked to any WooCommerce product.', 'wp-license-manager'); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><label for="wplm_notes"><?php _e('Notes', 'wp-license-manager'); ?></label></th>
                    <td><textarea id="wplm_notes" name="wplm_notes" rows="5" class="large-text"><?php echo esc_textarea(get_post_meta($post->ID, '_wplm_notes', true)); ?></textarea></td>
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
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $post_id,
                    'product_updated',
                    sprintf(__('Product %s updated.', 'wp-license-manager'), get_the_title($post_id)),
                    [
                        'product_id' => $post_id,
                        'product_title' => get_the_title($post_id),
                    ]
                );
            }
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
        check_ajax_referer('wplm_create_license_nonce', 'nonce');

        if (!current_user_can('edit_wplm_licenses')) {
            wp_send_json_error(['message' => __('You do not have sufficient permissions to generate license keys.', 'wp-license-manager')]);
        }

        $post_id = intval($_POST['post_id']);
        if ($post_id > 0) {
            check_ajax_referer('wplm_generate_key_' . $post_id, 'nonce');
        }

        $license_key = self::generate_standard_license_key();

        if (empty($license_key)) {
            wp_send_json_error(['message' => __('An error occurred while generating the license key.', 'wp-license-manager')]);
        }

        if ($post_id === 0) {
            // Create a new license post with the generated key as the title
            $new_post_args = [
                'post_title' => $license_key,
                'post_status' => 'auto-draft',
                'post_type' => 'wplm_license',
            ];
            $new_post_id = wp_insert_post($new_post_args);

            if (!is_wp_error($new_post_id)) {
                // Save other meta if available from the form for initial draft
                if (isset($_POST['product_id'])) update_post_meta($new_post_id, '_wplm_product_id', sanitize_text_field($_POST['product_id']));
                if (isset($_POST['customer_email'])) update_post_meta($new_post_id, '_wplm_customer_email', sanitize_email($_POST['customer_email']));
                if (isset($_POST['expiry_date'])) update_post_meta($new_post_id, '_wplm_expiry_date', sanitize_text_field($_POST['expiry_date']));
                if (isset($_POST['activation_limit'])) update_post_meta($new_post_id, '_wplm_activation_limit', intval($_POST['activation_limit']));

                if (class_exists('WPLM_Activity_Logger')) {
                    WPLM_Activity_Logger::log(
                        $new_post_id,
                        'license_created',
                        sprintf(__('New license key %s generated.', 'wp-license-manager'), $license_key),
                        [
                            'license_key' => $license_key,
                            'product_id' => $_POST['product_id'] ?? null,
                            'customer_email' => $_POST['customer_email'] ?? null,
                        ]
                    );
                }
                wp_send_json_success([
                    'key' => $license_key,
                    'redirect_url' => admin_url('post.php?post=' . $new_post_id . '&action=edit')
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to create new license draft.', 'wp-license-manager') . ' ' . $new_post_id->get_error_message()]);
            }
        } else {
            // For existing posts, just update the title
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $license_key,
            ]);

            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $post_id,
                    'license_updated',
                    sprintf(__('License key updated to %s.', 'wp-license-manager'), $license_key),
                    [
                        'license_key' => $license_key,
                    ]
                );
            }
            wp_send_json_success(['key' => $license_key]);
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
                    'wplm_create_license_nonce' => wp_create_nonce('wplm_create_license_nonce'), // For new license creation (generic nonce)
                    'add_customer_nonce' => wp_create_nonce('wplm_create_customer_nonce'), // For adding new customers
                    'search_users_nonce' => wp_create_nonce('wplm_search_users_nonce'), // For searching WordPress users
                    'admin_url' => admin_url(), // Pass admin URL for redirection
                    // Customer modal strings
                    'add_new_customer' => __('Add New Customer', 'wp-license-manager'),
                    'link_wordpress_user' => __('Link to WordPress User', 'wp-license-manager'),
                    'search_wordpress_user' => __('Search for a WordPress user', 'wp-license-manager'),
                    'link_wordpress_user_desc' => __('Optionally link this customer to an existing WordPress user.', 'wp-license-manager'),
                    'first_name' => __('First Name', 'wp-license-manager'),
                    'last_name' => __('Last Name', 'wp-license-manager'),
                    'email' => __('Email', 'wp-license-manager'),
                    'company' => __('Company', 'wp-license-manager'),
                    'phone' => __('Phone', 'wp-license-manager'),
                    'address' => __('Address', 'wp-license-manager'),
                    'city' => __('City', 'wp-license-manager'),
                    'state' => __('State/Province', 'wp-license-manager'),
                    'zip_postal_code' => __('Zip/Postal Code', 'wp-license-manager'),
                    'country' => __('Country', 'wp-license-manager'),
                    'social_media_links' => __('Social Media Links', 'wp-license-manager'),
                    'social_media_desc' => __('Enter one link per line.', 'wp-license-manager'),
                    'add_customer' => __('Add Customer', 'wp-license-manager'),
                    'cancel' => __('Cancel', 'wp-license-manager'),
                    'loading' => __('Loading...', 'wp-license-manager'),
                    'product_search_placeholder' => __('Search for a WPLM product', 'wp-license-manager'), // Placeholder for WPLM product search
                    'wc_product_search_placeholder' => __('Search for a WooCommerce product', 'wp-license-manager'), // Placeholder for WooCommerce product search
                    'wc_product_search_nonce' => wp_create_nonce('wplm_search_woocommerce_products_nonce'), // Nonce for WooCommerce product search
                ]
            );
        }
    }

    /**
     * Render the main settings page.
     */
    public function render_main_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-license-manager'));
        }
        ?>
        <div class="wrap">
            <h1><?php _e('WP License Manager Settings', 'wp-license-manager'); ?></h1>
            <div id="wplm-api-key-notice" class="notice" style="display:none;"></div>
            <?php settings_errors(); ?>

            <?php
            $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
            ?>
            <h2 class="nav-tab-wrapper">
                <a href="?page=wplm-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=export_import" class="nav-tab <?php echo $active_tab == 'export_import' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Export/Import', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=plugin_manager" class="nav-tab <?php echo $active_tab == 'plugin_manager' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Plugin Manager', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=api_key" class="nav-tab <?php echo $active_tab == 'api_key' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Key', 'wp-license-manager'); ?>
                </a>
                <a href="?page=wplm-settings&tab=license" class="nav-tab <?php echo $active_tab == 'license' ? 'nav-tab-active' : ''; ?>">
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
        ?>
        <div class="wplm-dashboard-wrap">
            <h1><?php _e('WP License Manager Dashboard', 'wp-license-manager'); ?></h1>
            <p><?php _e('Welcome to the WP License Manager dashboard. Here you can find an overview of your licenses, products, and recent activities.', 'wp-license-manager'); ?></p>

            <div class="wplm-dashboard-grid">
                <div class="wplm-dashboard-card">
                    <h3><?php _e('Total Licenses', 'wp-license-manager'); ?></h3>
                    <p class="wplm-dashboard-stat"><?php echo WPLM_License_Manager::get_total_licenses_count(); ?></p>
                </div>
                <div class="wplm-dashboard-card">
                    <h3><?php _e('Active Licenses', 'wp-license-manager'); ?></h3>
                    <p class="wplm-dashboard-stat"><?php echo WPLM_License_Manager::get_active_licenses_count(); ?></p>
                </div>
                <div class="wplm-dashboard-card">
                    <h3><?php _e('Total Products', 'wp-license-manager'); ?></h3>
                    <p class="wplm-dashboard-stat"><?php echo WPLM_Product_Manager::get_total_products_count(); ?></p>
                </div>
                <div class="wplm-dashboard-card">
                    <h3><?php _e('Total Customers', 'wp-license-manager'); ?></h3>
                    <p class="wplm-dashboard-stat"><?php echo WPLM_Customer_Management_System::get_total_customers_count(); ?></p>
                </div>
            </div>

            <div class="wplm-dashboard-activity-log">
                <h2><?php _e('Recent Activity', 'wp-license-manager'); ?></h2>
                <?php $recent_activities = WPLM_Activity_Logger::get_global_log(5); ?>
                <?php if (!empty($recent_activities)) : ?>
                    <ul>
                        <?php foreach ($recent_activities as $activity) : ?>
                            <li>
                                <strong><?php echo esc_html($activity['timestamp']); ?>:</strong>
                                <?php echo esc_html($activity['message']); ?>
                                <?php if (!empty($activity['object_type']) && !empty($activity['object_id'])) : ?>
                                    (<?php echo esc_html(ucfirst(str_replace('wplm_', '', $activity['object_type']))); ?> ID: <?php echo esc_html($activity['object_id']); ?>)
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p><?php _e('No recent activity.', 'wp-license-manager'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the WooCommerce Sync page.
     */
    public function render_woocommerce_sync_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-license-manager'));
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Generate Licenses from WooCommerce Orders', 'wp-license-manager'); ?></h1>
            <p><?php _e('Scan your WooCommerce orders to automatically generate licenses for purchased licensed products.', 'wp-license-manager'); ?></p>

            <div id="wplm-wc-scan-orders-status">
                <p><strong><?php _e('Status:', 'wp-license-manager'); ?></strong> <span id="wplm-wc-scan-message"><?php _e('Ready to scan.', 'wp-license-manager'); ?></span></p>
                <p><?php _e('Last scanned:', 'wp-license-manager'); ?> <span id="wplm-wc-last-scan-time"><?php echo get_option('wplm_last_wc_scan_time', __('Never', 'wp-license-manager')); ?></span></p>
            </div>

            <button type="button" class="button button-primary" id="wplm-scan-wc-orders-button">
                <?php _e('Scan Orders', 'wp-license-manager'); ?>
            </button>

            <div id="wplm-wc-scan-results" style="margin-top: 20px;">
                <!-- Results will be displayed here -->
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#wplm-scan-wc-orders-button').on('click', function() {
                    var button = $(this);
                    var messageSpan = $('#wplm-wc-scan-message');
                    var resultsDiv = $('#wplm-wc-scan-results');

                    button.prop('disabled', true).text('<?php _e('Scanning...', 'wp-license-manager'); ?>');
                    messageSpan.text('<?php _e('Scanning in progress...', 'wp-license-manager'); ?>');
                    resultsDiv.empty(); // Clear previous results

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wplm_scan_orders',
                            _wpnonce: '<?php echo wp_create_nonce('wplm_scan_orders_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                messageSpan.text('<?php _e('Scan complete!', 'wp-license-manager'); ?>');
                                resultsDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                                $('#wplm-wc-last-scan-time').text(response.data.last_scan_time);
                            } else {
                                messageSpan.text('<?php _e('Scan failed!', 'wp-license-manager'); ?>');
                                resultsDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            messageSpan.text('<?php _e('An unknown error occurred.', 'wp-license-manager'); ?>');
                            resultsDiv.html('<div class="notice notice-error"><p><?php _e('An unknown error occurred during the scan.', 'wp-license-manager'); ?></p></div>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('<?php _e('Scan Orders', 'wp-license-manager'); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
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
        
        $activities = WPLM_Activity_Logger::get_global_log();
        
        if (empty($activities)) {
            echo '<p>' . __('No activity logged yet.', 'wp-license-manager') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Date/Time', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Type', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Description', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Object Type', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Object ID', 'wp-license-manager') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($activities as $activity) {
            echo '<tr>';
            echo '<td>' . esc_html(date('Y-m-d H:i:s', strtotime($activity['timestamp']))) . '</td>';
            echo '<td><span class="wplm-activity-type">' . esc_html($activity['event_type']) . '</span></td>';
            echo '<td>' . esc_html($activity['description']) . '</td>';
            echo '<td>' . esc_html(ucfirst(str_replace('wplm_', '', $activity['object_type']))) . '</td>';
            echo '<td>' . esc_html($activity['object_id']) . '</td>';
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

    public function ajax_search_products() {
        check_ajax_referer('wplm_search_products_nonce', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $search_term = sanitize_text_field($_GET['q'] ?? '');
        $results = [];

        // Search WPLM Native Products
        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => 20,
            'post_status' => 'publish',
            's' => $search_term, // Search by title
            'meta_query' => [
                [
                    'key' => '_wplm_product_id',
                    'value' => $search_term,
                    'compare' => 'LIKE',
                ],
                'relation' => 'OR',
            ],
        ]);

        foreach ($wplm_products as $product) {
            $prod_id_meta = get_post_meta($product->ID, '_wplm_product_id', true);
            $results[] = [
                'id' => 'wplm|' . $prod_id_meta,
                'text' => '[WPLM] ' . $product->post_title . ' (' . $prod_id_meta . ')',
            ];
        }

        // Search WooCommerce Products (if active)
        if (function_exists('wc_get_products')) {
            $wc_products = wc_get_products([
                'limit' => 20,
                'status' => 'publish',
                'type' => 'virtual',
                's' => $search_term,
            ]);

            foreach ($wc_products as $product) {
                $results[] = [
                    'id' => 'woocommerce|' . $product->get_id(),
                    'text' => '[WC] ' . $product->get_name() . ' (ID: ' . $product->get_id() . ')',
                ];
            }
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX handler to scan WooCommerce orders and generate licenses.
     */
    public function ajax_scan_orders_new() {
        check_ajax_referer('wplm_scan_orders_nonce', '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have sufficient permissions to scan WooCommerce orders.', 'wp-license-manager')]);
        }

        $orders_scanned = 0;
        $licenses_generated = 0;
        $errors = [];

        // Get all completed WooCommerce orders
        $orders = wc_get_orders([
            'status' => 'completed',
            'limit' => -1,
        ]);

        if (empty($orders)) {
            wp_send_json_success([
                'message' => __('No completed orders found to scan.', 'wp-license-manager'),
                'orders_scanned' => 0,
                'licenses_generated' => 0,
                'last_scan_time' => current_time('mysql'),
            ]);
        }

        foreach ($orders as $order) {
            $orders_scanned++;
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $wc_product = wc_get_product($product_id);

                if (!$wc_product) {
                    continue;
                }

                $is_licensed = get_post_meta($product_id, '_wplm_wc_is_licensed_product', true);
                $linked_wplm_product_id = get_post_meta($product_id, '_wplm_wc_linked_wplm_product_id', true);

                if ($is_licensed === 'yes' && !empty($linked_wplm_product_id)) {
                    // Check if a license has already been generated for this order item
                    $license_for_order_item = get_posts([
                        'post_type' => 'wplm_license',
                        'meta_query' => [
                            'relation' => 'AND',
                            [
                                'key' => '_wplm_source_order_id',
                                'value' => $order->get_id(),
                                'compare' => '='
                            ],
                            [
                                'key' => '_wplm_source_order_item_id',
                                'value' => $item_id,
                                'compare' => '='
                            ],
                            [
                                'key' => '_wplm_product_id',
                                'value' => $linked_wplm_product_id,
                                'compare' => '='
                            ]
                        ],
                        'posts_per_page' => 1,
                        'fields' => 'ids'
                    ]);

                    if (!empty($license_for_order_item)) {
                        // License already generated for this item, skip
                        continue;
                    }

                    // Get license duration and activation limit from product settings or variation
                    $duration_type = get_post_meta($linked_wplm_product_id, '_wplm_default_duration_type', true) ?: 'lifetime';
                    $duration_value = get_post_meta($linked_wplm_product_id, '_wplm_default_duration_value', true) ?: 1;
                    $activation_limit = get_post_meta($linked_wplm_product_id, '_wplm_default_activation_limit', true) ?: 1;

                    // If it's a variable product, check variation-specific settings
                    if ($wc_product->is_type('variable') && $item->get_variation_id()) {
                        $variation_id = $item->get_variation_id();
                        $variation_duration_type = get_post_meta($variation_id, '_wplm_computed_duration_type', true);
                        $variation_duration_value = get_post_meta($variation_id, '_wplm_computed_duration_value', true);
                        $variation_activation_limit = get_post_meta($variation_id, '_wplm_computed_activation_limit', true);

                        if (!empty($variation_duration_type)) $duration_type = $variation_duration_type;
                        if (!empty($variation_duration_value)) $duration_value = $variation_duration_value;
                        if (!empty($variation_activation_limit)) $activation_limit = $variation_activation_limit;
                    }

                    // Generate a new license key
                    $license_key = self::generate_standard_license_key();
                    $customer_email = $order->get_billing_email();

                    $new_license_id = wp_insert_post([
                        'post_title' => $license_key,
                        'post_status' => 'publish',
                        'post_type' => 'wplm_license',
                    ], true);

                    if (!is_wp_error($new_license_id)) {
                        update_post_meta($new_license_id, '_wplm_status', 'active');
                        update_post_meta($new_license_id, '_wplm_product_id', $linked_wplm_product_id);
                        update_post_meta($new_license_id, '_wplm_product_type', 'wplm'); // Always WPLM type for generated licenses
                        update_post_meta($new_license_id, '_wplm_customer_email', $customer_email);
                        update_post_meta($new_license_id, '_wplm_activation_limit', $activation_limit);
                        update_post_meta($new_license_id, '_wplm_source_order_id', $order->get_id());
                        update_post_meta($new_license_id, '_wplm_source_order_item_id', $item_id);
                        update_post_meta($new_license_id, '_wplm_duration_type', $duration_type);
                        update_post_meta($new_license_id, '_wplm_duration_value', $duration_value);

                        if ($duration_type !== 'lifetime') {
                            $expiry_date = $this->calculate_expiry_date($duration_type, $duration_value);
                            update_post_meta($new_license_id, '_wplm_expiry_date', $expiry_date);
                        }

                        $licenses_generated++;

                        // Log the license generation
                        if (class_exists('WPLM_Activity_Logger')) {
                            WPLM_Activity_Logger::log(
                                $new_license_id,
                                'license_generated_from_wc_order',
                                sprintf('License key %s generated from WooCommerce Order #%d for product %s', $license_key, $order->get_id(), $wc_product->get_name()),
                                [
                                    'license_key' => $license_key,
                                    'wc_order_id' => $order->get_id(),
                                    'wc_product_id' => $product_id,
                                    'wplm_product_id' => $linked_wplm_product_id
                                ]
                            );
                        }
                    } else {
                        $errors[] = sprintf(__('Failed to generate license for Order #%d, Item %s: %s', 'wp-license-manager'), $order->get_id(), $item->get_name(), $new_license_id->get_error_message());
                        error_log('WPLM Scan Orders Error: ' . end($errors));
                    }
                }
            }
        }

        update_option('wplm_last_wc_scan_time', current_time('mysql'));

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => sprintf(__('Scan completed with %d errors. Generated %d licenses.', 'wp-license-manager'), count($errors), $licenses_generated) . '<br>' . implode('<br>', $errors),
                'orders_scanned' => $orders_scanned,
                'licenses_generated' => $licenses_generated,
                'last_scan_time' => current_time('mysql')
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf(__('Scan complete! Scanned %d orders and generated %d new licenses.', 'wp-license-manager'), $orders_scanned, $licenses_generated),
                'orders_scanned' => $orders_scanned,
                'licenses_generated' => $licenses_generated,
                'last_scan_time' => current_time('mysql')
            ]);
        }
    }

    /**
     * AJAX handler for creating a new customer profile.
     */
    public function ajax_create_customer_profile() {
        check_ajax_referer('wplm_create_customer_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wp-license-manager')]);
        }

        $user_id = isset($_POST['user_id']) ? absint(str_replace('wp-', '', $_POST['user_id'])) : 0;
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $company = sanitize_text_field($_POST['company'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $address = sanitize_text_field($_POST['address'] ?? '');
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $zip = sanitize_text_field($_POST['zip'] ?? '');
        $country = sanitize_text_field($_POST['country'] ?? '');
        $social_media = sanitize_textarea_field($_POST['social_media'] ?? '');

        if (empty($first_name) || empty($last_name) || empty($email)) {
            wp_send_json_error(['message' => __('First Name, Last Name, and Email are required.', 'wp-license-manager')]);
        }

        // Check if customer with this email already exists
        $existing_customer = get_posts([
            'post_type' => 'wplm_customer',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_wplm_email',
                    'value' => $email,
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);

        if (!empty($existing_customer)) {
            wp_send_json_error(['message' => __('A customer with this email already exists.', 'wp-license-manager')]);
        }

        $post_title = $first_name . ' ' . $last_name . ' (' . $email . ')';

        $post_data = [
            'post_title'    => $post_title,
            'post_status'   => 'publish',
            'post_type'     => 'wplm_customer',
        ];

        $customer_id = wp_insert_post($post_data, true);

        if (is_wp_error($customer_id)) {
            wp_send_json_error(['message' => $customer_id->get_error_message()]);
        }

        // Save all meta data
        update_post_meta($customer_id, '_wplm_first_name', $first_name);
        update_post_meta($customer_id, '_wplm_last_name', $last_name);
        update_post_meta($customer_id, '_wplm_email', $email);
        update_post_meta($customer_id, '_wplm_company', $company);
        update_post_meta($customer_id, '_wplm_phone', $phone);
        update_post_meta($customer_id, '_wplm_address', $address);
        update_post_meta($customer_id, '_wplm_city', $city);
        update_post_meta($customer_id, '_wplm_state', $state);
        update_post_meta($customer_id, '_wplm_zip', $zip);
        update_post_meta($customer_id, '_wplm_country', $country);
        update_post_meta($customer_id, '_wplm_social_media', $social_media);

        if ($user_id) {
            update_post_meta($customer_id, '_wplm_user_id', $user_id);
        }

        wp_send_json_success(['message' => __('Customer created successfully.', 'wp-license-manager'), 'customer_id' => $customer_id, 'redirect' => admin_url('post.php?post=' . $customer_id . '&action=edit')]);
    }

    /**
     * AJAX handler for searching WordPress users.
     */
    public function ajax_search_users() {
        check_ajax_referer('wplm_search_users_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wp-license-manager')]);
        }

        $search_term = sanitize_text_field($_GET['q'] ?? '');
        $results = [];

        if (empty($search_term)) {
            wp_send_json_success(['results' => $results]);
        }

        $users = get_users([
            'search' => '*' . $search_term . '*',
            'search_columns' => ['user_login', 'user_nicename', 'user_email', 'display_name'],
            'number' => 20,
            'fields' => ['ID', 'display_name'],
        ]);

        foreach ($users as $user) {
            $results[] = [
                'id' => 'wp-' . $user->ID,
                'text' => $user->display_name . ' (ID: ' . $user->ID . ')',
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * Generates a unique standard license key.
     * Format: XXXX-XXXX-XXXX-XXXX-XXXX (20 characters + 4 dashes = 24 total)
     *
     * @return string The generated license key.
     */
    private static function generate_standard_license_key(): string {
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

    public function ajax_search_woocommerce_products() {
        check_ajax_referer('wplm_search_woocommerce_products_nonce', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-license-manager')]);
        }

        $search_term = sanitize_text_field($_GET['q'] ?? '');
        $results = [];

        // Search WooCommerce Products
        $wc_products = wc_get_products([
            'limit' => 20,
            'status' => 'publish',
            'type' => 'virtual',
            's' => $search_term,
        ]);

        foreach ($wc_products as $product) {
            $results[] = [
                'id' => 'woocommerce|' . $product->get_id(),
                'text' => '[WC] ' . $product->get_name() . ' (ID: ' . $product->get_id() . ')',
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX handler for filtering activity log.
     */
    public function ajax_filter_activity_log() {
        check_ajax_referer('wplm_filter_activity', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wp-license-manager')]);
        }

        $activity_type = sanitize_text_field($_POST['activity_type'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');

        // Get filtered activities
        if (class_exists('WPLM_Activity_Logger')) {
            $activities = WPLM_Activity_Logger::get_global_log($activity_type, $date_from, $date_to);
        } else {
            $activities = [];
        }
        
        ob_start();
        $this->render_activity_log_table_content($activities);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX handler for clearing activity log.
     */
    public function ajax_clear_activity_log() {
        check_ajax_referer('wplm_clear_activity', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wp-license-manager')]);
        }

        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::clear_global_log();
            wp_send_json_success(['message' => __('Activity log cleared successfully.', 'wp-license-manager')]);
        } else {
            wp_send_json_error(['message' => __('Activity logging is not available.', 'wp-license-manager')]);
        }
    }

    /**
     * AJAX handler for getting activity logs for DataTables.
     */
    public function ajax_get_activity_logs() {
        check_ajax_referer('wplm_activity_log', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wp-license-manager')]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_activity_log';

        $draw = intval($_POST['draw'] ?? 1);
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $search_value = sanitize_text_field($_POST['search']['value'] ?? '');
        $order_column_idx = intval($_POST['order']['0']['column'] ?? 0);
        $order_dir = sanitize_text_field($_POST['order']['0']['dir'] ?? 'desc');
        $columns = $_POST['columns'] ?? [];

        $orderable_columns = [
            'id', 'item_id', 'event_type', 'description', 'created_at', 'meta_data'
        ];
        $order_by = $orderable_columns[$order_column_idx] ?? 'created_at';

        $where_clauses = [];
        $params = [];

        if (!empty($search_value)) {
            $where_clauses[] = "(id LIKE %s OR item_id LIKE %s OR event_type LIKE %s OR description LIKE %s OR meta_data LIKE %s)";
            $search_param = '%' . $wpdb->esc_like($search_value) . '%';
            array_push($params, $search_param, $search_param, $search_param, $search_param, $search_param);
        }

        $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

        $total_records = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name $where_sql", $params));

        $activities = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table_name 
            $where_sql
            ORDER BY $order_by $order_dir
            LIMIT %d OFFSET %d
        ", array_merge($params, [$length, $start])));

        $data = [];
        foreach ($activities as $activity) {
            $data[] = [
                'id' => $activity->id,
                'item_id' => $activity->item_id,
                'event_type' => esc_html($activity->event_type),
                'description' => esc_html($activity->description),
                'created_at' => esc_html($activity->created_at),
                'meta_data' => '<pre>' . esc_html(json_encode(json_decode($activity->meta_data), JSON_PRETTY_PRINT)) . '</pre>',
            ];
        }

        wp_send_json([
            'draw' => $draw,
            'recordsTotal' => intval($total_records),
            'recordsFiltered' => intval($total_records),
            'data' => $data,
        ]);
    }

    /**
     * Render the activity log table content.
     *
     * @param array $activities The activities to render.
     */
    private function render_activity_log_table_content($activities) {
        if (empty($activities)) {
            echo '<p>' . __('No data available.', 'wp-license-manager') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 50px;">' . __('ID', 'wp-license-manager') . '</th>';
        echo '<th style="width: 80px;">' . __('Item ID', 'wp-license-manager') . '</th>';
        echo '<th style="width: 150px;">' . __('Event Type', 'wp-license-manager') . '</th>';
        echo '<th>' . __('Description', 'wp-license-manager') . '</th>';
        echo '<th style="width: 150px;">' . __('Created At', 'wp-license-manager') . '</th>';
        echo '<th style="width: 200px;">' . __('Meta Data', 'wp-license-manager') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($activities as $activity) {
            echo '<tr>';
            echo '<td>' . esc_html($activity->id) . '</td>';
            echo '<td>' . esc_html($activity->item_id) . '</td>';
            echo '<td>' . esc_html($activity->event_type) . '</td>';
            echo '<td>' . esc_html($activity->description) . '</td>';
            echo '<td>' . esc_html($activity->created_at) . '</td>';
            echo '<td><pre>' . esc_html(json_encode(json_decode($activity->meta_data), JSON_PRETTY_PRINT)) . '</pre></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }
}
