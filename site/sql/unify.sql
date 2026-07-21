-- ============================================================
-- UNIFY SCHEMA: Add all site tables into siba_erp database
-- Run this AFTER erp/database/schema.sql has been executed
-- ============================================================

USE siba_erp;

-- Parents (site) with FK to ERP users
CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(15) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_site_parents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Admins (site) with FK to ERP users
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Super Admin', 'Admin') DEFAULT 'Super Admin',
    user_id INT NULL,
    CONSTRAINT fk_site_admins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Applications (site) with FK to ERP students
CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100),
    middle_name VARCHAR(100),
    last_name VARCHAR(100),
    dob DATE NOT NULL,
    gender VARCHAR(10),
    religion VARCHAR(50),
    blood_group VARCHAR(10),
    aadhaar_no VARCHAR(20),
    previous_school VARCHAR(200),
    previous_class VARCHAR(20),
    class_sought VARCHAR(20) NOT NULL,
    address_line1 TEXT,
    address_line2 TEXT,
    post_office VARCHAR(100),
    police_station VARCHAR(100),
    district VARCHAR(50),
    village_city VARCHAR(100),
    pin VARCHAR(10),
    state VARCHAR(50),
    country VARCHAR(50) DEFAULT 'India',
    father_name VARCHAR(100) NOT NULL,
    father_occupation VARCHAR(100),
    mother_name VARCHAR(100) NOT NULL,
    mother_occupation VARCHAR(100),
    guardian_name VARCHAR(100),
    guardian_occupation VARCHAR(100),
    family_annual_income VARCHAR(50),
    contact_no VARCHAR(15),
    email VARCHAR(100),
    address TEXT,
    birth_cert VARCHAR(255),
    aadhaar VARCHAR(255),
    leaving_cert VARCHAR(255),
    prev_marksheet VARCHAR(255),
    photo VARCHAR(255),
    status ENUM('Application started', 'Under review', 'Admitted', 'Rejected') DEFAULT 'Application started',
    student_id BIGINT NULL,
    admission_no VARCHAR(50) NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    CONSTRAINT fk_site_applications_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
);

-- Fees (site) - application/registration fees via Razorpay
CREATE TABLE IF NOT EXISTS fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    fee_type VARCHAR(20) DEFAULT 'monthly',
    month VARCHAR(20) NULL,
    year INT NULL,
    amount DECIMAL(10,2) NOT NULL,
    razorpay_order_id VARCHAR(100),
    razorpay_payment_id VARCHAR(100),
    razorpay_signature VARCHAR(255),
    status ENUM('Pending', 'Paid') DEFAULT 'Pending',
    paid_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
);

-- CMS Pages (site)
CREATE TABLE IF NOT EXISTS cms_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    page_title VARCHAR(255) NOT NULL,
    data_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings (site)
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Notifications (site)
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    sent_to ENUM('All Parents', 'Admitted', 'Under Review') DEFAULT 'All Parents',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Staff (site) with FK to ERP employees
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100),
    department VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    employee_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_site_staff_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
);
