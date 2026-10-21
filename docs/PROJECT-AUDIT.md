# TSG Academy LMS — Project Audit

**Audit date:** 21 October 2026  
**Scope:** Current workspace, Week 1/2 notes, initial SQL schema, and supplied proposal/dashboard PDFs.

## What exists

- A minimal PHP 8.1 MVC entry point, exact-path router, home controller/view, shared layout, environment loader, PDO singleton, output escaping, and CSRF token helpers.
- One MySQL 8 initial schema with 19 tables covering users, email OTPs, courses/content/access, payments, quizzes/attempts/answers, assignments, progress, announcements/recipients/replies, and audit logs.
- The original public-site PHP pages, CSS, and JavaScript remain preserved under `legacy-site/`; the root Apache rules block direct access. MVC routes now provide the public Home, Courses, About, and Contact pages. Published course cards and both learning-mode prices are read from the LMS database.
- XAMPP `.env.example`, storage deny rule, and Week 1/2 documentation.

## What actually works

- The root entry point delegates to `public/index.php`; the router can serve the home route and a 404 for unknown paths.
- The environment loader reads `.env`; PDO uses exceptions, `utf8mb4`, and native prepared statements.
- XAMPP MySQL and PDO connectivity are verified. The configured database was absent at audit start; it was created locally and migration `001_initial_schema.sql` imported into the new database. No existing database was overwritten.
- XAMPP PHP 8.1.17 is available. PHP CLI is not on the shell PATH.
- Existing public pages are static/reference pages. Their search/filter and contact form are not connected to backend workflows.

## Incomplete or risky

- Authentication was added in Phase B: public Student registration, hashed email OTP verification, login/logout, password reset, rate limits, CSRF checks, role middleware, a protected dashboard shell, and CLI-only first-admin provisioning. Course CRUD, payment workflow, quiz engine, assignment workflow, reports, announcement UI, and dashboards beyond the shell remain incomplete.
- No migration runner or migration tracking table exists; only the initial import SQL is supplied.
- Session cookies lacked strict mode and were scoped to `/`; base-path routing accepted partial prefix matches. Both were corrected during this phase.
- No diagnostic route or top-level safe exception response existed. A minimal health check and centralized exception handling are now present.
- The schema has the right broad entities, but workflow constraints still need migration-level review. In particular, 4-option/one-correct-answer rules, selected-option/question consistency, and attempt finalization need transactional application enforcement. The proposal's invoice, payment-method/settings, email-delivery-log, and certificate entities are not present; topics are represented only indirectly through modules/lessons.
- The original static website remains reference material; its old contact form, course filters, and many student-dashboard mockups are not wired into the LMS. The current public Contact page uses the official email link, and course listings come from published database records.
- `.env.example` defaults to local debug mode. Production deployment must explicitly disable debug and configure HTTPS, SMTP, storage, and database credentials.
- This workspace has no `.git` metadata, so a tracked/untracked diff could not be checked.

## Conflicts and product requirements

- Both PDFs specify 20 MCQs per weekly quiz. The latest instruction also requests 75. Quiz count must remain configurable; the initial schema currently defaults to 75. The company must confirm the default before production use.
- Both PDFs recommend student-specific access after verified payment and keep approval/access actions distinct unless the company approves a combined action. The schema supports these records but the workflow is not implemented.
- The PDFs describe draft requirements and leave quiz duration, pass mark, retakes, release schedule, invoice/legal details, refunds, email recipients, and proposed 15% commission rules for company approval. No policy has been invented.
- Dashboard scope in both PDFs aligns with the two roles only: Student and Admin.

## Fixes made before dependent features

- Added `/health`, which checks the configured database with `SELECT 1` and reports only generic dependency status.
- Scoped session cookies to the app path, enabled strict session mode/cookie-only sessions, and made router base-path matching boundary-safe.
- Added a centralized exception handler that logs details and returns a generic production error response.
- Added student authentication and password recovery with single-use hashed OTP challenges, per-account/IP request throttles, live account/role checks for the dashboard, and a CLI-only generated-password admin provisioner.
- Added and applied versioned migration `002_auth_rate_limits.sql` locally. No real email provider is configured; actual OTP delivery still depends on approved mail transport configuration.
- Added and applied `003_published_course_catalogue.sql` locally. It records course categories/levels, separate Recorded Only and Live + Recorded prices, and the three published courses and six prices supplied in the company-site screenshot. No placeholder admin account was created; imported course rows have a null creator.

## Verification

- PHP syntax checks, local HTTP smoke checks, and 19-table schema import are recorded in `docs/TEST-PLAN.md`.
- The health output intentionally does not expose connection details.
