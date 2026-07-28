<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$error = '';
$success = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS contact_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    email VARCHAR(100),
    subject VARCHAR(100),
    message TEXT NOT NULL,
    status ENUM('New','Read','Replied','Closed') DEFAULT 'New',
    admin_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update-status') {
        $id = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'New');
        $allowed = ['New', 'Read', 'Replied', 'Closed'];
        if (in_array($status, $allowed, true)) {
            $stmt = $pdo->prepare("UPDATE contact_submissions SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $status, 'id' => $id]);
            $success = 'Status updated.';
        }
    }

    if ($action === 'save-note') {
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        $stmt = $pdo->prepare("UPDATE contact_submissions SET admin_note = :note WHERE id = :id");
        $stmt->execute(['note' => $note, 'id' => $id]);
        $success = 'Note saved.';
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM contact_submissions WHERE id = :id")->execute(['id' => $id]);
        $success = 'Enquiry deleted.';
    }
}

$filter = (string) ($_GET['filter'] ?? 'all');
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
if ($filter === 'new') { $where = 'WHERE status = "New"'; }
elseif ($filter === 'read') { $where = 'WHERE status = "Read"'; }
elseif ($filter === 'replied') { $where = 'WHERE status = "Replied"'; }
elseif ($filter === 'closed') { $where = 'WHERE status = "Closed"'; }

$total = (int) $pdo->query("SELECT COUNT(*) FROM contact_submissions $where")->fetchColumn();
$rows = $pdo->prepare("SELECT * FROM contact_submissions $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$rows->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rows->bindValue(':offset', $offset, PDO::PARAM_INT);
$rows->execute();
$enquiries = $rows->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, (int) ceil($total / $perPage));

$counts = [
    'all'    => (int) $pdo->query("SELECT COUNT(*) FROM contact_submissions")->fetchColumn(),
    'new'    => (int) $pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE status = "New"')->fetchColumn(),
    'read'   => (int) $pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE status = "Read"')->fetchColumn(),
    'replied'=> (int) $pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE status = "Replied"')->fetchColumn(),
    'closed' => (int) $pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE status = "Closed"')->fetchColumn(),
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enquiries — SIBA ERP Admin</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .enq-table { width:100%; border-collapse:collapse; }
        .enq-table th { text-align:left; padding:.6rem .7rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .enq-table td { padding:.6rem .7rem; border-bottom:1px solid #f1f5f9; font-size:.85rem; vertical-align:top; }
        .enq-table tbody tr:hover td { background:#eff6ff; }
        .status-badge { display:inline-block; padding:.2rem .6rem; border-radius:6px; font-size:.73rem; font-weight:600; }
        .status-New { background:#dbeafe; color:#1e40af; }
        .status-Read { background:#fef3c7; color:#92400e; }
        .status-Replied { background:#d1fae5; color:#065f46; }
        .status-Closed { background:#f1f5f9; color:#64748b; }
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; flex-wrap:wrap; }
        .tab-bar a { padding:.55rem 1.2rem; font-size:.85rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .note-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.75rem; font-size:.82rem; color:#475569; margin-top:.3rem; }
        .enq-detail { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; }
        .enq-detail h3 { font-size:1rem; margin-bottom:.5rem; }
        .enq-meta { font-size:.8rem; color:#64748b; margin-bottom:.5rem; }
        .enq-message { font-size:.88rem; color:#334155; margin-bottom:.75rem; white-space:pre-wrap; }
        .enq-actions { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
        .enq-actions select { padding:.3rem .5rem; font-size:.8rem; border:1px solid #cbd5e1; border-radius:6px; min-height:auto; }
        .enq-actions .btn-sm { padding:.3rem .7rem; font-size:.78rem; border-radius:6px; }
        .pagination { display:flex; gap:.5rem; align-items:center; margin-top:1rem; }
        .pagination a, .pagination span { padding:.35rem .7rem; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; font-size:.85rem; color:#334155; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; }
    </style>
</head>
<body>
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow" style="background:rgba(255,255,255,.1);color:#effff5">SIBA ERP</span>
            <div class="brand-copy">
                <h2 style="font-size:1.7rem;color:#fff">Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>

        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link" href="application-intake.php">
                <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
            </a>
            <a class="nav-link" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag">List</span>
            </a>
            <a class="nav-link" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span>
            </a>
            <a class="nav-link" href="events-manager.php">
                <span class="sidebar-icon">📅</span><span>Events & News</span>
            </a>
            <a class="nav-link" href="gallery-manager.php">
                <span class="sidebar-icon">🖼</span><span>Gallery</span>
            </a>
            <a class="nav-link active" href="enquiries.php">
                <span class="sidebar-icon">📩</span><span>Enquiries</span>
            </a>
        </div>
        <?php if ($isOwner): ?>
        <div class="nav-group">
            <div class="nav-title">Administration</div>
            <?php $pendingAdminCount = 0; try { $pendingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $e) {} ?>
            <a class="nav-link" href="admin-requests.php">
                <span class="sidebar-icon">🔑</span>
                <span>Admin Requests</span>
                <?php if ($pendingAdminCount > 0): ?>
                    <span class="nav-tag" style="background:#f59e0b;color:#fff;"><?= $pendingAdminCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Site Content</span>
                    <h1>Enquiries</h1>
                    <p>View and manage enquiries submitted from the website contact form.</p>
                </div>
            </div>
        </section>

        <?php if ($success): ?>
            <div class="flash" style="background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="flash" style="background:#fdecea;border-color:#f3c8c5;color:#8f1c13"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="tab-bar">
            <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All (<?= $counts['all'] ?>)</a>
            <a href="?filter=new" class="<?= $filter === 'new' ? 'active' : '' ?>">New (<?= $counts['new'] ?>)</a>
            <a href="?filter=read" class="<?= $filter === 'read' ? 'active' : '' ?>">Read (<?= $counts['read'] ?>)</a>
            <a href="?filter=replied" class="<?= $filter === 'replied' ? 'active' : '' ?>">Replied (<?= $counts['replied'] ?>)</a>
            <a href="?filter=closed" class="<?= $filter === 'closed' ? 'active' : '' ?>">Closed (<?= $counts['closed'] ?>)</a>
        </div>

        <?php if (empty($enquiries)): ?>
            <div class="panel" style="padding:2rem;text-align:center;color:var(--text-light);">
                <p>No enquiries found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($enquiries as $enq): ?>
                <div class="enq-detail">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
                        <div>
                            <h3><?= e($enq['name']) ?> <span class="status-badge status-<?= e($enq['status']) ?>"><?= e($enq['status']) ?></span></h3>
                            <div class="enq-meta">
                                <i class="fas fa-phone"></i> <?= e($enq['phone']) ?>
                                <?php if ($enq['email']): ?>
                                    &nbsp;·&nbsp; <i class="fas fa-envelope"></i> <?= e($enq['email']) ?>
                                <?php endif; ?>
                                &nbsp;·&nbsp; <i class="fas fa-calendar"></i> <?= e($enq['created_at']) ?>
                                <?php if ($enq['subject']): ?>
                                    &nbsp;·&nbsp; <span class="tag" style="background:#e8f4fc;color:#1f5f87;padding:.15rem .5rem;border-radius:4px;font-size:.73rem;font-weight:600;"><?= e($enq['subject']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="enq-message"><?= e($enq['message']) ?></div>

                    <?php if ($enq['admin_note']): ?>
                        <div class="note-box"><strong>Note:</strong> <?= e($enq['admin_note']) ?></div>
                    <?php endif; ?>

                    <div class="enq-actions" style="margin-top:.75rem;">
                        <form method="post" style="display:inline-flex;gap:.3rem;align-items:center;">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update-status">
                            <input type="hidden" name="id" value="<?= (int) $enq['id'] ?>">
                            <select name="status" onchange="this.form.submit()">
                                <?php foreach (['New','Read','Replied','Closed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $enq['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <form method="post" style="display:inline-flex;gap:.3rem;align-items:center;flex:1;min-width:200px;">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save-note">
                            <input type="hidden" name="id" value="<?= (int) $enq['id'] ?>">
                            <input type="text" name="admin_note" value="<?= e($enq['admin_note'] ?? '') ?>" placeholder="Add a note..." style="flex:1;padding:.35rem .6rem;font-size:.82rem;border:1px solid #cbd5e1;border-radius:6px;min-height:auto;">
                            <button type="submit" class="btn btn-sm">Save Note</button>
                        </form>

                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this enquiry?')">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $enq['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?filter=<?= e($filter) ?>&p=<?= $page - 1 ?>">‹ Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?filter=<?= e($filter) ?>&p=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?filter=<?= e($filter) ?>&p=<?= $page + 1 ?>">Next ›</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/erp.js"></script>
</body>
</html>
