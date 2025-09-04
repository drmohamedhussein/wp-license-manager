<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get dashboard statistics
$stats = $this->get_dashboard_stats();
?>

<div class="wplm-dashboard-wrap">
    <div class="wplm-header">
        <h1 class="wplm-page-title">
            <span class="dashicons dashicons-dashboard"></span>
            <?php _e('License Manager Dashboard', 'wp-license-manager'); ?>
        </h1>
        <p class="wplm-page-description">
            <?php _e('Overview of your license management system', 'wp-license-manager'); ?>
        </p>
    </div>

    <!-- Statistics Cards -->
    <div class="wplm-stats-grid">
        <div class="wplm-stat-card wplm-stat-card--licenses">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-admin-network"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo number_format($stats['total_licenses']); ?></h3>
                <p><?php _e('Total Licenses', 'wp-license-manager'); ?></p>
                <small>
                    <span class="wplm-stat-active"><?php echo number_format($stats['active_licenses']); ?> <?php _e('Active', 'wp-license-manager'); ?></span>
                    <span class="wplm-stat-inactive"><?php echo number_format($stats['inactive_licenses']); ?> <?php _e('Inactive', 'wp-license-manager'); ?></span>
                </small>
            </div>
        </div>

        <div class="wplm-stat-card wplm-stat-card--products">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-products"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo number_format($stats['total_products']); ?></h3>
                <p><?php _e('Licensed Products', 'wp-license-manager'); ?></p>
                <small><?php echo number_format($stats['wc_products']); ?> <?php _e('WooCommerce', 'wp-license-manager'); ?></small>
            </div>
        </div>

        <div class="wplm-stat-card wplm-stat-card--customers">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo number_format($stats['total_customers']); ?></h3>
                <p><?php _e('Customers', 'wp-license-manager'); ?></p>
                <small><?php echo number_format($stats['new_customers_month']); ?> <?php _e('This Month', 'wp-license-manager'); ?></small>
            </div>
        </div>

        <div class="wplm-stat-card wplm-stat-card--revenue">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo wc_price($stats['monthly_revenue']); ?></h3>
                <p><?php _e('Monthly Revenue', 'wp-license-manager'); ?></p>
                <small class="wplm-stat-growth <?php echo $stats['revenue_growth'] >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo ($stats['revenue_growth'] >= 0 ? '+' : '') . number_format($stats['revenue_growth'], 1); ?>%
                </small>
            </div>
        </div>
    </div>

    <div class="wplm-dashboard-grid">
        <!-- Recent Activity -->
        <div class="wplm-dashboard-section wplm-recent-activity">
            <div class="wplm-section-header">
                <h2><?php _e('Recent Activity', 'wp-license-manager'); ?></h2>
                <a href="<?php echo admin_url('admin.php?page=wplm-activity-log'); ?>" class="button">
                    <?php _e('View All', 'wp-license-manager'); ?>
                </a>
            </div>
            <div class="wplm-activity-list">
                <?php if (!empty($stats['recent_activity'])): ?>
                    <?php foreach ($stats['recent_activity'] as $activity): ?>
                        <div class="wplm-activity-item">
                            <div class="wplm-activity-icon">
                                <span class="dashicons dashicons-<?php echo esc_attr($activity['icon']); ?>"></span>
                            </div>
                            <div class="wplm-activity-content">
                                <p><?php echo esc_html($activity['message']); ?></p>
                                <time><?php echo human_time_diff(strtotime($activity['date']), current_time('timestamp')); ?> <?php _e('ago', 'wp-license-manager'); ?></time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="wplm-no-activity"><?php _e('No recent activity', 'wp-license-manager'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- License Status Chart -->
        <div class="wplm-dashboard-section wplm-license-chart">
            <div class="wplm-section-header">
                <h2><?php _e('License Status Distribution', 'wp-license-manager'); ?></h2>
            </div>
            <div class="wplm-chart-container">
                <canvas id="wplm-license-status-chart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- Expiring Licenses -->
        <div class="wplm-dashboard-section wplm-expiring-licenses">
            <div class="wplm-section-header">
                <h2><?php _e('Expiring Soon', 'wp-license-manager'); ?></h2>
                <span class="wplm-badge wplm-badge--warning"><?php echo count($stats['expiring_licenses']); ?></span>
            </div>
            <div class="wplm-expiring-list">
                <?php if (!empty($stats['expiring_licenses'])): ?>
                    <?php foreach ($stats['expiring_licenses'] as $license): ?>
                        <div class="wplm-expiring-item">
                            <div class="wplm-license-key">
                                <strong><?php echo esc_html(substr($license['key'], 0, 8) . '...'); ?></strong>
                            </div>
                            <div class="wplm-license-info">
                                <p><?php echo esc_html($license['product']); ?></p>
                                <small><?php _e('Expires', 'wp-license-manager'); ?>: <?php echo esc_html($license['expiry_date']); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="wplm-no-expiring"><?php _e('No licenses expiring soon', 'wp-license-manager'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="wplm-dashboard-section wplm-quick-actions">
            <div class="wplm-section-header">
                <h2><?php _e('Quick Actions', 'wp-license-manager'); ?></h2>
            </div>
            <div class="wplm-actions-grid">
                <a href="<?php echo admin_url('post-new.php?post_type=wplm_license'); ?>" class="wplm-action-button">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Add License', 'wp-license-manager'); ?>
                </a>
                <a href="<?php echo admin_url('post-new.php?post_type=wplm_product'); ?>" class="wplm-action-button">
                    <span class="dashicons dashicons-products"></span>
                    <?php _e('Add Product', 'wp-license-manager'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wplm-customers'); ?>" class="wplm-action-button">
                    <span class="dashicons dashicons-groups"></span>
                    <?php _e('View Customers', 'wp-license-manager'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=wplm-settings'); ?>" class="wplm-action-button">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php _e('Settings', 'wp-license-manager'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize license status chart
    if (typeof Chart !== 'undefined') {
        var ctx = document.getElementById('wplm-license-status-chart').getContext('2d');
        var statusChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    '<?php _e('Active', 'wp-license-manager'); ?>',
                    '<?php _e('Inactive', 'wp-license-manager'); ?>',
                    '<?php _e('Expired', 'wp-license-manager'); ?>'
                ],
                datasets: [{
                    data: [
                        <?php echo $stats['active_licenses']; ?>,
                        <?php echo $stats['inactive_licenses']; ?>,
                        <?php echo $stats['expired_licenses']; ?>
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#6c757d',
                        '#dc3545'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    position: 'bottom'
                },
                tooltips: {
                    callbacks: {
                        label: function(tooltipItem, data) {
                            var label = data.labels[tooltipItem.index];
                            var value = data.datasets[0].data[tooltipItem.index];
                            return label + ': ' + value;
                        }
                    }
                }
            }
        });
    }
});
</script>

