<?php
declare(strict_types=1);

/**
 * Resolve application fee amount from fee_heads (category = Application Fee).
 * Falls back to APPLICATION_FEE constant or 200.
 *
 * @param PDO|mysqli|null $db
 */
function get_application_fee_amount($db = null): float
{
    $fallback = defined('APPLICATION_FEE') ? (float) APPLICATION_FEE : 200.0;
    if ($db === null) {
        return $fallback;
    }
    try {
        if ($db instanceof PDO) {
            $stmt = $db->query("SELECT default_amount FROM fee_heads WHERE category = 'Application Fee' AND is_active = 1 ORDER BY id ASC LIMIT 1");
            $val = $stmt ? $stmt->fetchColumn() : false;
            if ($val !== false && $val !== null && (float) $val > 0) {
                return (float) $val;
            }
        } elseif ($db instanceof mysqli) {
            $res = $db->query("SELECT default_amount FROM fee_heads WHERE category = 'Application Fee' AND is_active = 1 ORDER BY id ASC LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) {
                $amt = (float) ($row['default_amount'] ?? 0);
                if ($amt > 0) {
                    return $amt;
                }
            }
        }
    } catch (Throwable $e) {
        // fall through
    }
    return $fallback;
}

/**
 * Ensure applications.payment_amount column exists.
 *
 * @param PDO|mysqli $db
 */
function ensure_application_payment_amount_column($db): void
{
    try {
        if ($db instanceof PDO) {
            $cols = $db->query("SHOW COLUMNS FROM applications LIKE 'payment_amount'")->fetchAll();
            if (empty($cols)) {
                $db->exec("ALTER TABLE applications ADD COLUMN payment_amount DECIMAL(12,2) NOT NULL DEFAULT 200.00");
            }
        } elseif ($db instanceof mysqli) {
            $res = $db->query("SHOW COLUMNS FROM applications LIKE 'payment_amount'");
            if ($res && $res->num_rows === 0) {
                $db->query("ALTER TABLE applications ADD COLUMN payment_amount DECIMAL(12,2) NOT NULL DEFAULT 200.00");
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}
