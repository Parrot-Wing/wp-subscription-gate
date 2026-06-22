jQuery(document).ready(function($) {
    var form = $('#wpsg-register-form');
    var submitBtn = $('#initrix-submit-btn');
    var errorsDiv = $('#initrix-form-errors');

    // ---- Live email preview ----
    function updatePreview() {
        var prefix = $('#initrix-email-prefix').val();
        var domain = $('#initrix-domain').val();
        $('#initrix-email-preview').text(prefix && domain ? prefix + '@' + domain : '');
    }
    $('#initrix-email-prefix, #initrix-domain').on('input change', updatePreview);

    // ---- Password strength ----
    $('#initrix-password').on('input', function() {
        var p = $(this).val();
        var score = 0;
        if (p.length >= 8)  score++;
        if (p.length >= 12) score++;
        if (/[A-Z]/.test(p)) score++;
        if (/[a-z]/.test(p)) score++;
        if (/[0-9]/.test(p)) score++;
        if (/[^A-Za-z0-9]/.test(p)) score++;

        var labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
        var colors = ['#dc3545','#dc3545','#ffc107','#198754','#198754','#198754'];
        var idx = Math.min(score, 5);

        $('#initrix-password-strength')
            .text('Strength: ' + labels[idx])
            .css('color', colors[idx]);
    });

    // ---- Password match ----
    $('#initrix-password-confirm').on('input', function() {
        var $el = $('#initrix-password-match');
        if (!$(this).val()) { $el.text(''); return; }
        if ($(this).val() === $('#initrix-password').val()) {
            $el.text('\u2713 Passwords match').css('color', '#198754');
        } else {
            $el.text('\u2717 Passwords do not match').css('color', '#dc3545');
        }
    });

    // ---- Form submit ----
    form.on('submit', function(e) {
        e.preventDefault();
        errorsDiv.text('');
        submitBtn.prop('disabled', true).text('Connecting to Stripe...');

        $.post(wpsg_ajax.ajax_url, {
            action:          'wpsg_create_checkout',
            email_prefix:    $('#initrix-email-prefix').val().trim(),
            domain:          $('#initrix-domain').val(),
            password:        $('#initrix-password').val(),
            password_confirm:$('#initrix-password-confirm').val(),
            nonce:           wpsg_ajax.nonce
        })
        .done(function(response) {
            if (response.success && response.data.url) {
                window.location.href = response.data.url;
            } else {
                errorsDiv.text(response.data.message || 'Unknown error.');
                submitBtn.prop('disabled', false).text('Subscribe \u2014 Create Mailbox');
            }
        })
        .fail(function() {
            errorsDiv.text('Connection error. Please try again.');
            submitBtn.prop('disabled', false).text('Subscribe \u2014 Create Mailbox');
        });
    });
});
