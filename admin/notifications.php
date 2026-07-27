<?php
$pageTitle = "Notifications";
require_once('../includes/db_connect.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

$success = '';
$error   = '';

// Ensure notifications table
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    sent_to ENUM('All Parents','Admitted','Under Review') DEFAULT 'All Parents',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Send notification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_notif'])) {
    $title   = $conn->real_escape_string(trim($_POST['title']));
    $message = $conn->real_escape_string(trim($_POST['message']));
    $sent_to = $conn->real_escape_string($_POST['sent_to']);
    if ($title && $message) {
        $conn->query("INSERT INTO notifications (title, message, sent_to) VALUES ('$title','$message','$sent_to')");
        $success = "Notification logged. In production, this would send SMS/WhatsApp/Email to all matching parents.";
    } else {
        $error = "Title and message are required.";
    }
}

$notifList   = $conn->query("SELECT * FROM notifications ORDER BY sent_at DESC");
$total_notif = $conn->query("SELECT COUNT(*) FROM notifications")->fetch_row()[0];
$total_par   = $conn->query("SELECT COUNT(*) FROM parents")->fetch_row()[0];
?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem;"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem;"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<div class="admin-stats-grid" style="grid-template-columns: repeat(3,1fr); margin-bottom:1.5rem;">
    <div class="admin-stat-card blue">
        <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
        <div><div class="stat-num"><?php echo $total_notif; ?></div><div class="stat-lbl">Notifications Sent</div></div>
    </div>
    <div class="admin-stat-card green">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div><div class="stat-num"><?php echo $total_par; ?></div><div class="stat-lbl">Registered Parents</div></div>
    </div>
    <div class="admin-stat-card amber">
        <div class="stat-icon"><i class="fas fa-mobile-alt"></i></div>
        <div><div class="stat-num">SMS</div><div class="stat-lbl">Channel (Demo)</div></div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1.4fr; gap:1.5rem;">

    <!-- Compose -->
    <div class="admin-panel">
        <div class="admin-panel-header"><h3><i class="fas fa-paper-plane" style="color:var(--secondary-color)"></i> &nbsp;Send Notification</h3></div>
        <div class="admin-panel-body">
            <div class="alert alert-info" style="margin-bottom:1.25rem;">
                <i class="fas fa-info-circle"></i> In production, this sends SMS/WhatsApp & email via an SMS gateway (e.g., Twilio / MSG91).
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Notification Title *</label>
                    <input type="text" name="title" placeholder="e.g. Exam Schedule Update" required>
                </div>
                <div class="form-group">
                    <label>Send To</label>
                    <select name="sent_to">
                        <option value="All Parents">All Registered Parents</option>
                        <option value="Admitted">Admitted Students' Parents Only</option>
                        <option value="Under Review">Applications Under Review Only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Message *</label>
                    <textarea name="message" rows="6" placeholder="Type your notification message here..." required></textarea>
                </div>
                <button type="submit" name="send_notif" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-paper-plane"></i> Send Notification
                </button>
            </form>
        </div>
    </div>

    <!-- History -->
    <div class="admin-panel">
        <div class="admin-panel-header"><h3><i class="fas fa-history" style="color:var(--secondary-color)"></i> &nbsp;Notification History</h3></div>
        <div class="admin-panel-table">
            <table class="admin-table">
                <thead><tr><th>Title</th><th>Sent To</th><th>Sent At</th></tr></thead>
                <tbody>
                <?php if ($notifList->num_rows == 0): ?>
                    <tr><td colspan="3" style="text-align:center; color:var(--text-light); padding:2rem;">No notifications sent yet.</td></tr>
                <?php else: while ($n = $notifList->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($n['title']); ?></strong>
                            <br><small style="color:var(--text-light);"><?php echo htmlspecialchars(substr($n['message'], 0, 80)) . '...'; ?></small>
                        </td>
                        <td><span class="badge badge-review"><?php echo $n['sent_to']; ?></span></td>
                        <td style="color:var(--text-light); font-size:0.82rem;"><?php echo date('d M Y, h:i A', strtotime($n['sent_at'])); ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
