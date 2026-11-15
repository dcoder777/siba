<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$activePage = 'cms-content.php';

// Self-healing table check
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cms_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(100) NOT NULL UNIQUE,
        page_title VARCHAR(255) NOT NULL,
        data_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$msg = '';
$err = '';

$editSlug = trim((string) ($_GET['slug'] ?? ''));

// Handle Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $err = 'Security validation failed (CSRF).';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_page') {
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $title = trim((string) ($_POST['page_title'] ?? ''));
            $jsonStr = trim((string) ($_POST['data_json'] ?? '{}'));

            if ($slug === '' || $title === '') {
                $err = 'Page slug and title are required.';
            } else {
                // Validate JSON syntax
                $decoded = json_decode($jsonStr, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $err = 'Invalid JSON structure: ' . json_last_error_msg() . '. Please verify syntax before saving.';
                } else {
                    $formattedJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO cms_pages (slug, page_title, data_json, updated_at)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE page_title = VALUES(page_title), data_json = VALUES(data_json), updated_at = NOW()
                        ");
                        $stmt->execute([$slug, $title, $formattedJson]);
                        $msg = "Page '{$title}' saved successfully.";
                        $editSlug = $slug;
                    } catch (\PDOException $e) {
                        $err = 'Error saving page: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Fetch all pages
$allPages = $pdo->query("SELECT id, slug, page_title, updated_at FROM cms_pages ORDER BY slug ASC")->fetchAll(PDO::FETCH_ASSOC);

// If an active edit is requested, load that page
$activePageData = null;
if ($editSlug !== '') {
    $stmt = $pdo->prepare("SELECT * FROM cms_pages WHERE slug = ?");
    $stmt->execute([$editSlug]);
    $activePageData = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$activePageData && !empty($allPages)) {
    // Default to first page (e.g. 'about' or 'index')
    $editSlug = $allPages[0]['slug'];
    $stmt = $pdo->prepare("SELECT * FROM cms_pages WHERE slug = ?");
    $stmt->execute([$editSlug]);
    $activePageData = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Website CMS Editor | Communication & Content</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
</head>
<body class="erp-body">
<div class="erp-layout">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="erp-main">
        <header class="erp-header">
            <div>
                <nav class="breadcrumb-nav">
                    <span>Communication & Content</span> &gt;
                    <strong>Website CMS Editor</strong>
                </nav>
                <h1 class="page-title">Website Content Management</h1>
            </div>
            <div>
                <a href="../../index.php" target="_blank" class="btn btn-outline-primary">↗ View Public Website</a>
            </div>
        </header>

        <div class="erp-content">
            <?php if ($msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($err): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= e($err) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Page selector sidebar -->
                <div class="col-lg-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 fw-bold">
                            🌐 Website Pages (<?= count($allPages) ?>)
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($allPages as $pg): ?>
                                <a href="?slug=<?= urlencode($pg['slug']) ?>"
                                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($pg['slug'] === $editSlug) ? 'active' : '' ?>">
                                    <div>
                                        <div class="fw-bold"><?= e($pg['slug']) ?></div>
                                        <small class="<?= ($pg['slug'] === $editSlug) ? 'text-white-50' : 'text-muted' ?>">
                                            <?= e($pg['page_title']) ?>
                                        </small>
                                    </div>
                                    <span class="badge <?= ($pg['slug'] === $editSlug) ? 'bg-light text-dark' : 'bg-secondary' ?> rounded-pill" style="font-size: .7rem;">
                                        edit
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Page content editor -->
                <div class="col-lg-9">
                    <?php if ($activePageData): 
                        $rawJson = $activePageData['data_json'] ?: '{}';
                        $dec = json_decode($rawJson, true);
                        $prettyJson = json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $rawJson;
                    ?>
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0 fw-bold">Editing Page: <span class="text-primary font-monospace"><?= e($activePageData['slug']) ?></span></h5>
                                    <small class="text-muted">Last modified: <?= date('d M Y, h:i A', strtotime($activePageData['updated_at'])) ?></small>
                                </div>
                                <a href="../../<?= e($activePageData['slug'] === 'index' ? '' : ($activePageData['slug'] . '.php')) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    ↗ Live Preview
                                </a>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="save_page">
                                    <input type="hidden" name="slug" value="<?= e($activePageData['slug']) ?>">

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Page SEO / Browser Title *</label>
                                        <input type="text" name="page_title" class="form-control" required value="<?= e($activePageData['page_title']) ?>">
                                    </div>

                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="form-label fw-bold mb-0">Structured Page Content (JSON)</label>
                                            <small class="text-muted">Directly powers hero banners, sections, cards &amp; text on the website</small>
                                        </div>
                                        <textarea name="data_json" class="form-control font-monospace" rows="18" style="font-size: .85rem; line-height: 1.4;"><?= e($prettyJson) ?></textarea>
                                        <small class="text-muted d-block mt-1">
                                            💡 Content is validated for valid JSON formatting on save to guarantee site stability.
                                        </small>
                                    </div>

                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="cms-content.php" class="btn btn-secondary">Discard Changes</a>
                                        <button type="submit" class="btn btn-primary fw-bold px-4">💾 Save Website Page</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
