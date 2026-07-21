<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'about');
$pageTitle = $cms['title'];
$data = $cms['data'];
include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>About Us</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? ''); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? ''); ?></p>
</div>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['foundation_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['foundation_heading'] ?? ''); ?></h2>
    </div>
    <div class="grid">
        <?php foreach (($data['foundation_cards'] ?? []) as $card): ?>
            <div class="card feature-card">
                <div class="icon"><i class="fas fa-<?php echo htmlspecialchars($card['icon'] ?? 'star'); ?>"></i></div>
                <h3><?php echo htmlspecialchars($card['title'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($card['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="grid-2col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center;">
        <div>
            <img src="<?php echo htmlspecialchars($data['story_image'] ?? ''); ?>" alt="School Building" style="width: 100%; border-radius: 16px; box-shadow: var(--shadow-lg);">
        </div>
        <div>
            <div class="section-title" style="text-align: left; margin-bottom: 1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['story_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['story_heading'] ?? ''); ?></h2>
            </div>
            <?php foreach (($data['story_paragraphs'] ?? []) as $p): ?>
                <p style="color: var(--text-light); margin-bottom: 1rem; line-height: 1.8;"><?php echo htmlspecialchars($p); ?></p>
            <?php endforeach; ?>
            <div class="grid grid-4" style="margin-top: 2rem;">
                <?php foreach (($data['story_stats'] ?? []) as $stat): ?>
                    <div class="card" style="text-align: center; padding: 1rem; background: var(--primary-color); color: white;">
                        <div style="font-size: 2rem; font-weight: 800; color: var(--accent-color);"><?php echo htmlspecialchars($stat['number'] ?? ''); ?></div>
                        <div style="font-size: 0.75rem; opacity: 0.8;"><?php echo htmlspecialchars($stat['label'] ?? ''); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- comment out start -->

<!-- <section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php //echo htmlspecialchars($data['leadership_badge'] ?? ''); ?></span>
        <h2><?php //echo htmlspecialchars($data['leadership_heading'] ?? ''); ?></h2>
        <p><?php //echo htmlspecialchars($data['leadership_text'] ?? ''); ?></p>
    </div>
    <div class="grid">
        <?php //foreach (($data['leaders'] ?? []) as $leader): ?>
            <div class="card" style="display: flex; gap: 1.5rem; align-items: flex-start;">
                <img src="<?php //echo htmlspecialchars($leader['image'] ?? ''); ?>" alt="Leader" style="width: 90px; height: 90px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 3px solid var(--secondary-color);">
                <div>
                    <h3 style="color: var(--primary-color); margin-bottom: 0.25rem;"><?php //echo htmlspecialchars($leader['name'] ?? ''); ?></h3>
                    <span class="badge badge-review" style="margin-bottom: 0.75rem; display: inline-block;"><?php //echo htmlspecialchars($leader['role'] ?? ''); ?></span>
                    <p style="font-size: 0.88rem; color: var(--text-light);"><?php //echo htmlspecialchars($leader['bio'] ?? ''); ?></p>
                </div>
            </div>
        <?php //endforeach; ?>
    </div>
</section> -->
<!-- comment out end -->

<div class="cta-strip">
    <div>
        <h2><?php echo htmlspecialchars($data['cta_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['cta_text'] ?? ''); ?></p>
    </div>
    <div class="cta-strip-btns">
        <a href="<?php echo htmlspecialchars($data['cta_primary_link'] ?? 'parent/register.php'); ?>" class="btn btn-accent btn-lg"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($data['cta_primary_text'] ?? 'Apply Now'); ?></a>
        <a href="<?php echo htmlspecialchars($data['cta_secondary_link'] ?? 'contact.php'); ?>" class="btn btn-outline btn-lg"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($data['cta_secondary_text'] ?? 'Contact Us'); ?></a>
    </div>
</div>

<?php include('includes/footer.php'); ?>
