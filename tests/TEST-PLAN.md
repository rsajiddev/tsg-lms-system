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
- Known stale check, unrelated to this change: `tests/auth-flow-smoke.php` currently fails its "registration sends one message" assertion, because `MailService` now delivers over SMTP sockets instead of PHP's `mail()`, so the test's namespace mail stub records nothing. The authentication flow itself was not modified.

## Attendance and dashboard analytics checks
- `attendance_records` and the `attendance_grace_minutes` seed are appended to `database/migrations/001_initial_schema.sql`; importing the file into a scratch database created 31 tables, and the guarded seed keeps an admin-edited value across re-imports.
- `tests/attendance-flow-smoke.php` against a scratch database — passed: sign-in opens one pending row per UTC day, repeat sign-ins do not duplicate it, marking inside the window records present, an elapsed window is swept to absent and refuses a late mark, older pending rows are swept, the unique key blocks a second row for the same day, student and admin summaries stay consistent, and the grace value is honoured and clamped. The test student, its rows, and the stored grace value were restored afterwards.
- Dashboard analytics rendered server-side against a scratch database seeded with demo records, covering the admin 7/14/30-day ranges plus the student present and pending states — no PHP warnings and no failed reads.
- Countdown maths verified: a five-minute window renders 300 remaining seconds although PHP runs on Asia/Karachi while records are stored in UTC.
- Not tested in a browser: the live admin and student overview pages, chart appearance at 1050/903/760/560 px, and the real sign-in → mark-attendance click flow.

## Lesson video and watch-time checks
- `lessons.video_url/video_provider/video_duration_seconds`, `lesson_progress.watched_seconds/last_position_seconds/updated_at`, and the `lesson_watch_completion_percent` (90%) seed are appended to `database/migrations/001_initial_schema.sql`. Three import paths were run against scratch databases: a fresh install, a second import of the same file (idempotent, no error and no data change), and an upgrade from the previous revision of the file (the guarded `ALTER TABLE` patches added all six columns and the backfill re-classified a legacy row that had a `video_url` but `video_provider='none'` into `youtube`/`file`).
- The updated file was then imported into the local `tsg_academy_lms` development database after rehearsing the same upgrade on a full copy of it; row counts for users, courses, lessons and progress are unchanged.
- `tests/lesson-watch-flow-smoke.php` — passed against a scratch database and twice in a row against the local development database, leaving no rows and restoring the shared setting afterwards. It covers provider and embed detection, duration parsing and formatting, the video-less and unknown-length fallbacks that keep completion manual, capped forward-delta watch accounting, a heartbeat inside the minimum interval being refused credit and not allowed to move the remembered playhead, threshold auto-completion writing `completed_at`, seeking backwards and replaying past the end neither removing nor inflating credit, and percentage clamping back to the default.
- Lesson, admin course, and admin settings templates were rendered server-side with the shapes each controller passes — tracked file under the rule, completed YouTube embed, legacy schema without media, and the lesson editor with and without the video columns — with no PHP warnings. `public/assets/js/main.js` verified with `node --check`; touched PHP files verified with `php -l`.
- Not tested in a browser: the real heartbeat interval against the server rate guard, iframe behaviour while the tab is hidden, seek and resume UX, and the player at 600 px and below.
- Local development database defect found while testing, not introduced here: `tsg_academy_lms.quizzes` carries a stale InnoDB foreign-key entry, so deleting any course row fails with error 1451 even when no child rows exist.

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
