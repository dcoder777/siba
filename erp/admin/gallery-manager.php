<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isSuperAdmin = ($user['role'] ?? '') === 'admin';
$isOwner = ($user['role'] ?? '') === 'owner';
$explicitModules = fetch_user_module_access($pdo, (int) $user['id']);
$userRoles = fetch_user_roles($pdo, (int) $user['id'], (string) ($user['role'] ?? 'admin'));
$menus = menu_for_roles($userRoles, $explicitModules);
$entityMap = entity_config();
$error = '';
$success = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    image_file VARCHAR(500),
    youtube_url VARCHAR(500),
    category VARCHAR(100) DEFAULT 'General',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Auto-migrate: add youtube_url if missing
try { $pdo->exec("ALTER TABLE gallery ADD COLUMN youtube_url VARCHAR(500) AFTER image_file"); } catch (\Throwable $e) {}

$action = $_GET['action'] ?? 'list';

$uploadDir = __DIR__ . '/../../uploads/gallery/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $postAction = trim((string) ($_POST['action'] ?? ''));
    if (($action === 'add' || $action === 'edit') && ($postAction === '' || $postAction === 'add' || $postAction === 'edit')) {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'General'));
        $youtubeUrl = trim((string) ($_POST['youtube_url'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;

        // Normalize YouTube URL to embed format
        if ($youtubeUrl !== '') {
            if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $youtubeUrl, $m)) {
                $youtubeUrl = 'https://www.youtube.com/embed/' . $m[1];
            }
        }

        if ($title === '') {
            $error = 'Title is required.';
        } else {
            $imageFile = '';
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                if (in_array($_FILES['image_file']['type'], $allowed)) {
                    $imageFile = time() . '_gal_' . basename($_FILES['image_file']['name']);
                    move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $imageFile);
                } else {
                    $error = 'Only JPG, PNG, WebP images are allowed.';
                }
            }

            if ($error === '') {
                if ($id > 0) {
                    if ($imageFile !== '') {
                        $stmt = $pdo->prepare("UPDATE gallery SET title=?, description=?, image_file=?, youtube_url=?, category=?, is_active=? WHERE id=?");
                        $stmt->execute([$title, $description, $imageFile, $youtubeUrl ?: null, $category, $active, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE gallery SET title=?, description=?, youtube_url=?, category=?, is_active=? WHERE id=?");
                        $stmt->execute([$title, $description, $youtubeUrl ?: null, $category, $active, $id]);
                    }
                    $success = 'Gallery item updated successfully.';
                } else {
                    if ($imageFile === '' && $youtubeUrl === '') {
                        $error = 'Please upload an image or enter a YouTube URL.';
                    } else {
                        $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM gallery");
                        $maxSort->execute();
                        $nextSort = (int) $maxSort->fetchColumn();
                        $stmt = $pdo->prepare("INSERT INTO gallery (title, description, image_file, youtube_url, category, sort_order, is_active) VALUES (?,?,?,?,?,?,?)");
                        $stmt->execute([$title, $description, $imageFile ?: null, $youtubeUrl ?: null, $category, $nextSort, $active]);
                        $success = 'Gallery item added successfully.';
                    }
                }
                $action = 'list';
            }
        }
    }

    if ($postAction === 'delete' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $row = $pdo->prepare("SELECT image_file FROM gallery WHERE id=?");
        $row->execute([$id]);
        $img = $row->fetchColumn();
        if ($img && file_exists($uploadDir . $img)) {
            unlink($uploadDir . $img);
        }
        $pdo->prepare("DELETE FROM gallery WHERE id=?")->execute([$id]);
        $success = 'Gallery item deleted successfully.';
        $action = 'list';
    }

    if ($postAction === 'reorder' && isset($_POST['ids'])) {
        $ids = (array) $_POST['ids'];
        foreach ($ids as $i => $id) {
            $pdo->prepare("UPDATE gallery SET sort_order=? WHERE id=?")->execute([$i, (int) $id]);
        }
        $success = 'Order updated.';
        $action = 'list';
    }
}

$editRow = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM gallery WHERE id=?");
    $stmt->execute([(int) $_GET['id']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = 'Record not found.';
        $action = 'list';
    }
}

$galleryCount = $pdo->query("SELECT COUNT(*) FROM gallery")->fetchColumn();
$rows = $pdo->query("SELECT * FROM gallery ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT DISTINCT category FROM gallery ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
if (empty($categories)) $categories = ['General'];

$isEdit = $action === 'edit' && $editRow;
$row = $editRow ?? [];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gallery Manager — SIBA ERP Admin</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .gallery-table { width:100%; border-collapse:collapse; }
        .gallery-table th, .gallery-table td { padding:0.6rem 0.75rem; text-align:left; border-bottom:1px solid #e5e7eb; font-size:0.88rem; }
        .gallery-table th { font-weight:600; color:var(--text-light); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; }
        .gallery-table tr:hover td { background:#f9fafb; }
        .gallery-thumb { width:60px; height:60px; border-radius:8px; object-fit:cover; border:1px solid #e5e7eb; }
        .tag { display:inline-block; padding:0.15rem 0.5rem; border-radius:4px; font-size:0.75rem; font-weight:600; background:#dbeafe; color:#1e40af; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Site Content</span>
                    <h1>Gallery Manager</h1>
                    <p>Upload and manage gallery images displayed on the school website.</p>
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <a class="btn" href="?action=add">+ Add Image</a>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="notice error"><p><?= e($error) ?></p></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="notice success"><p><?= e($success) ?></p></div>
        <?php endif; ?>

        <?php if ($action === 'add' || ($action === 'edit' && $editRow)): ?>
            <section class="panel" style="padding:1.5rem;">
                <div class="section-title" style="margin-bottom:1.25rem">
                    <h2><?= $isEdit ? 'Edit' : 'Add' ?> Gallery Image</h2>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <?php endif; ?>

                    <div class="field-grid">
                        <div class="full-col">
                            <label for="title">Title *</label>
                            <input id="title" name="title" type="text" required value="<?= e($row['title'] ?? '') ?>">
                        </div>
                        <div class="full-col">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="3"><?= e($row['description'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label for="category">Category</label>
                            <input id="category" name="category" type="text" value="<?= e($row['category'] ?? 'General') ?>" list="catList">
                            <datalist id="catList">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= e($cat) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label for="youtube_url">YouTube Video URL</label>
                            <input id="youtube_url" name="youtube_url" type="url" placeholder="https://youtube.com/watch?v=..." value="<?= e($row['youtube_url'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="image_file">Image <?= $isEdit ? '(leave blank to keep current)' : ($youtubeUrl ? '' : '*') ?></label>
                            <input id="image_file" name="image_file" type="file" accept="image/*" <?= ($isEdit || !empty($youtubeUrl)) ? '' : 'required' ?>>
                        </div>
                        <?php if ($isEdit && ($row['image_file'] ?? '')): ?>
                        <div class="full-col">
                            <label>Current Image</label>
                            <img src="../../uploads/gallery/<?= rawurlencode($row['image_file']) ?>" style="max-width:300px;max-height:200px;border-radius:8px;border:1px solid #e5e7eb;">
                        </div>
                        <?php endif; ?>
                        <div class="full-col" style="margin-top:.5rem;">
                            <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" <?= $isEdit ? (($row['is_active'] ?? 1) ? 'checked' : '') : 'checked' ?> style="width:auto;min-height:auto;accent-color:var(--primary-color);">
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= $isEdit ? 'Update' : 'Upload' ?></button>
                        <a href="gallery-manager.php" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel" style="padding:1.25rem;">
                <?php if (count($rows) === 0): ?>
                    <p style="text-align:center;padding:2rem;color:var(--text-light);">No gallery images yet. Click "+ Add Image" to upload one.</p>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reorder">
                    <table class="gallery-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th style="width:70px;">Preview</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th style="width:60px;">Active</th>
                                <th style="width:140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td style="color:var(--text-light);font-size:0.8rem;"><?= $i++ ?></td>
                                <td>
                                    <?php if (!empty($r['youtube_url'])): ?>
                                        <div style="width:60px;height:60px;border-radius:8px;background:#ef4444;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;border:1px solid #e5e7eb;">▶</div>
                                    <?php elseif (!empty($r['image_file'])): ?>
                                        <img class="gallery-thumb" src="../../uploads/gallery/<?= rawurlencode($r['image_file']) ?>" alt="<?= e($r['title']) ?>">
                                    <?php else: ?>
                                        <div style="width:60px;height:60px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;border:1px solid #e5e7eb;">—</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e((string) ($r['title'] ?? '')) ?></strong>
                                    <?php if ($r['description']): ?>
                                        <br><small style="color:#64748b;"><?= e(mb_strimwidth($r['description'], 0, 60, '...')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="tag"><?= e((string) ($r['category'] ?? 'General')) ?></span></td>
                                <td><?= ($r['is_active'] ?? 0) ? '✓' : '✗' ?></td>
                                <td>
                                    <a class="btn btn-sm" href="?action=edit&id=<?= (int) $r['id'] ?>">Edit</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this image?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/erp.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
