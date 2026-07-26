<?php
require_once('includes/db_connect.php');
require_once('includes/cms.php');

$cms = cmsGetPage($conn, 'admissions');
$pageTitle = $cms['title'];
$data = $cms['data'];
include('includes/header.php');
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Admissions</span>
    </div>
    <h1><?php echo htmlspecialchars($data['hero_title'] ?? ''); ?></h1>
    <p><?php echo htmlspecialchars($data['hero_text'] ?? ''); ?></p>
</div>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['steps_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['steps_heading'] ?? ''); ?></h2>
    </div>
    <div class="steps">
        <?php foreach (($data['steps'] ?? []) as $idx => $step): ?>
            <div class="step">
                <div class="step-num"><?php echo $idx + 1; ?></div>
                <h4><?php echo htmlspecialchars($step['title'] ?? ''); ?></h4>
                <?php if (!empty($step['text'])): ?><p><?php echo htmlspecialchars($step['text'] ?? ''); ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="text-align: center; margin-top: 2.5rem;">
        <a href="<?php echo htmlspecialchars($data['steps_cta_link'] ?? 'parent/register.php'); ?>" class="btn btn-primary btn-lg"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($data['steps_cta_text'] ?? 'Start Application'); ?></a>
    </div>
</section>

<section class="section" style="background: var(--bg-color);">
    <div class="grid">
        <div class="card">
            <h3 style="color: var(--primary-color); margin-bottom: 1.25rem;"><i class="fas fa-check-circle" style="color: var(--secondary-color);"></i> &nbsp;<?php echo htmlspecialchars($data['eligibility_heading'] ?? 'Eligibility Criteria'); ?></h3>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <?php foreach (($data['eligibility_items'] ?? []) as $item): ?>
                <div>
                    <div style="font-weight: 600; color: var(--primary-color); font-size: 0.9rem; margin-bottom: 0.2rem;"><?php echo htmlspecialchars($item['key'] ?? ''); ?></div>
                    <div style="color: var(--text-light); font-size: 0.85rem; line-height: 1.5;"><?php echo htmlspecialchars($item['value'] ?? ''); ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="card">
            <h3 style="color: var(--primary-color); margin-bottom: 1.25rem;"><i class="fas fa-file-alt" style="color: var(--secondary-color);"></i> &nbsp;<?php echo htmlspecialchars($data['fees_heading'] ?? 'Documents Required'); ?></h3>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            <?php foreach (($data['fees_items'] ?? []) as $item): ?>
                <div style="display: flex; align-items: baseline; gap: 0.5rem; font-size: 0.9rem; color: var(--text-light);">
                    <i class="fas fa-chevron-right" style="font-size: 0.6rem; color: var(--secondary-color); flex-shrink: 0;"></i>
                    <span><?php echo htmlspecialchars($item['key'] ?? ''); ?><?php if (!empty($item['value'])): ?> <span style="opacity:0.7;">(<?php echo htmlspecialchars($item['value']); ?>)</span><?php endif; ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['docs_badge'] ?? ''); ?></span>
        <h2><?php echo htmlspecialchars($data['docs_heading'] ?? ''); ?></h2>
    </div>
    <div class="grid grid-4">
        <?php foreach (($data['docs'] ?? []) as $doc): ?>
            <div class="card feature-card">
                <div class="icon"><i class="fas fa-<?php echo htmlspecialchars($doc['icon'] ?? 'file'); ?>"></i></div>
                <h3><?php echo htmlspecialchars($doc['title'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($doc['text'] ?? ''); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" style="background: var(--bg-color);" id="faq">
    <div class="section-title">
        <span class="badge"><?php echo htmlspecialchars($data['faq_badge'] ?? 'FAQ'); ?></span>
        <h2><?php echo htmlspecialchars($data['faq_heading'] ?? 'Frequently Asked Questions'); ?></h2>
    </div>
    <div style="max-width: 800px; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem;">
        <?php foreach (($data['faqs'] ?? []) as $faq): ?>
            <div class="card" style="padding: 1.5rem; cursor: pointer;" onclick="this.querySelector('.faq-answer').style.display = this.querySelector('.faq-answer').style.display == 'none' ? 'block' : 'none';">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="color: var(--primary-color); font-size: 1rem;"><?php echo htmlspecialchars($faq['question'] ?? ''); ?></h4>
                    <i class="fas fa-chevron-down" style="color: var(--secondary-color);"></i>
                </div>
                <div class="faq-answer" style="display: none; margin-top: 0.75rem; color: var(--text-light); font-size: 0.9rem; line-height: 1.7;"><?php echo htmlspecialchars($faq['answer'] ?? ''); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="cta-strip">
    <div>
        <h2><?php echo htmlspecialchars($data['cta_heading'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($data['cta_text'] ?? ''); ?></p>
    </div>
    <a href="<?php echo htmlspecialchars($data['cta_link'] ?? 'parent/register.php'); ?>" class="btn btn-accent btn-lg"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($data['cta_button'] ?? 'Apply Now'); ?></a>
</div>

<?php include('includes/footer.php'); ?>
