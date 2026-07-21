CREATE DATABASE IF NOT EXISTS siba_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE siba_erp;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS approval_workflows;
DROP TABLE IF EXISTS payroll_items;
DROP TABLE IF EXISTS payroll_runs;
DROP TABLE IF EXISTS leave_requests;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS hostel_allocations;
DROP TABLE IF EXISTS hostel_rooms;
DROP TABLE IF EXISTS hostels;
DROP TABLE IF EXISTS transport_allocations;
DROP TABLE IF EXISTS transport_routes;
DROP TABLE IF EXISTS timetables;
DROP TABLE IF EXISTS payment_reconciliations;
DROP TABLE IF EXISTS receipts;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS student_fee_dues;
DROP TABLE IF EXISTS fee_structures;
DROP TABLE IF EXISTS parent_teacher_messages;
DROP TABLE IF EXISTS assignment_submissions;
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS exam_results;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS student_enrollments;
DROP TABLE IF EXISTS guardians;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS user_role_assignments;
DROP TABLE IF EXISTS user_module_access;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE user_role_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_role (user_id, role_id),
    CONSTRAINT fk_ura_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ura_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE user_module_access (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    can_access TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_module (user_id, module_key),
    CONSTRAINT fk_user_module_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE api_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE students (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admission_no VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    gender VARCHAR(20) NULL,
    dob DATE NULL,
    blood_group VARCHAR(10) NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(120) NULL,
    address TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE guardians (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    name VARCHAR(120) NOT NULL,
    relation_type VARCHAR(40) NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(120) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guardian_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE student_enrollments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    class_name VARCHAR(50) NOT NULL,
    section_name VARCHAR(50) NULL,
    session_label VARCHAR(20) NOT NULL,
    status ENUM('active', 'promoted', 'transferred', 'withdrawn', 'alumni') NOT NULL DEFAULT 'active',
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE attendance_records (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'leave') NOT NULL,
    remark VARCHAR(255) NULL,
    marked_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance (student_id, attendance_date),
    CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE subjects (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(20) NOT NULL UNIQUE,
    subject_name VARCHAR(120) NOT NULL,
    class_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE exam_results (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    subject_id BIGINT NOT NULL,
    exam_name VARCHAR(120) NOT NULL,
    max_marks DECIMAL(8,2) NOT NULL,
    obtained_marks DECIMAL(8,2) NOT NULL,
    grade VARCHAR(5) NULL,
    result_date DATE NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_result_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_result_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
);

CREATE TABLE assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    class_name VARCHAR(50) NOT NULL,
    section_name VARCHAR(50) NULL,
    due_date DATE NOT NULL,
    assigned_by INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE assignment_submissions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    assignment_id BIGINT NOT NULL,
    student_id BIGINT NOT NULL,
    submitted_at DATETIME NULL,
    submission_note TEXT NULL,
    status ENUM('submitted', 'pending', 'late', 'reviewed') NOT NULL DEFAULT 'submitted',
    marks_awarded DECIMAL(8,2) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_submission (assignment_id, student_id),
    CONSTRAINT fk_submission_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE parent_teacher_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    sender_user_id INT NOT NULL,
    receiver_user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ptm_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_ptm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ptm_receiver FOREIGN KEY (receiver_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE fee_structures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(50) NOT NULL,
    academic_session VARCHAR(20) NOT NULL,
    fee_head VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    frequency ENUM('one_time', 'monthly', 'quarterly', 'annual') NOT NULL DEFAULT 'monthly',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE student_fee_dues (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    fee_structure_id BIGINT NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('paid', 'pending', 'partial') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_due_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_due_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE CASCADE
);

CREATE TABLE payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    fee_due_id BIGINT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_mode ENUM('cash', 'upi', 'card', 'bank_transfer', 'cheque') NOT NULL,
    source ENUM('offline', 'website') NOT NULL DEFAULT 'offline',
    reference_no VARCHAR(120) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_due FOREIGN KEY (fee_due_id) REFERENCES student_fee_dues(id) ON DELETE SET NULL
);

CREATE TABLE receipts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT NOT NULL UNIQUE,
    receipt_no VARCHAR(80) NOT NULL UNIQUE,
    generated_at DATETIME NOT NULL,
    CONSTRAINT fk_receipt_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE TABLE payment_reconciliations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gateway_reference VARCHAR(120) NOT NULL,
    website_order_id VARCHAR(120) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('matched', 'mismatch', 'pending') NOT NULL,
    reconciled_at DATETIME NOT NULL,
    notes VARCHAR(255) NULL
);

CREATE TABLE timetables (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(50) NOT NULL,
    section_name VARCHAR(50) NULL,
    day_name VARCHAR(20) NOT NULL,
    period_no INT NOT NULL,
    subject_name VARCHAR(120) NULL,
    teacher_name VARCHAR(120) NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transport_routes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    route_name VARCHAR(100) NOT NULL,
    vehicle_no VARCHAR(30) NOT NULL,
    driver_name VARCHAR(120) NULL,
    attendant_name VARCHAR(120) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transport_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    route_id BIGINT NOT NULL,
    pickup_point VARCHAR(120) NOT NULL,
    drop_point VARCHAR(120) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ta_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_route FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE
);

CREATE TABLE hostels (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type ENUM('boys', 'girls', 'staff') NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE hostel_rooms (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    hostel_id BIGINT NOT NULL,
    room_no VARCHAR(30) NOT NULL,
    total_beds INT NOT NULL DEFAULT 1,
    occupied_beds INT NOT NULL DEFAULT 0,
    status ENUM('available', 'full', 'maintenance') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_room_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE
);

CREATE TABLE hostel_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT NOT NULL,
    room_id BIGINT NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NULL,
    status ENUM('active', 'vacated') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ha_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_ha_room FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE
);

CREATE TABLE employees (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    department VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    joining_date DATE NULL,
    ctc DECIMAL(12,2) NOT NULL DEFAULT 0,
    payout_account VARCHAR(100) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE leave_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    reason TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    requested_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leave_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE payroll_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    month_label VARCHAR(10) NOT NULL,
    generated_at DATETIME NOT NULL,
    generated_by INT NULL,
    CONSTRAINT fk_payrun_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE payroll_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id BIGINT NOT NULL,
    employee_id BIGINT NOT NULL,
    ctc_amount DECIMAL(12,2) NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL,
    deductions_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_payout DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_payitem_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_payitem_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE approval_workflows (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(50) NOT NULL,
    record_id BIGINT NOT NULL,
    submitted_by INT NOT NULL,
    approved_by INT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_approval_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_approval_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO roles (name) VALUES
('owner'),
('admin'),
('parent'),
('teacher'),
('driver'),
('finance'),
('hr');

-- password: password
INSERT INTO users (role_id, name, email, password_hash, is_active)
VALUES ((SELECT id FROM roles WHERE name = 'admin' LIMIT 1), 'System Admin', 'admin@siba.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

INSERT INTO user_module_access (user_id, module_key, can_access) VALUES
(1, 'students', 1),
(1, 'academics', 1),
(1, 'finance', 1),
(1, 'operations', 1),
(1, 'hr', 1),
(1, 'reports', 1);
