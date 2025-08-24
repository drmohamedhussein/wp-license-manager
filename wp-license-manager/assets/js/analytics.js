/**
 * Analytics Dashboard JavaScript for WP License Manager
 */

(function($) {
    'use strict';

    var WPLM_Analytics = {
        charts: {},
        
        init: function() {
            this.bindEvents();
            this.loadAnalytics();
        },

        bindEvents: function() {
            $('#refresh-analytics').on('click', this.loadAnalytics.bind(this));
            $('#export-analytics').on('click', this.exportAnalytics.bind(this));
            $('#analytics-period').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#custom-date-range').show();
                } else {
                    $('#custom-date-range').hide();
                    WPLM_Analytics.loadAnalytics();
                }
            });
            $('#start-date, #end-date').on('change', this.loadAnalytics.bind(this));
            
            // Report tabs
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.wplm-report-tab').removeClass('active');
                $(target).addClass('active');
            });
        },

        loadAnalytics: function() {
            var period = $('#analytics-period').val();
            var data = {
                action: 'wplm_get_analytics_data',
                nonce: wplm_analytics.nonce,
                period: period
            };
            
            if (period === 'custom') {
                data.start_date = $('#start-date').val();
                data.end_date = $('#end-date').val();
                
                if (!data.start_date || !data.end_date) {
                    return;
                }
            }
            
            // Show loading state
            this.showLoadingState();
            
            $.ajax({
                url: wplm_analytics.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        WPLM_Analytics.updateOverview(response.data.overview);
                        WPLM_Analytics.updateCharts(response.data.charts);
                        WPLM_Analytics.updateReports(response.data.reports);
                    } else {
                        WPLM_Analytics.showError(response.data.message || wplm_analytics.strings.error);
                    }
                },
                error: function() {
                    WPLM_Analytics.showError(wplm_analytics.strings.error);
                }
            });
        },

        showLoadingState: function() {
            // Update overview cards
            $('.wplm-stat-value').text('-');
            
            // Show loading in report tables
            $('.wplm-report-table tbody').html('<tr><td colspan="7">' + wplm_analytics.strings.loading + '</td></tr>');
        },

        updateOverview: function(data) {
            $('#total-licenses').text(this.formatNumber(data.total_licenses));
            $('#active-licenses').text(this.formatNumber(data.active_licenses));
            $('#expired-licenses').text(this.formatNumber(data.expired_licenses));
            $('#total-activations').text(this.formatNumber(data.total_activations));
            $('#total-customers').text(this.formatNumber(data.total_customers));
            $('#revenue').text(this.formatCurrency(data.revenue));
        },

        updateCharts: function(chartsData) {
            // Destroy existing charts
            Object.keys(this.charts).forEach(function(key) {
                if (WPLM_Analytics.charts[key]) {
                    WPLM_Analytics.charts[key].destroy();
                }
            });
            
            // License Trend Chart
            if (chartsData.license_trend) {
                this.charts.licenseTrend = this.createLineChart('license-trend-chart', chartsData.license_trend);
            }
            
            // Status Distribution Chart
            if (chartsData.status_distribution) {
                this.charts.statusDistribution = this.createPieChart('status-distribution-chart', chartsData.status_distribution);
            }
            
            // Product Performance Chart
            if (chartsData.product_performance) {
                this.charts.productPerformance = this.createBarChart('product-performance-chart', chartsData.product_performance);
            }
            
            // Activation Activity Chart
            if (chartsData.activation_activity) {
                this.charts.activationActivity = this.createLineChart('activation-activity-chart', chartsData.activation_activity);
            }
        },

        createLineChart: function(elementId, data) {
            var ctx = document.getElementById(elementId);
            if (!ctx) return null;
            
            return new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        },

        createPieChart: function(elementId, data) {
            var ctx = document.getElementById(elementId);
            if (!ctx) return null;
            
            return new Chart(ctx, {
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

        createBarChart: function(elementId, data) {
            var ctx = document.getElementById(elementId);
            if (!ctx) return null;
            
            return new Chart(ctx, {
                type: 'bar',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        },

        updateReports: function(reportsData) {
            // Update licenses report
            if (reportsData.licenses) {
                this.updateLicensesReport(reportsData.licenses);
            }
            
            // Update products report
            if (reportsData.products) {
                this.updateProductsReport(reportsData.products);
            }
            
            // Update customers report
            if (reportsData.customers) {
                this.updateCustomersReport(reportsData.customers);
            }
            
            // Update activity report
            if (reportsData.activity) {
                this.updateActivityReport(reportsData.activity);
            }
        },

        updateLicensesReport: function(data) {
            var $tbody = $('#licenses-analytics-table tbody');
            $tbody.empty();
            
            if (data.length === 0) {
                $tbody.html('<tr><td colspan="7">' + wplm_analytics.strings.no_data + '</td></tr>');
                return;
            }
            
            data.forEach(function(license) {
                var row = '<tr>' +
                    '<td><code>' + license.license_key + '</code></td>' +
                    '<td>' + license.product + '</td>' +
                    '<td>' + license.customer + '</td>' +
                    '<td><span class="status-badge status-' + license.status.toLowerCase() + '">' + license.status + '</span></td>' +
                    '<td>' + license.activations + '</td>' +
                    '<td>' + license.created + '</td>' +
                    '<td>' + license.expires + '</td>' +
                    '</tr>';
                $tbody.append(row);
            });
        },

        updateProductsReport: function(data) {
            var $tbody = $('#products-analytics-table tbody');
            $tbody.empty();
            
            if (data.length === 0) {
                $tbody.html('<tr><td colspan="6">' + wplm_analytics.strings.no_data + '</td></tr>');
                return;
            }
            
            data.forEach(function(product) {
                var row = '<tr>' +
                    '<td><strong>' + product.product + '</strong></td>' +
                    '<td>' + product.total_licenses + '</td>' +
                    '<td>' + product.active_licenses + '</td>' +
                    '<td>' + product.expired_licenses + '</td>' +
                    '<td>' + product.total_activations + '</td>' +
                    '<td>' + product.revenue + '</td>' +
                    '</tr>';
                $tbody.append(row);
            });
        },

        updateCustomersReport: function(data) {
            var $tbody = $('#customers-analytics-table tbody');
            $tbody.empty();
            
            if (data.length === 0) {
                $tbody.html('<tr><td colspan="6">' + wplm_analytics.strings.no_data + '</td></tr>');
                return;
            }
            
            data.forEach(function(customer) {
                var row = '<tr>' +
                    '<td>' + customer.customer + '</td>' +
                    '<td>' + customer.total_licenses + '</td>' +
                    '<td>' + customer.active_licenses + '</td>' +
                    '<td>' + customer.total_spent + '</td>' +
                    '<td>' + customer.last_activity + '</td>' +
                    '<td>' + customer.join_date + '</td>' +
                    '</tr>';
                $tbody.append(row);
            });
        },

        updateActivityReport: function(data) {
            var $tbody = $('#activity-analytics-table tbody');
            $tbody.empty();
            
            if (data.length === 0) {
                $tbody.html('<tr><td colspan="5">' + wplm_analytics.strings.no_data + '</td></tr>');
                return;
            }
            
            data.forEach(function(activity) {
                var associatedObjectHtml = '';
                if (activity.object_id && activity.object_title && activity.object_type && activity.object_link) {
                    associatedObjectHtml = `<a href="${activity.object_link}" target="_blank">${activity.object_title} (${activity.object_type.replace('wplm_', '').toUpperCase()})</a>`;
                } else if (activity.object_title) {
                    associatedObjectHtml = activity.object_title; // Fallback if no link or type
                } else {
                    associatedObjectHtml = '-';
                }

                var row = '<tr>' +
                    '<td>' + activity.date + '</td>' +
                    '<td>' + activity.action + '</td>' +
                    '<td>' + associatedObjectHtml + '</td>' +
                    '<td>' + activity.user + '</td>' +
                    '<td>' + activity.description + '</td>' +
                    '</tr>';
                $tbody.append(row);
            });
        },

        exportAnalytics: function() {
            var period = $('#analytics-period').val();
            var data = {
                action: 'wplm_export_analytics',
                nonce: wplm_analytics.nonce,
                period: period
            };
            
            if (period === 'custom') {
                data.start_date = $('#start-date').val();
                data.end_date = $('#end-date').val();
                
                if (!data.start_date || !data.end_date) {
                    alert('Please select both start and end dates for custom range.');
                    return;
                }
            }
            
            // Create form for download
            var form = document.createElement('form');
            form.method = 'post';
            form.action = wplm_analytics.ajax_url;
            
            Object.keys(data).forEach(function(key) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = data[key];
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        },

        showError: function(message) {
            // You could implement a proper notification system here
            alert('Error: ' + message);
        },

        formatNumber: function(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toString();
        },

        formatCurrency: function(amount) {
            if (typeof amount === 'number') {
                return '$' + amount.toFixed(2);
            }
            return amount || '$0.00';
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('.wplm-analytics').length) {
            WPLM_Analytics.init();
        }
    });

})(jQuery);
