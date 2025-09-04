<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages Custom Post Types for Licenses and Products.
 */
class WPLM_CPT_Manager {

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_filter('bulk_actions-edit-wplm_product', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-edit-wplm_license', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-edit-wplm_subscription', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-edit-wplm_order', [$this, 'add_bulk_actions']);
    }

    /**
     * Register the custom post types.
     */
    public function register_post_types() {
        // Register License Post Type
        $license_labels = [
            'name'                  => _x('Licenses', 'Post Type General Name', 'wp-license-manager'),
            'singular_name'         => _x('License', 'Post Type Singular Name', 'wp-license-manager'),
            'menu_name'             => __('Licenses', 'wp-license-manager'),
            'name_admin_bar'        => __('License', 'wp-license-manager'),
            'add_new_item'          => __('Add New License', 'wp-license-manager'),
            'add_new'               => __('Add New', 'wp-license-manager'),
            'new_item'              => __('New License', 'wp-license-manager'),
            'edit_item'             => __('Edit License', 'wp-license-manager'),
            'update_item'           => __('Update License', 'wp-license-manager'),
            'view_item'             => __('View License', 'wp-license-manager'),
            'search_items'          => __('Search License', 'wp-license-manager'),
        ];
        // Show native CPT menus by default so Orders/Customers are visible immediately
        $show_native = (bool) get_option('wplm_show_native_cpt_menus', true);
        $license_args = [
            'label'                 => __('License', 'wp-license-manager'),
            'description'           => __('License Keys', 'wp-license-manager'),
            'labels'                => $license_labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            // Ensure Licenses provides the parent native menu
            'show_in_menu'          => $show_native ? true : false,
            'menu_position'         => 20,
            'menu_icon'             => 'dashicons-lock',
            'show_in_admin_bar'     => $show_native ? true : false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post', // Use standard post capabilities for better compatibility
            'map_meta_cap'          => true, // Map WordPress meta capabilities
            'show_in_rest'          => false,
        ];
        register_post_type('wplm_license', $license_args);

        // Register Product Post Type
        $product_labels = [
            'name'                  => _x('Products', 'Post Type General Name', 'wp-license-manager'),
            'singular_name'         => _x('Product', 'Post Type Singular Name', 'wp-license-manager'),
            'menu_name'             => __('Products', 'wp-license-manager'),
            'name_admin_bar'        => __('Product', 'wp-license-manager'), // Added
            'add_new_item'          => __('Add New Product', 'wp-license-manager'),
            'add_new'               => __('Add New', 'wp-license-manager'),
            'new_item'              => __('New Product', 'wp-license-manager'),
            'edit_item'             => __('Edit Product', 'wp-license-manager'),
            'update_item'           => __('Update Product', 'wp-license-manager'),
            'view_item'             => __('View Product', 'wp-license-manager'),
            'search_items'          => __('Search Product', 'wp-license-manager'),
            'not_found'             => __('No products found', 'wp-license-manager'), // Added
            'not_found_in_trash'    => __('No products found in Trash', 'wp-license-manager'), // Added
            'all_items'             => __('All Products', 'wp-license-manager'), // Added
        ];
        $product_args = [
            'label'                 => __('Product', 'wp-license-manager'),
            'labels'                => $product_labels,
            'supports'              => ['title', 'editor', 'thumbnail', 'excerpt'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            // Place Products under Licenses native CPT menu like other WPLM post types
            'show_in_menu'          => $show_native ? 'edit.php?post_type=wplm_license' : false,
            'show_in_admin_bar'     => $show_native ? true : false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post', // Use standard post capabilities for better compatibility
            'map_meta_cap'          => true,
            'show_in_rest'          => true, // Enable Gutenberg support
        ];
        register_post_type('wplm_product', $product_args);

        // Add filter to handle slug conflicts for WPLM products
        add_filter('wp_unique_post_slug', [$this, 'handle_wplm_product_slug'], 10, 6);

        // Register Subscription Post Type
        $subscription_labels = [
            'name'                  => _x('Subscriptions', 'Post Type General Name', 'wp-license-manager'),
            'singular_name'         => _x('Subscription', 'Post Type Singular Name', 'wp-license-manager'),
            'menu_name'             => __('Subscriptions', 'wp-license-manager'),
            'name_admin_bar'        => __('Subscription', 'wp-license-manager'),
            'add_new_item'          => __('Add New Subscription', 'wp-license-manager'),
            'add_new'               => __('Add New', 'wp-license-manager'),
            'new_item'              => __('New Subscription', 'wp-license-manager'),
            'edit_item'             => __('Edit Subscription', 'wp-license-manager'),
            'update_item'           => __('Update Subscription', 'wp-license-manager'),
            'view_item'             => __('View Subscription', 'wp-license-manager'),
            'search_items'          => __('Search Subscriptions', 'wp-license-manager'),
            'not_found'             => __('No subscriptions found', 'wp-license-manager'),
            'not_found_in_trash'    => __('No subscriptions found in Trash', 'wp-license-manager'),
            'all_items'             => __('All Subscriptions', 'wp-license-manager'),
        ];
        $subscription_args = [
            'label'                 => __('Subscription', 'wp-license-manager'),
            'description'           => __('Customer Subscriptions', 'wp-license-manager'),
            'labels'                => $subscription_labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            // Place Subscriptions under Licenses native CPT menu
            'show_in_menu'          => $show_native ? 'edit.php?post_type=wplm_license' : false,
            'menu_position'         => 21,
            'show_in_admin_bar'     => $show_native ? true : false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post', // Use standard post capabilities for better compatibility
            'map_meta_cap'          => true,
            'show_in_rest'          => false,
        ];
        register_post_type('wplm_subscription', $subscription_args);

        // Register Customer Post Type
        $customer_labels = [
            'name'                  => _x('Customers', 'Post Type General Name', 'wp-license-manager'),
            'singular_name'         => _x('Customer', 'Post Type Singular Name', 'wp-license-manager'),
            'menu_name'             => __('Customers', 'wp-license-manager'),
            'name_admin_bar'        => __('Customer', 'wp-license-manager'),
            'add_new_item'          => __('Add New Customer', 'wp-license-manager'),
            'add_new'               => __('Add New', 'wp-license-manager'),
            'new_item'              => __('New Customer', 'wp-license-manager'),
            'edit_item'             => __('Edit Customer', 'wp-license-manager'),
            'update_item'           => __('Update Customer', 'wp-license-manager'),
            'view_item'             => __('View Customer', 'wp-license-manager'),
            'search_items'          => __('Search Customers', 'wp-license-manager'),
            'not_found'             => __('No customers found', 'wp-license-manager'),
            'not_found_in_trash'    => __('No customers found in Trash', 'wp-license-manager'),
            'all_items'             => __('All Customers', 'wp-license-manager'),
        ];

        $customer_args = [
            'label'                 => __('Customer', 'wp-license-manager'),
            'description'           => __('WPLM Customers', 'wp-license-manager'),
            'labels'                => $customer_labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            // Place Customers under Licenses native CPT menu
            'show_in_menu'          => $show_native ? 'edit.php?post_type=wplm_license' : false,
            'menu_position'         => 19,
            'show_in_admin_bar'     => $show_native ? true : false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            // Use standard post capabilities so admins/editor roles can see and edit
            'capability_type'       => 'post',
            'map_meta_cap'          => true,
            'show_in_rest'          => false,
        ];
        register_post_type('wplm_customer', $customer_args);

        // Register Order Post Type
        $order_labels = [
            'name'                  => _x('Orders', 'Post Type General Name', 'wp-license-manager'),
            'singular_name'         => _x('Order', 'Post Type Singular Name', 'wp-license-manager'),
            'menu_name'             => __('Orders', 'wp-license-manager'),
            'name_admin_bar'        => __('Order', 'wp-license-manager'),
            'add_new_item'          => __('Add New Order', 'wp-license-manager'),
            'add_new'               => __('Add New', 'wp-license-manager'),
            'new_item'              => __('New Order', 'wp-license-manager'),
            'edit_item'             => __('Edit Order', 'wp-license-manager'),
            'update_item'           => __('Update Order', 'wp-license-manager'),
            'view_item'             => __('View Order', 'wp-license-manager'),
            'search_items'          => __('Search Orders', 'wp-license-manager'),
            'not_found'             => __('No orders found', 'wp-license-manager'),
            'not_found_in_trash'    => __('No orders found in Trash', 'wp-license-manager'),
            'all_items'             => __('All Orders', 'wp-license-manager'),
        ];
        $order_args = [
            'label'                 => __('Order', 'wp-license-manager'),
            'description'           => __('Customer Orders', 'wp-license-manager'),
            'labels'                => $order_labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            // Place Orders under Licenses native CPT menu
            'show_in_menu'          => $show_native ? 'edit.php?post_type=wplm_license' : false,
            'menu_position'         => 22,
            'show_in_admin_bar'     => $show_native ? true : false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            // Use standard post capabilities so admins/editor roles can see and edit
            'capability_type'       => 'post',
            'map_meta_cap'          => true,
            'show_in_rest'          => false,
        ];
        register_post_type('wplm_order', $order_args);

        // Register Activity Log Post Type
        $activity_labels = [
            'name'                  => _x('Activity Logs', 'Post Type General Name', 'wp-license-manager'),
            'singular_name'         => _x('Activity Log', 'Post Type Singular Name', 'wp-license-manager'),
            'menu_name'             => __('Activity Logs', 'wp-license-manager'),
            'name_admin_bar'        => __('Activity Log', 'wp-license-manager'),
            'add_new_item'          => __('Add New Log', 'wp-license-manager'),
            'add_new'               => __('Add New', 'wp-license-manager'),
            'new_item'              => __('New Log', 'wp-license-manager'),
            'edit_item'             => __('Edit Log', 'wp-license-manager'),
            'update_item'           => __('Update Log', 'wp-license-manager'),
            'view_item'             => __('View Log', 'wp-license-manager'),
            'search_items'          => __('Search Logs', 'wp-license-manager'),
            'not_found'             => __('No logs found', 'wp-license-manager'),
            'not_found_in_trash'    => __('No logs found in Trash', 'wp-license-manager'),
            'all_items'             => __('All Logs', 'wp-license-manager'),
        ];
        $activity_args = [
            'label'                 => __('Activity Log', 'wp-license-manager'),
            'description'           => __('License Activity Logs', 'wp-license-manager'),
            'labels'                => $activity_labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => false, // Hide from main menu, use only in dashboard
            'show_in_menu'          => false,
            'show_in_admin_bar'     => false,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'wplm_license', // Reuse license capabilities
            'map_meta_cap'          => true,
            'show_in_rest'          => false,
        ];
        register_post_type('wplm_activity_log', $activity_args);
    }

    /**
     * Handle WPLM product slug conflicts
     * This ensures WPLM products use the same slug as WooCommerce products
     */
    public function handle_wplm_product_slug($slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug) {
        if ($post_type !== 'wplm_product') {
            return $slug;
        }

        // If this is a new post (no ID yet), check for existing products with the same slug
        if ($post_ID === 0) {
            // Check if there's already a WPLM product with this slug
            $existing_product = get_posts([
                'post_type' => 'wplm_product',
                'name' => $original_slug,
                'posts_per_page' => 1,
                'post_status' => 'publish'
            ]);

            if (!empty($existing_product)) {
                // Use the existing slug without adding "-2"
                return $original_slug;
            }
        }

        return $slug;
    }
    
    /**
     * Add bulk actions for custom post types.
     */
    public function add_bulk_actions($bulk_actions) {
        // Ensure default WordPress bulk actions are available
        if (!isset($bulk_actions['trash'])) {
            $bulk_actions['trash'] = __('Move to Trash', 'wp-license-manager');
        }
        if (!isset($bulk_actions['delete'])) {
            $bulk_actions['delete'] = __('Delete Permanently', 'wp-license-manager');
        }
        return $bulk_actions;
    }
}
