<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=siba_erp;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Check if application_no column exists
$r = $pdo->query("SHOW COLUMNS FROM applications LIKE 'application_no'");
$col = $r->fetch();
if ($col) {
    echo "application_no column already exists\n";
} else {
    $pdo->exec("ALTER TABLE applications ADD COLUMN application_no VARCHAR(30) NULL AFTER id");
    echo "Added application_no column\n";
}

// Also check if payment_status column exists
$r = $pdo->query("SHOW COLUMNS FROM applications LIKE 'payment_status'");
$col = $r->fetch();
if ($col) {
    echo "payment_status column already exists\n";
} else {
    $pdo->exec("ALTER TABLE applications ADD COLUMN payment_status ENUM('Pending','Paid') DEFAULT 'Pending' AFTER application_no");
    echo "Added payment_status column\n";
}

// Also check if payment_method column exists
$r = $pdo->query("SHOW COLUMNS FROM applications LIKE 'payment_method'");
$col = $r->fetch();
if ($col) {
    echo "payment_method column already exists\n";
} else {
    $pdo->exec("ALTER TABLE applications ADD COLUMN payment_method VARCHAR(20) DEFAULT 'Online' AFTER payment_status");
    echo "Added payment_method column\n";
}

echo "Done.\n";
