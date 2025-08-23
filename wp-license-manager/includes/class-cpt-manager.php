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
        $license_args = [
            'label'                 => __('License', 'wp-license-manager'),
            'description'           => __('License Keys', 'wp-license-manager'),
            'labels'                => $license_labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 20,
            'menu_icon'             => 'dashicons-lock',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'wplm_license', // Use custom capability type
            'capabilities'          => [
                'edit_post'          => 'edit_wplm_license',
                'read_post'          => 'read_wplm_license',
                'delete_post'        => 'delete_wplm_license',
                'edit_posts'         => 'edit_wplm_licenses',
                'edit_others_posts'  => 'edit_others_wplm_licenses',
                'publish_posts'      => 'publish_wplm_licenses',
                'read_private_posts' => 'read_private_wplm_licenses',
                'create_posts'       => 'create_wplm_licenses',
            ],
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
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => 'edit.php?post_type=wplm_license', // Sub-menu of Licenses
            'capability_type'       => 'wplm_product', // Use custom capability type
            'capabilities'          => [
                'edit_post'          => 'edit_wplm_product',
                'read_post'          => 'read_wplm_product',
                'delete_post'        => 'delete_wplm_product',
                'edit_posts'         => 'edit_wplm_products',
                'edit_others_posts'  => 'edit_others_wplm_products',
                'publish_posts'      => 'publish_wplm_products',
                'read_private_posts' => 'read_private_wplm_products',
                'create_posts'       => 'create_wplm_products',
            ],
            'map_meta_cap'          => true, // Map WordPress meta capabilities
            'show_in_rest'          => false,
        ];
        register_post_type('wplm_product', $product_args);

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
            'show_in_menu'          => 'edit.php?post_type=wplm_license',
            'menu_position'         => 21,
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
        register_post_type('wplm_subscription', $subscription_args);

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
}
