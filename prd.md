PRD: WP License manager integration

## 1. Product overview

### 1.1 Document title and version

* PRD: WP License manager integration
* Version: 1.0.0

### 1.2 Product summary

This PRD covers a lightweight licensing system for WordPress plugins consisting of two cooperating components in the workspace: the license client shipped with a plugin (example: `my-awesome-plugin`) and the license manager server (packaged as `wp-license-manager`). The client provides an admin settings page, AJAX endpoints for activation/deactivation, local storage of license state, and a small API client to call the manager server. The manager plugin exposes a REST API (`/wp-json/wplm/v1/`) to validate, activate and deactivate license keys.

Assumptions

- Primary audience: internal engineering and QA teams.
- The PRD covers both the client-side integration (`my-awesome-plugin`) and the server/manager plugin (`wp-license-manager`).
- Deployment targets: standard WordPress hosts (shared/managed) and CI packaging via GitHub Actions; optional Docker-based local dev for the manager.
- Default versioning: 1.0.0.

## 2. Goals

### 2.1 Business goals

* Provide a reliable, low-friction licensing flow for paid plugin features and updates.
* Minimize support requests by giving clear activation/deactivation UX and helpful errors.
* Keep server API simple and secure for future third-party integrations.

### 2.2 User goals

* Admins must be able to enter a license key, activate and deactivate it easily from plugin settings.
* The plugin should reliably gate premium features when the license is inactive.
* Error messages and next steps must be actionable.

### 2.3 Non-goals

* Not a full SaaS billing or subscription manager (no payments in scope).
* Not building a marketplace or automated update server beyond basic update gating.

## 3. User personas

### 3.1 Key user types

* Site administrator (primary)
* Plugin developer / maintainer
* Support agent

### 3.2 Basic persona details

* **Site administrator**: Installs plugin, enters the license, expects clear status and the ability to deactivate to move licenses between sites.
* **Plugin developer**: Integrates the client, monitors API, and debugs issues.
* **Support agent**: Steps through activation/deactivation and helps customers with error cases.

### 3.3 Role-based access

* **Administrator**: Full permissions to view the license settings, activate/deactivate license keys.
* **Editor/Author**: No access to license management.

## 4. Functional requirements

* **License settings page** (Priority: High)
  * Settings page appears under Settings > {plugin} License and shows current license status and key field.
  * Page uses nonces and capability checks.

* **Activation endpoint (client)** (Priority: High)
  * POST action via AJAX `wp_ajax_wplc_activate_license_{slug}` with nonce validation.
  * Calls server API endpoint `wp-json/wplm/v1/activate` and stores license state on success in option `wplc_{slug}_license_data`.

* **Deactivation endpoint (client)** (Priority: High)
  * POST action via AJAX `wp_ajax_wplc_deactivate_license_{slug}` with nonce validation.
  * Calls server API `wp-json/wplm/v1/deactivate` and removes local option regardless of remote result.

* **Server API (manager)** (Priority: High)
  * REST endpoints: `/wp-json/wplm/v1/activate`, `/deactivate`, `/validate`.
  * Accepts `license_key`, `product_id`, `domain` and returns structured JSON with `code`, `message`, and HTTP status codes.

* **Admin notice** (client) (Priority: Medium)
  * Show a dismissible admin notice when license is not active indicating the settings link.

* **License gating** (Priority: High)
  * Public method `is_license_active()` returns boolean and is used by plugin code to gate premium features.

* **Error handling & logging** (Priority: Medium)
  * Client surfaces user-friendly messages.
  * Manager logs API errors and provides admin diagnostics in a settings screen.

* **Security & privacy** (Priority: High)
  * All AJAX calls check nonces and current_user_can('manage_options').
  * Do not store PII beyond the license key and domain. Provide a GDPR note in docs.

## 5. User experience

### 5.1 Entry points & first-time user flow

* After installation, user navigates to Settings > {plugin} License.
* If no key present, status shows Inactive and the license key field is editable with an Activate button.
* On Activate: spinner, AJAX call, success -> success notice and page reload; failure -> error notice with message.

### 5.2 Core experience

* **Activate license**: enter license key -> click Activate -> validated by server -> option updated and status shows Active.
* **Deactivate license**: click Deactivate -> local option removed -> API called to free the remote allocation.

### 5.3 Advanced features & edge cases

* Network failure during activation: show clear error and keep local state unchanged.
* Server returns unexpected payload: show generic error "Invalid response from server".
* Concurrent activations from multiple sites: manager should enforce per-license rules (see server PRD/tasks).

### 5.4 UI/UX highlights

* Use clear success/error notice styles and preserve context link to the license settings.
* Disable key field when license is active to avoid accidental changes.

## 6. Narrative

An administrator installs the plugin, reaches the license settings page and enters a purchased key. The plugin validates with the license manager server; on success, premium functionality becomes available and the site receives updates. Support agents can ask the admin to deactivate the license locally, freeing it for another site.

## 7. Success metrics

### 7.1 User-centric metrics

* Time to activate (median) < 5s.
* Activation success rate > 98% in healthy network conditions.

### 7.2 Business metrics

* Reduction in license-related support tickets by 30% after improved UX.

### 7.3 Technical metrics

* API endpoint 95th percentile latency < 300ms under normal load.
* Error rate < 1% over 24h window.

## 8. Technical considerations

### 8.1 Integration points

* Client: `my-awesome-plugin/includes/wp-license-client.php` provides admin UI, AJAX handlers and `is_license_active()`.
* Server: `wp-license-manager` exposes REST routes under `wplm/v1` that accept POST bodies.

### 8.2 Data storage & privacy

* Client stores license data in WordPress option: `wplc_{slug}_license_data` (fields: key, status).
* Server stores license entries and activations; store only necessary metadata (domain, product_id, activation timestamp).
* Provide a privacy note for GDPR: admin can request deletion of stored license metadata.

### 8.3 Scalability & performance

* Expect low per-request CPU; a single WordPress host running the manager can serve modest loads. Add caching or an RDBMS index on license key for scale.
* Rate-limit activation endpoints to avoid abuse.

### 8.4 Potential challenges

* Handling offline/edge cases where server is unreachable.
* Preventing license key sharing/abuse while preserving low friction for legitimate license transfers.

## 9. Milestones & sequencing

### 9.1 Project estimate

* Small: 2 weeks (core client + server endpoints + tests + docs)

### 9.2 Team size & composition

* 2 engineers: 1 backend, 1 WordPress plugin developer; ½ QA resource during validation.

### 9.3 Suggested phases

* **Phase 1**: Core API endpoints and client activation flow (1 week)
  * Deliverables: `/activate`, `/deactivate`, client AJAX endpoints, settings page.
* **Phase 2**: Logging, diagnostics, improved error messages and tests (3 days)
* **Phase 3**: CI packaging, deployment docs, and small UX polish (2 days)

## 10. User stories

### 10.1 Activate license (happy path)

* **ID**: GH-001
* **Description**: As a site administrator, I want to enter my license key and activate it so the plugin shows as licensed and enables premium features.
* **Acceptance criteria**:
  * Settings page shows an editable license key field when no active license is present.
  * Clicking Activate sends an AJAX request with nonce and current user capability check.
  * On server success (HTTP 200 + success payload), option `wplc_{slug}_license_data` is created with `key` and `status: active`.
  * UI shows a success notice and status changes to Active.

### 10.2 Activate license (invalid key)

* **ID**: GH-002
* **Description**: As a site administrator, entering an invalid key shows a clear error and leaves local state unchanged.
* **Acceptance criteria**:
  * Server responds with appropriate error and non-200 status; UI shows error with server message.
  * Local option is not updated.

### 10.3 Deactivate license

* **ID**: GH-003
* **Description**: As an administrator, I want to deactivate the license so I can move it to another site.
* **Acceptance criteria**:
  * Clicking Deactivate sends AJAX with nonce and permission checks.
  * Local option `wplc_{slug}_license_data` is removed immediately.
  * Server API `/deactivate` is called; UI shows success or informational message.

### 10.4 Admin notice when inactive

* **ID**: GH-004
* **Description**: The plugin should surface a dismissible admin notice on plugin pages or license settings when license is inactive.
* **Acceptance criteria**:
  * When `is_license_active()` is false, admin notice appears on `plugins.php` and on the plugin settings page.
  * Notice includes a link to the license settings page.

### 10.5 Gate premium feature

* **ID**: GH-005
* **Description**: Plugin features requiring license must check `is_license_active()` and be unavailable when false.
* **Acceptance criteria**:
  * Unit or integration test demonstrates that a premium feature is blocked when `is_license_active()` returns false.

### 10.6 Secure AJAX endpoints

* **ID**: GH-006
* **Description**: Activation/deactivation endpoints must verify nonces and `manage_options` capability.
* **Acceptance criteria**:
  * Requests without proper nonce or capability return an error and do not change stored data.

### 10.7 Server API reliability

* **ID**: GH-007
* **Description**: Server endpoints must validate input and respond with JSON containing `code` and `message` and proper HTTP codes.
* **Acceptance criteria**:
  * `activate`, `deactivate`, `validate` return 200 on success and non-2xx for client/server errors with JSON error details.

### 10.8 Privacy & deletion

* **ID**: GH-008
* **Description**: An admin must be able to request deletion of stored license metadata.
* **Acceptance criteria**:
  * Manager plugin exposes a UI or an admin AJAX action to delete license activation records; action requires `manage_options`.


## Final checklist

* All user stories are testable: Done.
* Acceptance criteria clear: Done.
* Authentication/authorization requirements explicit: Done.
* Deployment guide provided separately in `DEPLOYMENT.md`: Done.
