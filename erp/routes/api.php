<?php

declare(strict_types=1);

use core\Router;
use modules\Academics\AcademicController;
use modules\Auth\AuthController;
use modules\Finance\FinanceController;
use modules\HR\HRController;
use modules\Operations\OperationsController;
use modules\Reports\ReportController;
use modules\Admissions\AdmissionController;
use modules\Students\StudentController;

return function (Router $router, array $context): void {
    $auth = new AuthController($context['pdo']);
    $students = new StudentController($context['pdo']);
    $academics = new AcademicController($context['pdo']);
    $finance = new FinanceController($context['pdo']);
    $operations = new OperationsController($context['pdo']);
    $hr = new HRController($context['pdo']);
    $reports = new ReportController($context['pdo']);
    $admissions = new AdmissionController($context['pdo']);

    $router->add('POST', '/api/v1/auth/login', fn() => $auth->login(), false);
    $router->add('GET', '/api/v1/auth/me', fn($_, $ctx) => $auth->me($ctx), true);

    $router->add('GET', '/api/v1/students', fn() => $students->list(), true, ['admin', 'teacher', 'finance'], 'students');
    $router->add('POST', '/api/v1/students', fn() => $students->create(), true, ['admin'], 'students');
    $router->add('PUT', '/api/v1/students/{id}', fn($p) => $students->update($p), true, ['admin'], 'students');
    $router->add('DELETE', '/api/v1/students/{id}', fn($p) => $students->delete($p), true, ['admin'], 'students');
    $router->add('GET', '/api/v1/students/attendance', fn() => $students->listAttendance(), true, ['admin', 'teacher'], 'students');
    $router->add('POST', '/api/v1/students/attendance', fn($_, $ctx) => $students->createAttendance($ctx), true, ['admin', 'teacher'], 'students');
    $router->add('PUT', '/api/v1/students/attendance/{id}', fn($p) => $students->updateAttendance($p), true, ['admin', 'teacher'], 'students');
    $router->add('DELETE', '/api/v1/students/attendance/{id}', fn($p) => $students->deleteAttendance($p), true, ['admin', 'teacher'], 'students');
    $router->add('GET', '/api/v1/students/lifecycle', fn() => $students->listLifecycle(), true, ['admin'], 'students');
    $router->add('POST', '/api/v1/students/lifecycle', fn() => $students->createLifecycle(), true, ['admin'], 'students');
    $router->add('PUT', '/api/v1/students/lifecycle/{id}', fn($p) => $students->updateLifecycle($p), true, ['admin'], 'students');
    $router->add('DELETE', '/api/v1/students/lifecycle/{id}', fn($p) => $students->deleteLifecycle($p), true, ['admin'], 'students');
    $router->add('GET', '/api/v1/students/{id}', fn($p) => $students->show($p), true, ['admin', 'teacher', 'finance'], 'students');

    $router->add('GET', '/api/v1/academics/subjects', fn() => $academics->listSubjects(), true, ['admin', 'teacher'], 'academics');
    $router->add('POST', '/api/v1/academics/subjects', fn() => $academics->createSubject(), true, ['admin'], 'academics');
    $router->add('PUT', '/api/v1/academics/subjects/{id}', fn($p) => $academics->updateSubject($p), true, ['admin'], 'academics');
    $router->add('DELETE', '/api/v1/academics/subjects/{id}', fn($p) => $academics->deleteSubject($p), true, ['admin'], 'academics');
    $router->add('GET', '/api/v1/academics/exam-results', fn() => $academics->listExamResults(), true, ['admin', 'teacher'], 'academics');
    $router->add('POST', '/api/v1/academics/exam-results', fn() => $academics->addExamResult(), true, ['admin', 'teacher'], 'academics');
    $router->add('PUT', '/api/v1/academics/exam-results/{id}', fn($p) => $academics->updateExamResult($p), true, ['admin', 'teacher'], 'academics');
    $router->add('DELETE', '/api/v1/academics/exam-results/{id}', fn($p) => $academics->deleteExamResult($p), true, ['admin'], 'academics');
    $router->add('GET', '/api/v1/academics/assignments', fn() => $academics->listAssignments(), true, ['admin', 'teacher'], 'academics');
    $router->add('POST', '/api/v1/academics/assignments', fn() => $academics->addAssignment(), true, ['admin', 'teacher'], 'academics');
    $router->add('PUT', '/api/v1/academics/assignments/{id}', fn($p) => $academics->updateAssignment($p), true, ['admin', 'teacher'], 'academics');
    $router->add('DELETE', '/api/v1/academics/assignments/{id}', fn($p) => $academics->deleteAssignment($p), true, ['admin'], 'academics');
    $router->add('GET', '/api/v1/academics/assignments/submissions', fn() => $academics->listSubmissions(), true, ['admin', 'teacher'], 'academics');
    $router->add('POST', '/api/v1/academics/assignments/submissions', fn() => $academics->submitAssignment(), true, ['admin', 'teacher', 'student'], 'academics');
    $router->add('PUT', '/api/v1/academics/assignments/submissions/{id}', fn($p) => $academics->updateSubmission($p), true, ['admin', 'teacher'], 'academics');
    $router->add('DELETE', '/api/v1/academics/assignments/submissions/{id}', fn($p) => $academics->deleteSubmission($p), true, ['admin'], 'academics');
    $router->add('GET', '/api/v1/academics/messages', fn() => $academics->listMessages(), true, ['admin', 'teacher', 'parent'], 'academics');
    $router->add('POST', '/api/v1/academics/messages', fn($_, $ctx) => $academics->parentTeacherMessage($ctx), true, ['admin', 'teacher', 'parent'], 'academics');
    $router->add('PUT', '/api/v1/academics/messages/{id}', fn($p) => $academics->updateMessage($p), true, ['admin', 'teacher', 'parent'], 'academics');
    $router->add('DELETE', '/api/v1/academics/messages/{id}', fn($p) => $academics->deleteMessage($p), true, ['admin', 'teacher'], 'academics');

    $router->add('GET', '/api/v1/finance/fee-structures', fn() => $finance->listFeeStructures(), true, ['admin', 'finance'], 'finance');
    $router->add('POST', '/api/v1/finance/fee-structures', fn() => $finance->createFeeStructure(), true, ['admin', 'finance'], 'finance');
    $router->add('PUT', '/api/v1/finance/fee-structures/{id}', fn($p) => $finance->updateFeeStructure($p), true, ['admin', 'finance'], 'finance');
    $router->add('DELETE', '/api/v1/finance/fee-structures/{id}', fn($p) => $finance->deleteFeeStructure($p), true, ['admin', 'finance'], 'finance');
    $router->add('GET', '/api/v1/finance/fees/dues', fn() => $finance->listFeeDues(), true, ['admin', 'finance'], 'finance');
    $router->add('POST', '/api/v1/finance/fees/dues', fn() => $finance->createFeeDue(), true, ['admin', 'finance'], 'finance');
    $router->add('PUT', '/api/v1/finance/fees/dues/{id}', fn($p) => $finance->updateFeeDue($p), true, ['admin', 'finance'], 'finance');
    $router->add('DELETE', '/api/v1/finance/fees/dues/{id}', fn($p) => $finance->deleteFeeDue($p), true, ['admin', 'finance'], 'finance');
    $router->add('GET', '/api/v1/finance/payments', fn() => $finance->listPayments(), true, ['admin', 'finance'], 'finance');
    $router->add('POST', '/api/v1/finance/payments/offline', fn() => $finance->offlinePaymentEntry(), true, ['admin', 'finance'], 'finance');
    $router->add('PUT', '/api/v1/finance/payments/{id}', fn($p) => $finance->updatePayment($p), true, ['admin', 'finance'], 'finance');
    $router->add('DELETE', '/api/v1/finance/payments/{id}', fn($p) => $finance->deletePayment($p), true, ['admin', 'finance'], 'finance');
    $router->add('GET', '/api/v1/finance/fees/summary', fn() => $finance->feeSummary(), true, ['admin', 'finance'], 'finance');
    $router->add('GET', '/api/v1/finance/reconciliation', fn() => $finance->listReconciliations(), true, ['admin', 'finance'], 'finance');
    $router->add('POST', '/api/v1/finance/reconciliation', fn() => $finance->reconcileWebsitePayment(), true, ['admin', 'finance'], 'finance');
    $router->add('PUT', '/api/v1/finance/reconciliation/{id}', fn($p) => $finance->updateReconciliation($p), true, ['admin', 'finance'], 'finance');
    $router->add('DELETE', '/api/v1/finance/reconciliation/{id}', fn($p) => $finance->deleteReconciliation($p), true, ['admin', 'finance'], 'finance');

    $router->add('GET', '/api/v1/operations/timetable', fn() => $operations->listTimetables(), true, ['admin'], 'operations');
    $router->add('POST', '/api/v1/operations/timetable', fn() => $operations->saveTimetable(), true, ['admin'], 'operations');
    $router->add('PUT', '/api/v1/operations/timetable/{id}', fn($p) => $operations->updateTimetable($p), true, ['admin'], 'operations');
    $router->add('DELETE', '/api/v1/operations/timetable/{id}', fn($p) => $operations->deleteTimetable($p), true, ['admin'], 'operations');
    $router->add('GET', '/api/v1/operations/transport/routes', fn() => $operations->listTransportRoutes(), true, ['admin'], 'operations');
    $router->add('POST', '/api/v1/operations/transport/routes', fn() => $operations->createTransportRoute(), true, ['admin'], 'operations');
    $router->add('PUT', '/api/v1/operations/transport/routes/{id}', fn($p) => $operations->updateTransportRoute($p), true, ['admin'], 'operations');
    $router->add('DELETE', '/api/v1/operations/transport/routes/{id}', fn($p) => $operations->deleteTransportRoute($p), true, ['admin'], 'operations');
    $router->add('GET', '/api/v1/operations/transport/allocations', fn() => $operations->listTransportAllocations(), true, ['admin'], 'operations');
    $router->add('POST', '/api/v1/operations/transport/allocations', fn() => $operations->allocateTransportStudent(), true, ['admin'], 'operations');
    $router->add('PUT', '/api/v1/operations/transport/allocations/{id}', fn($p) => $operations->updateTransportAllocation($p), true, ['admin'], 'operations');
    $router->add('DELETE', '/api/v1/operations/transport/allocations/{id}', fn($p) => $operations->deleteTransportAllocation($p), true, ['admin'], 'operations');
    $router->add('GET', '/api/v1/operations/hostels', fn() => $operations->listHostels(), true, ['admin'], 'operations');
    $router->add('POST', '/api/v1/operations/hostels', fn() => $operations->createHostel(), true, ['admin'], 'operations');
    $router->add('PUT', '/api/v1/operations/hostels/{id}', fn($p) => $operations->updateHostel($p), true, ['admin'], 'operations');
    $router->add('DELETE', '/api/v1/operations/hostels/{id}', fn($p) => $operations->deleteHostel($p), true, ['admin'], 'operations');
    $router->add('GET', '/api/v1/operations/hostels/rooms', fn() => $operations->listHostelRooms(), true, ['admin'], 'operations');
    $router->add('POST', '/api/v1/operations/hostels/rooms', fn() => $operations->createHostelRoom(), true, ['admin'], 'operations');
    $router->add('PUT', '/api/v1/operations/hostels/rooms/{id}', fn($p) => $operations->updateHostelRoom($p), true, ['admin'], 'operations');
    $router->add('DELETE', '/api/v1/operations/hostels/rooms/{id}', fn($p) => $operations->deleteHostelRoom($p), true, ['admin'], 'operations');
    $router->add('GET', '/api/v1/operations/hostel/allocations', fn() => $operations->listHostelAllocations(), true, ['admin'], 'operations');
    $router->add('POST', '/api/v1/operations/hostel/allocations', fn() => $operations->allocateHostel(), true, ['admin'], 'operations');
    $router->add('PUT', '/api/v1/operations/hostel/allocations/{id}', fn($p) => $operations->updateHostelAllocation($p), true, ['admin'], 'operations');
    $router->add('DELETE', '/api/v1/operations/hostel/allocations/{id}', fn($p) => $operations->deleteHostelAllocation($p), true, ['admin'], 'operations');

    $router->add('GET', '/api/v1/hr/employees', fn() => $hr->listEmployees(), true, ['admin', 'hr'], 'hr');
    $router->add('POST', '/api/v1/hr/employees', fn() => $hr->createEmployee(), true, ['admin', 'hr'], 'hr');
    $router->add('PUT', '/api/v1/hr/employees/{id}', fn($p) => $hr->updateEmployee($p), true, ['admin', 'hr'], 'hr');
    $router->add('DELETE', '/api/v1/hr/employees/{id}', fn($p) => $hr->deleteEmployee($p), true, ['admin', 'hr'], 'hr');
    $router->add('GET', '/api/v1/hr/leave-requests', fn() => $hr->listLeaveRequests(), true, ['admin', 'hr', 'teacher'], 'hr');
    $router->add('POST', '/api/v1/hr/leave-requests', fn($_, $ctx) => $hr->leaveRequest($ctx), true, ['admin', 'hr', 'teacher'], 'hr');
    $router->add('PUT', '/api/v1/hr/leave-requests/{id}', fn($p) => $hr->updateLeaveRequest($p), true, ['admin', 'hr'], 'hr');
    $router->add('DELETE', '/api/v1/hr/leave-requests/{id}', fn($p) => $hr->deleteLeaveRequest($p), true, ['admin', 'hr'], 'hr');
    $router->add('POST', '/api/v1/hr/leave-requests/approve', fn($_, $ctx) => $hr->approveLeave($ctx), true, ['admin', 'hr'], 'hr');
    $router->add('GET', '/api/v1/hr/payroll/runs', fn() => $hr->listPayrollRuns(), true, ['admin', 'hr', 'finance'], 'hr');
    $router->add('GET', '/api/v1/hr/payroll/items', fn() => $hr->listPayrollItems(), true, ['admin', 'hr', 'finance'], 'hr');
    $router->add('POST', '/api/v1/hr/payroll/generate', fn() => $hr->generatePayroll(), true, ['admin', 'hr', 'finance'], 'hr');
    $router->add('DELETE', '/api/v1/hr/payroll/runs/{id}', fn($p) => $hr->deletePayrollRun($p), true, ['admin', 'hr'], 'hr');

    $router->add('POST', '/api/v1/admissions/register-parent', fn() => $admissions->registerParent(), false);
    $router->add('GET', '/api/v1/admissions/applications', fn() => $admissions->list(), true, ['admin'], 'admissions');
    $router->add('POST', '/api/v1/admissions/apply', fn() => $admissions->apply(), true, ['admin'], 'admissions');

    $router->add('GET', '/api/v1/reports/dashboard', fn() => $reports->dashboard(), true, ['admin', 'finance', 'hr'], 'reports');
    $router->add('GET', '/api/v1/reports/payroll-export', fn() => $reports->payrollExport(), true, ['admin', 'hr', 'finance'], 'reports');
};
