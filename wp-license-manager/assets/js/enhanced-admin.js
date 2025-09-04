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
            $(document).on('click', '.wplm-view-order', this.viewOrderDetails);
            $(document).on('click', '.wplm-close', this.closeModal);
            
            // Action buttons
            $(document).on('click', '#clear-logs', this.clearActivityLogs);
            $(document).on('click', '#clear-old-logs', this.clearOldLogs);
            $(document).on('click', '#clear-all-logs', this.clearAllLogs);
            $(document).on('click', '#sync-wc-products', this.syncWooCommerceProducts);
            $(document).on('click', '#sync-wc-customers', this.syncWooCommerceCustomers);
            $(document).on('click', '#sync-wc-customer-orders', this.syncWooCommerceCustomerOrders);
            $(document).on('click', '#sync-wc-orders', this.syncWooCommerceOrdersForOrders);
            
            // Orders page event listeners
            $(document).on('click', '#add-order', this.showAddOrderModal);
            $(document).on('submit', '#add-order-form', this.createOrder);
            

            
            // Status toggles
            $(document).on('change', '.wplm-status-toggle', this.toggleStatus);

            // Action buttons in License Management table (Activate/Deactivate)
            $(document).on('click', '.wplm-toggle-status', this.clickToggleStatus);
            
            // Bulk actions
            $(document).on('click', '#apply-bulk-action', this.applyBulkAction);
            $(document).on('click', '#apply-bulk-action-products', this.applyBulkActionProducts);
            $(document).on('click', '#apply-bulk-action-subscriptions', this.applyBulkActionSubscriptions);
            
            // Select all checkboxes
            $(document).on('change', '#select-all-licenses', this.toggleSelectAllLicenses);
            $(document).on('change', '#select-all-products', this.toggleSelectAllProducts);
            $(document).on('change', '#select-all-subscriptions', this.toggleSelectAllSubscriptions);
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
            console.log('Initializing licenses table...');
            const table = this.initDataTable('#licenses-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_licenses';
                        d.nonce = wplm_admin.nonce;
                        d.status = $('#license-status-filter').val();
                        d.product = $('#license-product-filter').val();
                        console.log('AJAX data:', d);
                    },
                    error: function(xhr, error, thrown) {
                        console.error('DataTables AJAX error:', error, thrown);
                        console.log('XHR response:', xhr.responseText);
                    }
                },
                columns: [
                    { 
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'wplm-checkbox-column',
                        render: function(data, type, row) {
                            return '<input type="checkbox" class="wplm-bulk-checkbox" value="' + row.id + '">';
                        }
                    },
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

            // Checkbox column is now in HTML structure
        },

        /**
         * Initialize products table
         */
        initProductsTable: function() {
            console.log('Initializing products table...');
            const table = this.initDataTable('#products-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_products';
                        d.nonce = wplm_admin.nonce;
                        console.log('Products AJAX data:', d);
                    },
                    error: function(xhr, error, thrown) {
                        console.error('Products DataTables AJAX error:', error, thrown);
                        console.log('Products XHR response:', xhr.responseText);
                    }
                },
                columns: [
                    { 
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'wplm-checkbox-column',
                        render: function(data, type, row) {
                            return '<input type="checkbox" class="wplm-bulk-checkbox" value="' + row.id + '">';
                        }
                    },
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

            // Checkbox column is now in HTML structure
        },

        /**
         * Initialize subscriptions table
         */
        initSubscriptionsTable: function() {
            const table = this.initDataTable('#subscriptions-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_subscriptions';
                        d.nonce = wplm_admin.nonce;
                    }
                },
                columns: [
                    { 
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'wplm-checkbox-column',
                        render: function(data, type, row) {
                            return '<input type="checkbox" class="wplm-bulk-checkbox" value="' + row.id + '">';
                        }
                    },
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

            // Checkbox column is now in HTML structure
        },

        /**
         * Initialize customers table
         */
        initCustomersTable: function() {
            console.log('Initializing customers table...');
            const table = this.initDataTable('#customers-table', {
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_customers';
                        d.nonce = wplm_admin.nonce;
                        d.search = $('#customer-search').val();
                        console.log('Customers AJAX data:', d);
                    },
                    error: function(xhr, error, thrown) {
                        console.error('Customers DataTables AJAX error:', error, thrown);
                        console.log('Customers XHR response:', xhr.responseText);
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
            
            console.log('Customers table initialized:', table);
        },

        /**
         * Initialize orders table
         */
        initOrdersTable: function() {
            console.log('WPLM_Enhanced_Admin.initOrdersTable called');
            console.log('Initializing orders table...');
            
            // Simple test to see if the table exists
            if ($('#orders-table').length === 0) {
                console.error('Orders table not found on page');
                return;
            }
            
            console.log('Orders table found, proceeding with initialization...');
            console.log('Orders table element:', $('#orders-table')[0]);
            console.log('Orders table HTML:', $('#orders-table').html());
            
            // First, try to load data manually to test the AJAX endpoint
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_get_orders',
                    nonce: wplm_admin.nonce,
                    draw: 1,
                    start: 0,
                    length: 25
                },
                success: function(response) {
                    console.log('Manual AJAX test successful:', response);
                    
                    // If we got data, manually populate the table as fallback
                    if (response && response.data && response.data.length > 0) {
                        console.log('Manual population of orders table');
                        const tbody = $('#orders-table tbody');
                        let html = '';
                        response.data.forEach(function(row) {
                            html += '<tr>' +
                                '<td>' + (row.order_number || '—') + '</td>' +
                                '<td>' + (row.customer || '—') + '</td>' +
                                '<td>' + (row.email || '—') + '</td>' +
                                '<td>' + (row.total || '—') + '</td>' +
                                '<td>' + (row.status || '—') + '</td>' +
                                '<td>' + (row.date || '—') + '</td>' +
                                '<td>' + (row.actions || '—') + '</td>' +
                            '</tr>';
                        });
                        tbody.html(html);
                        
                        // Hide the processing indicator
                        $('#orders-table').closest('.dataTables_wrapper').find('.dataTables_processing').hide();
                    }
                },
                error: function(xhr, error, thrown) {
                    console.error('Manual AJAX test failed:', error, thrown);
                    console.log('Manual AJAX XHR response:', xhr.responseText);
                }
            });
            
            const table = this.initDataTable('#orders-table', {
                serverSide: true,
                processing: true,
                ajax: {
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: function(d) {
                        d.action = 'wplm_get_orders';
                        d.nonce = wplm_admin.nonce;
                        d.search = d.search || {};
                        d.search.value = $('#order-search').val();
                        console.log('Orders AJAX data:', d);
                    },
                    dataSrc: function(json) {
                        console.log('DataTables dataSrc called with:', json);
                        return json.data || [];
                    },
                    complete: function(xhr) {
                        console.log('Orders AJAX complete - Response:', xhr.responseText);
                    },
                    success: function(data) {
                        console.log('Orders AJAX success response:', data);
                    },
                    error: function(xhr, error, thrown) {
                        console.error('Orders DataTables AJAX error:', error, thrown);
                        console.log('Orders XHR response:', xhr.responseText);
                    }
                },
                columns: [
                    { 
                        data: 'order_number',
                        title: 'Order Number',
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    },
                    { 
                        data: 'customer',
                        title: 'Customer',
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    },
                    { 
                        data: 'email',
                        title: 'Email',
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    },
                    { 
                        data: 'total',
                        title: 'Total',
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    },
                    { 
                        data: 'status',
                        title: 'Status',
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    },
                    { 
                        data: 'date',
                        title: 'Date',
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    },
                    { 
                        data: 'actions',
                        title: 'Actions',
                        orderable: false,
                        searchable: false,
                        render: function(data, type, row) {
                            return data || '—';
                        }
                    }
                ]
            });
            
            console.log('Orders table initialized:', table);

            // Ensure reload on search button
            $(document).off('click.wplmSearchOrders').on('click.wplmSearchOrders', '#search-orders', function() {
                if ($.fn.DataTable.isDataTable('#orders-table')) {
                    $('#orders-table').DataTable().ajax.reload();
                }
            });
            
            // Additional debugging after initialization
            setTimeout(function() {
                console.log('DataTable instance:', table);
                console.log('DataTable settings:', table ? table.settings() : 'No table instance');
            }, 2000);
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
            // Check if DataTables is available
            if (typeof $.fn.DataTable === 'undefined') {
                console.error('DataTables is not loaded!');
                $(selector).html('<div class="wplm-error">DataTables library is not loaded. Please refresh the page.</div>');
                return null;
            }

            const defaultOptions = {
                processing: true,
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                language: {
                    processing: '<div class="wplm-loading">' + (wplm_admin ? wplm_admin.strings.loading : 'Loading...') + '</div>',
                    emptyTable: 'No data available',
                    zeroRecords: 'No matching records found'
                },
                drawCallback: function() {
                    // Re-initialize tooltips after table draw
                    WPLM_Enhanced_Admin.initTooltips();
                }
            };

            const finalOptions = $.extend(true, {}, defaultOptions, options);
            
            console.log('DataTables final options:', finalOptions);
            
            if ($.fn.DataTable.isDataTable(selector)) {
                console.log('Destroying existing DataTable');
                $(selector).DataTable().destroy();
            }
            
            const table = $(selector).DataTable(finalOptions);
            console.log('DataTable created:', table);
            return table;
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
         * View order details
         */
        viewOrderDetails: function(e) {
            e.preventDefault();
            const orderId = $(this).data('order-id');
            
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_get_order_details',
                    nonce: wplm_admin.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        $('#order-modal-body').html(response.data.html);
                        $('#order-modal').show();
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
         * Sync WooCommerce customers
         */
        syncWooCommerceCustomers: function() {
            const $button = $(this);
            const originalText = $button.text();
            const $status = $('#wc-customer-sync-status');
            
            $button.prop('disabled', true).text(wplm_admin.strings.loading);
            $status.html('<div class="wplm-sync-progress">Syncing customers from WooCommerce...</div>');

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_sync_wc_customers',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    console.log('Sync response:', response);
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        $status.html('<div class="wplm-sync-success">' + response.data.message + '</div>');
                        if ($('#customers-table').length) {
                            console.log('Reloading customers table...');
                            $('#customers-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        $status.html('<div class="wplm-sync-error">' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                    $status.html('<div class="wplm-sync-error">Sync failed. Please try again.</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Sync WooCommerce customer orders
         */
        syncWooCommerceCustomerOrders: function() {
            const $button = $(this);
            const originalText = $button.text();
            const $status = $('#wc-customer-sync-status');
            
            $button.prop('disabled', true).text(wplm_admin.strings.loading);
            $status.html('<div class="wplm-sync-progress">Syncing recent orders from WooCommerce...</div>');

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_sync_wc_customer_orders',
                    nonce: wplm_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        $status.html('<div class="wplm-sync-success">' + response.data.message + '</div>');
                        if ($('#customers-table').length) {
                            $('#customers-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        $status.html('<div class="wplm-sync-error">' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                    $status.html('<div class="wplm-sync-error">Sync failed. Please try again.</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Sync WooCommerce orders for order management
         */
        syncWooCommerceOrdersForOrders: function() {
            const $button = $(this);
            const originalText = $button.text();
            const $status = $('#wc-order-sync-status');
            
            $button.prop('disabled', true).text(wplm_admin.strings.loading);
            $status.html('<div class="wplm-sync-progress">Syncing orders from WooCommerce...</div>');

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_sync_wc_orders',
                    nonce: wplm_admin.nonce,
                    days_back: 30
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        $status.html('<div class="wplm-sync-success">' + response.data.message + '</div>');
                        if ($('#orders-table').length) {
                            $('#orders-table').DataTable().ajax.reload();
                        }
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        $status.html('<div class="wplm-sync-error">' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                    $status.html('<div class="wplm-sync-error">Sync failed. Please try again.</div>');
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
         * Click handler for action buttons rendered as .wplm-toggle-status
         */
        clickToggleStatus: function(e) {
            e.preventDefault();
            const $btn = $(this);
            const itemId = $btn.data('id');
            const nextStatus = $btn.data('status'); // 'active' or 'inactive'

            if (!itemId || !nextStatus) {
                WPLM_Enhanced_Admin.showNotification('Invalid action parameters', 'error');
                return;
            }

            const originalText = $btn.text();
            $btn.prop('disabled', true).text(wplm_admin.strings && wplm_admin.strings.loading ? wplm_admin.strings.loading : 'Working...');

            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_toggle_status',
                    nonce: wplm_admin.nonce,
                    item_id: itemId,
                    item_type: 'license',
                    new_status: nextStatus
                },
                success: function(response) {
                    if (response && response.success) {
                        // Toggle button label and data-status for the next action
                        if (nextStatus === 'inactive') {
                            $btn.text('Activate');
                            $btn.data('status', 'active');
                        } else {
                            $btn.text('Deactivate');
                            $btn.data('status', 'inactive');
                        }
                        WPLM_Enhanced_Admin.showNotification(response.data && response.data.message ? response.data.message : 'Status updated', 'success');
                    } else {
                        $btn.text(originalText);
                        WPLM_Enhanced_Admin.showNotification(response && response.data && response.data.message ? response.data.message : 'Update failed', 'error');
                    }
                },
                error: function() {
                    $btn.text(originalText);
                    WPLM_Enhanced_Admin.showNotification('Request failed', 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * Apply bulk action for licenses
         */
        applyBulkAction: function() {
            const action = $('#bulk-action-selector').val();
            const selected = [];
            
            $('#licenses-table .wplm-bulk-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            
            if (!action || selected.length === 0) {
                WPLM_Enhanced_Admin.showNotification('Please select an action and items', 'error');
                return;
            }
            
            // Special handling for lightning deactivation
            if (action === 'force_deactivate') {
                if (!confirm('Are you sure you want to LIGHTNING DEACTIVATE these licenses? This will instantly clear all domain activations and cannot be undone.')) {
                    return;
                }
                
                const startTime = performance.now();
                
                $.ajax({
                    url: wplm_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wplm_force_deactivate_licenses',
                        nonce: wplm_admin.nonce,
                        license_ids: selected
                    },
                    success: function(response) {
                        const endTime = performance.now();
                        const duration = Math.round(endTime - startTime);
                        
                        if (response.success) {
                            WPLM_Enhanced_Admin.showNotification(
                                `⚡ ${response.data.message} (Client: ${duration}ms)`, 
                                'success'
                            );
                            $('#licenses-table').DataTable().ajax.reload();
                        } else {
                            WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        WPLM_Enhanced_Admin.showNotification('Lightning deactivation failed', 'error');
                    }
                });
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
                        $('#licenses-table').DataTable().ajax.reload();
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
         * Apply bulk action for products
         */
        applyBulkActionProducts: function() {
            const action = $('#bulk-action-selector-products').val();
            const selected = [];
            
            $('#products-table .wplm-bulk-checkbox:checked').each(function() {
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
                        $('#products-table').DataTable().ajax.reload();
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
         * Apply bulk action for subscriptions
         */
        applyBulkActionSubscriptions: function() {
            const action = $('#bulk-action-selector-subscriptions').val();
            const selected = [];
            
            $('#subscriptions-table .wplm-bulk-checkbox:checked').each(function() {
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
                        $('#subscriptions-table').DataTable().ajax.reload();
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
         * Toggle select all for licenses
         */
        toggleSelectAllLicenses: function() {
            const isChecked = $(this).is(':checked');
            $('#licenses-table .wplm-bulk-checkbox').prop('checked', isChecked);
        },

        /**
         * Toggle select all for products
         */
        toggleSelectAllProducts: function() {
            const isChecked = $(this).is(':checked');
            $('#products-table .wplm-bulk-checkbox').prop('checked', isChecked);
        },

        /**
         * Toggle select all for subscriptions
         */
        toggleSelectAllSubscriptions: function() {
            const isChecked = $(this).is(':checked');
            $('#subscriptions-table .wplm-bulk-checkbox').prop('checked', isChecked);
        },

        /**
         * Show add order modal
         */
        showAddOrderModal: function() {
            $('#add-order-modal').show();
        },

        /**
         * Create new order
         */
        createOrder: function(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            data.action = 'wplm_create_order';
            data.nonce = wplm_admin.nonce;
            
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'success');
                        WPLM_Enhanced_Admin.closeModal();
                        $('#orders-table').DataTable().ajax.reload();
                        e.target.reset();
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
         * View order details
         */
        viewOrderDetails: function() {
            const orderId = $(this).data('order-id');
            
            $.ajax({
                url: wplm_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_get_order_details',
                    nonce: wplm_admin.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        $('#order-modal-body').html(response.data.html);
                        $('#order-modal').show();
                    } else {
                        WPLM_Enhanced_Admin.showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPLM_Enhanced_Admin.showNotification(wplm_admin.strings.error, 'error');
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Check if wplm_admin object is available
        if (typeof wplm_admin === 'undefined') {
            console.error('wplm_admin object is not defined!');
            return;
        }
        
        console.log('wplm_admin object:', wplm_admin);
        console.log('WPLM_Enhanced_Admin object:', typeof WPLM_Enhanced_Admin);
        console.log('WPLM_Enhanced_Admin.initOrdersTable:', typeof WPLM_Enhanced_Admin.initOrdersTable);
        
        WPLM_Enhanced_Admin.init();
        
        // Initialize specific tables based on current page
        if ($('#licenses-table').length) {
            console.log('Found licenses table, initializing...');
            WPLM_Enhanced_Admin.initLicensesTable();
        }
        
        if ($('#products-table').length) {
            console.log('Found products table, initializing...');
            WPLM_Enhanced_Admin.initProductsTable();
        }
        
        if ($('#subscriptions-table').length) {
            console.log('Found subscriptions table, initializing...');
            WPLM_Enhanced_Admin.initSubscriptionsTable();
        }
        
        if ($('#customers-table').length) {
            console.log('Found customers table, initializing...');
            WPLM_Enhanced_Admin.initCustomersTable();
        }
        
        if ($('#orders-table').length && !$.fn.DataTable.isDataTable('#orders-table')) {
            console.log('Found orders table, initializing...');
            WPLM_Enhanced_Admin.initOrdersTable();
        }
        
        if ($('#activity-log-table').length) {
            console.log('Found activity log table, initializing...');
            WPLM_Enhanced_Admin.initActivityLogTable();
        }
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

