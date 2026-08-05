<?php
/**
 * ERP Accounts Module — Full Database Migration
 * Run once: php erp/database/migrate_accounts.php
 * Creates all tables for the 20-module Accounts system.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use core\Database;

$pdo = Database::connection($config['db']);

echo "Starting Accounts Module migration...\n";

$tables = [

// ============================================================
// MODULE 1: MASTERS
// ============================================================

"CREATE TABLE IF NOT EXISTS `schools` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `school_name` VARCHAR(200) NOT NULL,
    `school_code` VARCHAR(50) DEFAULT NULL,
    `branch_name` VARCHAR(200) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `contact_number` VARCHAR(30) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `pan` VARCHAR(20) DEFAULT NULL,
    `tan` VARCHAR(20) DEFAULT NULL,
    `gst_number` VARCHAR(30) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `financial_years` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(50) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `academic_years` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(50) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('Active','Closed') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fee_heads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `category` VARCHAR(50) DEFAULT NULL,
    `class_name` VARCHAR(100) DEFAULT NULL,
    `default_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `frequency` ENUM('Monthly','Annual','One-Time','Quarterly') NOT NULL DEFAULT 'Monthly',
    `is_refundable` TINYINT(1) NOT NULL DEFAULT 0,
    `late_fee_applicable` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `expense_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `group_name` VARCHAR(100) DEFAULT NULL,
    `vendor_id` INT UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `approval_required` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `income_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `income_no` VARCHAR(50) NULL,
    `income_date` DATE NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_mode` VARCHAR(50) NULL,
    `payment_id` VARCHAR(150) NULL,
    `status` VARCHAR(30) DEFAULT 'Pending',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `approved_by` INT NULL,
    `approved_at` DATETIME NULL,
    `reject_reason` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `vendors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `vendor_code` VARCHAR(50) DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `mobile` VARCHAR(30) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `gst_number` VARCHAR(30) DEFAULT NULL,
    `pan` VARCHAR(20) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `bank_name` VARCHAR(150) DEFAULT NULL,
    `account_number` VARCHAR(50) DEFAULT NULL,
    `ifsc_code` VARCHAR(30) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `bank_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_name` VARCHAR(150) NOT NULL,
    `account_name` VARCHAR(200) NOT NULL,
    `account_number` VARCHAR(50) NOT NULL,
    `ifsc_code` VARCHAR(30) DEFAULT NULL,
    `branch` VARCHAR(150) DEFAULT NULL,
    `account_type` ENUM('Savings','Current') NOT NULL DEFAULT 'Current',
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `payment_modes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `is_online` TINYINT(1) NOT NULL DEFAULT 0,
    `transaction_id_required` TINYINT(1) NOT NULL DEFAULT 0,
    `bank_details_required` TINYINT(1) NOT NULL DEFAULT 0,
    `cheque_details_required` TINYINT(1) NOT NULL DEFAULT 0,
    `emi_allowed` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 2: FEE STRUCTURE
// ============================================================

"CREATE TABLE IF NOT EXISTS `fee_structures` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `academic_session` VARCHAR(50) NOT NULL,
    `class_name` VARCHAR(50) NOT NULL,
    `student_category` VARCHAR(50) NOT NULL DEFAULT 'Regular',
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `emi_allowed` TINYINT(1) NOT NULL DEFAULT 0,
    `num_installments` INT NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fee_structure_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_structure_id` INT UNSIGNED NOT NULL,
    `fee_head_id` INT UNSIGNED NOT NULL,
    `fee_head_name` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `frequency` ENUM('Monthly','Annual','One-Time','Quarterly') NOT NULL DEFAULT 'Monthly',
    `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_fsi_structure` (`fee_structure_id`),
    KEY `idx_fsi_head` (`fee_head_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fee_structure_installments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_structure_id` INT UNSIGNED NOT NULL,
    `installment_no` INT NOT NULL,
    `title` VARCHAR(100) DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee_type` ENUM('None','Fixed','Percentage') NOT NULL DEFAULT 'None',
    `late_fee_value` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee_grace_days` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_fsi_inst_structure` (`fee_structure_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fee_structure_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_structure_id` INT UNSIGNED NOT NULL,
    `assign_type` ENUM('Class','Section','Individual') NOT NULL DEFAULT 'Class',
    `assign_value` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_fsa_structure` (`fee_structure_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 3: STUDENT FEE ASSIGNMENT
// ============================================================

"CREATE TABLE IF NOT EXISTS `student_fee_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `admission_no` VARCHAR(50) DEFAULT NULL,
    `class_name` VARCHAR(50) NOT NULL,
    `section_name` VARCHAR(50) DEFAULT NULL,
    `fee_structure_id` INT UNSIGNED NOT NULL,
    `transport_required` TINYINT(1) NOT NULL DEFAULT 0,
    `hostel_required` TINYINT(1) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `emi_plan` VARCHAR(50) DEFAULT NULL,
    `effective_date` DATE DEFAULT NULL,
    `academic_session` VARCHAR(50) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_sfa_student` (`student_id`),
    KEY `idx_sfa_structure` (`fee_structure_id`),
    KEY `idx_sfa_session` (`academic_session`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 4: FEE DEMAND
// ============================================================

"CREATE TABLE IF NOT EXISTS `fee_demands` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `demand_no` VARCHAR(50) NOT NULL,
    `academic_session` VARCHAR(50) NOT NULL,
    `demand_month` VARCHAR(50) DEFAULT NULL,
    `class_name` VARCHAR(50) NOT NULL,
    `demand_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `total_students` INT NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `include_late_fee` TINYINT(1) NOT NULL DEFAULT 0,
    `include_old_dues` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('Draft','Generated','Cancelled') NOT NULL DEFAULT 'Draft',
    `generated_by` INT UNSIGNED DEFAULT NULL,
    `generated_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_demand_no` (`demand_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fee_demand_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `demand_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `admission_no` VARCHAR(50) DEFAULT NULL,
    `class_name` VARCHAR(50) NOT NULL,
    `fee_head_id` INT UNSIGNED NOT NULL,
    `fee_head_name` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `old_dues` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_demand` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status` ENUM('Pending','Paid','Partial','Cancelled') NOT NULL DEFAULT 'Pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_fdi_demand` (`demand_id`),
    KEY `idx_fdi_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 5: FEE COLLECTION
// ============================================================

"CREATE TABLE IF NOT EXISTS `fee_collections` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `receipt_no` VARCHAR(50) NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `admission_no` VARCHAR(50) DEFAULT NULL,
    `class_name` VARCHAR(50) DEFAULT NULL,
    `academic_session` VARCHAR(50) DEFAULT NULL,
    `fee_period` VARCHAR(50) DEFAULT NULL,
    `total_outstanding` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `payment_mode` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(150) DEFAULT NULL,
    `payment_date` DATE NOT NULL,
    `cheque_no` VARCHAR(50) DEFAULT NULL,
    `cheque_date` DATE DEFAULT NULL,
    `cheque_bank` VARCHAR(150) DEFAULT NULL,
    `payee_name` VARCHAR(200) DEFAULT NULL,
    `collector_name` VARCHAR(200) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('Active','Cancelled','Void') NOT NULL DEFAULT 'Active',
    `cancel_reason` TEXT DEFAULT NULL,
    `cancelled_by` INT UNSIGNED DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_receipt_no` (`receipt_no`),
    KEY `idx_fc_student` (`student_id`),
    KEY `idx_fc_date` (`payment_date`),
    KEY `idx_fc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fee_collection_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_collection_id` INT UNSIGNED NOT NULL,
    `fee_head_id` INT UNSIGNED DEFAULT NULL,
    `fee_head_name` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `is_advance` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_fci_collection` (`fee_collection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `student_fee_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `class_name` VARCHAR(50) DEFAULT NULL,
    `academic_session` VARCHAR(50) DEFAULT NULL,
    `fee_structure_id` INT UNSIGNED DEFAULT NULL,
    `total_fee` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_paid` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_discount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_late_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `status` ENUM('Active','Fully Paid','Closed') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_sfa_student_session` (`student_id`, `academic_session`),
    KEY `idx_sfa_session` (`academic_session`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 6: EMI / INSTALLMENTS
// ============================================================

"CREATE TABLE IF NOT EXISTS `emi_schedules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `fee_structure_id` INT UNSIGNED DEFAULT NULL,
    `total_fee` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `down_payment` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `installment_type` ENUM('Monthly','Quarterly','Custom') NOT NULL DEFAULT 'Monthly',
    `num_installments` INT NOT NULL DEFAULT 1,
    `installment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `first_emi_date` DATE DEFAULT NULL,
    `processing_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee_type` ENUM('None','Fixed','Percentage') NOT NULL DEFAULT 'None',
    `late_fee_value` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee_grace_days` INT NOT NULL DEFAULT 0,
    `status` ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
    `academic_session` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_emi_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `emi_payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `emi_schedule_id` INT UNSIGNED NOT NULL,
    `installment_no` INT NOT NULL,
    `due_date` DATE NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `late_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `paid_date` DATE DEFAULT NULL,
    `payment_mode` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(150) DEFAULT NULL,
    `status` ENUM('Pending','Paid','Overdue','Partial') NOT NULL DEFAULT 'Pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ep_schedule` (`emi_schedule_id`),
    KEY `idx_ep_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 7: DISCOUNTS
// ============================================================

"CREATE TABLE IF NOT EXISTS `discounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `discount_type` ENUM('Sibling','Staff','Scholarship','Sports','Other') NOT NULL DEFAULT 'Other',
    `discount_method` ENUM('Fixed','Percentage') NOT NULL DEFAULT 'Fixed',
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `applicable_fee_head_id` INT UNSIGNED DEFAULT NULL,
    `applicable_fee_head_name` VARCHAR(100) DEFAULT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE DEFAULT NULL,
    `reason` TEXT DEFAULT NULL,
    `approved_by` VARCHAR(200) DEFAULT NULL,
    `status` ENUM('Active','Expired','Cancelled') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_disc_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 8: EXPENSE MANAGEMENT
// ============================================================

"CREATE TABLE IF NOT EXISTS `expenses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_no` VARCHAR(50) NOT NULL,
    `expense_date` DATE NOT NULL,
    `category_id` INT UNSIGNED NULL,
    `category_name` VARCHAR(100) DEFAULT NULL,
    `vendor_id` INT UNSIGNED DEFAULT NULL,
    `vendor_name` VARCHAR(200) DEFAULT NULL,
    `bill_no` VARCHAR(100) DEFAULT NULL,
    `bill_date` DATE DEFAULT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `description` TEXT DEFAULT NULL,
    `bill_file` VARCHAR(255) DEFAULT NULL,
    `payment_mode` VARCHAR(50) DEFAULT NULL,
    `payment_date` DATE DEFAULT NULL,
    `payment_id` VARCHAR(150) DEFAULT NULL,
    `cheque_no` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(150) DEFAULT NULL,
    `payee_name` VARCHAR(200) DEFAULT NULL,
    `status` ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `reject_reason` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_expense_no` (`expense_no`),
    KEY `idx_exp_category` (`category_id`),
    KEY `idx_exp_vendor` (`vendor_id`),
    KEY `idx_exp_status` (`status`),
    KEY `idx_exp_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 9: VENDOR BILLS & PAYMENTS
// ============================================================

"CREATE TABLE IF NOT EXISTS `vendor_bills` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT UNSIGNED NOT NULL,
    `bill_no` VARCHAR(100) NOT NULL,
    `bill_date` DATE NOT NULL,
    `bill_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `description` TEXT DEFAULT NULL,
    `expense_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('Unpaid','Partial','Paid','Cancelled') NOT NULL DEFAULT 'Unpaid',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_vb_vendor` (`vendor_id`),
    KEY `idx_vb_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `vendor_payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_no` VARCHAR(50) NOT NULL,
    `vendor_id` INT UNSIGNED NOT NULL,
    `vendor_bill_id` INT UNSIGNED DEFAULT NULL,
    `bill_no` VARCHAR(100) DEFAULT NULL,
    `bill_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `tds_deducted` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_paid` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `payment_mode` VARCHAR(50) DEFAULT NULL,
    `payment_date` DATE NOT NULL,
    `transaction_id` VARCHAR(150) DEFAULT NULL,
    `cheque_no` VARCHAR(50) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_vp_no` (`payment_no`),
    KEY `idx_vp_vendor` (`vendor_id`),
    KEY `idx_vp_bill` (`vendor_bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 10: CASH & BANK
// ============================================================

"CREATE TABLE IF NOT EXISTS `cash_book` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_date` DATE NOT NULL,
    `transaction_type` ENUM('Receipt','Payment','Opening','Transfer-In','Transfer-Out') NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `direction` ENUM('Dr','Cr') NOT NULL DEFAULT 'Dr',
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cb_date` (`transaction_date`),
    KEY `idx_cb_type` (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `bank_book` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_account_id` INT UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `transaction_type` ENUM('Deposit','Withdrawal','Transfer-In','Transfer-Out','Opening','Expense','Salary') NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `direction` ENUM('Dr','Cr') NOT NULL DEFAULT 'Dr',
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `reconciled` TINYINT(1) NOT NULL DEFAULT 0,
    `reconciliation_date` DATE DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_bb_account` (`bank_account_id`),
    KEY `idx_bb_date` (`transaction_date`),
    KEY `idx_bb_reconciled` (`reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 11: CHEQUE BOOK
// ============================================================

"CREATE TABLE IF NOT EXISTS `cheque_books` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_account_id` INT UNSIGNED NOT NULL,
    `book_number` VARCHAR(50) NOT NULL,
    `start_cheque_no` VARCHAR(20) NOT NULL,
    `end_cheque_no` VARCHAR(20) NOT NULL,
    `total_cheques` INT NOT NULL DEFAULT 0,
    `received_date` DATE DEFAULT NULL,
    `status` ENUM('Active','Used','Closed') NOT NULL DEFAULT 'Active',
    `kept_by` VARCHAR(200) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cbk_bank` (`bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `cheque_issues` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cheque_number` VARCHAR(20) NOT NULL,
    `cheque_book_id` INT UNSIGNED DEFAULT NULL,
    `bank_account_id` INT UNSIGNED NOT NULL,
    `cheque_date` DATE NOT NULL,
    `payee_name` VARCHAR(200) NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `purpose` TEXT DEFAULT NULL,
    `voucher_no` VARCHAR(50) DEFAULT NULL,
    `issued_by` VARCHAR(200) DEFAULT NULL,
    `approved_by` VARCHAR(200) DEFAULT NULL,
    `status` ENUM('Issued','Cleared','Bounced','Cancelled','Pending') NOT NULL DEFAULT 'Issued',
    `cleared_date` DATE DEFAULT NULL,
    `cancel_reason` TEXT DEFAULT NULL,
    `cancelled_by` VARCHAR(200) DEFAULT NULL,
    `physical_retained` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cheque_no` (`cheque_number`),
    KEY `idx_ci_bank` (`bank_account_id`),
    KEY `idx_ci_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 12: BANK RECONCILIATION
// ============================================================

"CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_account_id` INT UNSIGNED NOT NULL,
    `statement_from` DATE NOT NULL,
    `statement_to` DATE NOT NULL,
    `erp_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `bank_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `unreconciled_cheques` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `bank_charges` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `difference` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `status` ENUM('Pending','Completed') NOT NULL DEFAULT 'Pending',
    `reconciled_by` INT UNSIGNED DEFAULT NULL,
    `reconciled_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_br_bank` (`bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 13: PAYROLL (Finance tables only)
// ============================================================

"CREATE TABLE IF NOT EXISTS `employee_salary_structures` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `employee_name` VARCHAR(200) NOT NULL,
    `basic_salary` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `hra` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `other_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `pf_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `esi_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `professional_tax` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tds` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `loan_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_salary` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `bank_account` VARCHAR(150) DEFAULT NULL,
    `payment_mode` VARCHAR(50) DEFAULT 'Bank Transfer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ess_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `payroll_runs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `month_label` VARCHAR(50) NOT NULL,
    `total_employees` INT NOT NULL DEFAULT 0,
    `total_gross` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_net` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `status` ENUM('Draft','Approved','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `generated_by` INT UNSIGNED DEFAULT NULL,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pr_month` (`month_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `payroll_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payroll_run_id` INT UNSIGNED NOT NULL,
    `employee_id` INT UNSIGNED NOT NULL,
    `employee_name` VARCHAR(200) NOT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `basic` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `hra` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `other_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gross_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `pf` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `esi` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `professional_tax` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tds` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `loan` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `other_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_payout` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `payment_status` ENUM('Pending','Paid') NOT NULL DEFAULT 'Pending',
    `payment_date` DATE DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pi_run` (`payroll_run_id`),
    KEY `idx_pi_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 14: INVENTORY
// ============================================================

"CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_code` VARCHAR(50) DEFAULT NULL,
    `item_name` VARCHAR(200) NOT NULL,
    `category` VARCHAR(100) DEFAULT NULL,
    `unit` VARCHAR(50) NOT NULL DEFAULT 'Piece',
    `opening_quantity` INT NOT NULL DEFAULT 0,
    `current_quantity` INT NOT NULL DEFAULT 0,
    `reorder_level` INT NOT NULL DEFAULT 0,
    `purchase_rate` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `store_location` VARCHAR(200) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_inv_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `inventory_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED NOT NULL,
    `transaction_type` ENUM('Purchase','Issue','Return','Opening','Adjustment') NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `department` VARCHAR(100) DEFAULT NULL,
    `issued_to` VARCHAR(200) DEFAULT NULL,
    `transaction_date` DATE NOT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_it_item` (`item_id`),
    KEY `idx_it_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 15: HOSTEL ACCOUNTS
// ============================================================

"CREATE TABLE IF NOT EXISTS `hostel_fee_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `hostel_name` VARCHAR(200) NOT NULL,
    `room_number` VARCHAR(50) DEFAULT NULL,
    `bed_number` VARCHAR(50) DEFAULT NULL,
    `joining_date` DATE NOT NULL,
    `monthly_room_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `meal_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `security_deposit` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `emi_allowed` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_hfa_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 16: TRANSPORT ACCOUNTS
// ============================================================

"CREATE TABLE IF NOT EXISTS `transport_fee_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `student_name` VARCHAR(200) NOT NULL,
    `route_name` VARCHAR(200) NOT NULL,
    `stop_name` VARCHAR(200) DEFAULT NULL,
    `vehicle_number` VARCHAR(50) DEFAULT NULL,
    `monthly_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `start_date` DATE NOT NULL,
    `travel_type` ENUM('One-Way','Two-Way') NOT NULL DEFAULT 'Two-Way',
    `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_tfa_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 17: BUDGET
// ============================================================

"CREATE TABLE IF NOT EXISTS `budget_heads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `budgets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `financial_year_id` INT UNSIGNED NOT NULL,
    `department` VARCHAR(200) DEFAULT NULL,
    `budget_head_id` INT UNSIGNED NOT NULL,
    `budget_head_name` VARCHAR(200) DEFAULT NULL,
    `annual_budget` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `amount_used` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `amount_committed` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `available_budget` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `alert_percentage` INT NOT NULL DEFAULT 80,
    `block_extra` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_bud_fy` (`financial_year_id`),
    KEY `idx_bud_head` (`budget_head_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 18: FIXED ASSETS
// ============================================================

"CREATE TABLE IF NOT EXISTS `asset_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `default_depreciation_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `fixed_assets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_code` VARCHAR(50) DEFAULT NULL,
    `asset_name` VARCHAR(200) NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `purchase_date` DATE NOT NULL,
    `vendor_id` INT UNSIGNED DEFAULT NULL,
    `purchase_cost` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `location` VARCHAR(200) DEFAULT NULL,
    `custodian` VARCHAR(200) DEFAULT NULL,
    `serial_number` VARCHAR(100) DEFAULT NULL,
    `warranty_end_date` DATE DEFAULT NULL,
    `useful_life_years` INT NOT NULL DEFAULT 5,
    `depreciation_rate` DECIMAL(5,2) NOT NULL DEFAULT 20,
    `accumulated_depreciation` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `current_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `status` ENUM('Active','Under Repair','Disposed') NOT NULL DEFAULT 'Active',
    `disposed_date` DATE DEFAULT NULL,
    `disposed_amount` DECIMAL(14,2) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_fa_category` (`category_id`),
    KEY `idx_fa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `asset_depreciations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_id` INT UNSIGNED NOT NULL,
    `financial_year_id` INT UNSIGNED NOT NULL,
    `depreciation_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `accumulated_after` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `book_value_after` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ad_asset` (`asset_id`),
    KEY `idx_ad_fy` (`financial_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// MODULE 19: REPORTS (no extra tables needed, uses views/queries)
// ============================================================

// ============================================================
// MODULE 20: FINANCIAL YEAR CLOSING
// ============================================================

"CREATE TABLE IF NOT EXISTS `year_closings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `financial_year_id` INT UNSIGNED NOT NULL,
    `closed_by` INT UNSIGNED DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `total_income` DECIMAL(16,2) NOT NULL DEFAULT 0,
    `total_expenses` DECIMAL(16,2) NOT NULL DEFAULT 0,
    `net_surplus` DECIMAL(16,2) NOT NULL DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('Pending','Completed') NOT NULL DEFAULT 'Pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_yc_fy` (`financial_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// ACCOUNTING ENGINE: LEDGER
// ============================================================

"CREATE TABLE IF NOT EXISTS `ledger_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_code` VARCHAR(30) NOT NULL,
    `account_name` VARCHAR(200) NOT NULL,
    `account_type` ENUM('Asset','Liability','Income','Expense','Equity') NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_la_code` (`account_code`),
    KEY `idx_la_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `ledger_entries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ledger_account_id` INT UNSIGNED NOT NULL,
    `entry_date` DATE NOT NULL,
    `entry_type` VARCHAR(50) DEFAULT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `direction` ENUM('Dr','Cr') NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_le_account` (`ledger_account_id`),
    KEY `idx_le_date` (`entry_date`),
    KEY `idx_le_ref` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `journal_entries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `journal_no` VARCHAR(50) NOT NULL,
    `entry_date` DATE NOT NULL,
    `description` TEXT NOT NULL,
    `debit_account_id` INT UNSIGNED NOT NULL,
    `credit_account_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `status` ENUM('Draft','Posted','Cancelled') NOT NULL DEFAULT 'Draft',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_je_no` (`journal_no`),
    KEY `idx_je_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// ============================================================
// AUDIT & APPROVAL
// ============================================================

"CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `user_name` VARCHAR(200) DEFAULT NULL,
    `module` VARCHAR(100) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `record_type` VARCHAR(100) DEFAULT NULL,
    `record_id` INT UNSIGNED DEFAULT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_al_user` (`user_id`),
    KEY `idx_al_module` (`module`),
    KEY `idx_al_record` (`record_type`, `record_id`),
    KEY `idx_al_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

$count = 0;
foreach ($tables as $sql) {
    try {
        $pdo->exec($sql);
        $count++;
    } catch (\PDOException $e) {
        echo "  SKIP/Error: " . $e->getMessage() . "\n";
    }
}

echo "Migration complete. {$count} tables processed.\n";
