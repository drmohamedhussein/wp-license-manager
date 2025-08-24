<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import/Export Manager - Export Functionality
 * Part 1 of the split WPLM_Import_Export_Manager class
 */
class WPLM_Import_Export_Manager_Export {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_export_actions']);
        add_action('wp_ajax_wplm_export_data', [$this, 'ajax_export_data']);
    }

    /**
     * Handle export actions
     */
    public function handle_export_actions() {
        if (!current_user_can('manage_wplm_licenses')) {
            return;
        }

        // Handle export
        if (isset($_POST['wplm_export_submit']) && wp_verify_nonce($_POST['wplm_export_nonce'], 'wplm_export_data')) {
            $this->handle_export();
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
        if (!class_exists('ZipArchive')) {
            $message = __('ZipArchive class not found. Please enable the PHP Zip extension for import/export functionality.', 'wp-license-manager');
            error_log('WPLM Error: ' . $message);
            $this->add_export_notice($message, 'error');
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
                    $element = $xml->createElement($key, htmlspecialchars($value));
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
                    $element = $xml->createElement($key, htmlspecialchars($value));
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
                    $element = $xml->createElement($key, htmlspecialchars($value));
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
                    $element = $xml->createElement($key, htmlspecialchars($value));
                    $setting_element->appendChild($element);
                }
                $settings_element->appendChild($setting_element);
            }
            $data_element->appendChild($settings_element);
        }

        // Export activity logs
        if ($include_logs) {
            $logs_element = $xml->createElement('activity_logs');
            $logs_data = $this->get_activity_logs_data();
            foreach ($logs_data as $log) {
                $log_element = $xml->createElement('log');
                foreach ($log as $key => $value) {
                    $element = $xml->createElement($key, htmlspecialchars($value));
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
     * Generate export metadata
     */
    private function generate_export_metadata($export_type, $include_settings, $include_logs) {
        return [
            'export_date' => current_time('mysql'),
            'export_type' => $export_type,
            'include_settings' => $include_settings,
            'include_logs' => $include_logs,
            'wplm_version' => WPLM_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'site_url' => get_site_url(),
            'total_licenses' => wp_count_posts('wplm_license')->publish,
            'total_products' => wp_count_posts('wplm_product')->publish,
            'total_subscriptions' => wp_count_posts('wplm_subscription')->publish,
            'total_customers' => wp_count_posts('wplm_customer')->publish,
        ];
    }

    /**
     * Generate licenses CSV
     */
    private function generate_licenses_csv() {
        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $csv_data = [];
        $csv_data[] = [
            'ID',
            'License Key',
            'Status',
            'Product Type',
            'Product ID',
            'Customer Email',
            'Duration Type',
            'Duration Value',
            'Expiry Date',
            'Activation Limit',
            'Activated Domains',
            'Created Date',
            'Modified Date'
        ];

        foreach ($licenses as $license) {
            $csv_data[] = [
                $license->ID,
                $license->post_title,
                get_post_meta($license->ID, '_wplm_status', true),
                get_post_meta($license->ID, '_wplm_product_type', true),
                get_post_meta($license->ID, '_wplm_product_id', true),
                get_post_meta($license->ID, '_wplm_customer_email', true),
                get_post_meta($license->ID, '_wplm_duration_type', true),
                get_post_meta($license->ID, '_wplm_duration_value', true),
                get_post_meta($license->ID, '_wplm_expiry_date', true),
                get_post_meta($license->ID, '_wplm_activation_limit', true),
                implode(';', get_post_meta($license->ID, '_wplm_activated_domains', true) ?: []),
                $license->post_date,
                $license->post_modified
            ];
        }

        return $this->array_to_csv($csv_data);
    }

    /**
     * Get licenses data for export
     */
    private function get_licenses_data() {
        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $data = [];
        foreach ($licenses as $license) {
            $data[] = [
                'id' => $license->ID,
                'license_key' => $license->post_title,
                'status' => get_post_meta($license->ID, '_wplm_status', true),
                'product_type' => get_post_meta($license->ID, '_wplm_product_type', true),
                'product_id' => get_post_meta($license->ID, '_wplm_product_id', true),
                'product_title' => $this->get_product_title(
                    get_post_meta($license->ID, '_wplm_product_id', true),
                    get_post_meta($license->ID, '_wplm_product_type', true)
                ),
                'customer_email' => get_post_meta($license->ID, '_wplm_customer_email', true),
                'duration_type' => get_post_meta($license->ID, '_wplm_duration_type', true),
                'duration_value' => get_post_meta($license->ID, '_wplm_duration_value', true),
                'expiry_date' => get_post_meta($license->ID, '_wplm_expiry_date', true),
                'activation_limit' => get_post_meta($license->ID, '_wplm_activation_limit', true),
                'activated_domains' => get_post_meta($license->ID, '_wplm_activated_domains', true) ?: [],
                'created_date' => $license->post_date,
                'modified_date' => $license->post_modified
            ];
        }

        return $data;
    }

    /**
     * Get product title by ID and type
     */
    private function get_product_title($product_id, $product_type) {
        if (empty($product_id)) {
            return '';
        }

        if ($product_type === 'woocommerce' && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            return $product ? $product->get_name() : '';
        } else {
            $product = get_post($product_id);
            return $product ? $product->post_title : '';
        }
    }

    /**
     * Add export notice
     */
    private function add_export_notice($message, $type) {
        add_action('admin_notices', function() use ($message, $type) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($message));
        });
    }

    /**
     * Generate products CSV
     */
    private function generate_products_csv() {
        $products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $csv_data = [];
        $csv_data[] = [
            'ID',
            'Product Name',
            'Product ID',
            'Current Version',
            'Download URL',
            'Product Type',
            'Description',
            'Created Date',
            'Modified Date'
        ];

        foreach ($products as $product) {
            $csv_data[] = [
                $product->ID,
                $product->post_title,
                get_post_meta($product->ID, '_wplm_product_id', true),
                get_post_meta($product->ID, '_wplm_current_version', true),
                get_post_meta($product->ID, '_wplm_download_url', true),
                get_post_meta($product->ID, '_wplm_product_type', true),
                $product->post_content,
                $product->post_date,
                $product->post_modified
            ];
        }

        return $this->array_to_csv($csv_data);
    }

    /**
     * Generate subscriptions CSV
     */
    private function generate_subscriptions_csv() {
        $subscriptions = get_posts([
            'post_type' => 'wplm_subscription',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $csv_data = [];
        $csv_data[] = [
            'ID',
            'Subscription Title',
            'Customer Email',
            'Product ID',
            'Status',
            'Billing Period',
            'Billing Interval',
            'Trial End Date',
            'Next Payment Date',
            'End Date',
            'Created Date',
            'Modified Date'
        ];

        foreach ($subscriptions as $subscription) {
            $csv_data[] = [
                $subscription->ID,
                $subscription->post_title,
                get_post_meta($subscription->ID, '_wplm_customer_email', true),
                get_post_meta($subscription->ID, '_wplm_product_id', true),
                get_post_meta($subscription->ID, '_wplm_subscription_status', true),
                get_post_meta($subscription->ID, '_wplm_billing_period', true),
                get_post_meta($subscription->ID, '_wplm_billing_interval', true),
                get_post_meta($subscription->ID, '_wplm_trial_end_date', true),
                get_post_meta($subscription->ID, '_wplm_next_payment_date', true),
                get_post_meta($subscription->ID, '_wplm_end_date', true),
                $subscription->post_date,
                $subscription->post_modified
            ];
        }

        return $this->array_to_csv($csv_data);
    }

    /**
     * Generate customers CSV
     */
    private function generate_customers_csv() {
        $customers = get_posts([
            'post_type' => 'wplm_customer',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $csv_data = [];
        $csv_data[] = [
            'ID',
            'Customer Name',
            'Customer Email',
            'Total Licenses',
            'Active Licenses',
            'First License Date',
            'Last Activity',
            'Customer Status',
            'Customer Source',
            'Address',
            'Notes',
            'Tags',
            'Created Date',
            'Modified Date'
        ];

        foreach ($customers as $customer) {
            $address = get_post_meta($customer->ID, '_wplm_address', true);
            $address_string = is_array($address) ? implode(', ', array_filter($address)) : $address;
            
            $tags = get_post_meta($customer->ID, '_wplm_tags', true);
            $tags_string = is_array($tags) ? implode(';', $tags) : $tags;

            $csv_data[] = [
                $customer->ID,
                $customer->post_title,
                get_post_meta($customer->ID, '_wplm_customer_email', true),
                get_post_meta($customer->ID, '_wplm_total_licenses', true),
                get_post_meta($customer->ID, '_wplm_active_licenses', true),
                get_post_meta($customer->ID, '_wplm_first_license_date', true),
                get_post_meta($customer->ID, '_wplm_last_activity', true),
                get_post_meta($customer->ID, '_wplm_customer_status', true),
                get_post_meta($customer->ID, '_wplm_customer_source', true),
                $address_string,
                get_post_meta($customer->ID, '_wplm_notes', true),
                $tags_string,
                $customer->post_date,
                $customer->post_modified
            ];
        }

        return $this->array_to_csv($csv_data);
    }

    /**
     * Generate settings CSV
     */
    private function generate_settings_csv() {
        $settings = [
            'wplm_api_key' => get_option('wplm_api_key'),
            'wplm_delete_on_uninstall' => get_option('wplm_delete_on_uninstall'),
            'wplm_export_import_type' => get_option('wplm_export_import_type'),
            'wplm_plugin_name' => get_option('wplm_plugin_name'),
            'wplm_default_duration_type' => get_option('wplm_default_duration_type'),
            'wplm_default_duration_value' => get_option('wplm_default_duration_value'),
            'wplm_default_activation_limit' => get_option('wplm_default_activation_limit'),
            'wplm_license_key_format' => get_option('wplm_license_key_format'),
            'wplm_email_notifications_enabled' => get_option('wplm_email_notifications_enabled'),
            'wplm_rest_api_enabled' => get_option('wplm_rest_api_enabled'),
        ];

        $csv_data = [];
        $csv_data[] = ['Option Name', 'Option Value'];

        foreach ($settings as $key => $value) {
            $csv_data[] = [$key, is_array($value) ? json_encode($value) : $value];
        }

        return $this->array_to_csv($csv_data);
    }

    /**
     * Generate activity logs CSV
     */
    private function generate_activity_logs_csv() {
        $logs = get_posts([
            'post_type' => 'wplm_activity_log',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $csv_data = [];
        $csv_data[] = [
            'ID',
            'Title',
            'Content',
            'Activity Type',
            'Object ID',
            'User ID',
            'Additional Data',
            'Created Date'
        ];

        foreach ($logs as $log) {
            $csv_data[] = [
                $log->ID,
                $log->post_title,
                $log->post_content,
                get_post_meta($log->ID, '_wplm_activity_type', true),
                get_post_meta($log->ID, '_wplm_object_id', true),
                get_post_meta($log->ID, '_wplm_user_id', true),
                get_post_meta($log->ID, '_wplm_additional_data', true),
                $log->post_date
            ];
        }

        return $this->array_to_csv($csv_data);
    }

    /**
     * Get products data for export
     */
    private function get_products_data() {
        $products = get_posts([
            'post_type' => 'wplm_product',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $data = [];
        foreach ($products as $product) {
            $data[] = [
                'id' => $product->ID,
                'product_name' => $product->post_title,
                'product_id' => get_post_meta($product->ID, '_wplm_product_id', true),
                'current_version' => get_post_meta($product->ID, '_wplm_current_version', true),
                'download_url' => get_post_meta($product->ID, '_wplm_download_url', true),
                'product_type' => get_post_meta($product->ID, '_wplm_product_type', true),
                'description' => $product->post_content,
                'created_date' => $product->post_date,
                'modified_date' => $product->post_modified
            ];
        }

        return $data;
    }

    /**
     * Get subscriptions data for export
     */
    private function get_subscriptions_data() {
        $subscriptions = get_posts([
            'post_type' => 'wplm_subscription',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $data = [];
        foreach ($subscriptions as $subscription) {
            $data[] = [
                'id' => $subscription->ID,
                'subscription_title' => $subscription->post_title,
                'customer_email' => get_post_meta($subscription->ID, '_wplm_customer_email', true),
                'product_id' => get_post_meta($subscription->ID, '_wplm_product_id', true),
                'status' => get_post_meta($subscription->ID, '_wplm_subscription_status', true),
                'billing_period' => get_post_meta($subscription->ID, '_wplm_billing_period', true),
                'billing_interval' => get_post_meta($subscription->ID, '_wplm_billing_interval', true),
                'trial_end_date' => get_post_meta($subscription->ID, '_wplm_trial_end_date', true),
                'next_payment_date' => get_post_meta($subscription->ID, '_wplm_next_payment_date', true),
                'end_date' => get_post_meta($subscription->ID, '_wplm_end_date', true),
                'created_date' => $subscription->post_date,
                'modified_date' => $subscription->post_modified
            ];
        }

        return $data;
    }

    /**
     * Get customers data for export
     */
    private function get_customers_data() {
        $customers = get_posts([
            'post_type' => 'wplm_customer',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $data = [];
        foreach ($customers as $customer) {
            $data[] = [
                'id' => $customer->ID,
                'customer_name' => $customer->post_title,
                'customer_email' => get_post_meta($customer->ID, '_wplm_customer_email', true),
                'total_licenses' => get_post_meta($customer->ID, '_wplm_total_licenses', true),
                'active_licenses' => get_post_meta($customer->ID, '_wplm_active_licenses', true),
                'first_license_date' => get_post_meta($customer->ID, '_wplm_first_license_date', true),
                'last_activity' => get_post_meta($customer->ID, '_wplm_last_activity', true),
                'customer_status' => get_post_meta($customer->ID, '_wplm_customer_status', true),
                'customer_source' => get_post_meta($customer->ID, '_wplm_customer_source', true),
                'address' => get_post_meta($customer->ID, '_wplm_address', true),
                'notes' => get_post_meta($customer->ID, '_wplm_notes', true),
                'tags' => get_post_meta($customer->ID, '_wplm_tags', true),
                'created_date' => $customer->post_date,
                'modified_date' => $customer->post_modified
            ];
        }

        return $data;
    }

    /**
     * Get settings data for export
     */
    private function get_settings_data() {
        $settings = [
            'wplm_api_key' => get_option('wplm_api_key'),
            'wplm_delete_on_uninstall' => get_option('wplm_delete_on_uninstall'),
            'wplm_export_import_type' => get_option('wplm_export_import_type'),
            'wplm_plugin_name' => get_option('wplm_plugin_name'),
            'wplm_default_duration_type' => get_option('wplm_default_duration_type'),
            'wplm_default_duration_value' => get_option('wplm_default_duration_value'),
            'wplm_default_activation_limit' => get_option('wplm_default_activation_limit'),
            'wplm_license_key_format' => get_option('wplm_license_key_format'),
            'wplm_email_notifications_enabled' => get_option('wplm_email_notifications_enabled'),
            'wplm_rest_api_enabled' => get_option('wplm_rest_api_enabled'),
        ];

        $data = [];
        foreach ($settings as $key => $value) {
            $data[] = [
                'option_name' => $key,
                'option_value' => is_array($value) ? json_encode($value) : $value
            ];
        }

        return $data;
    }

    /**
     * Get activity logs data for export
     */
    private function get_activity_logs_data() {
        $logs = get_posts([
            'post_type' => 'wplm_activity_log',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->ID,
                'title' => $log->post_title,
                'content' => $log->post_content,
                'activity_type' => get_post_meta($log->ID, '_wplm_activity_type', true),
                'object_id' => get_post_meta($log->ID, '_wplm_object_id', true),
                'user_id' => get_post_meta($log->ID, '_wplm_user_id', true),
                'additional_data' => get_post_meta($log->ID, '_wplm_additional_data', true),
                'created_date' => $log->post_date
            ];
        }

        return $data;
    }

    /**
     * Convert array to CSV string
     */
    private function array_to_csv($data) {
        $output = fopen('php://temp', 'r+');
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * AJAX handler for export data
     */
    public function ajax_export_data() {
        check_ajax_referer('wplm_export_nonce', 'nonce');

        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
        }

        $export_type = sanitize_text_field($_POST['export_type'] ?? 'all');
        $export_format = sanitize_text_field($_POST['export_format'] ?? 'csv');
        $include_settings = isset($_POST['include_settings']);
        $include_logs = isset($_POST['include_logs']);

        try {
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
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}