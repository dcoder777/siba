<?php
$pageTitle = "Gallery";
require_once('includes/db_connect.php');
include('includes/header.php');
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

<?php include('includes/footer.php'); ?>
