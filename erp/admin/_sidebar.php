<?php
/** Shared admin sidebar — include after admin_user() is available. */
$activePage = $activePage ?? '';
$isOwner = $isOwner ?? (($user['role'] ?? '') === 'owner');
?>
<aside class="sidebar" style="display:flex;flex-direction:column;">
    <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
        <span class="brand-mark">S</span>
        <span class="eyebrow">SIBA ERP</span>
        <div class="brand-copy">
            <h2>Administration</h2>
            <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
        </div>
        <div class="sidebar-controls">
            <button type="button" class="btn btn-soft sidebar-toggle" id="sidebarToggle" title="Collapse menu">
                <span class="sidebar-icon">≡</span>
            </button>
            <button type="button" class="btn btn-soft sidebar-toggle theme-toggle" id="themeToggle" title="Toggle theme">
                <span class="sidebar-icon" id="themeToggleIcon">D</span>
            </button>
        </div>
    </div>

    <div class="nav-group">
        <div class="nav-title">Admissions</div>
        <a class="nav-link<?= $activePage === 'application-intake.php' ? ' active' : '' ?>" href="application-intake.php">
            <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
        </a>
        <a class="nav-link<?= $activePage === 'applications-list.php' ? ' active' : '' ?>" href="applications-list.php">
            <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag">List</span>
        </a>
        <a class="nav-link<?= $activePage === 'parents-list.php' ? ' active' : '' ?>" href="parents-list.php">
            <span class="sidebar-icon">👤</span><span>Parents</span>
        </a>
        <a class="nav-link<?= $activePage === 'events-manager.php' ? ' active' : '' ?>" href="events-manager.php">
            <span class="sidebar-icon">📅</span><span>Events & News</span>
        </a>
        <a class="nav-link<?= $activePage === 'gallery-manager.php' ? ' active' : '' ?>" href="gallery-manager.php">
            <span class="sidebar-icon">🖼</span><span>Gallery</span>
        </a>
        <a class="nav-link<?= $activePage === 'enquiries.php' ? ' active' : '' ?>" href="enquiries.php">
            <span class="sidebar-icon">📩</span><span>Enquiries</span>
        </a>
    </div>

    <div class="nav-group">
        <div class="nav-title">Accounts</div>
        <a class="nav-link<?= $activePage === 'finance-dashboard.php' ? ' active' : '' ?>" href="finance-dashboard.php">
            <span class="sidebar-icon">📊</span><span>Dashboard</span>
        </a>
        <a class="nav-link<?= $activePage === 'masters.php' ? ' active' : '' ?>" href="masters.php">
            <span class="sidebar-icon">⚙</span><span>Masters</span>
        </a>
        <a class="nav-link<?= in_array($activePage, ['expense-entry.php','vendor-bills.php']) ? ' active' : '' ?>" href="expense-entry.php">
            <span class="sidebar-icon">📤</span><span>Expenses</span>
        </a>
        <a class="nav-link<?= $activePage === 'vendors.php' ? ' active' : '' ?>" href="vendors.php">
            <span class="sidebar-icon">🤝</span><span>Vendors</span>
        </a>
        <a class="nav-link<?= $activePage === 'income-entry.php' ? ' active' : '' ?>" href="income-entry.php">
            <span class="sidebar-icon">💰</span><span>Income</span>
        </a>
        <a class="nav-link<?= $activePage === 'cash-bank.php' ? ' active' : '' ?>" href="cash-bank.php">
            <span class="sidebar-icon">🏦</span><span>Cash & Bank</span>
        </a>
    </div>

    <?php if ($isOwner): ?>
    <div class="nav-group">
        <div class="nav-title">HR</div>
        <a class="nav-link<?= $activePage === 'salary-setup.php' ? ' active' : '' ?>" href="salary-setup.php">
            <span class="sidebar-icon">👥</span><span>Payroll</span>
        </a>
    </div>

    <div class="nav-group">
        <div class="nav-title">Reports</div>
        <a class="nav-link<?= $activePage === 'reports-new.php' ? ' active' : '' ?>" href="reports-new.php">
            <span class="sidebar-icon">📈</span><span>Reports</span>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
    <div class="nav-group">
        <div class="nav-title">Administration</div>
        <?php $pendingAdminCount = 0; try { $pendingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $e) {} ?>
        <a class="nav-link<?= $activePage === 'admin-requests.php' ? ' active' : '' ?>" href="admin-requests.php">
            <span class="sidebar-icon">🔑</span>
            <span>Admin Requests</span>
            <?php if ($pendingAdminCount > 0): ?>
                <span class="nav-tag" style="background:#f59e0b;color:#fff;"><?= $pendingAdminCount ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-link<?= $activePage === 'user-management.php' ? ' active' : '' ?>" href="user-management.php">
            <span class="sidebar-icon">🛡</span>
            <span>User Management</span>
        </a>
    </div>
    <?php endif; ?>

    <div class="nav-group" style="margin-top:auto;">
        <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
    </div>
</aside>
