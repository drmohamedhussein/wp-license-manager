=== WP License Manager ===
Contributors: Your Name
Tags: license, licensing, software, products, woocommerce, updates, activation, deactivation, api, manager
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A powerful and flexible license management system for WordPress, seamlessly integrating with WooCommerce for automated key generation, product updates, and customer dashboards.

== Description ==

WP License Manager is a robust and comprehensive solution for managing software licenses directly within your WordPress site. Designed for developers and businesses selling digital products, it provides an intuitive platform for generating, validating, and tracking license keys with advanced features like WooCommerce integration, automated product updates, and customer-facing license management. Say goodbye to manual license handling and streamline your digital product sales with ease.

= Key Features =

*   **Automated License Generation**: Automatically generates unique license keys upon WooCommerce order completion for virtual, downloadable products.
*   **WooCommerce Integration**: Deep integration with WooCommerce, allowing you to link licenses to specific WooCommerce products (including variable products) and manage them directly.
*   **Automated Product Updates**: Provides a robust server-side API endpoint for your client plugins or themes to check for and receive the latest version updates and download URLs.
*   **Customer License Dashboard**: A dedicated "My Licenses" section in the WooCommerce "My Account" area where customers can view their purchased licenses and manage activated domains (deactivate).
*   **License Expiry Notifications**: Automated email notifications sent to customers when their licenses are nearing expiry (e.g., 7, 3, 1 day before) and upon expiration, prompting renewals.
*   **License Activity Log**: Comprehensive logging of all API interactions (validation, activation, deactivation, update checks) for each license, providing a detailed audit trail.
*   **WooCommerce Subscriptions Support**: Automatically updates license statuses (active, inactive) based on the associated WooCommerce Subscription status (active, on-hold, cancelled, expired).
*   **Centralized Settings Page**: A new, intuitive settings interface under the WPLM dashboard with dedicated tabs for General options, Export/Import functionalities, and API Key management.
*   **Export/Import Functionality**: Easily export and import license keys, products, or both, as CSV spreadsheets for backup, migration, or bulk management.
*   **Custom Post Types**: Manages licenses and products using custom post types for native WordPress integration and flexibility.
*   **User-friendly UI/UX**: Enhanced administrative interface with a clean design, white background, and support for Right-to-Left (RTL) languages for a better user experience.
*   **Secure API**: Robust API for license validation, activation, and deactivation, protected by a unique API key.
*   **Flexible Activation Limits**: Set limits on how many domains a single license key can be activated on.

= How It Works =

1.  **Installation & Activation**: Install and activate the WP License Manager plugin on your WordPress site.
2.  **Product Setup**: Create or link existing WooCommerce products to WP License Manager products. Define product versions and download URLs for automated updates.
3.  **Automated Licensing**: When a customer purchases a licensed product via WooCommerce, a unique license key is automatically generated and delivered.
4.  **Client Integration**: Your client plugins/themes use the provided API to validate licenses, activate/deactivate domains, and check for updates.
5.  **Customer Management**: Customers can manage their own activated domains via the WooCommerce "My Account" dashboard.
6.  **Admin Control**: Manage all licenses, products, API keys, and plugin settings from the dedicated WP License Manager dashboard.

== Installation ==

= Automatic Installation =

1.  Log in to your WordPress admin panel.
2.  Navigate to **Plugins** > **Add New**.
3.  Search for "WP License Manager" (or upload the plugin zip file).
4.  Click "Install Now" and then "Activate".

= Manual Installation =

1.  Download the plugin zip file from the WordPress.org plugin repository or your source.
2.  Upload the extracted plugin folder to the `/wp-content/plugins/` directory via FTP/SFTP or your hosting panel's file manager.
3.  Activate the plugin through the 'Plugins' menu in WordPress.

= Setup Process =

1.  **Activate Plugin**: Ensure WP License Manager is active.
2.  **Generate API Key**: Go to **Licenses** > **Settings** > **API Key** tab. Generate a new API key. This key will be used by your client applications to communicate with the license server.
3.  **Configure Products**: For each WooCommerce virtual, downloadable product you wish to license, edit the product in WooCommerce. In the "License Manager Options" meta box, check "Is Licensed Product?" and either link to an existing WPLM Product or allow a new one to be created. Ensure the "Current Version" and "Download URL" are set for automated updates.
4.  **Client Integration**: Implement the client-side logic in your plugins/themes to interact with the WP License Manager API for validation, activation, deactivation, and updates.

== Frequently Asked Questions ==

= How do I generate a license key? =

License keys are automatically generated when a customer completes a WooCommerce order for a product configured as a licensed product. You can also manually generate keys from the "Add New License" page or via WP-CLI.

= Can I limit how many times a license can be activated? =

Yes, you can set an "Activation Limit" for each license in the License Details meta box on the license edit screen.

= How do clients check for updates? =

Clients will send a request to the plugin's API endpoint (`/wp-json/wplm/v1/update_check`) with their product slug and current version. The API will respond with the latest version and download URL if an update is available.

= What happens when a license expires? =

When a license expires, its status is automatically updated to "Expired" by a daily cron job. Customers receive email notifications before and after expiry. You can then implement logic in your client applications to restrict features for expired licenses.

= How does it handle WooCommerce Subscriptions? =

If WooCommerce Subscriptions is active, WP License Manager automatically adjusts the linked license status (Active/Inactive) based on the subscription's status (Active, On-Hold, Cancelled, Expired).

= Is my license data secure? =

Yes, the plugin uses API key authentication for all API interactions. License data is stored securely in your WordPress database as custom post types and meta data.

= Can I import and export license data? =

Yes, the **Licenses** > **Settings** page includes an **Export/Import** tab that allows you to export Licenses Only, Products Only, or Licenses and Products to a CSV file, and import them back.

== Screenshots ==

1.  **Licenses List** - Overview of all generated licenses with key details and status.
2.  **License Details** - Edit screen for a single license, showing status, product, customer, expiry, and activations.
3.  **Products List** - Management of custom products within WPLM, linked to WooCommerce products.
4.  **Product Details** - Edit screen for a WPLM product, including Product ID, Current Version, and Download URL.
5.  **WooCommerce Product Options** - Meta box on WooCommerce product edit screen to enable licensing, link WPLM products, and set versions.
6.  **Settings Page - General Tab** - Options like deleting data on uninstall.
7.  **Settings Page - Export/Import Tab** - Interface for exporting and importing license and product data via CSV.
8.  **Settings Page - API Key Tab** - Manage and regenerate your plugin's API key.
9.  **My Licenses Dashboard** - Customer-facing dashboard in WooCommerce My Account area to view and deactivate domains.

== Changelog ==

= 2.0.0 - 2024-07-XX =
*   **Major Refactoring and Enhancements**:
    *   Renamed from "License Worker" to "WP License Manager" for clarity and better branding.
    *   Removed all dependencies on Google Sheets and Google Apps Script, making the plugin fully self-contained within WordPress.
*   **WooCommerce Integration**:
    *   Automated license key generation upon WooCommerce order completion.
    *   Support for WooCommerce variable products, linking licenses to parent products.
    *   License keys displayed on admin order pages, customer order details, and order emails.
*   **Automated Product Updates**:
    *   New API endpoint for client plugins/themes to check for and download updates.
    *   Management of "Current Version" and "Download URL" for WPLM products.
*   **Customer License Dashboard**:
    *   "My Licenses" section added to WooCommerce "My Account" page.
    *   Customers can view their license keys, associated products, status, expiry, and activated domains.
    *   Ability for customers to deactivate domains directly from their dashboard.
*   **License Expiry Management**:
    *   WordPress cron job implemented for hourly checks of expiring licenses.
    *   Automated email notifications sent to customers before expiry (7, 3, 1 day) and upon expiration.
*   **License Activity Logging**:
    *   Comprehensive logging of all API interactions (validate, activate, deactivate, update check) to provide an audit trail for each license.
*   **WooCommerce Subscriptions Integration**:
    *   Automatic synchronization of WPLM license status with WooCommerce Subscription status (Active, On-Hold, Cancelled, Expired).
*   **Enhanced Admin UI/UX**:
    *   New centralized "Settings" menu under the WPLM dashboard with a tabbed interface (General, Export/Import, API Key, License).
    *   "Delete all plugin data upon uninstallation" option in General Settings.
    *   API Key management moved from WordPress general settings to the WPLM settings page.
    *   Improved design with a white background, cleaner layout, and RTL language support.
    *   All inline JavaScript moved to external `script.js` for better maintainability.
*   **Data Management**:
    *   Robust Export/Import feature for licenses and products (separate or combined) via CSV.
    *   Improved uninstall process with conditional data deletion based on user settings.
*   **Code Quality**:
    *   Extensive code review for best practices, security, and maintainability.
    *   Implementation of strict type declarations for improved code reliability.
    *   Refined error handling and logging throughout the plugin.

== Upgrade Notice ==

= 2.0.0 =
This is a major overhaul of WP License Manager, with significant new features, improved performance, and a completely revised architecture. Please review the changelog and setup instructions carefully, especially if you were using previous versions of License Worker. Backup your WordPress site before upgrading.

== Additional Information ==

= Documentation =

Complete documentation and integration guides are available on the plugin's official website ([plugin website URL here]). This includes:

*   Step-by-step setup guide
*   API reference for client integration
*   Troubleshooting guide
*   Developer hooks and filters

= Support =

For support and feature requests, please use the plugin's official support channels:

*   WordPress.org support forums ([link to support forum])
*   Plugin website contact form ([link to contact form])
*   GitHub repository (for developers: [link to GitHub repo])

= Contributing =

We welcome contributions! The plugin is developed with clean, well-documented code following WordPress coding standards. Feel free to submit pull requests or open issues on our GitHub repository.

= License =

This plugin is licensed under the GPL v2 or later license. You are free to use, modify, and distribute it according to the license terms.

= Credits =

*   Inspired by the need for a robust, self-contained WordPress license management solution.
*   Built with adherence to WordPress coding standards.
*   Leverages modern PHP, JavaScript (jQuery), and CSS practices.
*   Designed with accessibility and internationalization in mind.

= Privacy Policy =

WP License Manager prioritizes your privacy:

*   All license and product data is stored exclusively on your WordPress site database.
*   No data is sent to third-party services without explicit user configuration (e.g., if you integrate with external services).
*   User activity (API interactions) is logged locally for security and auditing purposes.
*   No personal data is collected by the plugin itself without your direct input or explicit configuration.
*   This plugin does not track personal data beyond what is necessary for its core functionality.

For questions about data handling, please review your site's general privacy policy and the plugin's documentation.