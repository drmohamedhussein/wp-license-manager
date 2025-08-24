```css
/* WPLM Admin RTL Styles */

/* General RTL Layout */
.rtl .wplm-dashboard-wrap,
.rtl .wplm-customers-wrap,
.rtl .wplm-subscriptions-wrap,
.rtl .wplm-activity-wrap {
    direction: rtl;
}

/* Header alignment */
.rtl .wplm-page-title {
    flex-direction: row-reverse;
}

.rtl .wplm-page-title .dashicons {
    margin-left: 10px;
    margin-right: 0;
}

/* Statistics grid RTL */
.rtl .wplm-stat-card {
    flex-direction: row-reverse;
}

.rtl .wplm-stat-card__icon {
    margin-left: 15px;
    margin-right: 0;
}

/* Toolbar RTL */
.rtl .wplm-customers-toolbar,
.rtl .wplm-subscriptions-toolbar {
    flex-direction: row-reverse;
}

.rtl .wplm-search-box form {
    flex-direction: row-reverse;
}

.rtl .wplm-search-box input[type="search"] {
    text-align: right;
}

/* Table RTL */
.rtl .wplm-customers-table,
.rtl .wplm-subscriptions-table {
    direction: rtl;
}

.rtl .wplm-customers-table th,
.rtl .wplm-customers-table td,
.rtl .wplm-subscriptions-table th,
.rtl .wplm-subscriptions-table td {
    text-align: right;
}

.rtl .wplm-customer-info {
    flex-direction: row-reverse;
}

.rtl .wplm-customer-avatar {
    margin-left: 12px;
    margin-right: 0;
}

.rtl .wplm-customer-wc a {
    flex-direction: row-reverse;
}

.rtl .wplm-customer-wc .dashicons {
    margin-left: 4px;
    margin-right: 0;
}

/* Actions RTL */
.rtl .wplm-actions {
    flex-direction: row-reverse;
}

.rtl .wplm-actions-dropdown {
    position: relative;
}

.rtl .wplm-dropdown-menu {
    left: 0;
    right: auto;
}

.rtl .wplm-dropdown-item {
    flex-direction: row-reverse;
    text-align: right;
}

.rtl .wplm-dropdown-item .dashicons {
    margin-left: 8px;
    margin-right: 0;
}

/* Status indicators RTL */
.rtl .wplm-status {
    flex-direction: row-reverse;
}

.rtl .wplm-status .dashicons {
    margin-left: 4px;
    margin-right: 0;
}

/* License breakdown RTL */
.rtl .wplm-license-breakdown .wplm-expired {
    margin-left: 0;
    margin-right: 8px;
}

/* Dashboard grid RTL */
.rtl .wplm-dashboard-section .wplm-section-header {
    flex-direction: row-reverse;
}

.rtl .wplm-activity-item {
    flex-direction: row-reverse;
}

.rtl .wplm-activity-icon {
    margin-left: 12px;
    margin-right: 0;
}

.rtl .wplm-activity-content {
    text-align: right;
}

.rtl .wplm-actions-grid {
    direction: rtl;
}

.rtl .wplm-action-button {
    flex-direction: row-reverse;
}

.rtl .wplm-action-button .dashicons {
    margin-left: 8px;
    margin-right: 0;
}

/* Modal RTL */
.rtl .wplm-modal-header {
    flex-direction: row-reverse;
}

.rtl .wplm-modal-close {
    left: 20px;
    right: auto;
}

.rtl .wplm-modal-body {
    text-align: right;
}

/* Quick Actions RTL */
.rtl .wplm-quick-actions .wplm-actions-grid {
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

/* Form elements RTL */
.rtl .wplm-form-row {
    flex-direction: row-reverse;
}

.rtl .wplm-form-label {
    text-align: right;
    margin-left: 10px;
    margin-right: 0;
}

.rtl .wplm-form-input,
.rtl .wplm-form-select,
.rtl .wplm-form-textarea {
    text-align: right;
}

/* Buttons RTL */
.rtl .wplm-button-group {
    flex-direction: row-reverse;
}

.rtl .wplm-button .dashicons {
    margin-left: 6px;
    margin-right: 0;
}

/* Notifications RTL */
.rtl .wplm-notice {
    text-align: right;
}

.rtl .wplm-notice .dashicons {
    float: right;
    margin-left: 8px;
    margin-right: 0;
}

/* Badges RTL */
.rtl .wplm-badge {
    direction: rtl;
}

/* Chart containers RTL */
.rtl .wplm-chart-container {
    direction: rtl;
}

.rtl .wplm-chart-container canvas {
    direction: ltr; /* Keep charts LTR for proper rendering */
}

/* Pagination RTL */
.rtl .wplm-pagination {
    direction: rtl;
}

.rtl .wplm-pagination .page-numbers {
    direction: ltr;
}

/* Responsive RTL adjustments */
@media (max-width: 768px) {
    .rtl .wplm-customers-toolbar {
        align-items: stretch;
    }
    
    .rtl .wplm-search-box {
        order: 2;
    }
    
    .rtl .wplm-export-actions {
        order: 1;
    }
    
    .rtl .wplm-customer-info {
        flex-direction: column;
        align-items: flex-end;
    }
    
    .rtl .wplm-customer-avatar {
        margin: 0 0 8px 0;
    }
    
    .rtl .wplm-actions {
        justify-content: flex-end;
    }
}

/* Print styles RTL */
@media print {
    .rtl .wplm-customers-table,
    .rtl .wplm-subscriptions-table {
        direction: rtl;
    }
    
    .rtl .wplm-print-header {
        text-align: right;
    }
}

/* High contrast mode RTL */
@media (prefers-contrast: high) {
    .rtl .wplm-dropdown-menu {
        border: 2px solid currentColor;
    }
    
    .rtl .wplm-status {
        border: 1px solid currentColor;
    }
}

/* Focus management RTL */
.rtl .wplm-dropdown-toggle:focus + .wplm-dropdown-menu {
    display: block;
}

.rtl .wplm-modal:focus-within .wplm-modal-content {
    outline: 2px solid #2271b1;
    outline-offset: 2px;
}

/* Animation adjustments RTL */
.rtl .wplm-stat-card:hover {
    transform: translateY(-2px) scale(1.02);
}

.rtl .wplm-action-button:hover {
    transform: translateX(-2px);
}

/* Loading states RTL */
.rtl .wplm-loading {
    direction: ltr; /* Keep loading spinner LTR */
}

.rtl .wplm-loading-text {
    direction: rtl;
    text-align: right;
}

/* Accessibility improvements RTL */
.rtl [dir="ltr"] {
    direction: ltr !important;
}

.rtl [dir="rtl"] {
    direction: rtl !important;
}

/* Specific component RTL overrides */
.rtl .wplm-expiring-item {
    flex-direction: row-reverse;
}

.rtl .wplm-license-key {
    text-align: right;
}

.rtl .wplm-license-info {
    text-align: right;
}

.rtl .wplm-empty-state {
    text-align: center; /* Keep centered for empty states */
}

/* Icon positioning RTL */
.rtl .wplm-icon-before::before {
    margin-left: 0.5em;
    margin-right: 0;
}

.rtl .wplm-icon-after::after {
    margin-left: 0;
    margin-right: 0.5em;
}
```