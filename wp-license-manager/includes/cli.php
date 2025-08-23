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
        throw new RuntimeException( 'Failed to generate a unique license key after multiple attempts.' );
    }

    $post_id = wp_insert_post([
        'post_title'  => $key,
        'post_type'   => 'wplm_license',
        'post_status' => 'publish',
    ], true);

    if ( is_wp_error( $post_id ) ) {
        throw new RuntimeException( 'Failed to create license: ' . $post_id->get_error_message() );
    }

    update_post_meta( $post_id, '_wplm_product_id', sanitize_text_field( $product_id ) );
    update_post_meta( $post_id, '_wplm_product_type', 'wplm' ); // Set product type for CLI generated licenses
    update_post_meta( $post_id, '_wplm_status', 'active' );
    update_post_meta( $post_id, '_wplm_customer_email', sanitize_email( $customer_email ) );
    update_post_meta( $post_id, '_wplm_activation_limit', intval( $activation_limit ) );
    
    // Handle duration system
    update_post_meta( $post_id, '_wplm_duration_type', sanitize_text_field( $duration_type ) );
    update_post_meta( $post_id, '_wplm_duration_value', intval( $duration_value ) );
    
    // Calculate expiry date based on duration or use provided expiry date
    if ( ! empty( $expiry_date ) ) {
        update_post_meta( $post_id, '_wplm_expiry_date', sanitize_text_field( $expiry_date ) );
    } elseif ( $duration_type === 'lifetime' ) {
        update_post_meta( $post_id, '_wplm_expiry_date', '' );
    } else {
        $calculated_expiry_date = wplm_calculate_expiry_date( $duration_type, $duration_value );
        update_post_meta( $post_id, '_wplm_expiry_date', $calculated_expiry_date );
    }
    
    update_post_meta( $post_id, '_wplm_activated_domains', [] );
    update_post_meta( $post_id, '_wplm_current_version', sanitize_text_field( $current_version ) ); // Save current version

    return $key;
}

/**
 * Calculate expiry date based on duration type and value
 */
function wplm_calculate_expiry_date($duration_type, $duration_value) {
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
            WP_CLI::success( "Generated license: $key" );
        } catch ( Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }, [
        'shortdesc' => 'Generate a new license key.',
        'synopsis' => [
            [
                'type'        => 'assoc',
                'name'        => 'product',
                'description' => 'The product slug for which to generate the license.',
                'optional'    => true,
                'default'     => 'my-awesome-plugin',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'limit',
                'description' => 'The activation limit for the license.',
                'optional'    => true,
                'default'     => 1,
            ],
            [
                'type'        => 'assoc',
                'name'        => 'email',
                'description' => 'The customer email for the license.',
                'optional'    => true,
                'default'     => '',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'expiry',
                'description' => 'The expiry date for the license (YYYY-MM-DD). Leave blank for duration-based or lifetime.',
                'optional'    => true,
                'default'     => '',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'duration_type',
                'description' => 'The duration type (e.g., days, months, years, lifetime). Only used if expiry is not set.',
                'optional'    => true,
                'default'     => 'lifetime',
            ],
            [
                'type'        => 'assoc',
                'name'        => 'duration_value',
                'description' => 'The duration value (e.g., 30 for 30 days). Only used if expiry is not set and duration_type is not lifetime.',
                'optional'    => true,
                'default'     => 1,
            ],
            [
                'type'        => 'assoc',
                'name'        => 'version',
                'description' => 'The product version for the license.',
                'optional'    => true,
                'default'     => '1.0.0',
            ],
        ],
    ]);
}
