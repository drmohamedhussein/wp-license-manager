<?php
/**
 * WP License Manager Uninstall Script
 *
 * This file is executed when the plugin is uninstalled.
 * It ensures that all plugin data is removed from the database based on user settings.
 *
 * @package WP_License_Manager
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Ensure WordPress functions are available.
if (!function_exists('get_option')) {
    require_once ABSPATH . 'wp-load.php';
}

// Ensure the main plugin file is loaded to access WP_License_Manager::uninstall()
require_once plugin_dir_path(__FILE__) . 'wp-license-manager.php';

// Call the static uninstall method from the main plugin class
if (class_exists('WP_License_Manager')) {
    WP_License_Manager::uninstall();
}