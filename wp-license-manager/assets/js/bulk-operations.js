/**
 * Bulk Operations JavaScript for WP License Manager
 */

(function($) {
    'use strict';

    var WPLM_Bulk = {
        
        init: function() {
            this.bindEvents();
            this.initTabs();
        },

        bindEvents: function() {
            // Bulk create form
            $('#bulk-create-form').on('submit', this.handleBulkCreate);
            
            // License management
            $('#load-licenses').on('click', this.loadLicenses);
            $('#select-all-licenses').on('click', this.selectAllLicenses);
            $('#deselect-all-licenses').on('click', this.deselectAllLicenses);
            
            // Bulk actions
            $('.bulk-action').on('click', this.handleBulkAction);
            $('#confirm-extend').on('click', this.confirmExtend);
            $('#cancel-extend').on('click', this.cancelExtend);
            
            // WooCommerce orders
            $('#scan-orders').on('click', this.scanWcOrders);
            $('#wc-bulk-form').on('submit', this.handleWcBulkGenerate);
            
            // Bulk update
            $('#preview-update').on('click', this.previewBulkUpdate);
            $('#bulk-update-form').on('submit', this.handleBulkUpdate);
        },

        initTabs: function() {
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.wplm-tab-content').removeClass('active');
                $(target).addClass('active');
            });
        },

        handleBulkCreate: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $progress = $('.wplm-progress-bar');
            var $result = $('#create-result');
            
            var count = parseInt($form.find('input[name="license_count"]').val());
            
            if (count > 100) {
                if (!confirm(wplm_bulk.strings.confirm_operation + '\n\n' + 
                           'Creating ' + count + ' licenses may take a while.')) {
                    return;
                }
            }
            
            $button.prop('disabled', true).text(wplm_bulk.strings.processing);
            $progress.show();
            $result.hide();
            
            var data = {
                action: 'wplm_bulk_create_licenses',
                nonce: wplm_bulk.nonce
            };
            
            // Add form data
            $form.serializeArray().forEach(function(item) {
                data[item.name] = item.value;
            });
            
            WPLM_Bulk.simulateProgress($progress, count * 100); // Simulate progress based on count
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    $progress.hide();
                    $button.prop('disabled', false).text('Create Licenses');
                    
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        
                        if (response.data.licenses && response.data.licenses.length > 0) {
                            var licensesHtml = '<h4>Created License Keys:</h4><div class="wplm-license-keys">';
                            response.data.licenses.forEach(function(license) {
                                licensesHtml += '<code>' + license.key + '</code> ';
                            });
                            licensesHtml += '</div>';
                            $result.append(licensesHtml);
                        }
                        
                        if (response.data.errors && response.data.errors.length > 0) {
                            $result.append('<h4>Errors:</h4><ul>');
                            response.data.errors.forEach(function(error) {
                                $result.append('<li>' + error + '</li>');
                            });
                            $result.append('</ul>');
                        }
                        
                        $form[0].reset();
                    } else {
                        $result.removeClass('success').addClass('error').show();
                        $result.html('<h3>Error</h3><p>' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $progress.hide();
                    $button.prop('disabled', false).text('Create Licenses');
                    $result.removeClass('success').addClass('error').show();
                    $result.html('<h3>Error</h3><p>' + wplm_bulk.strings.error + '</p>');
                }
            });
        },

        loadLicenses: function() {
            var $list = $('#license-list');
            var status = $('#license-status-filter').val();
            var product = $('#license-product-filter').val();
            
            $list.html('<p>Loading licenses...</p>');
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_get_licenses_for_bulk',
                    nonce: wplm_bulk.nonce,
                    status: status,
                    product: product
                },
                success: function(response) {
                    if (response.success && response.data.licenses) {
                        var html = '';
                        response.data.licenses.forEach(function(license) {
                            html += '<div class="wplm-license-item">';
                            html += '<label>';
                            html += '<input type="checkbox" name="license_ids[]" value="' + license.id + '" />';
                            html += '<strong>' + license.key + '</strong><br>';
                            html += 'Product: ' + license.product + '<br>';
                            html += 'Status: <span class="status-' + license.status + '">' + license.status + '</span><br>';
                            if (license.customer) {
                                html += 'Customer: ' + license.customer + '<br>';
                            }
                            if (license.expiry) {
                                html += 'Expires: ' + license.expiry;
                            }
                            html += '</label>';
                            html += '</div>';
                        });
                        
                        if (html === '') {
                            html = '<p>No licenses found matching the criteria.</p>';
                        }
                        
                        $list.html(html);
                    } else {
                        $list.html('<p>Error loading licenses.</p>');
                    }
                },
                error: function() {
                    $list.html('<p>Error loading licenses.</p>');
                }
            });
        },

        selectAllLicenses: function() {
            $('#license-list input[type="checkbox"]').prop('checked', true);
        },

        deselectAllLicenses: function() {
            $('#license-list input[type="checkbox"]').prop('checked', false);
        },

        handleBulkAction: function() {
            var action = $(this).data('action');
            var selectedLicenses = $('#license-list input[type="checkbox"]:checked');
            
            if (selectedLicenses.length === 0) {
                alert(wplm_bulk.strings.select_licenses);
                return;
            }
            
            if (action === 'extend') {
                $('#extend-options').show();
                return;
            }
            
            if (action === 'delete') {
                if (!confirm(wplm_bulk.strings.confirm_delete)) {
                    return;
                }
            } else if (!confirm(wplm_bulk.strings.confirm_operation)) {
                return;
            }
            
            WPLM_Bulk.performBulkAction(action, selectedLicenses);
        },

        performBulkAction: function(action, selectedLicenses) {
            var licenseIds = [];
            selectedLicenses.each(function() {
                licenseIds.push($(this).val());
            });
            
            var actionMap = {
                'activate': 'wplm_bulk_activate_licenses',
                'deactivate': 'wplm_bulk_deactivate_licenses',
                'delete': 'wplm_bulk_delete_licenses'
            };
            
            var ajaxAction = actionMap[action];
            if (!ajaxAction) {
                alert('Invalid action');
                return;
            }
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: ajaxAction,
                    nonce: wplm_bulk.nonce,
                    license_ids: licenseIds
                },
                success: function(response) {
                    var $result = $('#manage-result');
                    
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        
                        // Reload licenses
                        WPLM_Bulk.loadLicenses();
                    } else {
                        $result.removeClass('success').addClass('error').show();
                        $result.html('<h3>Error</h3><p>' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    var $result = $('#manage-result');
                    $result.removeClass('success').addClass('error').show();
                    $result.html('<h3>Error</h3><p>' + wplm_bulk.strings.error + '</p>');
                }
            });
        },

        confirmExtend: function() {
            var selectedLicenses = $('#license-list input[type="checkbox"]:checked');
            var extendValue = $('#extend-value').val();
            var extendUnit = $('#extend-unit').val();
            
            if (selectedLicenses.length === 0) {
                alert(wplm_bulk.strings.select_licenses);
                return;
            }
            
            if (!extendValue || extendValue < 1) {
                alert('Please enter a valid extension value.');
                return;
            }
            
            var licenseIds = [];
            selectedLicenses.each(function() {
                licenseIds.push($(this).val());
            });
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_bulk_extend_licenses',
                    nonce: wplm_bulk.nonce,
                    license_ids: licenseIds,
                    extend_value: extendValue,
                    extend_unit: extendUnit
                },
                success: function(response) {
                    var $result = $('#manage-result');
                    
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        $('#extend-options').hide();
                        
                        // Reload licenses
                        WPLM_Bulk.loadLicenses();
                    } else {
                        $result.removeClass('success').addClass('error').show();
                        $result.html('<h3>Error</h3><p>' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    var $result = $('#manage-result');
                    $result.removeClass('success').addClass('error').show();
                    $result.html('<h3>Error</h3><p>' + wplm_bulk.strings.error + '</p>');
                }
            });
        },

        cancelExtend: function() {
            $('#extend-options').hide();
        },

        simulateProgress: function($progressBar, duration) {
            var $fill = $progressBar.find('.wplm-progress-fill');
            var progress = 0;
            var interval = setInterval(function() {
                progress += Math.random() * 15;
                if (progress > 100) {
                    progress = 100;
                    clearInterval(interval);
                }
                $fill.css('width', progress + '%');
            }, duration / 20);
        },

        scanWcOrders: function() {
            var $form = $('#wc-bulk-form');
            var $results = $('#order-scan-results');
            var $button = $(this);
            
            $button.prop('disabled', true).text('Scanning...');
            $results.html('<p>Scanning WooCommerce orders...</p>');
            
            var formData = {};
            $form.serializeArray().forEach(function(item) {
                if (item.name.includes('[]')) {
                    var key = item.name.replace('[]', '');
                    if (!formData[key]) formData[key] = [];
                    formData[key].push(item.value);
                } else {
                    formData[item.name] = item.value;
                }
            });
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_scan_wc_orders',
                    nonce: wplm_bulk.nonce,
                    ...formData
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Scan Orders');
                    
                    if (response.success) {
                        var data = response.data;
                        var html = '<h3>Scan Results</h3>';
                        html += '<p><strong>Orders found:</strong> ' + data.total_orders + '</p>';
                        html += '<p><strong>Orders with licensed products:</strong> ' + data.licensed_orders + '</p>';
                        html += '<p><strong>Orders needing licenses:</strong> ' + data.orders_needing_licenses + '</p>';
                        
                        if (data.orders_needing_licenses > 0) {
                            html += '<p class="description">Click "Generate Licenses" to create licenses for these orders.</p>';
                            $form.find('button[type="submit"]').prop('disabled', false);
                        } else {
                            html += '<p>No orders need license generation.</p>';
                        }
                        
                        $results.html(html);
                    } else {
                        $results.html('<p class="error">Error: ' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Scan Orders');
                    $results.html('<p class="error">Error scanning orders.</p>');
                }
            });
        },

        handleWcBulkGenerate: function(e) {
            e.preventDefault();
            
            if (!confirm('Generate licenses for WooCommerce orders? This may take a while for large numbers of orders.')) {
                return;
            }
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $progress = $('.wplm-progress-bar');
            var $result = $('#wc-result');
            
            $button.prop('disabled', true).text('Generating...');
            $progress.show();
            $result.hide();
            
            var formData = {};
            $form.serializeArray().forEach(function(item) {
                if (item.name.includes('[]')) {
                    var key = item.name.replace('[]', '');
                    if (!formData[key]) formData[key] = [];
                    formData[key].push(item.value);
                } else {
                    formData[item.name] = item.value;
                }
            });
            
            WPLM_Bulk.simulateProgress($progress, 5000);
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_bulk_generate_from_wc_orders',
                    nonce: wplm_bulk.nonce,
                    ...formData
                },
                success: function(response) {
                    $progress.hide();
                    $button.prop('disabled', false).text('Generate Licenses');
                    
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        
                        if (response.data.details) {
                            var details = '<h4>Details:</h4><ul>';
                            Object.keys(response.data.details).forEach(function(key) {
                                details += '<li><strong>' + key + ':</strong> ' + response.data.details[key] + '</li>';
                            });
                            details += '</ul>';
                            $result.append(details);
                        }
                    } else {
                        $result.removeClass('success').addClass('error').show();
                        $result.html('<h3>Error</h3><p>' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $progress.hide();
                    $button.prop('disabled', false).text('Generate Licenses');
                    $result.removeClass('success').addClass('error').show();
                    $result.html('<h3>Error</h3><p>' + wplm_bulk.strings.error + '</p>');
                }
            });
        },

        previewBulkUpdate: function() {
            var $form = $('#bulk-update-form');
            var $preview = $('#update-preview');
            var $button = $('#bulk-update-form button[type="submit"]');
            
            $preview.html('<p>Loading preview...</p>');
            
            var formData = {};
            $form.serializeArray().forEach(function(item) {
                formData[item.name] = item.value;
            });
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_preview_bulk_update',
                    nonce: wplm_bulk.nonce,
                    ...formData
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '<h3>Preview Changes</h3>';
                        html += '<p><strong>Licenses to be updated:</strong> ' + data.count + '</p>';
                        
                        if (data.count > 0) {
                            html += '<p>The following changes will be applied:</p><ul>';
                            if (data.changes.status) {
                                html += '<li>Status: ' + data.changes.status + '</li>';
                            }
                            if (data.changes.activation_limit) {
                                html += '<li>Activation Limit: ' + data.changes.activation_limit + '</li>';
                            }
                            if (data.changes.extend_expiry) {
                                html += '<li>Extend Expiry: ' + data.changes.extend_expiry + '</li>';
                            }
                            html += '</ul>';
                            
                            $button.prop('disabled', false);
                        } else {
                            html += '<p>No licenses match the specified criteria.</p>';
                            $button.prop('disabled', true);
                        }
                        
                        $preview.html(html);
                    } else {
                        $preview.html('<p class="error">Error: ' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $preview.html('<p class="error">Error loading preview.</p>');
                }
            });
        },

        handleBulkUpdate: function(e) {
            e.preventDefault();
            
            if (!confirm('Apply bulk updates to the selected licenses?')) {
                return;
            }
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $result = $('#update-result');
            
            $button.prop('disabled', true).text('Updating...');
            
            var formData = {};
            $form.serializeArray().forEach(function(item) {
                formData[item.name] = item.value;
            });
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_bulk_update_licenses',
                    nonce: wplm_bulk.nonce,
                    ...formData
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Apply Updates');
                    
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                    } else {
                        $result.removeClass('success').addClass('error').show();
                        $result.html('<h3>Error</h3><p>' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Apply Updates');
                    $result.removeClass('success').addClass('error').show();
                    $result.html('<h3>Error</h3><p>' + wplm_bulk.strings.error + '</p>');
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPLM_Bulk.init();
    });

})(jQuery);
