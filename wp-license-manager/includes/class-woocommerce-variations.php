<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles WooCommerce Variable Product Variations licensing options
 */
class WPLM_WooCommerce_Variations {

    public function __construct() {
        // Add variation fields
        add_action('woocommerce_variation_options_pricing', [$this, 'add_variation_license_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_license_fields'], 10, 2);
        
        // Add license duration attributes
        add_filter('woocommerce_product_data_tabs', [$this, 'add_license_attributes_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'add_license_attributes_panel']);
    }

    /**
     * Add license-specific fields to product variations
     */
    public function add_variation_license_fields($loop, $variation_data, $variation) {
        $variation_id = $variation->ID;
        $parent_id = wp_get_post_parent_id($variation_id);
        
        // Check if parent product is licensed
        $is_parent_licensed = get_post_meta($parent_id, '_wplm_wc_is_licensed_product', true);
        if ($is_parent_licensed !== 'yes') {
            return;
        }

        echo '<div class="options_group wplm-variation-options">';
        echo '<h4>' . __('License Settings', 'wp-license-manager') . '</h4>';
        
        // License duration override
        $duration_type = get_post_meta($variation_id, '_wplm_variation_duration_type', true);
        $duration_value = get_post_meta($variation_id, '_wplm_variation_duration_value', true);
        $activation_limit = get_post_meta($variation_id, '_wplm_variation_activation_limit', true);
        
        // Duration Type
        echo '<p class="form-field">';
        echo '<label for="_wplm_variation_duration_type_' . $loop . '">' . __('License Duration', 'wp-license-manager') . '</label>';
        echo '<select id="_wplm_variation_duration_type_' . $loop . '" name="_wplm_variation_duration_type[' . $loop . ']" style="width: 120px;">';
        echo '<option value="">' . __('Use Default', 'wp-license-manager') . '</option>';
        echo '<option value="lifetime"' . selected($duration_type, 'lifetime', false) . '>' . __('Lifetime', 'wp-license-manager') . '</option>';
        echo '<option value="days"' . selected($duration_type, 'days', false) . '>' . __('Days', 'wp-license-manager') . '</option>';
        echo '<option value="months"' . selected($duration_type, 'months', false) . '>' . __('Months', 'wp-license-manager') . '</option>';
        echo '<option value="years"' . selected($duration_type, 'years', false) . '>' . __('Years', 'wp-license-manager') . '</option>';
        echo '</select>';
        echo '<input type="number" id="_wplm_variation_duration_value_' . $loop . '" name="_wplm_variation_duration_value[' . $loop . ']" value="' . esc_attr($duration_value) . '" min="1" style="width: 80px; margin-left: 10px;" placeholder="1" />';
        echo '</p>';
        
        // Activation Limit
        echo '<p class="form-field">';
        echo '<label for="_wplm_variation_activation_limit_' . $loop . '">' . __('Activation Limit', 'wp-license-manager') . '</label>';
        echo '<input type="number" id="_wplm_variation_activation_limit_' . $loop . '" name="_wplm_variation_activation_limit[' . $loop . ']" value="' . esc_attr($activation_limit) . '" min="1" style="width: 80px;" placeholder="1" />';
        echo '<span class="description">' . __('Number of sites this license can be activated on. Leave empty to use default (1).', 'wp-license-manager') . '</span>';
        echo '</p>';
        
        echo '</div>';
    }

    /**
     * Save variation license fields
     */
    public function save_variation_license_fields($variation_id, $loop) {
        // Duration settings
        if (isset($_POST['_wplm_variation_duration_type'][$loop])) {
            $duration_type = sanitize_text_field($_POST['_wplm_variation_duration_type'][$loop]);
            update_post_meta($variation_id, '_wplm_variation_duration_type', $duration_type);
        }
        
        if (isset($_POST['_wplm_variation_duration_value'][$loop])) {
            $duration_value = intval($_POST['_wplm_variation_duration_value'][$loop]);
            update_post_meta($variation_id, '_wplm_variation_duration_value', $duration_value);
        }
        
        if (isset($_POST['_wplm_variation_activation_limit'][$loop])) {
            $activation_limit = intval($_POST['_wplm_variation_activation_limit'][$loop]);
            update_post_meta($variation_id, '_wplm_variation_activation_limit', $activation_limit);
        }
    }

    /**
     * Add license attributes tab to product data
     */
    public function add_license_attributes_tab($tabs) {
        $tabs['license_attributes'] = [
            'label'    => __('License Attributes', 'wp-license-manager'),
            'target'   => 'license_attributes_product_data',
            'class'    => ['show_if_variable'],
            'priority' => 80,
        ];
        return $tabs;
    }

    /**
     * Add license attributes panel
     */
    public function add_license_attributes_panel() {
        global $post;
        
        echo '<div id="license_attributes_product_data" class="panel woocommerce_options_panel">';
        echo '<div class="options_group">';
        
        echo '<h3>' . __('License Duration Attributes', 'wp-license-manager') . '</h3>';
        echo '<p>' . __('Create attributes for license duration to use in variations. These will help customers choose their license terms.', 'wp-license-manager') . '</p>';
        
        echo '<div class="wplm-suggested-attributes">';
        echo '<h4>' . __('Suggested Attributes', 'wp-license-manager') . '</h4>';
        
        echo '<div class="attribute-suggestion">';
        echo '<strong>' . __('Duration Attribute:', 'wp-license-manager') . '</strong><br>';
        echo __('Name: "Duration" | Terms: "1 Year", "Lifetime"', 'wp-license-manager') . '<br>';
        echo '<em>' . __('Use this to let customers choose license duration', 'wp-license-manager') . '</em>';
        echo '</div>';
        
        echo '<div class="attribute-suggestion" style="margin-top: 15px;">';
        echo '<strong>' . __('Sites Attribute:', 'wp-license-manager') . '</strong><br>';
        echo __('Name: "Sites" | Terms: "1 Site", "5 Sites", "Unlimited"', 'wp-license-manager') . '<br>';
        echo '<em>' . __('Use this to let customers choose activation limits', 'wp-license-manager') . '</em>';
        echo '</div>';
        
        echo '<div style="margin-top: 20px; padding: 10px; background: #f0f8ff; border-left: 4px solid #0073aa;">';
        echo '<strong>' . __('How to Setup:', 'wp-license-manager') . '</strong><br>';
        echo '1. ' . __('Go to Products → Attributes and create the attributes above', 'wp-license-manager') . '<br>';
        echo '2. ' . __('Add these attributes to your variable product', 'wp-license-manager') . '<br>';
        echo '3. ' . __('Create variations using these attributes', 'wp-license-manager') . '<br>';
        echo '4. ' . __('Configure license settings for each variation', 'wp-license-manager');
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Get license settings for a specific variation
     */
    public static function get_variation_license_settings($variation_id, $parent_id = null) {
        if (!$parent_id) {
            $parent_id = wp_get_post_parent_id($variation_id);
        }
        
        // Get variation-specific settings
        $duration_type = get_post_meta($variation_id, '_wplm_variation_duration_type', true);
        $duration_value = get_post_meta($variation_id, '_wplm_variation_duration_value', true);
        $activation_limit = get_post_meta($variation_id, '_wplm_variation_activation_limit', true);
        
        // Use parent defaults if variation settings are empty
        if (empty($duration_type)) {
            $duration_type = get_post_meta($parent_id, '_wplm_wc_default_duration_type', true) ?: 'lifetime';
        }
        if (empty($duration_value) && $duration_type !== 'lifetime') {
            $duration_value = get_post_meta($parent_id, '_wplm_wc_default_duration_value', true) ?: 1;
        }
        if (empty($activation_limit)) {
            $activation_limit = 1; // Default activation limit
        }
        
        return [
            'duration_type' => $duration_type,
            'duration_value' => intval($duration_value),
            'activation_limit' => intval($activation_limit)
        ];
    }
}
