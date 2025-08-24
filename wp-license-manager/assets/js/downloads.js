/**
 * WPLM Enhanced Digital Downloads Frontend JavaScript
 * Handles cart operations, checkout process, and frontend interactions
 */

jQuery(document).ready(function($) {
    'use strict';

    // Add to cart functionality
    $(document).on('click', '.wplm-add-to-cart-btn', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var downloadId = $button.data('download-id');
        var originalText = $button.text();
        
        // Show loading state
        $button.text('Adding...').prop('disabled', true);
        
        $.ajax({
            url: wplm_downloads.ajax_url,
            type: 'POST',
            data: {
                action: 'wplm_add_to_cart',
                download_id: downloadId,
                nonce: wplm_downloads.add_to_cart_nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.text('Added!').addClass('added');
                    
                    // Show success message
                    showNotification(response.data.message, 'success');
                    
                    // Update cart count if element exists
                    updateCartCount();
                    
                    // Reset button after 2 seconds
                    setTimeout(function() {
                        $button.text(originalText).removeClass('added').prop('disabled', false);
                    }, 2000);
                } else {
                    showNotification(response.data.message, 'error');
                    $button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                showNotification('An error occurred. Please try again.', 'error');
                $button.text(originalText).prop('disabled', false);
            }
        });
    });

    // Remove from cart functionality
    $(document).on('click', '.remove-item', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var downloadId = $button.data('download-id');
        
        $.ajax({
            url: wplm_downloads.ajax_url,
            type: 'POST',
            data: {
                action: 'wplm_remove_from_cart',
                download_id: downloadId,
                nonce: wplm_downloads.remove_from_cart_nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.closest('.wplm-cart-item').fadeOut(300, function() {
                        $(this).remove();
                        updateCartTotal();
                    });
                    
                    showNotification(response.data.message, 'success');
                    updateCartCount();
                } else {
                    showNotification(response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('An error occurred. Please try again.', 'error');
            }
        });
    });

    // Checkout form submission
    $(document).on('submit', '#wplm-checkout-form', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitButton = $form.find('.wplm-place-order-btn');
        var originalText = $submitButton.text();
        
        // Show loading state
        $submitButton.text('Processing...').prop('disabled', true);
        
        var formData = $form.serialize();
        formData += '&action=wplm_process_checkout&nonce=' + wplm_downloads.process_checkout_nonce;
        
        $.ajax({
            url: wplm_downloads.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    
                    // Redirect to success page or refresh
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    showNotification(response.data.message, 'error');
                    $submitButton.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                showNotification('Checkout failed. Please try again.', 'error');
                $submitButton.text(originalText).prop('disabled', false);
            }
        });
    });

    // Price formatting
    function formatPrice(price) {
        return '$' + parseFloat(price).toFixed(2);
    }

    // Update cart count in header/navigation
    function updateCartCount() {
        $.ajax({
            url: wplm_downloads.ajax_url,
            type: 'POST',
            data: {
                action: 'wplm_get_cart_count',
                nonce: wplm_downloads.wplm_downloads_nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.wplm-cart-count').text(response.data.count);
                }
            }
        });
    }

    // Update cart total
    function updateCartTotal() {
        var total = 0;
        $('.wplm-cart-item').each(function() {
            var price = parseFloat($(this).find('.item-price').data('price')) || 0;
            total += price;
        });
        
        $('.wplm-cart-total strong').html('Total: ' + formatPrice(total));
        
        // Hide cart if empty
        if ($('.wplm-cart-item').length === 0) {
            $('.wplm-cart').html('<div class="wplm-cart-empty"><p>Your cart is empty.</p></div>');
        }
    }

    // Show notification messages
    function showNotification(message, type) {
        type = type || 'info';
        
        // Remove existing notifications
        $('.wplm-notification').remove();
        
        // Create notification element
        var notification = $('<div class="wplm-notification wplm-notification-' + type + '">')
            .html('<span class="wplm-notification-message">' + message + '</span>')
            .append('<button class="wplm-notification-close">&times;</button>');
        
        // Add to page
        $('body').prepend(notification);
        
        // Show with animation
        notification.slideDown(300);
        
        // Auto hide after 5 seconds
        setTimeout(function() {
            hideNotification(notification);
        }, 5000);
    }

    // Hide notification
    function hideNotification(notification) {
        notification.slideUp(300, function() {
            notification.remove();
        });
    }

    // Close notification manually
    $(document).on('click', '.wplm-notification-close', function() {
        hideNotification($(this).closest('.wplm-notification'));
    });

    // Download categories filter
    $(document).on('change', '.wplm-category-filter', function() {
        var category = $(this).val();
        var $downloads = $('.wplm-download-item');
        
        if (category === 'all') {
            $downloads.show();
        } else {
            $downloads.hide().filter('[data-category="' + category + '"]').show();
        }
    });

    // Search functionality
    $(document).on('input', '.wplm-search-downloads', function() {
        var searchTerm = $(this).val().toLowerCase();
        var $downloads = $('.wplm-download-item');
        
        $downloads.each(function() {
            var title = $(this).find('h3').text().toLowerCase();
            var description = $(this).find('.wplm-download-description').text().toLowerCase();
            
            if (title.indexOf(searchTerm) !== -1 || description.indexOf(searchTerm) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // Initialize cart count on page load
    updateCartCount();

    // Initialize any tooltips or popovers
    if (typeof $.fn.tooltip !== 'undefined') {
        $('[data-toggle="tooltip"]').tooltip();
    }

    // Responsive improvements
    function handleResponsive() {
        var $downloads = $('.wplm-downloads-list');
        var width = $(window).width();
        
        // Adjust download grid for mobile
        if (width < 768) {
            $downloads.addClass('wplm-mobile-view');
        } else {
            $downloads.removeClass('wplm-mobile-view');
        }
    }

    // Handle window resize
    $(window).on('resize', handleResponsive);
    handleResponsive(); // Initial call

    // Smooth scrolling for anchor links
    $(document).on('click', 'a[href^="#"]', function(e) {
        var target = $($(this).attr('href'));
        if (target.length) {
            e.preventDefault();
            $('html, body').animate({
                scrollTop: target.offset().top - 100
            }, 500);
        }
    });

    // Form validation enhancements
    $(document).on('blur', '#wplm-checkout-form input[required]', function() {
        var $field = $(this);
        var value = $field.val().trim();
        
        if (value === '') {
            $field.addClass('wplm-field-error');
        } else {
            $field.removeClass('wplm-field-error');
            
            // Email validation
            if ($field.attr('type') === 'email') {
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    $field.addClass('wplm-field-error');
                }
            }
        }
    });

    // Loading states for forms
    function setFormLoading($form, loading) {
        if (loading) {
            $form.addClass('wplm-form-loading');
            $form.find('input, button').prop('disabled', true);
        } else {
            $form.removeClass('wplm-form-loading');
            $form.find('input, button').prop('disabled', false);
        }
    }

    // Initialize any existing forms
    $('.wplm-form').each(function() {
        var $form = $(this);
        
        // Add loading overlay
        if (!$form.find('.wplm-loading-overlay').length) {
            $form.append('<div class="wplm-loading-overlay"><div class="wplm-spinner"></div></div>');
        }
    });

    console.log('WPLM Downloads initialized successfully');
});