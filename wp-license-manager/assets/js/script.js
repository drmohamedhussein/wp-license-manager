/*
 * WP License Manager Custom JavaScript
 */

jQuery(document).ready(function($) {

    // --- API Key Tab Logic ---
    const apiKeyInput = $('#wplm_current_api_key');
    // We assume the noticeDiv will be a common element on the page.
    // If not, it needs to be created or its selector adjusted.
    let noticeDiv = $('#wplm-api-key-notice'); 

    function show_notice(type, message) {
        if (noticeDiv.length === 0) {
            // If notice div doesn't exist, create it dynamically (for API key tab rendering)
            $('<div id="wplm-api-key-notice" class="notice" style="display:none;"></div>').insertAfter($('.wplm-settings-page h1'));
            noticeDiv = $('#wplm-api-key-notice');
        }
        noticeDiv.attr('class', 'notice notice-' + type + ' is-dismissible').html('<p>' + message + '</p>').show();
        $('html, body').animate({ scrollTop: noticeDiv.offset().top - 50 }, 500); // Scroll to notice
    }

    $('#wplm-copy-api-key').on('click', function() {
        apiKeyInput.select();
        document.execCommand('copy');
        show_notice('success', 'API Key copied to clipboard!');
    });

    $('#wplm-generate-new-api-key').on('click', function() {
        if (!confirm('Are you sure you want to generate a new API key? The old one will become invalid.')) {
            return;
        }

        const spinner = $(this).next('.spinner');
        spinner.addClass('is-active');
        noticeDiv.hide();

        $.post(wplm_admin_vars.ajaxurl, { action: 'wplm_generate_api_key', _wpnonce: wplm_admin_vars.generate_api_key_nonce })
            .done(function(resp) {
                if (resp.success) {
                    apiKeyInput.val(resp.data.key);
                    show_notice('success', 'New API Key generated successfully!');
                } else {
                    show_notice('error', resp.data.message || 'Failed to generate API Key.');
                }
            })
            .fail(function() {
                show_notice('error', 'An unexpected error occurred.');
            })
            .always(function() {
                spinner.removeClass('is-active');
            });
    });

    // --- Export Button Logic ---
    $('#wplm-export-button').on('click', function(e) {
        e.preventDefault();
        const exportType = $('input[name="wplm_export_type"]:checked').val();
        const exportUrl = wplm_admin_vars.ajaxurl + '?action=wplm_export_licenses&_wpnonce=' + wplm_admin_vars.export_licenses_nonce + '&export_type=' + exportType;
        window.location.href = exportUrl;
    });

    // --- License Key Generation Logic (for License edit page) ---
    $('#wplm-generate-key').on('click', function(e){
        e.preventDefault();
        
        let postId = wplm_admin_vars.generate_key_post_id;
        let nonceValue;

        // Ensure postId is a number for strict comparison
        postId = parseInt(postId, 10);

        // Check if we are on a "new post" screen (postId will be 0)
        if (postId === 0) {
            nonceValue = wplm_admin_vars.wplm_create_license_nonce; // Use generic nonce for new posts
        } else {
            nonceValue = wplm_admin_vars.generate_key_nonce_prefix + postId; // Use post-specific nonce for existing posts
        }
        
        // console.log('WPLM Debug JS: wplm_admin_vars.wplm_create_license_nonce:', wplm_admin_vars.wplm_create_license_nonce);
        console.log('WPLM Debug JS: nonceValue before AJAX data:', nonceValue);

        var data = {
            action: 'wplm_generate_key',
            post_id: postId,
            nonce: nonceValue,
            product_id: $('#wplm_product_id').val(),
            customer_email: $('#wplm_customer_email').val(),
            expiry_date: $('#wplm_expiry_date').val(),
            activation_limit: $('#wplm_activation_limit').val()
        };
        $('#wplm-generated-key').text('Generating...');
        console.log('Sending AJAX request to:', wplm_admin_vars.ajaxurl);
        console.log('Data being sent:', data);
        $.post(wplm_admin_vars.ajaxurl, data, function(resp){
            if (resp.success) {
                $('#wplm-generated-key').text(resp.data.key);
                $('#title').val(resp.data.key);
                
                // If a new post was created, redirect to its edit screen
                if (postId === 0 && resp.data.post_id) {
                    // Prevent browser warning by indicating form is saved
                    if (typeof window.wp !== 'undefined' && window.wp.data) {
                        window.wp.data.dispatch('core/editor').savePost();
                    }
                    // Use setTimeout to avoid any unsaved changes warning
                    setTimeout(function() {
                        window.location.href = `post.php?post=${resp.data.post_id}&action=edit`;
                    }, 100);
                }
                // You might want to display a temporary notice here if on an edit screen
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : (resp.data || 'unknown');
                $('#wplm-generated-key').text('Error: ' + msg);
                // Display error using show_notice if available in this context
            }
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX Request Failed:', textStatus, errorThrown, jqXHR);
            let errorMessage = 'An unexpected error occurred.';
            if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                errorMessage = jqXHR.responseJSON.message;
            } else if (jqXHR.responseText) {
                errorMessage = 'Server Response: ' + jqXHR.responseText.substring(0, 200) + '...'; // Show first 200 chars
            } else if (errorThrown) {
                errorMessage = 'Error: ' + errorThrown;
            }
            $('#wplm-generated-key').text('Error: ' + errorMessage);
        });
    });

    // --- WooCommerce Product Meta Box Logic ---
    function toggleWPLMProductRow() {
        if ($('#_wplm_wc_is_licensed_product').is(':checked')) {
            $('.wplm-wc-linked-product-row').show();
            $('.wplm-wc-current-version-row').show(); 
        } else {
            $('.wplm-wc-linked-product-row').hide();
            $('.wplm-wc-current-version-row').hide(); 
        }
    }

    toggleWPLMProductRow();
    $('#_wplm_wc_is_licensed_product').on('change', toggleWPLMProductRow);

    function autoCheckLicensedProduct() {
        var isVirtual = $('#_virtual').is(':checked');
        var isDownloadable = $('#_downloadable').is(':checked');

        if (isVirtual && isDownloadable) {
            $('#_wplm_wc_is_licensed_product').prop('checked', true).trigger('change');
        } else if ($('#_wplm_wc_linked_wplm_product').val() === '') {
            $('#_wplm_wc_is_licensed_product').prop('checked', false).trigger('change');
        }
    }

    $('#_virtual, #_downloadable').on('change', autoCheckLicensedProduct);
    autoCheckLicensedProduct();

    // --- Customer License Deactivation Logic ---
    $('.deactivate-domain-button').on('click', function(e) {
        e.preventDefault();

        const button = $(this);
        const licenseKey = button.data('license-key');
        const domain = button.data('domain');
        const nonce = wplm_admin_vars.wplm_customer_deactivate_license_nonce; // Will need to pass this via localize_script

        if (!confirm(`Are you sure you want to deactivate ${domain} for license ${licenseKey}?`)) {
            return;
        }

        button.prop('disabled', true).text('Deactivating...');

        $.ajax({
            url: wplm_admin_vars.ajaxurl,
            type: 'POST',
            data: {
                action: 'wplm_deactivate_customer_license',
                license_key: licenseKey,
                domain: domain,
                nonce: nonce,
            },
            success: function(response) {
                if (response.success) {
                    alert('Domain deactivated successfully!');
                    // Remove the deactivated domain from the list
                    button.closest('li').remove();
                    // Potentially update activations count display
                    const activationsCell = button.closest('td').prev();
                    let [current, limit] = activationsCell.text().split(' / ').map(s => parseInt(s.trim()));
                    activationsCell.text(`${current - 1} / ${limit}`);

                } else {
                    alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error.'));
                }
            },
            error: function() {
                alert('An unexpected error occurred.');
            },
            complete: function() {
                button.prop('disabled', false).text('Deactivate');
            }
        });
    });
});
