<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notification Manager for WPLM
 */
class WPLM_Notification_Manager {
    
    private $notifications = [];
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_notices', [$this, 'display_admin_notifications']);
        add_action('wp_ajax_wplm_dismiss_notification', [$this, 'dismiss_notification']);
        
        // License event hooks
        add_action('wplm_license_activated', [$this, 'on_license_activated'], 10, 2);
        add_action('wplm_license_expired', [$this, 'on_license_expired'], 10, 1);
        add_action('wplm_security_incident', [$this, 'on_security_incident'], 10, 5);
        
        // Daily checks
        if (!wp_next_scheduled('wplm_daily_notification_check')) {
            wp_schedule_event(time(), 'daily', 'wplm_daily_notification_check');
        }
        add_action('wplm_daily_notification_check', [$this, 'perform_daily_checks']);
    }

    public function init() {
        $this->load_notifications();
    }

    private function load_notifications() {
        $notifications = get_option('wplm_notifications', []);
        $this->notifications = array_filter($notifications, function($notification) {
            return empty($notification['expires']) || time() < $notification['expires'];
        });
        update_option('wplm_notifications', $this->notifications);
    }

    public function add_notification($type, $message, $data = [], $expires = null) {
        $sanitized_message = sanitize_text_field($message);
        $sanitized_data = $this->sanitize_notification_data($data);

        $notification = [
            'id' => uniqid('wplm_'),
            'type' => sanitize_key($type), // Sanitize type to ensure it's a safe key
            'message' => $sanitized_message,
            'data' => $sanitized_data,
            'created' => time(),
            'expires' => $expires,
            'dismissed' => false
        ];
        
        $this->notifications[] = $notification;
        if (false === update_option('wplm_notifications', $this->notifications)) {
            error_log(esc_html__('WPLM: Failed to save notifications to options.', 'wplm'));
            return false; // Indicate failure to add notification
        }
        
        return $notification['id'];
    }

    /**
     * Recursively sanitizes notification data.
     *
     * @param array $data The data array to sanitize.
     * @return array The sanitized data array.
     */
    private function sanitize_notification_data(array $data): array {
        $sanitized_data = [];
        foreach ($data as $key => $value) {
            $sanitized_key = sanitize_key($key);
            if (is_array($value)) {
                $sanitized_data[$sanitized_key] = $this->sanitize_notification_data($value);
            } else {
                $sanitized_data[$sanitized_key] = sanitize_text_field($value);
            }
        }
        return $sanitized_data;
    }

    public function display_admin_notifications() {
        if (!current_user_can('manage_wplm_licenses')) {
            return;
        }
        
        $unread_notifications = array_filter($this->notifications, function($notification) {
            return !$notification['dismissed'];
        });
        
        foreach (array_slice($unread_notifications, -3) as $notification) {
            $this->render_admin_notification($notification);
        }

        // Enqueue script for notification dismissal
        wp_enqueue_script('wplm-notifications', WPLM_URL . 'assets/js/notifications.js', ['jquery'], WPLM_VERSION, true);
        wp_localize_script(
            'wplm-notifications',
            'wplm_notification_ajax',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wplm_dismiss_notification'),
            ]
        );
    }

    private function render_admin_notification($notification) {
        // Determine the notice class based on type
        $class = 'notice is-dismissible wplm-notification';
        switch ($notification['type']) {
            case 'success':
                $class .= ' notice-success';
                break;
            case 'error':
                $class .= ' notice-error';
                break;
            case 'warning':
                $class .= ' notice-warning';
                break;
            default:
                $class .= ' notice-info';
                break;
        }

        $notification_message = wp_kses_post($notification['message']); // Sanitize message for output
        
        ?>
        <div class="<?php echo esc_attr($class); ?>" data-notification-id="<?php echo esc_attr($notification['id']); ?>">
            <p><strong><?php echo $notification_message; ?></strong></p>
        </div>
        
        <?php
    }

    public function dismiss_notification() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wplm_dismiss_notification')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'wplm')], 403);
        }
        
        $notification_id = sanitize_text_field($_POST['notification_id'] ?? '');
        if (empty($notification_id)) {
            wp_send_json_error(['message' => esc_html__('Notification ID is missing.', 'wplm')], 400);
        }

        $found = false;
        foreach ($this->notifications as &$notification) {
            if ($notification['id'] === $notification_id) {
                $notification['dismissed'] = true;
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(['message' => esc_html__('Notification not found.', 'wplm')], 404);
        }
        
        if (false === update_option('wplm_notifications', $this->notifications)) {
            error_log(esc_html__('WPLM: Failed to save dismissed notification status.', 'wplm'));
            wp_send_json_error(['message' => esc_html__('Failed to dismiss notification due to a database error.', 'wplm')], 500);
        }
        wp_send_json_success(['message' => esc_html__('Notification dismissed.', 'wplm')]);
    }

    public function on_license_activated($license_id, $domain) {
        // Ensure license_id is an integer and get_the_title is safe
        $license_id = absint($license_id);
        if (0 === $license_id) {
            error_log(esc_html__('WPLM: Invalid license ID for on_license_activated.', 'wplm'));
            return;
        }

        $license_key = get_the_title($license_id);
        $sanitized_domain = sanitize_text_field($domain); // Sanitize domain before use

        $message = sprintf(esc_html__('License %1$s activated on %2$s', 'wplm'), esc_html($license_key), esc_html($sanitized_domain));
        $this->add_notification('license_activated', $message, ['license_id' => $license_id, 'domain' => $sanitized_domain]);
    }

    public function on_license_expired($license_id) {
        // Ensure license_id is an integer and get_the_title is safe
        $license_id = absint($license_id);
        if (0 === $license_id) {
            error_log(esc_html__('WPLM: Invalid license ID for on_license_expired.', 'wplm'));
            return;
        }

        $license_key = get_the_title($license_id);
        $message = sprintf(esc_html__('License %s has expired', 'wplm'), esc_html($license_key));
        $this->add_notification('license_expired', $message, ['license_id' => $license_id]);
    }

    public function on_security_incident($license_key, $incident_type, $severity, $description, $additional_data) {
        // Sanitize all incoming data
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_incident_type = sanitize_key($incident_type);
        $sanitized_severity = sanitize_key($severity);
        $sanitized_description = sanitize_text_field($description);
        // Assuming additional_data is an array and needs recursive sanitization or specific handling
        // For simplicity, we'll convert it to a string for logging, or you might sanitize it recursively like in add_notification
        $sanitized_additional_data_log = print_r($additional_data, true);

        $message = sprintf(esc_html__('Security incident: %1$s for license %2$s (Severity: %3$s)', 'wplm'), esc_html($sanitized_description), esc_html($sanitized_license_key), esc_html($sanitized_severity));
        $this->add_notification(
            'security_incident',
            $message,
            [
                'license_key' => $sanitized_license_key,
                'incident_type' => $sanitized_incident_type,
                'severity' => $sanitized_severity,
                'description' => $sanitized_description,
                'additional_data' => $sanitized_additional_data_log // Log string representation
            ]
        );
    }

    public function perform_daily_checks() {
        $this->check_expiring_licenses();
        $this->cleanup_old_notifications();
    }

    private function check_expiring_licenses() {
        $licenses = get_posts([
            'post_type' => 'wplm_license',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                ['key' => '_wplm_status', 'value' => 'active'],
                ['key' => '_wplm_expiry_date', 'value' => '', 'compare' => '!=']
            ]
        ]);
        
        if (empty($licenses)) {
            return; // No active licenses to check
        }

        foreach ($licenses as $license) {
            $expiry_date = get_post_meta($license->ID, '_wplm_expiry_date', true);
            if (empty($expiry_date)) continue; // Skip if no expiry date (e.g., lifetime license)

            $expiry_timestamp = strtotime($expiry_date);
            if (false === $expiry_timestamp) {
                error_log(sprintf(esc_html__('WPLM: Invalid expiry date format for license ID %d: %s', 'wplm'), $license->ID, $expiry_date));
                continue; // Skip if expiry date is invalid
            }
            
            $days_remaining = ceil(($expiry_timestamp - time()) / DAY_IN_SECONDS);
            
            if ($days_remaining <= 7 && $days_remaining > 0) {
                $notification_transient_key = 'wplm_notif_expiring_' . $license->ID;
                if (get_transient($notification_transient_key)) {
                    continue; // Notification already sent recently
                }

                $message = sprintf(esc_html__('License %1$s expires in %2$d days.', 'wplm'), esc_html(get_the_title($license->ID)), $days_remaining);
                $this->add_notification('license_expiring', $message, ['license_id' => $license->ID, 'days_remaining' => $days_remaining]);
                set_transient($notification_transient_key, true, DAY_IN_SECONDS); // Notify once per day
            }
        }
    }

    private function cleanup_old_notifications() {
        $max_age = 30 * DAY_IN_SECONDS;
        
        $initial_count = count($this->notifications);

        $this->notifications = array_filter($this->notifications, function($notification) use ($max_age) {
            return (time() - $notification['created']) < $max_age;
        });

        if (count($this->notifications) !== $initial_count) {
            if (false === update_option('wplm_notifications', $this->notifications)) {
                error_log(esc_html__('WPLM: Failed to clean up old notifications.', 'wplm'));
            }
        }
    }

    public function get_notification_count(): int {
        // Ensure notifications are loaded before counting
        if (empty($this->notifications) && current_filter() !== 'init') {
            $this->load_notifications();
        }

        return count(array_filter($this->notifications, function($notification) {
            return !$notification['dismissed'];
        }));
    }

    /**
     * Send license delivery email to customer.
     *
     * @param string $customer_email The customer's email address.
     * @param string $license_key The generated license key.
     * @param int $order_id The WooCommerce order ID.
     * @param WC_Order $order The WooCommerce order object.
     */
    public function send_license_delivery_email(string $customer_email, string $license_key, int $order_id, $order) {
        // Validate customer email
        if (!is_email($customer_email)) {
            error_log(sprintf(esc_html__('WPLM: Invalid customer email for license delivery: %s', 'wplm'), $customer_email));
            return;
        }
        
        // Sanitize license key and order ID
        $sanitized_license_key = sanitize_text_field($license_key);
        $sanitized_order_id = absint($order_id);

        // Additional validation for order object if necessary, e.g., ensure it's a WC_Order instance
        if (!is_a($order, 'WC_Order')) {
            error_log(sprintf(esc_html__('WPLM: Invalid order object provided for license delivery to %s for order #%d.', 'wplm'), $customer_email, $sanitized_order_id));
            return;
        }

        $subject = sprintf(esc_html__('Your License Key for Order #%d', 'wp-license-manager'), $sanitized_order_id);
        
        $message = sprintf(
            esc_html__('Hello there,<br><br>Thank you for your purchase from %1$s. Here is your license key:<br><br><strong>License Key:</strong> <code>%2$s</code><br><br>You can manage your licenses in your account dashboard at: %3$s<br><br>Thanks,<br>%4$s', 'wp-license-manager'),
            esc_html(get_bloginfo('name')),
            esc_html($sanitized_license_key),
            esc_url(wc_get_account_endpoint_url('licenses')),
            esc_html(get_bloginfo('name'))
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . esc_html(get_bloginfo('name')) . ' <' . sanitize_email(get_option('admin_email')) . '>'
        ];

        $sent = wp_mail($customer_email, $subject, $message, $headers);

        if (!$sent) {
            error_log(sprintf(esc_html__('WPLM: Failed to send license email for order #%d to %s.', 'wplm'), $sanitized_order_id, $customer_email));
        } else {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0, // No specific license ID yet, or find it if needed
                    'license_email_sent',
                    sprintf(esc_html__('License email sent to %1$s for order #%2$d, license key: %3$s', 'wplm'), $customer_email, $sanitized_order_id, $sanitized_license_key),
                    ['customer_email' => $customer_email, 'order_id' => $sanitized_order_id, 'license_key' => $sanitized_license_key]
                );
            }
        }
    }
}