$(document).ready(function() {
    $('#scrapeForm').on('submit', function(e) {
        e.preventDefault();
        
        var url = $('#url').val();
        if (!url) {
            alert('يرجى إدخال رابط الموقع');
            return;
        }
        
        showProgress();
        startScrape($(this).serialize());
    });
});

function startScrape(formData) {
    $.ajax({
        url: 'api.php',
        type: 'POST',
        data: formData + '&action=start',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                trackProgress(response.session_id);
            } else {
                alert(response.message || 'حدث خطأ');
            }
        },
        error: function() {
            alert('خطأ في الاتصال بالخادم');
        }
    });
}

function trackProgress(sessionId) {
    $.ajax({
        url: 'api.php',
        type: 'POST',
        data: { action: 'progress', session_id: sessionId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.completed) {
                updateStats(response.stats);
                showComplete(response.download_url);
            }
        }
    });
}

function updateStats(stats) {
    $('#filesCount').text(stats.files || 0);
    $('#imagesCount').text(stats.images || 0);
    $('#codeCount').text(stats.code || 0);
    $('#errorsCount').text(stats.errors || 0);
    $('#progressBar').css('width', '100%');
    $('#progressText').text('100%');
}

function showProgress() {
    $('.input-section').hide();
    $('.progress-section').show();
}

function showComplete(downloadUrl) {
    $('.progress-section').hide();
    $('.result-section').show();
    if (downloadUrl) $('.btn-download').attr('href', downloadUrl);
}