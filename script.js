jQuery(document).ready(function($) {
    $('#das-form').on('submit', function(e) {
        e.preventDefault();

        let route = $('#das-route').val();
        let date = $('#das-date').val();
        let $btn = $(this).find('button');
        let $result = $('#das-result');
        let $content = $result.find('.das-content');

        if(!route || !date) {
            alert('Please select both a route and a date.');
            return;
        }

        // UI Loading State
        $btn.text('Asking the Captain...').prop('disabled', true);
        $result.show();
        $content.html('Checking satellite weather data...');

        $.ajax({
            url: das_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'das_check_sailing',
                route_id: route,
                date: date,
                nonce: das_vars.nonce
            },
            success: function(response) {
                if(response.success) {
                    // Typewriter effect could be added here for extra "AI" feel
                    $content.html(response.data.analysis);
                } else {
                    $content.html('Error connecting to the weather station.');
                }
            },
            error: function() {
                $content.html('Communication error. Please try again.');
            },
            complete: function() {
                $btn.text('Analyze Conditions').prop('disabled', false);
            }
        });
    });
});
