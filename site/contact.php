<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'contact');
$pageTitle = $cms['title'];
$data = $cms['data'];
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
            <form>
                <div class="form-row">
                    <div class="form-group">
                        <label>Your Name *</label>
                        <input type="text" placeholder="Full name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" placeholder="10-digit mobile" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" placeholder="your@email.com">
                </div>
                <div class="form-group">
                    <label>Subject *</label>
                    <select required>
                        <option value="">Select a topic</option>
                        <?php foreach (($data['form_topics'] ?? []) as $topic): ?>
                            <option><?php echo htmlspecialchars($topic); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Message *</label>
                    <textarea placeholder="Write your message here..." rows="5" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
                <p style="font-size: 0.78rem; color: var(--text-light); margin-top: 0.75rem; text-align: center;"><?php echo htmlspecialchars($data['form_footer'] ?? ''); ?></p>
            </form>
        </div>
    </div>
</section>

<section style="background: #e5e7eb; height: 350px; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 1rem; color: var(--text-light);">
    <i class="fas fa-map-marked-alt" style="font-size: 3rem; color: var(--secondary-color);"></i>
    <p style="font-weight: 600;"><?php echo htmlspecialchars($data['map_title'] ?? 'Interactive map coming soon'); ?></p>
    <p style="font-size: 0.85rem;"><?php echo htmlspecialchars($data['map_subtitle'] ?? ''); ?></p>
</section>

<?php include('includes/footer.php'); ?>
