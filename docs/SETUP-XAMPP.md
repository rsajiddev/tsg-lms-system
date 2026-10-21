# Local Setup — XAMPP

## Requirements

- XAMPP with PHP 8.1 or later, Apache, MySQL/MariaDB, and the `pdo_mysql` extension.
- MySQL 8 is the schema target. Check MariaDB compatibility before using a different server version.

## Setup

1. Keep the project under `xampp/htdocs/TSG-Academy-LMS`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin, create `tsg_academy_lms` using `utf8mb4_unicode_ci`.
4. Import `database/migrations/001_initial_schema.sql` into that empty database. Do not import it over existing production data.
5. Apply each later versioned migration in order: `002_auth_rate_limits.sql`, then `003_published_course_catalogue.sql`. Migration 003 adds course categories/levels and separate Recorded Only / Live + Recorded prices, then imports the three courses shown on the supplied Academy site screenshot. Imported public catalogue rows have no admin creator; future admin-created courses remain linked to the creating admin.
6. Copy `.env.example` to `.env`; configure `APP_URL`, `APP_TIMEZONE`, database values, and an approved mail sender/transport for this machine. Keep `.env` private.
7. Open `http://localhost/TSG-Academy-LMS/`.
8. Check `http://localhost/TSG-Academy-LMS/health`. `{"status":"ok","database":"ok"}` confirms that PHP can connect and execute a basic query. An unavailable response contains no credentials; check the XAMPP PHP error log and local `.env` values.

## Admin provisioning

Public admin registration is not implemented or permitted. After applying migration 002, provision the first admin from the project directory with `php bin/create-admin.php "Admin Name" admin@example.com`. The command creates a random one-time password, stores only its hash, and refuses to run when an admin already exists. Save the generated password in an approved password manager. Never add a default production password or seed an admin account.

## Deployment notes

- The application uses `APP_TIMEZONE` for period calculations. Keep timestamps and reporting boundaries consistent with the approved timezone.
- Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, a strong session name, non-default DB credentials, and approved SMTP settings before deployment.
- `storage/` and source directories are denied by the root `.htaccess`. Private files must remain outside public storage and be delivered through authorized controllers.
- The current project has no migration runner. Apply future versioned migrations in order after a backup; never reset/drop tables to make a workflow pass.
- For scheduled report generation in XAMPP/production, configure an operating-system scheduler only after report implementation. Dashboard-open generation is planned as the fallback.
- Back up both the database and private uploads. Test restoring them to a separate local database/storage location before production rollout.
