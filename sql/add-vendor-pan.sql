-- Run each line separately. Ignore "Duplicate column" errors if column already exists.
ALTER TABLE vendors ADD COLUMN vendor_code VARCHAR(50) DEFAULT NULL AFTER id;
ALTER TABLE vendors ADD COLUMN gst_number VARCHAR(30) DEFAULT NULL AFTER email;
ALTER TABLE vendors ADD COLUMN pan VARCHAR(20) DEFAULT NULL AFTER gst_number;
ALTER TABLE vendors ADD COLUMN address TEXT DEFAULT NULL AFTER pan;
ALTER TABLE vendors ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL AFTER address;
ALTER TABLE vendors ADD COLUMN account_number VARCHAR(50) DEFAULT NULL AFTER bank_name;
ALTER TABLE vendors ADD COLUMN ifsc_code VARCHAR(20) DEFAULT NULL AFTER account_number;
ALTER TABLE vendors ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER ifsc_code;
