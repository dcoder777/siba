<?php
$pageTitle = "Manage Website Content";
require_once('../includes/db_connect.php');
require_once('../includes/cms.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

function cmsFieldLabel($key)
{
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords($key);
}

function cmsRenderField($name, $value, $key = '', $level = 0)
{
    $label = $key !== '' ? cmsFieldLabel($key) : 'Field';

    if (is_array($value)) {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);

        if ($isAssoc) {
            echo '<div class="cms-group" style="border:1px solid #e5e7eb; border-radius:10px; padding:1rem; margin-bottom:1rem; background:#fff;">';
            echo '<div style="font-weight:700; color:var(--primary-color); margin-bottom:0.85rem; font-size:0.92rem;">' . htmlspecialchars($label) . '</div>';
            foreach ($value as $childKey => $childVal) {
                cmsRenderField($name . '[' . $childKey . ']', $childVal, (string)$childKey, $level + 1);
            }
            echo '</div>';
            return;
        }

        echo '<div class="cms-group" style="border:1px solid #e5e7eb; border-radius:10px; padding:1rem; margin-bottom:1rem; background:#fff;">';
        echo '<div style="font-weight:700; color:var(--primary-color); margin-bottom:0.85rem; font-size:0.92rem;">' . htmlspecialchars($label) . '</div>';
        foreach ($value as $idx => $childVal) {
            echo '<div style="border:1px dashed #d1d5db; border-radius:8px; padding:0.85rem; margin-bottom:0.75rem; background:#fafafa;">';
            echo '<div style="font-size:0.78rem; color:var(--text-light); margin-bottom:0.6rem; font-weight:600;">Item ' . ((int)$idx + 1) . '</div>';
            cmsRenderField($name . '[' . $idx . ']', $childVal, is_array($childVal) ? 'Details' : ('Value ' . ((int)$idx + 1)), $level + 1);
            echo '</div>';
        }
        echo '</div>';
        return;
    }

    $str = (string)$value;
    echo '<div class="form-group" style="margin-bottom:0.9rem;">';
    echo '<label style="font-size:0.82rem;">' . htmlspecialchars($label) . '</label>';

    $isLong = strlen($str) > 120 || strpos($str, "\n") !== false;
    if ($isLong) {
        echo '<textarea name="' . htmlspecialchars($name) . '" rows="3">' . htmlspecialchars($str) . '</textarea>';
    } else {
        echo '<input type="text" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($str) . '">';
    }
    echo '</div>';
}

$allPages = cmsAllPages();
$selectedSlug = $_GET['page'] ?? 'index';
if (!isset($allPages[$selectedSlug])) {
    $selectedSlug = 'index';
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_content'])) {
    $selectedSlug = $_POST['page_slug'] ?? 'index';
    if (!isset($allPages[$selectedSlug])) {
        $selectedSlug = 'index';
    }

    $pageTitleInput = trim($_POST['page_title'] ?? '');
    $decoded = $_POST['data'] ?? [];

    if ($pageTitleInput === '') {
        $error = 'Page title is required.';
    } elseif (!is_array($decoded)) {
        $error = 'Invalid form payload. Please retry.';
    } else {
        if (cmsSavePage($conn, $selectedSlug, $pageTitleInput, $decoded)) {
            $success = 'Content saved successfully.';
        } else {
            $error = 'Could not save content. Please try again.';
        }
    }
}

$page = cmsGetPage($conn, $selectedSlug);
?>

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="admin-panel">
    <div class="admin-panel-header">
        <h3><i class="fas fa-file-code" style="color:var(--secondary-color)"></i> &nbsp;Website CMS</h3>
        <form method="GET" style="display:flex; gap:0.75rem; align-items:center;">
            <label for="page" style="font-size:0.85rem; color:var(--text-light);">Select Page</label>
            <select id="page" name="page" onchange="this.form.submit()" style="padding:0.5rem 0.75rem; border:1.5px solid #d1d5db; border-radius:8px;">
                <?php foreach ($allPages as $slug => $meta): ?>
                    <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $slug === $selectedSlug ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($meta['label']); ?> (<?php echo htmlspecialchars($slug); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="admin-panel-body">
        <div class="alert alert-info" style="margin-bottom:1.25rem;">
            <i class="fas fa-info-circle"></i>
            Edit content directly in fields below. No JSON editing needed.
        </div>
        <form method="POST">
            <input type="hidden" name="page_slug" value="<?php echo htmlspecialchars($selectedSlug); ?>">
            <div class="form-group">
                <label>Browser/Page Title</label>
                <input type="text" name="page_title" value="<?php echo htmlspecialchars($page['title']); ?>" required>
            </div>
            <div class="form-group">
                <label>Page Content</label>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem; max-height:70vh; overflow:auto;">
                    <?php cmsRenderField('data', $page['data'], 'Content'); ?>
                </div>
            </div>
            <button type="submit" name="save_content" class="btn btn-primary">
                <i class="fas fa-save"></i> Save CMS Content
            </button>
            <a href="<?php echo SITE_URL . '/' . $selectedSlug . '.php'; ?>" class="btn btn-outline-primary" target="_blank" style="margin-left:0.75rem;">
                <i class="fas fa-external-link-alt"></i> Open Page
            </a>
        </form>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
