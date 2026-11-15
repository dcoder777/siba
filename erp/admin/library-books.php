<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'library');

$pageTitle = 'Library Books & Catalog';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS library_books (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id VARCHAR(50) NOT NULL UNIQUE,
        isbn VARCHAR(50) DEFAULT NULL,
        title VARCHAR(200) NOT NULL,
        author VARCHAR(150) NOT NULL,
        publisher VARCHAR(150) DEFAULT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'General',
        language VARCHAR(50) NOT NULL DEFAULT 'English',
        class_group VARCHAR(50) DEFAULT 'All',
        edition VARCHAR(50) DEFAULT NULL,
        rack_location VARCHAR(50) DEFAULT NULL,
        total_copies INT NOT NULL DEFAULT 1,
        available_copies INT NOT NULL DEFAULT 1,
        status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lb_category (category),
        KEY idx_lb_title (title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_book_copies (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id BIGINT UNSIGNED NOT NULL,
        accession_no VARCHAR(60) NOT NULL UNIQUE,
        barcode VARCHAR(60) DEFAULT NULL,
        rack_location VARCHAR(50) DEFAULT NULL,
        purchase_date DATE DEFAULT NULL,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Available','Issued','Reserved','Damaged','Lost') NOT NULL DEFAULT 'Available',
        condition_note VARCHAR(255) DEFAULT 'Good',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lbc_book (book_id),
        KEY idx_lbc_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_book') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $author = trim((string) ($_POST['author'] ?? ''));
            $publisher = trim((string) ($_POST['publisher'] ?? ''));
            $isbn = trim((string) ($_POST['isbn'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'General'));
            $language = trim((string) ($_POST['language'] ?? 'English'));
            $classGroup = trim((string) ($_POST['class_group'] ?? 'All'));
            $edition = trim((string) ($_POST['edition'] ?? ''));
            $rack = trim((string) ($_POST['rack_location'] ?? 'Rack A-01'));
            $numCopies = max(1, (int) ($_POST['num_copies'] ?? 1));
            $cost = max(0, (float) ($_POST['cost'] ?? 150));

            if ($title === '' || $author === '') {
                throw new \RuntimeException('Book title and author are required.');
            }

            $pdo->beginTransaction();

            $nextNum = (int) $pdo->query("SELECT COUNT(*) FROM library_books")->fetchColumn() + 101;
            $bookCode = 'BOOK-' . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO library_books 
                (book_id, isbn, title, author, publisher, category, language, class_group, edition, rack_location, total_copies, available_copies, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
            $stmt->execute([
                $bookCode, $isbn ?: null, $title, $author, $publisher ?: null,
                $category, $language, $classGroup, $edition ?: null, $rack,
                $numCopies, $numCopies
            ]);
            $newBookId = (int) $pdo->lastInsertId();

            // Insert copy accession records
            $insCopy = $pdo->prepare("INSERT INTO library_book_copies (book_id, accession_no, barcode, rack_location, purchase_date, cost, status, condition_note) VALUES (?, ?, ?, ?, CURDATE(), ?, 'Available', 'Good')");
            for ($i = 1; $i <= $numCopies; $i++) {
                $accNo = 'ACC-' . substr($bookCode, 5) . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                $barcode = 'BC' . substr($bookCode, 5) . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                $insCopy->execute([$newBookId, $accNo, $barcode, $rack, $cost]);
            }

            $pdo->commit();
            $success = "Book '{$title}' added with {$numCopies} physical copy/copies registered.";
        }

        if ($action === 'update_book') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $author = trim((string) ($_POST['author'] ?? ''));
            $publisher = trim((string) ($_POST['publisher'] ?? ''));
            $isbn = trim((string) ($_POST['isbn'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'General'));
            $language = trim((string) ($_POST['language'] ?? 'English'));
            $edition = trim((string) ($_POST['edition'] ?? ''));
            $rack = trim((string) ($_POST['rack_location'] ?? ''));

            if ($id <= 0 || $title === '' || $author === '') {
                throw new \RuntimeException('Valid Book details are required.');
            }

            $stmt = $pdo->prepare("UPDATE library_books SET title = ?, author = ?, publisher = ?, isbn = ?, category = ?, language = ?, edition = ?, rack_location = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $author, $publisher ?: null, $isbn ?: null, $category, $language, $edition ?: null, $rack ?: null, $id]);

            $success = "Book '{$title}' updated successfully.";
        }

        if ($action === 'archive_book') {
            if (!$canDelete && !$isOwner) {
                throw new \RuntimeException('You do not have permission to archive library books.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE library_books SET status = 'Archived' WHERE id = ?")->execute([$id]);
                $success = 'Book moved to archives.';
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: library-books.php?success=' . urlencode($success));
        exit;
    }
}

// ── Search & Filter State ──
$search = trim((string) ($_GET['q'] ?? ''));
$filterCategory = trim((string) ($_GET['cat'] ?? ''));

$categories = $pdo->query("SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

$whereParts = ["status = 'Active'"];
$params = [];

if ($search !== '') {
    $whereParts[] = "(book_id LIKE :q OR title LIKE :q OR author LIKE :q OR isbn LIKE :q OR rack_location LIKE :q)";
    $params['q'] = '%' . $search . '%';
}
if ($filterCategory !== '') {
    $whereParts[] = "category = :cat";
    $params['cat'] = $filterCategory;
}

$whereStr = implode(' AND ', $whereParts);

$booksStmt = $pdo->prepare("SELECT * FROM library_books WHERE {$whereStr} ORDER BY id DESC LIMIT 100");
$booksStmt->execute($params);
$books = $booksStmt->fetchAll(PDO::FETCH_ASSOC);

// Overall Library KPIs
$totalTitles = (int) $pdo->query("SELECT COUNT(*) FROM library_books WHERE status = 'Active'")->fetchColumn();
$totalCopies = (int) $pdo->query("SELECT COALESCE(SUM(total_copies), 0) FROM library_books WHERE status = 'Active'")->fetchColumn();
$availableCopies = (int) $pdo->query("SELECT COALESCE(SUM(available_copies), 0) FROM library_books WHERE status = 'Active'")->fetchColumn();
$activeIssuesCount = (int) $pdo->query("SELECT COUNT(*) FROM library_issues WHERE issue_status = 'Issued'")->fetchColumn();
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
                    <span class="eyebrow">Learning Resource Center</span>
                    <h1>Library Catalog & Accession Registry</h1>
                    <p>Manage the school library book collection, physical accession copies, shelf locations, and circulation availability.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <a href="library-circulation.php" class="btn btn-sm btn-outline">🔄 Circulation Desk (Issue / Return)</a>
                    <button type="button" class="btn btn-sm btn-primary" onclick="openAddBookModal()">+ Add New Book</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <div class="kpi-card">
                <div class="kpi-label">Book Titles Cataloged</div>
                <div class="kpi-value"><?= $totalTitles ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Unique library titles</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Physical Copies</div>
                <div class="kpi-value"><?= $totalCopies ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Accession barcode tagged</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Available on Shelves</div>
                <div class="kpi-value" style="color:#16a34a;"><?= $availableCopies ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Ready for circulation checkout</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Currently Borrowed</div>
                <div class="kpi-value" style="color:#2563eb;"><?= $activeIssuesCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Issued to students & staff</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="library-books.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px;">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by Book ID, Title, Author, ISBN, or Rack Location..." style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <div>
                    <select name="cat" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if ($search !== '' || $filterCategory !== ''): ?>
                    <a href="library-books.php" class="btn btn-sm btn-soft">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Book Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Catalog Directory (<?= count($books) ?>)</h3>
                <span style="font-size:.8rem;color:#64748b;">Showing active catalog</span>
            </div>

            <?php if (empty($books)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    No books found. Click "<strong>+ Add New Book</strong>" to catalog library books.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Book ID</th>
                                <th>Title & Author</th>
                                <th>Category</th>
                                <th>Language</th>
                                <th>Rack Location</th>
                                <th style="text-align:right;">Total Copies</th>
                                <th style="text-align:right;">Available</th>
                                <th style="text-align:center;">Circulation</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($books as $b): 
                                $avail = (int) $b['available_copies'];
                                $tot = (int) $b['total_copies'];
                            ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($b['book_id']) ?></td>
                                    <td>
                                        <div style="font-weight:700;color:#0f172a;"><?= e($b['title']) ?></div>
                                        <div style="font-size:.78rem;color:#64748b;">
                                            By <?= e($b['author']) ?> <?= $b['publisher'] ? (' | ' . e($b['publisher'])) : '' ?>
                                            <?= $b['isbn'] ? (' | ISBN: ' . e($b['isbn'])) : '' ?>
                                        </div>
                                    </td>
                                    <td><span class="badge" style="background:#f1f5f9;color:#334155;"><?= e($b['category']) ?></span></td>
                                    <td><?= e($b['language']) ?></td>
                                    <td><strong style="color:#0369a1;"><?= e($b['rack_location'] ?: '—') ?></strong></td>
                                    <td style="text-align:right;font-weight:600;"><?= $tot ?></td>
                                    <td style="text-align:right;font-weight:800;color:<?= $avail > 0 ? '#16a34a' : '#dc2626' ?>;">
                                        <?= $avail ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($avail > 0): ?>
                                            <span class="badge" style="background:#d1fae5;color:#065f46;">In Library</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#fee2e2;color:#991b1b;">All Issued</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;gap:.35rem;justify-content:center;">
                                            <?php if ($avail > 0): ?>
                                                <a href="library-circulation.php?book_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-soft" title="Quick Issue">
                                                    ⚡ Issue
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline" onclick='editBook(<?= json_encode($b) ?>)'>
                                                Edit
                                            </button>
                                            <?php if ($canDelete || $isOwner): ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Archive book <?= e(addslashes($b['title'])) ?>?');">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="archive_book">
                                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Archive">✕</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ ADD / EDIT BOOK MODAL ═══════════════════ -->
<div id="bookModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:640px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="bookModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Add New Book to Library</h2>
            <button type="button" class="icon-btn" onclick="closeModal('bookModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="bookAction" value="create_book">
            <input type="hidden" name="id" id="bookId" value="">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Book Title *</label>
                <input type="text" name="title" id="bookTitle" required placeholder="e.g. Discovery of India" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Author(s) *</label>
                    <input type="text" name="author" id="bookAuthor" required placeholder="e.g. Jawaharlal Nehru" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Publisher</label>
                    <input type="text" name="publisher" id="bookPublisher" placeholder="e.g. Oxford Press" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Category</label>
                    <select name="category" id="bookCategory" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                        <option value="General">General</option>
                        <option value="Fiction">Fiction</option>
                        <option value="Science">Science</option>
                        <option value="Mathematics">Mathematics</option>
                        <option value="History">History & Civics</option>
                        <option value="Geography">Geography</option>
                        <option value="Language">Language & Literature</option>
                        <option value="Reference">Reference / Encyclopedia</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Language</label>
                    <select name="language" id="bookLanguage" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                        <option value="English">English</option>
                        <option value="Hindi">Hindi</option>
                        <option value="Sanskrit">Sanskrit</option>
                        <option value="Regional">Regional</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Rack / Shelf</label>
                    <input type="text" name="rack_location" id="bookRack" value="Rack A-01" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;" id="copiesRow">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Initial Physical Copies to Add</label>
                    <input type="number" name="num_copies" id="bookCopies" min="1" value="3" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Purchase Cost per Copy (₹)</label>
                    <input type="number" step="0.01" name="cost" id="bookCost" value="250.00" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">ISBN / Edition</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <input type="text" name="isbn" id="bookIsbn" placeholder="e.g. 978-0199106875" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                    <input type="text" name="edition" id="bookEdition" placeholder="e.g. 2nd Revised Edition" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('bookModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" id="bookBtn">Save Book</button>
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
function openAddBookModal() {
    document.getElementById('bookAction').value = 'create_book';
    document.getElementById('bookId').value = '';
    document.getElementById('bookTitle').value = '';
    document.getElementById('bookAuthor').value = '';
    document.getElementById('bookPublisher').value = '';
    document.getElementById('bookIsbn').value = '';
    document.getElementById('bookEdition').value = '';
    document.getElementById('bookCopies').value = '3';
    document.getElementById('bookCost').value = '250.00';
    var cpRow = document.getElementById('copiesRow');
    if (cpRow) cpRow.style.display = 'grid';
    document.getElementById('bookModalTitle').textContent = 'Add New Book to Library';
    document.getElementById('bookBtn').textContent = 'Save Book & Generate Accession Copies';
    openModal('bookModal');
}
function editBook(b) {
    document.getElementById('bookAction').value = 'update_book';
    document.getElementById('bookId').value = b.id;
    document.getElementById('bookTitle').value = b.title;
    document.getElementById('bookAuthor').value = b.author;
    document.getElementById('bookPublisher').value = b.publisher || '';
    document.getElementById('bookCategory').value = b.category || 'General';
    document.getElementById('bookLanguage').value = b.language || 'English';
    document.getElementById('bookRack').value = b.rack_location || '';
    document.getElementById('bookIsbn').value = b.isbn || '';
    document.getElementById('bookEdition').value = b.edition || '';
    var cpRow = document.getElementById('copiesRow');
    if (cpRow) cpRow.style.display = 'none';
    document.getElementById('bookModalTitle').textContent = 'Edit Book: ' + b.title;
    document.getElementById('bookBtn').textContent = 'Update Book Details';
    openModal('bookModal');
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
