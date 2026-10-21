# TSG Academy LMS

PHP 8+ / MySQL / MVC application for XAMPP. The existing public website is preserved in `legacy-site/` as a reference; the LMS currently runs through a front controller and Router.

## Quick start
1. Place this folder in `htdocs/`.
2. Create MySQL database `tsg_academy_lms` in phpMyAdmin.
3. Import `database/migrations/001_initial_schema.sql`.
4. Apply `database/migrations/002_auth_rate_limits.sql`.
5. Copy `.env.example` to `.env` and configure `APP_URL`, DB values, and approved mail sender/transport settings.
6. Start Apache/MySQL and open the project URL.

See [the project audit](docs/PROJECT-AUDIT.md), [implementation plan](docs/IMPLEMENTATION-PLAN.md), [open company decisions](docs/OPEN-DECISIONS.md), [test plan](docs/TEST-PLAN.md), and [XAMPP setup guide](docs/SETUP-XAMPP.md), along with the original [Week 1](docs/WEEK-1-AUDIT-AND-REQUIREMENTS.md) and [Week 2](docs/WEEK-2-MVC-FOUNDATION.md) notes.

**Status:** foundation and authentication flows are implemented; course, payment, assessment, reporting, and announcement workflows are still pending. `/health` checks basic database connectivity without exposing connection details. Student OTP email delivery requires configured mail transport. Do not deploy this application to production or add real student data before completing the security review.
