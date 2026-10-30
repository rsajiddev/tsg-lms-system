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

## Account identity and certificate ID checks
- `users.account_reference` (`VARCHAR(64) NULL` plus a unique key), the `student_id_prefix`/`student_id_next_number` seeds, the backfill that gives every existing account an ID, and the change of `certificates.certificate_id` from a unique key to the plain index `idx_certificate_public_id` are all in `database/migrations/001_initial_schema.sql`; the retired `certificate_id_prefix` setting is deleted by the same file so a re-import cannot leave the old switch behind.
- The upgrade path was rehearsed on a scratch copy and then applied to the local `tsg_academy_lms` development database: the existing student became `TSG-CER-001`, the existing admin `TSG-ADM-00001`, the counter sits at `2`, and `SHOW INDEX` reports `idx_certificate_public_id` as NON_UNIQUE. Re-importing the file changes nothing.
- `tests/certificate-verify-smoke.php` — passed on a scratch database and on a rerun, leaving no rows: one student holding a live and an expired certificate under one ID renders both cards and the "issued for 2 certificates" banner, only the live row offers a download and its link names the live course, a lower-case ID still resolves, and unknown or malformed IDs are refused before the database is asked.
- `tests/admin-identity-flow-smoke.php` — passed on a scratch database, twice in a row and with `--drop=1`: saving the scheme renumbers the roll oldest-first from the typed number, carries the issued certificates with their owners, advances the counter, flashes the count and writes one `account.identity_scheme_updated` audit row; a stale CSRF token refuses the request and moves nothing; renaming the Admin ID stores it upper-cased and audited, while a duplicate or malformed value is refused and the old ID stays; a student posting to either endpoint is bounced by the role guard before the handler runs and changes no setting, account or certificate.
- `tests/interface-render-smoke.php` now asserts the new admin and student settings markup (scheme card, editable Admin ID, read-only student ID on the student page) and renders the student ID scheme through `AccountIdentity` at service level; `tests/email-notification-smoke.php` asks the same service for the ID it expects in the approval mail, and creates its own admin when the database has none, because publishing with no actor falls back to the senior admin.
- Live HTTP checks against the running Apache: `GET /verify?cert_id=TSG-CER-001` — 200 with the certificate card markup; `GET /public/assets/css/pages.css` and `/public/assets/js/main.js` — served with the new verify/identity rules and the confirm-value sync; `GET /admin/account` without a session — 302 to `/login`.
- Not tested in a browser: the two new confirmation dialogs at narrow widths, their no-JS `:target` fallback, and clicking the re-issue flow through the admin's own settings page.

## E-mail rendering, delivery log and the test-data resets
- Defect found from a real Gmail message: the developer's monthly revenue summary arrived as a wall of base64. `MailService::multipart()` built each MIME part's `Content-Type` and `Content-Transfer-Encoding` lines from single-quoted PHP strings, so the four characters `\r\n` went on the wire instead of a line break, no part ever had a blank line ending its header block, and the client read the whole part as header noise. Every automated message was affected — student OTPs and welcomes, HR and developer copies, payment decisions, certificates — not only the developer summary.
- Fixed by building both alternatives through one closure that uses real `"\r\n"` line breaks, then verified on the wire: a loopback SMTP capture of the six `MailService` send methods — developer revenue report, new-student alert, OTP, payment submitted, payment decision, certificate issued — which fan out into eleven messages across the student, HR, developer and approving-admin mailboxes, and every one of them reports two correctly separated parts, a closing boundary, a clean strict-mode base64 decode and a body that round-trips byte for byte.
- `tests/auth-flow-smoke.php` now parses the captured bytes the way a mail client does — split on the boundary, headers separated from payload, `base64_decode(..., true)`, literal-`\r\n` leak check — and fails with *"Registration delivered a message a mail client cannot render"*. Proven as a regression guard: with the committed `MailService` restored the run fails, with the fix it passes.
- `tests/email-notification-smoke.php` adds a socket-free envelope check that calls the private `multipart()` through reflection and asserts real CRLFs, exactly two alternatives, exact header lines and a decode that returns the original text including a non-ASCII word.
- `tests/purge-tools-smoke.php` drives two more endpoints in child processes: `POST /admin/students/purge` (mistyped phrase deletes nothing; the right phrase removes the account with its enrolment, attendance, submission with its stored file, payment, certificate, grade, OTP, sign-in events and audit rows, keeps the course, the assignment, the admin and the announcement, unlinks rather than deletes the delivery-log row, retires the payment notification and audits `student.bulk_deleted`; an empty directory says so) and `POST /admin/announcements/email-log/clear` (a request without a valid CSRF token empties nothing, a valid one clears the rows and audits `email_log.cleared`, and an already empty log reports it).
- `tests/interface-render-smoke.php` renders the student directory and asserts its *Delete all students* card, and asserts the announcement workspace carries the *Clear activity* control.
- Mobile layout measured, not assumed: the announcement workspace was rendered from the development database and loaded in a browser frame at 390, 360 and 320 px. The document scroll width equals the viewport at all three, no element crosses the right edge and no container scrolls sideways; each delivery-log row shows every column as its own labelled line and the *Clear activity* button wraps under the card heading instead of squeezing it.
- Not tested: a real Gmail round trip. Everything above is verified against a loopback SMTP server, so the client-side rendering proof is the parser's, not Google's; and the two new destructive tools have not been clicked through in a logged-in browser session.

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
