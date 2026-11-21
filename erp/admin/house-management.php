<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'house_management');

$pageTitle = 'House Management & Leaderboard';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS houses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        house_code VARCHAR(20) NOT NULL UNIQUE,
        house_name VARCHAR(100) NOT NULL,
        color_code VARCHAR(20) NOT NULL DEFAULT '#3b82f6',
        house_teacher_id BIGINT UNSIGNED DEFAULT NULL,
        motto VARCHAR(255) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        points INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS house_activity_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        house_id INT UNSIGNED NOT NULL,
        event_name VARCHAR(150) NOT NULL,
        event_date DATE NOT NULL,
        points_awarded INT NOT NULL DEFAULT 0,
        reason TEXT DEFAULT NULL,
        awarded_by VARCHAR(100) DEFAULT 'Admin',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_hal_house (house_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'award_points') {
            $houseId = (int) ($_POST['house_id'] ?? 0);
            $eventName = trim((string) ($_POST['event_name'] ?? ''));
            $points = (int) ($_POST['points'] ?? 0);
            $eventDate = trim((string) ($_POST['event_date'] ?? date('Y-m-d')));
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if ($houseId <= 0 || $eventName === '' || $points === 0) {
                throw new \RuntimeException('House, Event Name, and valid Points (non-zero) are required.');
            }

            $pdo->beginTransaction();
            // 1. Insert activity log
            $pdo->prepare("INSERT INTO house_activity_logs (house_id, event_name, event_date, points_awarded, reason, awarded_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$houseId, $eventName, $eventDate, $points, $reason, $user['name'] ?? 'Admin']);

            // 2. Update house score
            $pdo->prepare("UPDATE houses SET points = points + ?, updated_at = NOW() WHERE id = ?")
                ->execute([$points, $houseId]);

            $pdo->commit();
            $success = "Successfully recorded {$points} points for event '{$eventName}'.";
        }

        if ($action === 'save_house') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = strtoupper(trim((string) ($_POST['house_code'] ?? '')));
            $name = trim((string) ($_POST['house_name'] ?? ''));
            $color = trim((string) ($_POST['color_code'] ?? '#3b82f6'));
            $motto = trim((string) ($_POST['motto'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));

            if ($code === '' || $name === '') {
                throw new \RuntimeException('House code and name are required.');
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE houses SET house_code = ?, house_name = ?, color_code = ?, motto = ?, description = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$code, $name, $color, $motto, $desc, $id]);
                $success = "House '{$name}' updated successfully.";
            } else {
                $pdo->prepare("INSERT INTO houses (house_code, house_name, color_code, motto, description, points, status) VALUES (?, ?, ?, ?, ?, 0, 'Active')")
                    ->execute([$code, $name, $color, $motto, $desc]);
                $success = "New House '{$name}' created successfully.";
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: house-management.php?success=' . urlencode($success));
        exit;
    }
}

// ── Fetch Houses with Leaderboard Rank and Student Count ──
$houses = $pdo->query("
    SELECT h.*,
           (SELECT COUNT(*) FROM student_house_allocations WHERE house_id = h.id AND status = 'Active') as student_count,
           (SELECT CONCAT(s.first_name, ' ', s.last_name) FROM student_house_allocations sha JOIN students s ON s.id = sha.student_id WHERE sha.house_id = h.id AND sha.role = 'Captain' AND sha.status = 'Active' LIMIT 1) as captain_name,
           (SELECT name FROM employees WHERE id = h.house_teacher_id LIMIT 1) as teacher_name
    FROM houses h
    WHERE h.status = 'Active'
    ORDER BY h.points DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate maximum points for percentage bar
$maxPoints = 0;
foreach ($houses as $h) {
    if ((int)$h['points'] > $maxPoints) {
        $maxPoints = (int)$h['points'];
    }
}
if ($maxPoints <= 0) $maxPoints = 1;

// Recent Activity Log
$activityLogs = $pdo->query("
    SELECT l.*, h.house_name, h.color_code
    FROM house_activity_logs l
    JOIN houses h ON h.id = l.house_id
    ORDER BY l.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.4rem">
                    <span class="eyebrow">Campus Life & Co-Curricular</span>
                    <h1>House Management & Leaderboard</h1>
                    <p>Track school houses, house standings, and award inter-house competition points.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="openModal('awardPointsModal')">🏆 Award House Points</button>
                    <button type="button" class="btn btn-sm btn-soft" onclick="openAddHouseModal()">+ Add House</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- House Leaderboard Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:1.25rem;margin-bottom:1.5rem;">
            <?php foreach ($houses as $idx => $h): 
                $rank = $idx + 1;
                $pct = round(((int)$h['points'] / $maxPoints) * 100);
            ?>
                <div class="panel" style="border-top:6px solid <?= e($h['color_code']) ?>;padding:1.25rem;position:relative;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                        <span style="font-size:.78rem;font-weight:700;color:<?= e($h['color_code']) ?>;text-transform:uppercase;letter-spacing:1px;">
                            <?= e($h['house_code']) ?>
                        </span>
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:<?= $rank === 1 ? '#fef08a' : '#f1f5f9' ?>;color:<?= $rank === 1 ? '#854d0e' : '#475569' ?>;font-weight:800;font-size:.85rem;">
                            #<?= $rank ?>
                        </span>
                    </div>

                    <h2 style="margin:0 0 .25rem;font-size:1.2rem;font-weight:800;color:#0f172a;"><?= e($h['house_name']) ?></h2>
                    <div style="font-size:.82rem;font-style:italic;color:#64748b;margin-bottom:.75rem;">"<?= e($h['motto'] ?: 'Excellence and integrity') ?>"</div>

                    <div style="display:flex;align-items:baseline;gap:.5rem;margin-bottom:.5rem;">
                        <span style="font-size:1.8rem;font-weight:800;color:<?= e($h['color_code']) ?>;"><?= number_format((int)$h['points']) ?></span>
                        <span style="font-size:.85rem;color:#64748b;font-weight:600;">points</span>
                    </div>

                    <!-- Progress bar against leader -->
                    <div style="background:#e2e8f0;height:8px;border-radius:4px;overflow:hidden;margin-bottom:1rem;">
                        <div style="width:<?= $pct ?>%;height:100%;background:<?= e($h['color_code']) ?>;border-radius:4px;"></div>
                    </div>

                    <div style="border-top:1px solid #f1f5f9;padding-top:.75rem;font-size:.82rem;display:flex;flex-direction:column;gap:.35rem;color:#475569;">
                        <div><strong>Members:</strong> <?= (int)$h['student_count'] ?> students</div>
                        <div><strong>Captain:</strong> <?= e($h['captain_name'] ?: 'Not Assigned') ?></div>
                        <div><strong>House Master:</strong> <?= e($h['teacher_name'] ?: 'Staff In-Charge') ?></div>
                    </div>

                    <div style="margin-top:1rem;display:flex;gap:.5rem;">
                        <button type="button" class="btn btn-sm btn-soft" style="flex:1;" onclick="quickAward(<?= (int)$h['id'] ?>, '<?= e(addslashes($h['house_name'])) ?>')">
                            + Award Points
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick='editHouse(<?= json_encode($h) ?>)'>
                            Edit
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Activity Log & Points Register -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Recent Inter-House Competition Points Log</h3>
                <span style="font-size:.8rem;color:#64748b;">Audit trail of points earned</span>
            </div>

            <?php if (empty($activityLogs)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    No points recorded yet. Click "<strong>🏆 Award House Points</strong>" to record results of sports, debates, or quizzes!
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>House</th>
                                <th>Event / Activity</th>
                                <th style="text-align:right;">Points</th>
                                <th>Reason / Remarks</th>
                                <th>Awarded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityLogs as $log): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($log['event_date']) ?></td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:700;background:<?= e($log['color_code']) ?>20;color:<?= e($log['color_code']) ?>;">
                                            <span style="width:8px;height:8px;border-radius:50%;background:<?= e($log['color_code']) ?>;"></span>
                                            <?= e($log['house_name']) ?>
                                        </span>
                                    </td>
                                    <td><strong><?= e($log['event_name']) ?></strong></td>
                                    <td style="text-align:right;font-size:1.05rem;font-weight:800;color:<?= (int)$log['points_awarded'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
                                        <?= (int)$log['points_awarded'] >= 0 ? '+' : '' ?><?= (int)$log['points_awarded'] ?>
                                    </td>
                                    <td style="color:#64748b;font-size:.85rem;"><?= e($log['reason'] ?: '—') ?></td>
                                    <td><?= e($log['awarded_by'] ?: 'Admin') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ AWARD POINTS MODAL ═══════════════════ -->
<div id="awardPointsModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:540px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="awardModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Award Inter-House Points</h2>
            <button type="button" class="icon-btn" onclick="closeModal('awardPointsModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="award_points">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select House *</label>
                <select name="house_id" id="awardHouseSelect" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose House --</option>
                    <?php foreach ($houses as $h): ?>
                        <option value="<?= (int) $h['id'] ?>"><?= e($h['house_name']) ?> (<?= e($h['house_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Event / Competition Title *</label>
                <input type="text" name="event_name" required placeholder="e.g. Annual Athletics 100m Dash / Inter-house Debate" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Points to Award *</label>
                    <input type="number" name="points" required placeholder="e.g. 50 (or negative for penalty)" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Event Date *</label>
                    <input type="date" name="event_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Description / Reason</label>
                <textarea name="reason" rows="2" placeholder="e.g. First place in Junior Boys relay race" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('awardPointsModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Save Points</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ ADD / EDIT HOUSE MODAL ═══════════════════ -->
<div id="houseModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:540px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="houseModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Add House</h2>
            <button type="button" class="icon-btn" onclick="closeModal('houseModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_house">
            <input type="hidden" name="id" id="houseId" value="">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">House Code *</label>
                    <input type="text" name="house_code" id="houseCode" required placeholder="e.g. H-RED" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;text-transform:uppercase;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Color Hex *</label>
                    <input type="color" name="color_code" id="houseColor" value="#3b82f6" style="width:100%;height:38px;padding:2px;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">House Name *</label>
                <input type="text" name="house_name" id="houseName" required placeholder="e.g. Red Dragons" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Motto</label>
                <input type="text" name="motto" id="houseMotto" placeholder="e.g. Courage and Excellence" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Description</label>
                <textarea name="description" id="houseDesc" rows="2" placeholder="House values and representation..." style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('houseModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" id="houseBtn">Save House</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
function quickAward(houseId, houseName) {
    var sel = document.getElementById('awardHouseSelect');
    if (sel) sel.value = houseId;
    document.getElementById('awardModalTitle').textContent = 'Award Points to ' + houseName;
    openModal('awardPointsModal');
}
function openAddHouseModal() {
    document.getElementById('houseId').value = '';
    document.getElementById('houseCode').value = '';
    document.getElementById('houseName').value = '';
    document.getElementById('houseColor').value = '#3b82f6';
    document.getElementById('houseMotto').value = '';
    document.getElementById('houseDesc').value = '';
    document.getElementById('houseModalTitle').textContent = 'Add New House';
    document.getElementById('houseBtn').textContent = 'Create House';
    openModal('houseModal');
}
function editHouse(h) {
    document.getElementById('houseId').value = h.id;
    document.getElementById('houseCode').value = h.house_code;
    document.getElementById('houseName').value = h.house_name;
    document.getElementById('houseColor').value = h.color_code;
    document.getElementById('houseMotto').value = h.motto || '';
    document.getElementById('houseDesc').value = h.description || '';
    document.getElementById('houseModalTitle').textContent = 'Edit House: ' + h.house_name;
    document.getElementById('houseBtn').textContent = 'Update House';
    openModal('houseModal');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
