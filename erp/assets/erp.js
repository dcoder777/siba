document.addEventListener('DOMContentLoaded', function () {
    var inputs = document.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"]');
    inputs.forEach(function (el) {
        el.addEventListener('input', function () {
            var start = this.selectionStart, end = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(start, end);
        });
    });
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"]').forEach(function (el) {
                el.value = el.value.toUpperCase();
            });
        });
    });
});
