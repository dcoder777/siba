# SIBA School ERP (Custom PHP + MySQL)

Custom School ERP backend built without frameworks, designed for:
- Web ERP panel
- Website integration
- Future mobile app integration (API-first)

## Tech
- PHP (custom core, no Laravel/Symfony)
- MySQL
- REST JSON API (`/api/v1/...`)
- Token-based auth + role-based access

## Modules Included (MVP)
- Student & Academic Management
- Financial Management
- Operations Management
- HR & Payroll
- Reporting & Dashboard
- API-ready architecture for mobile apps

## Project Structure
```text
public/                  # front controller
admin/                   # web admin UI (role-based menus)
config/                  # environment/config
core/                    # DB, router, auth, request/response
modules/
  Auth/
  Students/
  Academics/
  Finance/
  Operations/
  HR/
  Reports/
routes/                  # API route map
database/schema.sql      # schema + seed data
docs/openapi.yaml        # Swagger/OpenAPI spec
```

## Setup
1. Copy `.env.example` to `.env` and update DB values.
2. Create DB schema:
```sql
SOURCE H:/server/htdocs/SIBA_Public_School_erp/database/schema.sql;
```
3. Point Apache/Nginx doc root to:
```text
H:/server/htdocs/SIBA_Public_School_erp/public
```
4. Test health:
```http
GET /api/v1/health
```
5. Open ERP admin UI:
```text
http://localhost/SIBA_Public_School_erp/admin/login.php
```
6. Seed full dummy dataset:
```powershell
H:\server\php\php.exe H:\server\htdocs\SIBA_Public_School_erp\database\seed_dummy.php
```
7. Enable/refresh user module-access controls (super admin feature):
```powershell
H:\server\php\php.exe H:\server\htdocs\SIBA_Public_School_erp\database\migrate_user_access.php
```
8. Apply role-alignment + multi-role assignment migration (client R1 backlog alignment):
```powershell
H:\server\php\php.exe H:\server\htdocs\SIBA_Public_School_erp\database\migrate_backlog_r1_roles.php
```

## Default Login
- Email: `admin@siba.local`
- Password: `password`
- Super Admin can manage user creation + module-level access from Admin panel: `Dashboard -> User Access`

## API Notes
- Base path: `/api/v1`
- Auth: `Authorization: Bearer <token>`
- Login endpoint: `POST /api/v1/auth/login`
- Role-based route protection implemented at router level.
- Unauthorized/forbidden attempts are logged in `storage/logs/access_denials.log`.
- Full CRUD with pagination/filter/search for all major modules.

See detailed endpoint list in:
- `docs/api-endpoints.md`
- `docs/openapi.yaml`
- `docs/swagger-ui.html`

## Mobile App Readiness
- Stable versioned API namespace (`v1`)
- Stateless token auth
- JSON request/response standards
- Clear module boundaries for future scaling

## Next Build Phases Recommended
1. Add field-level validation rules and stronger data constraints per entity.
2. Introduce granular permission matrix (`role_permissions` table).
3. Add background jobs for monthly dues/payroll/reconciliation.
4. Add audit logs + activity timeline.
5. Add PHPUnit tests and CI pipeline.
