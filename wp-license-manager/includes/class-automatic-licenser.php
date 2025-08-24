<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the Automatic Licenser System for WPLM.
 */
class WPLM_Automatic_Licenser {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_wplm_upload_product_zip', [ $this, 'ajax_upload_product_zip' ] );
        add_action( 'wp_ajax_wplm_process_product_zip', [ $this, 'ajax_process_product_zip' ] );
        add_action( 'wp_ajax_wplm_download_licensed_product', [ $this, 'ajax_download_licensed_product' ] );
    }

    /**
     * Add admin menu for Automatic Licenser.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wplm-dashboard',
            __( 'Automatic Licenser', 'wp-license-manager' ),
            __( 'Automatic Licenser', 'wp-license-manager' ),
            'manage_options',
            'wplm-automatic-licenser',
            [ $this, 'render_automatic_licenser_page' ]
        );
    }

    /**
     * Enqueue admin scripts for the Automatic Licenser page.
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'wplm-automatic-licenser' ) === false ) {
            return;
        }

        wp_enqueue_script( 'wplm-automatic-licenser', plugin_dir_url( WPLM_PLUGIN_FILE ) . 'assets/js/automatic-licenser.js', [ 'jquery' ], WPLM_VERSION, true );
        wp_localize_script( 'wplm-automatic-licenser', 'wplm_licenser', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wplm_automatic_licenser_nonce' ),
            'strings'  => [
                'uploading'          => __( 'Uploading...', 'wp-license-manager' ),
                'processing'         => __( 'Processing...', 'wp-license-manager' ),
                'downloading'        => __( 'Downloading...', 'wp-license-manager' ),
                'select_file'        => __( 'Please select a zip file to upload.', 'wp-license-manager' ),
                'invalid_file_type'  => __( 'Invalid file type. Please upload a .zip file.', 'wp-license-manager' ),
                'upload_error'       => __( 'Error uploading file.', 'wp-license-manager' ),
                'process_error'      => __( 'Error processing file.', 'wp-license-manager' ),
                'generic_error'      => __( 'An unexpected error occurred. Please try again.', 'wp-license-manager' ),
                'file_ready'         => __( 'File processed successfully! Ready for download.', 'wp-license-manager' ),
            ]
        ] );
    }

    /**
     * Render the Automatic Licenser admin page.
     */
    public function render_automatic_licenser_page() {
        ?>
        <div class="wrap wplm-automatic-licenser">
            <h1><?php _e( 'Automatic Licenser System', 'wp-license-manager' ); ?></h1>
            <p><?php _e( 'Upload a plugin/theme ZIP file, and WPLM will automatically inject licensing code and provide a licensed version for download.', 'wp-license-manager' ); ?></p>

            <div class="wplm-licenser-form-container wplm-card">
                <h2><?php _e( 'Upload Product ZIP', 'wp-license-manager' ); ?></h2>
                <form id="wplm-licenser-upload-form" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'wplm_automatic_licenser_nonce', 'wplm_licenser_nonce' ); ?>
                    <input type="file" id="wplm-product-zip" name="wplm_product_zip" accept=".zip" required />
                    <button type="submit" class="button button-primary" id="wplm-upload-button"><?php _e( 'Upload & Process', 'wp-license-manager' ); ?></button>
                </form>
                <div id="wplm-upload-progress" class="wplm-progress-bar" style="display: none;"><div class="wplm-progress-fill"></div></div>
                <div id="wplm-upload-message" class="wplm-notice"></div>
            </div>

            <div id="wplm-licenser-results" class="wplm-licenser-results-container wplm-card" style="display: none;">
                <h2><?php _e( 'Licensed Product Ready', 'wp-license-manager' ); ?></h2>
                <p id="wplm-download-message"></p>
                <button type="button" class="button button-primary" id="wplm-download-button"><?php _e( 'Download Licensed Product', 'wp-license-manager' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for uploading product ZIP file.
     */
    public function ajax_upload_product_zip() {
        check_ajax_referer( 'wplm_automatic_licenser_nonce', 'wplm_licenser_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-license-manager' ) ] );
            return;
        }

        if ( ! isset( $_FILES['wplm_product_zip'] ) || empty( $_FILES['wplm_product_zip']['name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'wp-license-manager' ) ] );
            return;
        }

        $file = $_FILES['wplm_product_zip'];

        // Enhanced file validation
        if ( ! $this->validate_uploaded_file( $file ) ) {
            wp_send_json_error( [ 'message' => __( 'File validation failed. Please check the file and try again.', 'wp-license-manager' ) ] );
            return;
        }

        // Validate file type
        $file_info = wp_check_filetype( $file['name'] );
        if ( 'zip' !== $file_info['ext'] ) {
            wp_send_json_error( [ 'message' => __( 'Invalid file type. Please upload a .zip file.', 'wp-license-manager' ) ] );
            return;
        }

        // Check file size (limit to 50MB)
        $max_file_size = 50 * 1024 * 1024; // 50MB
        if ( $file['size'] > $max_file_size ) {
            wp_send_json_error( [ 'message' => __( 'File size too large. Maximum allowed size is 50MB.', 'wp-license-manager' ) ] );
            return;
        }

        try {
            $upload_dir = wp_upload_dir();
            $temp_dir   = $this->get_secure_temp_directory( $upload_dir['basedir'] );
            
            if ( ! $temp_dir ) {
                wp_send_json_error( [ 'message' => __( 'Could not create secure temporary directory.', 'wp-license-manager' ) ] );
                return;
            }

            $temp_zip_path = $temp_dir . '/' . $this->generate_secure_filename( 'wplm_product_' ) . '.zip';

            if ( move_uploaded_file( $file['tmp_name'], $temp_zip_path ) ) {
                // Verify the uploaded file is actually a valid ZIP
                if ( ! $this->verify_zip_file( $temp_zip_path ) ) {
                    $this->cleanup_temp_files( $temp_zip_path );
                    wp_send_json_error( [ 'message' => __( 'Uploaded file is not a valid ZIP archive.', 'wp-license-manager' ) ] );
                    return;
                }

                // Store the path in a transient for later processing
                set_transient( 'wplm_processing_zip_' . get_current_user_id(), $temp_zip_path, HOUR_IN_SECONDS );
                
                // Log successful upload
                if ( class_exists( 'WPLM_Activity_Logger' ) ) {
                    WPLM_Activity_Logger::log(
                        0,
                        'product_zip_uploaded',
                        sprintf( 'Product ZIP file uploaded successfully: %s', basename( $file['name'] ) ),
                        [
                            'filename' => $file['name'],
                            'filesize' => $file['size'],
                            'user_id' => get_current_user_id()
                        ]
                    );
                }
                
                wp_send_json_success( [ 'message' => __( 'File uploaded successfully. Processing...', 'wp-license-manager' ) ] );
            } else {
                wp_send_json_error( [ 'message' => __( 'Failed to move uploaded file.', 'wp-license-manager' ) ] );
            }
            
        } catch ( Exception $e ) {
            error_log( 'WPLM Error: Exception in ajax_upload_product_zip: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'wp-license-manager' ) ] );
        }
    }

    /**
     * AJAX handler for processing the uploaded product ZIP file (extract, inject, re-zip).
     */
    public function ajax_process_product_zip() {
        check_ajax_referer( 'wplm_automatic_licenser_nonce', 'wplm_licenser_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-license-manager' ) ] );
            return;
        }

        $temp_zip_path = get_transient( 'wplm_processing_zip_' . get_current_user_id() );

        if ( ! $temp_zip_path || ! file_exists( $temp_zip_path ) ) {
            wp_send_json_error( [ 'message' => __( 'No ZIP file found for processing.', 'wp-license-manager' ) ] );
            return;
        }

        $upload_dir  = wp_upload_dir();
        $extract_dir = $upload_dir['basedir'] . '/wplm-temp-licenser/extracted_' . uniqid() . '/';
        $output_dir  = $upload_dir['basedir'] . '/wplm-temp-licenser/licensed_output/';
        if ( ! wp_mkdir_p( $extract_dir ) || ! wp_mkdir_p( $output_dir ) ) {
            wp_send_json_error( [ 'message' => __( 'Could not create output directories.', 'wp-license-manager' ) ] );
            return;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $temp_zip_path ) === true ) {
            $zip->extractTo( $extract_dir );
            $zip->close();
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to open or extract ZIP file.', 'wp-license-manager' ) ] );
            return;
        }

        // Identify the main plugin/theme directory inside the extracted contents
        $dirs = array_filter( glob( $extract_dir . '*' ), 'is_dir' );
        $main_product_path = '';
        if ( count( $dirs ) === 1 ) {
            $main_product_path = reset( $dirs );
        } else {
            // Try to find a plugin file (e.g., has a /* Plugin Name: */ header)
            foreach ( $dirs as $dir ) {
                $files = glob( $dir . '/*.php' );
                foreach ( $files as $file ) {
                    $file_contents = file_get_contents( $file );
                    if ( preg_match( '/Plugin Name:|Theme Name:/', $file_contents ) ) {
                        $main_product_path = $dir;
                        break 2;
                    }
                }
            }
        }
        
        if ( empty( $main_product_path ) ) {
            wp_send_json_error( [ 'message' => __( 'Could not identify main plugin/theme directory.', 'wp-license-manager' ) ] );
            $this->cleanup_temp_files( $temp_zip_path, $extract_dir );
            return;
        }

        // --- Licensing Code Injection Logic ---
        $injection_result = $this->inject_licensing_code( $main_product_path );
        if ( is_wp_error( $injection_result ) ) {
            wp_send_json_error( [ 'message' => $injection_result->get_error_message() ] );
            $this->cleanup_temp_files( $temp_zip_path, $extract_dir );
            return;
        }

        $modified_product_name = basename( $main_product_path );
        $output_zip_name     = $modified_product_name . '-licensed.zip';
        $output_zip_path     = $output_dir . $output_zip_name;

        $new_zip = new ZipArchive();
        if ( $new_zip->open( $output_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
            $this->add_folder_to_zip( $main_product_path, $new_zip, $modified_product_name );
            $new_zip->close();
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to create licensed ZIP file.', 'wp-license-manager' ) ] );
            $this->cleanup_temp_files( $temp_zip_path, $extract_dir );
            return;
        }

        // Clean up temporary extracted files, but keep the uploaded original and new licensed zip for a while
        $this->cleanup_temp_files( $temp_zip_path, $extract_dir );

        // Store the path of the generated licensed ZIP for download
        set_transient( 'wplm_licensed_product_path_' . get_current_user_id(), $output_zip_path, HOUR_IN_SECONDS );

        wp_send_json_success( [ 
            'message'    => __( 'Product processed and licensed successfully!', 'wp-license-manager' ),
            'download_url' => add_query_arg( ['action' => 'wplm_download_licensed_product', 'nonce' => wp_create_nonce('wplm_automatic_licenser_nonce')], admin_url('admin-ajax.php') )
        ] );
    }

    /**
     * Helper function to add a folder and its contents to a ZipArchive.
     */
    private function add_folder_to_zip( $folder_path, $zip_archive, $base_in_zip ) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $folder_path ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $files as $name => $file ) {
            // Skip directories (they would be added automatically when adding files)
            if ( ! $file->isDir() ) {
                $file_path     = $file->getRealPath();
                $relative_path = $base_in_zip . '/' . substr( $file_path, strlen( $folder_path ) + 1 );

                $zip_archive->addFile( $file_path, $relative_path );
            }
        }
    }

    /**
     * AJAX handler for downloading the licensed product.
     */
    public function ajax_download_licensed_product() {
        check_ajax_referer( 'wplm_automatic_licenser_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'wp-license-manager' ) );
        }

        $licensed_product_path = get_transient( 'wplm_licensed_product_path_' . get_current_user_id() );

        if ( ! $licensed_product_path || ! file_exists( $licensed_product_path ) ) {
            wp_die( __( 'Licensed product not found or expired. Please re-process.', 'wp-license-manager' ) );
        }

        $file_name = basename( $licensed_product_path );

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
        header( 'Content-Length: ' . filesize( $licensed_product_path ) );
        readfile( $licensed_product_path );

        // Clean up after download
        unlink( $licensed_product_path );
        delete_transient( 'wplm_licensed_product_path_' . get_current_user_id() );

        exit;
    }

    /**
     * Helper to clean up temporary files and directories.
     */
    private function cleanup_temp_files( $zip_path, $extract_path = null ) {
        if ( file_exists( $zip_path ) ) {
            unlink( $zip_path );
        }
        if ( $extract_path && is_dir( $extract_path ) ) {
            $this->rmdir_recursive( $extract_path );
        }
        // Optionally clean up the main temp directory if empty
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/wplm-temp-licenser/';
        if ( is_dir( $temp_dir ) && count( scandir( $temp_dir ) ) <= 2 ) { // . and ..
            rmdir( $temp_dir );
        }
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function rmdir_recursive( $dir ) {
        if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() );
            }
        }
        rmdir( $dir );
    }

    /**
     * Injects licensing code into the main plugin/theme file.
     * Enhanced implementation with comprehensive error handling and security.
     *
     * @param string $main_product_path The path to the main plugin/theme directory.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function inject_licensing_code( $main_product_path ) {
        try {
            // Validate and sanitize the path
            $main_product_path = $this->sanitize_path( $main_product_path );
            if ( ! $this->is_valid_product_path( $main_product_path ) ) {
                return new WP_Error( 'wplm_invalid_path', __( 'Invalid product path provided.', 'wp-license-manager' ) );
            }

            // Find the main plugin/theme file
            $main_file = $this->find_main_product_file( $main_product_path );
            if ( ! $main_file ) {
                return new WP_Error( 'wplm_no_main_file', __( 'Could not find the main plugin or theme file to inject licensing code.', 'wp-license-manager' ) );
            }

            // Extract product information
            $product_info = $this->extract_product_info( $main_file );
            if ( is_wp_error( $product_info ) ) {
                return $product_info;
            }

            // Generate and inject licensing client code
            $client_injection_result = $this->inject_licensing_client( $main_product_path, $product_info );
            if ( is_wp_error( $client_injection_result ) ) {
                return $client_injection_result;
            }

            // Inject licensing code into main file
            $main_file_injection_result = $this->inject_into_main_file( $main_file, $product_info );
            if ( is_wp_error( $main_file_injection_result ) ) {
                return $main_file_injection_result;
            }

            // Log successful injection
            if ( class_exists( 'WPLM_Activity_Logger' ) ) {
                WPLM_Activity_Logger::log(
                    0,
                    'licensing_code_injected',
                    sprintf( 'Licensing code successfully injected into %s: %s', $product_info['type'], $product_info['name'] ),
                    [
                        'product_path' => $main_product_path,
                        'product_name' => $product_info['name'],
                        'product_slug' => $product_info['slug'],
                        'product_type' => $product_info['type']
                    ]
                );
            }

            return true;

        } catch ( Exception $e ) {
            error_log( 'WPLM Error: Exception in inject_licensing_code: ' . $e->getMessage() );
            return new WP_Error( 'wplm_injection_exception', __( 'An unexpected error occurred during code injection.', 'wp-license-manager' ) );
        }
    }

    /**
     * Validate uploaded file for security
     */
    private function validate_uploaded_file( $file ) {
        // Check for upload errors
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            error_log( 'WPLM Error: File upload error: ' . $file['error'] );
            return false;
        }

        // Check if file was uploaded via HTTP POST
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            error_log( 'WPLM Error: File was not uploaded via HTTP POST' );
            return false;
        }

        // Validate file name
        $filename = sanitize_file_name( $file['name'] );
        if ( empty( $filename ) || $filename !== $file['name'] ) {
            error_log( 'WPLM Error: Invalid filename detected' );
            return false;
        }

        // Check for potentially dangerous file extensions
        $dangerous_extensions = [ 'php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'bat', 'cmd', 'sh' ];
        $file_extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( in_array( $file_extension, $dangerous_extensions ) ) {
            error_log( 'WPLM Error: Dangerous file extension detected: ' . $file_extension );
            return false;
        }

        return true;
    }

    /**
     * Get secure temporary directory
     */
    private function get_secure_temp_directory( $base_dir ) {
        $temp_dir = $base_dir . '/wplm-temp-licenser/' . uniqid( 'wplm_' ) . '/';
        
        if ( ! wp_mkdir_p( $temp_dir ) ) {
            return false;
        }

        // Set restrictive permissions
        chmod( $temp_dir, 0755 );

        // Create .htaccess to prevent direct access
        $htaccess_content = "Order deny,allow\nDeny from all";
        file_put_contents( $temp_dir . '.htaccess', $htaccess_content );

        return $temp_dir;
    }

    /**
     * Generate secure filename
     */
    private function generate_secure_filename( $prefix ) {
        return $prefix . wp_generate_password( 16, false );
    }

    /**
     * Verify ZIP file integrity
     */
    private function verify_zip_file( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return false;
        }

        // Check for potentially dangerous files inside ZIP
        $dangerous_files = [ '.php', '.php3', '.php4', '.php5', '.phtml', '.exe', '.bat', '.cmd', '.sh' ];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $filename = $zip->getNameIndex( $i );
            foreach ( $dangerous_files as $dangerous_ext ) {
                if ( strpos( $filename, $dangerous_ext ) !== false ) {
                    $zip->close();
                    return false;
                }
            }
        }

        $zip->close();
        return true;
    }

    /**
     * Sanitize file path
     */
    private function sanitize_path( $path ) {
        $path = realpath( $path );
        if ( $path === false ) {
            return false;
        }

        // Ensure path is within allowed directories
        $upload_dir = wp_upload_dir();
        $allowed_base = realpath( $upload_dir['basedir'] );
        
        if ( strpos( $path, $allowed_base ) !== 0 ) {
            return false;
        }

        return $path;
    }

    /**
     * Validate product path
     */
    private function is_valid_product_path( $path ) {
        if ( ! $path || ! is_dir( $path ) ) {
            return false;
        }

        // Check if directory contains PHP files
        $php_files = glob( $path . '/*.php' );
        return ! empty( $php_files );
    }

    /**
     * Find main product file
     */
    private function find_main_product_file( $product_path ) {
        $files = glob( $product_path . '/*.php' );
        foreach ( $files as $file ) {
            $file_contents = file_get_contents( $file );
            if ( preg_match( '/^\s*\/\*.*?Plugin Name:.*?\*\/|^\s*\/\*.*?Theme Name:.*?\*\//ims', $file_contents ) ) {
                return $file;
            }
        }
        return false;
    }

    /**
     * Extract product information
     */
    private function extract_product_info( $main_file ) {
        if ( ! file_exists( $main_file ) ) {
            return new WP_Error( 'wplm_file_not_found', __( 'Main product file not found.', 'wp-license-manager' ) );
        }

        $plugin_data = get_file_data( $main_file, [
            'Plugin Name' => 'Plugin Name',
            'Theme Name'  => 'Theme Name',
        ] );

        $product_name = $plugin_data['Plugin Name'] ?: $plugin_data['Theme Name'];
        if ( empty( $product_name ) ) {
            return new WP_Error( 'wplm_no_product_name', __( 'Could not extract product name from file headers.', 'wp-license-manager' ) );
        }

        $product_slug = sanitize_title( $product_name );
        $product_type = ! empty( $plugin_data['Plugin Name'] ) ? 'plugin' : 'theme';

        return [
            'name' => $product_name,
            'slug' => $product_slug,
            'type' => $product_type,
            'file' => $main_file
        ];
    }

    /**
     * Inject licensing client code
     */
    private function inject_licensing_client( $product_path, $product_info ) {
        // Get WPLM API URL from settings
        $wplm_api_url = get_option( 'wplm_api_url', home_url( '/wp-json/wplm/v2/' ) );

        // Try to load template from multiple locations
        $template_locations = [
            WPLM_PLUGIN_DIR . 'templates/wplm-licensing-client-template.php',
            WPLM_PLUGIN_DIR . 'includes/wplm-license-client-template.php',
            plugin_dir_path( __FILE__ ) . '../templates/wplm-licensing-client-template.php'
        ];

        $client_template_path = false;
        foreach ( $template_locations as $location ) {
            if ( file_exists( $location ) ) {
                $client_template_path = $location;
                break;
            }
        }

        if ( ! $client_template_path ) {
            // Create a basic template if none exists
            $client_template_path = $this->create_basic_licensing_template( $product_path );
            if ( ! $client_template_path ) {
                return new WP_Error( 'wplm_template_creation_failed', __( 'Failed to create licensing template.', 'wp-license-manager' ) );
            }
        }

        $client_code = file_get_contents( $client_template_path );
        $client_code = str_replace( '{{WPLM_API_URL}}', esc_url( $wplm_api_url ), $client_code );
        $client_code = str_replace( '{{WPLM_PRODUCT_SLUG}}', esc_attr( $product_info['slug'] ), $client_code );
        $client_code = str_replace( '{{WPLM_PRODUCT_NAME}}', esc_attr( $product_info['name'] ), $client_code );
        
        $client_file_name = 'wplm-licensing-client.php';
        $client_file_path = $product_path . '/' . $client_file_name;
        
        if ( ! file_put_contents( $client_file_path, $client_code ) ) {
            return new WP_Error( 'wplm_template_write_failed', __( 'Failed to write licensing client file.', 'wp-license-manager' ) );
        }

        return true;
    }

    /**
     * Create basic licensing template if none exists
     */
    private function create_basic_licensing_template( $product_path ) {
        $template_content = '<?php
/**
 * WPLM Licensing Client - Auto-generated
 * This file was automatically generated by the WPLM Automatic Licenser
 */

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

// Basic licensing check
function wplm_validate_license( $license_key ) {
    $api_url = "{{WPLM_API_URL}}";
    $product_slug = "{{WPLM_PRODUCT_SLUG}}";
    
    $response = wp_remote_post( $api_url . "validate", [
        "body" => [
            "license_key" => $license_key,
            "domain" => $_SERVER["HTTP_HOST"],
            "product_slug" => $product_slug
        ]
    ] );
    
    if ( is_wp_error( $response ) ) {
        return false;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    return isset( $data["success"] ) && $data["success"] === true;
}

// Initialize licensing
function wplm_fs_init() {
    // Add your licensing logic here
    add_action( "admin_notices", "wplm_admin_notice" );
}

function wplm_admin_notice() {
    echo "<div class=\"notice notice-info\"><p>This product is licensed through WPLM.</p></div>";
}
';

        $template_path = $product_path . '/wplm-licensing-client-template.php';
        if ( file_put_contents( $template_path, $template_content ) ) {
            return $template_path;
        }

        return false;
    }

    /**
     * Inject licensing code into main file
     */
    private function inject_into_main_file( $main_file, $product_info ) {
        $main_file_contents = file_get_contents( $main_file );
        if ( $main_file_contents === false ) {
            return new WP_Error( 'wplm_file_read_failed', __( 'Failed to read main product file.', 'wp-license-manager' ) );
        }

        $injection_point = '<?php';
        $client_file_name = 'wplm-licensing-client.php';
        
        // Create injection code
        $injection_code = "\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nrequire_once __DIR__ . '/{$client_file_name}';\nadd_action( 'plugins_loaded', 'wplm_fs_init' );\n";

        // Check if already injected
        if ( strpos( $main_file_contents, $client_file_name ) !== false ) {
            return true; // Already injected
        }

        // Inject the code
        $new_contents = str_replace( $injection_point, $injection_point . $injection_code, $main_file_contents );
        
        if ( ! file_put_contents( $main_file, $new_contents ) ) {
            return new WP_Error( 'wplm_main_file_write_failed', __( 'Failed to inject code into main product file.', 'wp-license-manager' ) );
        }

        return true;
    }
}
