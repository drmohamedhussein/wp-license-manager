/**
 * WP License Manager - Admin JavaScript
 */

(function($) {
    'use strict';

    var WPLM_Admin = {
        
        init: function() {
            this.bindEvents();
            this.initDashboardCharts();
            this.initTooltips();
            this.initSearchFilters();
            this.initSubscriptionActions();
            this.initCustomerActions();
            this.initActivityLogActions();

            // Debug: surface obvious setup issues
            if (typeof window.wplm_admin_vars === 'undefined') {
                console.error('WPLM: wplm_admin_vars is undefined. Script localization did not run.');
            } else {
                // Minimal key fields to verify at runtime
                if (!wplm_admin_vars.ajaxurl) {
                    console.error('WPLM: ajaxurl is missing in wplm_admin_vars.');
                }
            }
        },

        bindEvents: function() {
            // License key generation
            $(document).on('click', '#wplm-generate-key', this.generateLicenseKey);
            
            // Prevent duplicate form submission after license generation
            $(document).on('submit', '#post', this.preventDuplicateSubmission);
            
            // API key generation
            $(document).on('click', '#wplm-generate-new-api-key', this.generateApiKey);
            
            // Copy to clipboard
            $(document).on('click', '#wplm-copy-api-key', this.copyApiKey);
            
            // Subscription actions
            $(document).on('click', '.wplm-subscription-action', this.handleSubscriptionAction);
            
            // Customer search
            $(document).on('click', '#wplm-search-customers', this.searchCustomers);
            $(document).on('keypress', '#wplm-customer-search', function(e) {
                if (e.which === 13) {
                    WPLM_Admin.searchCustomers();
                }
            });
            
            // Activity log filters
            $(document).on('click', '#wplm-filter-activity', this.filterActivityLog);
            $(document).on('click', '#wplm-clear-activity', this.clearActivityLog);
            
            // Form validation
            $(document).on('submit', '.wplm-form', this.validateForm);
            
            // Dynamic form elements
            $(document).on('change', '#wplm_duration_type', this.toggleDurationValue);
            $(document).on('change', '#_wplm_wc_is_licensed_product', this.toggleLicenseFields);
        },

        preventDuplicateSubmission: function(e) {
            // Check if a license was just generated via AJAX
            if (window.wplm_license_generated && $('#post_type').val() === 'wplm_license') {
                var licenseKey = $('#title').val();
                if (licenseKey && licenseKey.length > 0) {
                    // Show warning and prevent submission
                    if (!confirm('A license has already been generated via AJAX. Submitting this form may create a duplicate. Do you want to continue?')) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
        },

        generateLicenseKey: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            if (typeof window.wplm_admin_vars === 'undefined') {
                alert('WP License Manager: Script not initialized properly. Please hard refresh (Ctrl/Cmd + Shift + R) and try again.');
                console.error('WPLM: wplm_admin_vars is undefined at click time.');
                return;
            }

            var postId = $button.data('post-id') || wplm_admin_vars.generate_key_post_id || 0;
            
            // Generate nonce dynamically using WordPress's built-in nonce generation
            var nonce = '';
            if (postId === 0) {
                // For new posts, use a simple nonce that doesn't expire
                nonce = wplm_admin_vars.create_license_nonce;
            } else {
                // For existing posts, use post-specific nonce
                nonce = wplm_admin_vars.post_edit_nonce || wplm_admin_vars.create_license_nonce;
            }
            
            // Debug traces
            console.log('WPLM: Generate clicked. postId=', postId);
            console.log('WPLM: Using nonce=', nonce);
            console.log('WPLM: AJAX URL=', wplm_admin_vars.ajaxurl);

            $button.prop('disabled', true).text(wplm_admin_vars.generating_text || 'Generating...');
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_generate_key',
                    post_id: postId,
                    nonce: nonce,
                    product_id: $('#wplm_product_id').val(),
                    customer_email: $('#wplm_customer_email').val(),
                    expiry_date: $('#wplm_expiry_date').val(),
                    activation_limit: $('#wplm_activation_limit').val()
                },
                success: function(response) {
                    console.log('WPLM: AJAX success response:', response);
                    if (response.success) {
                        $('#wplm-generated-key').text(response.data.key);
                        // Set the generated key as the title
                        $('#title').val(response.data.key);
                        WPLM_Admin.showNotification('License key generated successfully!', 'success');
                        
                        // If this was a new post, show success message and let user save manually
                        if (postId === 0 && response.data.post_id) {
                            WPLM_Admin.showNotification('License created! You can now save the post or continue editing.', 'success');
                            // Set a flag to prevent duplicate form submission
                            window.wplm_license_generated = true;
                        }
                    } else {
                        console.error('WPLM: AJAX logical error:', response);
                        WPLM_Admin.showNotification(response.data.message || 'Failed to generate license key', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('WPLM: AJAX transport error:', status, error, xhr);
                    var msg = 'An error occurred while generating the license key';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        msg = xhr.responseJSON.data.message;
                    } else if (xhr && xhr.responseText) {
                        msg = 'Server: ' + xhr.responseText.substring(0, 200);
                    }
                    WPLM_Admin.showNotification(msg, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Generate Key');
                }
            });
        },

        generateApiKey: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            $button.prop('disabled', true).text('Generating...');
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_generate_api_key',
                    _wpnonce: wplm_admin_vars.generate_api_key_nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wplm_current_api_key').val(response.data.key);
                        WPLM_Admin.showNotification('New API key generated successfully!', 'success');
                    } else {
                        WPLM_Admin.showNotification(response.data.message || 'Failed to generate API key', 'error');
                    }
                },
                error: function() {
                    WPLM_Admin.showNotification('An error occurred while generating the API key', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Generate New API Key');
                }
            });
        },

        copyApiKey: function(e) {
            e.preventDefault();
            
            var $input = $('#wplm_current_api_key');
            $input.select();
            document.execCommand('copy');
            
            WPLM_Admin.showNotification('API key copied to clipboard!', 'info');
        },

        handleSubscriptionAction: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var action = $button.data('action');
            var subscriptionId = $button.data('subscription-id');
            var confirmText = $button.data('confirm');
            
            if (confirmText && !confirm(confirmText)) {
                return;
            }
            
            $button.prop('disabled', true);
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_' + action + '_subscription',
                    subscription_id: subscriptionId,
                    nonce: wp.create_nonce('wplm_subscription_action'),
                    reason: prompt('Reason (optional):') || ''
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Admin.showNotification(response.data.message, 'success');
                        location.reload();
                    } else {
                        WPLM_Admin.showNotification(response.data.message || 'Action failed', 'error');
                    }
                },
                error: function() {
                    WPLM_Admin.showNotification('An error occurred', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        searchCustomers: function() {
            var searchTerm = $('#wplm-customer-search').val();
            
            if (searchTerm.length < 3) {
                WPLM_Admin.showNotification('Please enter at least 3 characters to search', 'warning');
                return;
            }
            
            // Show loading
            $('.wplm-customer-table').html('<div class="wplm-loading"><span class="wplm-spinner"></span> Searching...</div>');
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_search_customers',
                    search: searchTerm,
                    nonce: wp.create_nonce('wplm_search_customers')
                },
                success: function(response) {
                    if (response.success) {
                        $('.wplm-customer-table').html(response.data.html);
                    } else {
                        WPLM_Admin.showNotification(response.data.message || 'Search failed', 'error');
                    }
                },
                error: function() {
                    WPLM_Admin.showNotification('Search error occurred', 'error');
                }
            });
        },

        filterActivityLog: function() {
            var activityType = $('#wplm-activity-type').val();
            var dateFrom = $('#wplm-activity-date-from').val();
            var dateTo = $('#wplm-activity-date-to').val();
            
            // Show loading
            $('.wplm-activity-log').html('<div class="wplm-loading"><span class="wplm-spinner"></span> Filtering...</div>');
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_filter_activity_log',
                    activity_type: activityType,
                    date_from: dateFrom,
                    date_to: dateTo,
                    nonce: wp.create_nonce('wplm_filter_activity')
                },
                success: function(response) {
                    if (response.success) {
                        $('.wplm-activity-log').html(response.data.html);
                    } else {
                        WPLM_Admin.showNotification(response.data.message || 'Filter failed', 'error');
                    }
                },
                error: function() {
                    WPLM_Admin.showNotification('Filter error occurred', 'error');
                }
            });
        },

        clearActivityLog: function() {
            if (!confirm('Are you sure you want to clear the activity log? This action cannot be undone.')) {
                return;
            }
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_clear_activity_log',
                    nonce: wp.create_nonce('wplm_clear_activity')
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Admin.showNotification('Activity log cleared successfully', 'success');
                        location.reload();
                    } else {
                        WPLM_Admin.showNotification(response.data.message || 'Clear failed', 'error');
                    }
                },
                error: function() {
                    WPLM_Admin.showNotification('Clear error occurred', 'error');
                }
            });
        },

        toggleDurationValue: function() {
            var durationType = $(this).val();
            var $valueField = $('#wplm_duration_value');
            
            if (durationType === 'lifetime') {
                $valueField.hide();
            } else {
                $valueField.show();
            }
        },

        toggleLicenseFields: function() {
            var isChecked = $(this).is(':checked');
            var $rows = $('.wplm-wc-linked-product-row, .wplm-wc-current-version-row, .wplm-wc-duration-row');
            
            if (isChecked) {
                $rows.show();
            } else {
                $rows.hide();
            }
        },

        validateForm: function(e) {
            var $form = $(this);
            var isValid = true;
            var errors = [];
            
            // Check required fields
            $form.find('[required]').each(function() {
                var $field = $(this);
                if (!$field.val().trim()) {
                    isValid = false;
                    $field.addClass('error');
                    errors.push($field.attr('data-label') || $field.attr('name') + ' is required');
                } else {
                    $field.removeClass('error');
                }
            });
            
            // Check email fields
            $form.find('input[type="email"]').each(function() {
                var $field = $(this);
                if ($field.val() && !WPLM_Admin.isValidEmail($field.val())) {
                    isValid = false;
                    $field.addClass('error');
                    errors.push('Please enter a valid email address');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                WPLM_Admin.showNotification(errors.join('<br>'), 'error');
            }
            
            return isValid;
        },

        isValidEmail: function(email) {
            var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        },

        showNotification: function(message, type) {
            type = type || 'info';
            
            var $notification = $('<div class="wplm-notification wplm-notification-' + type + '">' + message + '</div>');
            
            // Remove existing notifications
            $('.wplm-notification').remove();
            
            // Add new notification
            $('.wrap').first().prepend($notification);
            
            // Auto hide after 5 seconds
            setTimeout(function() {
                $notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        initDashboardCharts: function() {
            // Initialize charts if Chart.js is available
            if (typeof Chart !== 'undefined' && $('#wplm-license-chart').length) {
                this.createLicenseChart();
            }
        },

        createLicenseChart: function() {
            var ctx = document.getElementById('wplm-license-chart').getContext('2d');
            
            // Sample data - in real implementation, this would come from the server
            var chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Licenses Created',
                        data: [12, 19, 3, 5, 2, 3],
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        },

        initTooltips: function() {
            // Initialize tooltips for elements with data-tooltip attribute
            $('[data-tooltip]').each(function() {
                var $element = $(this);
                var tooltip = $element.attr('data-tooltip');
                
                $element.on('mouseenter', function() {
                    var $tooltip = $('<div class="wplm-tooltip">' + tooltip + '</div>');
                    $('body').append($tooltip);
                    
                    var offset = $element.offset();
                    $tooltip.css({
                        top: offset.top - $tooltip.outerHeight() - 5,
                        left: offset.left + ($element.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
                    });
                });
                
                $element.on('mouseleave', function() {
                    $('.wplm-tooltip').remove();
                });
            });
        },

        initSearchFilters: function() {
            // Initialize search filters with debouncing
            var searchTimeout;
            
            $('.wplm-search-input').on('input', function() {
                clearTimeout(searchTimeout);
                var $input = $(this);
                
                searchTimeout = setTimeout(function() {
                    var searchTerm = $input.val();
                    if (searchTerm.length >= 3 || searchTerm.length === 0) {
                        WPLM_Admin.performSearch(searchTerm, $input.data('search-type'));
                    }
                }, 500);
            });
        },

        performSearch: function(term, type) {
            // Perform search based on type
            var $container = $('.wplm-search-results');
            
            if (!$container.length) {
                return;
            }
            
            $container.html('<div class="wplm-loading"><span class="wplm-spinner"></span> Searching...</div>');
            
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_search',
                    term: term,
                    type: type,
                    nonce: wp.create_nonce('wplm_search')
                },
                success: function(response) {
                    if (response.success) {
                        $container.html(response.data.html);
                    } else {
                        $container.html('<p>No results found.</p>');
                    }
                },
                error: function() {
                    $container.html('<p>Search error occurred.</p>');
                }
            });
        },

        initSubscriptionActions: function() {
            // Initialize subscription-specific actions
            $('.wplm-subscription-toggle').on('change', function() {
                var $toggle = $(this);
                var subscriptionId = $toggle.data('subscription-id');
                var newStatus = $toggle.is(':checked') ? 'active' : 'paused';
                
                $.ajax({
                    url: wplm_admin_vars.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wplm_toggle_subscription',
                        subscription_id: subscriptionId,
                        status: newStatus,
                        nonce: wp.create_nonce('wplm_subscription_action')
                    },
                    success: function(response) {
                        if (response.success) {
                            WPLM_Admin.showNotification('Subscription status updated', 'success');
                        } else {
                            $toggle.prop('checked', !$toggle.is(':checked')); // Revert on error
                            WPLM_Admin.showNotification(response.data.message || 'Update failed', 'error');
                        }
                    },
                    error: function() {
                        $toggle.prop('checked', !$toggle.is(':checked')); // Revert on error
                        WPLM_Admin.showNotification('Update error occurred', 'error');
                    }
                });
            });
        },

        initCustomerActions: function() {
            // Initialize customer-specific actions
            $('.wplm-customer-toggle').on('click', function(e) {
                e.preventDefault();
                
                var $button = $(this);
                var customerId = $button.data('customer-id');
                var action = $button.data('action');
                
                $.ajax({
                    url: wplm_admin_vars.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wplm_customer_action',
                        customer_id: customerId,
                        customer_action: action,
                        nonce: wp.create_nonce('wplm_customer_action')
                    },
                    success: function(response) {
                        if (response.success) {
                            WPLM_Admin.showNotification(response.data.message, 'success');
                            if (action === 'delete') {
                                $button.closest('tr').fadeOut();
                            }
                        } else {
                            WPLM_Admin.showNotification(response.data.message || 'Action failed', 'error');
                        }
                    },
                    error: function() {
                        WPLM_Admin.showNotification('Action error occurred', 'error');
                    }
                });
            });
        },

        initActivityLogActions: function() {
            // Real-time activity log updates
            if ($('.wplm-activity-log').length && wplm_admin_vars.enable_realtime_log) {
                setInterval(function() {
                    WPLM_Admin.refreshActivityLog();
                }, 30000); // Refresh every 30 seconds
            }
        },

        refreshActivityLog: function() {
            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_get_recent_activity',
                    nonce: wp.create_nonce('wplm_activity_log')
                },
                success: function(response) {
                    if (response.success && response.data.has_new) {
                        // Show notification about new activity
                        var newCount = response.data.new_count;
                        WPLM_Admin.showNotification(newCount + ' new activity entries', 'info');
                    }
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPLM_Admin.init();
    });

    // Make WPLM_Admin globally available
    window.WPLM_Admin = WPLM_Admin;

})(jQuery);
