<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'index');
$pageTitle = $cms['title'];
$data = $cms['data'];
include('includes/header.php');
?>

<section class="hero">
    <img src="assets/images/siba_banner1.jpeg" alt="SIBA Public School Banner" class="hero-banner-img">
</section>

<div class="stats-bar">
    <?php foreach (($data['stats_bar'] ?? []) as $stat): ?>
        <div class="stat-item">
            <div class="number"><?php echo htmlspecialchars($stat['number'] ?? ''); ?></div>
            <div class="label"><?php echo htmlspecialchars($stat['label'] ?? ''); ?></div>
        </div>
    <?php endforeach; ?>
</div>

<section class="section section-alt">
    <div class="grid-2col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center;">
        <div class="fade-in">
            <div class="section-title" style="text-align: left; margin-bottom: 1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['about_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['about_heading'] ?? ''); ?></h2>
                <p><?php echo htmlspecialchars($data['about_text'] ?? ''); ?></p>
            </div>
            <div style="margin-bottom: 2rem;">
                <?php foreach (($data['about_points'] ?? []) as $point): ?>
                    <div class="why-item">
                        <div class="why-icon"><i class="fas fa-<?php echo htmlspecialchars($point['icon'] ?? 'circle'); ?>"></i></div>
                        <div><h4><?php echo htmlspecialchars($point['title'] ?? ''); ?></h4><p><?php echo htmlspecialchars($point['text'] ?? ''); ?></p></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <a href="about.php" class="btn btn-primary btn-lg">Discover Our Story <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="fade-in-2">
            <img src="<?php echo htmlspecialchars($data['about_image'] ?? ''); ?>" alt="Students learning" style="width:100%; border-radius: 16px; box-shadow: var(--shadow-lg);">
        </div>
    </div>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="section-title fade-in">
        <span class="badge"><?php echo htmlspecialchars($data['offerings_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['offerings_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['offerings_text'] ?? ''); ?></p>
    </div>
    <div class="grid fade-in-2">
        <?php foreach (($data['offerings'] ?? []) as $item): ?>
            <div class="card feature-card">
                <div class="icon"><i class="fas fa-<?php echo htmlspecialchars($item['icon'] ?? 'star'); ?>"></i></div>
                <h3><?php echo htmlspecialchars($item['title'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($item['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="section-title fade-in">
        <span class="badge"><?php echo htmlspecialchars($data['admission_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['admission_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['admission_text'] ?? ''); ?></p>
    </div>
    <div class="steps fade-in-2">
        <?php foreach (($data['admission_steps'] ?? []) as $idx => $step): ?>
            <div class="step">
                <div class="step-num"><?php echo $idx + 1; ?></div>
                <h4><?php echo htmlspecialchars($step['title'] ?? ''); ?></h4>
                <p><?php echo htmlspecialchars($step['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="text-align: center; margin-top: 2.5rem;">
        <a href="<?php echo htmlspecialchars($data['admission_cta_primary_link'] ?? 'parent/register.php'); ?>" class="btn btn-primary btn-lg"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($data['admission_cta_primary_text'] ?? 'Start Application'); ?></a>
        <a href="<?php echo htmlspecialchars($data['admission_cta_secondary_link'] ?? 'admissions.php'); ?>" class="btn btn-outline-primary btn-lg" style="margin-left: 1rem;"><?php echo htmlspecialchars($data['admission_cta_secondary_text'] ?? 'Learn More'); ?></a>
    </div>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="grid-2col-split" style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 3rem; align-items: start;">
        <div>
            <div class="section-title" style="text-align: left; margin-bottom: 1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['events_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['events_heading'] ?? ''); ?></h2>
            </div>
            <div class="card" style="padding: 1.5rem;">
                <?php foreach (($data['events'] ?? []) as $event): ?>
                    <?php 
                        /* if there is  value in any array keys, skip the event */
                        if (!empty($event['day']) || !empty($event['month']) || !empty($event['title']) || !empty($event['text'])) {
                    ?>     
                    <div class="event-item">
                        <div class="event-date"><span class="day"><?php echo htmlspecialchars($event['day'] ?? ''); ?></span><span class="month"><?php echo htmlspecialchars($event['month'] ?? ''); ?></span></div>
                        <div class="event-info"><h4><?php echo htmlspecialchars($event['title'] ?? ''); ?></h4><p><?php echo htmlspecialchars($event['text'] ?? ''); ?></p></div>
                    </div>
                    <?php } ?>
                <?php endforeach; ?>
            </div>
            <a href="events.php" class="btn btn-primary" style="margin-top: 1.25rem;"><i class="fas fa-calendar"></i> View All Events</a>
        </div>
        <div>
            <div class="section-title" style="text-align: left; margin-bottom: 1.5rem;">
                <span class="badge"><?php echo htmlspecialchars($data['testimonials_badge'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($data['testimonials_heading'] ?? ''); ?></h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                <?php foreach (($data['testimonials'] ?? []) as $item): ?>
                    <div class="testimonial-card">
                        <p>"<?php echo htmlspecialchars($item['text'] ?? ''); ?>"</p>
                        <div class="author">
                            <img src="<?php echo htmlspecialchars($item['image'] ?? ''); ?>" alt="Parent">
                            <div><strong><?php echo htmlspecialchars($item['name'] ?? ''); ?></strong><span><?php echo htmlspecialchars($item['role'] ?? ''); ?></span></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<div class="cta-strip">
    <div>
        <h2><?php echo htmlspecialchars($data['final_cta_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['final_cta_text'] ?? ''); ?></p>
    </div>
    <div class="cta-strip-btns">
        <a href="<?php echo htmlspecialchars($data['final_cta_primary_link'] ?? 'parent/register.php'); ?>" class="btn btn-accent btn-lg"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($data['final_cta_primary_text'] ?? 'Apply'); ?></a>
        <a href="<?php echo htmlspecialchars($data['final_cta_secondary_link'] ?? 'contact.php'); ?>" class="btn btn-outline btn-lg"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($data['final_cta_secondary_text'] ?? 'Contact'); ?></a>
    </div>
</div>

<div class="contact-strip">
    <?php foreach (($data['contact_strip'] ?? []) as $contact): ?>
        <div class="contact-item">
            <div class="icon"><i class="fas fa-<?php echo htmlspecialchars($contact['icon'] ?? 'circle'); ?>"></i></div>
            <div><strong><?php echo htmlspecialchars($contact['label'] ?? ''); ?></strong><span><?php echo $contact['value'] ?? ''; ?></span></div>
        </div>
    <?php endforeach; ?>
</div>

<?php include('includes/footer.php'); ?>
