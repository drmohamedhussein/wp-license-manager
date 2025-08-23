<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprehensive Email Notification System for WPLM
 */
class WPLM_Email_Notification_System {

    private $templates_dir;

    public function __construct() {
        $this->templates_dir = WPLM_PLUGIN_DIR . 'templates/emails/';
        
        // Email hooks
        add_action('wplm_license_activated', [$this, 'send_license_activated_email'], 10, 2);
        add_action('wplm_license_deactivated', [$this, 'send_license_deactivated_email'], 10, 2);
        add_action('wplm_license_expired', [$this, 'send_license_expired_email'], 10, 2);
        add_action('wplm_license_expiring_soon', [$this, 'send_license_expiring_email'], 10, 2);
        add_action('wplm_license_created', [$this, 'send_license_created_email'], 10, 2);
        add_action('wplm_license_suspended', [$this, 'send_license_suspended_email'], 10, 2);
        
        // WooCommerce order hooks
        add_action('woocommerce_order_status_completed', [$this, 'send_order_license_email'], 20, 1);
        
        // Admin notifications
        add_action('wplm_license_limit_reached', [$this, 'send_admin_limit_notification'], 10, 2);
        add_action('wplm_suspicious_activity', [$this, 'send_admin_security_notification'], 10, 2);
        
        // Scheduled checks
        add_action('wplm_check_expiring_licenses_daily', [$this, 'check_expiring_licenses']);
        
        // Email templates
        add_filter('wplm_email_template_path', [$this, 'get_email_template_path'], 10, 2);
    }

    /**
     * Send license activation notification
     */
    public function send_license_activated_email($license_id, $domain) {
        $license = get_post($license_id);
        if (!$license) return;

        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        if (!$customer_email) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);

        $subject = sprintf(__('License Activated: %s', 'wp-license-manager'), $product_name);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'domain' => $domain,
            'activation_date' => current_time('mysql'),
            'customer_email' => $customer_email
        ];

        $this->send_email($customer_email, $subject, 'license-activated', $data);
        
        // Log activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log($license_id, 'email_sent', 'License activation email sent', [
                'email_type' => 'license_activated',
                'recipient' => $customer_email,
                'domain' => $domain
            ]);
        }
    }

    /**
     * Send license deactivation notification
     */
    public function send_license_deactivated_email($license_id, $domain) {
        $license = get_post($license_id);
        if (!$license) return;

        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        if (!$customer_email) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);

        $subject = sprintf(__('License Deactivated: %s', 'wp-license-manager'), $product_name);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'domain' => $domain,
            'deactivation_date' => current_time('mysql'),
            'customer_email' => $customer_email
        ];

        $this->send_email($customer_email, $subject, 'license-deactivated', $data);
    }

    /**
     * Send license expiration notification
     */
    public function send_license_expired_email($license_id, $expiry_date) {
        $license = get_post($license_id);
        if (!$license) return;

        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        if (!$customer_email) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);

        $subject = sprintf(__('License Expired: %s', 'wp-license-manager'), $product_name);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'expiry_date' => $expiry_date,
            'customer_email' => $customer_email,
            'renewal_url' => $this->get_renewal_url($license_id)
        ];

        $this->send_email($customer_email, $subject, 'license-expired', $data);
    }

    /**
     * Send license expiring soon notification
     */
    public function send_license_expiring_email($license_id, $expiry_date) {
        $license = get_post($license_id);
        if (!$license) return;

        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        if (!$customer_email) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);

        $days_until_expiry = ceil((strtotime($expiry_date) - time()) / (60 * 60 * 24));

        $subject = sprintf(__('License Expiring Soon: %s (%d days)', 'wp-license-manager'), $product_name, $days_until_expiry);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'expiry_date' => $expiry_date,
            'days_until_expiry' => $days_until_expiry,
            'customer_email' => $customer_email,
            'renewal_url' => $this->get_renewal_url($license_id)
        ];

        $this->send_email($customer_email, $subject, 'license-expiring', $data);
    }

    /**
     * Send license creation notification
     */
    public function send_license_created_email($license_id, $order_id = null) {
        $license = get_post($license_id);
        if (!$license) return;

        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        if (!$customer_email) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);

        $subject = sprintf(__('Your License Key: %s', 'wp-license-manager'), $product_name);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'customer_email' => $customer_email,
            'creation_date' => $license->post_date,
            'activation_limit' => get_post_meta($license_id, '_wplm_activation_limit', true) ?: 1,
            'expiry_date' => get_post_meta($license_id, '_wplm_expiry_date', true),
            'order_id' => $order_id,
            'download_url' => get_post_meta($license_id, '_wplm_download_url', true)
        ];

        $this->send_email($customer_email, $subject, 'license-created', $data);
    }

    /**
     * Send license suspended notification
     */
    public function send_license_suspended_email($license_id, $reason) {
        $license = get_post($license_id);
        if (!$license) return;

        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);
        if (!$customer_email) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);

        $subject = sprintf(__('License Suspended: %s', 'wp-license-manager'), $product_name);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'customer_email' => $customer_email,
            'suspension_reason' => $reason,
            'suspension_date' => current_time('mysql'),
            'support_url' => get_option('wplm_support_url', admin_url())
        ];

        $this->send_email($customer_email, $subject, 'license-suspended', $data);
    }

    /**
     * Send WooCommerce order license email
     */
    public function send_order_license_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $license_keys = get_post_meta($order_id, '_wplm_generated_license_keys', true);
        if (empty($license_keys)) return;

        $customer_email = $order->get_billing_email();
        $subject = sprintf(__('Your License Keys - Order #%s', 'wp-license-manager'), $order->get_order_number());

        $items_with_licenses = [];
        foreach ($order->get_items() as $item_id => $item) {
            $license_key = wc_get_order_item_meta($item_id, '_wplm_license_key', true);
            $license_keys_array = wc_get_order_item_meta($item_id, '_wplm_license_keys', true);
            
            if ($license_key || $license_keys_array) {
                $keys = [];
                if ($license_key) $keys[] = $license_key;
                if (is_array($license_keys_array)) $keys = array_merge($keys, $license_keys_array);
                
                $items_with_licenses[] = [
                    'product_name' => $item->get_name(),
                    'license_keys' => $keys,
                    'quantity' => $item->get_quantity()
                ];
            }
        }

        $data = [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'customer_email' => $customer_email,
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'items_with_licenses' => $items_with_licenses,
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'order_total' => $order->get_total(),
            'my_account_url' => wc_get_page_permalink('myaccount')
        ];

        $this->send_email($customer_email, $subject, 'order-license-delivery', $data);
    }

    /**
     * Send admin notification for license limit reached
     */
    public function send_admin_limit_notification($license_id, $attempted_domain) {
        $admin_email = get_option('admin_email');
        $license = get_post($license_id);
        if (!$license) return;

        $product_id = get_post_meta($license_id, '_wplm_product_id', true);
        $product_name = $this->get_product_name($product_id);
        $customer_email = get_post_meta($license_id, '_wplm_customer_email', true);

        $subject = sprintf(__('License Activation Limit Reached: %s', 'wp-license-manager'), $license->post_title);
        
        $data = [
            'license_key' => $license->post_title,
            'product_name' => $product_name,
            'customer_email' => $customer_email,
            'attempted_domain' => $attempted_domain,
            'activation_limit' => get_post_meta($license_id, '_wplm_activation_limit', true),
            'current_activations' => count(get_post_meta($license_id, '_wplm_activated_domains', true) ?: []),
            'admin_url' => admin_url('post.php?post=' . $license_id . '&action=edit')
        ];

        $this->send_email($admin_email, $subject, 'admin-limit-reached', $data);
    }

    /**
     * Send admin security notification
     */
    public function send_admin_security_notification($license_id, $activity_details) {
        $admin_email = get_option('admin_email');
        $license = get_post($license_id);
        if (!$license) return;

        $subject = sprintf(__('Suspicious License Activity: %s', 'wp-license-manager'), $license->post_title);
        
        $data = [
            'license_key' => $license->post_title,
            'activity_details' => $activity_details,
            'timestamp' => current_time('mysql'),
            'admin_url' => admin_url('post.php?post=' . $license_id . '&action=edit')
        ];

        $this->send_email($admin_email, $subject, 'admin-security-alert', $data);
    }

    /**
     * Check for expiring licenses (daily cron job)
     */
    public function check_expiring_licenses() {
        $warning_days = get_option('wplm_expiry_warning_days', 7);
        $warning_date = date('Y-m-d', strtotime("+{$warning_days} days"));

        global $wpdb;
        $expiring_licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value as expiry_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             AND pm.meta_key = '_wplm_expiry_date'
             AND pm.meta_value != ''
             AND pm.meta_value <= %s
             AND pm.meta_value >= %s",
            $warning_date,
            date('Y-m-d')
        ));

        foreach ($expiring_licenses as $license) {
            $already_notified = get_post_meta($license->ID, '_wplm_expiry_warning_sent', true);
            if (!$already_notified) {
                do_action('wplm_license_expiring_soon', $license->ID, $license->expiry_date);
                update_post_meta($license->ID, '_wplm_expiry_warning_sent', current_time('mysql'));
            }
        }

        // Check for expired licenses
        $expired_licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value as expiry_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             AND pm.meta_key = '_wplm_expiry_date'
             AND pm.meta_value != ''
             AND pm.meta_value < %s",
            date('Y-m-d')
        ));

        foreach ($expired_licenses as $license) {
            $status = get_post_meta($license->ID, '_wplm_status', true);
            if ($status === 'active') {
                update_post_meta($license->ID, '_wplm_status', 'expired');
                do_action('wplm_license_expired', $license->ID, $license->expiry_date);
            }
        }
    }

    /**
     * Send email using WordPress mail system
     */
    private function send_email($to, $subject, $template, $data) {
        // Check if email notifications are enabled
        if (!get_option('wplm_email_notifications_enabled', true)) {
            return false;
        }

        $template_content = $this->load_email_template($template, $data);
        if (!$template_content) {
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('wplm_from_name', get_bloginfo('name')) . ' <' . get_option('wplm_from_email', get_option('admin_email')) . '>'
        ];

        $result = wp_mail($to, $subject, $template_content, $headers);
        
        // Log email activity
        if (class_exists('WPLM_Activity_Logger')) {
            WPLM_Activity_Logger::log(0, 'email_sent', "Email sent: {$template}", [
                'recipient' => $to,
                'subject' => $subject,
                'template' => $template,
                'success' => $result
            ]);
        }

        return $result;
    }

    /**
     * Load and parse email template
     */
    private function load_email_template($template, $data) {
        $template_file = $this->templates_dir . $template . '.php';
        
        // Fallback to built-in template if custom doesn't exist
        if (!file_exists($template_file)) {
            return $this->get_builtin_template($template, $data);
        }

        ob_start();
        extract($data);
        include $template_file;
        return ob_get_clean();
    }

    /**
     * Get built-in email templates
     */
    private function get_builtin_template($template, $data) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        $header = $this->get_email_header($site_name);
        $footer = $this->get_email_footer($site_name, $site_url);

        switch ($template) {
            case 'license-activated':
                $body = sprintf(
                    '<h2>%s</h2>
                    <p>%s</p>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <strong>%s:</strong> <code>%s</code><br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s
                    </div>
                    <p>%s</p>',
                    __('License Activated Successfully', 'wp-license-manager'),
                    __('Your license has been successfully activated on the following domain:', 'wp-license-manager'),
                    __('License Key', 'wp-license-manager'),
                    esc_html($data['license_key']),
                    __('Product', 'wp-license-manager'),
                    esc_html($data['product_name']),
                    __('Domain', 'wp-license-manager'),
                    esc_html($data['domain']),
                    __('Activation Date', 'wp-license-manager'),
                    esc_html($data['activation_date']),
                    __('Thank you for using our software!', 'wp-license-manager')
                );
                break;

            case 'license-created':
                $expiry_text = !empty($data['expiry_date']) ? $data['expiry_date'] : __('Lifetime', 'wp-license-manager');
                $body = sprintf(
                    '<h2>%s</h2>
                    <p>%s</p>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <strong>%s:</strong> <code style="font-size: 16px; color: #d63384;">%s</code><br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s
                    </div>
                    <p>%s</p>
                    <p><strong>%s:</strong> %s</p>',
                    __('Your License Key', 'wp-license-manager'),
                    __('Thank you for your purchase! Here are your license details:', 'wp-license-manager'),
                    __('License Key', 'wp-license-manager'),
                    esc_html($data['license_key']),
                    __('Product', 'wp-license-manager'),
                    esc_html($data['product_name']),
                    __('Activations Allowed', 'wp-license-manager'),
                    esc_html($data['activation_limit']),
                    __('Expires', 'wp-license-manager'),
                    esc_html($expiry_text),
                    __('Please keep this license key safe. You will need it to activate the software.', 'wp-license-manager'),
                    __('Important', 'wp-license-manager'),
                    __('Do not share your license key with others. Each license is tied to your account.', 'wp-license-manager')
                );
                break;

            case 'license-expiring':
                $body = sprintf(
                    '<h2 style="color: #ff6b6b;">%s</h2>
                    <p>%s</p>
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <strong>%s:</strong> <code>%s</code><br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> <strong style="color: #e17055;">%d %s</strong>
                    </div>
                    <p>%s</p>
                    %s',
                    __('License Expiring Soon!', 'wp-license-manager'),
                    __('Your license will expire soon. Please renew to continue using the software.', 'wp-license-manager'),
                    __('License Key', 'wp-license-manager'),
                    esc_html($data['license_key']),
                    __('Product', 'wp-license-manager'),
                    esc_html($data['product_name']),
                    __('Expiry Date', 'wp-license-manager'),
                    esc_html($data['expiry_date']),
                    __('Days Remaining', 'wp-license-manager'),
                    $data['days_until_expiry'],
                    __('days', 'wp-license-manager'),
                    __('Renew your license to avoid any interruption in service.', 'wp-license-manager'),
                    !empty($data['renewal_url']) ? sprintf('<p><a href="%s" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">%s</a></p>', esc_url($data['renewal_url']), __('Renew License', 'wp-license-manager')) : ''
                );
                break;

            case 'order-license-delivery':
                $items_html = '';
                foreach ($data['items_with_licenses'] as $item) {
                    $keys_html = '';
                    foreach ($item['license_keys'] as $key) {
                        $keys_html .= '<code style="display: block; background: #f1f3f4; padding: 5px; margin: 2px 0; border-radius: 3px;">' . esc_html($key) . '</code>';
                    }
                    $items_html .= sprintf(
                        '<div style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px;">
                            <h4>%s</h4>
                            <p><strong>%s:</strong></p>
                            %s
                        </div>',
                        esc_html($item['product_name']),
                        __('License Key(s)', 'wp-license-manager'),
                        $keys_html
                    );
                }

                $body = sprintf(
                    '<h2>%s</h2>
                    <p>%s <strong>#%s</strong>.</p>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s<br>
                        <strong>%s:</strong> %s
                    </div>
                    <h3>%s</h3>
                    %s
                    <p>%s</p>
                    %s',
                    __('Order Complete - Your License Keys', 'wp-license-manager'),
                    __('Thank you for your order', 'wp-license-manager'),
                    esc_html($data['order_number']),
                    __('Customer', 'wp-license-manager'),
                    esc_html($data['customer_name']),
                    __('Order Date', 'wp-license-manager'),
                    esc_html($data['order_date']),
                    __('Total', 'wp-license-manager'),
                    wc_price($data['order_total']),
                    __('Your License Keys', 'wp-license-manager'),
                    $items_html,
                    __('Keep these license keys safe! You can also view them in your account dashboard.', 'wp-license-manager'),
                    !empty($data['my_account_url']) ? sprintf('<p><a href="%s" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">%s</a></p>', esc_url($data['my_account_url']), __('View in My Account', 'wp-license-manager')) : ''
                );
                break;

            default:
                return false;
        }

        return $header . $body . $footer;
    }

    /**
     * Get email header HTML
     */
    private function get_email_header($site_name) {
        return sprintf(
            '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>%s</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #667eea 0%%, #764ba2 100%%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                    .content { background: white; padding: 30px; border: 1px solid #ddd; }
                    .footer { background: #f8f9fa; padding: 20px; text-align: center; border-radius: 0 0 8px 8px; border: 1px solid #ddd; border-top: none; }
                    code { background: #f1f3f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1 style="margin: 0;">%s</h1>
                    </div>
                    <div class="content">',
            esc_html($site_name),
            esc_html($site_name)
        );
    }

    /**
     * Get email footer HTML
     */
    private function get_email_footer($site_name, $site_url) {
        return sprintf(
            '    </div>
                    <div class="footer">
                        <p>%s <a href="%s">%s</a></p>
                        <p style="font-size: 12px; color: #666;">%s</p>
                    </div>
                </div>
            </body>
            </html>',
            __('This email was sent by', 'wp-license-manager'),
            esc_url($site_url),
            esc_html($site_name),
            __('Please do not reply to this automated email.', 'wp-license-manager')
        );
    }

    /**
     * Get product name from product ID
     */
    private function get_product_name($product_id) {
        if (empty($product_id)) return __('Unknown Product', 'wp-license-manager');
        
        $product = get_page_by_title($product_id, OBJECT, 'wplm_product');
        return $product ? $product->post_title : $product_id;
    }

    /**
     * Get renewal URL for license
     */
    private function get_renewal_url($license_id) {
        $renewal_url = get_option('wplm_renewal_url');
        if ($renewal_url) {
            return add_query_arg('license_id', $license_id, $renewal_url);
        }
        return '';
    }

    /**
     * Get email template path
     */
    public function get_email_template_path($path, $template) {
        return $this->templates_dir . $template . '.php';
    }
}
