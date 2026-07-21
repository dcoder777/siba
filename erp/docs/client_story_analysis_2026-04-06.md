# Client Backlog Analysis (2026-04-06)

Source file reviewed:
- `01_sprint_backlog_user_stories.csv`

Total stories analyzed: `44`

## 1) Coverage Summary By Epic

1. `E01 Access, Identity, And Role Governance`
- Current: `Partial`
- Implemented now:
  - Role universe aligned toward client matrix (`owner`, `admin`, `parent`, `teacher`, `driver`, plus finance/hr).
  - Multi-role assignment groundwork added (`user_role_assignments`).
  - Admin UI can now save role assignments per user.
  - Unauthorized/forbidden attempts are explicitly logged with actor + timestamp in `storage/logs/access_denials.log`.
- Remaining:
  - API token expiry harmonization checks across all channels.
  - API key-based sign-in policy if required in addition to bearer token login.

2. `E02 Admissions And Student Lifecycle`
- Current: `Partial`
- Existing coverage: student lifecycle records exist.
- Remaining:
  - Full applicant intake workflow and conversion event trail.
  - Counsellor review states and mandatory rejection remarks.

3. `E03 Academic Structure And Timetable`
- Current: `Partial`
- Existing coverage: subjects + timetable entities present.
- Remaining:
  - Normalized class/section ownership model with conflict prevention and versioning.

4. `E04 Attendance Operations`
- Current: `Partial`
- Existing coverage: student attendance records.
- Remaining:
  - Session-based attendance locking and correction workflow.
  - Staff attendance module and payroll feed export.

5. `E05 Assessments, Exams, Results, Assignments`
- Current: `Partial`
- Existing coverage: assignments, submissions, exam results.
- Remaining:
  - Lifecycle statuses (`scheduled/completed/cancelled`, etc.), publication workflow and notifications.

6. `E06 Fees, Invoices, Payments`
- Current: `Partial`
- Existing coverage: fee structures, dues, payments, reconciliation.
- Remaining:
  - Invoice lifecycle and atomic settlement model.
  - Overdue escalation and parent ledger API parity.

7. `E07 Payroll And Accounting`
- Current: `Partial`
- Existing coverage: payroll runs/items.
- Remaining:
  - Journal posting and balanced ledger controls.
  - Chart-of-accounts governance and reversal controls.

8. `E08 Transport`
- Current: `Partial`
- Existing coverage: routes and student allocations.
- Remaining:
  - Driver shift checklist and incident capture workflow.

9. `E09 Communications`
- Current: `Partial`
- Existing coverage: parent-teacher messaging.
- Remaining:
  - Broadcast scheduler, read/unread tracking, event-triggered notification automation.

10. `E10 Governance And Audit`
- Current: `Partial`
- Implemented now:
  - Access denial logging baseline.
- Remaining:
  - Full immutable before/after audit log store for critical transactions.
  - Soft-delete lifecycle with restore workflow by entity.

11. `E11 Sync And Integration Reliability`
- Current: `Not implemented`
- Remaining:
  - Event bus, retries, idempotent consumers, failed-job admin queue.

12. `E12 Reporting And Dashboards`
- Current: `Partial`
- Implemented now:
  - Reports module supports module-wise CSV export with period and contextual filters.
- Remaining:
  - Role-specific deep domain dashboards with KPI drilldown and metadata-rich export trails.

## 2) Adjustments Implemented In This Update

1. Multi-role assignments (R1 governance foundation)
- Added schema + migration support for `user_role_assignments`.
- Added admin UI role assignment save action per user.
- Create-user flow now stores optional multi-role assignments.

2. Role matrix alignment baseline
- Expanded role defaults to include `owner`, `parent`, `teacher`, `driver` in module mapping logic.
- Menu composition now supports merged module visibility from multiple active roles.

3. Access denial logging
- Added explicit logging for unauthorized/forbidden role/module attempts in API router.

4. Reporting support
- Reports module (already added in prior work) remains aligned with story export requirements (period + filters + CSV).

## 3) Recommended Next Sprint Execution Order

1. `R1` hardening:
- admissions intake workflow + applicant states
- class/section normalized model
- attendance session lock model
- role-permission matrix enforcement at API action level

2. `R2` transactional integrity:
- invoice status machine + payment idempotency/reversal
- event-triggered notifications + read state
- integration retry queue and monitoring

3. `R3` governance depth:
- journals and chart-of-accounts controls
- domain dashboards with role widgets
- compliance-grade audit trails with before/after snapshots

