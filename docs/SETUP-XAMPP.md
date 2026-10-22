# Local Setup — XAMPP

## Requirements

- XAMPP with PHP 8.1 or later, Apache, MySQL/MariaDB, and the `pdo_mysql` extension.
- MySQL 8 is the schema target. Check MariaDB compatibility before using a different server version.

## Setup

1. Keep the project under `xampp/htdocs/TSG-Academy-LMS`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin, create `tsg_academy_lms` using `utf8mb4_unicode_ci`.
4. For a fresh database, import only `database/migrations/001_initial_schema.sql`. It now contains the complete schema, course catalogue/prices, auth throttling, reviews table and seed rows. Do not import it over existing production data.
5. Keep the existing database/app values in `.env`; copy only the mail settings from `.env.example`. For Gmail testing use `smtp.gmail.com`, port `587`, `tls`, the full Gmail address as username/sender, and a Google App Password as `MAIL_PASSWORD` (enter its 16 characters without spaces). Set `MAIL_ADMIN_NOTIFICATION_ADDRESS` to the inbox that should receive new-student alerts; this is not an admin login. Google requires 2-Step Verification before an App Password can be created. Keep `.env` private; never commit it. Replace these test settings with the company's approved sender and SMTP credentials for delivery.
6. Open `http://localhost/TSG-Academy-LMS/`.
7. Check `http://localhost/TSG-Academy-LMS/health`. `{"status":"ok","database":"ok"}` confirms that PHP can connect and execute a basic query. An unavailable response contains no credentials; check the XAMPP PHP error log and local `.env` values.

## Admin provisioning

Public admin registration is not implemented or permitted. After importing the main schema, provision the first admin from the project directory with `php bin/create-admin.php "Admin Name" admin@example.com`. The command creates a random one-time password, stores only its hash, and refuses to run when an admin already exists. Save the generated password in an approved password manager. Never add a default production password or seed an admin account.

## Deployment notes

- The application uses `APP_TIMEZONE` for period calculations. Keep timestamps and reporting boundaries consistent with the approved timezone.
- Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, a strong session name, non-default DB credentials, and approved SMTP settings before deployment.
- `storage/` and source directories are denied by the root `.htaccess`. Private files must remain outside public storage and be delivered through authorized controllers.
- The current project has no migration runner. Keep the project handoff schema consolidated in `database/migrations/001_initial_schema.sql`; update it whenever a table or schema change is introduced. Back up existing databases before applying schema changes; never reset/drop tables to make a workflow pass.
- Registration creates a pending account before sending OTP. If SMTP delivery fails, the account cannot sign in until a code is successfully delivered and verified; use the verification page's resend action after fixing `MAIL_*` settings.
- For scheduled report generation in XAMPP/production, configure an operating-system scheduler only after report implementation. Dashboard-open generation is planned as the fallback.
- Back up both the database and private uploads. Test restoring them to a separate local database/storage location before production rollout.
