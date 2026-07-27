<?php
$pageTitle = "Gallery";
require_once('includes/db_connect.php');
include('includes/header.php');

$galleryImages = [];
$galleryVideos = [];
try {
    $stmt = $pdo->query("SELECT title, description, image_file, youtube_url, category FROM gallery WHERE is_active = 1 ORDER BY sort_order ASC");
    $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allItems as $item) {
        if (!empty($item['youtube_url'])) {
            $galleryVideos[] = $item;
        } else {
            $galleryImages[] = $item;
        }
    }
} catch (Throwable $e) {
    // table might not exist yet
}
?>

<div class="page-hero">
    <div class="breadcrumb">
        <a href="<?php echo SITE_URL; ?>"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Gallery</span>
    </div>
    <h1>Gallery</h1>
    <p>Watch our school video and explore glimpses of SIBA Public School.</p>
</div>

<section class="section" style="background: var(--bg-color);">
    <div class="section-title">
        <span class="badge">School Video</span>
        <h2>Welcome to SIBA Public School</h2>
    </div>
    <div style="max-width: 800px; margin: 0 auto;">
        <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:16px;box-shadow:var(--shadow-lg);">
            <iframe style="position:absolute;top:0;left:0;width:100%;height:100%;" src="https://www.youtube.com/embed/fzG7wr2H-NU" title="SIBA Public School" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        </div>
    </div>
</section>

<?php if (!empty($galleryVideos)): ?>
<section class="section section-alt">
    <div class="section-title">
        <span class="badge">Videos</span>
        <h2>Watch Our School Videos</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.5rem;max-width:1200px;margin:0 auto;">
        <?php foreach ($galleryVideos as $vid): ?>
            <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:var(--shadow);">
                <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;">
                    <iframe style="position:absolute;top:0;left:0;width:100%;height:100%;" src="<?= e($vid['youtube_url']) ?>" title="<?= e($vid['title']) ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
                <div style="padding:1rem;">
                    <h3 style="margin:0 0 .3rem;font-size:1rem;"><?= e($vid['title']) ?></h3>
                    <?php if ($vid['category']): ?>
                        <span style="display:inline-block;padding:.15rem .5rem;background:#fdecea;color:#dc2626;border-radius:4px;font-size:.75rem;font-weight:600;margin-bottom:.4rem;"><?= e($vid['category']) ?></span>
                    <?php endif; ?>
                    <?php if ($vid['description']): ?>
                        <p style="margin:0;color:#64748b;font-size:.85rem;"><?= e($vid['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($galleryImages)): ?>
<section class="section" style="background: var(--bg-color);">
    <div class="section-title">
        <span class="badge">Photo Gallery</span>
        <h2>School Life in Pictures</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.25rem;max-width:1200px;margin:0 auto;">
        <?php foreach ($galleryImages as $img): ?>
            <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:var(--shadow);transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform='none'">
                <img src="<?= SITE_URL ?>/uploads/gallery/<?= rawurlencode($img['image_file']) ?>" alt="<?= e($img['title']) ?>" style="width:100%;height:220px;object-fit:cover;display:block;">
                <div style="padding:1rem;">
                    <h3 style="margin:0 0 .3rem;font-size:1rem;"><?= e($img['title']) ?></h3>
                    <?php if ($img['category']): ?>
                        <span style="display:inline-block;padding:.15rem .5rem;background:#eaf4fb;color:#2563eb;border-radius:4px;font-size:.75rem;font-weight:600;margin-bottom:.4rem;"><?= e($img['category']) ?></span>
                    <?php endif; ?>
                    <?php if ($img['description']): ?>
                        <p style="margin:0;color:#64748b;font-size:.85rem;"><?= e($img['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
