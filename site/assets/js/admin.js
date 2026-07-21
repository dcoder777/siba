$(document).ready(function () {
    // Auto-dismiss success alerts
    setTimeout(function () { $('.alert-success').fadeOut(600); }, 4000);

    // Confirm before delete actions
    $('a[href*="delete="]').on('click', function (e) {
        if (!confirm('Are you sure you want to delete this record?')) e.preventDefault();
    });
});
