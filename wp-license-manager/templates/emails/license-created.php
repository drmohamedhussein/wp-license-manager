<?php
/**
 * License Created Email Template
 * 
 * @var array $data Email data containing license information
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($data['subject'] ?? 'License Key'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007cba; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .license-key { background: #fff; border: 2px solid #007cba; padding: 15px; margin: 20px 0; font-family: monospace; font-size: 18px; text-align: center; }
        .details { background: #fff; padding: 15px; margin: 10px 0; }
        .footer { background: #333; color: white; padding: 20px; text-align: center; font-size: 12px; }
        .button { display: inline-block; background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php _e('Your License Key', 'wp-license-manager'); ?></h1>
        </div>
        
        <div class="content">
            <h2><?php echo esc_html($data['product_name']); ?></h2>
            
            <p><?php _e('Thank you for your purchase! Your license key has been generated and is ready to use.', 'wp-license-manager'); ?></p>
            
            <div class="license-key">
                <strong><?php echo esc_html($data['license_key']); ?></strong>
            </div>
            
            <div class="details">
                <h3><?php _e('License Details', 'wp-license-manager'); ?></h3>
                <ul>
                    <li><strong><?php _e('Product:', 'wp-license-manager'); ?></strong> <?php echo esc_html($data['product_name']); ?></li>
                    <li><strong><?php _e('License Key:', 'wp-license-manager'); ?></strong> <?php echo esc_html($data['license_key']); ?></li>
                    <li><strong><?php _e('Activation Limit:', 'wp-license-manager'); ?></strong> <?php echo esc_html($data['activation_limit']); ?></li>
                    <?php if (!empty($data['expiry_date'])): ?>
                    <li><strong><?php _e('Expires:', 'wp-license-manager'); ?></strong> <?php echo esc_html(date('F j, Y', strtotime($data['expiry_date']))); ?></li>
                    <?php else: ?>
                    <li><strong><?php _e('Expires:', 'wp-license-manager'); ?></strong> <?php _e('Never (Lifetime)', 'wp-license-manager'); ?></li>
                    <?php endif; ?>
                    <?php if (!empty($data['order_id'])): ?>
                    <li><strong><?php _e('Order ID:', 'wp-license-manager'); ?></strong> #<?php echo esc_html($data['order_id']); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <?php if (!empty($data['download_url'])): ?>
            <div style="text-align: center;">
                <a href="<?php echo esc_url($data['download_url']); ?>" class="button"><?php _e('Download Product', 'wp-license-manager'); ?></a>
            </div>
            <?php endif; ?>
            
            <p><?php _e('Keep this email safe as it contains your license key. You will need this key to activate the product.', 'wp-license-manager'); ?></p>
        </div>
        
        <div class="footer">
            <p><?php printf(__('This email was sent from %s', 'wp-license-manager'), get_bloginfo('name')); ?></p>
        </div>
    </div>
</body>
</html>
