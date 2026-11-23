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
- `GET /students/classes` (alias: `GET /classes`) — fast lightweight distinct class and section list with student counts
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

## Admissions
- `POST /admissions/register-parent` — Register parent account (public)
- `GET /admissions/applications` — List applications (supports pagination, `id`, `q` [by application ID, app no, student name, parent], and `status`; returns `application_no` and `application_number`)
- `POST /admissions/apply` — Submit application with multipart/form-data document uploads (`birth_cert`, `aadhaar`, `leaving_cert`, `prev_marksheet`, `photo`, `caste_cert`, `father_photo`, `mother_photo`, `father_aadhaar`, `mother_aadhaar`, `father_voter`, `mother_voter`, `disability_cert`, `guardian_signature`; returns `application_no` and `application_number`)
- `GET /admissions/applications/{id}` — Get single application details by ID
- `GET /admissions/applications/{id}/receipt` — Download application acknowledgement receipt as PDF (`format=binary`, default) or JSON (`format=json`)
- `GET /admissions/my-applications` — List applications for the currently authenticated parent

## Parents
- `GET /parents`
- `GET /parents/me/applications` — List applications for the currently authenticated parent
- `GET /parents/{id}`
- `PUT /parents/{id}`
- `GET /parents/{id}/applications`

## Reports
- `GET /reports/dashboard`
- `GET /reports/payroll-export?month=YYYY-MM`

## Module 1: Inventory (Stationery & Consumables)
- `GET /inventory/items` — List catalog items (supports `q`, `category`, pagination)
- `POST /inventory/items` — Register consumable/stationery item
- `GET /inventory/items/{id}` — Item details & recent 20 audit transactions
- `PUT /inventory/items/{id}` — Update item pricing, location, or reorder limits
- `DELETE /inventory/items/{id}` — Delete inventory item
- `GET /inventory/transactions` — Stock audit transactions history (`item_id`, `type`, pagination)
- `POST /inventory/transactions` — Record stock issue or inbound stock-in with automatic balance adjustment

## Module 2: Student Lifecycle
- `GET /students/{id}/dossier` — Complete 360° student dossier (demographics, guardians, remarks, house, certificates, marks)
- `GET /students/{id}/remarks` — List disciplinary and behavioral remarks for student
- `POST /students/{id}/remarks` — Record behavioral or disciplinary remark
- `DELETE /students/remarks/{id}` — Delete remark record
- `GET /students/promotions/history` — List promotion history logs
- `POST /students/promotions/batch` — Execute batch student promotions to next grade
- `GET /students/certificates/all` — List issued certificates directory
- `POST /students/certificates/issue` — Issue official Transfer Certificate (TC), Bonafide, Character, or No-Dues certificate
- `GET /students/certificates/{id}` — Retrieve certificate print details

## Module 3: House Management
- `GET /houses` — List all houses with live points totals & leaderboard
- `POST /houses` — Create new student house
- `GET /houses/{id}` — House profile, student roster, and recent points log
- `PUT /houses/{id}` — Update house information
- `DELETE /houses/{id}` — Delete house
- `GET /houses/allocations` — List student house allocations
- `POST /houses/allocations` — Assign student to a house
- `GET /houses/points` — List points activity log
- `POST /houses/points` — Award or deduct house points

## Module 4: Library
- `GET /library/books` — Catalog listing of books (supports `q`, `category`, pagination)
- `POST /library/books` — Add book title and auto-generate barcode copies
- `GET /library/books/{id}` — Book details & individual copy status
- `PUT /library/books/{id}` — Update book title & rack location
- `DELETE /library/books/{id}` — Delete book title
- `GET /library/circulation` — Active, overdue, and returned book loans
- `POST /library/circulation/issue` — Issue book to student with due date
- `POST /library/circulation/return/{id}` — Return issued book & record overdue fines

## Module 5: Academics and EXAM
- `GET /academics/exams` — List exam terms & schedules
- `POST /academics/exams` — Create exam term schedule
- `GET /academics/exams/{id}` — Get exam schedule details
- `PUT /academics/exams/{id}` — Update exam schedule
- `DELETE /academics/exams/{id}` — Delete exam schedule
- `GET /academics/marks` — List entered marks (filtered by `exam_id`, `student_id`, `subject_id`)
- `POST /academics/marks` — Record student marks (strictly marks-only evaluation)
- `POST /academics/marks/batch` — Bulk save student marks for a class
- `GET /academics/report-card/{student_id}/{exam_id}` — Consolidated report card marksheet data (marks only)

## Module 6: Operations & Fleet
- `GET /operations/fleet/vehicles` — List fleet vehicles
- `POST /operations/fleet/vehicles` — Register vehicle in school fleet
- `GET /operations/fleet/vehicles/{id}` — Get vehicle details
- `PUT /operations/fleet/vehicles/{id}` — Update vehicle information
- `DELETE /operations/fleet/vehicles/{id}` — Remove vehicle from fleet
- `GET /operations/transport/routes` — List transport routes
- `POST /operations/transport/routes` — Create transport route
- `GET /operations/transport/allocations` — List student transport allocations
- `POST /operations/transport/allocations` — Assign student to bus route
- `GET /operations/hostels` — List hostels
- `GET /operations/hostels/rooms` — List rooms with bed capacities and occupancy
- `POST /operations/hostels/rooms` — Create room
- `GET /operations/hostel/allocations` — List student hostel bed allocations
- `POST /operations/hostel/allocations` — Allocate student to hostel room bed

## Module 7: Communication & Content
- `GET /communication/announcements` — List circular notices & announcements
- `POST /communication/announcements` — Publish notice circular & trigger email gateway dispatch
- `GET /communication/announcements/{id}` — Get announcement details & email dispatch logs
- `DELETE /communication/announcements/{id}` — Delete announcement
- `GET /content/pages` — List CMS website pages
- `GET /content/pages/{slug}` — Get CMS page content
- `PUT /content/pages/{slug}` — Update CMS page content
- `GET /content/events` — List school events & calendar
- `POST /content/events` — Create school event or news item
- `DELETE /content/events/{id}` — Delete school event
- `GET /content/gallery` — List website photo/video gallery
- `POST /content/gallery` — Add media item to gallery
- `DELETE /content/gallery/{id}` — Delete gallery item
- `GET /content/enquiries` — List website contact enquiries
- `PUT /content/enquiries/{id}` — Update enquiry status & admin note
- `DELETE /content/enquiries/{id}` — Delete enquiry

## Health
- `GET /health`
