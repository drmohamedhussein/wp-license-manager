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
        $notification = [
            'id' => uniqid('wplm_'),
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'created' => time(),
            'expires' => $expires,
            'dismissed' => false
        ];
        
        $this->notifications[] = $notification;
        update_option('wplm_notifications', $this->notifications);
        
        return $notification['id'];
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
    }

    private function render_admin_notification($notification) {
        $class = 'notice notice-info is-dismissible wplm-notification';
        
        ?>
        <div class="<?php echo esc_attr($class); ?>" data-notification-id="<?php echo esc_attr($notification['id']); ?>">
            <p><strong><?php echo esc_html($notification['message']); ?></strong></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.wplm-notification .notice-dismiss').on('click', function() {
                var notificationId = $(this).closest('.wplm-notification').data('notification-id');
                $.post(ajaxurl, {
                    action: 'wplm_dismiss_notification',
                    notification_id: notificationId,
                    nonce: '<?php echo wp_create_nonce('wplm_dismiss_notification'); ?>'
                });
            });
        });
        </script>
        <?php
    }

    public function dismiss_notification() {
        if (!wp_verify_nonce($_POST['nonce'], 'wplm_dismiss_notification')) {
            wp_die('Security check failed');
        }
        
        $notification_id = sanitize_text_field($_POST['notification_id']);
        
        foreach ($this->notifications as &$notification) {
            if ($notification['id'] === $notification_id) {
                $notification['dismissed'] = true;
                break;
            }
        }
        
        update_option('wplm_notifications', $this->notifications);
        wp_die('success');
    }

    public function on_license_activated($license_id, $domain) {
        $license_key = get_the_title($license_id);
        $message = sprintf('License %s activated on %s', $license_key, $domain);
        $this->add_notification('license_activated', $message, ['license_id' => $license_id]);
    }

    public function on_license_expired($license_id) {
        $license_key = get_the_title($license_id);
        $message = sprintf('License %s has expired', $license_key);
        $this->add_notification('license_expired', $message, ['license_id' => $license_id]);
    }

    public function on_security_incident($license_key, $incident_type, $severity, $description, $additional_data) {
        $message = sprintf('Security incident: %s for license %s', $description, $license_key);
        $this->add_notification('security_incident', $message, [
            'license_key' => $license_key,
            'incident_type' => $incident_type,
            'severity' => $severity
        ]);
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
        
        foreach ($licenses as $license) {
            $expiry_date = get_post_meta($license->ID, '_wplm_expiry_date', true);
            if (empty($expiry_date)) continue;
            
            $days_remaining = ceil((strtotime($expiry_date) - time()) / DAY_IN_SECONDS);
            
            if ($days_remaining <= 7 && $days_remaining > 0) {
                $notification_transient_key = 'wplm_notif_expiring_' . $license->ID;
                if (get_transient($notification_transient_key)) {
                    continue; // Notification already sent recently
                }

                $message = sprintf('License %s expires in %d days', get_the_title($license->ID), $days_remaining);
                $this->add_notification('license_expiring', $message, ['license_id' => $license->ID]);
                set_transient($notification_transient_key, true, DAY_IN_SECONDS); // Notify once per day
            }
        }
    }

    private function cleanup_old_notifications() {
        $max_age = 30 * DAY_IN_SECONDS;
        
        $this->notifications = array_filter($this->notifications, function($notification) use ($max_age) {
            return (time() - $notification['created']) < $max_age;
        });
        
        update_option('wplm_notifications', $this->notifications);
    }

    public function get_notification_count() {
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
        if (!is_email($customer_email)) {
            error_log('WPLM: Invalid customer email for license delivery: ' . $customer_email);
            return;
        }

        $subject = sprintf(__('Your License Key for Order #%d', 'wp-license-manager'), $order_id);
        
        $message = sprintf(
            __('Hello there,<br><br>Thank you for your purchase from %s. Here is your license key:<br><br><strong>License Key:</strong> <code>%s</code><br><br>You can manage your licenses in your account dashboard at: %s<br><br>Thanks,<br>%s', 'wp-license-manager'),
            get_bloginfo('name'),
            esc_html($license_key),
            esc_url(wc_get_account_endpoint_url('licenses')),
            get_bloginfo('name')
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email', '') . '>'
        ];

        $sent = wp_mail($customer_email, $subject, $message, $headers);

        if (!$sent) {
            error_log(sprintf('WPLM: Failed to send license email for order #%d to %s.', $order_id, $customer_email));
        } else {
            if (class_exists('WPLM_Activity_Logger')) {
                WPLM_Activity_Logger::log(
                    0, // No specific license ID yet, or find it if needed
                    'license_email_sent',
                    sprintf('License email sent to %s for order #%d, license key: %s', $customer_email, $order_id, $license_key),
                    ['customer_email' => $customer_email, 'order_id' => $order_id, 'license_key' => $license_key]
                );
            }
        }
    }
}
