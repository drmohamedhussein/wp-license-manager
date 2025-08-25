<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Manager - Meta Boxes and Basic Admin Functionality
 * Part 1 of the split WPLM_Admin_Manager class
 */
class WPLM_Admin_Manager_Meta_Boxes {

    private $products_map = null;

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_post_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
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
        $product_type = get_post_meta($post->ID, '_wplm_product_type', true);

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
                        $duration_value = get_post_meta($post->ID, '_wplm_duration_value', true) ?: '1';
                        ?>
                        <select name="wplm_duration_type" id="wplm_duration_type">
                            <option value="lifetime" <?php selected($duration_type, 'lifetime'); ?>><?php _e('Lifetime', 'wp-license-manager'); ?></option>
                            <option value="days" <?php selected($duration_type, 'days'); ?>><?php _e('Days', 'wp-license-manager'); ?></option>
                            <option value="months" <?php selected($duration_type, 'months'); ?>><?php _e('Months', 'wp-license-manager'); ?></option>
                            <option value="years" <?php selected($duration_type, 'years'); ?>><?php _e('Years', 'wp-license-manager'); ?></option>
                        </select>
                        <input type="number" name="wplm_duration_value" id="wplm_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" style="width: 80px;" <?php echo ($duration_type === 'lifetime') ? 'disabled' : ''; ?>>
                        <p class="description"><?php _e('Set the license duration. Leave as lifetime for unlimited access.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_expiry_date"><?php _e('Expiry Date', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="date" id="wplm_expiry_date" name="wplm_expiry_date" value="<?php echo esc_attr($expiry_date); ?>" <?php echo ($duration_type === 'lifetime') ? 'disabled' : ''; ?>>
                        <p class="description"><?php _e('Set the expiry date for the license.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_activation_limit"><?php _e('Activation Limit', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="number" id="wplm_activation_limit" name="wplm_activation_limit" value="<?php echo esc_attr($activation_limit); ?>" min="1" class="small-text">
                        <p class="description"><?php _e('Maximum number of domains this license can be activated on. Use -1 for unlimited.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_activated_domains"><?php _e('Activated Domains', 'wp-license-manager'); ?></label></th>
                    <td>
                        <textarea id="wplm_activated_domains" name="wplm_activated_domains" rows="3" class="large-text" readonly><?php echo esc_textarea(is_array($activated_domains) ? implode("\n", $activated_domains) : $activated_domains); ?></textarea>
                        <p class="description"><?php _e('Domains where this license is currently activated. This field is read-only and managed automatically.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <script>
        jQuery(document).ready(function($) {
            $('#wplm_duration_type').on('change', function() {
                var durationType = $(this).val();
                var durationValue = $('#wplm_duration_value');
                var expiryDate = $('#wplm_expiry_date');
                
                if (durationType === 'lifetime') {
                    durationValue.prop('disabled', true);
                    expiryDate.prop('disabled', true);
                } else {
                    durationValue.prop('disabled', false);
                    expiryDate.prop('disabled', false);
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
        $product_type = get_post_meta($post->ID, '_wplm_product_type', true);
        $description = get_post_meta($post->ID, '_wplm_description', true);
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="wplm_product_id"><?php _e('Product ID', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="wplm_product_id" name="wplm_product_id" value="<?php echo esc_attr($product_id); ?>" class="regular-text">
                        <p class="description"><?php _e('Unique identifier for this product (e.g., plugin-slug, theme-name).', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_current_version"><?php _e('Current Version', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="wplm_current_version" name="wplm_current_version" value="<?php echo esc_attr($current_version); ?>" class="regular-text">
                        <p class="description"><?php _e('Current version of this product (e.g., 1.0.0).', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_download_url"><?php _e('Download URL', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="url" id="wplm_download_url" name="wplm_download_url" value="<?php echo esc_url($download_url); ?>" class="large-text">
                        <p class="description"><?php _e('URL where customers can download the latest version of this product.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_product_type"><?php _e('Product Type', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="wplm_product_type" id="wplm_product_type">
                            <option value="plugin" <?php selected($product_type, 'plugin'); ?>><?php _e('Plugin', 'wp-license-manager'); ?></option>
                            <option value="theme" <?php selected($product_type, 'theme'); ?>><?php _e('Theme', 'wp-license-manager'); ?></option>
                            <option value="addon" <?php selected($product_type, 'addon'); ?>><?php _e('Addon', 'wp-license-manager'); ?></option>
                            <option value="other" <?php selected($product_type, 'other'); ?>><?php _e('Other', 'wp-license-manager'); ?></option>
                        </select>
                        <p class="description"><?php _e('Type of product this license is for.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_description"><?php _e('Description', 'wp-license-manager'); ?></label></th>
                    <td>
                        <textarea id="wplm_description" name="wplm_description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                        <p class="description"><?php _e('Brief description of this product.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the meta box for WooCommerce products.
     */
    public function render_woocommerce_license_options_meta_box($post) {
        wp_nonce_field('wplm_save_woocommerce_license_options', 'wplm_nonce');

        $is_licensed_product = get_post_meta($post->ID, '_wplm_wc_is_licensed_product', true);
        $linked_wplm_product_id = get_post_meta($post->ID, '_wplm_wc_linked_wplm_product_id', true);
        $current_version = get_post_meta($post->ID, '_wplm_wc_current_version', true);
        $download_url = get_post_meta($post->ID, '_wplm_wc_download_url', true);

        // Get WPLM products for linking
        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="wplm_wc_is_licensed_product"><?php _e('Enable Licensing', 'wp-license-manager'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wplm_wc_is_licensed_product" name="wplm_wc_is_licensed_product" value="yes" <?php checked($is_licensed_product, 'yes'); ?>>
                            <?php _e('Enable license generation for this product', 'wp-license-manager'); ?>
                        </label>
                        <p class="description"><?php _e('Check this to automatically generate licenses when this product is purchased.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_wc_linked_wplm_product_id"><?php _e('Link to WPLM Product', 'wp-license-manager'); ?></label></th>
                    <td>
                        <select name="wplm_wc_linked_wplm_product_id" id="wplm_wc_linked_wplm_product_id">
                            <option value=""><?php _e('-- Create New WPLM Product --', 'wp-license-manager'); ?></option>
                            <?php foreach ($wplm_products as $product) : ?>
                                <option value="<?php echo esc_attr($product->ID); ?>" <?php selected($linked_wplm_product_id, $product->ID); ?>>
                                    <?php echo esc_html($product->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Link to an existing WPLM product or create a new one automatically.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_wc_current_version"><?php _e('Current Version', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="text" id="wplm_wc_current_version" name="wplm_wc_current_version" value="<?php echo esc_attr($current_version); ?>" class="regular-text">
                        <p class="description"><?php _e('Current version of this product (e.g., 1.0.0).', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wplm_wc_download_url"><?php _e('Download URL', 'wp-license-manager'); ?></label></th>
                    <td>
                        <input type="url" id="wplm_wc_download_url" name="wplm_wc_download_url" value="<?php echo esc_url($download_url); ?>" class="large-text">
                        <p class="description"><?php _e('URL where customers can download the latest version of this product.', 'wp-license-manager'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save post meta data.
     */
    public function save_post_meta($post_id) {
        // Security checks
        if (!isset($_POST['wplm_nonce']) || !wp_verify_nonce($_POST['wplm_nonce'], 'wplm_save_license_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $post_type = get_post_type($post_id);

        if ($post_type === 'wplm_license') {
            $this->save_license_meta($post_id);
        } elseif ($post_type === 'wplm_product') {
            $this->save_product_meta($post_id);
        } elseif ($post_type === 'product' && function_exists('wc_get_products')) {
            $this->save_woocommerce_license_options($post_id);
        }
    }

    /**
     * Save license meta data.
     */
    private function save_license_meta($post_id) {
        // Save status
        if (isset($_POST['wplm_status'])) {
            $status = sanitize_text_field($_POST['wplm_status']);
            update_post_meta($post_id, '_wplm_status', $status);
        }

        // Save product association
        if (isset($_POST['wplm_product_id'])) {
            $product_value = sanitize_text_field($_POST['wplm_product_id']);
            if (!empty($product_value)) {
                $parts = explode('|', $product_value);
                if (count($parts) === 2) {
                    update_post_meta($post_id, '_wplm_product_type', $parts[0]);
                    update_post_meta($post_id, '_wplm_product_id', $parts[1]);
                }
            }
        }

        // Save customer email
        if (isset($_POST['wplm_customer_email'])) {
            $customer_email = sanitize_email($_POST['wplm_customer_email']);
            update_post_meta($post_id, '_wplm_customer_email', $customer_email);
        }

        // Save duration settings
        if (isset($_POST['wplm_duration_type'])) {
            $duration_type = sanitize_text_field($_POST['wplm_duration_type']);
            update_post_meta($post_id, '_wplm_duration_type', $duration_type);

            if ($duration_type !== 'lifetime' && isset($_POST['wplm_duration_value'])) {
                $duration_value = absint($_POST['wplm_duration_value']);
                update_post_meta($post_id, '_wplm_duration_value', $duration_value);
            }
        }

        // Save expiry date
        if (isset($_POST['wplm_expiry_date'])) {
            $expiry_date = sanitize_text_field($_POST['wplm_expiry_date']);
            update_post_meta($post_id, '_wplm_expiry_date', $expiry_date);
        }

        // Save activation limit
        if (isset($_POST['wplm_activation_limit'])) {
            $activation_limit = absint($_POST['wplm_activation_limit']);
            update_post_meta($post_id, '_wplm_activation_limit', $activation_limit);
        }
    }

    /**
     * Save product meta data.
     */
    private function save_product_meta($post_id) {
        if (isset($_POST['wplm_product_id'])) {
            $product_id = sanitize_text_field($_POST['wplm_product_id']);
            update_post_meta($post_id, '_wplm_product_id', $product_id);
        }

        if (isset($_POST['wplm_current_version'])) {
            $current_version = sanitize_text_field($_POST['wplm_current_version']);
            update_post_meta($post_id, '_wplm_current_version', $current_version);
        }

        if (isset($_POST['wplm_download_url'])) {
            $download_url = esc_url_raw($_POST['wplm_download_url']);
            update_post_meta($post_id, '_wplm_download_url', $download_url);
        }

        if (isset($_POST['wplm_product_type'])) {
            $product_type = sanitize_text_field($_POST['wplm_product_type']);
            update_post_meta($post_id, '_wplm_product_type', $product_type);
        }

        if (isset($_POST['wplm_description'])) {
            $description = sanitize_textarea_field($_POST['wplm_description']);
            update_post_meta($post_id, '_wplm_description', $description);
        }
    }

    /**
     * Save WooCommerce license options.
     */
    private function save_woocommerce_license_options($post_id) {
        if (isset($_POST['wplm_wc_is_licensed_product'])) {
            $is_licensed = sanitize_text_field($_POST['wplm_wc_is_licensed_product']);
            update_post_meta($post_id, '_wplm_wc_is_licensed_product', $is_licensed);
        }

        if (isset($_POST['wplm_wc_linked_wplm_product_id'])) {
            $linked_product_id = absint($_POST['wplm_wc_linked_wplm_product_id']);
            update_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', $linked_product_id);
        }

        if (isset($_POST['wplm_wc_current_version'])) {
            $current_version = sanitize_text_field($_POST['wplm_wc_current_version']);
            update_post_meta($post_id, '_wplm_wc_current_version', $current_version);
        }

        if (isset($_POST['wplm_wc_download_url'])) {
            $download_url = esc_url_raw($_POST['wplm_wc_download_url']);
            update_post_meta($post_id, '_wplm_wc_download_url', $download_url);
        }
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_admin_assets($hook_suffix) {
        // Only load on our admin pages
        if (strpos($hook_suffix, 'wplm') === false && strpos($hook_suffix, 'license-manager') === false) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    }
}