$(document).ready(function () {

    // ============ Mobile Menu Toggle ============
    $('.mobile-menu-toggle').on('click', function () {
        $('.nav-links').toggleClass('active');
        $(this).find('i').toggleClass('fa-bars fa-times');
    });
    
    // Close mobile menu when clicking a link
    $('.nav-links li a').on('click', function () {
        if ($(window).width() <= 968) {
            $('.nav-links').removeClass('active');
            $('.mobile-menu-toggle i').removeClass('fa-times').addClass('fa-bars');
        }
    });
    
    // Close mobile menu when clicking outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('nav').length && $('.nav-links').hasClass('active')) {
            $('.nav-links').removeClass('active');
            $('.mobile-menu-toggle i').removeClass('fa-times').addClass('fa-bars');
        }
    });

    // ============ Scroll-based fade in for public pages ============
    function checkFade() {
        $('.fade-in-2, .fade-in-3').each(function () {
            var top = $(this).offset().top;
            var windowBottom = $(window).scrollTop() + $(window).height();
            if (top < windowBottom - 60) {
                $(this).css({ opacity: 1 });
            }
        });
    }
    $(window).on('scroll', checkFade);
    checkFade();

    // ============ Active nav highlight ============
    var current = window.location.pathname.split('/').pop();
    $('.nav-links li a').each(function () {
        if ($(this).attr('href') && $(this).attr('href').endsWith(current)) {
            $(this).addClass('active');
        }
    });

    // ============ Terms Modal (apply.php) ============
    $('#showTermsBtn').on('click', function (e) {
        e.preventDefault();
        $('#termsModal').addClass('active');
    });
    $('#acceptTerms').on('click', function () {
        $('#termsCheck').prop('checked', true);
        $('#termsModal').removeClass('active');
    });
    $('#declineTerms, #closeTerms').on('click', function () {
        $('#termsCheck').prop('checked', false);
        $('#termsModal').removeClass('active');
    });
    // Close modal by clicking overlay
    $('#termsModal').on('click', function (e) {
        if ($(e.target).is('#termsModal')) {
            $(this).removeClass('active');
        }
    });

    // ============ Photo Preview (apply.php) ============
    $('#photoInput').on('change', function () {
        if (this.files && this.files[0]) {
            var reader = new FileReader();
            reader.onload = function (e) {
                $('#previewImg').attr('src', e.target.result);
                $('#photoPreview').slideDown(200);
            };
            reader.readAsDataURL(this.files[0]);
        }
    });

    // ============ Form validation before submit (apply.php) ============
    $('#applyForm').on('submit', function (e) {
        var termsChecked = $('#termsCheck').is(':checked');
        if (!termsChecked) {
            e.preventDefault();
            $('#termsModal').addClass('active');
            $('html, body').animate({ scrollTop: $('#applyForm').offset().top - 100 }, 300);
        }
    });

    // ============ Card hover pulse (public pages) ============
    $('.feature-card').on('mouseenter', function () {
        $(this).find('.icon').css('transform', 'scale(1.1) rotate(-3deg)');
    }).on('mouseleave', function () {
        $(this).find('.icon').css('transform', '');
    });

});
