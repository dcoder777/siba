<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'contact');
$pageTitle = $cms['title'];
$data = $cms['data'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $phone   = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $email   = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $subject = $conn->real_escape_string(trim($_POST['subject'] ?? ''));
    $message = $conn->real_escape_string(trim($_POST['message'] ?? ''));

    if ($name === '' || $phone === '' || $subject === '' || $message === '') {
        $error = 'Please fill in all required fields.';
    } else {
        $conn->query("CREATE TABLE IF NOT EXISTS contact_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(15) NOT NULL,
            email VARCHAR(100),
            subject VARCHAR(100),
            message TEXT NOT NULL,
            status ENUM('New','Read','Replied','Closed') DEFAULT 'New',
            admin_note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $stmt = $conn->prepare("INSERT INTO contact_submissions (name, phone, email, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssss', $name, $phone, $email, $subject, $message);
        if ($stmt->execute()) {
            $success = 'Your enquiry has been submitted. We will get back to you shortly!';
        } else {
            $error = 'Something went wrong. Please try again.';
        }
        $stmt->close();
    }
}

include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Contact</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? ''); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? ''); ?></p>
</div>

<section class="section section-alt">
    <div class="grid-2col-split" style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 3rem; align-items: start;">
        <div>
            <div class="section-title" style="text-align:left; margin-bottom:1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['details_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['details_heading'] ?? ''); ?></h2>
            </div>

            <?php foreach (($data['contacts'] ?? []) as $contact): ?>
                <div class="card" style="margin-bottom: 1rem; display: flex; gap: 1rem; align-items: flex-start;">
                    <div class="why-icon"><i class="fas fa-<?php echo htmlspecialchars($contact['icon'] ?? 'circle'); ?>"></i></div>
                    <div>
                        <h4 style="margin-bottom: 0.25rem;"><?php echo htmlspecialchars($contact['title'] ?? ''); ?></h4>
                        <p style="color: var(--text-light); font-size: 0.9rem;"><?php echo nl2br(htmlspecialchars($contact['value'] ?? '')); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card" style="background: var(--primary-color); color: white; text-align: center; padding: 1.5rem;">
                <h4 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($data['portal_title'] ?? 'Parent Portal'); ?></h4>
                <p style="opacity: 0.8; font-size: 0.88rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($data['portal_text'] ?? ''); ?></p>
                <a href="<?php echo htmlspecialchars($data['portal_button_link'] ?? 'parent/login.php'); ?>" class="btn btn-accent"><i class="fas fa-sign-in-alt"></i> <?php echo htmlspecialchars($data['portal_button_text'] ?? 'Parent Login'); ?></a>
            </div>
        </div>

        <div class="form-card">
            <h3 style="color: var(--primary-color); margin-bottom: 1.5rem; font-size: 1.3rem;"><i class="fas fa-paper-plane"></i> &nbsp;<?php echo htmlspecialchars($data['form_heading'] ?? 'Send Us an Enquiry'); ?></h3>

            <?php if ($success): ?>
                <div style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;padding:1rem;border-radius:8px;margin-bottom:1rem;">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:1rem;border-radius:8px;margin-bottom:1rem;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Your Name *</label>
                        <input type="text" name="name" placeholder="Full name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" placeholder="10-digit mobile" maxlength="10" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="your@email.com">
                </div>
                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject" required>
                        <option value="">Select a topic</option>
                        <?php foreach (($data['form_topics'] ?? []) as $topic): ?>
                            <option value="<?php echo htmlspecialchars($topic); ?>"><?php echo htmlspecialchars($topic); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Message *</label>
                    <textarea name="message" placeholder="Write your message here..." rows="5" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
                <p style="font-size: 0.78rem; color: var(--text-light); margin-top: 0.75rem; text-align: center;"><?php echo htmlspecialchars($data['form_footer'] ?? ''); ?></p>
            </form>
        </div>
    </div>
</section>

<section style="padding:0; height:400px;">
    <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3691.5!2d88.5535739!3d23.5031635!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x39f923ca371e3bdd%3A0x3434513072769727!2sSiba%20Public%20School!5e0!3m2!1sen!2sin!4v1722170000000!5m2!1sen!2sin" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
</section>

<?php include('includes/footer.php'); ?>
