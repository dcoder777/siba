<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reports – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Reports</span>
                    <h1>Reports & Analytics</h1>
                    <p>Comprehensive financial and operational reports for your institution.</p>
                </div>
            </div>
        </section>

        <div style="display:flex;align-items:center;justify-content:center;min-height:50vh;">
            <div style="text-align:center;max-width:480px;">
                <div style="font-size:4rem;margin-bottom:1rem;">🚧</div>
                <h2 style="font-size:1.5rem;font-weight:700;color:#1e293b;margin-bottom:.5rem;">Coming Soon</h2>
                <p style="color:#64748b;font-size:.95rem;line-height:1.6;margin-bottom:1.5rem;">Reports module is under development. Financial reports, analytics, and export features will be available here soon.</p>
                <a href="finance-dashboard.php" style="display:inline-block;background:#2563eb;color:#fff;border:none;padding:.6rem 1.5rem;border-radius:999px;font-weight:600;font-size:.875rem;text-decoration:none;">← Back to Dashboard</a>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
