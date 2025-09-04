<?php
/**
 * WPLM Licensing Client Template
 * This file is automatically injected into licensed products by the WPLM Automatic Licenser.
 * It handles license activation and validation with the WPLM server.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define the WPLM API URL and product slug - these are replaced during injection.
if ( ! defined( 'WPLM_CLIENT_API_URL' ) ) {
    define( 'WPLM_CLIENT_API_URL', '{{WPLM_API_URL}}' );
}
if ( ! defined( 'WPLM_CLIENT_PRODUCT_SLUG' ) ) {
    define( 'WPLM_CLIENT_PRODUCT_SLUG', '{{WPLM_PRODUCT_SLUG}}' );
}

// Main function to initialize licensing - this will be hooked by the injected plugin/theme
function wplm_fs_init() {
    if ( ! class_exists( 'WPLM_Licensing_Client' ) ) {
        class WPLM_Licensing_Client {

            private $product_slug;
            private $api_url;

            public function __construct() {
                $this->product_slug = WPLM_CLIENT_PRODUCT_SLUG;
                $this->api_url      = WPLM_CLIENT_API_URL;

                // Add admin notices for license status (only for admins on the specific plugin/theme screen)
                add_action( 'admin_notices', [ $this, 'admin_notices' ] );

                // Optionally add a settings link in the plugin list
                add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );
            }

            /**
             * Call the WPLM API.
             *
             * @param string $endpoint The API endpoint.
             * @param array $args The request arguments.
             * @return array|WP_Error The API response or WP_Error on failure.
             */
            private function call_api( $endpoint, $args = [] ) {
                $url = trailingslashit( $this->api_url ) . $endpoint;
                
                $default_args = [
                    'method'    => 'POST',
                    'timeout'   => 30,
                    'body'      => array_merge( $args, [
                        'product_slug' => $this->product_slug,
                        'domain'       => home_url(),
                    ] ),
                ];

                $response = wp_remote_post( $url, $default_args );

                if ( is_wp_error( $response ) ) {
                    return $response;
                }

                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );

                if ( ! $data || ! isset( $data['success'] ) ) {
                    return new WP_Error( 'wplm_api_error', __( 'Invalid API response.', 'wp-license-manager' ) );
                }

                return $data;
            }

            /**
             * Activate the license.
             *
             * @param string $license_key The license key to activate.
             * @return array|WP_Error API response or WP_Error on failure.
             */
            public function activate_license( $license_key ) {
                $response = $this->call_api( 'activate', [ 'license_key' => $license_key ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                if ( $response['success'] ) {
                    update_option( 'wplm_license_key_' . $this->product_slug, $license_key );
                    update_option( 'wplm_license_status_' . $this->product_slug, 'active' );
                    update_option( 'wplm_license_data_' . $this->product_slug, $response['data'] );
                } else {
                    delete_option( 'wplm_license_status_' . $this->product_slug );
                    delete_option( 'wplm_license_data_' . $this->product_slug );
                }
                return $response;
            }

            /**
             * Deactivate the license.
             *
             * @return array|WP_Error API response or WP_Error on failure.
             */
            public function deactivate_license() {
                $license_key = get_option( 'wplm_license_key_' . $this->product_slug, '' );
                if ( empty( $license_key ) ) {
                    return new WP_Error( 'wplm_no_license_key', __( 'No license key found to deactivate.', 'wp-license-manager' ) );
                }
                $response = $this->call_api( 'deactivate', [ 'license_key' => $license_key ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                if ( $response['success'] ) {
                    delete_option( 'wplm_license_key_' . $this->product_slug );
                    delete_option( 'wplm_license_status_' . $this->product_slug );
                    delete_option( 'wplm_license_data_' . $this->product_slug );
                }
                return $response;
            }

            /**
             * Validate the license.
             *
             * @return array|WP_Error API response or WP_Error on failure.
             */
            public function validate_license() {
                $license_key = get_option( 'wplm_license_key_' . $this->product_slug, '' );
                if ( empty( $license_key ) ) {
                    return new WP_Error( 'wplm_no_license_key', __( 'No license key found for validation.', 'wp-license-manager' ) );
                }
                $response = $this->call_api( 'validate', [ 'license_key' => $license_key ] );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                if ( $response['success'] && isset( $response['data']['status'] ) && $response['data']['status'] === 'active' ) {
                    update_option( 'wplm_license_status_' . $this->product_slug, 'active' );
                    update_option( 'wplm_license_data_' . $this->product_slug, $response['data'] );
                } else {
                    update_option( 'wplm_license_status_' . $this->product_slug, 'inactive' );
                    update_option( 'wplm_license_data_' . $this->product_slug, $response['data'] );
                }
                return $response;
            }

            /**
             * Display admin notices based on license status.
             */
            public function admin_notices() {
                // Only show notice on the specific plugin/theme admin page, or all admin pages if not active
                $screen = get_current_screen();
                $plugin_basename = plugin_basename( dirname( __FILE__ ) . '/' . $this->product_slug . '.php' ); // Adjust as needed
                $is_plugin_page = ( isset( $_GET['page'] ) && strpos( $_GET['page'], $this->product_slug ) !== false );

                if ( ! $is_plugin_page && $screen && $screen->id !== 'plugins' && $screen->id !== 'dashboard' ) {
                    return; // Only show on relevant pages or plugins list
                }

                $license_status = get_option( 'wplm_license_status_' . $this->product_slug, 'inactive' );
                $license_key = get_option( 'wplm_license_key_' . $this->product_slug, '' );

                $plugin_name = get_file_data( __FILE__, [ 'Plugin Name' => 'Plugin Name', 'Theme Name' => 'Theme Name' ] )['Plugin Name'] ?: WPLM_CLIENT_PRODUCT_SLUG;
                
                if ( 'active' !== $license_status ) {
                    $message = sprintf(
                        __( '%s is not licensed. Please <a href="%s">activate your license</a> to receive updates and support.', 'wp-license-manager' ),
                        esc_html( $plugin_name ),
                        esc_url( admin_url( 'admin.php?page=' . $this->product_slug . '-settings' ) ) // Placeholder for settings page
                    );
                    printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', $message );
                } else if ( 'active' === $license_status && empty( $license_key ) ) {
                    // This case should ideally not happen if activation is handled correctly
                    $message = sprintf(
                        __( '%s is active but the license key is missing. Please re-enter your license key.', 'wp-license-manager' ),
                        esc_html( $plugin_name )
                    );
                    printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', $message );
                }
            }

            /**
             * Add settings link to plugin actions.
             *
             * @param array $links Plugin action links.
             * @return array Modified plugin action links.
             */
            public function add_settings_link( $links ) {
                $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $this->product_slug . '-settings' ) ) . '">' . __( 'Settings', 'wp-license-manager' ) . '</a>';
                array_unshift( $links, $settings_link );
                return $links;
            }

        }

        // Initialize the client if it's not already loaded.
        new WPLM_Licensing_Client();
    }
}
