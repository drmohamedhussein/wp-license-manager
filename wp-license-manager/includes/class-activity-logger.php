<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages logging of license-related activities.
 */
class WPLM_Activity_Logger {

    /**
     * Logs an activity related to a license or product.
     *
     * @param int    $object_id   The ID of the license or product post.
     * @param string $event_type  A unique identifier for the event (e.g., 'license_activated', 'product_updated').
     * @param string $description A human-readable description of the activity.
     * @param array  $data        Optional array of additional data to store.
     * @return bool True on success, false on failure.
     */
    public static function log(int $object_id, string $event_type, string $description, array $data = []): bool {
        // Validate $object_id
        $sanitized_object_id = absint($object_id);
        if (0 === $sanitized_object_id && $object_id !== 0) { // Allow 0 for non-object specific logs
            error_log(sprintf(esc_html__('WPLM_Activity_Logger: Invalid object ID provided: %s.', 'wplm'), $object_id));
            return false;
        }

        // Sanitize other simple inputs
        $sanitized_event_type = sanitize_key($event_type);
        $sanitized_description = sanitize_text_field($description);

        // Sanitize data array recursively
        $sanitized_data = self::sanitize_recursive_data($data);

        if (!function_exists('get_current_user_id')) { // Ensure WordPress functions are loaded
            require_once(ABSPATH . 'wp-includes/pluggable.php');
        }

        $user_id = get_current_user_id();
        $activity_log = get_post_meta($sanitized_object_id, '_wplm_activity_log', true);

        if (!is_array($activity_log)) {
            $activity_log = [];
        }

        $log_entry = [
            'timestamp'   => current_time('mysql', true), // UTC time
            'user_id'     => $user_id,
            'event_type'  => $sanitized_event_type,
            'description' => $sanitized_description,
            'data'        => $sanitized_data,
            'ip_address'  => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'  => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];

        $activity_log[] = $log_entry;

        // Limit the log to a reasonable number of entries (e.g., last 50)
        $activity_log = array_slice($activity_log, -50);

        if (false === update_post_meta($sanitized_object_id, '_wplm_activity_log', $activity_log)) {
            error_log(sprintf(esc_html__('WPLM_Activity_Logger: Failed to save activity log for object ID %d.', 'wplm'), $sanitized_object_id));
            return false;
        }
        return true; // Indicate success
    }

    /**
     * Recursively sanitizes data array for logging.
     *
     * @param array $data The data array to sanitize.
     * @return array The sanitized data array.
     */
    private static function sanitize_recursive_data(array $data): array {
        $sanitized_data = [];
        foreach ($data as $key => $value) {
            $sanitized_key = sanitize_key($key);
            if (is_array($value)) {
                $sanitized_data[$sanitized_key] = self::sanitize_recursive_data($value);
            } else {
                $sanitized_data[$sanitized_key] = sanitize_text_field($value);
            }
        }
        return $sanitized_data;
    }

    /**
     * Retrieves the activity log for a given license or product.
     *
     * @param int $object_id The ID of the license or product post.
     * @return array The activity log array, or an empty array if none exists.
     */
    public static function get_log(int $object_id): array {
        $sanitized_object_id = absint($object_id);

        $log = get_post_meta($sanitized_object_id, '_wplm_activity_log', true);
        return is_array($log) ? self::sanitize_recursive_data($log) : [];
    }
}
