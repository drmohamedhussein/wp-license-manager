/**
 * Enhanced Admin JavaScript for WP License Manager
 * Provides modern UI interactions, AJAX functionality, and analytics
 */

(function($) {
    'use strict';

    window.WPLM_Enhanced_Admin = {
        
        /**
         * Initialize the enhanced admin interface
         */
        init: function() {
            this.initEventListeners();
            this.initModals();
            this.initTooltips();
            this.detectRTL();
            this.initSelect2(); // Initialize Select2 for all dropdowns
        },

        /**
         * Detect RTL language and apply styles
         */
        detectRTL: function() {
            if ($('body').hasClass('rtl') || $('html').attr('dir') === 'rtl') {
                $('.wplm-enhanced-admin-wrap').attr('dir', 'rtl');
            }
        },

        /**
         * Initialize event listeners
         */
        initEventListeners: function() {
            // Filter functionality
            $(document).on('click', '#apply-filters', this.applyFilters);
            $(document).on('click', '#clear-filters', this.clearFilters);
            $(document).on('click', '#apply-log-filters', this.applyLogFilters);
            
            // Search functionality
            $(document).on('click', '#search-customers', this.searchCustomers);
            $(document).on('keypress', '#customer-search', function(e) {
                if (e.which === 13) {
                    WPLM_Enhanced_Admin.searchCustomers();
                }
            });
            
            // Modal triggers
            $(document).on('click', '.wplm-view-customer', this.viewCustomerDetails);
            $(document).on('click', '.wplm-close', this.closeModal);
            
            // Action buttons
            $(document).on('click', '#clear-logs', this.clearActivityLogs);
            $(document).on('click', '#clear-old-logs', this.clearOldLogs);
            $(document).on('click', '#clear-all-logs', this.clearAllLogs);
            $(document).on('click', '#sync-wc-products', this.syncWooCommerceProducts);
            $(document).on('click', '#scan-orders', this.scanWooCommerceOrders);
            $(document).on('click', '#generate-licenses-button', this.generateWooCommerceLicenses);
            
            // Status toggles
            $(document).on('change', '.wplm-status-toggle', this.toggleStatus);
            
            // Bulk actions
            $(document).on('click', '#bulk-action-apply', this.applyBulkAction);
        },

        /**
         * Initialize modals
         */
        initModals: function() {
            // Close modal on outside click
            $(document).on('click', '.wplm-modal', function(e) {
                if (e.target === this) {
                    WPLM_Enhanced_Admin.closeModal();
                }
            });
            
            // Close modal on escape key
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) {
                    WPLM_Enhanced_Admin.closeModal();
                }
            });
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('[data-tooltip]').each(function() {
                $(this).attr('title', $(this).data('tooltip'));
            });
        },

        /**
         * Load dashboard statistics
         */
        loadDashboardStats: function() {
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_dashboard_stats',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const stats = response.data;
                        $('#total-licenses').text(stats.total_licenses);
                        $('#active-licenses').text(stats.active_licenses);
                        $('#expiring-licenses').text(stats.expiring_licenses);
                        $('#total-customers').text(stats.total_customers);
                        $('#total-products').text(stats.total_products);
                        $('#active-subscriptions').text(stats.active_subscriptions);
                        
                        // Add animation
                        $('.wplm-stat-card').addClass('wplm-fade-in');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Load recent activity
         */
        loadRecentActivity: function() {
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_activity_logs',
                    nonce: wplm_admin.nonce,
                    limit: 5
                },
                success: function(response) {
                    if (response.success) {
                        const activities = response.data;
                        let html = '';
                        
                        if (activities.length === 0) {
                            html = '<div class="wplm-loading">' + wplm_admin.strings.no_activity + '</div>';
                        } else {
                            activities.forEach(function(activity) {
                                html += WPLM_Enhanced_Admin.renderActivityItem(activity);
                            });
                        }
                        
                        $('#recent-activity-list').html(html);
                    }
                },
                error: function() {
                    $('#recent-activity-list').html('<div class="wplm-loading">Error loading activity</div>');
                }
            });
        },

        /**
         * Render activity item
         */
        renderActivityItem: function(activity) {
            const iconClass = this.getActivityIcon(activity.type);
            const iconColor = this.getActivityColor(activity.type);
            
            return `
                <div class="wplm-activity-item wplm-slide-up">
                    <div class="wplm-activity-icon" style="background-color: ${iconColor}">
                        <span class="dashicons ${iconClass}"></span>
                    </div>
                    <div class="wplm-activity-content">
                        <p class="wplm-activity-title">${activity.description}</p>
                        <p class="wplm-activity-meta">${activity.date} • ${activity.user}</p>
                    </div>
                </div>
            `;
        },

        /**
         * Get activity icon based on type
         */
        getActivityIcon: function(type) {
            const icons = {
                'license_created': 'dashicons-plus-alt',
                'license_activated': 'dashicons-yes-alt',
                'license_deactivated': 'dashicons-dismiss',
                'license_expired': 'dashicons-clock',
                'product_created': 'dashicons-products',
                'subscription_created': 'dashicons-update',
                'default': 'dashicons-admin-generic'
            };
            return icons[type] || icons.default;
        },

        /**
         * Get activity color based on type
         */
        getActivityColor: function(type) {
            const colors = {
                'license_created': '#28a745',
                'license_activated': '#17a2b8',
                'license_deactivated': '#ffc107',
                'license_expired': '#dc3545',
                'product_created': '#6f42c1',
                'subscription_created': '#fd7e14',
                'default': '#6c757d'
            };
            return colors[type] || colors.default;
        },

        /**
         * Initialize charts
         */
        initCharts: function() {
            if (typeof Chart !== 'undefined') {
                this.initLicenseChart();
                this.initRevenueChart();
            }
        },

        /**
         * Initialize license activations chart
         */
        initLicenseChart: function() {
            const ctx = document.getElementById('licensesChart');
            if (!ctx) return;

            // Mock data - replace with real AJAX call
            const data = {
                labels: this.getLast30Days(),
                datasets: [{
                    label: 'License Activations',
                    data: this.generateMockData(30),
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            };

            new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        },

        /**
         * Initialize revenue chart
         */
        initRevenueChart: function() {
            const ctx = document.getElementById('revenueChart');
            if (!ctx) return;

            const data = {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Revenue',
                    data: [1200, 1900, 3000, 5000, 2000, 3000],
                    backgroundColor: [
                        'rgba(102, 126, 234, 0.8)',
                        'rgba(118, 75, 162, 0.8)',
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)',
                        'rgba(23, 162, 184, 0.8)'
                    ],
                    borderWidth: 0
                }]
            };

            new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        },

        /**
         * Initialize enhanced DataTables
         */
        initLicensesTable: function() {
            this.initDataTable('#licenses-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_licenses';
                        d.nonce = wplm_admin.nonce;
                        d.status = $('#license-status-filter').val();
                        d.product = $('#license-product-filter').val();
                    }
                },
                columns: [
                    { data: 'license_key' },
                    { data: 'customer' },
                    { data: 'product' },
                    { 
                        data: 'status',
                        render: function(data) {
                            return '<span class="wplm-status-badge wplm-status-' + data + '">' + data + '</span>';
                        }
                    },
                    { data: 'expiry_date' },
                    { data: 'activations' },
                    { 
                        data: 'actions',
                        orderable: false,
                        searchable: false
                    }
                ]
            });
        },

        /**
         * Initialize products table
         */
        initProductsTable: function() {
            this.initDataTable('#products-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_products';
                        d.nonce = wplm_admin.nonce;
                    }
                },
                columns: [
                    { data: 'product_name' },
                    { data: 'product_id' },
                    { data: 'version' },
                    { data: 'total_licenses' },
                    { data: 'active_licenses' },
                    { data: 'wc_link' },
                    { 
                        data: 'actions',
                        orderable: false,
                        searchable: false
                    }
                ]
            });
        },

        /**
         * Initialize subscriptions table
         */
        initSubscriptionsTable: function() {
            this.initDataTable('#subscriptions-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_subscriptions';
                        d.nonce = wplm_admin.nonce;
                    }
                },
                columns: [
                    { data: 'subscription_id' },
                    { data: 'customer' },
                    { data: 'product' },
                    { 
                        data: 'status',
                        render: function(data) {
                            return '<span class="wplm-status-badge wplm-status-' + data + '">' + data + '</span>';
                        }
                    },
                    { data: 'next_payment' },
                    { data: 'total_paid' },
                    { 
                        data: 'actions',
                        orderable: false,
                        searchable: false
                    }
                ]
            });
        },

        /**
         * Initialize customers table
         */
        initCustomersTable: function() {
            this.initDataTable('#customers-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_customers';
                        d.nonce = wplm_admin.nonce;
                        d.search = $('#customer-search').val();
                    }
                },
                columns: [
                    { data: 'customer' },
                    { data: 'email' },
                    { data: 'total_licenses' },
                    { data: 'active_licenses' },
                    { data: 'total_spent' },
                    { data: 'last_activity' },
                    { 
                        data: 'actions',
                        orderable: false,
                        searchable: false
                    }
                ]
            });
        },

        /**
         * Initialize activity log table
         */
        initActivityLogTable: function() {
            this.initDataTable('#activity-log-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_activity_logs';
                        d.nonce = wplm_admin.nonce;
                        d.type = $('#log-type-filter').val();
                        d.date_from = $('#log-date-from').val();
                        d.date_to = $('#log-date-to').val();
                    }
                },
                columns: [
                    { data: 'date_time' },
                    { 
                        data: 'type',
                        render: function(data) {
                            return '<span class="wplm-activity-type">' + data.replace('_', ' ') + '</span>';
                        }
                    },
                    { data: 'description' },
                    { data: 'user' },
                    { data: 'ip_address' },
                    { 
                        data: 'details',
                        orderable: false,
                        searchable: false
                    }
                ],
                order: [[0, 'desc']]
            });
        },

        /**
         * Initialize DataTable with common settings
         */
        initDataTable: function(selector, options) {
            const defaultOptions = {
                processing: true,
                serverSide: true,
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                language: {
                    processing: '<div class="wplm-loading">' + wplm_admin.strings.loading + '</div>',
                    emptyTable: 'No data available',
                    zeroRecords: 'No matching records found'
                },
                drawCallback: function() {
                    // Re-initialize tooltips after table draw
                    WPLM_Enhanced_Admin.initTooltips();
                }
            };

            const finalOptions = $.extend(true, {}, defaultOptions, options);
            
            if ($.fn.DataTable.isDataTable(selector)) {
                $(selector).DataTable().destroy();
            }
            
            return $(selector).DataTable(finalOptions);
        },

        /**
         * Apply filters
         */
        applyFilters: function() {
            const table = $('.wplm-enhanced-table').DataTable();
            table.ajax.reload();
        },

        /**
         * Clear filters
         */
        clearFilters: function() {
            $('.wplm-filters-section select').val('');
            $('.wplm-filters-section input').val('');
            WPLM_Enhanced_Admin.applyFilters();
        },

        /**
         * Apply log filters
         */
        applyLogFilters: function() {
            const table = $('#activity-log-table').DataTable();
            table.ajax.reload();
        },

        /**
         * Search customers
         */
        searchCustomers: function() {
            const table = $('#customers-table').DataTable();
            table.ajax.reload();
        },

        /**
         * View customer details
         */
        viewCustomerDetails: function(e) {
            e.preventDefault();
            const customerId = $(this).data('customer-id');
            
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_get_customer_details',
                    nonce: wplm_admin.nonce,
                    customer_id: customerId
                },
                success: function(response) {
                    if (response.success) {
                        $('#customer-modal-body').html(response.data.html);
                        $('#customer-modal').show();
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('.wplm-modal').hide();
        },

        /**
         * Clear activity logs
         */
        clearActivityLogs: function() {
            if (!confirm(wplm_admin.strings.confirm_clear_logs)) {
                return;
            }

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_clear_activity_logs',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        if ($('#activity-log-table').length) {
                            $('#activity-log-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Clear old logs
         */
        clearOldLogs: function() {
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_clear_old_logs',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        if ($('#activity-log-table').length) {
                            $('#activity-log-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Clear all logs
         */
        clearAllLogs: function() {
            if (!confirm(wplm_admin.strings.confirm_clear_logs)) {
                return;
            }

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_clear_all_logs',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        if ($('#activity-log-table').length) {
                            $('#activity-log-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Sync WooCommerce products
         */
        syncWooCommerceProducts: function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text(wplm_admin.strings.loading);

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_sync_wc_products',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        if ($('#products-table').length) {
                            $('#products-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Toggle status
         */
        toggleStatus: function() {
            const $toggle = $(this);
            const itemId = $toggle.data('item-id');
            const itemType = $toggle.data('item-type');
            const newStatus = $toggle.is(':checked') ? 'active' : 'inactive';

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_toggle_status',
                    nonce: wplm_admin.nonce,
                    item_id: itemId,
                    item_type: itemType,
                    status: newStatus
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                    } else {
                        $toggle.prop('checked', !$toggle.is(':checked'));
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    $toggle.prop('checked', !$toggle.is(':checked'));
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Apply bulk action
         */
        applyBulkAction: function() {
            const action = $('#bulk-action-select').val();
            const selected = [];
            
            $('.wplm-bulk-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            
            if (!action || selected.length === 0) {
                WPLM_Enhanced_Admin.showNotification('Please select an action and items', 'error');
                return;
            }
            
            if (!confirm('Are you sure you want to perform this bulk action?')) {
                return;
            }

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_bulk_action',
                    nonce: wplm_admin.nonce,
                    bulk_action: action,
                    items: selected
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        $('.wplm-enhanced-table').DataTable().ajax.reload();
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        },

        /**
         * Show notification
         */
        showNotification: function(message, type) {
            const $notification = $('<div class="wplm-notification wplm-notification-' + type + '">' + message + '</div>');
            
            $('body').append($notification);
            
            setTimeout(function() {
                $notification.addClass('wplm-notification-show');
            }, 100);
            
            setTimeout(function() {
                $notification.removeClass('wplm-notification-show');
                setTimeout(function() {
                    $notification.remove();
                }, 300);
            }, 3000);
        },

        /**
         * Helper function to get last 30 days
         */
        getLast30Days: function() {
            const dates = [];
            for (let i = 29; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                dates.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
            }
            return dates;
        },

        /**
         * Generate mock data for charts
         */
        generateMockData: function(days) {
            const data = [];
            for (let i = 0; i < days; i++) {
                data.push(Math.floor(Math.random() * 50) + 10);
            }
            return data;
        },

        /**
         * Initialize Select2 for AJAX dropdowns
         */
        initSelect2: function() {
            if ($.fn.select2) {
                $('#wplm_subscription_customer_id').select2({
                    placeholder: wplm_admin.strings.select_customer,
                    allowClear: true,
                    ajax: {
                        url: wplm_admin.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'wplm_search_customers',
                                nonce: wplm_admin.nonce,
                                search: params.term, // search term
                                page: params.page
                            };
                        },
                        processResults: function (data, params) {
                            params.page = params.page || 1;
                            return {
                                results: data.data.customers,
                                pagination: {
                                    more: (params.page * 30) < data.data.total_count
                                }
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 2,
                    templateResult: function(customer) {
                        if (customer.loading) return customer.text;
                        return `<div>${customer.text} (${customer.email})</div>`;
                    },
                    templateSelection: function(customer) {
                        return customer.text || customer.id;
                    }
                });
        
                $('#wplm_subscription_product_id').select2({
                    placeholder: wplm_admin.strings.select_product,
                    allowClear: true,
                    ajax: {
                        url: wplm_admin.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'wplm_search_products',
                                nonce: wplm_admin.nonce,
                                search: params.term, // search term
                                page: params.page
                            };
                        },
                        processResults: function (data, params) {
                            params.page = params.page || 1;
                            return {
                                results: data.data.products,
                                pagination: {
                                    more: (params.page * 30) < data.data.total_count
                                }
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 2,
                    templateResult: function(product) {
                        if (product.loading) return product.text;
                        return `<div>${product.text} (ID: ${product.id})</div>`;
                    },
                    templateSelection: function(product) {
                        return product.text || product.id;
                    }
                });
        
                // Initialize Select2 for product search fields in Add/Bulk License Modals
                $('.wplm-select2-product-search').select2({
                    placeholder: wplm_admin.strings.select_product,
                    allowClear: true,
                    ajax: {
                        url: wplm_admin.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'wplm_search_products',
                                nonce: wplm_admin.nonce,
                                search: params.term, // search term
                                page: params.page
                            };
                        },
                        processResults: function (data, params) {
                            params.page = params.page || 1;
                            return {
                                results: data.data.products,
                                pagination: {
                                    more: (params.page * 30) < data.data.total_count
                                }
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 2,
                    templateResult: function(product) {
                        if (product.loading) return product.text;
                        return `<div>${product.text} (ID: ${product.id})</div>`;
                    },
                    templateSelection: function(product) {
                        return product.text || product.id;
                    }
                });
            }
        },

        /**
         * Open create subscription modal
         */
        openCreateSubscriptionModal: function() {
            const modalHtml = `
                <div id="create-subscription-modal" class="wplm-modal" style="display: block;">
                    <div class="wplm-modal-content">
                        <div class="wplm-modal-header">
                            <h3>${wplm_admin.strings.create_subscription}</h3>
                            <span class="wplm-close">&times;</span>
                        </div>
                        <div class="wplm-modal-body">
                            <form id="create-subscription-form">
                                <table class="form-table">
                                    <tr>
                                        <th><label for="cs_customer_email">${wplm_admin.strings.customer_email}</label></th>
                                        <td><input type="email" id="cs_customer_email" name="customer_email" class="regular-text" required /></td>
                                    </tr>
                                    <tr>
                                        <th><label for="cs_product_name">${wplm_admin.strings.product_name}</label></th>
                                        <td><input type="text" id="cs_product_name" name="product_name" class="regular-text" required /></td>
                                    </tr>
                                    <tr>
                                        <th><label for="cs_billing_interval">${wplm_admin.strings.billing_frequency}</label></th>
                                        <td>
                                            <input type="number" id="cs_billing_interval" name="billing_interval" value="1" min="1" style="width: 80px;" />
                                            <select name="billing_period">
                                                <option value="day">${wplm_admin.strings.days}</option>
                                                <option value="week">${wplm_admin.strings.weeks}</option>
                                                <option value="month" selected>${wplm_admin.strings.months}</option>
                                                <option value="year">${wplm_admin.strings.years}</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="cs_start_date">${wplm_admin.strings.start_date}</label></th>
                                        <td><input type="date" id="cs_start_date" name="start_date" value="${new Date().toISOString().slice(0, 10)}" class="regular-text" /></td>
                                    </tr>
                                    <tr>
                                        <th><label for="cs_end_date">${wplm_admin.strings.end_date}</label></th>
                                        <td><input type="date" id="cs_end_date" name="end_date" class="regular-text" /><p class="description">${wplm_admin.strings.leave_empty_for_ongoing}</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="cs_status">${wplm_admin.strings.status}</label></th>
                                        <td>
                                            <select id="cs_status" name="status">
                                                <option value="active">${wplm_admin.strings.active}</option>
                                                <option value="on-hold">${wplm_admin.strings.on_hold}</option>
                                                <option value="cancelled">${wplm_admin.strings.cancelled}</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit">
                                    <button type="submit" class="button button-primary" id="submit-create-subscription">${wplm_admin.strings.create_subscription}</button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);

            // Handle form submission
            $('#create-subscription-form').on('submit', function(e) {
                e.preventDefault();
                const $form = $(this);
                const $submitButton = $('#submit-create-subscription');
                $submitButton.prop('disabled', true).text(wplm_admin.strings.loading);

                $.ajax({
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wplm_create_subscription',
                        nonce: wplm_admin.subscription_nonce,
                        customer_email: $('#cs_customer_email').val(),
                        product_name: $('#cs_product_name').val(),
                        billing_interval: $('#cs_billing_interval').val(),
                        billing_period: $('select[name="billing_period"]').val(),
                        start_date: $('#cs_start_date').val(),
                        end_date: $('#cs_end_date').val(),
                        status: $('#cs_status').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                            WPLM_Enhanced_Admin.closeModal();
                            $('#subscriptions-table').DataTable().ajax.reload();
                        } else {
                            WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                    },
                    complete: function() {
                        $submitButton.prop('disabled', false).text(wplm_admin.strings.create_subscription);
                    }
                });
            });
        },

        /**
         * Close create subscription modal
         */
        closeCreateSubscriptionModal: function() {
            $('#create-subscription-modal').hide();
        },

        /**
         * Create new subscription
         */
        createSubscription: function() {
            const $form = $('#create-subscription-form');
            const $button = $('#create-subscription-button');
            const originalText = $button.text();
            
            $button.prop('disabled', true).text(wplm_admin.strings.loading);

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: $form.serialize(),
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        $('#subscriptions-table').DataTable().ajax.reload();
                        WPLM_Enhanced_Admin.closeCreateSubscriptionModal();
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },
        
        /**
         * Scan WooCommerce Orders for licensed products.
         */
        scanWooCommerceOrders: function() {
            const $button = $(this);
            const $form = $('#wc-bulk-form');
            const $resultsDiv = $('#order-scan-results');
            const $generateButton = $('#generate-licenses-button');
            const originalButtonText = $button.text();

            $button.prop('disabled', true).text(wplm_admin.strings.scanning_orders_text);
            $generateButton.prop('disabled', true);
            $resultsDiv.html('<div class="wplm-loading-spinner"></div> ' + wplm_admin.strings.scanning_orders_text + '...');

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: $form.serialize() + '&action=wplm_scan_wc_orders' + '&nonce=' + wplm_admin.bulk_operations_nonce,
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        let resultsHtml = '<h4>' + response.data.message + '</h4>';
                        if (response.data.scannable_orders.length > 0) {
                            resultsHtml += '<p>' + wplm_admin.strings.orders_to_process + ':</p>';
                            resultsHtml += '<ul class="wplm-list-disc wplm-list-inside">';
                            response.data.scannable_orders.forEach(function(order) {
                                resultsHtml += `<li><a href="${wplm_admin.admin_url}post.php?post=${order.order_id}&action=edit" target="_blank">#${order.order_number}</a> (${order.customer_email}) - ${order.total_products} ${wplm_admin.strings.licenses_text}</li>`;
                            });
                            resultsHtml += '</ul>';
                            $generateButton.prop('disabled', false).data('products-to-license', response.data.products_to_license);
                            $generateButton.data('send-emails', $form.find('input[name="send_emails"]').is(':checked'));

                        } else {
                            resultsHtml += '<p>' + wplm_admin.strings.no_orders_found + '</p>';
                        }
                        $resultsDiv.html(resultsHtml);
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        $resultsDiv.html('<p class="error-message">' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error_scanning_orders, 'error');
                    $resultsDiv.html('<p class="error-message">' + wplm_admin.strings.error_scanning_orders + '</p>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        },

        /**
         * Generate licenses for scanned WooCommerce orders.
         */
        generateWooCommerceLicenses: function() {
            const $button = $(this);
            const $resultsDiv = $('#wc-result');
            const productsToLicense = $button.data('products-to-license');
            const sendEmails = $button.data('send-emails');
            const originalButtonText = $button.text();

            if (!productsToLicense || Object.keys(productsToLicense).length === 0) {
                WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.no_licenses_to_generate, 'warning');
                return;
            }

            if (!confirm(wplm_admin.strings.confirm_generate_licenses)) {
                return;
            }

            $button.prop('disabled', true).text(wplm_admin.strings.generating_licenses_text);
            $resultsDiv.html('<div class="wplm-loading-spinner"></div> ' + wplm_admin.strings.generating_licenses_text + '...');

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_generate_wc_licenses',
                    nonce: wplm_admin.bulk_operations_nonce,
                    orders_data: JSON.stringify(productsToLicense),
                    send_emails: sendEmails,
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        let detailsHtml = '<h4>' + response.data.message + '</h4>';
                        detailsHtml += '<p>' + wplm_admin.strings.generated_licenses_details + ':</p>';
                        detailsHtml += '<ul class="wplm-list-disc wplm-list-inside">';
                        response.data.details.forEach(function(license) {
                            detailsHtml += `<li><a href="${wplm_admin.admin_url}post.php?post=${license.license_id}&action=edit" target="_blank">${license.license_key}</a> (${license.product_name}) for <a href="${wplm_admin.admin_url}post.php?post=${license.order_id}&action=edit" target="_blank">Order #${license.order_id}</a> - ${license.customer_email}</li>`;
                        });
                        detailsHtml += '</ul>';
                        $resultsDiv.html(detailsHtml);
                        // Optionally refresh licenses table if it's on the same page
                        if ($('#licenses-table').length) {
                            $('#licenses-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        $resultsDiv.html('<p class="error-message">' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error_generating_licenses, 'error');
                    $resultsDiv.html('<p class="error-message">' + wplm_admin.strings.error_generating_licenses + '</p>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPLM_Enhanced_Admin.init();
    });

})(jQuery);

// Additional CSS for notifications
const notificationCSS = `
.wplm-notification {
    position: fixed;
    top: 32px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 6px;
    color: white;
    font-weight: 500;
    z-index: 999999;
    transform: translateX(100%);
    transition: transform 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.wplm-notification-show {
    transform: translateX(0);
}

.wplm-notification-success {
    background: #28a745;
}

.wplm-notification-error {
    background: #dc3545;
}

.wplm-notification-warning {
    background: #ffc107;
    color: #212529;
}

.wplm-notification-info {
    background: #17a2b8;
}

[dir="rtl"] .wplm-notification {
    right: auto;
    left: 20px;
    transform: translateX(-100%);
}

[dir="rtl"] .wplm-notification-show {
    transform: translateX(0);
}
`;

// Inject notification CSS
const style = document.createElement('style');
style.textContent = notificationCSS;
document.head.appendChild(style);

