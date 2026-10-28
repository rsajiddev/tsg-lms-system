# Initial Test Plan

## Foundation smoke tests
- GET `/` returns 200 and shows the foundation page.
- GET `/this-route-does-not-exist` returns 404.
- GET `/health` returns a generic 200/503 dependency status without revealing DSN, credentials, or exception details.
- The router strips only an exact configured application base path (including a path boundary).
- Session cookie is HttpOnly, SameSite=Lax, scoped to the application path, and strict mode is enabled.
- Invalid DB credentials fail gracefully without exposing credentials or stack traces to the browser.
- `Security::escape` encodes HTML special characters.
- CSRF verification fails for a missing/wrong token and succeeds for the session token.

## Executed during this foundation phase
- XAMPP PHP 8.1.17 syntax check for all PHP files — passed.
- HTTP `GET /` — 200; page and app assets use configured base URL.
- HTTP `GET /this-route-does-not-exist` — 404.
- HTTP `GET /health` before schema setup — 503 with generic JSON only; no DB details leaked.
- Created the previously absent local `tsg_academy_lms` database and imported `001_initial_schema.sql` without dropping/overwriting any data — passed; 19 tables present.
- HTTP `GET /health` after import — 200 with `{"status":"ok","database":"ok"}`.
- Subdirectory smoke check with `APP_URL` set to `/TSG-Academy-LMS`: home — 200, unknown path — 404, session cookie path — `/TSG-Academy-LMS/`.
- `.env`/credentials disclosure check on health response — no credentials or DB error details returned.

## Authentication smoke checks
- The auth rate-limit table is included in `database/migrations/001_initial_schema.sql`.
- XAMPP PHP syntax check for all application and test PHP files — passed.
- `tests/auth-flow-smoke.php` against local MySQL with a test-only mail stub — passed: pending student registration, OTP activation and replay rejection, successful and failed login, password reset, duplicate registration behavior, and request throttling. Test user and associated rows were removed afterward.
- HTTP `GET /register` — 200 with CSRF token and escaped form fields.
- HTTP `GET /dashboard` without a session — redirects to `/login`.
- HTTP `POST /login` and `/register` without CSRF token — 419; no auth operation is performed.
- Real OTP email delivery, admin CLI provisioning, and verified student/admin browser sessions — not tested; no mail provider is configured and no production/admin account was created.

## Attendance and dashboard analytics checks
- `attendance_records` and the `attendance_grace_minutes` seed are appended to `database/migrations/001_initial_schema.sql`; importing the file into a scratch database created 31 tables, and the guarded seed keeps an admin-edited value across re-imports.
- `tests/attendance-flow-smoke.php` against a scratch database — passed: sign-in opens one pending row per UTC day, repeat sign-ins do not duplicate it, marking inside the window records present, an elapsed window is swept to absent and refuses a late mark, older pending rows are swept, the unique key blocks a second row for the same day, student and admin summaries stay consistent, and the grace value is honoured and clamped. The test student, its rows, and the stored grace value were restored afterwards.
- Dashboard analytics rendered server-side against a scratch database seeded with demo records, covering the admin 7/14/30-day ranges plus the student present and pending states — no PHP warnings and no failed reads.
- Countdown maths verified: a five-minute window renders 300 remaining seconds although PHP runs on Asia/Karachi while records are stored in UTC.
- Not tested in a browser: the live admin and student overview pages, chart appearance at 1050/903/760/560 px, and the real sign-in → mark-attendance click flow.

## Required before quiz release
- Deadline enforced on server even if client clock is changed.
- Student cannot submit another student's attempt.
- Correct answer key never appears in the student question payload.
- Attempt can be finalized only once; duplicate requests do not duplicate scoring.
- Hidden-tab/focus event records the policy reason; server rejects invalid attempt states.
- 75 questions × 4 options are supported; scoring for all-correct, all-wrong, unanswered and mixed responses is verified.
- Pass/fail boundary and rounding rules are explicit and tested.

## Required before announcements/reports release
- Student can only read/reply to notifications addressed to them.
- Admin can see reply author, body and timestamp.
- Unread count changes after opening a notification.
- Weekly/monthly report totals reconcile with underlying quiz and assignment records and use the configured timezone.
