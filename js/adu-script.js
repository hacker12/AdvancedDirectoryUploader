jQuery(document).ready(function ($) {
    $('#adu-upload-form').on('submit', function () {
        $('#adu-progress').show();
        var progressBarFill = $('#adu-progress-bar-fill');
        var interval = setInterval(function () {
            var width = parseInt(progressBarFill.css('width')) + 10;
            progressBarFill.css('width', width + '%');
            if (width >= 100) {
                clearInterval(interval);
            }
        }, 500);
    });
});
