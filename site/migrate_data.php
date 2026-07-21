<?php
/**
 * One-time migration script: move all site data from siba_school to siba_erp.
 *
 * Prerequisites:
 *   1. Run erp/database/schema.sql to set up ERP tables in siba_erp
 *   2. Run site/sql/unify.sql to create site tables in siba_erp
 *   3. Update site/includes/config.php to point to siba_erp
 *
 * Usage: php migrate_data.php  (or serve via web and visit this page)
 * Run this BEFORE pointing the site config to siba_erp.
 */

set_time_limit(300);
ini_set('display_errors', 1);
error_reporting(E_ALL);

$src = new mysqli('localhost', 'root', '', 'siba_school');
if ($src->connect_error) die("Source connection failed: " . $src->connect_error);
$src->set_charset('utf8mb4');

$dst = new mysqli('localhost', 'root', '', 'siba_erp');
if ($dst->connect_error) die("Destination connection failed: " . $dst->connect_error);
$dst->set_charset('utf8mb4');

echo "<pre>\n";
echo "Starting data migration from siba_school → siba_erp\n\n";

function esc(mysqli $db, $val): string {
    return $val === null ? 'NULL' : "'" . $db->real_escape_string((string)$val) . "'";
}
function log_msg(string $msg): void { echo "[MIGRATE] $msg\n"; }

// ─── 1. Copy admins → link to ERP users ───
log_msg("--- Migrating admins ---");
$admin_role_id = $dst->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetch_assoc()['id'];
$admins = $src->query("SELECT * FROM admins");
while ($a = $admins->fetch_assoc()) {
    $email = $a['username'] . '@siba.local';
    // Insert into destination admins (preserve ID)
    $dst->query("INSERT INTO admins (id, username, password, role)
        VALUES ({$a['id']}, " . esc($dst, $a['username']) . ", " . esc($dst, $a['password']) . ", " . esc($dst, $a['role']) . ")");
    // Create ERP user
    $dst->query("INSERT INTO users (role_id, name, email, password_hash, is_active)
        VALUES ($admin_role_id, " . esc($dst, $a['username']) . ", " . esc($dst, $email) . ", " . esc($dst, $a['password']) . ", 1)");
    $user_id = $dst->insert_id;
    $dst->query("UPDATE admins SET user_id = $user_id WHERE id = {$a['id']}");
    log_msg("Admin '{$a['username']}' → user #$user_id");
}

// ─── 2. Copy parents → link to ERP users ───
log_msg("--- Migrating parents ---");
$parent_role_id = $dst->query("SELECT id FROM roles WHERE name = 'parent' LIMIT 1")->fetch_assoc()['id'];
$parents = $src->query("SELECT * FROM parents");
while ($p = $parents->fetch_assoc()) {
    $email = $p['email'] ?: 'parent_' . $p['phone'] . '@siba.local';
    $name = $p['name'] ?: ('Parent ' . $p['phone']);
    // Insert into destination parents (preserve ID)
    $dst->query("INSERT INTO parents (id, name, email, phone, password, created_at)
        VALUES ({$p['id']}, " . esc($dst, $p['name']) . ", " . esc($dst, $p['email']) . ",
                " . esc($dst, $p['phone']) . ", " . esc($dst, $p['password']) . ",
                " . esc($dst, $p['created_at']) . ")");
    // Create ERP user
    $dst->query("INSERT INTO users (role_id, name, email, password_hash, is_active)
        VALUES ($parent_role_id, " . esc($dst, $name) . ", " . esc($dst, $email) . ", " . esc($dst, $p['password']) . ", 1)");
    $user_id = $dst->insert_id;
    $dst->query("UPDATE parents SET user_id = $user_id WHERE id = {$p['id']}");
    log_msg("Parent {$p['phone']} → user #$user_id");
}

// ─── 3. Copy staff → link to ERP employees ───
log_msg("--- Migrating staff ---");
$staff_list = $src->query("SELECT * FROM staff");
while ($s = $staff_list->fetch_assoc()) {
    // Insert into destination staff (preserve ID)
    $dst->query("INSERT INTO staff (id, name, designation, department, phone, email, status, created_at)
        VALUES ({$s['id']}, " . esc($dst, $s['name']) . ", " . esc($dst, $s['designation']) . ",
                " . esc($dst, $s['department']) . ", " . esc($dst, $s['phone']) . ",
                " . esc($dst, $s['email']) . ", " . esc($dst, $s['status']) . ",
                " . esc($dst, $s['created_at']) . ")");
    // Create ERP employee
    $emp_code = 'EMP-' . str_pad($s['id'], 4, '0', STR_PAD_LEFT);
    $dept = $s['department'] ?: 'General';
    $desig = $s['designation'] ?: 'Staff';
    $st = strtolower($s['status']);
    $dst->query("INSERT INTO employees (employee_code, name, department, designation, status)
        VALUES (" . esc($dst, $emp_code) . ", " . esc($dst, $s['name']) . ",
                " . esc($dst, $dept) . ", " . esc($dst, $desig) . ", " . esc($dst, $st) . ")");
    $emp_id = $dst->insert_id;
    $dst->query("UPDATE staff SET employee_id = $emp_id WHERE id = {$s['id']}");
    log_msg("Staff '{$s['name']}' → employee #$emp_id");
}

// ─── 4. Copy applications → link admitted ones to ERP students ───
log_msg("--- Migrating applications ---");
$apps = $src->query("SELECT * FROM applications");
$admitted_count = 0;
while ($app = $apps->fetch_assoc()) {
    // Insert into destination applications (preserve ID)
    $cols = ['id','parent_id','student_name','first_name','middle_name','last_name','dob','gender','religion',
             'blood_group','aadhaar_no','previous_school','previous_class','class_sought','address_line1',
             'address_line2','post_office','police_station','district','village_city','pin','state','country',
             'father_name','father_occupation','mother_name','mother_occupation','guardian_name',
             'guardian_occupation','family_annual_income','contact_no','email','address','birth_cert','aadhaar',
             'leaving_cert','prev_marksheet','photo','status','applied_at'];
    $values = [];
    foreach ($cols as $c) {
        $values[] = esc($dst, $app[$c]);
    }
    $dst->query("INSERT INTO applications (" . implode(',', $cols) . ") VALUES (" . implode(',', $values) . ")");

    // If admitted, create ERP student + enrollment
    if ($app['status'] === 'Admitted' && empty($app['student_id'])) {
        $name_parts = explode(' ', trim($app['student_name']), 2);
        $first_name = $name_parts[0] ?: $app['student_name'];
        $last_name  = $name_parts[1] ?? '';
        $cnt = $dst->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];
        $admission_no = sprintf("ADM%04d", $cnt + 1);
        $addr_parts = array_filter([$app['address_line1'], $app['address_line2'],
            $app['village_city'] ?? $app['district'], $app['state'], $app['pin']]);
        $dst->query("INSERT INTO students (admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address)
            VALUES (" . esc($dst, $admission_no) . ", " . esc($dst, $first_name) . ", " . esc($dst, $last_name) . ",
                    " . esc($dst, $app['gender']) . ", " . esc($dst, $app['dob']) . ",
                    " . esc($dst, $app['blood_group']) . ", " . esc($dst, $app['contact_no']) . ",
                    " . esc($dst, $app['email']) . ", " . esc($dst, implode(', ', $addr_parts)) . ")");
        $student_id = $dst->insert_id;
        $ss = $dst->query("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
        $session = $ss && $ss->num_rows ? $ss->fetch_assoc()['setting_value'] : (date('Y') . '-' . (date('Y') + 1));
        $dst->query("INSERT INTO student_enrollments (student_id, class_name, session_label, status, is_current)
            VALUES ($student_id, " . esc($dst, $app['class_sought']) . ", " . esc($dst, $session) . ", 'active', 1)");
        $dst->query("UPDATE applications SET student_id = $student_id, admission_no = " . esc($dst, $admission_no) . " WHERE id = {$app['id']}");
        $admitted_count++;
        log_msg("App #{$app['id']} ({$app['student_name']}) → student #$student_id ($admission_no)");
    }
}

// ─── 5. Copy fees ───
log_msg("--- Migrating fees ---");
$fees = $src->query("SELECT * FROM fees");
$fee_count = 0;
while ($f = $fees->fetch_assoc()) {
    $dst->query("INSERT INTO fees (id, application_id, fee_type, month, year, amount, razorpay_order_id, razorpay_payment_id, razorpay_signature, status, paid_at)
        VALUES ({$f['id']}, {$f['application_id']}, " . esc($dst, $f['fee_type']) . ",
                " . esc($dst, $f['month']) . ", " . esc($dst, $f['year']) . ",
                {$f['amount']}, " . esc($dst, $f['razorpay_order_id']) . ",
                " . esc($dst, $f['razorpay_payment_id']) . ", " . esc($dst, $f['razorpay_signature']) . ",
                " . esc($dst, $f['status']) . ", " . esc($dst, $f['paid_at']) . ")");
    $fee_count++;
}
log_msg("Fees copied: $fee_count");

// ─── 6. Copy remaining tables ───
foreach (['cms_pages', 'settings', 'notifications'] as $tbl) {
    $count = $dst->query("SELECT COUNT(*) AS c FROM $tbl")->fetch_assoc()['c'];
    if ($count == 0) {
        $src->query("INSERT INTO siba_erp.$tbl SELECT * FROM siba_school.$tbl");
        $copied = $src->affected_rows;
        log_msg("Copied $copied records into '$tbl'");
    } else {
        log_msg("Table '$tbl' already has $count records, skipping");
    }
}

echo "\n✅ Migration complete! Admitted apps converted to students: $admitted_count\n";
echo "</pre>\n";
$src->close();
$dst->close();
