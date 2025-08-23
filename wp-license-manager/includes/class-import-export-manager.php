<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Import/Export Manager for WPLM
 */
class WPLM_Import_Export_Manager {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_import_export_actions']);
        add_action('wp_ajax_wplm_export_data', [$this, 'ajax_export_data']);
        add_action('wp_ajax_wplm_import_data', [$this, 'ajax_import_data']);
        add_action('wp_ajax_wplm_validate_import', [$this, 'ajax_validate_import']);
    }

    /**
     * Handle import/export actions
     */
    public function handle_import_export_actions() {
        if (!current_user_can('manage_wplm_licenses')) {
            return;
        }

        // Handle export
        if (isset($_POST['wplm_export_submit']) && wp_verify_nonce($_POST['wplm_export_nonce'], 'wplm_export_data')) {
            $this->handle_export();
        }

        // Handle import
        if (isset($_POST['wplm_import_submit']) && wp_verify_nonce($_POST['wplm_import_nonce'], 'wplm_import_data')) {
            $this->handle_import();
        }
    }

    /**
     * Handle export
     */
    private function handle_export() {
        $export_type = sanitize_text_field($_POST['wplm_export_type'] ?? 'all');
        $export_format = sanitize_text_field($_POST['wplm_export_format'] ?? 'csv');
        $include_settings = isset($_POST['wplm_include_settings']);
        $include_logs = isset($_POST['wplm_include_logs']);

        switch ($export_format) {
            case 'json':
                $this->export_json($export_type, $include_settings, $include_logs);
                break;
            case 'xml':
                $this->export_xml($export_type, $include_settings, $include_logs);
                break;
            default:
                $this->export_csv($export_type, $include_settings, $include_logs);
                break;
        }
    }

    /**
     * Export to CSV
     */
    private function export_csv($export_type, $include_settings, $include_logs) {
        // If exporting a single type, directly output CSV. Otherwise, create a ZIP archive.
        if (in_array($export_type, ['licenses', 'products', 'subscriptions', 'customers', 'settings', 'activity_logs'])) {
            $csv_content = '';
            $filename_prefix = 'wplm-' . $export_type;

            switch ($export_type) {
                case 'licenses':
                    $csv_content = $this->generate_licenses_csv();
                    break;
                case 'products':
                    $csv_content = $this->generate_products_csv();
                    break;
                case 'subscriptions':
                    $csv_content = $this->generate_subscriptions_csv();
                    break;
                case 'customers':
                    $csv_content = $this->generate_customers_csv();
                    break;
                case 'settings':
                    $csv_content = $this->generate_settings_csv();
                    break;
                case 'activity_logs':
                    $csv_content = $this->generate_activity_logs_csv();
                    break;
            }

            $filename = $filename_prefix . '-export-' . date('Y-m-d-H-i-s') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $csv_content;
            exit;
        } else { // 'all' or any other type will be a ZIP file
            if (!class_exists('ZipArchive')) {
                $message = __('ZipArchive class not found. Please enable the PHP Zip extension for export functionality.', 'wp-license-manager');
                error_log('WPLM Error: ' . $message);
                $this->add_import_notice($message, 'error'); // Using import notice for consistency
                return;
            }
            
            $zip = new ZipArchive();
            $temp_file = tempnam(sys_get_temp_dir(), 'wplm_export');
            
            if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                wp_die(__('Cannot create export file', 'wp-license-manager'));
            }

            // Export licenses
            if (in_array($export_type, ['all', 'licenses', 'licenses_and_products'])) {
                $licenses_csv = $this->generate_licenses_csv();
                $zip->addFromString('licenses.csv', $licenses_csv);
            }

            // Export products
            if (in_array($export_type, ['all', 'products', 'licenses_and_products'])) {
                $products_csv = $this->generate_products_csv();
                $zip->addFromString('products.csv', $products_csv);
            }

            // Export subscriptions
            if (in_array($export_type, ['all', 'subscriptions'])) {
                $subscriptions_csv = $this->generate_subscriptions_csv();
                $zip->addFromString('subscriptions.csv', $subscriptions_csv);
            }

            // Export customers
            if (in_array($export_type, ['all', 'customers'])) {
                $customers_csv = $this->generate_customers_csv();
                $zip->addFromString('customers.csv', $customers_csv);
            }

            // Export settings
            if ($include_settings) {
                $settings_csv = $this->generate_settings_csv();
                $zip->addFromString('settings.csv', $settings_csv);
            }

            // Export activity logs
            if ($include_logs) {
                $logs_csv = $this->generate_activity_logs_csv();
                $zip->addFromString('activity_logs.csv', $logs_csv);
            }

            // Add export metadata
            $metadata = $this->generate_export_metadata($export_type, $include_settings, $include_logs);
            $zip->addFromString('export_info.json', json_encode($metadata, JSON_PRETTY_PRINT));

            $zip->close();

            // Send file
            $filename = 'wplm-export-' . date('Y-m-d-H-i-s') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Content-Length: ' . filesize($temp_file));
            readfile($temp_file);
            unlink($temp_file);
            exit;
        }
    }

    /**
     * Export to JSON
     */
    private function export_json($export_type, $include_settings, $include_logs) {
        $export_data = [
            'export_info' => $this->generate_export_metadata($export_type, $include_settings, $include_logs),
            'data' => []
        ];

        // Export licenses
        if (in_array($export_type, ['all', 'licenses', 'licenses_and_products'])) {
            $export_data['data']['licenses'] = $this->get_licenses_data();
        }

        // Export products
        if (in_array($export_type, ['all', 'products', 'licenses_and_products'])) {
            $export_data['data']['products'] = $this->get_products_data();
        }

        // Export subscriptions
        if (in_array($export_type, ['all', 'subscriptions'])) {
            $export_data['data']['subscriptions'] = $this->get_subscriptions_data();
        }

        // Export customers
        if (in_array($export_type, ['all', 'customers'])) {
            $export_data['data']['customers'] = $this->get_customers_data();
        }

        // Export settings
        if ($include_settings) {
            $export_data['data']['settings'] = $this->get_settings_data();
        }

        // Export activity logs
        if ($include_logs) {
            $export_data['data']['activity_logs'] = $this->get_activity_logs_data();
        }

        $filename = 'wplm-export-' . date('Y-m-d-H-i-s') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Export to XML
     */
    private function export_xml($export_type, $include_settings, $include_logs) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        $root = $xml->createElement('wplm_export');
        $xml->appendChild($root);

        // Add metadata
        $metadata = $this->generate_export_metadata($export_type, $include_settings, $include_logs);
        $info_element = $xml->createElement('export_info');
        foreach ($metadata as $key => $value) {
            $element = $xml->createElement($key, htmlspecialchars($value));
            $info_element->appendChild($element);
        }
        $root->appendChild($info_element);

        // Export data
        $data_element = $xml->createElement('data');
        $root->appendChild($data_element);

        // Export licenses
        if (in_array($export_type, ['all', 'licenses', 'licenses_and_products'])) {
            $licenses_element = $xml->createElement('licenses');
            $licenses_data = $this->get_licenses_data();
            foreach ($licenses_data as $license) {
                $license_element = $xml->createElement('license');
                foreach ($license as $key => $value) {
                    $element = $xml->createElement($key, htmlspecialchars($value));
                    $license_element->appendChild($element);
                }
                $licenses_element->appendChild($license_element);
            }
            $data_element->appendChild($licenses_element);
        }

        // Export products
        if (in_array($export_type, ['all', 'products', 'licenses_and_products'])) {
            $products_element = $xml->createElement('products');
            $products_data = $this->get_products_data();
            foreach ($products_data as $product) {
                $product_element = $xml->createElement('product');
                foreach ($product as $key => $value) {
                    $element = $xml->createElement($key, htmlspecialchars(is_array($value) ? json_encode($value) : $value));
                    $product_element->appendChild($element);
                }
                $products_element->appendChild($product_element);
            }
            $data_element->appendChild($products_element);
        }

        // Export subscriptions
        if (in_array($export_type, ['all', 'subscriptions'])) {
            $subscriptions_element = $xml->createElement('subscriptions');
            $subscriptions_data = $this->get_subscriptions_data();
            foreach ($subscriptions_data as $subscription) {
                $subscription_element = $xml->createElement('subscription');
                foreach ($subscription as $key => $value) {
                    $element = $xml->createElement($key, htmlspecialchars(is_array($value) ? json_encode($value) : $value));
                    $subscription_element->appendChild($element);
                }
                $subscriptions_element->appendChild($subscription_element);
            }
            $data_element->appendChild($subscriptions_element);
        }

        // Export customers
        if (in_array($export_type, ['all', 'customers'])) {
            $customers_element = $xml->createElement('customers');
            $customers_data = $this->get_customers_data();
            foreach ($customers_data as $customer) {
                $customer_element = $xml->createElement('customer');
                foreach ($customer as $key => $value) {
                    // Handle arrays like address, tags, etc. by encoding them to JSON
                    $element = $xml->createElement($key, htmlspecialchars(is_array($value) ? json_encode($value) : $value));
                    $customer_element->appendChild($element);
                }
                $customers_element->appendChild($customer_element);
            }
            $data_element->appendChild($customers_element);
        }

        // Export settings
        if ($include_settings) {
            $settings_element = $xml->createElement('settings');
            $settings_data = $this->get_settings_data();
            foreach ($settings_data as $setting) {
                $setting_element = $xml->createElement('setting');
                foreach ($setting as $key => $value) {
                    $element = $xml->createElement($key, htmlspecialchars(is_array($value) ? json_encode($value) : $value));
                    $setting_element->appendChild($element);
                }
                $settings_element->appendChild($setting_element);
            }
            $data_element->appendChild($settings_element);
        }

        // Export activity logs
        if ($include_logs) {
            $logs_element = $xml->createElement('activity_logs');
            $activity_logs_data = $this->get_activity_logs_data();
            foreach ($activity_logs_data as $log_entry) {
                $log_element = $xml->createElement('log_entry');
                foreach ($log_entry as $key => $value) {
                    $element = $xml->createElement($key, htmlspecialchars(is_array($value) ? json_encode($value) : $value));
                    $log_element->appendChild($element);
                }
                $logs_element->appendChild($log_element);
            }
            $data_element->appendChild($logs_element);
        }

        $filename = 'wplm-export-' . date('Y-m-d-H-i-s') . '.xml';
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo $xml->saveXML();
        exit;
    }

    /**
     * Handle import
     */
    private function handle_import() {
        if (empty($_FILES['wplm_import_file']['tmp_name'])) {
            $this->add_import_notice(__('No file uploaded.', 'wp-license-manager'), 'error');
            return;
        }

        $file = $_FILES['wplm_import_file'];
        $file_info = pathinfo($file['name']);
        $file_extension = strtolower($file_info['extension']);

        // Validate file type
        $allowed_types = ['csv', 'json', 'xml', 'zip'];
        if (!in_array($file_extension, $allowed_types)) {
            $this->add_import_notice(__('Invalid file type. Allowed types: CSV, JSON, XML, ZIP', 'wp-license-manager'), 'error');
            return;
        }

        $import_mode = sanitize_text_field($_POST['wplm_import_mode'] ?? 'create_only');
        $backup_before_import = isset($_POST['wplm_backup_before_import']);

        // Create backup if requested
        if ($backup_before_import) {
            $this->create_pre_import_backup();
        }

        try {
            switch ($file_extension) {
                case 'json':
                    $result = $this->import_json($file['tmp_name'], $import_mode);
                    break;
                case 'xml':
                    $result = $this->import_xml($file['tmp_name'], $import_mode);
                    break;
                case 'zip':
                    $result = $this->import_zip($file['tmp_name'], $import_mode);
                    break;
                default:
                    $result = $this->import_csv($file['tmp_name'], $import_mode);
                    break;
            }

            if ($result['success']) {
                $this->add_import_notice($result['message'], 'success');
            } else {
                $this->add_import_notice($result['message'], 'error');
            }

        } catch (Exception $e) {
            $this->add_import_notice(__('Import failed: ', 'wp-license-manager') . $e->getMessage(), 'error');
        }
    }

    /**
     * Import from JSON
     */
    private function import_json($file_path, $import_mode) {
        $json_content = file_get_contents($file_path);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON file', 'wp-license-manager'));
        }

        return $this->process_import_data($data['data'] ?? $data, $import_mode);
    }

    /**
     * Import from XML
     */
    private function import_xml($file_path, $import_mode) {
        $xml = simplexml_load_file($file_path);
        if ($xml === false) {
            throw new Exception(__('Invalid XML file', 'wp-license-manager'));
        }

        $data = $this->xml_to_array($xml);
        return $this->process_import_data($data['data'] ?? $data, $import_mode);
    }

    /**
     * Import from ZIP
     */
    private function import_zip($file_path, $import_mode) {
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== TRUE) {
            throw new Exception(__('Cannot open ZIP file', 'wp-license-manager'));
        }

        $temp_dir = sys_get_temp_dir() . '/wplm_import_' . uniqid();
        mkdir($temp_dir);

        $zip->extractTo($temp_dir);
        $zip->close();

        $results = [];

        // Process each file in the ZIP
        $files = scandir($temp_dir);
        foreach ($files as $file) {
            if (in_array($file, ['.', '..', 'export_info.json'])) {
                continue;
            }

            $file_path = $temp_dir . '/' . $file;
            $file_info = pathinfo($file);
            
            if ($file_info['extension'] === 'csv') {
                $result = $this->import_csv_file($file_path, $file_info['filename'], $import_mode);
                $results[] = $result;
            }
        }

        // Cleanup
        $this->delete_directory($temp_dir);

        $total_imported = array_sum(array_column($results, 'imported'));
        $total_updated = array_sum(array_column($results, 'updated'));
        $total_failed = array_sum(array_column($results, 'failed'));

        return [
            'success' => true,
            'message' => sprintf(
                __('Import completed: %d imported, %d updated, %d failed', 'wp-license-manager'),
                $total_imported, $total_updated, $total_failed
            ),
            'details' => $results
        ];
    }

    /**
     * Import from CSV
     */
    private function import_csv($file_path, $import_mode) {
        return $this->import_csv_file($file_path, 'licenses', $import_mode);
    }

    /**
     * Import CSV file
     */
    private function import_csv_file($file_path, $data_type, $import_mode) {
        $csv_file = fopen($file_path, 'r');
        if (!$csv_file) {
            throw new Exception(__('Cannot open CSV file', 'wp-license-manager'));
        }

        $headers = fgetcsv($csv_file);
        if (!$headers) {
            fclose($csv_file);
            throw new Exception(__('Invalid CSV format', 'wp-license-manager'));
        }

        $imported = 0;
        $updated = 0;
        $failed = 0;

        while (($row = fgetcsv($csv_file)) !== false) {
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            
            try {
                $result = $this->import_single_item($data, $data_type, $import_mode);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
            } catch (Exception $e) {
                $failed++;
                error_log('WPLM Import Error: ' . $e->getMessage());
            }
        }

        fclose($csv_file);

        return [
            'success' => true,
            'message' => sprintf(
                __('%s: %d imported, %d updated, %d failed', 'wp-license-manager'),
                ucfirst($data_type), $imported, $updated, $failed
            ),
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed
        ];
    }

    /**
     * Import single item
     */
    private function import_single_item($data, $data_type, $import_mode) {
        try {
            switch ($data_type) {
                case 'licenses':
                    return $this->import_license($data, $import_mode);
                case 'products':
                    return $this->import_product($data, $import_mode);
                case 'subscriptions':
                    return $this->import_subscription($data, $import_mode);
                case 'customers':
                    return $this->import_customer($data, $import_mode);
                case 'settings':
                    return $this->import_setting($data, $import_mode);
                case 'activity_logs':
                    return $this->import_activity_log($data, $import_mode);
                default:
                    throw new Exception(__('Unknown data type: ', 'wp-license-manager') . $data_type);
            }
        } catch (Exception $e) {
            error_log(sprintf(__('WPLM Import Error for %s (%s): %s', 'wp-license-manager'), $data_type, json_encode($data), $e->getMessage()));
            throw $e; // Re-throw to be caught by handle_import
        }
    }

    /**
     * Import license
     */
    private function import_license($data, $import_mode) {
        $license_key = sanitize_text_field($data['license_key'] ?? '');
        if (empty($license_key)) {
            throw new Exception(__('License key is required', 'wp-license-manager'));
        }

        // Check if license exists
        $existing_license = get_page_by_title($license_key, OBJECT, 'wplm_license');
        
        if ($existing_license) {
            if ($import_mode === 'create_only') {
                return 'skipped';
            }
            $license_id = $existing_license->ID;
            $action = 'updated';
        } else {
            // Create new license
            $license_id = wp_insert_post([
                'post_title' => $license_key,
                'post_type' => 'wplm_license',
                'post_status' => 'publish'
            ]);
            
            if (is_wp_error($license_id)) {
                throw new Exception(__('Failed to create license', 'wp-license-manager'));
            }
            $action = 'imported';
        }

        // Update license meta
        $meta_fields = [
            '_wplm_product_id' => 'product_id',
            '_wplm_product_type' => 'product_type',
            '_wplm_customer_email' => 'customer_email',
            '_wplm_expiry_date' => 'expiry_date',
            '_wplm_activation_limit' => 'activation_limit',
            '_wplm_status' => 'status',
            '_wplm_current_version' => 'current_version'
        ];

        foreach ($meta_fields as $meta_key => $data_key) {
            if (isset($data[$data_key]) && $data[$data_key] !== '') {
                update_post_meta($license_id, $meta_key, sanitize_text_field($data[$data_key]));
            }
        }

        // Handle activated domains
        if (isset($data['activated_domains']) && !empty($data['activated_domains'])) {
            $domains = is_string($data['activated_domains']) ? 
                      explode('|', $data['activated_domains']) : 
                      $data['activated_domains'];
            update_post_meta($license_id, '_wplm_activated_domains', array_map('sanitize_text_field', $domains));
        }

        return $action;
    }

    /**
     * Import product
     */
    private function import_product($data, $import_mode) {
        $product_id_slug = sanitize_text_field($data['product_id'] ?? '');
        $product_title = sanitize_text_field($data['product_title'] ?? $product_id_slug);

        if (empty($product_id_slug)) {
            throw new Exception(__('Product ID is required', 'wp-license-manager'));
        }

        // Check if product exists
        $existing_products = get_posts([
            'post_type' => 'wplm_product',
            'meta_key' => '_wplm_product_id',
            'meta_value' => $product_id_slug,
            'posts_per_page' => 1,
            'post_status' => 'any'
        ]);

        if (!empty($existing_products)) {
            if ($import_mode === 'create_only') {
                return 'skipped';
            }
            $product_id = $existing_products[0]->ID;
            $action = 'updated';
        } else {
            // Create new product
            $product_id = wp_insert_post([
                'post_title' => $product_title,
                'post_type' => 'wplm_product',
                'post_status' => 'publish'
            ]);
            
            if (is_wp_error($product_id)) {
                throw new Exception(__('Failed to create product', 'wp-license-manager'));
            }
            $action = 'imported';
        }

        // Update product meta
        $meta_fields = [
            '_wplm_product_id' => 'product_id',
            '_wplm_current_version' => 'current_version',
            '_wplm_download_url' => 'download_url',
            '_wplm_product_type' => 'product_type'
        ];

        foreach ($meta_fields as $meta_key => $data_key) {
            if (isset($data[$data_key]) && $data[$data_key] !== '') {
                if ($meta_key === '_wplm_download_url') {
                    update_post_meta($product_id, $meta_key, esc_url_raw($data[$data_key]));
                } else {
                    update_post_meta($product_id, $meta_key, sanitize_text_field($data[$data_key]));
                }
            }
        }

        return $action;
    }

    /**
     * Import subscription
     */
    private function import_subscription($data, $import_mode) {
        if (!class_exists('WPLM_Subscription_Manager')) {
            throw new Exception(__('Subscription manager not available', 'wp-license-manager'));
        }

        $subscription_manager = new WPLM_Subscription_Manager();
        
        $subscription_key = sanitize_text_field($data['subscription_key'] ?? '');
        if (empty($subscription_key)) {
            throw new Exception(__('Subscription key is required', 'wp-license-manager'));
        }

        // Check if subscription exists
        $existing_subscription = $subscription_manager->get_subscription($subscription_key);
        
        if ($existing_subscription) {
            if ($import_mode === 'create_only') {
                return 'skipped';
            }
            
            $update_data = [
                'status' => sanitize_text_field($data['status'] ?? 'active'),
                'billing_period' => sanitize_text_field($data['billing_period'] ?? 'monthly'),
                'billing_interval' => intval($data['billing_interval'] ?? 1)
            ];
            
            $subscription_manager->update_subscription($existing_subscription->id, $update_data);
            return 'updated';
        } else {
            // Create new subscription
            $subscription_args = [
                'license_id' => intval($data['license_id'] ?? 0),
                'customer_email' => sanitize_email($data['customer_email'] ?? ''),
                'product_id' => sanitize_text_field($data['product_id'] ?? ''),
                'status' => sanitize_text_field($data['status'] ?? 'active'),
                'billing_period' => sanitize_text_field($data['billing_period'] ?? 'monthly'),
                'billing_interval' => intval($data['billing_interval'] ?? 1)
            ];
            
            $result = $subscription_manager->create_subscription($subscription_args);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            return 'imported';
        }
    }

    /**
     * Generate export metadata
     */
    private function generate_export_metadata($export_type, $include_settings, $include_logs) {
        return [
            'export_date' => current_time('mysql'),
            'export_type' => $export_type,
            'include_settings' => $include_settings ? 'yes' : 'no',
            'include_logs' => $include_logs ? 'yes' : 'no',
            'wplm_version' => WPLM_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'site_url' => get_site_url(),
            'export_id' => uniqid('wplm_export_')
        ];
    }

    /**
     * Generate licenses CSV
     */
    private function generate_licenses_csv() {
        $output = fopen('php://temp', 'r+');
        
        // Headers
        fputcsv($output, [
            'license_key', 'product_id', 'product_title', 'product_type', 'customer_email',
            'expiry_date', 'activation_limit', 'status', 'current_version', 'activated_domains',
            'created_date', 'wc_product_id', 'subscription_id'
        ]);

        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        foreach ($licenses as $license) {
            $product_id = get_post_meta($license->ID, '_wplm_product_id', true);
            $product_type = get_post_meta($license->ID, '_wplm_product_type', true);
            $activated_domains = get_post_meta($license->ID, '_wplm_activated_domains', true);
            $wc_subscription_id = get_post_meta($license->ID, '_wplm_wc_subscription_id', true);
            
            // Get product title
            $product_title = $this->get_product_title($product_id, $product_type);
            
            fputcsv($output, [
                $license->post_title,
                $product_id,
                $product_title,
                $product_type,
                get_post_meta($license->ID, '_wplm_customer_email', true),
                get_post_meta($license->ID, '_wplm_expiry_date', true),
                get_post_meta($license->ID, '_wplm_activation_limit', true),
                get_post_meta($license->ID, '_wplm_status', true),
                get_post_meta($license->ID, '_wplm_current_version', true),
                is_array($activated_domains) ? implode('|', $activated_domains) : '',
                $license->post_date,
                '', // WC product ID would be derived
                $wc_subscription_id
            ]);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }

    /**
     * Get all data methods for JSON/XML export
     */
    private function get_licenses_data() {
        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        $data = [];
        foreach ($licenses as $license) {
            $data[] = [
                'id' => $license->ID,
                'license_key' => $license->post_title,
                'product_id' => get_post_meta($license->ID, '_wplm_product_id', true),
                'product_type' => get_post_meta($license->ID, '_wplm_product_type', true),
                'customer_email' => get_post_meta($license->ID, '_wplm_customer_email', true),
                'expiry_date' => get_post_meta($license->ID, '_wplm_expiry_date', true),
                'activation_limit' => get_post_meta($license->ID, '_wplm_activation_limit', true),
                'status' => get_post_meta($license->ID, '_wplm_status', true),
                'current_version' => get_post_meta($license->ID, '_wplm_current_version', true),
                'activated_domains' => get_post_meta($license->ID, '_wplm_activated_domains', true),
                'created_date' => $license->post_date,
                'modified_date' => $license->post_modified
            ];
        }

        return $data;
    }

    /**
     * Helper methods
     */
    private function get_product_title($product_id, $product_type) {
        if ($product_type === 'wplm') {
            $products = get_posts([
                'post_type' => 'wplm_product',
                'meta_key' => '_wplm_product_id',
                'meta_value' => $product_id,
                'posts_per_page' => 1
            ]);
            return !empty($products) ? $products[0]->post_title : $product_id;
        } elseif ($product_type === 'woocommerce' && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            return $product ? $product->get_name() : $product_id;
        }
        
        return $product_id;
    }

    private function add_import_notice($message, $type) {
        set_transient('wplm_import_notice', $message, 60);
        set_transient('wplm_import_notice_type', $type, 60);
    }

    private function create_pre_import_backup() {
        // Create a backup before import
        $backup_data = [
            'licenses' => $this->get_licenses_data(),
            'products' => $this->get_products_data(),
            'settings' => $this->get_settings_data(),
            'backup_date' => current_time('mysql')
        ];

        $backup_file = wp_upload_dir()['basedir'] . '/wplm-backup-' . date('Y-m-d-H-i-s') . '.json';
        file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));
        
        update_option('wplm_last_backup_file', $backup_file);
    }

    private function delete_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function xml_to_array($xml) {
        return json_decode(json_encode($xml), true);
    }

    // Add other generate_*_csv and get_*_data methods as needed...
    
    private function generate_products_csv() {
        $output = fopen('php://temp', 'r+');
        
        fputcsv($output, [
            'id', 'product_name', 'product_id_slug', 'product_type', 'current_version',
            'download_url', 'wc_product_id', 'total_licenses', 'active_licenses',
            'created_date', 'modified_date'
        ]);

        $products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        foreach ($products as $product) {
            fputcsv($output, [
                $product->ID,
                $product->post_title,
                get_post_meta($product->ID, '_wplm_product_id', true),
                get_post_meta($product->ID, '_wplm_product_type', true),
                get_post_meta($product->ID, '_wplm_current_version', true),
                get_post_meta($product->ID, '_wplm_download_url', true),
                get_post_meta($product->ID, '_wplm_wc_product_id', true),
                get_post_meta($product->ID, '_wplm_total_licenses', true),
                get_post_meta($product->ID, '_wplm_active_licenses', true),
                $product->post_date,
                $product->post_modified
            ]);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function generate_subscriptions_csv() {
        $output = fopen('php://temp', 'r+');
        
        fputcsv($output, [
            'id', 'subscription_key', 'license_id', 'customer_email', 'product_id',
            'status', 'billing_period', 'billing_interval', 'start_date', 'end_date',
            'next_payment_date', 'total_paid', 'created_date', 'modified_date'
        ]);

        $subscriptions = get_posts([
            'post_type' => 'wplm_subscription',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        foreach ($subscriptions as $subscription) {
            fputcsv($output, [
                $subscription->ID,
                get_post_meta($subscription->ID, '_wplm_subscription_key', true),
                get_post_meta($subscription->ID, '_wplm_license_id', true),
                get_post_meta($subscription->ID, '_wplm_customer_email', true),
                get_post_meta($subscription->ID, '_wplm_product_id', true),
                get_post_meta($subscription->ID, '_wplm_status', true),
                get_post_meta($subscription->ID, '_wplm_billing_period', true),
                get_post_meta($subscription->ID, '_wplm_billing_interval', true),
                get_post_meta($subscription->ID, '_wplm_start_date', true),
                get_post_meta($subscription->ID, '_wplm_end_date', true),
                get_post_meta($subscription->ID, '_wplm_next_payment_date', true),
                get_post_meta($subscription->ID, '_wplm_total_paid', true),
                $subscription->post_date,
                $subscription->post_modified
            ]);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function generate_customers_csv() {
        $output = fopen('php://temp', 'r+');
        
        // Headers
        fputcsv($output, [
            'customer_id', 'customer_email', 'customer_name', 'first_name', 'last_name',
            'company', 'phone', 'status', 'tags', 'notes', 'total_licenses',
            'active_licenses', 'total_spent', 'order_count', 'first_order_date',
            'last_activity', 'customer_source', 'wc_customer_id',
            // New customer fields
            'username', 'country', 'mobile_number', 'social_media'
        ]);

        $customers = get_posts([
            'post_type' => 'wplm_customer',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        foreach ($customers as $customer) {
            $customer_email = get_post_meta($customer->ID, '_wplm_customer_email', true);
            $first_name = get_post_meta($customer->ID, '_wplm_first_name', true);
            $last_name = get_post_meta($customer->ID, '_wplm_last_name', true);
            $company = get_post_meta($customer->ID, '_wplm_company', true);
            $phone = get_post_meta($customer->ID, '_wplm_phone', true);
            $status = get_post_meta($customer->ID, '_wplm_customer_status', true);
            $tags = get_post_meta($customer->ID, '_wplm_tags', true);
            $notes = get_post_meta($customer->ID, '_wplm_notes', true);
            $total_licenses = get_post_meta($customer->ID, '_wplm_total_licenses', true);
            $active_licenses = get_post_meta($customer->ID, '_wplm_active_licenses', true);
            $total_spent = get_post_meta($customer->ID, '_wplm_total_spent', true);
            $order_count = get_post_meta($customer->ID, '_wplm_order_count', true);
            $first_order_date = get_post_meta($customer->ID, '_wplm_first_order_date', true);
            $last_activity = get_post_meta($customer->ID, '_wplm_last_activity', true);
            $customer_source = get_post_meta($customer->ID, '_wplm_customer_source', true);
            $wc_customer_id = get_post_meta($customer->ID, '_wplm_wc_customer_id', true);
            
            // New fields
            $username = get_post_meta($customer->ID, '_wplm_username', true);
            $country = get_post_meta($customer->ID, '_wplm_country', true);
            $mobile_number = get_post_meta($customer->ID, '_wplm_mobile_number', true);
            $social_media = get_post_meta($customer->ID, '_wplm_social_media', true);

            fputcsv($output, [
                $customer->ID,
                $customer_email,
                $customer->post_title, // Full name
                $first_name,
                $last_name,
                $company,
                $phone,
                $status,
                is_array($tags) ? implode('|', $tags) : '',
                $notes,
                $total_licenses,
                $active_licenses,
                $total_spent,
                $order_count,
                $first_order_date,
                $last_activity,
                $customer_source,
                $wc_customer_id,
                // New customer fields
                $username,
                $country,
                $mobile_number,
                $social_media
            ]);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function generate_settings_csv() {
        global $wpdb;
        $output = fopen('php://temp', 'r+');
        
        fputcsv($output, [
            'option_name', 'option_value'
        ]);

        $wplm_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wplm_%'");

        foreach ($wplm_options as $option) {
            fputcsv($output, [
                $option->option_name,
                is_serialized($option->option_value) ? serialize($option->option_value) : $option->option_value
            ]);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function generate_activity_logs_csv() {
        $output = fopen('php://temp', 'r+');
        
        fputcsv($output, [
            'timestamp', 'user_id', 'event_type', 'description', 'object_id',
            'object_type', 'ip_address', 'user_agent', 'data'
        ]);

        $activity_logs = WPLM_Activity_Logger::get_global_log();

        foreach ($activity_logs as $log_entry) {
            fputcsv($output, [
                $log_entry['timestamp'] ?? '',
                $log_entry['user_id'] ?? '',
                $log_entry['event_type'] ?? '',
                $log_entry['description'] ?? '',
                $log_entry['object_id'] ?? '',
                $log_entry['object_type'] ?? '',
                $log_entry['ip_address'] ?? '',
                $log_entry['user_agent'] ?? '',
                json_encode($log_entry['data'] ?? [], JSON_UNESCAPED_UNICODE)
            ]);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function get_products_data() {
        $products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        $data = [];
        foreach ($products as $product) {
            $data[] = [
                'id' => $product->ID,
                'product_name' => $product->post_title,
                'product_id_slug' => get_post_meta($product->ID, '_wplm_product_id', true),
                'product_type' => get_post_meta($product->ID, '_wplm_product_type', true),
                'current_version' => get_post_meta($product->ID, '_wplm_current_version', true),
                'download_url' => get_post_meta($product->ID, '_wplm_download_url', true),
                'wc_product_id' => get_post_meta($product->ID, '_wplm_wc_product_id', true),
                'total_licenses' => get_post_meta($product->ID, '_wplm_total_licenses', true),
                'active_licenses' => get_post_meta($product->ID, '_wplm_active_licenses', true),
                'created_date' => $product->post_date,
                'modified_date' => $product->post_modified
            ];
        }

        return $data;
    }
    
    private function get_subscriptions_data() {
        $subscriptions = get_posts([
            'post_type' => 'wplm_subscription',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        $data = [];
        foreach ($subscriptions as $subscription) {
            $data[] = [
                'id' => $subscription->ID,
                'subscription_key' => get_post_meta($subscription->ID, '_wplm_subscription_key', true),
                'license_id' => get_post_meta($subscription->ID, '_wplm_license_id', true),
                'customer_email' => get_post_meta($subscription->ID, '_wplm_customer_email', true),
                'product_id' => get_post_meta($subscription->ID, '_wplm_product_id', true),
                'status' => get_post_meta($subscription->ID, '_wplm_status', true),
                'billing_period' => get_post_meta($subscription->ID, '_wplm_billing_period', true),
                'billing_interval' => get_post_meta($subscription->ID, '_wplm_billing_interval', true),
                'start_date' => get_post_meta($subscription->ID, '_wplm_start_date', true),
                'end_date' => get_post_meta($subscription->ID, '_wplm_end_date', true),
                'next_payment_date' => get_post_meta($subscription->ID, '_wplm_next_payment_date', true),
                'total_paid' => get_post_meta($subscription->ID, '_wplm_total_paid', true),
                'created_date' => $subscription->post_date,
                'modified_date' => $subscription->post_modified
            ];
        }

        return $data;
    }
    
    private function get_customers_data() {
        $customers = get_posts([
            'post_type' => 'wplm_customer',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        $data = [];
        foreach ($customers as $customer) {
            $address = get_post_meta($customer->ID, '_wplm_address', true) ?: [];
            $tags = get_post_meta($customer->ID, '_wplm_tags', true) ?: [];
            $license_keys = get_post_meta($customer->ID, '_wplm_license_keys', true) ?: [];
            $order_ids = get_post_meta($customer->ID, '_wplm_order_ids', true) ?: [];
            $communication_log = get_post_meta($customer->ID, '_wplm_communication_log', true) ?: [];
            
            // New fields
            $username = get_post_meta($customer->ID, '_wplm_username', true);
            $country = get_post_meta($customer->ID, '_wplm_country', true);
            $mobile_number = get_post_meta($customer->ID, '_wplm_mobile_number', true);
            $social_media = get_post_meta($customer->ID, '_wplm_social_media', true);

            $data[] = [
                'id' => $customer->ID,
                'customer_email' => get_post_meta($customer->ID, '_wplm_customer_email', true),
                'customer_name' => $customer->post_title,
                'first_name' => get_post_meta($customer->ID, '_wplm_first_name', true),
                'last_name' => get_post_meta($customer->ID, '_wplm_last_name', true),
                'company' => get_post_meta($customer->ID, '_wplm_company', true),
                'phone' => get_post_meta($customer->ID, '_wplm_phone', true),
                'status' => get_post_meta($customer->ID, '_wplm_customer_status', true),
                'tags' => $tags,
                'notes' => get_post_meta($customer->ID, '_wplm_notes', true),
                'total_licenses' => get_post_meta($customer->ID, '_wplm_total_licenses', true),
                'active_licenses' => get_post_meta($customer->ID, '_wplm_active_licenses', true),
                'total_spent' => get_post_meta($customer->ID, '_wplm_total_spent', true),
                'order_count' => get_post_meta($customer->ID, '_wplm_order_count', true),
                'first_order_date' => get_post_meta($customer->ID, '_wplm_first_order_date', true),
                'last_activity' => get_post_meta($customer->ID, '_wplm_last_activity', true),
                'customer_source' => get_post_meta($customer->ID, '_wplm_customer_source', true),
                'wc_customer_id' => get_post_meta($customer->ID, '_wplm_wc_customer_id', true),
                'address' => $address,
                'license_keys' => $license_keys,
                'order_ids' => $order_ids,
                'communication_log' => $communication_log,
                // New customer fields
                'username' => $username,
                'country' => $country,
                'mobile_number' => $mobile_number,
                'social_media' => $social_media
            ];
        }

        return $data;
    }
    
    private function get_settings_data() {
        global $wpdb;
        $data = [];

        // Get all WPLM options (those starting with 'wplm_')
        $wplm_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wplm_%'");

        foreach ($wplm_options as $option) {
            $data[] = [
                'option_name' => $option->option_name,
                'option_value' => is_serialized($option->option_value) ? unserialize($option->option_value) : $option->option_value
            ];
        }

        return $data;
    }
    
    private function get_activity_logs_data() {
        $activity_logs = WPLM_Activity_Logger::get_global_log();
        $data = [];
        
        foreach ($activity_logs as $log_entry) {
            // For JSON/XML, it's better to include all original data
            $data[] = $log_entry;
        }
        
        return $data;
    }
    
    private function import_customer($data, $import_mode) {
        $customer_email = sanitize_email($data['customer_email'] ?? '');
        if (empty($customer_email) || !is_email($customer_email)) {
            throw new Exception(__('Valid customer email is required for import', 'wp-license-manager'));
        }

        // Check if customer exists by email
        $existing_customer = $this->get_customer_by_email($customer_email);
        $customer_id = 0;
        $action = 'skipped';

        if ($existing_customer) {
            if ($import_mode === 'create_only') {
                return 'skipped';
            }
            $customer_id = $existing_customer->ID;
            $action = 'updated';
        } else {
            // If import mode is update_only, skip if customer doesn't exist
            if ($import_mode === 'update_only') {
                return 'skipped';
            }
            
            // Create new customer
            $customer_name = sanitize_text_field($data['customer_name'] ?? $customer_email);
            $customer_id = wp_insert_post([
                'post_type' => 'wplm_customer',
                'post_title' => $customer_name,
                'post_status' => 'publish',
                'post_author' => get_current_user_id() // Assign to current user
            ]);

            if (is_wp_error($customer_id)) {
                throw new Exception(__('Failed to create customer: ', 'wp-license-manager') . $customer_id->get_error_message());
            }
            $action = 'imported';
        }

        // Update customer meta fields
        $meta_fields = [
            '_wplm_customer_email' => sanitize_email($data['customer_email'] ?? ''),
            '_wplm_first_name' => sanitize_text_field($data['first_name'] ?? ''),
            '_wplm_last_name' => sanitize_text_field($data['last_name'] ?? ''),
            '_wplm_company' => sanitize_text_field($data['company'] ?? ''),
            '_wplm_phone' => sanitize_text_field($data['phone'] ?? ''),
            '_wplm_customer_status' => sanitize_text_field($data['status'] ?? 'active'),
            '_wplm_notes' => sanitize_textarea_field($data['notes'] ?? ''),
            '_wplm_total_licenses' => intval($data['total_licenses'] ?? 0),
            '_wplm_active_licenses' => intval($data['active_licenses'] ?? 0),
            '_wplm_total_spent' => floatval($data['total_spent'] ?? 0),
            '_wplm_order_count' => intval($data['order_count'] ?? 0),
            '_wplm_first_order_date' => sanitize_text_field($data['first_order_date'] ?? ''),
            '_wplm_last_activity' => sanitize_text_field($data['last_activity'] ?? current_time('mysql')),
            '_wplm_customer_source' => sanitize_text_field($data['customer_source'] ?? 'import'),
            '_wplm_wc_customer_id' => sanitize_text_field($data['wc_customer_id'] ?? ''),
            // New customer fields for import
            '_wplm_username' => sanitize_text_field($data['username'] ?? ''),
            '_wplm_country' => sanitize_text_field($data['country'] ?? ''),
            '_wplm_mobile_number' => sanitize_text_field($data['mobile_number'] ?? ''),
            '_wplm_social_media' => sanitize_textarea_field($data['social_media'] ?? ''),
        ];

        foreach ($meta_fields as $meta_key => $value) {
            update_post_meta($customer_id, $meta_key, $value);
        }

        // Handle address array
        if (isset($data['address']) && is_array($data['address'])) {
            $sanitized_address = [];
            foreach ($data['address'] as $key => $value) {
                $sanitized_address[$key] = sanitize_text_field($value);
            }
            update_post_meta($customer_id, '_wplm_address', $sanitized_address);
        }

        // Handle tags (pipe-separated string or array)
        if (isset($data['tags'])) {
            $tags = is_string($data['tags']) ? 
                    array_filter(array_map('trim', explode('|', $data['tags']))) : 
                    (is_array($data['tags']) ? array_map('sanitize_text_field', $data['tags']) : []);
            update_post_meta($customer_id, '_wplm_tags', $tags);
        }

        // Handle license_keys (pipe-separated string or array)
        if (isset($data['license_keys'])) {
            $license_keys = is_string($data['license_keys']) ? 
                            array_filter(array_map('trim', explode('|', $data['license_keys']))) : 
                            (is_array($data['license_keys']) ? array_map('sanitize_text_field', $data['license_keys']) : []);
            update_post_meta($customer_id, '_wplm_license_keys', $license_keys);
        }

        // Handle order_ids (pipe-separated string or array)
        if (isset($data['order_ids'])) {
            $order_ids = is_string($data['order_ids']) ? 
                         array_filter(array_map('trim', explode('|', $data['order_ids']))) : 
                         (is_array($data['order_ids']) ? array_map('intval', $data['order_ids']) : []);
            update_post_meta($customer_id, '_wplm_order_ids', $order_ids);
        }
        
        // Handle communication_log (JSON string or array)
        if (isset($data['communication_log'])) {
            $communication_log = is_string($data['communication_log']) ? 
                                 json_decode($data['communication_log'], true) : 
                                 (is_array($data['communication_log']) ? $data['communication_log'] : []);
            if (is_array($communication_log)) {
                update_post_meta($customer_id, '_wplm_communication_log', $communication_log);
            }
        }

        // Update post title if necessary
        $new_post_title = trim(sanitize_text_field($data['first_name'] ?? '') . ' ' . sanitize_text_field($data['last_name'] ?? ''));
        if (empty($new_post_title)) {
            $new_post_title = $customer_email;
        }
        // Use username for post title if provided and no first/last name
        if (empty($new_post_title) && !empty($data['username'])) {
            $new_post_title = sanitize_text_field($data['username']);
        }

        if ($new_post_title !== get_the_title($customer_id)) {
            wp_update_post([
                'ID' => $customer_id,
                'post_title' => $new_post_title
            ]);
        }

        return $action;
    }
    
    // Helper function to get customer by email (since it's used here and in customer-management-system)
    private function get_customer_by_email($email) {
        $customers = get_posts([
            'post_type' => 'wplm_customer',
            'meta_key' => '_wplm_customer_email',
            'meta_value' => $email,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);

        return !empty($customers) ? get_post($customers[0]) : null;
    }

    private function import_setting($data, $import_mode) {
        $option_name = sanitize_text_field($data['option_name'] ?? '');
        $option_value = $data['option_value'] ?? ''; // Value might be serialized or array

        if (empty($option_name)) {
            throw new Exception(__('Option name is required for setting import', 'wp-license-manager'));
        }

        if ($import_mode === 'create_only' && get_option($option_name) !== false) {
            return 'skipped'; // Option already exists, and we are in create_only mode
        }

        // Attempt to unserialize if it's a serialized string
        if (is_string($option_value) && (@unserialize($option_value) !== false || $option_value === 'b:0;')) {
            $option_value = unserialize($option_value);
        }

        update_option($option_name, $option_value);

        return 'imported';
    }
    
    private function import_activity_log($data, $import_mode) {
        $timestamp = sanitize_text_field($data['timestamp'] ?? '');
        $event_type = sanitize_key($data['event_type'] ?? '');
        $description = sanitize_text_field($data['description'] ?? '');
        $object_id = intval($data['object_id'] ?? 0);
        $object_type = sanitize_text_field($data['object_type'] ?? '');
        $user_id = intval($data['user_id'] ?? 0);
        $ip_address = sanitize_text_field($data['ip_address'] ?? '');
        $user_agent = sanitize_text_field($data['user_agent'] ?? '');
        $log_data = json_decode($data['data'] ?? '{}', true);

        if (empty($timestamp) || empty($event_type) || empty($description)) {
            throw new Exception(__('Timestamp, event type, and description are required for activity log import', 'wp-license-manager'));
        }

        // Re-log the activity to ensure it goes into the global log as well
        WPLM_Activity_Logger::log($object_id, $event_type, $description, $log_data);

        return 'imported';
    }
    
    private function process_import_data($data, $import_mode) {
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;
        $total_failed = 0;

        foreach ($data as $data_type => $items) {
            if (!is_array($items)) continue; // Skip if not an array of items

            foreach ($items as $item) {
                try {
                    $result = $this->import_single_item($item, $data_type, $import_mode);
                    if ($result === 'imported') {
                        $total_imported++;
                    } elseif ($result === 'updated') {
                        $total_updated++;
                    } elseif ($result === 'skipped') {
                        $total_skipped++;
                    }
                } catch (Exception $e) {
                    $total_failed++;
                    error_log('WPLM Import Data Processing Error (' . $data_type . '): ' . $e->getMessage());
                }
            }
        }

        return [
            'success' => true,
            'message' => sprintf(__('Import completed: %d imported, %d updated, %d skipped, %d failed.', 'wp-license-manager'),
                                    $total_imported, $total_updated, $total_skipped, $total_failed),
            'imported_count' => $total_imported,
            'updated_count' => $total_updated,
            'skipped_count' => $total_skipped,
            'failed_count' => $total_failed
        ];
    }
}
