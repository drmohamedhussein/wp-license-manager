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
        try {
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

            // Optimize WordPress function loading - only load if not already available
            if (!function_exists('get_current_user_id')) {
                // Use a more efficient approach - check if we're in admin context
                if (is_admin()) {
                    $user_id = get_current_user_id();
                } else {
                    // For frontend, try to get user ID from session or set to 0
                    $user_id = 0;
                }
            } else {
                $user_id = get_current_user_id();
            }

            // Check if we should use database table instead of post meta for better performance
            if (self::should_use_database_table()) {
                return self::log_to_database($sanitized_object_id, $sanitized_event_type, $sanitized_description, $sanitized_data, $user_id);
            }

            // Fallback to post meta method
            return self::log_to_post_meta($sanitized_object_id, $sanitized_event_type, $sanitized_description, $sanitized_data, $user_id);

        } catch (Exception $e) {
            error_log('WPLM_Activity_Logger: Exception in log method: ' . $e->getMessage());
            return false;
        }
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
        try {
            $sanitized_object_id = absint($object_id);

            // Check if we should use database table
            if (self::should_use_database_table()) {
                return self::get_log_from_database($sanitized_object_id);
            }

            // Fallback to post meta method
            $log = get_post_meta($sanitized_object_id, '_wplm_activity_log', true);
            return is_array($log) ? self::sanitize_recursive_data($log) : [];
            
        } catch (Exception $e) {
            error_log('WPLM_Activity_Logger: Exception in get_log method: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if we should use database table for better performance
     */
    private static function should_use_database_table(): bool {
        // Check if the database table exists and is accessible
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_activity_logs';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        // Also check if we have a large number of logs (indicating performance issues)
        if ($table_exists) {
            $log_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            return $log_count > 1000; // Use table if more than 1000 logs exist
        }
        
        return false;
    }

    /**
     * Log to database table for better performance
     */
    private static function log_to_database(int $object_id, string $event_type, string $description, array $data, int $user_id): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_activity_logs';
        
        $result = $wpdb->insert(
            $table_name,
            [
                'object_id' => $object_id,
                'event_type' => $event_type,
                'description' => $description,
                'data' => json_encode($data),
                'user_id' => $user_id,
                'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'created_at' => current_time('mysql', true)
            ],
            [
                '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s'
            ]
        );
        
        if ($result === false) {
            error_log('WPLM_Activity_Logger: Failed to insert log into database: ' . $wpdb->last_error);
            return false;
        }
        
        // Clean up old logs to prevent table bloat
        self::cleanup_old_logs();
        
        return true;
    }

    /**
     * Log to post meta (fallback method)
     */
    private static function log_to_post_meta(int $object_id, string $event_type, string $description, array $data, int $user_id): bool {
        $activity_log = get_post_meta($object_id, '_wplm_activity_log', true);

        if (!is_array($activity_log)) {
            $activity_log = [];
        }

        $log_entry = [
            'timestamp'   => current_time('mysql', true),
            'user_id'     => $user_id,
            'event_type'  => $event_type,
            'description' => $description,
            'data'        => $data,
            'ip_address'  => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'  => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];

        $activity_log[] = $log_entry;

        // Limit the log to prevent memory issues (reduced from 50 to 25)
        $activity_log = array_slice($activity_log, -25);

        if (false === update_post_meta($object_id, '_wplm_activity_log', $activity_log)) {
            error_log(sprintf(esc_html__('WPLM_Activity_Logger: Failed to save activity log for object ID %d.', 'wplm'), $object_id));
            return false;
        }
        
        return true;
    }

    /**
     * Get log from database table
     */
    private static function get_log_from_database(int $object_id): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_activity_logs';
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE object_id = %d ORDER BY created_at DESC LIMIT 100",
                $object_id
            ),
            ARRAY_A
        );
        
        if (empty($logs)) {
            return [];
        }
        
        // Process and sanitize the logs
        $processed_logs = [];
        foreach ($logs as $log) {
            $processed_logs[] = [
                'timestamp' => $log['created_at'],
                'user_id' => (int) $log['user_id'],
                'event_type' => $log['event_type'],
                'description' => $log['description'],
                'data' => json_decode($log['data'], true) ?: [],
                'ip_address' => $log['ip_address'],
                'user_agent' => $log['user_agent']
            ];
        }
        
        return $processed_logs;
    }

    /**
     * Clean up old logs to prevent table bloat
     */
    private static function cleanup_old_logs(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wplm_activity_logs';
        
        // Keep only logs from the last 90 days
        $wpdb->query(
            "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        
        // Also limit total logs per object to prevent excessive storage
        $wpdb->query(
            "DELETE t1 FROM {$table_name} t1
             INNER JOIN (
                 SELECT object_id, id FROM {$table_name} t2
                 WHERE t2.id NOT IN (
                     SELECT id FROM (
                         SELECT id FROM {$table_name} t3
                         WHERE t3.object_id = t2.object_id
                         ORDER BY created_at DESC
                         LIMIT 50
                     ) t4
                 )
             ) t5 ON t1.id = t5.id"
        );
    }

    /**
     * Create activity logs table if it doesn't exist
     */
    public static function maybe_create_table(): void {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $table_name = $wpdb->prefix . 'wplm_activity_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            object_id bigint(20) NOT NULL,
            event_type varchar(100) NOT NULL,
            description text NOT NULL,
            data longtext,
            user_id bigint(20) NOT NULL DEFAULT 0,
            ip_address varchar(45),
            user_agent text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY object_id (object_id),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) {$charset_collate};";
        
        dbDelta($sql);
        
        if ($wpdb->last_error) {
            error_log('WPLM_Activity_Logger: Error creating activity logs table: ' . $wpdb->last_error);
        }
    }
}
