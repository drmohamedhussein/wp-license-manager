/**
 * Automatic Licenser JavaScript for WP License Manager
 */

(function($) {
    'use strict';

    var WPLM_Licenser = {

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Handle file upload form submission
            $('#wplm-licenser-upload-form').on('submit', this.handleUpload.bind(this));
            // Handle download button click
            $('#wplm-download-button').on('click', this.handleDownload.bind(this));
        },

        showProgress: function(message) {
            $('#wplm-upload-message').removeClass('wplm-notice-error wplm-notice-success').addClass('wplm-notice-info').text(message).show();
            $('#wplm-upload-progress').show();
            $('#wplm-upload-button').prop('disabled', true);
            $('#wplm-licenser-results').hide();
        },

        hideProgress: function() {
            $('#wplm-upload-progress').hide();
            $('#wplm-upload-button').prop('disabled', false);
        },

        showMessage: function(message, type = 'info') {
            $('#wplm-upload-message').removeClass('wplm-notice-error wplm-notice-success wplm-notice-info').addClass('wplm-notice-' + type).text(message).show();
        },

        handleUpload: function(e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var $fileInput = $('#wplm-product-zip');
            var file = $fileInput[0].files[0];

            if (!file) {
                this.showMessage(wplm_licenser.strings.select_file, 'error');
                return;
            }

            if (file.type !== 'application/zip') {
                this.showMessage(wplm_licenser.strings.invalid_file_type, 'error');
                return;
            }

            var formData = new FormData($form[0]);
            formData.append('action', 'wplm_upload_product_zip');
            formData.append('nonce', wplm_licenser.nonce);

            this.showProgress(wplm_licenser.strings.uploading);

            $.ajax({
                url: wplm_licenser.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener("progress", function(evt) {
                        if (evt.lengthComputable) {
                            var percentComplete = (evt.loaded / evt.total) * 100;
                            $('#wplm-upload-progress .wplm-progress-fill').css('width', percentComplete + '%');
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    if (response.success) {
                        WPLM_Licenser.showMessage(response.data.message, 'success');
                        WPLM_Licenser.processZip(); // Trigger the next step
                    } else {
                        WPLM_Licenser.showMessage(response.data.message || wplm_licenser.strings.upload_error, 'error');
                        WPLM_Licenser.hideProgress();
                    }
                },
                error: function() {
                    WPLM_Licenser.showMessage(wplm_licenser.strings.generic_error, 'error');
                    WPLM_Licenser.hideProgress();
                }
            });
        },

        processZip: function() {
            this.showProgress(wplm_licenser.strings.processing);
            $('#wplm-upload-progress .wplm-progress-fill').css('width', '0%'); // Reset progress

            $.ajax({
                url: wplm_licenser.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_process_product_zip',
                    nonce: wplm_licenser.nonce
                },
                success: function(response) {
                    WPLM_Licenser.hideProgress();
                    if (response.success) {
                        $('#wplm-upload-message').hide(); // Hide initial upload message
                        $('#wplm-licenser-results').show();
                        $('#wplm-download-message').text(response.data.message || wplm_licenser.strings.file_ready);
                        $('#wplm-download-button').data('download-url', response.data.download_url);
                        WPLM_Licenser.showMessage(wplm_licenser.strings.file_ready, 'success');
                    } else {
                        WPLM_Licenser.showMessage(response.data.message || wplm_licenser.strings.process_error, 'error');
                    }
                },
                error: function() {
                    WPLM_Licenser.showMessage(wplm_licenser.strings.generic_error, 'error');
                    WPLM_Licenser.hideProgress();
                }
            });
        },

        handleDownload: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var downloadUrl = $button.data('download-url');

            if (downloadUrl) {
                $button.prop('disabled', true).text(wplm_licenser.strings.downloading);
                window.location.href = downloadUrl; // Initiates download

                // Re-enable button after a short delay (browser might block popups if not direct click)
                setTimeout(function() {
                    $button.prop('disabled', false).text(wplm_licenser.strings.downloading_complete || 'Download Licensed Product');
                }, 3000);
            } else {
                this.showMessage(wplm_licenser.strings.generic_error, 'error');
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPLM_Licenser.init();
    });

})(jQuery);
