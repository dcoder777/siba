-- Migration: Add missing columns to applications table
-- Run this in phpMyAdmin SQL tab on siba_erp database

USE siba_erp;

-- Add missing columns (IF NOT EXISTS won't work for columns in MySQL, so use procedure)
-- Safe to run multiple times — each block is guarded

SET @dbname = DATABASE();

-- Function to safely add column if missing
-- caste
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'caste');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN caste VARCHAR(20) AFTER photo', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- disability
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'disability');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN disability VARCHAR(10) AFTER caste', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- disability_details
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'disability_details');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN disability_details VARCHAR(255) AFTER disability', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- caste_cert
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'caste_cert');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN caste_cert VARCHAR(255) AFTER disability_details', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- father_photo
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'father_photo');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN father_photo VARCHAR(255) AFTER caste_cert', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mother_photo
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'mother_photo');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN mother_photo VARCHAR(255) AFTER father_photo', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- father_aadhaar_no
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'father_aadhaar_no');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN father_aadhaar_no VARCHAR(20) AFTER mother_photo', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- father_aadhaar
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'father_aadhaar');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN father_aadhaar VARCHAR(255) AFTER father_aadhaar_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mother_aadhaar_no
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'mother_aadhaar_no');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN mother_aadhaar_no VARCHAR(20) AFTER father_aadhaar', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mother_aadhaar
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'mother_aadhaar');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN mother_aadhaar VARCHAR(255) AFTER mother_aadhaar_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- father_voter_no
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'father_voter_no');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN father_voter_no VARCHAR(30) AFTER mother_aadhaar', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- father_voter
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'father_voter');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN father_voter VARCHAR(255) AFTER father_voter_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mother_voter_no
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'mother_voter_no');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN mother_voter_no VARCHAR(30) AFTER father_voter', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mother_voter
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'mother_voter');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN mother_voter VARCHAR(255) AFTER mother_voter_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- disability_cert
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'disability_cert');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN disability_cert VARCHAR(255) AFTER mother_voter', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- guardian_signature
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'guardian_signature');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN guardian_signature VARCHAR(255) AFTER disability_cert', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- application_number
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'application_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN application_number VARCHAR(50) AFTER admission_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- payment_status
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'payment_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN payment_status ENUM(''Pending'',''Paid'') DEFAULT ''Pending'' AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- deleted_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE applications ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER applied_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
