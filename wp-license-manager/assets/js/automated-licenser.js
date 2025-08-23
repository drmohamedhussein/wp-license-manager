/**
 * Automated Licenser System JavaScript for WP License Manager
 */

(function($) {
    'use strict';

    var WPLM_Automated_Licenser_Frontend = {

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#wplm-licenser-upload-form').on('submit', this.handleUploadAndProcess.bind(this));
        },

        handleUploadAndProcess: function(e) {
            e.preventDefault();

            var $form = $(e.target);
            var $submitButton = $('#wplm-licenser-submit-button');
            var $feedbackDiv = $('#wplm-licenser-feedback');
            var $progressBar = $('#wplm-licenser-progress');
            var $resultsDiv = $('#wplm-licenser-results');
            var $detailsDiv = $('#wplm-licenser-details');
            var $downloadLinkP = $('#wplm-licenser-download-link');
            var $zipFile = $('#wplm_licenser_zip_file');
            var $itemId = $('#wplm_licenser_item_id');

            $feedbackDiv.hide().removeClass('notice-success notice-error').empty();
            $resultsDiv.hide();
            $submitButton.prop('disabled', true);
            $progressBar.show().find('.wplm-progress-fill').css('width', '0%');

            // Client-side validation
            if (!$zipFile.val()) {
                $feedbackDiv.addClass('notice-error').html('<p>' + wplm_licenser.strings.invalid_file + '</p>').show();
                $submitButton.prop('disabled', false);
                $progressBar.hide();
                return;
            }

            var fileExtension = $zipFile.val().split('.').pop().toLowerCase();
            if (fileExtension !== 'zip') {
                $feedbackDiv.addClass('notice-error').html('<p>' + wplm_licenser.strings.invalid_file + '</p>').show();
                $submitButton.prop('disabled', false);
                $progressBar.hide();
                return;
            }

            var itemIdValue = parseInt($itemId.val(), 10);
            if (isNaN(itemIdValue) || itemIdValue <= 0) {
                $feedbackDiv.addClass('notice-error').html('<p>' + wplm_licenser.strings.invalid_item_id + '</p>').show();
                $submitButton.prop('disabled', false);
                $progressBar.hide();
                return;
            }
            
            var formData = new FormData($form[0]);
            formData.append('action', 'wplm_licenser_upload_and_process');
            formData.append('nonce', wplm_licenser.nonce);

            $.ajax({
                url: wplm_licenser.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(evt) {
                        if (evt.lengthComputable) {
                            var percentComplete = (evt.loaded / evt.total) * 100;
                            $progressBar.find('.wplm-progress-fill').css('width', percentComplete + '%');
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    $submitButton.prop('disabled', false);
                    $progressBar.hide();
                    
                    if (response.success) {
                        $feedbackDiv.addClass('notice-success').html('<p>' + response.data.message + '</p>').show();
                        $detailsDiv.html('<p>' + response.data.details + '</p>');
                        $downloadLinkP.html('<a href="' + response.data.download_url + '" class="button button-primary" download>' + wplm_licenser.strings.download_licensed_file + '</a>');
                        $resultsDiv.show();
                        $form[0].reset(); // Clear the file input
                    } else {
                        $feedbackDiv.addClass('notice-error').html('<p>' + (response.data.message || wplm_licenser.strings.error) + '</p>').show();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $submitButton.prop('disabled', false);
                    $progressBar.hide();
                    $feedbackDiv.addClass('notice-error').html('<p>' + wplm_licenser.strings.error + ' (' + textStatus + ': ' + errorThrown + ')</p>').show();
                }
            });
        }
    };

    $(document).ready(function() {
        if ($('.wplm-automated-licenser').length) {
            WPLM_Automated_Licenser_Frontend.init();
        }
    });

})(jQuery);
