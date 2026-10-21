# Week 2 — MVC Foundation & Database Design

## Implemented in this starter
- Root `index.php` delegates to `public/index.php`.
- Minimal Router maps HTTP method + path to controller actions.
- `HomeController` and View/layout separation.
- Environment loading from `.env` and sample `.env.example`.
- PDO singleton connection with exceptions, `utf8mb4`, native prepared statements and logged technical errors.
- Session bootstrap with HttpOnly, SameSite=Lax and HTTPS-aware Secure cookie.
- Baseline response headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- Output escaping and CSRF token/verification helpers.
- Initial MySQL schema for roles, OTPs, courses/modules/lessons, per-student course access, payments, timed quizzes/questions/options/attempts/answers, assignments/submissions, lesson progress, notifications/recipients/replies and audit logs.
- Original public pages copied to `legacy-site/` for reference; not yet wired into the MVC router.

## Local setup (XAMPP)
1. Copy the `TSG-Academy-LMS` folder into XAMPP's `htdocs` directory.
2. Start Apache and MySQL from XAMPP Control Panel.
3. Open phpMyAdmin and create database `tsg_academy_lms` using `utf8mb4_unicode_ci`.
4. Import `database/migrations/001_initial_schema.sql`.
5. Copy `.env.example` to `.env`; set `APP_URL` to the folder URL (for example `http://localhost/TSG-Academy-LMS`) and update DB values if needed.
6. Visit `http://localhost/TSG-Academy-LMS/`.
7. Check PHP errors/logs if it does not load. Never commit `.env`.

## Important status
This is a **Week 1–2 foundation**, not a production-ready LMS. Login/registration/OTP, role middleware, real dashboards, payment review, quiz submission/scoring, assignments, reports and notification UI/API remain to be implemented and tested in subsequent modules. The database schema is a starting migration and must be validated against the company's final rules before production data is entered.

## Week 2 verification checklist
- [ ] Home route loads without a PHP fatal error.
- [ ] Unknown URL returns HTTP 404.
- [ ] Database connection works after `.env` is configured.
- [ ] All migration tables import successfully in MySQL 8.
- [ ] No `.env` or private uploaded files are exposed through the web server.
- [ ] PHP lint passes for all PHP files.
- [ ] Browser test on desktop and mobile widths.
