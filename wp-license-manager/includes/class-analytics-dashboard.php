<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics and Reporting Dashboard for WPLM
 */
class WPLM_Analytics_Dashboard {

    public function __construct() {
        // Admin page hooks
        add_action('admin_menu', [$this, 'add_admin_menu'], 100);
        
        // AJAX handlers
        add_action('wp_ajax_wplm_get_analytics_data', [$this, 'ajax_get_analytics_data']);
        add_action('wp_ajax_wplm_export_analytics', [$this, 'ajax_export_analytics']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add admin menu for analytics
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wplm-dashboard',
            __('Analytics & Reports', 'wp-license-manager'),
            __('Analytics', 'wp-license-manager'),
            'manage_wplm_licenses',
            'wplm-analytics',
            [$this, 'render_analytics_page']
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wplm-analytics') === false) {
            return;
        }

        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        wp_enqueue_script('wplm-analytics', plugin_dir_url(WPLM_PLUGIN_FILE) . 'assets/js/analytics.js', ['jquery', 'chart-js'], WPLM_VERSION, true);
        wp_localize_script('wplm-analytics', 'wplm_analytics', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplm_analytics'),
            'strings' => [
                'loading' => __('Loading...', 'wp-license-manager'),
                'error' => __('Error loading data.', 'wp-license-manager'),
                'no_data' => __('No data available.', 'wp-license-manager'),
            ]
        ]);
    }

    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        ?>
        <div class="wrap wplm-analytics">
            <h1><?php _e('Analytics & Reports', 'wp-license-manager'); ?></h1>
            
            <div class="wplm-analytics-controls">
                <div class="wplm-date-filter">
                    <label for="analytics-period"><?php _e('Period:', 'wp-license-manager'); ?></label>
                    <select id="analytics-period">
                        <option value="7days"><?php _e('Last 7 Days', 'wp-license-manager'); ?></option>
                        <option value="30days" selected><?php _e('Last 30 Days', 'wp-license-manager'); ?></option>
                        <option value="90days"><?php _e('Last 90 Days', 'wp-license-manager'); ?></option>
                        <option value="12months"><?php _e('Last 12 Months', 'wp-license-manager'); ?></option>
                        <option value="custom"><?php _e('Custom Range', 'wp-license-manager'); ?></option>
                    </select>
                    
                    <div id="custom-date-range" style="display: none;">
                        <input type="date" id="start-date" />
                        <span><?php _e('to', 'wp-license-manager'); ?></span>
                        <input type="date" id="end-date" />
                    </div>
                    
                    <button type="button" id="refresh-analytics" class="button"><?php _e('Refresh', 'wp-license-manager'); ?></button>
                    <button type="button" id="export-analytics" class="button"><?php _e('Export Report', 'wp-license-manager'); ?></button>
                </div>
            </div>

            <!-- Overview Statistics -->
            <div class="wplm-analytics-overview">
                <h2><?php _e('Overview', 'wp-license-manager'); ?></h2>
                <div class="wplm-stats-grid">
                    <div class="wplm-stat-card">
                        <div class="wplm-stat-value" id="total-licenses">-</div>
                        <div class="wplm-stat-label"><?php _e('Total Licenses', 'wp-license-manager'); ?></div>
                    </div>
                    <div class="wplm-stat-card">
                        <div class="wplm-stat-value" id="active-licenses">-</div>
                        <div class="wplm-stat-label"><?php _e('Active Licenses', 'wp-license-manager'); ?></div>
                    </div>
                    <div class="wplm-stat-card">
                        <div class="wplm-stat-value" id="expired-licenses">-</div>
                        <div class="wplm-stat-label"><?php _e('Expired Licenses', 'wp-license-manager'); ?></div>
                    </div>
                    <div class="wplm-stat-card">
                        <div class="wplm-stat-value" id="total-activations">-</div>
                        <div class="wplm-stat-label"><?php _e('Total Activations', 'wp-license-manager'); ?></div>
                    </div>
                    <div class="wplm-stat-card">
                        <div class="wplm-stat-value" id="total-customers">-</div>
                        <div class="wplm-stat-label"><?php _e('Total Customers', 'wp-license-manager'); ?></div>
                    </div>
                    <div class="wplm-stat-card">
                        <div class="wplm-stat-value" id="revenue">-</div>
                        <div class="wplm-stat-label"><?php _e('Revenue (if WC)', 'wp-license-manager'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="wplm-analytics-charts">
                <div class="wplm-chart-grid">
                    <!-- License Creation Trend -->
                    <div class="wplm-chart-container">
                        <h3><?php _e('License Creation Trend', 'wp-license-manager'); ?></h3>
                        <canvas id="license-trend-chart"></canvas>
                    </div>

                    <!-- License Status Distribution -->
                    <div class="wplm-chart-container">
                        <h3><?php _e('License Status Distribution', 'wp-license-manager'); ?></h3>
                        <canvas id="status-distribution-chart"></canvas>
                    </div>

                    <!-- Product Performance -->
                    <div class="wplm-chart-container">
                        <h3><?php _e('Product Performance', 'wp-license-manager'); ?></h3>
                        <canvas id="product-performance-chart"></canvas>
                    </div>

                    <!-- Activation Activity -->
                    <div class="wplm-chart-container">
                        <h3><?php _e('Activation Activity', 'wp-license-manager'); ?></h3>
                        <canvas id="activation-activity-chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Detailed Reports -->
            <div class="wplm-analytics-reports">
                <h2><?php _e('Detailed Reports', 'wp-license-manager'); ?></h2>
                
                <div class="wplm-report-tabs">
                    <nav class="nav-tab-wrapper">
                        <a href="#licenses-report" class="nav-tab nav-tab-active"><?php _e('Licenses Report', 'wp-license-manager'); ?></a>
                        <a href="#products-report" class="nav-tab"><?php _e('Products Report', 'wp-license-manager'); ?></a>
                        <a href="#customers-report" class="nav-tab"><?php _e('Customers Report', 'wp-license-manager'); ?></a>
                        <a href="#activity-report" class="nav-tab"><?php _e('Activity Report', 'wp-license-manager'); ?></a>
                    </nav>
                </div>

                <div class="wplm-report-content">
                    <!-- Licenses Report -->
                    <div id="licenses-report" class="wplm-report-tab active">
                        <div class="wplm-report-table">
                            <table id="licenses-analytics-table" class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('License Key', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Product', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Status', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Activations', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Created', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Expires', 'wp-license-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="7"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Products Report -->
                    <div id="products-report" class="wplm-report-tab">
                        <div class="wplm-report-table">
                            <table id="products-analytics-table" class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Product', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Total Licenses', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Expired Licenses', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Total Activations', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Revenue', 'wp-license-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Customers Report -->
                    <div id="customers-report" class="wplm-report-tab">
                        <div class="wplm-report-table">
                            <table id="customers-analytics-table" class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Customer', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Total Licenses', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Active Licenses', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Total Spent', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Last Activity', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Join Date', 'wp-license-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Activity Report -->
                    <div id="activity-report" class="wplm-report-tab">
                        <div class="wplm-report-table">
                            <table id="activity-analytics-table" class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Date', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Action', 'wp-license-manager'); ?></th>
                                        <th><?php _e('License', 'wp-license-manager'); ?></th>
                                        <th><?php _e('User/Customer', 'wp-license-manager'); ?></th>
                                        <th><?php _e('Details', 'wp-license-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="5"><?php _e('Loading...', 'wp-license-manager'); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .wplm-analytics {
            max-width: 1200px;
        }
        
        .wplm-analytics-controls {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .wplm-date-filter {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .wplm-analytics-overview {
            margin-bottom: 30px;
        }
        
        .wplm-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .wplm-stat-card {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .wplm-stat-value {
            font-size: 2.5em;
            font-weight: bold;
            color: #007cba;
            margin-bottom: 5px;
        }
        
        .wplm-stat-label {
            font-size: 0.9em;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .wplm-chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }
        
        .wplm-chart-container {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .wplm-chart-container h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #333;
        }
        
        .wplm-chart-container canvas {
            max-height: 300px;
        }
        
        .wplm-analytics-reports {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-top: 30px;
        }
        
        .wplm-analytics-reports h2 {
            padding: 20px 20px 0;
            margin: 0;
        }
        
        .wplm-report-tabs .nav-tab-wrapper {
            border-bottom: 1px solid #ddd;
            margin: 0;
        }
        
        .wplm-report-content {
            padding: 20px;
        }
        
        .wplm-report-tab {
            display: none;
        }
        
        .wplm-report-tab.active {
            display: block;
        }
        
        .wplm-report-table {
            max-width: 100%;
            overflow-x: auto;
        }
        
        .wplm-report-table table {
            min-width: 800px;
        }
        
        @media (max-width: 768px) {
            .wplm-chart-grid {
                grid-template-columns: 1fr;
            }
            
            .wplm-date-filter {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.wplm-report-tab').removeClass('active');
                $(target).addClass('active');
            });
            
            // Period change
            $('#analytics-period').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#custom-date-range').show();
                } else {
                    $('#custom-date-range').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Get analytics data
     */
    public function ajax_get_analytics_data() {
        check_ajax_referer('wplm_analytics', 'nonce');
        
        if (!current_user_can('manage_wplm_licenses')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $period = sanitize_text_field($_POST['period'] ?? '30days');
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');

        $date_range = $this->get_date_range($period, $start_date, $end_date);

        $data = [
            'overview' => $this->get_overview_stats($date_range),
            'charts' => [
                'license_trend' => $this->get_license_trend_data($date_range),
                'status_distribution' => $this->get_status_distribution_data($date_range),
                'product_performance' => $this->get_product_performance_data($date_range),
                'activation_activity' => $this->get_activation_activity_data($date_range)
            ],
            'reports' => [
                'licenses' => $this->get_licenses_report_data($date_range),
                'products' => $this->get_products_report_data($date_range),
                'customers' => $this->get_customers_report_data($date_range),
                'activity' => $this->get_activity_report_data($date_range)
            ]
        ];

        wp_send_json_success($data);
    }

    /**
     * Get date range based on period
     */
    private function get_date_range($period, $start_date = '', $end_date = '') {
        $end = current_time('Y-m-d');
        
        switch ($period) {
            case '7days':
                $start = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30days':
                $start = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90days':
                $start = date('Y-m-d', strtotime('-90 days'));
                break;
            case '12months':
                $start = date('Y-m-d', strtotime('-12 months'));
                break;
            case 'custom':
                $start = $start_date ?: date('Y-m-d', strtotime('-30 days'));
                $end = $end_date ?: current_time('Y-m-d');
                break;
            default:
                $start = date('Y-m-d', strtotime('-30 days'));
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Get overview statistics
     */
    private function get_overview_stats($date_range) {
        global $wpdb;

        $total_licenses = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'wplm_license' 
             AND post_status = 'publish'"
        );

        $active_licenses = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_status' 
             AND pm.meta_value = 'active'"
        );

        $expired_licenses = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_expiry_date' 
             AND pm.meta_value != '' 
             AND pm.meta_value < %s",
            date('Y-m-d')
        ));

        // Calculate total activations
        $activations_result = $wpdb->get_results(
            "SELECT pm.meta_value FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_activated_domains'"
        );

        $total_activations = 0;
        foreach ($activations_result as $row) {
            $domains = maybe_unserialize($row->meta_value);
            if (is_array($domains)) {
                $total_activations += count($domains);
            }
        }

        $total_customers = $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_customer_email' 
             AND pm.meta_value != ''"
        );

        // Calculate revenue (if WooCommerce)
        $revenue = 0;
        if (function_exists('wc_price')) {
            $revenue_result = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(pm.meta_value) FROM {$wpdb->posts} p 
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                 WHERE p.post_type = 'shop_order' 
                 AND p.post_status IN ('wc-completed', 'wc-processing') 
                 AND pm.meta_key = '_order_total' 
                 AND p.post_date >= %s 
                 AND p.post_date <= %s",
                $date_range['start'] . ' 00:00:00',
                $date_range['end'] . ' 23:59:59'
            ));
            $revenue = floatval($revenue_result);
        }

        return [
            'total_licenses' => intval($total_licenses),
            'active_licenses' => intval($active_licenses),
            'expired_licenses' => intval($expired_licenses),
            'total_activations' => $total_activations,
            'total_customers' => intval($total_customers),
            'revenue' => $revenue
        ];
    }

    /**
     * Get license trend data for chart
     */
    private function get_license_trend_data($date_range) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_date) as date, COUNT(*) as count 
             FROM {$wpdb->posts} 
             WHERE post_type = 'wplm_license' 
             AND post_status = 'publish' 
             AND post_date >= %s 
             AND post_date <= %s 
             GROUP BY DATE(post_date) 
             ORDER BY date ASC",
            $date_range['start'] . ' 00:00:00',
            $date_range['end'] . ' 23:59:59'
        ));

        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = date('M j', strtotime($row->date));
            $data[] = intval($row->count);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('Licenses Created', 'wp-license-manager'),
                    'data' => $data,
                    'borderColor' => 'rgb(0, 124, 186)',
                    'backgroundColor' => 'rgba(0, 124, 186, 0.1)',
                    'fill' => true
                ]
            ]
        ];
    }

    /**
     * Get status distribution data for chart
     */
    private function get_status_distribution_data($date_range) {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT pm.meta_value as status, COUNT(*) as count 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_status' 
             GROUP BY pm.meta_value"
        );

        $labels = [];
        $data = [];
        $colors = [
            'active' => '#28a745',
            'inactive' => '#ffc107',
            'expired' => '#dc3545',
            'suspended' => '#6c757d'
        ];
        $background_colors = [];

        foreach ($results as $row) {
            $labels[] = ucfirst($row->status);
            $data[] = intval($row->count);
            $background_colors[] = $colors[$row->status] ?? '#007cba';
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $background_colors
                ]
            ]
        ];
    }

    /**
     * Get product performance data
     */
    private function get_product_performance_data($date_range) {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT pm.meta_value as product, COUNT(*) as licenses 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'wplm_license' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_wplm_product_id' 
             GROUP BY pm.meta_value 
             ORDER BY licenses DESC 
             LIMIT 10"
        );

        $labels = [];
        $data = [];

        foreach ($results as $row) {
            $labels[] = $row->product;
            $data[] = intval($row->licenses);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('Licenses', 'wp-license-manager'),
                    'data' => $data,
                    'backgroundColor' => 'rgba(0, 124, 186, 0.8)'
                ]
            ]
        ];
    }

    /**
     * Get activation activity data
     */
    private function get_activation_activity_data($date_range) {
        // This would require activity logging to be implemented
        // For now, return sample data
        return [
            'labels' => [],
            'datasets' => [
                [
                    'label' => __('Activations', 'wp-license-manager'),
                    'data' => [],
                    'borderColor' => 'rgb(40, 167, 69)',
                    'backgroundColor' => 'rgba(40, 167, 69, 0.1)',
                    'fill' => true
                ]
            ]
        ];
    }

    /**
     * Get detailed reports data
     */
    private function get_licenses_report_data($date_range) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title as license_key, p.post_date,
                    MAX(CASE WHEN pm.meta_key = '_wplm_product_id' THEN pm.meta_value END) as product_id,
                    MAX(CASE WHEN pm.meta_key = '_wplm_customer_email' THEN pm.meta_value END) as customer_email,
                    MAX(CASE WHEN pm.meta_key = '_wplm_status' THEN pm.meta_value END) as status,
                    MAX(CASE WHEN pm.meta_key = '_wplm_expiry_date' THEN pm.meta_value END) as expiry_date,
                    MAX(CASE WHEN pm.meta_key = '_wplm_activated_domains' THEN pm.meta_value END) as activated_domains
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             AND p.post_date >= %s
             AND p.post_date <= %s
             GROUP BY p.ID
             ORDER BY p.post_date DESC
             LIMIT 100",
            $date_range['start'] . ' 00:00:00',
            $date_range['end'] . ' 23:59:59'
        ));

        $data = [];
        foreach ($results as $row) {
            $activated_domains = maybe_unserialize($row->activated_domains) ?: [];
            $activations_count = is_array($activated_domains) ? count($activated_domains) : 0;
            
            $data[] = [
                'license_key' => $row->license_key,
                'product' => $row->product_id ?: '-',
                'customer' => $row->customer_email ?: '-',
                'status' => ucfirst($row->status ?: 'inactive'),
                'activations' => $activations_count,
                'created' => date('M j, Y', strtotime($row->post_date)),
                'expires' => $row->expiry_date ? date('M j, Y', strtotime($row->expiry_date)) : 'Never'
            ];
        }

        return $data;
    }

    /**
     * Get products report data
     */
    private function get_products_report_data($date_range) {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT pm.meta_value as product_id,
                    COUNT(*) as total_licenses,
                    SUM(CASE WHEN pm2.meta_value = 'active' THEN 1 ELSE 0 END) as active_licenses,
                    SUM(CASE WHEN pm3.meta_value != '' AND pm3.meta_value < CURDATE() THEN 1 ELSE 0 END) as expired_licenses
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wplm_product_id'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wplm_status'
             LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wplm_expiry_date'
             WHERE p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             GROUP BY pm.meta_value
             ORDER BY total_licenses DESC"
        );

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'product' => $row->product_id,
                'total_licenses' => intval($row->total_licenses),
                'active_licenses' => intval($row->active_licenses),
                'expired_licenses' => intval($row->expired_licenses),
                'total_activations' => 0, // Would need to calculate from activated_domains
                'revenue' => function_exists('wc_price') ? wc_price(0) : '$0'
            ];
        }

        return $data;
    }

    /**
     * Get customers report data
     */
    private function get_customers_report_data($date_range) {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT pm.meta_value as customer_email,
                    COUNT(*) as total_licenses,
                    SUM(CASE WHEN pm2.meta_value = 'active' THEN 1 ELSE 0 END) as active_licenses,
                    MIN(p.post_date) as join_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wplm_customer_email'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wplm_status'
             WHERE p.post_type = 'wplm_license'
             AND p.post_status = 'publish'
             AND pm.meta_value != ''
             GROUP BY pm.meta_value
             ORDER BY total_licenses DESC
             LIMIT 100"
        );

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'customer' => $row->customer_email,
                'total_licenses' => intval($row->total_licenses),
                'active_licenses' => intval($row->active_licenses),
                'total_spent' => function_exists('wc_price') ? wc_price(0) : '$0',
                'last_activity' => '-',
                'join_date' => date('M j, Y', strtotime($row->join_date))
            ];
        }

        return $data;
    }

    /**
     * Get activity report data
     */
    private function get_activity_report_data($date_range) {
        // This would require the activity logger
        // Return empty for now
        return [];
    }
}
