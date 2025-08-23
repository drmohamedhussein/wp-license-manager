<?php
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automated Licenser System for WPLM
 * Allows users to upload a plugin/theme zip, inject licensing logic, and generate a licensed version.
 */
class WPLM_Automated_Licenser {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_wplm_licenser_upload_and_process', [$this, 'ajax_upload_and_process']);
        add_action('wplm_licenser_cleanup_downloads', [$this, 'cleanup_old_downloads']);

        if (!wp_next_scheduled('wplm_licenser_cleanup_downloads')) {
            wp_schedule_event(time(), 'daily', 'wplm_licenser_cleanup_downloads');
        }
    }

    /**
     * Add admin menu for the Automated Licenser System
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wplm-dashboard',
            __('Automated Licenser', 'wp-license-manager'),
            __('Automated Licenser', 'wp-license-manager'),
            'manage_options',
            'wplm-automated-licenser',
            [$this, 'render_licenser_page']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wplm-automated-licenser') === false) {
            return;
        }

        wp_enqueue_script('wplm-automated-licenser', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/automated-licenser.js', ['jquery'], WPLM_VERSION, true);
        wp_localize_script('wplm-automated-licenser', 'wplm_licenser', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplm_licenser_nonce'),
            'strings' => [
                'uploading' => __('Uploading and processing...', 'wp-license-manager'),
                'error' => __('An error occurred.', 'wp-license-manager'),
                'success' => __('Successfully generated licensed file.', 'wp-license-manager'),
                'invalid_file' => __('Please upload a valid zip file.', 'wp-license-manager'),
                'download_licensed_file' => __('Download Licensed File', 'wp-license-manager'),
                'invalid_item_id' => __('Please enter a valid WPLM Product ID.', 'wp-license-manager'),
            ]
        ]);
    }

    /**
     * Render the Automated Licenser System page
     */
    public function render_licenser_page() {
        ?>
        <div class="wrap wplm-automated-licenser">
            <h1><?php _e('Automated Licenser System', 'wp-license-manager'); ?></h1>
            <p><?php _e('Upload your plugin or theme as a zip file to automatically inject WPLM licensing logic.', 'wp-license-manager'); ?></p>

            <div class="wplm-licenser-form-section">
                <form id="wplm-licenser-upload-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('wplm_licenser_nonce', '_wplm_licenser_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="wplm_licenser_zip_file"><?php _e('Upload Plugin/Theme Zip', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="file" id="wplm_licenser_zip_file" name="wplm_licenser_zip_file" accept=".zip" required />
                                <p class="description"><?php _e('Select the zip file of your plugin or theme.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplm_licenser_item_id"><?php _e('WPLM Product ID', 'wp-license-manager'); ?></label></th>
                            <td>
                                <input type="number" id="wplm_licenser_item_id" name="wplm_licenser_item_id" required class="small-text" value="" min="1" />
                                <p class="description"><?php _e('Enter the WPLM Product ID for this plugin/theme.', 'wp-license-manager'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="wplm-licenser-submit-button"><?php _e('Upload and Process', 'wp-license-manager'); ?></button>
                    </p>
                </form>
                <div id="wplm-licenser-feedback" class="notice" style="display:none;"></div>
                <div id="wplm-licenser-progress" class="wplm-progress-bar" style="display:none;">
                    <div class="wplm-progress-fill" style="width: 0%;"></div>
                </div>
            </div>

            <div id="wplm-licenser-results" style="margin-top: 20px; display:none;">
                <h2><?php _e('Processing Results', 'wp-license-manager'); ?></h2>
                <div id="wplm-licenser-details"></div>
                <p id="wplm-licenser-download-link"></p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for uploading and processing the zip file.
     */
    public function ajax_upload_and_process() {
        check_ajax_referer('wplm_licenser_nonce', '_wplm_licenser_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        if (empty($_FILES['wplm_licenser_zip_file']['tmp_name'])) {
            wp_send_json_error(['message' => __('Please upload a valid zip file.', 'wp-license-manager')]);
        }

        $item_id = isset($_POST['wplm_licenser_item_id']) ? absint($_POST['wplm_licenser_item_id']) : 0;

        // The file array will already be validated by empty check for 'tmp_name'
        // and other checks will ensure all necessary keys are present.
        $file = $_FILES['wplm_licenser_zip_file'];
        
        // Check for general upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('File upload error: ', 'wp-license-manager') . $file['error']]);
        }

        if (empty($item_id)) {
            wp_send_json_error(['message' => __('Invalid WPLM Product ID provided.', 'wp-license-manager')]);
        }

        $zip_path = $file['tmp_name'];
        $zip_name = sanitize_file_name($file['name']);

        // Validate file type (basic check)
        if ('application/zip' !== $file['type'] && 'application/x-zip-compressed' !== $file['type']) {
            wp_send_json_error(['message' => __('Please upload a valid zip file.', 'wp-license-manager')]);
        }

        // File size validation
        $max_file_size = defined('WPLM_MAX_UPLOAD_SIZE') ? WPLM_MAX_UPLOAD_SIZE : wp_max_upload_size();
        if ($file['size'] > $max_file_size) {
            wp_send_json_error(['message' => sprintf(__('Uploaded file exceeds the maximum allowed size of %s.', 'wp-license-manager'), size_format($max_file_size))]);
        }

        // Define a temporary directory for extraction
        $extraction_path = self::get_temp_dir();

        if (!$extraction_path) {
            wp_send_json_error(['message' => __('Failed to create temporary directory for extraction.', 'wp-license-manager')]);
        }

        $zip = new ZipArchive;
        if ($zip->open($zip_path) === TRUE) {
            // Validate each file path in the zip to prevent Zip Slip vulnerability
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $filePath = $extraction_path . '/' . $filename;
                // Sanitize and normalize path for security
                $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
                $extraction_path_sanitized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $extraction_path);

                if (strpos(realpath($filePath), realpath($extraction_path_sanitized)) !== 0) {
                    $zip->close();
                    self::delete_dir($extraction_path);
                    wp_send_json_error(['message' => __('Zip file contains malicious paths. Aborting.', 'wp-license-manager')]);
                }
            }

            // All paths are safe, proceed with extraction
            if (!$zip->extractTo($extraction_path)) {
                $zip->close();
                self::delete_dir($extraction_path);
                wp_send_json_error(['message' => __('Failed to extract zip file.', 'wp-license-manager')]);
            }
            $zip->close();

            $main_file_info = self::find_main_plugin_or_theme_file($extraction_path);
            $main_file_path = $main_file_info['file'];
            $product_type = $main_file_info['type'];
            $product_data = [];

            if (empty($main_file_path)) {
                self::delete_dir($extraction_path);
                wp_send_json_error(['message' => __('Could not find a main plugin or theme file in the zip.', 'wp-license-manager')]);
            }

            if ('plugin' === $product_type) {
                $product_data = self::parse_plugin_data($main_file_path);
            } elseif ('theme' === $product_type) {
                $product_data = self::parse_theme_data($main_file_path);
            }

            if (empty($product_data) || empty($product_data['Name']) || empty($product_data['Version'])) {
                self::delete_dir($extraction_path);
                wp_send_json_error(['message' => __('Could not parse valid plugin/theme data.', 'wp-license-manager')]);
            }

            // 3. Inject licensing code into the main file.
            $licensing_code_template = self::get_licensing_code_template($product_data, $item_id, $product_type);

            if (!file_exists($main_file_path) || !is_readable($main_file_path)) {
                error_log(sprintf(esc_html__('WPLM_Automated_Licenser: Main file not found or not readable: %s', 'wplm'), $main_file_path));
                self::delete_dir($extraction_path); // Clean up extracted files
                wp_send_json_error(['message' => __('Could not read the main plugin/theme file.', 'wp-license-manager')]);
            }
            $file_contents = file_get_contents($main_file_path);

            // Find a suitable place to inject. After the header comments is ideal.
            if ('plugin' === $product_type) {
                // For plugins, inject after plugin header. Try to find '*/' closing tag after 'Plugin Name:'
                if (preg_match('/(\/\*.*?\*\/)\s*(if\s*\(!\s*defined\(\'ABSPATH\'\)\)\s*\{)?/ims', $file_contents, $matches, PREG_OFFSET_CAPTURE)) {
                    // If it matches the typical plugin structure, inject after the closing tag of the header block
                    $injection_point = $matches[0][1] + strlen($matches[0][0]);
                    $file_contents = substr_replace($file_contents, $licensing_code_template, $injection_point, 0);
                } elseif (preg_match('/^\s*<\?php/s', $file_contents)) {
                    // If only <?php is present, inject after it
                    $injection_point = strpos($file_contents, '?php') + 4;
                    $file_contents = substr_replace($file_contents, "\n" . $licensing_code_template, $injection_point, 0);
                } else {
                    // Fallback: prepend if no clear injection point found (less ideal)
                    $file_contents = $licensing_code_template . $file_contents;
                }
            } elseif ('theme' === $product_type) {
                // We are targeting the main PHP file, which is more appropriate for logic injection.
                if (preg_match('/^\s*<\?php/s', $file_contents)) {
                    // If only <?php is present, inject after it
                    $injection_point = strpos($file_contents, '?php') + 4;
                    $file_contents = substr_replace($file_contents, "\n" . $licensing_code_template, $injection_point, 0);
                } else {
                    // Fallback: prepend if no clear injection point found
                    $file_contents = $licensing_code_template . $file_contents;
                }
            }

            file_put_contents($main_file_path, $file_contents);

            // 4. Create a new update handler file.
            $update_handler_code = self::get_update_handler_code($product_data, $product_type);
            $update_handler_file_name = 'wplm-updater.php';
            $update_handler_path = dirname($main_file_path) . '/' . $update_handler_file_name;
            file_put_contents($update_handler_path, $update_handler_code);

            // 5. Re-zip the modified contents.
            $licensed_zip_name = sanitize_title($product_data['Name']) . '-licensed-v' . str_replace('.', '-', $product_data['Version']) . '.zip';
            $licensed_zip_path = $extraction_path . '/' . $licensed_zip_name;

            if (!self::create_zip($main_file_info['root_dir'], $licensed_zip_path)) {
                self::delete_dir($extraction_path);
                wp_send_json_error(['message' => __('Failed to create licensed zip file.', 'wp-license-manager')]);
            }

            // --- End Placeholder ---
            
            // Move the licensed zip to a publicly accessible, temporary download directory
            $downloads_dir = self::get_downloads_dir();
            if (!$downloads_dir) {
                self::delete_dir($extraction_path);
                wp_send_json_error(['message' => __('Failed to create download directory.', 'wp-license-manager')]);
            }

            $final_licensed_zip_path = $downloads_dir . '/' . basename($licensed_zip_path);
            
            if (!rename($licensed_zip_path, $final_licensed_zip_path)) {
                self::delete_dir($extraction_path);
                wp_send_json_error(['message' => __('Failed to move licensed file for download.', 'wp-license-manager')]);
            }

            $licensed_download_url = str_replace(wp_upload_dir()['basedir'], wp_upload_dir()['baseurl'], $final_licensed_zip_path);
            
            // Clean up extraction directory immediately
            self::delete_dir($extraction_path);
            
            wp_send_json_success([
                'message' => wplm_licenser.strings.success,
                'download_url' => $licensed_download_url,
                'details' => sprintf(__('Successfully processed %s (%s - %s). Download your licensed file.', 'wp-license-manager'), $product_data['Name'], $product_type, $product_data['Version'])
            ]);

        } else {
            // If zip->open fails, no extraction_path to delete as it was never created/used for extraction
            wp_send_json_error(['message' => __('Failed to open zip file for processing.', 'wp-license-manager')]);
        }
    }

    private static function find_main_plugin_or_theme_file($extraction_path) {
        $main_file = '';
        $type = '';
        $root_dir = $extraction_path; // Initialize root_dir

        $files_and_dirs = scandir($extraction_path);
        $top_level_content = array_diff($files_and_dirs, array('.', '..'));

        $potential_roots = [];
        foreach ($top_level_content as $name) {
            $full_path = $extraction_path . '/' . $name;
            if (is_dir($full_path)) {
                $potential_roots[] = $full_path;
            } elseif (is_file($full_path) && pathinfo($full_path, PATHINFO_EXTENSION) === 'php') {
                // Handle case where plugin/theme files are directly in the root of the zip
                $potential_roots[] = $extraction_path;
                break; // Found a PHP file, assume root is extraction path
            }
        }
        
        // If no subdirectories or direct PHP files, the root is the extraction path itself.
        if (empty($potential_roots)) {
            $potential_roots[] = $extraction_path;
        }

        foreach ($potential_roots as $root_dir) {
            // Check for plugin header in PHP files
            $php_files = glob($root_dir . '/*.php');
            foreach ($php_files as $file) {
                $file_contents = file_get_contents($file);
                if (preg_match('/^[\s]*<\?php.*?Plugin Name:.*$/ims', $file_contents)) {
                    $main_file = $file;
                    $type = 'plugin';
                    break 2; // Found plugin, break both loops
                }
            }

            // If not a plugin, check for theme header in style.css
            $style_css = $root_dir . '/style.css';
            if (file_exists($style_css)) {
                $file_contents = file_get_contents($style_css);
                if (preg_match('/^[\s\t\/*#@]*Theme Name:.*$/ims', $file_contents)) {
                    // For themes, the main file for injection should ideally be functions.php or a core PHP file.
                    // We'll use style.css to detect, but try to find functions.php or the first PHP file for injection.
                    $main_file = $root_dir . '/functions.php';
                    if (!file_exists($main_file)) {
                        $theme_php_files = glob($root_dir . '/*.php');
                        if (!empty($theme_php_files)) {
                            $main_file = $theme_php_files[0]; // Take the first PHP file as a fallback
                        } else {
                            $main_file = $style_css; // Fallback to style.css if no PHP file found
                        }
                    }
                    $type = 'theme';
                    break; // Found theme, break root_dir loop
                }
            }
        }

        return ['file' => $main_file, 'type' => $type, 'root_dir' => $root_dir];
    }

    /**
     * Parses plugin data from a plugin file.
     *
     * @param string $plugin_file_path The path to the main plugin file.
     * @return array An associative array of plugin data.
     */
    private static function parse_plugin_data($plugin_file_path) {
        if (!file_exists($plugin_file_path)) {
            return [];
        }

        $default_headers = [
            'Name' => 'Plugin Name',
            'PluginURI' => 'Plugin URI',
            'Version' => 'Version',
            'Description' => 'Description',
            'Author' => 'Author',
            'AuthorURI' => 'Author URI',
            'TextDomain' => 'Text Domain',
            'DomainPath' => 'Domain Path',
            'Network' => 'Network',
            '_sitewide' => 'Site Wide Only',
        ];

        $plugin_data = get_file_data($plugin_file_path, $default_headers, 'plugin');
        
        // Add slug if not already present
        if (empty($plugin_data['slug'])) {
            $plugin_data['slug'] = basename(dirname($plugin_file_path));
        }

        return $plugin_data;
    }

    /**
     * Parses theme data from a theme style.css file.
     *
     * @param string $theme_style_file_path The path to the theme's style.css file.
     * @return array An associative array of theme data.
     */
    private static function parse_theme_data($theme_style_file_path) {
        if (!file_exists($theme_style_file_path)) {
            return [];
        }

        $default_headers = [
            'Name' => 'Theme Name',
            'ThemeURI' => 'Theme URI',
            'Description' => 'Description',
            'Author' => 'Author',
            'AuthorURI' => 'Author URI',
            'Version' => 'Version',
            'Template' => 'Template',
            'Status' => 'Status',
            'Tags' => 'Tags',
            'TextDomain' => 'Text Domain',
            'DomainPath' => 'Domain Path',
        ];

        $theme_data = get_file_data($theme_style_file_path, $default_headers, 'theme');
        
        // Add slug if not already present
        if (empty($theme_data['slug'])) {
            $theme_data['slug'] = basename(dirname($theme_style_file_path));
        }

        return $theme_data;
    }

    /**
     * Returns the licensing code template to be injected.
     *
     * @param array $product_data The parsed product data (Name, Version, Slug).
     * @param string $item_id The unique ID of the product in WPLM.
     * @param string $product_type The type of product ('plugin' or 'theme').
     * @return string The PHP code for licensing.
     */
    private static function get_licensing_code_template($product_data, $item_id, $product_type) {
        $wplm_api_url = get_option('wplm_api_url', '');
        $product_slug = sanitize_title($product_data['Name']);
        $product_version = $product_data['Version'];

        ob_start();
        ?>
<?php
// WPLM Automated Licensing Integration - Do not remove or modify
if (!defined('ABSPATH')) exit; // Exit if accessed directly

if (!class_exists('WPLM_License_Client')) {
    // Moved to wplm-license-client-template.php
}

// Get product data (Name) to use in add_options_page title
// This requires re-parsing the file or passing it down from the main licenser
// For now, let's assume we can get this from the plugin header if it's a plugin
// Or from the theme data. A more robust solution might pass more data.
$product_data = [
    'Name' => '',
    'Version' => '',
    'slug' => $product_slug_for_client, // This needs to be correctly passed
];

if ('plugin' === $product_type) {
    $plugin_headers = get_file_data(__FILE__, ['Name' => 'Plugin Name', 'Version' => 'Version'], 'plugin');
    $product_data['Name'] = $plugin_headers['Name'];
    $product_data['Version'] = $plugin_headers['Version'];
} elseif ('theme' === $product_type) {
    $theme_headers = get_file_data(get_stylesheet_directory() . '/style.css', ['Name' => 'Theme Name', 'Version' => 'Version'], 'theme');
    $product_data['Name'] = $theme_headers['Name'];
    $product_data['Version'] = $theme_headers['Version'];
}

$GLOBALS['wplm_client_product_data_' . $product_slug_for_client] = $product_data;

// Instantiate the client
// Ensure $product_slug_for_client is defined and unique for each injected client.
// This might require a unique variable name based on the plugin/theme being licensed.
$injected_file = ('plugin' === $product_type) ? __FILE__ : '';
$wplm_license_client_instance_name = 'wplm_client_' . $product_slug_for_client . '_license_client';
$GLOBALS[$wplm_license_client_instance_name] = new WPLM_License_Client($injected_file);

?>
        <?php
        return ob_get_clean();
    }

    /**
     * Returns the update handler code template to be injected.
     *
     * @param array $product_data The parsed product data.
     * @param string $product_type The type of product ('plugin' or 'theme').
     * @return string The PHP code for the update handler.
     */
    private static function get_update_handler_code($product_data, $product_type) {
        ob_start();
        ?>
<?php
// WPLM Automated Update Handler - Do not remove or modify
if (!defined('ABSPATH')) exit; // Exit if accessed directly

// This file will be loaded by the main plugin/theme file to handle updates.
// It can be extended to include more advanced update logic.

// These constants are defined to be used by the WPLM_License_Client in the main plugin/theme file.
// They provide necessary information for the licensing and update checks.

if (!defined('WPLM_CLIENT_PRODUCT_SLUG')) {
    define('WPLM_CLIENT_PRODUCT_SLUG', '' . esc_attr($product_data['slug']) . '');
}
if (!defined('WPLM_CLIENT_PRODUCT_VERSION')) {
    define('WPLM_CLIENT_PRODUCT_VERSION', '' . esc_attr($product_data['Version']) . '');
}
if (!defined('WPLM_CLIENT_PRODUCT_NAME')) {
    define('WPLM_CLIENT_PRODUCT_NAME', '' . esc_attr($product_data['Name']) . '');
}
if (!defined('WPLM_CLIENT_PRODUCT_TYPE')) {
    define('WPLM_CLIENT_PRODUCT_TYPE', '' . esc_attr($product_type) . '');
}
if (!defined('WPLM_CLIENT_ITEM_ID')) {
    define('WPLM_CLIENT_ITEM_ID', '' . esc_attr($item_id) . '');
}

// Ensure this update handler is included early enough in the main plugin/theme file
// for the constants to be available before the WPLM_License_Client is instantiated.

?>
        <?php
        return ob_get_clean();
    }

    /**
     * Creates a zip file from a given directory.
     *
     * @param string $source_dir The directory to zip.
     * @param string $output_zip_path The path where the zip file will be created.
     * @return bool True on success, false on failure.
     */
    private static function create_zip($source_dir, $output_zip_path) {
        $zip = new ZipArchive();
        if ($zip->open($output_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return false;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relativePath = str_replace($source_dir . '/', '', $file_path);
                $zip->addFile($file_path, $relativePath);
            }
        }

        return $zip->close();
    }

    /**
     * Helper to recursively delete a directory.
     *
     * @param string $dir The directory path.
     */
    private static function delete_dir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delete_dir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * Generates a unique temporary directory path.
     *
     * @return string The path to the temporary directory.
     */
    private static function get_temp_dir() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/wplm-licenser-temp/' . uniqid('wplm_licenser_');
        if (!wp_mkdir_p($temp_dir)) {
            // Fallback to system temp if WordPress upload dir fails
            $temp_dir = sys_get_temp_dir() . '/wplm-licenser-temp/' . uniqid('wplm_licenser_');
            if (!wp_mkdir_p($temp_dir)) {
                // If even system temp fails, return false
                return false;
            }
        }
        return $temp_dir;
    }

    /**
     * Generates a unique temporary directory for downloads.
     *
     * @return string The path to the temporary download directory.
     */
    private static function get_downloads_dir() {
        $upload_dir = wp_upload_dir();
        $downloads_dir = $upload_dir['basedir'] . '/wplm-licenser-downloads';
        if (!wp_mkdir_p($downloads_dir)) {
            return false;
        }
        return $downloads_dir;
    }

    /**
     * Cleans up old licensed files from the download directory.
     */
    public function cleanup_old_downloads() {
        $downloads_dir = self::get_downloads_dir();
        if (!$downloads_dir || !is_dir($downloads_dir)) {
            return;
        }

        $retention_days = 1; // Keep files for 1 day
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);

        $files = scandir($downloads_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $file_path = $downloads_dir . '/' . $file;
            if (is_file($file_path) && filemtime($file_path) < $cutoff_time) {
                unlink($file_path);
            }
        }
    }

    /**
     * Recursively lists all files within a given directory.
     *
     * @param string $directory The directory path.
     * @return array An array of file paths.
     */
    private static function list_files_in_directory($directory) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $files[] = $fileinfo->getRealPath();
            }
        }
        return $files;
    }
}
