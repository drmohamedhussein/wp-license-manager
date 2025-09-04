<?php
/**
 * WPLM License Template for Plugin Developers
 * 
 * QUICK SETUP INSTRUCTIONS:
 * 1. Copy this entire code block
 * 2. Paste it into your main plugin file (after the plugin header comments)
 * 3. Copy portable-license-client.php to your-plugin/includes/ folder
 * 4. Replace the values below with your actual information
 * 5. Test on a development site first!
 * 
 * WHAT TO REPLACE:
 * - YOUR_WPLM_SERVER_URL: Your WPLM server URL (e.g., https://yourdomain.com)
 * - YOUR_API_KEY: Your WPLM API key from Settings → API Key
 * - YOUR_PLUGIN_SLUG: Your plugin folder name (e.g., my-awesome-plugin)
 * - YOUR_PLUGIN_NAME: Display name for your plugin (e.g., My Awesome Plugin)
 */

// ============================================================================
// STEP 1: INCLUDE THE LICENSE CLIENT
// ============================================================================
if (!class_exists('Portable_License_Client')) {
    require_once __DIR__ . '/includes/portable-license-client.php';
}

// ============================================================================
// STEP 2: INITIALIZE THE LICENSE SYSTEM
// ============================================================================
add_action('plugins_loaded', function() {
    if (class_exists('Portable_License_Client')) {
        global $YOUR_PLUGIN_SLUG_license_client; // Replace YOUR_PLUGIN_SLUG
        $YOUR_PLUGIN_SLUG_license_client = new Portable_License_Client(
            'YOUR_WPLM_SERVER_URL',     // ← Replace with your WPLM server URL
            'YOUR_API_KEY',             // ← Replace with your WPLM API key  
            'YOUR_PLUGIN_SLUG',         // ← Replace with your plugin slug
            'YOUR_PLUGIN_NAME',         // ← Replace with your plugin name
            __FILE__                    // ← Keep this as is
        );
    }
});

// ============================================================================
// STEP 3: LICENSE CHECK FUNCTION
// ============================================================================
function YOUR_PLUGIN_SLUG_is_licensed() { // Replace YOUR_PLUGIN_SLUG
    global $YOUR_PLUGIN_SLUG_license_client; // Replace YOUR_PLUGIN_SLUG
    return isset($YOUR_PLUGIN_SLUG_license_client) && $YOUR_PLUGIN_SLUG_license_client->is_active();
}

// ============================================================================
// STEP 4: ADMIN NOTICE FOR UNLICENSED USERS
// ============================================================================
add_action('admin_notices', function() {
    if (!YOUR_PLUGIN_SLUG_is_licensed()) { // Replace YOUR_PLUGIN_SLUG
        ?>
        <div class="notice notice-error">
            <p>
                <strong>YOUR_PLUGIN_NAME</strong> requires a valid license to function properly. 
                <a href="<?php echo admin_url('options-general.php?page=YOUR_PLUGIN_SLUG-license'); ?>">
                    Activate your license here
                </a>
            </p>
        </div>
        <?php
    }
});

// ============================================================================
// STEP 5: BLOCK PLUGIN FUNCTIONALITY IF NOT LICENSED
// ============================================================================
add_action('init', function() {
    if (!YOUR_PLUGIN_SLUG_is_licensed()) { // Replace YOUR_PLUGIN_SLUG
        // Option 1: Remove main plugin hooks/functionality
        // remove_action('init', 'your_main_plugin_function');
        // remove_filter('the_content', 'your_content_filter');
        
        // Option 2: Show functionality disabled message on frontend
        add_filter('the_content', function($content) {
            if (current_user_can('manage_options')) {
                return '<div style="background:#fee;border:1px solid #f00;padding:10px;margin:10px 0;">
                    <strong>YOUR_PLUGIN_NAME:</strong> Plugin functionality disabled. 
                    <a href="' . admin_url('options-general.php?page=YOUR_PLUGIN_SLUG-license') . '">Activate license</a>
                </div>' . $content;
            }
            return $content;
        });
        
        // Option 3: Return early from your main functions
        // Just add this check at the beginning of your plugin's main functions:
        // if (!YOUR_PLUGIN_SLUG_is_licensed()) return;
    }
});

// ============================================================================
// EXAMPLE: HOW TO USE IN YOUR PLUGIN FUNCTIONS
// ============================================================================

// Example function that only works when licensed
function your_premium_feature() {
    // Always check license first
    if (!YOUR_PLUGIN_SLUG_is_licensed()) { // Replace YOUR_PLUGIN_SLUG
        return; // Exit if not licensed
    }
    
    // Your premium functionality here
    // This code only runs when license is active
}

// Example shortcode that requires license
add_shortcode('your_premium_shortcode', function($atts) {
    if (!YOUR_PLUGIN_SLUG_is_licensed()) { // Replace YOUR_PLUGIN_SLUG
        return '<p><em>This feature requires a valid license.</em></p>';
    }
    
    // Your shortcode functionality here
    return '<p>Premium content here!</p>';
});

/**
 * ============================================================================
 * REPLACEMENT CHECKLIST:
 * ============================================================================
 * 
 * Find and replace these values throughout this file:
 * 
 * 1. YOUR_WPLM_SERVER_URL    → https://yourdomain.com
 * 2. YOUR_API_KEY            → Your actual API key from WPLM Settings
 * 3. YOUR_PLUGIN_SLUG        → your-plugin-folder-name (lowercase, hyphens)
 * 4. YOUR_PLUGIN_NAME        → Your Plugin Display Name
 * 
 * FILES TO COPY:
 * 1. portable-license-client.php → your-plugin/includes/portable-license-client.php
 * 
 * TEST CHECKLIST:
 * □ License page appears at Settings → Your Plugin License
 * □ Admin notice shows when unlicensed
 * □ License activation works on first try
 * □ Plugin functionality is blocked when unlicensed
 * □ Everything works normally when licensed
 * 
 * ============================================================================
 */
