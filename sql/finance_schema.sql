-- Finance Module Schema for SIBA ERP
-- Run this after the main schema.sql

-- Fee Heads
CREATE TABLE IF NOT EXISTS fee_heads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_mandatory TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Fee Structures
CREATE TABLE IF NOT EXISTS fee_structures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    academic_session VARCHAR(20) NOT NULL,
    class_name VARCHAR(50),
    total_amount DECIMAL(12,2) DEFAULT 0,
    installment_enabled TINYINT(1) DEFAULT 0,
    num_installments INT DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Fee Structure Items (individual fee heads with amounts)
CREATE TABLE IF NOT EXISTS fee_structure_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fee_structure_id INT NOT NULL,
    fee_head_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    is_optional TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE CASCADE,
    FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id) ON DELETE CASCADE
);

-- Fee Installments
CREATE TABLE IF NOT EXISTS fee_installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fee_structure_id INT NOT NULL,
    installment_no INT NOT NULL,
    title VARCHAR(100),
    due_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    late_fee_type ENUM('fixed','percentage') DEFAULT 'fixed',
    late_fee_value DECIMAL(10,2) DEFAULT 0,
    late_fee_grace_days INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE CASCADE
);

-- Fee Structure Assignments (to classes, sections, or individual students)
CREATE TABLE IF NOT EXISTS fee_structure_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fee_structure_id INT NOT NULL,
    assign_type ENUM('class','section','student') NOT NULL,
    assign_value VARCHAR(100) NOT NULL,
    student_id INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE CASCADE
);

-- Student Fee Accounts (per-student fee ledger)
CREATE TABLE IF NOT EXISTS student_fee_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    student_name VARCHAR(200) DEFAULT '',
    class_name VARCHAR(50) DEFAULT '',
    academic_session VARCHAR(20) NOT NULL,
    fee_structure_id INT NOT NULL,
    total_fee DECIMAL(12,2) DEFAULT 0,
    total_paid DECIMAL(12,2) DEFAULT 0,
    total_discount DECIMAL(12,2) DEFAULT 0,
    total_late_fee DECIMAL(12,2) DEFAULT 0,
    balance DECIMAL(12,2) DEFAULT 0,
    status ENUM('active','closed','transferred') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Compatibility: add student_name/class_name to student_fee_accounts if missing
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_fee_accounts' AND COLUMN_NAME = 'student_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE student_fee_accounts ADD COLUMN student_name VARCHAR(200) DEFAULT '''' AFTER student_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_fee_accounts' AND COLUMN_NAME = 'class_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE student_fee_accounts ADD COLUMN class_name VARCHAR(50) DEFAULT '''' AFTER student_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Fee Collections
CREATE TABLE IF NOT EXISTS fee_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(50) UNIQUE NOT NULL,
    student_id INT,
    student_name VARCHAR(200),
    class_name VARCHAR(50),
    academic_session VARCHAR(20),
    total_amount DECIMAL(12,2) NOT NULL,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    late_fee DECIMAL(12,2) DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('Cash','Cheque','UPI','Card','Bank Transfer') NOT NULL,
    payment_date DATE NOT NULL,
    cheque_no VARCHAR(50),
    cheque_date DATE,
    cheque_bank VARCHAR(100),
    cheque_clearance ENUM('Pending','Cleared','Bounced') DEFAULT 'Pending',
    transaction_ref VARCHAR(100),
    collector_id INT,
    collector_name VARCHAR(100),
    notes TEXT,
    status ENUM('Active','Cancelled','Void') DEFAULT 'Active',
    cancelled_at TIMESTAMP NULL,
    cancelled_by INT,
    cancel_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Fee Collection Items (individual fee heads in a collection)
CREATE TABLE IF NOT EXISTS fee_collection_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fee_collection_id INT NOT NULL,
    fee_head_id INT NOT NULL,
    fee_head_name VARCHAR(100),
    amount DECIMAL(10,2) NOT NULL,
    is_advance TINYINT(1) DEFAULT 0,
    FOREIGN KEY (fee_collection_id) REFERENCES fee_collections(id) ON DELETE CASCADE
);

-- Fee Collection Installment Mapping
CREATE TABLE IF NOT EXISTS fee_collection_installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fee_collection_id INT NOT NULL,
    installment_id INT,
    installment_no INT,
    amount DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (fee_collection_id) REFERENCES fee_collections(id) ON DELETE CASCADE
);

-- Discounts / Scholarships
CREATE TABLE IF NOT EXISTS fee_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    discount_type ENUM('scholarship','sibling','merit','need_based','other') NOT NULL,
    discount_percentage DECIMAL(5,2),
    discount_amount DECIMAL(10,2),
    description TEXT,
    approved_by INT,
    valid_from DATE,
    valid_until DATE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Expense Categories
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    department VARCHAR(50),
    is_recurring TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    vendor_name VARCHAR(200),
    bill_no VARCHAR(100),
    bill_date DATE,
    amount DECIMAL(12,2) NOT NULL,
    gst_amount DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL,
    description TEXT,
    bill_file VARCHAR(255),
    payment_mode ENUM('Cash','Cheque','UPI','Card','Bank Transfer') NOT NULL,
    payment_date DATE,
    cheque_no VARCHAR(50),
    payee_name VARCHAR(200),
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE
);

-- Income Categories
CREATE TABLE IF NOT EXISTS income_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Income Records (non-fee income)
CREATE TABLE IF NOT EXISTS income_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    income_type ENUM('donation','grant','miscellaneous','other') DEFAULT 'miscellaneous',
    amount DECIMAL(12,2) NOT NULL,
    receipt_no VARCHAR(50),
    donor_name VARCHAR(200),
    donor_contact VARCHAR(50),
    description TEXT,
    payment_mode ENUM('Cash','Cheque','UPI','Card','Bank Transfer') NOT NULL,
    payment_date DATE,
    transaction_ref VARCHAR(100),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES income_categories(id) ON DELETE CASCADE
);

-- Bank Accounts
CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_no VARCHAR(50) NOT NULL,
    branch VARCHAR(100),
    ifsc_code VARCHAR(20),
    opening_balance DECIMAL(12,2) DEFAULT 0,
    current_balance DECIMAL(12,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Cash Book
CREATE TABLE IF NOT EXISTS cash_book (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out') NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    balance DECIMAL(12,2) DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bank Book
CREATE TABLE IF NOT EXISTS bank_book (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out','reconciliation') NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    balance DECIMAL(12,2) DEFAULT 0,
    reconciled TINYINT(1) DEFAULT 0,
    reconciliation_date DATE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE
);

-- General Ledger
CREATE TABLE IF NOT EXISTS ledger_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(20) UNIQUE,
    account_name VARCHAR(200) NOT NULL,
    account_type ENUM('asset','liability','income','expense','equity') NOT NULL,
    parent_id INT,
    opening_balance DECIMAL(12,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Ledger Entries
CREATE TABLE IF NOT EXISTS ledger_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ledger_account_id INT NOT NULL,
    entry_date DATE NOT NULL,
    entry_type ENUM('journal','receipt','payment','expense','income','contra') NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ledger_account_id) REFERENCES ledger_accounts(id) ON DELETE CASCADE
);

-- Audit Log for Finance
CREATE TABLE IF NOT EXISTS finance_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT,
    old_value TEXT,
    new_value TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default data

-- Default Fee Heads
INSERT IGNORE INTO fee_heads (id, name, description, sort_order) VALUES
(1, 'Tuition Fee', 'Regular tuition fee', 1),
(2, 'Admission Fee', 'One-time admission fee', 2),
(3, 'Transport Fee', 'Transport/ bus fee', 3),
(4, 'Hostel Fee', 'Hostel accommodation fee', 4),
(5, 'Examination Fee', 'Exam/ assessment fee', 5),
(6, 'Library Fee', 'Library membership fee', 6),
(7, 'Sports Fee', 'Sports and games fee', 7),
(8, 'Laboratory Fee', 'Science lab fee', 8),
(9, 'Development Fee', 'Infrastructure development fee', 9),
(10, 'Miscellaneous', 'Other miscellaneous charges', 10);

-- Default Expense Categories
INSERT IGNORE INTO expense_categories (id, name, description, department) VALUES
(1, 'Salary', 'Staff salaries and wages', 'HR'),
(2, 'Electricity', 'Electricity bills', 'Admin'),
(3, 'Maintenance', 'Building and equipment maintenance', 'Admin'),
(4, 'Stationery', 'Office and school stationery', 'Admin'),
(5, 'Transport', 'Vehicle fuel and maintenance', 'Transport'),
(6, 'Food', 'Canteen and hostel food', 'Hostel'),
(7, 'Events', 'School events and functions', 'Events'),
(8, 'Equipment', 'Lab and sports equipment', 'Academic'),
(9, 'Utilities', 'Water, internet, phone bills', 'Admin'),
(10, 'Miscellaneous', 'Other miscellaneous expenses', 'Admin');

-- Default Income Categories
INSERT IGNORE INTO income_categories (id, name, description) VALUES
(1, 'Donations', 'Voluntary donations'),
(2, 'Grants', 'Government and private grants'),
(3, 'Miscellaneous', 'Other miscellaneous income');

-- Default Ledger Accounts (Chart of Accounts)
INSERT IGNORE INTO ledger_accounts (account_code, account_name, account_type) VALUES
('1', 'Assets', 'asset'),
('1.1', 'Cash in Hand', 'asset'),
('1.2', 'Bank Accounts', 'asset'),
('1.3', 'Accounts Receivable', 'asset'),
('2', 'Liabilities', 'liability'),
('2.1', 'Accounts Payable', 'liability'),
('2.2', 'Advance Fees', 'liability'),
('3', 'Income', 'income'),
('3.1', 'Tuition Fee Income', 'income'),
('3.2', 'Transport Fee Income', 'income'),
('3.3', 'Hostel Fee Income', 'income'),
('3.4', 'Admission Fee Income', 'income'),
('3.5', 'Other Fee Income', 'income'),
('3.6', 'Donations Income', 'income'),
('3.7', 'Grants Income', 'income'),
('3.8', 'Miscellaneous Income', 'income'),
('4', 'Expenses', 'expense'),
('4.1', 'Salary Expenses', 'expense'),
('4.2', 'Utility Expenses', 'expense'),
('4.3', 'Maintenance Expenses', 'expense'),
('4.4', 'Stationery Expenses', 'expense'),
('4.5', 'Transport Expenses', 'expense'),
('4.6', 'Event Expenses', 'expense'),
('4.7', 'Miscellaneous Expenses', 'expense'),
('5', 'Equity', 'equity'),
('5.1', 'Retained Earnings', 'equity');

-- Default Bank Account
INSERT IGNORE INTO bank_accounts (id, account_name, bank_name, account_no, ifsc_code, opening_balance, current_balance) VALUES
(1, 'Main Account', 'State Bank of India', '12345678901', 'SBIN0001234', 0, 0);
