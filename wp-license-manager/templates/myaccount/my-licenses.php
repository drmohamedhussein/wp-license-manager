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

<style>
/* CSS Variables for whitelabel colors */
:root {
    --wplm-primary-color: #5de0e6;
    --wplm-secondary-color: #004aad;
    --wplm-success-color: #28a745;
    --wplm-warning-color: #ffc107;
    --wplm-danger-color: #dc3545;
    --wplm-font-white: #ffffff;
    --wplm-wc-card-bg: #ffffff;
    --wplm-wc-card-border: #e9ecef;
}

.wplm-licenses-container {
    display: grid;
    gap: 20px;
    margin: 20px 0;
}

.wplm-license-card {
    background: var(--wplm-wc-card-bg, #ffffff);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid var(--wplm-wc-card-border, #e9ecef);
    overflow: hidden;
    transition: all 0.3s ease;
}

.wplm-license-card:hover {
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.wplm-license-header {
    background: linear-gradient(135deg, var(--wplm-primary-color, #5de0e6) 0%, var(--wplm-secondary-color, #004aad) 100%);
    color: var(--wplm-font-white, #ffffff);
    padding: 20px;
    position: relative;
}

.wplm-license-key {
    font-family: 'Courier New', monospace;
    font-size: 16px;
    font-weight: 600;
    margin: 0;
    word-break: break-all;
    line-height: 1.4;
}

.wplm-license-body {
    padding: 20px;
}

.wplm-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #f1f3f4;
}

.wplm-info-row:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.wplm-info-label {
    font-weight: 600;
    color: #5f6368;
    font-size: 14px;
    min-width: 100px;
}

.wplm-info-value {
    flex: 1;
    text-align: right;
    font-size: 14px;
}

.wplm-product-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 16px;
}

.wplm-status-badge {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
}

.wplm-status-active {
    background: var(--wplm-success-color, #28a745);
    color: var(--wplm-font-white, #ffffff);
}

.wplm-status-inactive {
    background: var(--wplm-danger-color, #dc3545);
    color: var(--wplm-font-white, #ffffff);
}

.wplm-status-expired {
    background: var(--wplm-warning-color, #ffc107);
    color: #212529;
}

.wplm-activated-domains {
    margin-top: 15px;
}

.wplm-domains-title {
    font-weight: 600;
    color: #5f6368;
    font-size: 14px;
    margin-bottom: 10px;
}

.wplm-domain-item {
    display: inline-block;
    background: #f8f9fa;
    padding: 6px 12px;
    margin: 4px 8px 4px 0;
    border-radius: 20px;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1px solid #e9ecef;
}

.wplm-deactivate-button {
    background: var(--wplm-danger-color, #dc3545);
    color: var(--wplm-font-white, #ffffff);
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    cursor: pointer;
    margin-left: 6px;
    transition: background 0.2s ease;
}

.wplm-deactivate-button:hover {
    background: var(--wplm-secondary-color, #004aad);
}

.wplm-add-domain-wrap {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #f1f3f4;
}

.wplm-add-domain-input {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 10px;
    transition: border-color 0.2s ease;
}

.wplm-add-domain-input:focus {
    outline: none;
    border-color: var(--wplm-primary-color, #5de0e6);
}

.wplm-add-domain-button {
    background: linear-gradient(135deg, var(--wplm-primary-color, #5de0e6) 0%, var(--wplm-secondary-color, #004aad) 100%);
    color: var(--wplm-font-white, #ffffff);
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    transition: all 0.2s ease;
}

.wplm-add-domain-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(93, 224, 230, 0.3);
}

.wplm-add-domain-button:disabled {
    background: #6c757d;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.wplm-no-licenses {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
}

.wplm-no-licenses h3 {
    color: #6c757d;
    margin-bottom: 10px;
}

.wplm-no-licenses p {
    color: #6c757d;
    margin-bottom: 20px;
}

.wplm-browse-button {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
}

.wplm-browse-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    color: white;
    text-decoration: none;
}

@media (max-width: 768px) {
    .wplm-licenses-container {
        gap: 15px;
        margin: 15px 0;
    }
    
    .wplm-license-header {
        padding: 15px;
    }
    
    .wplm-license-body {
        padding: 15px;
    }
    
    .wplm-license-key {
        font-size: 14px;
    }
    
    .wplm-info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .wplm-info-value {
        text-align: left;
    }
    
    .wplm-product-name {
        font-size: 14px;
    }
}
</style>

<?php if ($licenses_query->have_posts()) : ?>
    <div class="wplm-licenses-container">
        <?php while ($licenses_query->have_posts()) : $licenses_query->the_post();
            $license_id = get_the_ID();
            $license_key = get_the_title();
            $status = get_post_meta($license_id, '_wplm_status', true);
            $product_slug = get_post_meta($license_id, '_wplm_product_id', true);
            $product_type = get_post_meta($license_id, '_wplm_product_type', true);
            $expiry_date = get_post_meta($license_id, '_wplm_expiry_date', true);
            $activation_limit = get_post_meta($license_id, '_wplm_activation_limit', true) ?: 1;
            $activated_domains = get_post_meta($license_id, '_wplm_activated_domains', true) ?: [];
            
            // Initialize admin control variables
            $admin_override_domains = get_post_meta($license_id, '_wplm_admin_override_domains', true);
            $is_admin_controlled = !empty($admin_override_domains) && is_array($admin_override_domains) && count($admin_override_domains) > 0;

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
            if ($status === 'active') $status_class = 'wplm-status-active';
            if ($status === 'expired') $status_class = 'wplm-status-expired';
            if ($status === 'inactive') $status_class = 'wplm-status-inactive';
        ?>
            <div class="wplm-license-card">
                <div class="wplm-license-header">
                    <div class="wplm-license-key"><?php echo esc_html($license_key); ?></div>
                </div>
                
                <div class="wplm-license-body">
                    <div class="wplm-info-row">
                        <span class="wplm-info-label"><?php _e('Product', 'wp-license-manager'); ?></span>
                        <span class="wplm-info-value">
                            <div class="wplm-product-name"><?php echo esc_html($product_name); ?></div>
                        </span>
                    </div>
                    
                    <div class="wplm-info-row">
                        <span class="wplm-info-label"><?php _e('Status', 'wp-license-manager'); ?></span>
                        <span class="wplm-info-value">
                            <span class="wplm-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                        </span>
                    </div>
                    
                    <div class="wplm-info-row">
                        <span class="wplm-info-label"><?php _e('Expires', 'wp-license-manager'); ?></span>
                        <span class="wplm-info-value">
                            <?php echo $expiry_date ? esc_html(date_i18n(get_option('date_format'), strtotime($expiry_date))) : esc_html__('Lifetime', 'wp-license-manager'); ?>
                        </span>
                    </div>
                    
                    <div class="wplm-info-row">
                        <span class="wplm-info-label"><?php _e('Activations', 'wp-license-manager'); ?></span>
                        <span class="wplm-info-value">
                            <strong><?php echo count($activated_domains); ?> / <?php echo esc_html($activation_limit); ?></strong>
                        </span>
                    </div>
                    
                    <?php if (!empty($activated_domains)) : ?>
                        <div class="wplm-activated-domains">
                            <div class="wplm-domains-title"><?php _e('Activated Domains', 'wp-license-manager'); ?></div>
                            <?php foreach ($activated_domains as $domain) : ?>
                                <span class="wplm-domain-item">
                                    <?php echo esc_html($domain); ?>
                                    <?php if (!$is_admin_controlled) : ?>
                                        <button type="button" class="wplm-deactivate-button deactivate-domain-button" data-license-key="<?php echo esc_attr($license_key); ?>" data-domain="<?php echo esc_attr($domain); ?>">
                                            <?php _e('Remove', 'wp-license-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Show Add Domain UI if domain validation is enabled and under limit (or unlimited)
                    $require_domain_validation = get_post_meta($license_id, '_wplm_require_domain_validation', true);
                    $admin_override_domains = get_post_meta($license_id, '_wplm_admin_override_domains', true);
                    $is_admin_controlled = !empty($admin_override_domains) && is_array($admin_override_domains) && count($admin_override_domains) > 0;
                    $can_add_more = ($activation_limit === -1) || (count($activated_domains) < (int) $activation_limit);
                    
                    if ($require_domain_validation === '1' && $can_add_more && !$is_admin_controlled) :
                    ?>
                        <div class="wplm-add-domain-wrap">
                            <input type="text" class="wplm-add-domain-input" placeholder="<?php esc_attr_e('Enter domain (example.com)', 'wp-license-manager'); ?>" />
                            <button type="button" class="wplm-add-domain-button" data-license-key="<?php echo esc_attr($license_key); ?>">
                                <?php _e('Add Domain', 'wp-license-manager'); ?>
                            </button>
                        </div>
                        <script>
                        (function(){
                            const container = document.currentScript.parentElement;
                            const input = container.querySelector('.wplm-add-domain-input');
                            const button = container.querySelector('.wplm-add-domain-button');
                            const domainsContainer = container.parentElement.querySelector('.wplm-activated-domains');
                            button.addEventListener('click', function(){
                                const domain = (input.value || '').trim();
                                if(!domain){ return; }
                                button.disabled = true;
                                button.textContent = '<?php echo esc_js(__('Adding...', 'wp-license-manager')); ?>';
                                jQuery.post(wplm_admin_vars.ajaxurl, {
                                    action: 'wplm_add_customer_domain',
                                    nonce: wplm_admin_vars.wplm_customer_add_domain_nonce,
                                    license_key: button.getAttribute('data-license-key'),
                                    domain: domain
                                }).done(function(resp){
                                    if(resp && resp.success){
                                        // Create domains container if it doesn't exist
                                        let domainsDiv = domainsContainer;
                                        if(!domainsDiv){
                                            domainsDiv = document.createElement('div');
                                            domainsDiv.className = 'wplm-activated-domains';
                                            domainsDiv.innerHTML = '<div class="wplm-domains-title"><?php echo esc_js(__('Activated Domains', 'wp-license-manager')); ?></div>';
                                            container.parentElement.insertBefore(domainsDiv, container);
                                        }
                                        // Add new domain
                                        const domainSpan = document.createElement('span');
                                        domainSpan.className = 'wplm-domain-item';
                                        domainSpan.innerHTML = domain + ' <button type="button" class="wplm-deactivate-button deactivate-domain-button" data-license-key="<?php echo esc_js($license_key); ?>" data-domain="' + domain + '"><?php echo esc_js(__('Remove', 'wp-license-manager')); ?></button>';
                                        domainsDiv.appendChild(domainSpan);
                                        input.value = '';
                                    } else {
                                        alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Failed to add domain', 'wp-license-manager')); ?>');
                                    }
                                }).fail(function(){
                                    alert('<?php echo esc_js(__('An error occurred', 'wp-license-manager')); ?>');
                                }).always(function(){
                                    button.disabled = false;
                                    button.textContent = '<?php echo esc_js(__('Add Domain', 'wp-license-manager')); ?>';
                                });
                            });
                        })();
                        </script>
                    <?php endif; ?>
                    
                    <?php if ($is_admin_controlled) : ?>
                        <div class="wplm-admin-notice" style="margin-top: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; color: #856404;">
                            <strong><?php _e('Admin Controlled:', 'wp-license-manager'); ?></strong>
                            <?php _e('Domain management for this license is controlled by the administrator.', 'wp-license-manager'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    <?php wp_reset_postdata(); ?>
<?php else : ?>
    <div class="wplm-no-licenses">
        <div style="font-size: 48px; color: #6c757d; margin-bottom: 20px;">🔑</div>
        <h3><?php _e('No Licenses Found', 'wp-license-manager'); ?></h3>
        <p><?php _e('You haven\'t purchased any licensed products yet.', 'wp-license-manager'); ?></p>
        <a href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>" class="wplm-browse-button">
            <?php _e('Browse Products', 'wp-license-manager'); ?>
        </a>
    </div>
<?php endif; ?>
