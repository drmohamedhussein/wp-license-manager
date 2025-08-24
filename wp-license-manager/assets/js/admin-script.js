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
        },

        bindEvents: function() {
            // License key generation
            $(document).on('click', '#wplm-generate-key', this.generateLicenseKey);
            
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
            
            // Subscription filters and actions
            $(document).on('click', '#wplm-filter-subscriptions', this.filterSubscriptions);
            $(document).on('keypress', '#wplm-subscription-search', function(e) {
                if (e.which === 13) {
                    WPLM_Admin.filterSubscriptions();
                }
            });

            // Form validation
            $(document).on('submit', '.wplm-form', this.validateForm);
            
            // Dynamic form elements
            $(document).on('change', '#wplm_duration_type', this.toggleDurationValue);
            $(document).on('change', '#_wplm_wc_is_licensed_product', this.toggleLicenseFields);
        },

        generateLicenseKey: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var postId = $button.data('post-id') || wplm_admin_vars.generate_key_post_id || 0;
            // For new posts, use the create license nonce
            // For existing posts, use the pre-generated post edit nonce
            var nonce = postId === 0 ? wplm_admin_vars.create_license_nonce : 
                       (wplm_admin_vars.post_edit_nonce || wplm_admin_vars.create_license_nonce);
            
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
                    if (response.success) {
                        $('#wplm-generated-key').text(response.data.key);
                        WPLM_Admin.showNotification('License key generated successfully!', 'success');
                        
                        // If this was a new post, redirect to edit page
                        if (postId === 0 && response.data.post_id) {
                            window.location.href = wplm_admin_vars.edit_post_url + '&post=' + response.data.post_id + '&action=edit';
                        }
                    } else {
                        WPLM_Admin.showNotification(response.data.message || 'Failed to generate license key', 'error');
                    }
                },
                error: function() {
                    WPLM_Admin.showNotification('An error occurred while generating the license key', 'error');
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
            $('.wplm-activity-log').html('<div class="wplm-loading"><span class="wplm-spinner"></span> ' + wplm_admin_vars.filtering_text + '</div>');
            
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
                        $('.wplm-activity-log').html('<p class="wplm-error-message">' + (response.data.message || wplm_admin_vars.filter_failed_text) + '</p>');
                        WPLM_Admin.showNotification(response.data.message || wplm_admin_vars.filter_failed_text, 'error');
                    }
                },
                error: function() {
                    $('.wplm-activity-log').html('<p class="wplm-error-message">' + wplm_admin_vars.filter_error_text + '</p>');
                    WPLM_Admin.showNotification(wplm_admin_vars.filter_error_text, 'error');
                }
            });
        },

        clearActivityLog: function() {
            if (!confirm(wplm_admin_vars.confirm_clear_log_text)) {
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
            
            // Add new notification to the unified notification area
            $('.wplm-admin-notices').first().prepend($notification);
            
            // Auto hide after 5 seconds
            setTimeout(function() {
                $notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        initDashboardCharts: function() {
            // Initialize charts if Chart.js is available
            if (typeof Chart !== 'undefined' && $('#wplm-license-status-chart').length) {
                this.createLicenseChart();
            }
        },

        createLicenseChart: function() {
            var ctx = document.getElementById('wplm-license-status-chart').getContext('2d');
            
            // Use localized data for the chart
            var activeLicenses = wplm_dashboard_vars.stats.active_licenses;
            var inactiveLicenses = wplm_dashboard_vars.stats.inactive_licenses;
            var expiredLicenses = wplm_dashboard_vars.stats.expired_licenses;

            var chart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [
                        wplm_admin_vars.active_text,
                        wplm_admin_vars.inactive_text,
                        wplm_admin_vars.expired_text
                    ],
                    datasets: [{
                        data: [
                            activeLicenses,
                            inactiveLicenses,
                            expiredLicenses
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#6c757d',
                            '#dc3545'
                        ],
                        borderWidth: 0
                    }]
                },
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
        },

        filterSubscriptions: function() {
            var $list = $('#wplm-subscriptions-list');
            var status = $('#wplm-subscription-status-filter').val();
            var search = $('#wplm-subscription-search').val();

            $list.html('<tr><td colspan="7" class="wplm-loading-cell"><div class="wplm-loading-spinner"><span class="wplm-spinner"></span> ' + wplm_admin_vars.loading_subscriptions_text + '</div></td></tr>');

            $.ajax({
                url: wplm_admin_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wplm_filter_subscriptions',
                    nonce: wp.create_nonce('wplm_filter_subscriptions'),
                    status: status,
                    search: search,
                },
                success: function(response) {
                    if (response.success) {
                        var html = '';
                        if (response.data.subscriptions && response.data.subscriptions.length > 0) {
                            response.data.subscriptions.forEach(function(subscription) {
                                html += `
                                    <tr>
                                        <td>${subscription.id}</td>
                                        <td><a href="${subscription.customer_edit_link}">${subscription.customer_name}</a></td>
                                        <td>${subscription.product_name}</td>
                                        <td><span class="wplm-status wplm-status--${subscription.status_slug}">${subscription.status_text}</span></td>
                                        <td>${subscription.start_date}</td>
                                        <td>${subscription.next_payment_date}</td>
                                        <td>
                                            <button type="button" class="button button-small wplm-subscription-action" data-action="view" data-subscription-id="${subscription.id}">${wplm_admin_vars.view_text}</button>
                                            ${subscription.can_cancel ? `<button type="button" class="button button-small wplm-subscription-action" data-action="cancel" data-subscription-id="${subscription.id}" data-confirm="${wplm_admin_vars.confirm_cancel_subscription_text}">${wplm_admin_vars.cancel_text}</button>` : ''}
                                            ${subscription.can_reactivate ? `<button type="button" class="button button-small wplm-subscription-action" data-action="reactivate" data-subscription-id="${subscription.id}" data-confirm="${wplm_admin_vars.confirm_reactivate_subscription_text}">${wplm_admin_vars.reactivate_text}</button>` : ''}
                                        </td>
                                    </tr>
                                `;
                            });
                        } else {
                            html = `<tr><td colspan="7" class="wplm-no-subscriptions-found">${wplm_admin_vars.no_subscriptions_found_text}</td></tr>`;
                        }
                        $list.html(html);
                        // Update pagination (needs to be implemented based on response.data.pagination if available)
                    } else {
                        $list.html('<tr><td colspan="7" class="wplm-error-cell"><p>' + (response.data.message || wplm_admin_vars.error_loading_subscriptions_text) + '</p></td></tr>');
                        WPLM_Admin.showNotification(response.data.message || wplm_admin_vars.error_loading_subscriptions_text, 'error');
                    }
                },
                error: function() {
                    $list.html('<tr><td colspan="7" class="wplm-error-cell"><p>' + wplm_admin_vars.error_loading_subscriptions_text + '</p></td></tr>');
                    WPLM_Admin.showNotification(wplm_admin_vars.error_loading_subscriptions_text, 'error');
                }
            });
        },

    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPLM_Admin.init();
    });

    // Make WPLM_Admin globally available
    window.WPLM_Admin = WPLM_Admin;

    // Dropdown toggles (moved from customers.php)
    $(document).on('click keypress', '.wplm-dropdown-toggle', function(e) {
        if (e.type === 'keypress' && e.which !== 13 && e.which !== 32) return; // Only Enter/Space for keypress
        e.preventDefault();
        e.stopPropagation();
        
        var $toggle = $(this);
        var $menu = $toggle.siblings('.wplm-dropdown-menu');

        // Toggle aria-expanded attribute
        var isExpanded = $toggle.attr('aria-expanded') === 'true';
        $toggle.attr('aria-expanded', !isExpanded);

        // Hide all other dropdowns
        $('.wplm-dropdown-menu').not($menu).hide();
        $('.wplm-dropdown-toggle').not($toggle).attr('aria-expanded', 'false');
        
        $menu.toggle();

        // If opening, focus on the first item
        if ($menu.is(':visible')) {
            $menu.find('.wplm-dropdown-item').first().focus();
        }
    });
    
    // Close dropdowns when clicking elsewhere (moved from customers.php)
    $(document).on('click', function() {
        $('.wplm-dropdown-menu').hide();
        $('.wplm-dropdown-toggle').attr('aria-expanded', 'false');
    });
    
    // Keyboard navigation for dropdowns
    $(document).on('keydown', '.wplm-dropdown-menu', function(e) {
        var $menu = $(this);
        var $items = $menu.find('.wplm-dropdown-item:visible');
        var $focusedItem = $(document.activeElement);
        var focusedIndex = $items.index($focusedItem);

        switch (e.which) {
            case 38: // Up arrow
                e.preventDefault();
                var newIndex = focusedIndex - 1;
                if (newIndex < 0) newIndex = $items.length - 1; // Loop to last
                $items.eq(newIndex).focus();
                break;
            case 40: // Down arrow
                e.preventDefault();
                var newIndex = focusedIndex + 1;
                if (newIndex >= $items.length) newIndex = 0; // Loop to first
                $items.eq(newIndex).focus();
                break;
            case 27: // Escape
                e.preventDefault();
                $menu.hide();
                $menu.siblings('.wplm-dropdown-toggle').focus().attr('aria-expanded', 'false');
                break;
            case 13: // Enter
            case 32: // Space
                e.preventDefault();
                $focusedItem.click(); // Trigger click on focused item
                break;
        }
    });

    // View customer modal (moved from customers.php)
    $(document).on('click', '.wplm-view-customer', function() {
        var customerEmail = $(this).data('customer-email');
        WPLM_Admin.loadCustomerDetails(customerEmail);
        $('#wplm-customer-modal').attr('aria-hidden', 'false').show();
        $('#wplm-customer-modal').focus(); // Focus the modal itself
        WPLM_Admin.trapFocus($('#wplm-customer-modal'));
    });
    
    // Close modal (moved from customers.php)
    $(document).on('click keydown', '.wplm-modal-close, #wplm-customer-modal', function(e) {
        if (e.type === 'click' && e.target === this || (e.type === 'keydown' && e.which === 27)) {
            e.preventDefault();
            $('#wplm-customer-modal').attr('aria-hidden', 'true').hide();
            // Restore focus to the element that opened the modal (if applicable)
            // This would require storing the triggering element's focus before opening the modal.
        }
    });
    
    // Trap focus inside modal (new function)
    WPLM_Admin.trapFocus = function($modal) {
        var focusableElements = $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
        var firstFocusableElement = focusableElements.first();
        var lastFocusableElement = focusableElements.last();

        $modal.on('keydown.wplmFocusTrap', function(e) {
            if (e.which === 9) { // TAB key
                if (e.shiftKey) { // SHIFT + TAB
                    if ($(document.activeElement).is(firstFocusableElement)) {
                        lastFocusableElement.focus();
                        e.preventDefault();
                    }
                } else { // TAB
                    if ($(document.activeElement).is(lastFocusableElement)) {
                        firstFocusableElement.focus();
                        e.preventDefault();
                    }
                }
            }
        });
    };

    // Export customers (moved from customers.php)
    $(document).on('click', '#wplm-export-customers', function() {
        window.location.href = wplm_admin_vars.ajaxurl + '?action=wplm_export_customers&_wpnonce=' + wplm_admin_vars.nonce;
    });
    
    // Export individual customer data (moved from customers.php)
    $(document).on('click', '.wplm-export-customer-data', function() {
        var customerEmail = $(this).data('customer-email');
        window.location.href = wplm_admin_vars.ajaxurl + '?action=wplm_export_customer_data&customer_email=' + encodeURIComponent(customerEmail) + '&_wpnonce=' + wplm_admin_vars.nonce;
    });

    // Customer Details AJAX function (moved from customers.php)
    WPLM_Admin.loadCustomerDetails = function(customerEmail) {
        $.ajax({
            url: wplm_admin_vars.ajaxurl,
            type: 'POST',
            data: {
                action: 'wplm_get_customer_details',
                customer_email: customerEmail,
                _wpnonce: wplm_admin_vars.nonce
            },
            beforeSend: function() {
                $('#wplm-customer-modal .wplm-modal-body').html('<p>' + wplm_admin_vars.loading_text + '</p>');
            },
            success: function(response) {
                if (response.success) {
                    // Render details from JSON data
                    var customer = response.data.customer;
                    var licenses = response.data.licenses;
                    var html = WPLM_Admin.renderCustomerDetails(customer, licenses);
                    $('#wplm-customer-modal .wplm-modal-body').html(html);
                } else {
                    $('#wplm-customer-modal .wplm-modal-body').html('<p>' + (response.data.message || wplm_admin_vars.error_loading_text) + '</p>');
                }
            },
            error: function() {
                $('#wplm-customer-modal .wplm-modal-body').html('<p>' + wplm_admin_vars.error_loading_text + '</p>');
            }
        });
    };

    // Function to render customer details from JSON (new function)
    WPLM_Admin.renderCustomerDetails = function(customer, licenses) {
        var html = `
            <div class="wplm-customer-detail-header">
                <div class="wplm-customer-avatar">${customer.avatar}</div>
                <div class="wplm-customer-info">
                    <h3>${customer.name}</h3>
                    <p><a href="mailto:${customer.email}">${customer.email}</a></p>
                    ${customer.wc_profile_link ? `<p><a href="${customer.wc_profile_link}" target="_blank"><span class="dashicons dashicons-admin-users"></span> ${wplm_admin_vars.wc_customer_text}</a></p>` : ''}
                </div>
            </div>
            <div class="wplm-customer-stats-grid">
                <div class="wplm-stat-card">
                    <h4>${wplm_admin_vars.total_licenses_text}</h4>
                    <p>${customer.total_licenses}</p>
                </div>
                <div class="wplm-stat-card">
                    <h4>${wplm_admin_vars.active_licenses_text}</h4>
                    <p>${customer.active_licenses}</p>
                </div>
                <div class="wplm-stat-card">
                    <h4>${wplm_admin_vars.total_value_text}</h4>
                    <p>${customer.total_value}</p>
                </div>
                <div class="wplm-stat-card">
                    <h4>${wplm_admin_vars.registered_on_text}</h4>
                    <p>${customer.registered_on}</p>
                </div>
            </div>
            <div class="wplm-customer-licenses">
                <h4>${wplm_admin_vars.licenses_text}</h4>
                ${licenses.length > 0 ? `
                    <table class="wplm-customer-licenses-table">
                        <thead>
                            <tr>
                                <th>${wplm_admin_vars.license_key_text}</th>
                                <th>${wplm_admin_vars.product_text}</th>
                                <th>${wplm_admin_vars.status_text}</th>
                                <th>${wplm_admin_vars.expiry_text}</th>
                                <th>${wplm_admin_vars.activations_text}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${licenses.map(license => `
                                <tr>
                                    <td>${license.license_key_short}</td>
                                    <td>${license.product_name}</td>
                                    <td><span class="wplm-status wplm-status--${license.status}">${license.status_text}</span></td>
                                    <td>${license.expiry_date}</td>
                                    <td>${license.activation_count}/${license.activation_limit}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                ` : `<p>${wplm_admin_vars.no_licenses_text}</p>`}
            </div>
        `;
        return html;
    };

})(jQuery);
