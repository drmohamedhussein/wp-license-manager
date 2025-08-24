<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Licensing - Admin Interface
 * Part 3 of the split WPLM_Advanced_Licensing class
 */
class WPLM_Advanced_Licensing_Admin {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_license_type_meta_box']);
        add_action('save_post', [$this, 'save_license_type_meta']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=wplm_license',
            esc_html__('Advanced Licensing', 'wplm'),
            esc_html__('Advanced', 'wplm'),
            'manage_options',
            'wplm-advanced-licensing',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Add license type meta box
     */
    public function add_license_type_meta_box() {
        add_meta_box(
            'wplm_license_type',
            esc_html__('License Type & Advanced Settings', 'wplm'),
            [$this, 'render_license_type_meta_box'],
            'wplm_license',
            'side',
            'high'
        );
    }

    /**
     * Render license type meta box
     */
    public function render_license_type_meta_box($post) {
        wp_nonce_field('wplm_license_type_meta', 'wplm_license_type_nonce');
        
        $license_type_id = get_post_meta($post->ID, '_wplm_license_type', true);
        $license_types = $this->get_all_license_types();
        
        ?>
        <p>
            <label for="wplm_license_type"><?php esc_html_e('License Type:', 'wplm'); ?></label>
            <select name="wplm_license_type" id="wplm_license_type" style="width: 100%;">
                <option value=""><?php esc_html_e('Default', 'wplm'); ?></option>
                <?php foreach ($license_types as $type): ?>
                    <option value="<?php echo esc_attr($type->id); ?>" <?php selected($license_type_id, $type->id); ?>>
                        <?php echo esc_html($type->type_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        
        <?php if ($license_type_id): ?>
            <div class="wplm-license-type-info">
                <?php $type = $this->get_license_type($license_type_id); ?>
                <?php if ($type): ?>
                    <p><strong><?php esc_html_e('Max Domains:', 'wplm'); ?></strong> 
                       <?php echo $type->max_domains == -1 ? esc_html__('Unlimited', 'wplm') : esc_html($type->max_domains); ?>
                    </p>
                    <p><strong><?php esc_html_e('Check Interval:', 'wplm'); ?></strong> 
                       <?php echo esc_html($type->check_interval); ?> hours
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Save license type meta
     */
    public function save_license_type_meta($post_id) {
        if (!isset($_POST['wplm_license_type_nonce']) || !wp_verify_nonce($_POST['wplm_license_type_nonce'], 'wplm_license_type_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $license_type_id = intval($_POST['wplm_license_type'] ?? 0);
        update_post_meta($post_id, '_wplm_license_type', $license_type_id);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }
        
        if (get_post_type() === 'wplm_license') {
            wp_enqueue_script(
                'wplm-advanced-admin',
                plugin_dir_url(__FILE__) . '../assets/js/advanced-admin.js',
                ['jquery'],
                WPLM_VERSION,
                true
            );
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Advanced Licensing', 'wplm'); ?></h1>
            <p><?php esc_html_e('Advanced licensing features and settings.', 'wplm'); ?></p>
            
            <div class="wplm-advanced-overview">
                <h2><?php esc_html_e('Overview', 'wplm'); ?></h2>
                <p><?php esc_html_e('This section provides advanced licensing features including:', 'wplm'); ?></p>
                <ul>
                    <li><?php esc_html_e('License type management', 'wplm'); ?></li>
                    <li><?php esc_html_e('Security monitoring', 'wplm'); ?></li>
                    <li><?php esc_html_e('API configuration', 'wplm'); ?></li>
                    <li><?php esc_html_e('Advanced validation rules', 'wplm'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Get all license types
     */
    private function get_all_license_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY type_name ASC");
    }

    /**
     * Get license type
     */
    private function get_license_type($type_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wplm_license_types';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($type_id)));
    }
}

