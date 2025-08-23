<?php
/**
 * My Licenses Dashboard
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/my-licenses.php.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @author  WooCommerce
 * @package WooCommerce/Templates
 * @version 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @var WP_Query $licenses_query
 */

?>

<h2><?php _e('My Licenses', 'wp-license-manager'); ?></h2>

<?php if ($licenses_query->have_posts()) : ?>
    <table class="woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-license-key"><span class="nobr"><?php _e('License Key', 'wp-license-manager'); ?></span></th>
                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-product"><span class="nobr"><?php _e('Product', 'wp-license-manager'); ?></span></th>
                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-status"><span class="nobr"><?php _e('Status', 'wp-license-manager'); ?></span></th>
                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-expiry"><span class="nobr"><?php _e('Expires', 'wp-license-manager'); ?></span></th>
                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-activations"><span class="nobr"><?php _e('Activations', 'wp-license-manager'); ?></span></th>
                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-actions"><span class="nobr"><?php _e('Actions', 'wp-license-manager'); ?></span></th>
            </tr>
        </thead>

        <tbody>
            <?php while ($licenses_query->have_posts()) : $licenses_query->the_post();
                $license_id = get_the_ID();
                $license_key = get_the_title();
                $status = get_post_meta($license_id, '_wplm_status', true);
                $product_slug = get_post_meta($license_id, '_wplm_product_id', true);
                $product_type = get_post_meta($license_id, '_wplm_product_type', true);
                $expiry_date = get_post_meta($license_id, '_wplm_expiry_date', true);
                $activation_limit = get_post_meta($license_id, '_wplm_activation_limit', true) ?: 1;
                $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];

                $product_name = 'N/A';
                if ($product_type === 'wplm') {
                    $wplm_product = get_posts([
                        'post_type' => 'wplm_product',
                        'meta_key' => '_wplm_product_id',
                        'meta_value' => $product_slug,
                        'posts_per_page' => 1,
                        'post_status' => 'publish',
                    ]);
                    if (!empty($wplm_product)) {
                        $product_name = '[WPLM] ' . $wplm_product[0]->post_title;
                    }
                } elseif ($product_type === 'woocommerce') {
                    if (function_exists('wc_get_product')) {
                        $wc_product = wc_get_product($product_slug);
                        if ($wc_product) {
                            $product_name = '[WC] ' . $wc_product->get_name();
                        }
                    }
                }

                $status_class = '';
                if ($status === 'active') $status_class = 'is-active';
                if ($status === 'expired') $status_class = 'is-expired';
                if ($status === 'inactive') $status_class = 'is-inactive';
            ?>
                <tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr($status); ?> order">
                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-license-key" data-title="<?php _e('License Key', 'wp-license-manager'); ?>">
                        <?php echo esc_html($license_key); ?>
                    </td>
                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-product" data-title="<?php _e('Product', 'wp-license-manager'); ?>">
                        <?php echo esc_html($product_name); ?>
                    </td>
                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-status" data-title="<?php _e('Status', 'wp-license-manager'); ?>">
                        <span class="status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                    </td>
                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-expiry" data-title="<?php _e('Expires', 'wp-license-manager'); ?>">
                        <?php echo $expiry_date ? esc_html(date_i18n(get_option('date_format'), strtotime($expiry_date))) : esc_html__('Lifetime', 'wp-license-manager'); ?>
                    </td>
                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-activations" data-title="<?php _e('Activations', 'wp-license-manager'); ?>">
                        <?php echo count($activated_domains); ?> / <?php echo esc_html($activation_limit); ?>
                        <?php if (!empty($activated_domains)) : ?>
                            <ul class="activated-domains-list">
                                <?php foreach ($activated_domains as $domain) : ?>
                                    <li>
                                        <?php echo esc_html($domain); ?>
                                        <button type="button" class="button-link deactivate-domain-button" data-license-key="<?php echo esc_attr($license_key); ?>" data-domain="<?php echo esc_attr($domain); ?>">
                                            <?php _e('Deactivate', 'wp-license-manager'); ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-actions" data-title="<?php _e('Actions', 'wp-license-manager'); ?>">
                        <?php // Future actions like View Details, etc. ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <?php wp_reset_postdata(); ?>
<?php else : ?>
    <div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
        <a class="woocommerce-Button button" href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>">
            <?php _e('Go shop', 'wp-license-manager') ?>
        </a>
        <?php _e('No licenses found.', 'wp-license-manager'); ?>
    </div>
<?php endif; ?>
