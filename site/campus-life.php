<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'campus-life');
$pageTitle = $cms['title'];
$data = $cms['data'];
include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Campus Life</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? ''); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? ''); ?></p>
</div>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['infra_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['infra_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['infra_text'] ?? ''); ?></p>
    </div>
    <div class="grid">
        <?php foreach (($data['infrastructure'] ?? []) as $item): ?>
            <div class="card feature-card">
                <div class="icon"><i class="fas fa-<?php echo htmlspecialchars($item['icon'] ?? 'building'); ?>"></i></div>
                <h3><?php echo htmlspecialchars($item['title'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($item['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="grid-2col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center;">
        <div>
            <div class="section-title" style="text-align: left; margin-bottom: 1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['hostel_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['hostel_heading'] ?? ''); ?></h2>
            </div>
            <p style="color: var(--text-light); margin-bottom: 1.25rem; line-height: 1.8;"><?php echo htmlspecialchars($data['hostel_text'] ?? ''); ?></p>
            <?php foreach (($data['hostel_points'] ?? []) as $point): ?>
                <div class="why-item">
                    <div class="why-icon"><i class="fas fa-<?php echo htmlspecialchars($point['icon'] ?? 'check'); ?>"></i></div>
                    <div><h4><?php echo htmlspecialchars($point['title'] ?? ''); ?></h4><p><?php echo htmlspecialchars($point['text'] ?? ''); ?></p></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div>
            <img src="<?php echo htmlspecialchars($data['hostel_image'] ?? ''); ?>" alt="Hostel" style="width: 100%; border-radius: 16px; box-shadow: var(--shadow-lg);">
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['student_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['student_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['student_text'] ?? ''); ?></p>
    </div>
    <div class="grid">
        <?php foreach (($data['student_life'] ?? []) as $item): ?>
            <div class="card">
                <img src="<?php echo htmlspecialchars($item['image'] ?? ''); ?>" alt="Student life" style="width:100%; height: 180px; object-fit: cover; border-radius: 8px; margin-bottom: 1rem;">
                <h3 style="color: var(--primary-color);"><?php echo htmlspecialchars($item['title'] ?? ''); ?></h3>
                <p style="color: var(--text-light); font-size: 0.88rem;"><?php echo htmlspecialchars($item['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="cta-strip">
    <div>
        <h2><?php echo htmlspecialchars($data['cta_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['cta_text'] ?? ''); ?></p>
    </div>
    <div class="cta-strip-btns">
        <a href="<?php echo htmlspecialchars($data['cta_primary_link'] ?? 'contact.php'); ?>" class="btn btn-accent btn-lg"><i class="fas fa-calendar-check"></i> <?php echo htmlspecialchars($data['cta_primary_text'] ?? 'Schedule a Tour'); ?></a>
        <a href="<?php echo htmlspecialchars($data['cta_secondary_link'] ?? 'parent/register.php'); ?>" class="btn btn-outline btn-lg"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($data['cta_secondary_text'] ?? 'Apply Now'); ?></a>
    </div>
</div>

<?php include('includes/footer.php'); ?>
