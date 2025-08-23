<?php
/**
 * WPLM Automated Licensing Client - Do not remove or modify
 * This file contains the WPLM_License_Client class which is injected into licensed products.
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

if (!class_exists('WPLM_License_Client')) {
    class WPLM_License_Client {
        private $api_url;
        private $product_slug;
        private $version;
        private $license_key = ''; // This will be stored in an option per plugin/theme
        private $item_id;
        private $product_type;
        private $plugin_file = ''; // Only for plugins to identify in transients

        public function __construct($file) {
            $this->plugin_file = $file; // Store the main plugin file path for updates

            // These constants should be defined in the wplm-updater.php file which is included before this.
            $this->api_url = get_option('wplm_api_url', '');
            $this->product_slug = WPLM_CLIENT_PRODUCT_SLUG;
            $this->version = WPLM_CLIENT_PRODUCT_VERSION;
            $this->item_id = WPLM_CLIENT_ITEM_ID;
            $this->product_type = WPLM_CLIENT_PRODUCT_TYPE;

            $this->license_key = get_option('wplm_client_license_key_' . $this->product_slug, '');

            add_action('admin_init', [$this, 'admin_init']);
            add_action('admin_menu', [$this, 'add_license_menu_page']);
            
            if ('plugin' === $this->product_type) {
                add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_plugin_update']);
                add_filter('plugins_api', [$this, 'plugins_api_call'], 10, 3);
            } elseif ('theme' === $this->product_type) {
                add_filter('pre_set_site_transient_update_themes', [$this, 'check_for_theme_update']);
            }
            
            add_action('wp_ajax_wplm_client_activate_license_' . $this->product_slug, [$this, 'ajax_activate_license']);
            add_action('wp_ajax_wplm_client_deactivate_license_' . $this->product_slug, [$this, 'ajax_deactivate_license']);
        }

        public function add_license_menu_page() {
            // The product_data is stored in a global variable set by the injected licensing code.
            $product_data = $GLOBALS['wplm_client_product_data_' . $this->product_slug];

            add_options_page(
                sprintf(__('%s License', 'wp-license-manager'), $product_data['Name'] ?? $this->product_slug),
                sprintf(__('%s License', 'wp-license-manager'), $product_data['Name'] ?? $this->product_slug),
                'manage_options',
                $this->product_slug . '-license',
                [$this, 'render_license_page']
            );
        }

        public function render_license_page() {
            $license_status = get_option('wplm_client_license_status_' . $this->product_slug, 'inactive');
            $license_key_display = ('active' === $license_status) ? str_repeat('*', strlen($this->license_key) - 4) . substr($this->license_key, -4) : $this->license_key;
            
            // The product_data is stored in a global variable set by the injected licensing code.
            $product_data = $GLOBALS['wplm_client_product_data_' . $this->product_slug];
            ?>
            <div class="wrap">
                <h1><?php printf(__('%s License', 'wp-license-manager'), $product_data['Name'] ?? $this->product_slug); ?></h1>
                <form id="wplm-client-license-form-<?php echo esc_attr($this->product_slug); ?>">
                    <?php wp_nonce_field('wplm_client_license_nonce_' . $this->product_slug, '_wplm_nonce'); ?>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="wplm_client_license_key_<?php echo esc_attr($this->product_slug); ?>"><?php _e('License Key', 'wp-license-manager'); ?></label></th>
                                <td>
                                    <input type="text" id="wplm_client_license_key_<?php echo esc_attr($this->product_slug); ?>" name="license_key" value="<?php echo esc_attr($license_key_display); ?>" class="regular-text" <?php disabled('active', $license_status); ?> />
                                    <p class="description">
                                        <?php _e('Enter your license key for this product.', 'wp-license-manager'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('License Status', 'wp-license-manager'); ?></th>
                                <td>
                                    <span class="wplm-client-license-status wplm-client-license-status--<?php echo esc_attr($license_status); ?>">
                                        <?php echo esc_html(ucfirst($license_status)); ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(('active' === $license_status ? __('Deactivate License', 'wp-license-manager') : __('Activate License', 'wp-license-manager')), 'primary', 'wplm-client-submit-license', true, ['data-action' => ('active' === $license_status ? 'deactivate' : 'activate')]); ?>
                </form>
                <div id="wplm-client-feedback-<?php echo esc_attr($this->product_slug); ?>" style="margin-top: 10px;"></div>
            </div>
            <script>
                jQuery(document).ready(function($) {
                    $('#wplm-client-license-form-<?php echo esc_attr($this->product_slug); ?>').on('submit', function(e) {
                        e.preventDefault();
                        var form = $(this);
                        var button = $('#wplm-client-submit-license');
                        var action = button.data('action');
                        var feedback = $('#wplm-client-feedback-<?php echo esc_attr($this->product_slug); ?>');
                        var licenseKeyInput = $('#wplm_client_license_key_<?php echo esc_attr($this->product_slug); ?>');
                        var licenseKey = licenseKeyInput.val();

                        feedback.empty().removeClass('notice notice-success notice-error').hide();
                        button.prop('disabled', true).text(action === 'activate' ? '<?php _e('Activating...', 'wp-license-manager'); ?>' : '<?php _e('Deactivating...', 'wp-license-manager'); ?>');

                        $.ajax({
                            url: wplm_client_admin_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'wplm_client_' + action + '_license_' + '<?php echo esc_attr($this->product_slug); ?>',
                                _wplm_nonce: form.find('input[name="_wplm_nonce"]').val(),
                                license_key: licenseKey,
                                product_slug: '<?php echo esc_attr($this->product_slug); ?>',
                                item_id: '<?php echo esc_attr($this->item_id); ?>',
                                domain: '<?php echo esc_url(home_url()); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    feedback.addClass('notice notice-success').text(response.data.message).show();
                                    location.reload();
                                } else {
                                    feedback.addClass('notice notice-error').text(response.data.message).show();
                                }
                            },
                            error: function(xhr, status, error) {
                                feedback.addClass('notice notice-error').text('<?php _e('AJAX Error: ', 'wp-license-manager'); ?>' + error).show();
                            },
                            complete: function() {
                                button.prop('disabled', false).text(action === 'activate' ? '<?php _e('Activate License', 'wp-license-manager'); ?>' : '<?php _e('Deactivate License', 'wp-license-manager'); ?>');
                            }
                        });
                    });
                });

                // Basic CSS for status badge
                $('<style>\
                    .wplm-client-license-status {\
                        display: inline-block;\
                        padding: 4px 8px;\
                        border-radius: 4px;\
                        font-weight: bold;\
                    }\
                    .wplm-client-license-status--active {\
                        background-color: #d4edda;\
                        color: #155724;\
                    }\
                    .wplm-client-license-status--inactive, .wplm-client-license-status--expired {\
                        background-color: #f8d7da;\
                        color: #721c24;\
                    }\
                </style>').appendTo('head');
            </script>
            <?php
        }

        public function admin_init() {
            // Enqueue script for client-side license management on relevant admin pages.
            // This ensures jQuery is available and the localized script is loaded.
            if (isset($_GET['page']) && strpos($_GET['page'], $this->product_slug . '-license') !== false) {
                wp_enqueue_script('wplm-client-admin', plugins_url('assets/js/wplm-client-admin.js', $this->plugin_file), ['jquery'], $this->version, true);
                wp_localize_script('wplm-client-admin', 'wplm_client_admin_ajax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wplm_client_general_nonce'), // Generic nonce for admin scripts if needed.
                ]);
            }

            // Optionally, add an admin notice if the license is not active.
            $license_status = get_option('wplm_client_license_status_' . $this->product_slug, 'inactive');
            if ('active' !== $license_status && current_user_can('manage_options')) {
                add_action('admin_notices', function() {
                    // The product_data is stored in a global variable set by the injected licensing code.
                    $product_data = $GLOBALS['wplm_client_product_data_' . $this->product_slug];
                    $message = sprintf(
                        __('%s requires a valid license to receive updates and support. <a href="%s">Activate your license now.</a>', 'wp-license-manager'),
                        esc_html($product_data['Name'] ?? $this->product_slug),
                        esc_url(admin_url('options-general.php?page=' . $this->product_slug . '-license'))
                    );
                    printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', $message);
                });
            }
        }

        public function ajax_activate_license() {
            check_ajax_referer('wplm_client_license_nonce_' . $this->product_slug, '_wplm_nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
            }

            $license_key = sanitize_text_field($_POST['license_key']);
            $product_slug = sanitize_text_field($_POST['product_slug']);
            $item_id = absint($_POST['item_id']);
            $domain = esc_url_raw($_POST['domain']);

            if (empty($license_key) || empty($product_slug) || empty($item_id) || empty($domain)) {
                wp_send_json_error(['message' => __('Missing required parameters.', 'wp-license-manager')]);
            }

            $response = $this->call_api('activate', [
                'license_key' => $license_key,
                'product_slug' => $product_slug,
                'item_id' => $item_id,
                'domain' => $domain,
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()]);
            }

            if (isset($response['success']) && $response['success'] === true) {
                update_option('wplm_client_license_key_' . $this->product_slug, $license_key);
                update_option('wplm_client_license_status_' . $this->product_slug, 'active');
                update_option('wplm_client_license_data_' . $this->product_slug, $response['data']);
                wp_send_json_success(['message' => __('License activated successfully!', 'wp-license-manager'), 'data' => $response['data']]);
            } else {
                // Ensure error message is extracted correctly, default if not available.
                $error_message = isset($response['data']['message']) ? $response['data']['message'] : __('Failed to activate license.', 'wp-license-manager');
                wp_send_json_error(['message' => $error_message]);
            }
        }

        public function ajax_deactivate_license() {
            check_ajax_referer('wplm_client_license_nonce_' . $this->product_slug, '_wplm_nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'wp-license-manager')]);
            }

            $license_key = get_option('wplm_client_license_key_' . $this->product_slug, '');
            $product_slug = sanitize_text_field($_POST['product_slug']);
            $item_id = absint($_POST['item_id']);
            $domain = esc_url_raw($_POST['domain']);

            if (empty($license_key) || empty($product_slug) || empty($item_id) || empty($domain)) {
                wp_send_json_error(['message' => __('Missing required parameters.', 'wp-license-manager')]);
            }

            $response = $this->call_api('deactivate', [
                'license_key' => $license_key,
                'product_slug' => $product_slug,
                'item_id' => $item_id,
                'domain' => $domain,
            ]);

            // Even if remote deactivation fails, clear local data to allow new activation.
            delete_option('wplm_client_license_key_' . $this->product_slug);
            delete_option('wplm_client_license_status_' . $this->product_slug);
            delete_option('wplm_client_license_data_' . $this->product_slug);

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => __('License deactivated locally, but remote deactivation failed: ', 'wp-license-manager') . $response->get_error_message()]);
            }

            if (isset($response['success']) && $response['success'] === true) {
                wp_send_json_success(['message' => __('License deactivated successfully!', 'wp-license-manager')]);
            } else {
                $error_message = isset($response['data']['message']) ? $response['data']['message'] : __('License deactivated locally, but remote deactivation failed.', 'wp-license-manager');
                wp_send_json_error(['message' => $error_message]);
            }
        }

        public function check_for_plugin_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            $request_args = [
                'license_key' => $this->license_key,
                'product_slug' => $this->product_slug,
                'item_id' => $this->item_id,
                'version' => $this->version,
                'domain' => home_url(),
            ];

            $response = $this->call_api('check_update', $request_args);

            if (!is_wp_error($response) && isset($response['success']) && $response['success'] === true && !empty($response['data'])) {
                $update_data = (object) $response['data'];
                if (version_compare($this->version, $update_data->new_version, '<')) {
                $update = new stdClass();
                $update->slug = $this->product_slug;
                    $update->new_version = $update_data->new_version;
                    $update->url = $update_data->url ?? '';
                    $update->package = $update_data->package;
                    $transient->response[$this->plugin_file] = $update;
                }
            }
            return $transient;
        }

        public function plugins_api_call($result, $action, $args) {
            if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->product_slug) {
                return $result;
            }

            $request_args = [
                'license_key' => $this->license_key,
                'product_slug' => $this->product_slug,
                'item_id' => $this->item_id,
                'version' => $this->version,
                'domain' => home_url(),
            ];

            $response = $this->call_api('plugin_information', $request_args);

            if (!is_wp_error($response) && isset($response['success']) && $response['success'] === true && !empty($response['data'])) {
                return (object) $response['data'];
            }

            return $result;
        }

        public function check_for_theme_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            $request_args = [
                'license_key' => $this->license_key,
                'product_slug' => $this->product_slug,
                'item_id' => $this->item_id,
                'version' => $this->version,
                'domain' => home_url(),
            ];

            $response = $this->call_api('check_update', $request_args);

            if (!is_wp_error($response) && isset($response['success']) && $response['success'] === true && !empty($response['data'])) {
                $update_data = (object) $response['data'];
                if (version_compare($this->version, $update_data->new_version, '<')) {
                $update = [];
                    $update['new_version'] = $update_data->new_version;
                    $update['url'] = $update_data->url ?? '';
                    $update['package'] = $update_data->package;
                $transient->response[$this->product_slug] = $update;
                }
            }
            return $transient;
        }

        private function call_api($action, $args) {
            $api_url = trailingslashit($this->api_url) . 'wp-json/wplm/v1/' . $action;
            
            $api_params = array_merge([
                // 'edd_action' => $action, // Removed EDD specific action
                // 'url' => home_url(), // Already in args, remove if not needed or handle consistently
            ], $args);

            $response = wp_remote_post($api_url, [
                'timeout' => 15,
                'sslverify' => true, // Changed to true for production security
                'body' => json_encode($api_params), // Send as JSON for REST API
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WPLM-API-Key' => get_option('wplm_api_key', '') // Assuming WPLM API Key is stored in an option
                ],
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $decoded_body = json_decode($body, true); // Decode as associative array
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log(sprintf(esc_html__('WPLM_Automated_Licenser: JSON decode error in API response: %s (Raw: %s)', 'wplm'), json_last_error_msg(), $body));
                    return new WP_Error('wplm_api_error', __('Invalid API response format.', 'wp-license-manager'));
                }
                return $decoded_body;
            }
 
            $error_message = is_wp_error($response) ? $response->get_error_message() : __('An unknown error occurred during API call.', 'wp-license-manager');
            error_log(sprintf(esc_html__('WPLM_Automated_Licenser: API call failed for action %s: %s', 'wplm'), $action, $error_message));
            return new WP_Error('wplm_api_call_failed', $error_message);
        }

        // Public method to check if the license is active
        public function is_license_active() {
            $license_status = get_option('wplm_client_license_status_' . $this->product_slug, 'inactive');
            return 'active' === $license_status;
        }
    }
}
