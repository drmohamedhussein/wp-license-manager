<?php
/**
 * License Expiring Email Template
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
    <title><?php _e('License Expiring Soon', 'wp-license-manager'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #ff9800; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .license-key { background: #fff; border: 2px solid #ff9800; padding: 15px; margin: 20px 0; font-family: monospace; font-size: 18px; text-align: center; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .details { background: #fff; padding: 15px; margin: 10px 0; }
        .footer { background: #333; color: white; padding: 20px; text-align: center; font-size: 12px; }
        .button { display: inline-block; background: #ff9800; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php _e('License Expiring Soon', 'wp-license-manager'); ?></h1>
        </div>
        
        <div class="content">
            <div class="warning">
                <h2><?php printf(__('Your license for %s will expire in %d days', 'wp-license-manager'), esc_html($data['product_name']), esc_html($data['days_until_expiry'])); ?></h2>
            </div>
            
            <div class="license-key">
                <strong><?php echo esc_html($data['license_key']); ?></strong>
            </div>
            
            <div class="details">
                <h3><?php _e('License Details', 'wp-license-manager'); ?></h3>
                <ul>
                    <li><strong><?php _e('Product:', 'wp-license-manager'); ?></strong> <?php echo esc_html($data['product_name']); ?></li>
                    <li><strong><?php _e('License Key:', 'wp-license-manager'); ?></strong> <?php echo esc_html($data['license_key']); ?></li>
                    <li><strong><?php _e('Expires:', 'wp-license-manager'); ?></strong> <?php echo esc_html(date('F j, Y', strtotime($data['expiry_date']))); ?></li>
                    <li><strong><?php _e('Days Remaining:', 'wp-license-manager'); ?></strong> <?php echo esc_html($data['days_until_expiry']); ?></li>
                </ul>
            </div>
            
            <p><?php _e('To continue receiving updates and support, please renew your license before it expires.', 'wp-license-manager'); ?></p>
            
            <?php if (!empty($data['renewal_url'])): ?>
            <div style="text-align: center;">
                <a href="<?php echo esc_url($data['renewal_url']); ?>" class="button"><?php _e('Renew License', 'wp-license-manager'); ?></a>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p><?php printf(__('This email was sent from %s', 'wp-license-manager'), get_bloginfo('name')); ?></p>
        </div>
    </div>
</body>
</html>