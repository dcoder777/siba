-- Database Structure for SIBA Public School

CREATE DATABASE IF NOT EXISTS siba_school;
USE siba_school;

-- Parents Table
CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(15) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Applications Table
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
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
);

-- Fees Table
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

-- Admins Table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Super Admin', 'Admin') DEFAULT 'Super Admin'
);

-- CMS Pages Table
CREATE TABLE IF NOT EXISTS cms_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    page_title VARCHAR(255) NOT NULL,
    data_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Dummy Data
INSERT INTO admins (username, password, role) VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin'); -- password is 'password'

-- Dummy Parent
INSERT INTO parents (phone, password) VALUES ('1234567890', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); -- password is 'password'

-- Dummy Application
INSERT INTO applications (parent_id, student_name, father_name, mother_name, contact_no, email, address, pin, district, state, dob, class_sought, birth_cert, photo, status)
VALUES (1, 'John Doe Jr.', 'John Doe', 'Jane Doe', '1234567890', 'john@example.com', '123 School lane', '700001', 'Kolkata', 'West Bengal', '2015-05-15', 'Class 5', 'birth_cert_sample.jpg', 'photo_sample.jpg', 'Application started');
