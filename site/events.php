<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'events');
$pageTitle = $cms['title'];
$data = $cms['data'];
include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Events & News</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? ''); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? ''); ?></p>
</div>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['events_badge'] ?? 'Calendar'); ?></span>
        <h2><?php echo htmlspecialchars($data['events_heading'] ?? 'Upcoming Events'); ?></h2>
    </div>
    <?php foreach (($data['events'] ?? []) as $event): ?>
        <?php 
            // if event is not empty //
            if (!empty($event['title']) && !empty($event['text'])) { ?>
        <div class="card" style="display: grid; grid-template-columns: auto 1fr; gap: 1.75rem; align-items: center; margin-bottom: 1.25rem;">
            <div style="text-align: center; background: <?php echo htmlspecialchars($event['color'] ?? '#4b5563'); ?>; color: white; border-radius: 14px; padding: 1rem 1.25rem; min-width: 80px;">
                <div style="font-size: 2rem; font-weight: 800; line-height: 1;"><?php echo htmlspecialchars($event['day'] ?? ''); ?></div>
                <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.9;"><?php echo htmlspecialchars($event['month'] ?? ''); ?></div>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.4rem;">
                    <i class="fas fa-<?php echo htmlspecialchars($event['icon'] ?? 'calendar'); ?>" style="color: <?php echo htmlspecialchars($event['color'] ?? '#4b5563'); ?>;"></i>
                    <h3 style="color: var(--primary-color); font-size: 1.05rem;"><?php echo htmlspecialchars($event['title'] ?? ''); ?></h3>
                </div>
                <p style="color: var(--text-light); font-size: 0.9rem;"><?php echo htmlspecialchars($event['text'] ?? ''); ?></p>
            </div>
        </div>
        <?php } ?>
    <?php endforeach; ?>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['news_badge'] ?? 'News'); ?></span>
        <h2><?php echo htmlspecialchars($data['news_heading'] ?? 'Latest from SIBA'); ?></h2>
    </div>
    <div class="grid">
        <?php foreach (($data['news'] ?? []) as $item): ?>
            <div class="card">
                <img src="<?php echo htmlspecialchars($item['image'] ?? ''); ?>" alt="News" style="width:100%; height:180px; object-fit:cover; border-radius:8px; margin-bottom:1rem;">
                <span class="badge badge-review" style="font-size:0.72rem; margin-bottom:0.5rem;"><?php echo htmlspecialchars($item['category'] ?? 'Update'); ?></span>
                <h3 style="color: var(--primary-color); margin-bottom:0.5rem;"><?php echo htmlspecialchars($item['title'] ?? ''); ?></h3>
                <p style="color: var(--text-light); font-size:0.88rem;"><?php echo htmlspecialchars($item['text'] ?? ''); ?></p>
                <p style="font-size: 0.78rem; color: var(--text-light); margin-top: 0.75rem;"><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($item['date'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php include('includes/footer.php'); ?>
