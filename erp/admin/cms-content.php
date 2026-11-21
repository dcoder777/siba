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

if (isset($_GET['success'])) {
    $msg = (string) $_GET['success'];
}

$editSlug = trim((string) ($_GET['slug'] ?? ''));

function format_cms_label(string $key): string
{
    $acronyms = ['cta' => 'CTA', 'seo' => 'SEO', 'faq' => 'FAQ', 'url' => 'URL', 'id' => 'ID', 'tc' => 'TC', 'lc' => 'LC', 'wbbse' => 'WBBSE', 'cctv' => 'CCTV'];
    $words = explode('_', $key);
    $out = [];
    foreach ($words as $w) {
        $lw = strtolower($w);
        $out[] = $acronyms[$lw] ?? ucfirst($w);
    }
    return implode(' ', $out);
}

function get_cms_section_group(string $key): string
{
    if (str_starts_with($key, 'hero_')) return 'Hero Banner';
    if (str_starts_with($key, 'stats_') || $key === 'hero_stats') return 'Statistics & Highlights';
    if (str_starts_with($key, 'about_') || str_starts_with($key, 'foundation_') || str_starts_with($key, 'story_') || str_starts_with($key, 'leadership_') || $key === 'leaders') return 'About & Leadership';
    if (str_starts_with($key, 'curriculum_') || str_starts_with($key, 'streams_') || str_starts_with($key, 'cocurricular_') || $key === 'streams' || $key === 'cocurricular') return 'Curriculum & Academics';
    if (str_starts_with($key, 'offerings_') || $key === 'offerings' || str_starts_with($key, 'infra') || str_starts_with($key, 'hostel_') || str_starts_with($key, 'student_') || $key === 'infrastructure' || $key === 'student_life') return 'Campus & Facilities';
    if (str_starts_with($key, 'admission_') || str_starts_with($key, 'steps_') || str_starts_with($key, 'eligibility_') || str_starts_with($key, 'docs_') || str_starts_with($key, 'fees_') || $key === 'steps' || $key === 'docs') return 'Admissions & Enrolment';
    if (str_starts_with($key, 'events_') || str_starts_with($key, 'news_') || $key === 'events' || $key === 'news') return 'Events & News';
    if (str_starts_with($key, 'faq_') || $key === 'faqs') return 'Frequently Asked Questions (FAQ)';
    if (str_starts_with($key, 'testimonials_') || $key === 'testimonials') return 'Testimonials & Reviews';
    if (str_starts_with($key, 'details_') || str_starts_with($key, 'contacts') || str_starts_with($key, 'contact_') || str_starts_with($key, 'portal_') || str_starts_with($key, 'form_') || str_starts_with($key, 'map_')) return 'Contact & Location';
    if (str_starts_with($key, 'cta_') || str_starts_with($key, 'final_cta_')) return 'Call To Action (CTA)';
    return 'General Page Content';
}

// Handle Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $err = 'Security validation failed (CSRF).';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_page') {
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $title = trim((string) ($_POST['page_title'] ?? ''));
            $saveMode = trim((string) ($_POST['save_mode'] ?? 'form'));

            if ($slug === '' || $title === '') {
                $err = 'Page slug and title are required.';
            } else {
                $formattedJson = '{}';

                if ($saveMode === 'json') {
                    // Raw JSON mode
                    $jsonStr = trim((string) ($_POST['data_json'] ?? '{}'));
                    $decoded = json_decode($jsonStr, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $err = 'Invalid JSON structure: ' . json_last_error_msg() . '. Please verify syntax before saving.';
                    } else {
                        $formattedJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                } else {
                    // Visual Form fields mode
                    // Load existing page to preserve key schema order
                    $curStmt = $pdo->prepare("SELECT data_json FROM cms_pages WHERE slug = ?");
                    $curStmt->execute([$slug]);
                    $existingRaw = $curStmt->fetchColumn() ?: '{}';
                    $existingData = json_decode($existingRaw, true);
                    if (!is_array($existingData)) {
                        $existingData = [];
                    }

                    $reconstructed = [];

                    $postedFields = $_POST['field'] ?? [];
                    $postedList = $_POST['field_list'] ?? [];
                    $postedArray = $_POST['field_array'] ?? [];
                    $postedEmptyArray = $_POST['field_empty_array'] ?? [];

                    // Rebuild maintaining original key order
                    foreach ($existingData as $origKey => $origVal) {
                        if (array_key_exists($origKey, $postedFields)) {
                            $reconstructed[$origKey] = trim((string) $postedFields[$origKey]);
                        } elseif (array_key_exists($origKey, $postedList) && is_array($postedList[$origKey])) {
                            $cleanList = [];
                            foreach ($postedList[$origKey] as $rawItem) {
                                if (is_array($rawItem)) {
                                    $cleanItem = [];
                                    $hasVal = false;
                                    foreach ($rawItem as $pK => $pV) {
                                        $strV = trim((string) $pV);
                                        if ($strV !== '') $hasVal = true;
                                        $cleanItem[$pK] = $strV;
                                    }
                                    if ($hasVal) {
                                        $cleanList[] = $cleanItem;
                                    }
                                }
                            }
                            $reconstructed[$origKey] = $cleanList;
                        } elseif (array_key_exists($origKey, $postedArray) && is_array($postedArray[$origKey])) {
                            $cleanArr = [];
                            foreach ($postedArray[$origKey] as $rawV) {
                                $strV = trim((string) $rawV);
                                if ($strV !== '') {
                                    $cleanArr[] = $strV;
                                }
                            }
                            $reconstructed[$origKey] = $cleanArr;
                        } elseif (array_key_exists($origKey, $postedEmptyArray)) {
                            $reconstructed[$origKey] = [];
                        } else {
                            $reconstructed[$origKey] = $origVal;
                        }
                    }

                    // Also capture any newly added scalar fields that were not in existing data
                    foreach ($postedFields as $k => $v) {
                        if (!array_key_exists($k, $reconstructed)) {
                            $reconstructed[$k] = trim((string) $v);
                        }
                    }

                    $formattedJson = json_encode($reconstructed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                if ($err === '') {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO cms_pages (slug, page_title, data_json, updated_at)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE page_title = VALUES(page_title), data_json = VALUES(data_json), updated_at = NOW()
                        ");
                        $stmt->execute([$slug, $title, $formattedJson]);
                        header("Location: cms-content.php?slug=" . urlencode($slug) . "&success=" . urlencode("Page '{$title}' updated successfully."));
                        exit;
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
    // Default to first page
    $editSlug = $allPages[0]['slug'];
    $stmt = $pdo->prepare("SELECT * FROM cms_pages WHERE slug = ?");
    $stmt->execute([$editSlug]);
    $activePageData = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Parse active page data into sections
$pageSections = [];
$prettyJson = '{}';
if ($activePageData) {
    $rawJson = $activePageData['data_json'] ?: '{}';
    $dec = json_decode($rawJson, true);
    if (!is_array($dec)) {
        $dec = [];
    }
    $prettyJson = json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $rawJson;

    foreach ($dec as $k => $v) {
        $group = get_cms_section_group($k);
        $pageSections[$group][$k] = $v;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Website CMS Content Editor – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .cms-section-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
            overflow: hidden;
        }
        .cms-section-header {
            background: #f8fafc;
            padding: .85rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .cms-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .cms-section-body {
            padding: 1.25rem;
        }
        .list-item-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        .list-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .75rem;
            padding-bottom: .5rem;
            border-bottom: 1px dashed #cbd5e1;
        }
        .editor-mode-nav .nav-link {
            font-weight: 600;
            font-size: .9rem;
            padding: .6rem 1.25rem;
            border-radius: 8px 8px 0 0;
            color: #475569;
        }
        .editor-mode-nav .nav-link.active {
            color: #1e3a8a;
            background: #fff;
            border-bottom-color: #fff;
            font-weight: 700;
        }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <header class="erp-header">
            <div>
                <nav class="breadcrumb-nav">
                    <span>Communication & Content</span> &gt;
                    <strong>Website Content Management</strong>
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
                    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
                        <div class="card-header bg-white py-3 fw-bold border-bottom">
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
                    <?php if ($activePageData): ?>
                        <form method="post" id="cmsForm">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="save_page">
                            <input type="hidden" name="slug" value="<?= e($activePageData['slug']) ?>">
                            <input type="hidden" name="save_mode" id="saveModeInput" value="form">

                            <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
                                <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <h5 class="mb-0 fw-bold">
                                            Editing Page: <span class="text-primary font-monospace"><?= e($activePageData['slug']) ?></span>
                                        </h5>
                                        <small class="text-muted">Last saved: <?= date('d M Y, h:i A', strtotime($activePageData['updated_at'])) ?></small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="../../<?= e($activePageData['slug'] === 'index' ? '' : ($activePageData['slug'] . '.php')) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                            ↗ Live Preview
                                        </a>
                                        <button type="submit" class="btn btn-sm btn-primary px-3 fw-bold">
                                            💾 Save Changes
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Page SEO Title -->
                            <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;">
                                <div class="card-body p-3">
                                    <label class="form-label fw-bold" style="font-size:.9rem;">Page SEO / Browser Title *</label>
                                    <input type="text" name="page_title" class="form-control" required value="<?= e($activePageData['page_title']) ?>" placeholder="e.g. Welcome - SIBA Public School">
                                </div>
                            </div>

                            <!-- Editor Mode Tabs -->
                            <ul class="nav nav-tabs editor-mode-nav mb-3" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" id="tab-visual-btn" type="button" onclick="switchEditorMode('form')">
                                        📝 Visual Form Inputs (Field-by-Field)
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="tab-json-btn" type="button" onclick="switchEditorMode('json')">
                                        { } Raw JSON Editor (Advanced)
                                    </button>
                                </li>
                            </ul>

                            <!-- ═════════ 1. VISUAL FORM INPUTS ═════════ -->
                            <div id="visualEditorPane">
                                <?php if (empty($pageSections)): ?>
                                    <div class="alert alert-info">No structured fields found for this page. You can add content via the Raw JSON tab.</div>
                                <?php else: ?>
                                    <?php foreach ($pageSections as $groupName => $fields): ?>
                                        <div class="cms-section-card">
                                            <div class="cms-section-header">
                                                <h3 class="cms-section-title">
                                                    <span><?= e($groupName) ?></span>
                                                </h3>
                                                <span class="badge bg-light text-muted border"><?= count($fields) ?> field(s)</span>
                                            </div>
                                            <div class="cms-section-body">
                                                <div class="row g-3">
                                                    <?php foreach ($fields as $key => $val): ?>
                                                        <?php if (is_array($val)): ?>
                                                            <?php if (empty($val)): ?>
                                                                <!-- Empty list -->
                                                                <div class="col-12">
                                                                    <div class="p-3 border rounded-3 bg-light d-flex justify-content-between align-items-center">
                                                                        <div>
                                                                            <label class="form-label fw-bold mb-0" style="font-size:.9rem;"><?= e(format_cms_label($key)) ?></label>
                                                                            <div class="text-muted small">List is currently empty.</div>
                                                                        </div>
                                                                        <input type="hidden" name="field_empty_array[<?= e($key) ?>]" value="1">
                                                                    </div>
                                                                </div>
                                                            <?php elseif (isset($val[0]) && is_array($val[0])): ?>
                                                                <!-- Array of Objects / Cards -->
                                                                <div class="col-12">
                                                                    <div class="p-3 border rounded-3 bg-light">
                                                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                                                            <div>
                                                                                <label class="form-label fw-bold mb-0" style="font-size:1rem;color:#1e3a8a;">
                                                                                    <?= e(format_cms_label($key)) ?>
                                                                                </label>
                                                                                <div class="text-muted small">Structured items list (<?= count($val) ?> items)</div>
                                                                            </div>
                                                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addListItem('<?= e($key) ?>')">
                                                                                + Add Item
                                                                            </button>
                                                                        </div>

                                                                        <div id="list-container-<?= e($key) ?>">
                                                                            <?php foreach ($val as $idx => $item): ?>
                                                                                <div class="list-item-box" data-index="<?= $idx ?>">
                                                                                    <div class="list-item-header">
                                                                                        <span class="badge bg-primary">Item #<span class="item-number"><?= $idx + 1 ?></span></span>
                                                                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="removeListItem(this)">✕ Delete</button>
                                                                                    </div>
                                                                                    <div class="row g-2">
                                                                                        <?php foreach ($item as $propK => $propV): 
                                                                                            $isLong = is_string($propV) && (strlen($propV) > 75 || str_contains($propV, "\n") || preg_match('/(text|desc|description|body|bio|quote|answer|value|intro)/i', $propK));
                                                                                        ?>
                                                                                            <div class="<?= $isLong ? 'col-12' : 'col-md-6' ?>">
                                                                                                <label class="form-label text-muted small fw-bold mb-1">
                                                                                                    <?= e(format_cms_label($propK)) ?>
                                                                                                </label>
                                                                                                <?php if ($isLong): ?>
                                                                                                    <textarea name="field_list[<?= e($key) ?>][<?= $idx ?>][<?= e($propK) ?>]" class="form-control form-control-sm" rows="2"><?= e((string)$propV) ?></textarea>
                                                                                                <?php else: ?>
                                                                                                    <input type="text" name="field_list[<?= e($key) ?>][<?= $idx ?>][<?= e($propK) ?>]" class="form-control form-control-sm" value="<?= e((string)$propV) ?>">
                                                                                                <?php endif; ?>
                                                                                            </div>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <!-- Array of Strings -->
                                                                <div class="col-12">
                                                                    <div class="p-3 border rounded-3 bg-light">
                                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                                            <div>
                                                                                <label class="form-label fw-bold mb-0" style="font-size:.95rem;">
                                                                                    <?= e(format_cms_label($key)) ?>
                                                                                </label>
                                                                                <div class="text-muted small">List entries (<?= count($val) ?>)</div>
                                                                            </div>
                                                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addArrayItem('<?= e($key) ?>')">
                                                                                + Add Entry
                                                                            </button>
                                                                        </div>
                                                                        <div id="array-container-<?= e($key) ?>">
                                                                            <?php foreach ($val as $idx => $strItem): ?>
                                                                                <div class="input-group mb-2 array-item-row">
                                                                                    <input type="text" name="field_array[<?= e($key) ?>][]" class="form-control" value="<?= e((string)$strItem) ?>">
                                                                                    <button type="button" class="btn btn-outline-danger" onclick="removeArrayItem(this)">✕</button>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <!-- Scalar text field -->
                                                            <?php 
                                                                $isLong = is_string($val) && (strlen($val) > 75 || str_contains($val, "\n") || preg_match('/(text|desc|description|body|intro|content|address|note|paragraph)/i', $key));
                                                            ?>
                                                            <div class="<?= $isLong ? 'col-12' : 'col-md-6' ?>">
                                                                <label class="form-label fw-bold mb-1" style="font-size: .88rem;">
                                                                    <?= e(format_cms_label($key)) ?>
                                                                </label>
                                                                <?php if ($isLong): ?>
                                                                    <textarea name="field[<?= e($key) ?>]" class="form-control" rows="3"><?= e((string)$val) ?></textarea>
                                                                <?php else: ?>
                                                                    <input type="text" name="field[<?= e($key) ?>]" class="form-control" value="<?= e((string)$val) ?>">
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- ═════════ 2. RAW JSON EDITOR PANE ═════════ -->
                            <div id="jsonEditorPane" style="display:none;">
                                <div class="card border-0 shadow-sm mb-4">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label fw-bold mb-0">Raw Structured JSON</label>
                                            <small class="text-muted">Direct underlying JSON representation</small>
                                        </div>
                                        <textarea name="data_json" id="rawJsonTextarea" class="form-control font-monospace" rows="22" style="font-size:.85rem;line-height:1.4;background:#1e293b;color:#f8fafc;"><?= e($prettyJson) ?></textarea>
                                        <small class="text-muted d-block mt-2">
                                            💡 Changes made in Raw JSON mode are validated for valid JSON syntax upon saving.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom Action Bar -->
                            <div class="card border-0 shadow-sm" style="border-radius:12px;">
                                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                                    <a href="cms-content.php?slug=<?= urlencode($activePageData['slug']) ?>" class="btn btn-outline-secondary">Discard Changes</a>
                                    <button type="submit" class="btn btn-primary fw-bold px-4">💾 Save Website Page</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function switchEditorMode(mode) {
    var visualPane = document.getElementById('visualEditorPane');
    var jsonPane = document.getElementById('jsonEditorPane');
    var visualBtn = document.getElementById('tab-visual-btn');
    var jsonBtn = document.getElementById('tab-json-btn');
    var saveModeInput = document.getElementById('saveModeInput');

    if (mode === 'json') {
        visualPane.style.display = 'none';
        jsonPane.style.display = 'block';
        visualBtn.classList.remove('active');
        jsonBtn.classList.add('active');
        saveModeInput.value = 'json';
    } else {
        visualPane.style.display = 'block';
        jsonPane.style.display = 'none';
        visualBtn.classList.add('active');
        jsonBtn.classList.remove('active');
        saveModeInput.value = 'form';
    }
}

function removeListItem(btn) {
    var card = btn.closest('.list-item-box');
    var container = card.parentElement;
    if (confirm('Are you sure you want to delete this item?')) {
        card.remove();
        // Renumber remaining items
        var boxes = container.querySelectorAll('.list-item-box');
        boxes.forEach(function(b, idx) {
            var num = b.querySelector('.item-number');
            if (num) num.textContent = (idx + 1);
        });
    }
}

function addListItem(key) {
    var container = document.getElementById('list-container-' + key);
    if (!container) return;
    var existingBoxes = container.querySelectorAll('.list-item-box');
    var nextIdx = existingBoxes.length;

    var cloneSource = existingBoxes.length > 0 ? existingBoxes[existingBoxes.length - 1] : null;
    if (cloneSource) {
        var clone = cloneSource.cloneNode(true);
        clone.setAttribute('data-index', nextIdx);
        var num = clone.querySelector('.item-number');
        if (num) num.textContent = (nextIdx + 1);

        // Reset input values and update names
        clone.querySelectorAll('input, textarea').forEach(function(input) {
            input.value = '';
            var name = input.getAttribute('name') || '';
            // replace index [X] with [nextIdx]
            var updatedName = name.replace(/\[\d+\]/, '[' + nextIdx + ']');
            input.setAttribute('name', updatedName);
        });

        container.appendChild(clone);
    }
}

function removeArrayItem(btn) {
    var row = btn.closest('.array-item-row');
    if (row) row.remove();
}

function addArrayItem(key) {
    var container = document.getElementById('array-container-' + key);
    if (!container) return;
    var div = document.createElement('div');
    div.className = 'input-group mb-2 array-item-row';
    div.innerHTML = '<input type="text" name="field_array[' + key + '][]" class="form-control" value="">' +
                    '<button type="button" class="btn btn-outline-danger" onclick="removeArrayItem(this)">✕</button>';
    container.appendChild(div);
}
</script>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
