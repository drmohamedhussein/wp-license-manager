jQuery(document).ready(function($) {
    $(document).on('click', '.wplm-notification .notice-dismiss', function() {
        var $notification = $(this).closest('.wplm-notification');
        var notificationId = $notification.data('notification-id');

        if (notificationId) {
            $.ajax({
                url: wplm_notification_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wplm_dismiss_notification',
                    nonce: wplm_notification_ajax.nonce,
                    notification_id: notificationId
                },
                success: function(response) {
                    if (response.success) {
                        $notification.fadeTo(100, 0, function() {
                            $(this).slideUp(100, function() {
                                $(this).remove();
                            });
                        });
                    } else {
                        console.error('WPLM Notification Dismissal Error:', response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('WPLM Notification Dismissal AJAX Error:', status, error);
                }
            });
        }
    });
});
