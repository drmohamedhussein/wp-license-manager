/**
 * Bulk Operations JavaScript for WP License Manager
 */

(function($) {
    'use strict';

    var WPLM_Bulk = {
        
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initSelect2();
        },

        bindEvents: function() {
            // Bulk create form
            $('#bulk-create-form').on('submit', this.handleBulkCreate);
            
            // License management
            $('#load-licenses').on('click', this.loadLicenses);
            $('#select-all-licenses').on('click', this.selectAllLicenses);
            $('#deselect-all-licenses').on('click', this.deselectAllLicenses);
            $('#license-status-filter, #license-product-filter, #license-customer-filter, #license-expiry-from, #license-expiry-to').on('change', function() {
                // Automatically trigger load licenses when filters change
                WPLM_Bulk.loadLicenses();
            });

            
            // Bulk actions
            $('.bulk-action').on('click', this.handleBulkAction);
            $('#confirm-extend').on('click', this.confirmExtend);
            $('#confirm-set-expiry').on('click', this.confirmSetExpiry);
            $('#confirm-set-activation-limit').on('click', this.confirmSetActivationLimit);
            $('#confirm-change-product').on('click', this.confirmChangeProduct);
            $('#confirm-transfer-customer').on('click', this.confirmTransferCustomer);
            $('.wplm-bulk-action-options #cancel-action').on('click', this.cancelAction);
            
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
                // Use specific IDs for Select2 fields to get their selected value
                if (item.name === 'product_id') {
                    data[item.name] = $('#bulk_create_product_id').val();
                } else if (item.name === 'customer_id') {
                    data[item.name] = $('#bulk_create_customer_id').val();
                } else {
                    data[item.name] = item.value;
                }
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
            var customer = $('#license-customer-filter').val();
            var expiryFrom = $('#license-expiry-from').val();
            var expiryTo = $('#license-expiry-to').val();
            
            $list.html('<p>Loading licenses...</p>');
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_get_licenses_for_bulk',
                    nonce: wplm_bulk.nonce,
                    status: status,
                    product: product,
                    customer: customer,
                    expiry_from: expiryFrom,
                    expiry_to: expiryTo
                },
                success: function(response) {
                    if (response.success && response.data.licenses) {
                        var html = '';
                        response.data.licenses.forEach(function(license) {
                            html += '<div class="wplm-license-item">';
                            html += '<label>';
                            html += '<input type="checkbox" name="license_ids[]" value="' + license.id + '" />';
                            html += '<strong>' + license.key + '</strong><br>';
                            html += 'Product: ' + license.product + ' (ID: ' + license.product_id + ')<br>';
                            if (license.customer !== 'N/A') {
                                html += 'Customer: ' + license.customer + ' (Email: ' + license.customer_email + ')<br>';
                            }
                            html += 'Status: <span class="status-' + license.status + '">' + license.status + '</span><br>';
                            if (license.expiry) {
                                html += 'Expires: ' + license.expiry + '<br>';
                            }
                            html += 'Activations: ' + license.activations + ' / ' + license.activation_limit + '<br>';
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
            
            // Hide all option containers first
            $('.wplm-bulk-action-options').hide();

            switch (action) {
                case 'extend':
                    $('#extend-options').show();
                    return;
                case 'set_expiry':
                    $('#set-expiry-options').show();
                    return;
                case 'set_activation_limit':
                    $('#set-activation-limit-options').show();
                    return;
                case 'change_product':
                    $('#change-product-options').show();
                    return;
                case 'transfer_customer':
                    $('#transfer-customer-options').show();
                    return;
                case 'delete':
                    if (!confirm(wplm_bulk.strings.confirm_delete)) {
                        return;
                    }
                    break; // Proceed to performBulkAction
                default:
                    if (!confirm(wplm_bulk.strings.confirm_operation)) {
                        return;
                    }
                    break; // Proceed to performBulkAction
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
                // This should not happen if handleBulkAction correctly directs flows
                alert(wplm_bulk.strings.invalid_action_or_confirmation);
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
                alert(wplm_bulk.strings.invalid_extension_value);
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

        /**
         * Cancel any bulk action options display.
         */
        cancelAction: function() {
            $('.wplm-bulk-action-options').hide();
        },

        /**
         * Confirm setting new expiry date
         */
        confirmSetExpiry: function() {
            var selectedLicenses = $('#license-list input[type="checkbox"]:checked');
            var newExpiryDate = $('#new-expiry-date').val();

            if (selectedLicenses.length === 0) {
                alert(wplm_bulk.strings.select_licenses);
                return;
            }

            if (!newExpiryDate) {
                alert(wplm_bulk.strings.invalid_expiry_date);
                return;
            }
            
            if (!confirm(wplm_bulk.strings.confirm_operation)) {
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
                    action: 'wplm_bulk_set_expiry',
                    nonce: wplm_bulk.nonce,
                    license_ids: licenseIds,
                    new_expiry_date: newExpiryDate
                },
                success: function(response) {
                    var $result = $('#manage-result');
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        WPLM_Bulk.loadLicenses();
                        $('#set-expiry-options').hide();
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

        /**
         * Confirm setting new activation limit
         */
        confirmSetActivationLimit: function() {
            var selectedLicenses = $('#license-list input[type="checkbox"]:checked');
            var newLimit = $('#new-activation-limit-value').val();

            if (selectedLicenses.length === 0) {
                alert(wplm_bulk.strings.select_licenses);
                return;
            }

            if (newLimit < 0) {
                alert(wplm_bulk.strings.invalid_activation_limit);
                return;
            }

            if (!confirm(wplm_bulk.strings.confirm_operation)) {
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
                    action: 'wplm_bulk_set_activation_limit',
                    nonce: wplm_bulk.nonce,
                    license_ids: licenseIds,
                    new_limit: newLimit
                },
                success: function(response) {
                    var $result = $('#manage-result');
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        WPLM_Bulk.loadLicenses();
                        $('#set-activation-limit-options').hide();
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

        /**
         * Confirm changing product for licenses
         */
        confirmChangeProduct: function() {
            var selectedLicenses = $('#license-list input[type="checkbox"]:checked');
            var newProductId = $('#new-product-id').val();

            if (selectedLicenses.length === 0) {
                alert(wplm_bulk.strings.select_licenses);
                return;
            }

            if (!newProductId) {
                alert(wplm_bulk.strings.select_new_product);
                return;
            }

            if (!confirm(wplm_bulk.strings.confirm_operation)) {
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
                    action: 'wplm_bulk_change_product',
                    nonce: wplm_bulk.nonce,
                    license_ids: licenseIds,
                    new_product_id: newProductId
                },
                success: function(response) {
                    var $result = $('#manage-result');
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        WPLM_Bulk.loadLicenses();
                        $('#change-product-options').hide();
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

        /**
         * Confirm transferring licenses to a new customer
         */
        confirmTransferCustomer: function() {
            var selectedLicenses = $('#license-list input[type="checkbox"]:checked');
            var newCustomerId = $('#new-customer-id').val();

            if (selectedLicenses.length === 0) {
                alert(wplm_bulk.strings.select_licenses);
                return;
            }

            if (!newCustomerId) {
                alert(wplm_bulk.strings.select_new_customer);
                return;
            }

            if (!confirm(wplm_bulk.strings.confirm_operation)) {
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
                    action: 'wplm_bulk_transfer_customer',
                    nonce: wplm_bulk.nonce,
                    license_ids: licenseIds,
                    new_customer_id: newCustomerId
                },
                success: function(response) {
                    var $result = $('#manage-result');
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>Success!</h3><p>' + response.data.message + '</p>');
                        WPLM_Bulk.loadLicenses();
                        $('#transfer-customer-options').hide();
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

        /**
         * Initialize Select2 dropdowns
         */
        initSelect2: function() {
            if ($.fn.select2) {
                // Bulk Create Product Select
                $('#bulk_create_product_id, #license-product-filter, #new-product-id').select2({
                    placeholder: wplm_admin.strings.select_product_for_bulk_create,
                    allowClear: true,
                    ajax: {
                        url: wplm_bulk.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'wplm_search_products',
                                nonce: wplm_bulk.nonce,
                                search: params.term,
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

                // Bulk Create Customer Select
                $('#bulk_create_customer_id, #license-customer-filter, #new-customer-id').select2({
                    placeholder: wplm_admin.strings.select_customer_for_bulk_create,
                    allowClear: true,
                    ajax: {
                        url: wplm_bulk.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'wplm_search_customers',
                                nonce: wplm_bulk.nonce,
                                search: params.term,
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
            }
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
                    filters: {
                        license_status: $('#bulk-update-status-filter').val(),
                        product_id: $('#bulk-update-product-filter').val(),
                    },
                    update_data: {
                        new_status: $('#bulk-update-new-status').val(),
                        new_activation_limit: $('#bulk-update-new-activation-limit').val(),
                        extend_expiry_days: $('#bulk-update-extend-expiry').val(),
                    }
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '<h3>' + wplm_bulk.strings.preview_changes + '</h3>';
                        html += '<p><strong>' + wplm_bulk.strings.licenses_to_be_updated + ':</strong> ' + data.licenses.length + '</p>';
                        
                        if (data.licenses.length > 0) {
                            html += '<p>' + wplm_bulk.strings.changes_to_be_applied + ':</p><ul>';
                            var updateData = {};
                            // Collect update data from form fields to display in preview
                            if ($('#bulk-update-new-status').val()) {
                                updateData.new_status = $('#bulk-update-new-status').val();
                                html += '<li>' + wplm_bulk.strings.status_change_to + ': <strong>' + updateData.new_status + '</strong></li>';
                            }
                            if ($('#bulk-update-new-activation-limit').val()) {
                                updateData.new_activation_limit = $('#bulk-update-new-activation-limit').val();
                                html += '<li>' + wplm_bulk.strings.activation_limit_change_to + ': <strong>' + updateData.new_activation_limit + '</strong></li>';
                            }
                            if ($('#bulk-update-extend-expiry').val()) {
                                updateData.extend_expiry_days = $('#bulk-update-extend-expiry').val();
                                html += '<li>' + wplm_bulk.strings.extend_expiry_by + ': <strong>' + updateData.extend_expiry_days + ' days</strong></li>';
                            }
                            html += '</ul>';

                            html += '<h4>' + wplm_bulk.strings.affected_licenses + ':</h4>';
                            html += '<div class="wplm-licenses-preview-list">';
                            data.licenses.forEach(function(license) {
                                html += '<p><strong>' + license.license_key + '</strong> (Product: ' + license.product + ', Status: ' + license.license_status + ')</p>';
                            });
                            html += '</div>';
                            
                            $button.prop('disabled', false);
                        } else {
                            html += '<p>' + wplm_bulk.strings.no_licenses_match_criteria + '</p>';
                            $button.prop('disabled', true);
                        }
                        
                        $preview.html(html);
                    } else {
                        $preview.html('<p class="error">' + (response.data.message || wplm_bulk.strings.error_loading_preview) + '</p>');
                    }
                },
                error: function() {
                    $preview.html('<p class="error">' + wplm_bulk.strings.error_loading_preview + '</p>');
                }
            });
        },

        handleBulkUpdate: function(e) {
            e.preventDefault();
            
            if (!confirm(wplm_bulk.strings.confirm_apply_updates)) {
                return;
            }
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $result = $('#update-result');
            
            $button.prop('disabled', true).text(wplm_bulk.strings.updating_text);
            
            // Collect filters and update data directly from the form
            var filters = {
                license_status: $('#bulk-update-status-filter').val(),
                product_id: $('#bulk-update-product-filter').val(),
            };
            var update_data = {
                new_status: $('#bulk-update-new-status').val(),
                new_activation_limit: $('#bulk-update-new-activation-limit').val(),
                extend_expiry_days: $('#bulk-update-extend-expiry').val(),
            };
            
            $.ajax({
                url: wplm_bulk.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_bulk_update_licenses',
                    nonce: wplm_bulk.nonce,
                    filters: filters,
                    update_data: update_data
                },
                success: function(response) {
                    $button.prop('disabled', false).text(wplm_bulk.strings.apply_updates_text);
                    
                    if (response.success) {
                        $result.removeClass('error').addClass('success').show();
                        $result.html('<h3>' + wplm_bulk.strings.success_text + '!</h3><p>' + response.data.message + '</p>');
                        // Optionally clear filters or reload main license list
                        // WPLM_Bulk.loadLicenses();
                    } else {
                        $result.removeClass('success').addClass('error').show();
                        $result.html('<h3>' + wplm_bulk.strings.error_text + '</h3><p>' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(wplm_bulk.strings.apply_updates_text);
                    $result.removeClass('success').addClass('error').show();
                    $result.html('<h3>' + wplm_bulk.strings.error_text + '</h3><p>' + wplm_bulk.strings.error_applying_updates + '</p>');
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPLM_Bulk.init();
    });

})(jQuery);
