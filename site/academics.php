<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'academics');
$pageTitle = $cms['title'];
$data = $cms['data'];
include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Academics</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? ''); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? ''); ?></p>
</div>

<section class="section section-alt">
    <div class="grid-2col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center;">
        <div>
            <div class="section-title" style="text-align:left; margin-bottom: 1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['curriculum_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['curriculum_heading'] ?? ''); ?></h2>
            </div>
            <?php foreach (($data['curriculum_points'] ?? []) as $point): ?>
                <div class="why-item">
                    <div class="why-icon"><i class="fas fa-<?php echo htmlspecialchars($point['icon'] ?? 'book'); ?>"></i></div>
                    <div><h4><?php echo htmlspecialchars($point['title'] ?? ''); ?></h4><p><?php echo htmlspecialchars($point['text'] ?? ''); ?></p></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div>
            <img src="<?php echo htmlspecialchars($data['curriculum_image'] ?? ''); ?>" alt="Students in classroom" style="width: 100%; border-radius: 16px; box-shadow: var(--shadow-lg);">
        </div>
    </div>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['streams_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['streams_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['streams_text'] ?? ''); ?></p>
    </div>
    <div class="grid">
        <?php foreach (($data['streams'] ?? []) as $stream): ?>
            <div class="card feature-card" style="border-top: 4px solid var(--secondary-color);">
                <div class="icon" style="background: var(--secondary-color);"><i class="fas fa-<?php echo htmlspecialchars($stream['icon'] ?? 'book'); ?>"></i></div>
                <h3><?php echo htmlspecialchars($stream['title'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($stream['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['cocurricular_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['cocurricular_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['cocurricular_text'] ?? ''); ?></p>
    </div>
    <div class="grid">
        <?php foreach (($data['cocurricular'] ?? []) as $item): ?>
            <div class="card">
                <div style="font-size: 2.5rem; margin-bottom: 0.75rem;"><i class="fas fa-<?php echo htmlspecialchars($item['icon'] ?? 'star'); ?>"></i></div>
                <h3 style="color: var(--primary-color);"><?php echo htmlspecialchars($item['title'] ?? ''); ?></h3>
                <p style="color: var(--text-light);"><?php echo htmlspecialchars($item['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="cta-strip">
    <div>
        <h2><?php echo htmlspecialchars($data['cta_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['cta_text'] ?? ''); ?></p>
    </div>
    <a href="<?php echo htmlspecialchars($data['cta_link'] ?? 'parent/register.php'); ?>" class="btn btn-accent btn-lg"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($data['cta_text_button'] ?? 'Apply for Admission'); ?></a>
</div>

<?php include('includes/footer.php'); ?>
