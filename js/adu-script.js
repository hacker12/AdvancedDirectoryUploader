jQuery(document).ready(function($) {
    // Progress Bar Simulation
    $('#adu-upload-form').on('submit', function() {
        $('#adu-progress').show();
        var progressBarFill = $('#adu-progress-bar-fill');
        var width = 0;
        var interval = setInterval(function() {
            width += 20;
            progressBarFill.css('width', width + '%');
            if (width >= 100) {
                clearInterval(interval);
            }
        }, 300);
    });

    // Expandable Tree Navigation
    $('#adu-directory-listing').on('click', '.adu-toggle', function() {
        var toggleButton = $(this);
        var nestedList = toggleButton.parent().find('> .adu-nested');
        nestedList.toggleClass('adu-active');
        if (nestedList.hasClass('adu-active')) {
            toggleButton.text('[-]');
        } else {
            toggleButton.text('[+]');
        }
    });
});
