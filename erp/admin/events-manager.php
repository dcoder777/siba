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

$pdo->exec("CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('event', 'news') NOT NULL DEFAULT 'event',
    title VARCHAR(255) NOT NULL,
    text TEXT,
    day VARCHAR(2),
    month VARCHAR(20),
    icon VARCHAR(50) DEFAULT 'calendar',
    color VARCHAR(7) DEFAULT '#4b5563',
    image VARCHAR(500),
    attachment VARCHAR(500),
    category VARCHAR(100),
    event_date DATE,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
try { $pdo->exec("ALTER TABLE events ADD COLUMN attachment VARCHAR(500) AFTER image"); } catch (\Throwable $e) {}

$action = $_GET['action'] ?? 'list';
$type = $_GET['type'] ?? 'event';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $postAction = trim((string) ($_POST['action'] ?? ''));
    if (($action === 'add' || $action === 'edit') && ($postAction === '' || $postAction === 'add' || $postAction === 'edit')) {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $text = trim((string) ($_POST['text'] ?? ''));
        $day = trim((string) ($_POST['day'] ?? ''));
        $month = trim((string) ($_POST['month'] ?? ''));
        $icon = trim((string) ($_POST['icon'] ?? 'calendar'));
        $color = trim((string) ($_POST['color'] ?? '#4b5563'));
        $image = trim((string) ($_POST['image'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $eventDate = trim((string) ($_POST['event_date'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? 'event'));
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            $error = 'Title is required.';
        } else {
            $eventDateVal = $eventDate !== '' ? $eventDate : null;
            $imageVal = $image !== '' ? $image : null;

            $uploadDir = __DIR__ . '/../../uploads/events/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $attachmentVal = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip','rar','jpg','jpeg','png','mp4','webm'];
                if (in_array($ext, $allowed, true)) {
                    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['attachment']['name']));
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $filename);
                    $attachmentVal = $filename;
                } else {
                    $error = 'File type not allowed.';
                }
            }

            if ($error === '') {
                if ($id > 0) {
                    if ($attachmentVal === null) $attachmentVal = $row['attachment'] ?? null;
                    $stmt = $pdo->prepare("UPDATE events SET type=?, title=?, text=?, day=?, month=?, icon=?, color=?, image=?, attachment=?, category=?, event_date=?, is_active=? WHERE id=?");
                    $stmt->execute([$type, $title, $text, $day, $month, $icon, $color, $imageVal, $attachmentVal, $category, $eventDateVal, $active, $id]);
                    $success = 'Updated successfully.';
                } else {
                    $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM events");
                    $maxSort->execute();
                    $nextSort = (int) $maxSort->fetchColumn();
                    $stmt = $pdo->prepare("INSERT INTO events (type, title, text, day, month, icon, color, image, attachment, category, event_date, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$type, $title, $text, $day, $month, $icon, $color, $imageVal, $attachmentVal, $category, $eventDateVal, $nextSort, $active]);
                    $success = 'Added successfully.';
                }
                $action = 'list';
            }
        }
    }

    if ($postAction === 'delete' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $delRow = $pdo->prepare("SELECT attachment FROM events WHERE id=?");
        $delRow->execute([$id]);
        $delAttach = $delRow->fetchColumn();
        if ($delAttach && file_exists(__DIR__ . '/../../uploads/events/' . $delAttach)) {
            unlink(__DIR__ . '/../../uploads/events/' . $delAttach);
        }
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$id]);
        $success = 'Deleted successfully.';
        $action = 'list';
    }

    if ($postAction === 'reorder' && isset($_POST['ids'])) {
        $ids = (array) $_POST['ids'];
        foreach ($ids as $i => $id) {
            $pdo->prepare("UPDATE events SET sort_order=? WHERE id=?")->execute([$i, (int) $id]);
        }
        $success = 'Order updated.';
        $action = 'list';
    }
}

$editRow = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id=?");
    $stmt->execute([(int) $_GET['id']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = 'Record not found.';
        $action = 'list';
    }
}

$stmt = $pdo->prepare("SELECT * FROM events WHERE type=? ORDER BY sort_order ASC");
$stmt->execute([$type]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventsCount = $pdo->query("SELECT COUNT(*) FROM events WHERE type='event'")->fetchColumn();
$newsCount = $pdo->query("SELECT COUNT(*) FROM events WHERE type='news'")->fetchColumn();

$monthOptions = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$iconOptions = ['calendar','running','graduation-cap','handshake','flask','music','paint-brush','futbol','book','star','bell','bullhorn','video','camera','award','trophy','users','globe','leaf','heart'];
$colorOptions = ['#4b5563','#272727','#feb630','#5eabe3','#10b981','#ef4444','#8b5cf6','#ec4899','#14b8a6','#f97316'];

$activeTab = $type;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Events Manager — SIBA ERP Admin</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .events-table { width:100%; border-collapse:collapse; }
        .events-table th, .events-table td { padding:0.6rem 0.75rem; text-align:left; border-bottom:1px solid #e5e7eb; font-size:0.88rem; }
        .events-table th { font-weight:600; color:var(--text-light); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; }
        .events-table tr:hover td { background:#f9fafb; }
        .color-swatch { display:inline-block; width:14px; height:14px; border-radius:50%; vertical-align:middle; }
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:0.6rem 1.5rem; font-size:0.9rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .tag { display:inline-block; padding:0.15rem 0.5rem; border-radius:4px; font-size:0.75rem; font-weight:600; }
        .tag-event { background:#dbeafe; color:#1e40af; }
        .tag-news { background:#fce7f3; color:#9d174d; }
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
                    <h1>Events & News Manager</h1>
                    <p>Add, edit, and manage events and news items displayed on the school website.</p>
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <a class="btn" href="?action=add&type=event">+ Add Event</a>
                    <a class="btn" href="?action=add&type=news">+ Add News</a>
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
            <?php $isEdit = $action === 'edit' && $editRow; $row = $editRow ?? []; ?>
            <section class="panel" style="padding:1.5rem;">
                <div class="section-title" style="margin-bottom:1.25rem">
                    <h2><?= $isEdit ? 'Edit' : 'Add' ?> <?= $type === 'event' ? 'Event' : 'News' ?></h2>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="type" value="<?= e($isEdit ? (string) ($row['type'] ?? $type) : $type) ?>">

                    <div class="field-grid">
                        <div class="full-col">
                            <label for="title">Title *</label>
                            <input id="title" name="title" type="text" required value="<?= e($row['title'] ?? '') ?>">
                        </div>
                        <div class="full-col">
                            <label for="text">Description</label>
                            <textarea id="text" name="text" rows="4"><?= e($row['text'] ?? '') ?></textarea>
                        </div>
                        <?php if ($type === 'event' || ($isEdit && ($row['type'] ?? '') === 'event')): ?>
                        <div>
                            <label for="day">Day (e.g. 15)</label>
                            <input id="day" name="day" type="text" maxlength="2" value="<?= e($row['day'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="month">Month (e.g. Apr)</label>
                            <select id="month" name="month">
                                <option value="">—</option>
                                <?php foreach ($monthOptions as $m): ?>
                                    <option value="<?= e($m) ?>" <?= ($row['month'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="icon">Icon</label>
                            <select id="icon" name="icon">
                                <?php foreach ($iconOptions as $opt): ?>
                                    <option value="<?= e($opt) ?>" <?= ($row['icon'] ?? 'calendar') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="color">Color</label>
                            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                                <input id="color" name="color" type="color" value="<?= e($row['color'] ?? '#4b5563') ?>" style="width:44px;height:36px;padding:2px;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;background:transparent;">
                                <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                                    <?php foreach ($colorOptions as $opt): ?>
                                        <button type="button" class="color-pick-btn" data-color="<?= e($opt) ?>" title="<?= e($opt) ?>" style="width:24px;height:24px;border-radius:50%;border:2px solid <?= ($row['color'] ?? '#4b5563') === $opt ? '#1e293b' : '#e5e7eb' ?>;background:<?= e($opt) ?>;cursor:pointer;padding:0;"></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($type === 'news' || ($isEdit && ($row['type'] ?? '') === 'news')): ?>
                        <div>
                            <label for="category">Category</label>
                            <input id="category" name="category" type="text" value="<?= e($row['category'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="event_date">Date</label>
                            <input id="event_date" name="event_date" type="date" value="<?= e($row['event_date'] ?? '') ?>">
                        </div>
                        <div class="full-col">
                            <label for="image">Image URL</label>
                            <input id="image" name="image" type="text" value="<?= e($row['image'] ?? '') ?>">
                        </div>
                        <?php endif; ?>
                        <div class="full-col">
                            <label for="attachment">Attachment (PDF, DOC, Image, Video)</label>
                            <input id="attachment" name="attachment" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.jpg,.jpeg,.png,.mp4,.webm">
                            <?php if ($isEdit && !empty($row['attachment'])): ?>
                                <small style="color:#64748b;">Current: <a href="<?= SITE_URL ?>/uploads/events/<?= e($row['attachment']) ?>" target="_blank"><?= e($row['attachment']) ?></a></small>
                            <?php endif; ?>
                        </div>
                        <div class="full-col" style="margin-top:.5rem;">
                            <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" <?= $isEdit ? (($row['is_active'] ?? 1) ? 'checked' : '') : 'checked' ?> style="width:auto;min-height:auto;accent-color:var(--primary-color);">
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= $isEdit ? 'Update' : 'Add' ?></button>
                        <a href="events-manager.php?type=<?= e($type) ?>" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <div class="tab-bar">
                <a href="?type=event" class="<?= $activeTab === 'event' ? 'active' : '' ?>">Events (<?= $eventsCount ?>)</a>
                <a href="?type=news" class="<?= $activeTab === 'news' ? 'active' : '' ?>">News (<?= $newsCount ?>)</a>
            </div>

            <section class="panel" style="padding:1.25rem;">
                <?php if (count($rows) === 0): ?>
                    <p style="text-align:center;padding:2rem;color:var(--text-light);">No <?= e($type === 'event' ? 'events' : 'news items') ?> yet. Click "+ Add" to create one.</p>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reorder">
                    <table class="events-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>Title</th>
                                <?php if ($type === 'event'): ?>
                                <th style="width:80px;">Day</th>
                                <th style="width:80px;">Month</th>
                                <th style="width:60px;">Color</th>
                                <?php endif; ?>
                                <?php if ($type === 'news'): ?>
                                <th style="width:100px;">Category</th>
                                <th style="width:120px;">Date</th>
                                <?php endif; ?>
                                <th style="width:60px;">Active</th>
                                <th style="width:140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td style="color:var(--text-light);font-size:0.8rem;"><?= $i++ ?></td>
                                <td><strong><?= e((string) ($r['title'] ?? '')) ?></strong></td>
                                <?php if ($type === 'event'): ?>
                                <td><?= e((string) ($r['day'] ?? '')) ?></td>
                                <td><?= e((string) ($r['month'] ?? '')) ?></td>
                                <td><span class="color-swatch" style="background:<?= e((string) ($r['color'] ?? '#4b5563')) ?>"></span></td>
                                <?php endif; ?>
                                <?php if ($type === 'news'): ?>
                                <td><span class="tag tag-news"><?= e((string) ($r['category'] ?? '')) ?></span></td>
                                <td style="font-size:0.83rem;"><?= e((string) ($r['event_date'] ?? '')) ?></td>
                                <?php endif; ?>
                                <td><?= ($r['is_active'] ?? 0) ? '✓' : '✗' ?></td>
                                <td>
                                    <a class="btn btn-sm" href="?action=edit&id=<?= (int) $r['id'] ?>&type=<?= e($type) ?>">Edit</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this item?')">
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
<script>
document.querySelectorAll('.color-pick-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var colorInput = document.getElementById('color');
        colorInput.value = this.dataset.color;
        document.querySelectorAll('.color-pick-btn').forEach(function(b) { b.style.borderColor = '#e5e7eb'; });
        this.style.borderColor = '#1e293b';
    });
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
