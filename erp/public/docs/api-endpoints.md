# API Endpoints (`/api/v1`)

**Base URL (local):** `http://localhost/siba/erp/public/api/v1`

All listing endpoints support pagination with:
- `page` (default `1`)
- `limit` (default `20`, max `100`)
- plus module-specific `q` and filter parameters

## Unified Database

The site (public website & parent portal) and ERP now share a single database **`siba_erp`**.

| App | Tables |
|-----|--------|
| **ERP** | `roles`, `users`, `user_role_assignments`, `user_module_access`, `api_tokens`, `students`, `guardians`, `student_enrollments`, `attendance_records`, `subjects`, `exam_results`, `assignments`, `assignment_submissions`, `parent_teacher_messages`, `fee_structures`, `student_fee_dues`, `payments`, `receipts`, `payment_reconciliations`, `timetables`, `transport_routes`, `transport_allocations`, `hostels`, `hostel_rooms`, `hostel_allocations`, `employees`, `leave_requests`, `payroll_runs`, `payroll_items`, `approval_workflows` |
| **Site** | `parents` (FK→users), `admins` (FK→users), `applications` (FK→students), `fees`, `cms_pages`, `settings`, `notifications`, `staff` (FK→employees) |

### Cross-app integration
- **Auth:** Site `parents.user_id` ↔ ERP `users(id)`, Site `admins.user_id` ↔ ERP `users(id)`
- **Admissions:** Site `applications.student_id` ↔ ERP `students(id)` (auto-created when admitted)
- **Staff:** Site `staff.employee_id` ↔ ERP `employees(id)` (auto-created)
- **Config:** `site/includes/config.php` uses `DB_NAME = siba_erp` (same as ERP)

## Auth
- `POST /auth/login`
- `GET /auth/me`

## Students
- `GET /students`
- `GET /students/{id}`
- `POST /students`
- `PUT /students/{id}`
- `DELETE /students/{id}`
- `GET /students/attendance`
- `POST /students/attendance`
- `PUT /students/attendance/{id}`
- `DELETE /students/attendance/{id}`
- `GET /students/lifecycle`
- `POST /students/lifecycle`
- `PUT /students/lifecycle/{id}`
- `DELETE /students/lifecycle/{id}`

## Academics
- `GET /academics/subjects`
- `POST /academics/subjects`
- `PUT /academics/subjects/{id}`
- `DELETE /academics/subjects/{id}`
- `GET /academics/exam-results`
- `POST /academics/exam-results`
- `PUT /academics/exam-results/{id}`
- `DELETE /academics/exam-results/{id}`
- `GET /academics/assignments`
- `POST /academics/assignments`
- `PUT /academics/assignments/{id}`
- `DELETE /academics/assignments/{id}`
- `GET /academics/assignments/submissions`
- `POST /academics/assignments/submissions`
- `PUT /academics/assignments/submissions/{id}`
- `DELETE /academics/assignments/submissions/{id}`
- `GET /academics/messages`
- `POST /academics/messages`
- `PUT /academics/messages/{id}`
- `DELETE /academics/messages/{id}`

## Finance
- `GET /finance/fee-structures`
- `POST /finance/fee-structures`
- `PUT /finance/fee-structures/{id}`
- `DELETE /finance/fee-structures/{id}`
- `GET /finance/fees/dues`
- `POST /finance/fees/dues`
- `PUT /finance/fees/dues/{id}`
- `DELETE /finance/fees/dues/{id}`
- `GET /finance/payments`
- `POST /finance/payments/offline`
- `PUT /finance/payments/{id}`
- `DELETE /finance/payments/{id}`
- `GET /finance/fees/summary`
- `GET /finance/reconciliation`
- `POST /finance/reconciliation`
- `PUT /finance/reconciliation/{id}`
- `DELETE /finance/reconciliation/{id}`

## Operations
- `GET /operations/timetable`
- `POST /operations/timetable`
- `PUT /operations/timetable/{id}`
- `DELETE /operations/timetable/{id}`
- `GET /operations/transport/routes`
- `POST /operations/transport/routes`
- `PUT /operations/transport/routes/{id}`
- `DELETE /operations/transport/routes/{id}`
- `GET /operations/transport/allocations`
- `POST /operations/transport/allocations`
- `PUT /operations/transport/allocations/{id}`
- `DELETE /operations/transport/allocations/{id}`
- `GET /operations/hostels`
- `POST /operations/hostels`
- `PUT /operations/hostels/{id}`
- `DELETE /operations/hostels/{id}`
- `GET /operations/hostels/rooms`
- `POST /operations/hostels/rooms`
- `PUT /operations/hostels/rooms/{id}`
- `DELETE /operations/hostels/rooms/{id}`
- `GET /operations/hostel/allocations`
- `POST /operations/hostel/allocations`
- `PUT /operations/hostel/allocations/{id}`
- `DELETE /operations/hostel/allocations/{id}`

## HR
- `GET /hr/employees`
- `POST /hr/employees`
- `PUT /hr/employees/{id}`
- `DELETE /hr/employees/{id}`
- `GET /hr/leave-requests`
- `POST /hr/leave-requests`
- `PUT /hr/leave-requests/{id}`
- `DELETE /hr/leave-requests/{id}`
- `POST /hr/leave-requests/approve`
- `GET /hr/payroll/runs`
- `DELETE /hr/payroll/runs/{id}`
- `GET /hr/payroll/items`
- `POST /hr/payroll/generate`

## Reports
- `GET /reports/dashboard`
- `GET /reports/payroll-export?month=YYYY-MM`

## Health
- `GET /health`
