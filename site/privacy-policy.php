<?php
$pageTitle = "Privacy Policy";
include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Privacy Policy</span>
    </div>
    <h1>Privacy Policy</h1>
    <p>How we collect, use, and protect your personal information.</p>
</div>

<style>
    .pp-wrap {
        max-width: 980px;
        margin: 2.5rem auto;
        background: #fff;
        border: 1px solid #f1d9a8;
        border-radius: 6px;
        padding: 2.5rem 3rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .pp-updated {
        display: inline-block;
        background: #fdf3e1;
        color: #a86b1a;
        font-weight: 700;
        font-size: 0.95rem;
        padding: 0.5rem 1rem;
        border-left: 4px solid #a86b1a;
        margin-bottom: 1.5rem;
    }
    .pp-intro, .pp-callout {
        background: #fdf6e9;
        border-left: 4px solid #d9981f;
        padding: 1.1rem 1.4rem;
        margin-bottom: 2rem;
        color: #4a3a1f;
        font-size: 0.98rem;
        line-height: 1.65;
    }
    .pp-callout {
        background: #f6f6f6;
        border-left-color: #a86b1a;
        color: #444;
    }
    .pp-section {
        margin-bottom: 2rem;
    }
    .pp-section h2 {
        color: #8a5a1a;
        font-size: 1.55rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding-bottom: 0.6rem;
        border-bottom: 1px solid #e8d8b8;
        margin-bottom: 1rem;
    }
    .pp-section p {
        color: #333;
        font-size: 0.98rem;
        line-height: 1.7;
        margin-bottom: 0.9rem;
    }
    .pp-list {
        list-style: none;
        padding: 0;
        margin: 0.5rem 0 1rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.6rem 2rem;
    }
    .pp-list li {
        position: relative;
        padding-left: 1.4rem;
        color: #333;
        font-size: 0.97rem;
        line-height: 1.5;
    }
    .pp-list li::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0.55rem;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #d9981f;
    }
    .pp-contact-box {
        background: linear-gradient(135deg, #a86b1a 0%, #6b2d1a 100%);
        color: #fff;
        padding: 1.8rem 2rem;
        border-radius: 4px;
        margin: 1.5rem 0;
    }
    .pp-contact-box h2 {
        color: #fff;
        font-size: 1.6rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding-bottom: 0.6rem;
        border-bottom: 1px solid rgba(255,255,255,0.25);
    }
    .pp-contact-box p {
        color: #fff;
        font-size: 0.97rem;
        line-height: 1.7;
        margin-bottom: 0.4rem;
    }
    .pp-contact-box a {
        color: #fff;
        text-decoration: underline;
        font-weight: 600;
    }
    .pp-agree {
        background: #fdf3e1;
        border: 1px solid #f1d9a8;
        border-radius: 4px;
        padding: 1rem 1.4rem;
        text-align: center;
        color: #6b4a1a;
        font-weight: 600;
        font-size: 0.95rem;
        margin-top: 1.5rem;
    }
    @media (max-width: 640px) {
        .pp-wrap { padding: 1.5rem 1.2rem; }
        .pp-list { grid-template-columns: 1fr; gap: 0.5rem; }
        .pp-section h2 { font-size: 1.3rem; }
    }
</style>

<div class="pp-wrap">
    <div class="pp-updated">Last Updated: June 2026</div>

    <div class="pp-intro">
        SIBA Public School ("School", "we", "our", or "us") values your privacy and is committed to protecting the personal information you share with us.
    </div>

    <div class="pp-section">
        <h2>Information We Collect</h2>
        <p>When you submit an enquiry, admission form, or contact request through our website, social media platforms, or lead generation forms, we may collect:</p>
        <ul class="pp-list">
            <li>Parent/Guardian Name</li>
            <li>Mobile Number</li>
            <li>Email Address</li>
            <li>Child's Name</li>
            <li>Child's Age/Class Applying For</li>
            <li>Any additional information voluntarily provided by you</li>
        </ul>
    </div>

    <div class="pp-section">
        <h2>How We Use Your Information</h2>
        <p>The information collected may be used to:</p>
        <ul class="pp-list">
            <li>Respond to admission enquiries</li>
            <li>Provide information about academic programs and admissions</li>
            <li>Schedule campus visits or counselling sessions</li>
            <li>Share important updates regarding admissions and school activities</li>
            <li>Improve our services and communication</li>
        </ul>
    </div>

    <div class="pp-section">
        <h2>Information Sharing</h2>
        <p>SIBA Public School does not sell, rent, or share personal information with unrelated third parties for marketing purposes.</p>
        <p>Information may be shared only with authorized school personnel involved in admissions, administration, or communication processes.</p>
    </div>

    <div class="pp-section">
        <h2>Data Security</h2>
        <p>We take reasonable measures to protect your personal information against unauthorized access, misuse, disclosure, or loss.</p>
    </div>

    <div class="pp-section">
        <h2>Communication Consent</h2>
        <p>By submitting your information through our website, Meta Lead Forms, or other enquiry channels, you consent to being contacted by SIBA Public School via phone calls, SMS, email, or WhatsApp regarding admissions, school programs, campus visits, and related educational services.</p>
    </div>

    <div class="pp-section">
        <h2>Third-Party Platforms</h2>
        <p>Our website and advertisements may utilize third-party platforms such as Meta (Facebook &amp; Instagram) and Google for communication and marketing purposes. These platforms may collect certain information in accordance with their respective privacy policies.</p>
        <div class="pp-callout">
            This section covers use of social media advertisements, Meta Lead Forms, Google marketing tools and other third-party digital platforms used for school communication and admission-related outreach.
        </div>
    </div>

    <div class="pp-section">
        <h2>Your Rights</h2>
        <p>You may request correction, updating, or removal of your personal information by contacting us using the details provided below.</p>

        <div class="pp-contact-box">
            <h2>Contact Us</h2>
            <p><strong>SIBA Public School</strong></p>
            <p><i class="fas fa-map-marker-alt" style="margin-right:0.5rem;opacity:0.9;"></i> 123 Education Lane, City, State – 700001</p>
            <p><i class="fas fa-phone" style="margin-right:0.5rem;opacity:0.9;"></i> +91 12345 67890</p>
            <p><i class="fas fa-envelope" style="margin-right:0.5rem;opacity:0.9;"></i> info@sibaschool.com</p>
            <p><i class="fas fa-clock" style="margin-right:0.5rem;opacity:0.9;"></i> Mon–Fri: 8:00 AM – 3:00 PM</p>
            <p style="margin-top:0.6rem;">For any privacy-related concerns, please contact the school administration.</p>
        </div>
    </div>

    <div class="pp-agree">
        By using our website and submitting your information, you agree to the terms outlined in this Privacy Policy.
    </div>
</div>

<?php include('includes/footer.php'); ?>
