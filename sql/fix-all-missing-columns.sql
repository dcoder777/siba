-- Fix all mismatched columns in finance module tables.
-- Run this ONCE. Ignore "Duplicate column" errors for columns that already exist.

-- ═══════════════════════════════════════
-- 1. VENDORS – add all missing columns
-- ═══════════════════════════════════════
ALTER TABLE vendors ADD COLUMN vendor_code VARCHAR(50) DEFAULT NULL AFTER id;
ALTER TABLE vendors ADD COLUMN gst_number VARCHAR(30) DEFAULT NULL AFTER email;
ALTER TABLE vendors ADD COLUMN pan VARCHAR(20) DEFAULT NULL AFTER gst_number;
ALTER TABLE vendors ADD COLUMN address TEXT DEFAULT NULL AFTER pan;
ALTER TABLE vendors ADD COLUMN bank_name VARCHAR(150) DEFAULT NULL AFTER address;
ALTER TABLE vendors ADD COLUMN account_number VARCHAR(50) DEFAULT NULL AFTER bank_name;
ALTER TABLE vendors ADD COLUMN ifsc_code VARCHAR(30) DEFAULT NULL AFTER account_number;
ALTER TABLE vendors ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER ifsc_code;
ALTER TABLE vendors ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active;
ALTER TABLE vendors ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ═══════════════════════════════════════
-- 2. BANK_ACCOUNTS – rename & add columns
-- ═══════════════════════════════════════
ALTER TABLE bank_accounts CHANGE account_no account_number VARCHAR(50) NOT NULL;
ALTER TABLE bank_accounts ADD COLUMN account_type ENUM('Savings','Current') NOT NULL DEFAULT 'Current' AFTER branch;
ALTER TABLE bank_accounts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ═══════════════════════════════════════
-- 3. EXPENSES – add all missing columns
-- ═══════════════════════════════════════
ALTER TABLE expenses ADD COLUMN expense_no VARCHAR(50) NOT NULL AFTER id;
ALTER TABLE expenses ADD COLUMN expense_date DATE NOT NULL AFTER expense_no;
ALTER TABLE expenses ADD COLUMN category_name VARCHAR(100) DEFAULT NULL AFTER category_id;
ALTER TABLE expenses ADD COLUMN vendor_id INT UNSIGNED DEFAULT NULL AFTER category_name;
ALTER TABLE expenses ADD COLUMN payment_id VARCHAR(150) DEFAULT NULL AFTER payment_date;
ALTER TABLE expenses ADD COLUMN transaction_id VARCHAR(150) DEFAULT NULL AFTER payment_id;
ALTER TABLE expenses ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER approved_at;
ALTER TABLE expenses MODIFY COLUMN status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending';
ALTER TABLE expenses ADD UNIQUE KEY uk_expense_no (expense_no);
ALTER TABLE expenses ADD INDEX idx_exp_category (category_id);
ALTER TABLE expenses ADD INDEX idx_exp_vendor (vendor_id);
ALTER TABLE expenses ADD INDEX idx_exp_status (status);
ALTER TABLE expenses ADD INDEX idx_exp_date (expense_date);

-- ═══════════════════════════════════════
-- 4. INCOME_CATEGORIES – add all missing columns
-- ═══════════════════════════════════════
ALTER TABLE income_categories ADD COLUMN income_no VARCHAR(50) DEFAULT NULL AFTER id;
ALTER TABLE income_categories ADD COLUMN income_date DATE DEFAULT NULL AFTER income_no;
ALTER TABLE income_categories ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER description;
ALTER TABLE income_categories ADD COLUMN payment_mode VARCHAR(50) DEFAULT NULL AFTER amount;
ALTER TABLE income_categories ADD COLUMN payment_id VARCHAR(150) DEFAULT NULL AFTER payment_mode;
ALTER TABLE income_categories ADD COLUMN status VARCHAR(20) DEFAULT 'Pending' AFTER payment_id;
ALTER TABLE income_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
ALTER TABLE income_categories ADD COLUMN created_by INT UNSIGNED DEFAULT NULL AFTER is_active;
ALTER TABLE income_categories ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
