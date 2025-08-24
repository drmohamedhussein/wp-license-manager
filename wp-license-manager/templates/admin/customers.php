<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get customers data
$customers_data = $this->get_customers_data();
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$total_customers = $customers_data['total'];
$customers = $customers_data['customers'];
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
?>

<div class="wplm-customers-wrap">
    <div class="wplm-admin-notices"></div> <!-- Unified notification area -->
    <div class="wplm-header">
        <h1 class="wplm-page-title">
            <span class="dashicons dashicons-groups"></span>
            <?php _e('Customer Management', 'wp-license-manager'); ?>
        </h1>
        <p class="wplm-page-description">
            <?php _e('Manage your customers and their licenses', 'wp-license-manager'); ?>
        </p>
    </div>

    <!-- Customer Statistics -->
    <div class="wplm-customer-stats">
        <div class="wplm-stat-card">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo number_format($customers_data['total']); ?></h3>
                <p><?php _e('Total Customers', 'wp-license-manager'); ?></p>
            </div>
        </div>

        <div class="wplm-stat-card">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-calendar-alt"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo number_format($customers_data['new_this_month']); ?></h3>
                <p><?php _e('New This Month', 'wp-license-manager'); ?></p>
            </div>
        </div>

        <div class="wplm-stat-card">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-yes"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo number_format($customers_data['active_customers']); ?></h3>
                <p><?php _e('Active Customers', 'wp-license-manager'); ?></p>
            </div>
        </div>

        <div class="wplm-stat-card">
            <div class="wplm-stat-card__icon">
                <span class="dashicons dashicons-star-filled"></span>
            </div>
            <div class="wplm-stat-card__content">
                <h3><?php echo wc_price($customers_data['avg_customer_value']); ?></h3>
                <p><?php _e('Avg. Customer Value', 'wp-license-manager'); ?></p>
            </div>
        </div>
    </div>

    <!-- Search and Filters -->
    <div class="wplm-customers-toolbar">
        <div class="wplm-search-box">
            <form method="get" action="">
                <input type="hidden" name="page" value="wplm-customers">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search customers...', 'wp-license-manager'); ?>">
                <button type="submit" class="button">
                    <span class="dashicons dashicons-search"></span>
                    <?php _e('Search', 'wp-license-manager'); ?>
                </button>
                <?php if ($search): ?>
                    <a href="<?php echo admin_url('admin.php?page=wplm-customers'); ?>" class="button">
                        <?php _e('Clear', 'wp-license-manager'); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="wplm-export-actions">
            <button type="button" class="button button-secondary" id="wplm-export-customers">
                <span class="dashicons dashicons-download"></span>
                <?php _e('Export Customers', 'wp-license-manager'); ?>
            </button>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="wplm-customers-table-wrap">
        <table class="wplm-customers-table">
            <thead>
                <tr>
                    <th class="wplm-col-customer"><?php _e('Customer', 'wp-license-manager'); ?></th>
                    <th class="wplm-col-licenses"><?php _e('Licenses', 'wp-license-manager'); ?></th>
                    <th class="wplm-col-products"><?php _e('Products', 'wp-license-manager'); ?></th>
                    <th class="wplm-col-status"><?php _e('Status', 'wp-license-manager'); ?></th>
                    <th class="wplm-col-value"><?php _e('Total Value', 'wp-license-manager'); ?></th>
                    <th class="wplm-col-registered"><?php _e('Registered', 'wp-license-manager'); ?></th>
                    <th class="wplm-col-actions"><?php _e('Actions', 'wp-license-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($customers)): ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr class="wplm-customer-row" data-customer-email="<?php echo esc_attr($customer['email']); ?>">
                            <td class="wplm-col-customer">
                                <div class="wplm-customer-info">
                                    <div class="wplm-customer-avatar">
                                        <?php echo get_avatar($customer['email'], 40); ?>
                                    </div>
                                    <div class="wplm-customer-details">
                                        <strong class="wplm-customer-name">
                                            <?php echo esc_html($customer['name'] ?: $customer['email']); ?>
                                        </strong>
                                        <div class="wplm-customer-email">
                                            <a href="mailto:<?php echo esc_attr($customer['email']); ?>">
                                                <?php echo esc_html($customer['email']); ?>
                                            </a>
                                        </div>
                                        <?php if ($customer['wc_customer_id']): ?>
                                            <div class="wplm-customer-wc">
                                                <a href="<?php echo admin_url('user-edit.php?user_id=' . $customer['wc_customer_id']); ?>" target="_blank">
                                                    <span class="dashicons dashicons-admin-users"></span>
                                                    <?php _e('WC Customer', 'wp-license-manager'); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="wplm-col-licenses">
                                <div class="wplm-license-summary">
                                    <div class="wplm-license-count">
                                        <strong><?php echo $customer['total_licenses']; ?></strong>
                                        <?php _e('licenses', 'wp-license-manager'); ?>
                                    </div>
                                    <div class="wplm-license-breakdown">
                                        <span class="wplm-active"><?php echo $customer['active_licenses']; ?> <?php _e('active', 'wp-license-manager'); ?></span>
                                        <?php if ($customer['expired_licenses'] > 0): ?>
                                            <span class="wplm-expired"><?php echo $customer['expired_licenses']; ?> <?php _e('expired', 'wp-license-manager'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="wplm-col-products">
                                <div class="wplm-products-list">
                                    <?php foreach (array_slice($customer['products'], 0, 3) as $product): ?>
                                        <div class="wplm-product-item">
                                            <span class="wplm-product-name"><?php echo esc_html($product); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($customer['products']) > 3): ?>
                                        <div class="wplm-product-more">
                                            +<?php echo count($customer['products']) - 3; ?> <?php _e('more', 'wp-license-manager'); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="wplm-col-status">
                                <?php if ($customer['active_licenses'] > 0): ?>
                                    <span class="wplm-status wplm-status--active">
                                        <span class="dashicons dashicons-yes"></span>
                                        <?php _e('Active', 'wp-license-manager'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="wplm-status wplm-status--inactive">
                                        <span class="dashicons dashicons-no"></span>
                                        <?php _e('Inactive', 'wp-license-manager'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="wplm-col-value">
                                <strong><?php echo wc_price($customer['total_value']); ?></strong>
                            </td>
                            <td class="wplm-col-registered">
                                <div class="wplm-date">
                                    <?php echo date_i18n(get_option('date_format'), strtotime($customer['first_license_date'])); ?>
                                </div>
                                <div class="wplm-time-ago">
                                    <?php echo human_time_diff(strtotime($customer['first_license_date']), current_time('timestamp')); ?> <?php _e('ago', 'wp-license-manager'); ?>
                                </div>
                            </td>
                            <td class="wplm-col-actions">
                                <div class="wplm-actions">
                                    <button type="button" class="button button-small wplm-view-customer" data-customer-email="<?php echo esc_attr($customer['email']); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                        <?php _e('View', 'wp-license-manager'); ?>
                                    </button>
                                    <button type="button" class="button button-small wplm-edit-customer" data-customer-email="<?php echo esc_attr($customer['email']); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                        <?php _e('Edit', 'wp-license-manager'); ?>
                                    </button>
                                    <div class="wplm-actions-dropdown">
                                        <button type="button" class="button button-small wplm-dropdown-toggle" aria-expanded="false" aria-haspopup="true" id="customer-actions-toggle-<?php echo esc_attr($customer['customer_id']); ?>">
                                            <span class="dashicons dashicons-ellipsis"></span>
                                        </button>
                                        <div class="wplm-dropdown-menu" role="menu" aria-labelledby="customer-actions-toggle-<?php echo esc_attr($customer['customer_id']); ?>">
                                            <a href="mailto:<?php echo esc_attr($customer['email']); ?>" class="wplm-dropdown-item" role="menuitem">
                                                <span class="dashicons dashicons-email"></span>
                                                <?php _e('Send Email', 'wp-license-manager'); ?>
                                            </a>
                                            <button type="button" class="wplm-dropdown-item wplm-export-customer-data" data-customer-email="<?php echo esc_attr($customer['email']); ?>" role="menuitem">
                                                <span class="dashicons dashicons-download"></span>
                                                <?php _e('Export Data', 'wp-license-manager'); ?>
                                            </button>
                                            <?php if ($customer['wc_customer_id']): ?>
                                                <a href="<?php echo admin_url('user-edit.php?user_id=' . $customer['wc_customer_id']); ?>" target="_blank" class="wplm-dropdown-item" role="menuitem">
                                                    <span class="dashicons dashicons-admin-users"></span>
                                                    <?php _e('WC Profile', 'wp-license-manager'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="wplm-no-customers">
                            <div class="wplm-empty-state">
                                <span class="dashicons dashicons-groups"></span>
                                <h3><?php _e('No customers found', 'wp-license-manager'); ?></h3>
                                <p><?php _e('No customers match your search criteria.', 'wp-license-manager'); ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_customers > $per_page): ?>
        <div class="wplm-pagination">
            <?php
            $total_pages = ceil($total_customers / $per_page);
            $pagination_args = [
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $current_page,
                'total' => $total_pages,
                'prev_text' => '&laquo; ' . __('Previous', 'wp-license-manager'),
                'next_text' => __('Next', 'wp-license-manager') . ' &raquo;',
            ];
            
            if ($search) {
                $pagination_args['add_args'] = ['s' => $search];
            }
            
            echo paginate_links($pagination_args);
            ?>
        </div>
    <?php endif; ?>
</div>

<!-- Customer Detail Modal -->
<div id="wplm-customer-modal" class="wplm-modal" style="display: none;">
    <div class="wplm-modal-content">
        <div class="wplm-modal-header">
            <h2><?php _e('Customer Details', 'wp-license-manager'); ?></h2>
            <button type="button" class="wplm-modal-close">&times;</button>
        </div>
        <div class="wplm-modal-body">
            <!-- Customer details will be loaded here via AJAX -->
        </div>
    </div>
</div>

