<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced WooCommerce synchronization for WPLM
 */
class WPLM_WooCommerce_Sync {

    public function __construct() {
        add_action('init', [$this, 'init']);
        
        // Product sync hooks
        add_action('save_post', [$this, 'sync_product_on_save'], 20, 2);
        add_action('woocommerce_process_product_meta', [$this, 'sync_product_meta'], 30);
        add_action('woocommerce_save_product_variation', [$this, 'sync_variation'], 30, 2);
        
        // Delete hooks
        add_action('before_delete_post', [$this, 'handle_product_deletion']);
        
        // Status change hooks
        add_action('transition_post_status', [$this, 'handle_product_status_change'], 10, 3);
        
        // Bulk actions
        add_action('wp_ajax_wplm_bulk_sync_products', [$this, 'ajax_bulk_sync_products']);
        add_action('wp_ajax_wplm_sync_single_product', [$this, 'ajax_sync_single_product']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'show_sync_notices']);
        
        // Cron job for periodic sync
        add_action('wplm_periodic_product_sync', [$this, 'periodic_sync']);
        
        if (!wp_next_scheduled('wplm_periodic_product_sync')) {
            wp_schedule_event(time(), 'hourly', 'wplm_periodic_product_sync');
        }
    }

    /**
     * Initialize sync system
     */
    public function init() {
        // Add sync status to product meta
        add_action('add_meta_boxes', [$this, 'add_sync_meta_boxes']);
        
        // Add bulk sync action
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_sync_action']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_sync_action'], 10, 3);
        
        // Add sync column to products list
        add_filter('manage_product_posts_columns', [$this, 'add_sync_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_sync_column'], 10, 2);
    }

    /**
     * Sync product when saved
     */
    public function sync_product_on_save($post_id, $post) {
        if ($post->post_type !== 'product') {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $this->sync_woocommerce_product($post_id);
    }

    /**
     * Sync product meta after WooCommerce processes it
     */
    public function sync_product_meta($product_id) {
        $this->sync_woocommerce_product($product_id);
    }

    /**
     * Sync product variation
     */
    public function sync_variation($variation_id, $i) {
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return;
        }

        $parent_id = $variation->get_parent_id();
        $parent_product = wc_get_product($parent_id);
        
        if (!$parent_product) {
            return;
        }

        // Check if parent product is licensed
        $is_licensed = get_post_meta($parent_id, '_wplm_wc_is_licensed_product', true);
        if ($is_licensed !== 'yes') {
            return;
        }

        // Sync variation-specific license settings
        $this->sync_variation_license_settings($variation_id, $parent_id);
    }

    /**
     * Main product synchronization method
     */
    public function sync_woocommerce_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        $is_licensed = get_post_meta($product_id, '_wplm_wc_is_licensed_product', true);
        
        if ($is_licensed !== 'yes') {
            // If product is no longer licensed, handle cleanup
            $this->handle_unlicensed_product($product_id);
            return false;
        }

        // Get or create linked WPLM product
        $wplm_product_id = $this->get_or_create_wplm_product($product);
        
        if (!$wplm_product_id) {
            return false;
        }

        // Sync basic product data
        $this->sync_basic_product_data($product, $wplm_product_id);
        
        // Sync license-specific data
        $this->sync_license_settings($product, $wplm_product_id);
        
        // Sync variations if it's a variable product
        if ($product->is_type('variable')) {
            $this->sync_product_variations($product, $wplm_product_id);
        }

        // Update sync status
        update_post_meta($product_id, '_wplm_sync_status', 'synced');
        update_post_meta($product_id, '_wplm_last_sync', current_time('mysql'));
        
        // Log activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                $wplm_product_id,
                'product_synced_from_woocommerce',
                sprintf('Product "%s" synced from WooCommerce product #%d', $product->get_name(), $product_id),
                ['wc_product_id' => $product_id, 'product_type' => $product->get_type()]
            );
        }

        return true;
    }

    /**
     * Get or create WPLM product for WooCommerce product
     */
    private function get_or_create_wplm_product($wc_product) {
        $product_id = $wc_product->get_id();
        // Try to find existing WPLM product by looking up its linked WC product ID
        $existing_wplm_products = get_posts([
            'post_type'      => 'wplm_product',
            'meta_key'       => '_wplm_wc_product_id',
            'meta_value'     => $product_id,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        if (!empty($existing_wplm_products)) {
            return $existing_wplm_products[0];
        }

        // Create new WPLM product
        $product_title = $wc_product->get_name();
        $product_slug = $this->generate_unique_product_slug($product_title);
        
        $wplm_product_id = wp_insert_post([
            'post_title' => $product_title,
            'post_name' => $product_slug, // Set post_name directly
            'post_content' => $wc_product->get_description(),
            'post_excerpt' => $wc_product->get_short_description(),
            'post_type' => 'wplm_product',
            'post_status' => 'publish'
        ]);

        if (is_wp_error($wplm_product_id)) {
            error_log('WPLM Sync Error: Failed to create WPLM product: ' . $wplm_product_id->get_error_message());
            return false;
        }

        // Set product meta
        update_post_meta($wplm_product_id, '_wplm_product_type', 'wplm');
        update_post_meta($wplm_product_id, '_wplm_wc_product_id', $product_id); // Link WPLM product to WC product by ID
        
        // Link WooCommerce product to WPLM product by ID (for WC product meta)
        update_post_meta($product_id, '_wplm_wc_linked_wplm_product_id', $wplm_product_id);
        delete_post_meta($product_id, '_wplm_wc_linked_wplm_product'); // Remove old slug-based link

        return $wplm_product_id;
    }

    /**
     * Sync basic product data
     */
    private function sync_basic_product_data($wc_product, $wplm_product_id) {
        // Update post data
        wp_update_post([
            'ID' => $wplm_product_id,
            'post_title' => $wc_product->get_name(),
            'post_content' => $wc_product->get_description(),
            'post_excerpt' => $wc_product->get_short_description(),
            'post_status' => $wc_product->get_status() === 'publish' ? 'publish' : 'draft'
        ]);

        // Sync version
        $version = get_post_meta($wc_product->get_id(), '_wplm_wc_current_version', true);
        if (!empty($version)) {
            update_post_meta($wplm_product_id, '_wplm_current_version', $version);
        }

        // Sync download URL if available
        if ($wc_product->is_downloadable()) {
            $downloads = $wc_product->get_downloads();
            if (!empty($downloads)) {
                $first_download = reset($downloads);
                update_post_meta($wplm_product_id, '_wplm_download_url', $first_download->get_file());
            }
        }
    }

    /**
     * Sync license settings
     */
    private function sync_license_settings($wc_product, $wplm_product_id) {
        $product_id = $wc_product->get_id();
        
        // Sync default duration settings
        $duration_type = get_post_meta($product_id, '_wplm_wc_default_duration_type', true) ?: 'lifetime';
        $duration_value = get_post_meta($product_id, '_wplm_wc_default_duration_value', true) ?: 1;
        
        update_post_meta($wplm_product_id, '_wplm_default_duration_type', $duration_type);
        update_post_meta($wplm_product_id, '_wplm_default_duration_value', $duration_value);
        
        // Sync other license settings
        $activation_limit = get_post_meta($product_id, '_wplm_wc_default_activation_limit', true) ?: 1;
        update_post_meta($wplm_product_id, '_wplm_default_activation_limit', $activation_limit);
    }

    /**
     * Sync product variations
     */
    private function sync_product_variations($wc_product, $wplm_product_id) {
        if (!$wc_product->is_type('variable')) {
            return;
        }

        $variations = $wc_product->get_children();
        $variation_data = [];

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $variation_settings = $this->get_variation_license_settings($variation_id, $wc_product->get_id());
            $variation_data[$variation_id] = [
                'name' => $variation->get_name(),
                'attributes' => $variation->get_variation_attributes(),
                'license_settings' => $variation_settings
            ];
        }

        update_post_meta($wplm_product_id, '_wplm_variations_data', $variation_data);
    }

    /**
     * Get variation license settings
     */
    private function get_variation_license_settings($variation_id, $parent_id) {
        // Get variation-specific settings or fall back to parent settings
        $duration_type = get_post_meta($variation_id, '_wplm_variation_duration_type', true);
        $duration_value = get_post_meta($variation_id, '_wplm_variation_duration_value', true);
        $activation_limit = get_post_meta($variation_id, '_wplm_variation_activation_limit', true);

        if (empty($duration_type)) {
            $duration_type = get_post_meta($parent_id, '_wplm_wc_default_duration_type', true) ?: 'lifetime';
        }
        
        if (empty($duration_value)) {
            $duration_value = get_post_meta($parent_id, '_wplm_wc_default_duration_value', true) ?: 1;
        }
        
        if (empty($activation_limit)) {
            $activation_limit = get_post_meta($parent_id, '_wplm_wc_default_activation_limit', true) ?: 1;
        }

        return [
            'duration_type' => $duration_type,
            'duration_value' => intval($duration_value),
            'activation_limit' => intval($activation_limit)
        ];
    }

    /**
     * Sync variation license settings
     */
    private function sync_variation_license_settings($variation_id, $parent_id) {
        $settings = $this->get_variation_license_settings($variation_id, $parent_id);
        
        // Update variation meta with computed settings
        update_post_meta($variation_id, '_wplm_computed_duration_type', $settings['duration_type']);
        update_post_meta($variation_id, '_wplm_computed_duration_value', $settings['duration_value']);
        update_post_meta($variation_id, '_wplm_computed_activation_limit', $settings['activation_limit']);
    }

    /**
     * Handle product deletion
     */
    public function handle_product_deletion($post_id) {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        $linked_slug = get_post_meta($post_id, '_wplm_wc_linked_wplm_product_id', true);
        if (empty($linked_slug)) {
            return;
        }

        // Find linked WPLM product
        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_wc_product_id',
            'meta_value' => $linked_slug,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        if (!empty($wplm_products)) {
            $wplm_product = $wplm_products[0];
            
            // Option 1: Delete WPLM product
            // wp_delete_post($wplm_product->ID, true);
            
            // Option 2: Just mark as orphaned (recommended)
            update_post_meta($wplm_product->ID, '_wplm_wc_product_deleted', true);
            update_post_meta($wplm_product->ID, '_wplm_wc_product_id', '');
            
            // Log activity
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $wplm_product->ID,
                    'wc_product_deleted',
                    sprintf('WooCommerce product #%d was deleted, WPLM product orphaned', $post_id),
                    ['deleted_wc_product_id' => $post_id]
                );
            }
        }
    }

    /**
     * Handle product status changes
     */
    public function handle_product_status_change($new_status, $old_status, $post) {
        if ($post->post_type !== 'product') {
            return;
        }

        $linked_slug = get_post_meta($post->ID, '_wplm_wc_linked_wplm_product_id', true);
        if (empty($linked_slug)) {
            return;
        }

        // Find linked WPLM product
        $wplm_products = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_wc_product_id',
            'meta_value' => $linked_slug,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        if (!empty($wplm_products)) {
            $wplm_product_id = $wplm_products[0]->ID;
            
            // Sync status
            $wplm_status = ($new_status === 'publish') ? 'publish' : 'draft';
            wp_update_post([
                'ID' => $wplm_product_id,
                'post_status' => $wplm_status
            ]);
            
            // Log activity
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $wplm_product_id,
                    'product_status_synced',
                    sprintf('Product status synced: %s → %s', $old_status, $new_status),
                    ['old_status' => $old_status, 'new_status' => $new_status]
                );
            }
        }
    }

    /**
     * Handle unlicensed product
     */
    private function handle_unlicensed_product($product_id) {
        $linked_slug = get_post_meta($product_id, '_wplm_wc_linked_wplm_product_id', true);
        if (empty($linked_slug)) {
            return;
        }

        // Mark as no longer licensed
        update_post_meta($product_id, '_wplm_sync_status', 'not_licensed');
        
        // Optionally disable associated licenses
        $wplm_product_id = get_post_meta($product_id, '_wplm_wc_linked_wplm_product_id', true);
        if ($wplm_product_id) {
            $this->disable_product_licenses($wplm_product_id);
        }
    }

    /**
     * Disable licenses for a product
     */
    private function disable_product_licenses($product_id) {
        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $product_id,
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        foreach ($licenses as $license) {
            update_post_meta($license->ID, '_wplm_status', 'inactive');
            
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    $license->ID,
                    'license_disabled_product_unlicensed',
                    sprintf('License disabled because product "%s" is no longer licensed', $product_id),
                    ['product_id' => $product_id]
                );
            }
        }
    }

    /**
     * Generate unique product slug
     */
    private function generate_unique_product_slug($title) {
        $base_slug = sanitize_title($title);
        $slug = $base_slug;
        $counter = 1;

        while (post_exists_by_slug($slug, 'wplm_product')) { // Use helper function
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if slug exists for a given post type.
     * Helper function, replacing slug_exists method.
     *
     * @param string $slug The slug to check.
     * @param string $post_type The post type to check within.
     * @return bool True if the slug exists, false otherwise.
     */
    private function post_exists_by_slug(string $slug, string $post_type): bool {
        $args = [
            'name'           => $slug,
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];
        $query = new WP_Query($args);
        return $query->have_posts();
    }

    /**
     * Periodic sync of all licensed products
     */
    public function periodic_sync() {
        $licensed_products = get_posts([
            'post_type' => 'product',
            'meta_key' => '_wplm_wc_is_licensed_product',
            'meta_value' => 'yes',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        foreach ($licensed_products as $product) {
            $this->sync_woocommerce_product($product->ID);
        }
        
        // Log periodic sync
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(
                0,
                'periodic_sync_completed',
                sprintf('Periodic sync completed for %d licensed products', count($licensed_products)),
                ['synced_count' => count($licensed_products)]
            );
        }
    }

    /**
     * Add sync meta box to product edit screen
     */
    public function add_sync_meta_boxes() {
        add_meta_box(
            'wplm_product_sync_status',
            __('WPLM Sync Status', 'wp-license-manager'),
            [$this, 'render_sync_meta_box'],
            'product',
            'side',
            'high'
        );
    }

    /**
     * Render sync meta box
     */
    public function render_sync_meta_box($post) {
        $is_licensed = get_post_meta($post->ID, '_wplm_wc_is_licensed_product', true);
        $sync_status = get_post_meta($post->ID, '_wplm_sync_status', true);
        $last_sync = get_post_meta($post->ID, '_wplm_last_sync', true);
        $linked_slug = get_post_meta($post->ID, '_wplm_wc_linked_wplm_product_id', true);

        ?>
        <div class="wplm-sync-status">
            <?php if ($is_licensed === 'yes'): ?>
                <p><strong><?php _e('Licensed Product:', 'wp-license-manager'); ?></strong> ✓</p>
                
                <?php if (!empty($linked_slug)): ?>
                    <p><strong><?php _e('WPLM Product ID:', 'wp-license-manager'); ?></strong> <?php echo esc_html($linked_slug); ?></p>
                <?php endif; ?>
                
                <p><strong><?php _e('Sync Status:', 'wp-license-manager'); ?></strong> 
                    <span class="wplm-status-badge wplm-status-<?php echo esc_attr($sync_status ?: 'unknown'); ?>">
                        <?php echo esc_html(ucfirst($sync_status ?: 'Unknown')); ?>
                    </span>
                </p>
                
                <?php if ($last_sync): ?>
                    <p><strong><?php _e('Last Sync:', 'wp-license-manager'); ?></strong> <?php echo esc_html(date('Y-m-d H:i:s', strtotime($last_sync))); ?></p>
                <?php endif; ?>
                
                <p>
                    <button type="button" class="button button-secondary" id="wplm-sync-single-product" data-product-id="<?php echo esc_attr($post->ID); ?>">
                        <?php _e('Sync Now', 'wp-license-manager'); ?>
                    </button>
                </p>
            <?php else: ?>
                <p><?php _e('This product is not licensed.', 'wp-license-manager'); ?></p>
            <?php endif; ?>
        </div>

        <style>
        .wplm-status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .wplm-status-synced { background: #d1fae5; color: #065f46; }
        .wplm-status-pending { background: #fef3c7; color: #92400e; }
        .wplm-status-error { background: #fee2e2; color: #991b1b; }
        .wplm-status-unknown { background: #f3f4f6; color: #374151; }
        </style>
        <?php
    }

    /**
     * Add bulk sync action
     */
    public function add_bulk_sync_action($actions) {
        $actions['wplm_bulk_sync'] = __('Sync with WPLM', 'wp-license-manager');
        return $actions;
    }

    /**
     * Handle bulk sync action
     */
    public function handle_bulk_sync_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'wplm_bulk_sync') {
            return $redirect_to;
        }

        $synced_count = 0;
        foreach ($post_ids as $post_id) {
            if ($this->sync_woocommerce_product($post_id)) {
                $synced_count++;
            }
        }

        $redirect_to = add_query_arg('wplm_bulk_synced', $synced_count, $redirect_to);
        return $redirect_to;
    }

    /**
     * Add sync column to products list
     */
    public function add_sync_column($columns) {
        $columns['wplm_sync_status'] = __('WPLM Sync', 'wp-license-manager');
        return $columns;
    }

    /**
     * Render sync column content
     */
    public function render_sync_column($column, $post_id) {
        if ($column !== 'wplm_sync_status') {
            return;
        }

        $is_licensed = get_post_meta($post_id, '_wplm_wc_is_licensed_product', true);
        
        if ($is_licensed !== 'yes') {
            echo '<span style="color: #666;">—</span>';
            return;
        }

        $sync_status = get_post_meta($post_id, '_wplm_sync_status', true) ?: 'unknown';
        $status_colors = [
            'synced' => '#46b450',
            'pending' => '#ffb900',
            'error' => '#dc3232',
            'unknown' => '#82878c'
        ];

        $color = $status_colors[$sync_status] ?? $status_colors['unknown'];
        echo '<span style="color: ' . esc_attr($color) . '; font-weight: bold;">●</span> ';
        echo esc_html(ucfirst($sync_status));
    }

    /**
     * Show sync notices
     */
    public function show_sync_notices() {
        if (isset($_GET['wplm_bulk_synced'])) {
            $count = intval($_GET['wplm_bulk_synced']);
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(__('Successfully synced %d products with WPLM.', 'wp-license-manager'), $count); ?></p>
            </div>
            <?php
        }
    }

    /**
     * AJAX: Bulk sync products
     */
    public function ajax_bulk_sync_products() {
        check_ajax_referer('wplm_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $product_ids = array_map('intval', $_POST['product_ids'] ?? []);
        $synced_count = 0;

        foreach ($product_ids as $product_id) {
            if ($this->sync_woocommerce_product($product_id)) {
                $synced_count++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Successfully synced %d products', 'wp-license-manager'), $synced_count),
            'synced_count' => $synced_count
        ]);
    }

    /**
     * AJAX: Sync single product
     */
    public function ajax_sync_single_product() {
        check_ajax_referer('wplm_sync_single', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error(['message' => 'Invalid product ID']);
        }

        $result = $this->sync_woocommerce_product($product_id);
        
        if ($result) {
            wp_send_json_success(['message' => __('Product synced successfully', 'wp-license-manager')]);
        } else {
            wp_send_json_error(['message' => __('Failed to sync product', 'wp-license-manager')]);
        }
    }
}
