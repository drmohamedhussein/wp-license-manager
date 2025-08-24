<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI utilities for WP License Manager
 */

/**
 * Generate a standardized license key
 * Format: XXXX-XXXX-XXXX-XXXX-XXXX (20 characters + 4 dashes = 24 total)
 */
function wplm_generate_standard_license_key(): string {
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
 * Generate a new license post programmatically.
 *
 * @param string $product_id
 * @param int $activation_limit
 * @param string $customer_email
 * @param string $duration_type
 * @param int $duration_value
 * @param string $current_version
 * @param string $expiry_date Optional. A direct expiry date (YYYY-MM-DD) to use instead of calculating from duration.
 * @return string Generated license key
 */
function wplm_generate_license( string $product_id, int $activation_limit = 1, string $customer_email = '', string $duration_type = 'lifetime', int $duration_value = 1, string $current_version = '1.0.0', string $expiry_date = '' ): string {
    // Validate product ID
    if (empty($product_id)) {
        throw new RuntimeException(esc_html__('Product ID cannot be empty.', 'wplm'));
    }

    // Validate customer email
    if (!empty($customer_email) && !is_email($customer_email)) {
        throw new RuntimeException(esc_html__('Invalid customer email address.', 'wplm'));
    }

    // Validate activation limit
    if ($activation_limit < 1) {
        throw new RuntimeException(esc_html__('Activation limit must be at least 1.', 'wplm'));
    }

    // Validate duration type and value
    $allowed_duration_types = ['lifetime', 'days', 'months', 'years'];
    if (!in_array($duration_type, $allowed_duration_types, true)) {
        throw new RuntimeException(sprintf(esc_html__('Invalid duration type: %s. Allowed types are: %s', 'wplm'), $duration_type, implode(', ', $allowed_duration_types)));
    }
    if ($duration_type !== 'lifetime' && $duration_value < 1) {
        throw new RuntimeException(esc_html__('Duration value must be at least 1 for non-lifetime licenses.', 'wplm'));
    }

    // Validate expiry date format if provided
    if (!empty($expiry_date) && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expiry_date)) {
        throw new RuntimeException(esc_html__('Expiry date must be in YYYY-MM-DD format.', 'wplm'));
    }

    // Generate a standardized unique key (format: XXXX-XXXX-XXXX-XXXX-XXXX)
    $key = wplm_generate_standard_license_key();
    
    // Ensure uniqueness by checking existing titles
    $attempts = 0;
    while ( $attempts < 5 ) {
        $existing_license = new WP_Query([
            'post_type'      => 'wplm_license',
            'posts_per_page' => 1,
            'title'          => $key,
            'fields'         => 'ids',
            'exact'          => true,
        ]);
        if ( !$existing_license->have_posts() ) {
            break;
        }
        $attempts++;
        $key = wplm_generate_standard_license_key();
    }

    if ( $attempts === 5 ) {
        throw new RuntimeException( esc_html__('Failed to generate a unique license key after multiple attempts.', 'wplm') );
    }

    $post_id = wp_insert_post([
        'post_title'  => $key,
        'post_type'   => 'wplm_license',
        'post_status' => 'publish',
    ], true);

    if ( is_wp_error( $post_id ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to create license: %s', 'wplm'), $post_id->get_error_message()) );
    }

    if ( false === update_post_meta( $post_id, '_wplm_product_id', sanitize_text_field( $product_id ) ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to save product ID for license %s.', 'wplm'), $key) );
    }
    if ( false === update_post_meta( $post_id, '_wplm_product_type', 'wplm' ) ) { // Set product type for CLI generated licenses
        throw new RuntimeException( sprintf(esc_html__('Failed to save product type for license %s.', 'wplm'), $key) );
    }
    if ( false === update_post_meta( $post_id, '_wplm_status', 'active' ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to set status for license %s.', 'wplm'), $key) );
    }
    if ( false === update_post_meta( $post_id, '_wplm_customer_email', sanitize_email( $customer_email ) ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to save customer email for license %s.', 'wplm'), $key) );
    }
    if ( false === update_post_meta( $post_id, '_wplm_activation_limit', intval( $activation_limit ) ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to save activation limit for license %s.', 'wplm'), $key) );
    }
    
    // Handle duration system
    if ( false === update_post_meta( $post_id, '_wplm_duration_type', sanitize_text_field( $duration_type ) ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to save duration type for license %s.', 'wplm'), $key) );
    }
    if ( false === update_post_meta( $post_id, '_wplm_duration_value', intval( $duration_value ) ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to save duration value for license %s.', 'wplm'), $key) );
    }
    
    // Calculate expiry date based on duration or use provided expiry date
    if ( ! empty( $expiry_date ) ) {
        if ( false === update_post_meta( $post_id, '_wplm_expiry_date', sanitize_text_field( $expiry_date ) ) ) {
            throw new RuntimeException( sprintf(esc_html__('Failed to save expiry date for license %s.', 'wplm'), $key) );
        }
    } elseif ( $duration_type === 'lifetime' ) {
        if ( false === update_post_meta( $post_id, '_wplm_expiry_date', '' ) ) {
            throw new RuntimeException( sprintf(esc_html__('Failed to set lifetime expiry for license %s.', 'wplm'), $key) );
        }
    } else {
        $calculated_expiry_date = wplm_calculate_expiry_date( $duration_type, $duration_value );
        if (is_wp_error($calculated_expiry_date)) {
            throw new RuntimeException( sprintf(esc_html__('Failed to calculate expiry date for license %1$s: %2$s', 'wplm'), $key, $calculated_expiry_date->get_error_message()) );
        }
        if ( false === update_post_meta( $post_id, '_wplm_expiry_date', $calculated_expiry_date ) ) {
            throw new RuntimeException( sprintf(esc_html__('Failed to save calculated expiry date for license %s.', 'wplm'), $key) );
        }
    }
    
    if ( false === update_post_meta( $post_id, '_wplm_activated_domains', [] ) ) {
        throw new RuntimeException( sprintf(esc_html__('Failed to initialize activated domains for license %s.', 'wplm'), $key) );
    }
    if ( false === update_post_meta( $post_id, '_wplm_current_version', sanitize_text_field( $current_version ) ) ) { // Save current version
        throw new RuntimeException( sprintf(esc_html__('Failed to save current version for license %s.', 'wplm'), $key) );
    }

    return $key;
}

/**
 * Calculate expiry date based on duration type and value
 */
function wplm_calculate_expiry_date($duration_type, $duration_value) {
    $current_time = current_time('Y-m-d');
    
    switch ($duration_type) {
        case 'days':
            $timestamp = strtotime($current_time . ' + ' . absint($duration_value) . ' days');
            break;
        case 'months':
            $timestamp = strtotime($current_time . ' + ' . absint($duration_value) . ' months');
            break;
        case 'years':
            $timestamp = strtotime($current_time . ' + ' . absint($duration_value) . ' years');
            break;
        default:
            return ''; // Lifetime or invalid type
    }

    if (false === $timestamp) {
        return new WP_Error('date_calculation_failed', esc_html__('Failed to calculate expiry date.', 'wplm'));
    }

    return date('Y-m-d', $timestamp);
}

// WP-CLI command registration
if ( defined('WP_CLI') && WP_CLI ) {
    WP_CLI::add_command( 'wplm license generate', function( $args, $assoc ) {
        $product_id = $assoc['product'] ?? 'my-awesome-plugin';
        $limit = isset($assoc['limit']) ? intval($assoc['limit']) : 1;
        $email = $assoc['email'] ?? '';
        $expiry_date = $assoc['expiry'] ?? ''; // Direct expiry date
        $duration_type = $assoc['duration_type'] ?? 'lifetime'; // New parameter
        $duration_value = isset($assoc['duration_value']) ? intval($assoc['duration_value']) : 1; // New parameter
        $version = $assoc['version'] ?? '1.0.0';

        try {
            $key = wplm_generate_license( $product_id, $limit, $email, $duration_type, $duration_value, $version, $expiry_date );
            WP_CLI::success( sprintf( esc_html__('Generated license: %s', 'wplm'), $key ) );
        } catch ( Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }, [
        'shortdesc' => esc_html__('Generate a new license key.', 'wplm'),
        'synopsis' => [
            [
                'type'        => 'assoc',
                'name'        => 'product',
                'description' => esc_html__('The product slug for which to generate the license. Example: my-awesome-plugin', 'wplm'),
                'optional'    => true,
                'default'     => 'my-awesome-plugin',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'limit',
                'description' => esc_html__('The activation limit for the license. Must be a positive integer.', 'wplm'),
                'optional'    => true,
                'default'     => 1,
            ],
            [
                'type'        => 'assoc',
                'name'        => 'email',
                'description' => esc_html__('The customer email for the license. Must be a valid email address.', 'wplm'),
                'optional'    => true,
                'default'     => '',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'expiry',
                'description' => esc_html__('The expiry date for the license (YYYY-MM-DD). Leave blank for duration-based or lifetime. Example: 2025-12-31', 'wplm'),
                'optional'    => true,
                'default'     => '',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'duration_type',
                'description' => esc_html__('The duration type (e.g., day, month, year, lifetime). Only used if expiry is not set. Values: day, month, year, lifetime.', 'wplm'),
                'optional'    => true,
                'default'     => 'lifetime',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'duration_value',
                'description' => esc_html__('The duration value (e.g., 30 for 30 days). Only used if expiry is not set and duration_type is not lifetime. Must be a positive integer.', 'wplm'),
                'optional'    => true,
                'default'     => 1,
            ],
            [
                'type'        => 'assoc',
                'name'        => 'version',
                'description' => esc_html__('The product version for the license. Example: 1.0.0', 'wplm'),
                'optional'    => true,
                'default'     => '1.0.0',
            ],
        ],
    ]);
}
