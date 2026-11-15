-- ============================================================
-- SIBA PUBLIC SCHOOL ERP - COMPLETE MULTI-MODULE SCHEMA EXPORT
-- Includes all 7 new modules:
-- 1. Inventory (Consumables & Stationery)
-- 2. Student Lifecycle (Promotions, TC, Bonafide Certificates)
-- 3. House Management (Houses Leaderboard, Points Logs, Roles)
-- 4. Library (Catalog, Book Copies, Issue/Return Desk, Overdue Fines)
-- 5. Academics & EXAM (Subjects, Term Exams, Marks-Only Grading Scheme, Printable Marksheets)
-- 6. Operations & Fleet (Fleet Vehicles, Transport Routes, Student Commuters, Hostels & Beds)
-- 7. Communication & Content (Circulars, Email Gateway, Website CMS Pages, Events, Gallery, Enquiries)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. INVENTORY MODULE (Consumables & Stationery)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_code` VARCHAR(50) NOT NULL UNIQUE,
    `item_name` VARCHAR(150) NOT NULL,
    `category` VARCHAR(80) NOT NULL DEFAULT 'Stationery',
    `unit` VARCHAR(30) NOT NULL DEFAULT 'Pieces',
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `current_stock` INT NOT NULL DEFAULT 0,
    `min_threshold` INT NOT NULL DEFAULT 5,
    `location` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `transaction_type` ENUM('IN', 'ISSUE', 'RETURN', 'ADJUSTMENT') NOT NULL,
    `quantity` INT NOT NULL,
    `issued_to` VARCHAR(150) NULL,
    `department` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `created_by` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_item_date` (`item_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. STUDENT LIFECYCLE MODULE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_certificates` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `certificate_no` VARCHAR(50) NOT NULL UNIQUE,
    `student_id` BIGINT NOT NULL,
    `certificate_type` ENUM('Transfer Certificate', 'Bonafide Certificate', 'Character Certificate', 'School Leaving Certificate', 'No Dues Certificate') NOT NULL,
    `reason` TEXT NULL,
    `conduct` VARCHAR(100) DEFAULT 'Good',
    `issued_date` DATE NOT NULL,
    `issued_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cert_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `student_promotions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `from_class` VARCHAR(50) NOT NULL,
    `to_class` VARCHAR(50) NOT NULL,
    `from_session` VARCHAR(50) NOT NULL,
    `to_session` VARCHAR(50) NOT NULL,
    `promotion_status` ENUM('Promoted', 'Detained', 'Graduated') NOT NULL DEFAULT 'Promoted',
    `promoted_date` DATE NOT NULL,
    `promoted_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `student_remarks_discipline` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `incident_date` DATE NOT NULL,
    `category` ENUM('Academic', 'Behavioral', 'Attendance', 'Achievement', 'Warning') NOT NULL DEFAULT 'Behavioral',
    `severity` ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL DEFAULT 'Low',
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `action_taken` TEXT NULL,
    `recorded_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. HOUSE MANAGEMENT MODULE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `houses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `color_code` VARCHAR(20) NOT NULL DEFAULT '#1e3a8a',
    `motto` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `student_house_allocations` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL UNIQUE,
    `house_id` INT NOT NULL,
    `role` ENUM('Member', 'Captain', 'Vice Captain', 'Prefect') NOT NULL DEFAULT 'Member',
    `allocated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_house` (`house_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `house_activity_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `house_id` INT NOT NULL,
    `event_name` VARCHAR(200) NOT NULL,
    `points` INT NOT NULL,
    `points_type` ENUM('Award', 'Deduction') NOT NULL DEFAULT 'Award',
    `activity_date` DATE NOT NULL,
    `recorded_by` INT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_house_date` (`house_id`, `activity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `houses` (`id`, `name`, `color_code`, `motto`) VALUES
(1, 'Red Dragons', '#dc2626', 'Courage, Strength & Valor'),
(2, 'Blue Titans', '#2563eb', 'Wisdom, Integrity & Vision'),
(3, 'Green Phoenix', '#16a34a', 'Growth, Harmony & Resilience'),
(4, 'Yellow Eagles', '#ca8a04', 'Excellence, Speed & Brilliance');

-- ------------------------------------------------------------
-- 4. LIBRARY MODULE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `library_books` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `isbn` VARCHAR(50) NULL,
    `title` VARCHAR(255) NOT NULL,
    `author` VARCHAR(200) NOT NULL,
    `publisher` VARCHAR(150) NULL,
    `edition` VARCHAR(50) NULL,
    `category` VARCHAR(100) NOT NULL DEFAULT 'General',
    `rack_location` VARCHAR(80) NULL,
    `total_copies` INT NOT NULL DEFAULT 1,
    `available_copies` INT NOT NULL DEFAULT 1,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `library_book_copies` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `book_id` BIGINT NOT NULL,
    `accession_no` VARCHAR(50) NOT NULL UNIQUE,
    `barcode` VARCHAR(60) NULL,
    `condition_status` ENUM('Good', 'Fair', 'Damaged', 'Lost') NOT NULL DEFAULT 'Good',
    `is_available` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_book` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `library_issues` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `copy_id` BIGINT NOT NULL,
    `book_id` BIGINT NOT NULL,
    `borrower_type` ENUM('student', 'staff') NOT NULL DEFAULT 'student',
    `borrower_id` BIGINT NOT NULL,
    `issue_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `return_date` DATE NULL,
    `fine_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `fine_status` ENUM('None', 'Unpaid', 'Paid', 'Waived') NOT NULL DEFAULT 'None',
    `status` ENUM('Issued', 'Returned', 'Overdue', 'Lost') NOT NULL DEFAULT 'Issued',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_issue_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. ACADEMICS & EXAM MODULE (Marks-Only Grading System)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_code` VARCHAR(30) NOT NULL UNIQUE,
    `subject_name` VARCHAR(120) NOT NULL,
    `class_name` VARCHAR(50) NULL,
    `teacher_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `exams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `academic_year` VARCHAR(50) NOT NULL DEFAULT '2025-2026',
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `status` ENUM('Scheduled', 'Ongoing', 'Completed') NOT NULL DEFAULT 'Scheduled',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `exam_results` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `exam_name` VARCHAR(150) NOT NULL,
    `max_marks` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
    `obtained_marks` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `grade` VARCHAR(10) NULL,
    `result_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_student_subject_exam` (`student_id`, `subject_id`, `exam_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. OPERATIONS & FLEET MODULE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transport_vehicles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_no` VARCHAR(50) NOT NULL UNIQUE,
    `model` VARCHAR(100) NULL,
    `capacity` INT NOT NULL DEFAULT 30,
    `driver_name` VARCHAR(120) NULL,
    `driver_phone` VARCHAR(30) NULL,
    `status` ENUM('Active', 'Maintenance', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `transport_routes` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `route_name` VARCHAR(100) NOT NULL,
    `vehicle_id` INT NULL,
    `vehicle_no` VARCHAR(30) NULL,
    `driver_name` VARCHAR(120) NULL,
    `attendant_name` VARCHAR(120) NULL,
    `start_location` VARCHAR(150) NULL,
    `end_location` VARCHAR(150) NULL,
    `monthly_fare` DECIMAL(10,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `transport_allocations` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `route_id` BIGINT NOT NULL,
    `pickup_point` VARCHAR(120) NULL,
    `drop_point` VARCHAR(120) NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `hostels` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `type` ENUM('boys', 'girls', 'staff') NOT NULL DEFAULT 'boys',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `hostel_rooms` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `hostel_id` BIGINT NOT NULL,
    `room_no` VARCHAR(30) NOT NULL,
    `total_beds` INT NOT NULL DEFAULT 4,
    `occupied_beds` INT NOT NULL DEFAULT 0,
    `status` ENUM('available', 'full', 'maintenance') NOT NULL DEFAULT 'available',
    `block_id` INT NULL,
    `monthly_fee` DECIMAL(10,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `hostel_allocations` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `room_id` BIGINT NOT NULL,
    `from_date` DATE NOT NULL,
    `to_date` DATE NULL,
    `status` ENUM('active', 'vacated') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7. COMMUNICATION & CONTENT MODULE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_announcements` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `content` TEXT NOT NULL,
    `audience` VARCHAR(50) NOT NULL DEFAULT 'All',
    `priority` VARCHAR(20) DEFAULT 'Normal',
    `valid_until` DATE NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `send_email` TINYINT(1) DEFAULT 0,
    `email_status` VARCHAR(30) DEFAULT 'none',
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcement_email_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `announcement_id` BIGINT NOT NULL,
    `recipient_email` VARCHAR(150) NOT NULL,
    `recipient_name` VARCHAR(150) NULL,
    `recipient_type` VARCHAR(50) NULL,
    `status` ENUM('Queued', 'Sent', 'Failed') NOT NULL DEFAULT 'Sent',
    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ann_id` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cms_pages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `page_title` VARCHAR(255) NOT NULL,
    `data_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('event', 'news') NOT NULL DEFAULT 'event',
    `title` VARCHAR(255) NOT NULL,
    `text` TEXT NULL,
    `day` VARCHAR(2) NULL,
    `month` VARCHAR(20) NULL,
    `icon` VARCHAR(50) DEFAULT 'calendar',
    `color` VARCHAR(7) DEFAULT '#4b5563',
    `image` VARCHAR(500) NULL,
    `attachment` VARCHAR(500) NULL,
    `category` VARCHAR(100) NULL,
    `event_date` DATE NULL,
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gallery` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `image_file` VARCHAR(500) NULL,
    `youtube_url` VARCHAR(500) NULL,
    `category` VARCHAR(100) DEFAULT 'General',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `contact_submissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(15) NOT NULL,
    `email` VARCHAR(100) NULL,
    `subject` VARCHAR(100) NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('New', 'Read', 'Replied', 'Closed') DEFAULT 'New',
    `admin_note` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
