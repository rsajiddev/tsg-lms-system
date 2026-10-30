# TSG Academy LMS

PHP 8 / MySQL / MVC application that runs on XAMPP with no Composer packages, no CDN and no build step. The original marketing site is kept untouched in `legacy-site/` for reference; the LMS itself is served by a single front controller and Router.

This README is the single catalogue of what is implemented. Every feature that ships gets recorded here as it is finished, so one file answers "what does the system do today?".

## Quick start

1. Copy this folder into `htdocs/`.
2. Create the MySQL database `tsg_academy_lms` in phpMyAdmin.
3. Import `database/migrations/001_initial_schema.sql`.
4. Copy `.env.example` to `.env` and set `APP_URL`, the DB values, and the `MAIL_*` block (the sending mailbox, its app password and the internal inboxes). Every comment in `.env.example` states what its variable is for, so a new owner can configure the installation without reading any PHP.
5. Start Apache and MySQL, then open the project URL.
6. Create the first admin with `php bin/create-admin.php`.

`database/migrations/001_initial_schema.sql` is the canonical schema for fresh installs **and** for incremental upgrades. New schema changes belong in that file; its guarded `ALTER`/`INSERT` statements let an existing database pick up the additions when the updated file is imported again. Back up the database before re-importing. Mail transport must be configured before registration and password-reset OTPs can be delivered.

## Implemented features

### Public website
- Home page with course highlights, reviews and academy call-to-action.
- Course catalogue (`/courses`), per-course detail pages (`/courses/{slug}`), About, Contact.
- Legal pages: Privacy Policy, Refund Policy, Terms of Service.
- Public certificate verification (`/verify`) and certificate download (`/certificate/download`). The number a visitor types is the owner's Academy ID, and one ID legitimately opens several certificates — one per finished course — so the page lists every document behind it, keeps the verified/expired state per row, and each download names its course (`/certificate/download?cert_id=…&course_id=…`).
- Public course review submission (`/reviews`).
- `/health` reports database connectivity without exposing connection details.

### Accounts and security
- Student and admin registration with email OTP verification, resend with rate limits.
- Login/logout with per-IP and per-account throttling, password reset by OTP, session CSRF tokens on every mutating request.
- Role-aware middleware (`student` / `admin`), password hashing, output escaping, upload and file-download guards.
- Security settings page for the admin: authentication limits only — OTP window, login protection, registration limits, password-reset limits. Controls that belong to another feature were moved to that feature's page (attendance window → Students, lesson watch rule → Lesson library, certificate prefix/validity → Certificates), each saved by its own endpoint that validates just its own keys.

### Courses, modules and lessons
- Admin course builder: course records with slug, category, visibility, cover image upload, duration, level, pricing, plus explicit module/lesson sort order and publish/draft state.
- **Lesson editing & publishing** card on the Lesson library: one collapsible editor per lesson in the current list for content, video link and length, and publish/draft state — editing stays on the library page instead of the course page.
- **Lesson library** (`/admin/lessons`): the lesson home page — the **Add lesson** authoring form (target course, module, title, content, video link and length, status), the **Watch-time rule** card, every lesson across all courses in one searchable list with filters by course / status / video state, KPI cards (total, published, with video, missing length), completion reach per lesson, pagination, and *Edit lesson* anchors that jump to that lesson's editor below.
- Student enrolment management per course (grant/revoke access, expiry), with access enforced on every learning page.

### Course grading and completion
- A weighted grade matrix (`course_grades`) is stored per student and course and rebuilt automatically: **video 30%** (average watched share of published lessons, or completion when a lesson has no video), **quizzes 28%** (best submitted score across the course's lesson and module quizzes), **exercises 14%** and **project 14%** (best reviewed score per `exercise` / `mini_project` assignment), **final test 14%** (best score on the `final` quiz).
- A component the course does not offer is dropped and its weight is redistributed across the components that do exist, so a course without a project can still reach 100%. Which components were present is kept as a bitmask, and the course page labels an absent component "Not offered in this course" instead of showing it as 0%.
- Letter scale: `A+ ≥ 90`, `A ≥ 80`, `B+ ≥ 70`, `B ≥ 60`, `C ≥ 50`, `F < 50`. `C` is the pass bar.
- Weights and pass thresholds live in `app_settings` (`grade_weight_video|quiz|exercise|project|final`, `certificate_min_grade_percent`, `certificate_min_attendance_percent`) and are seeded once, so a manual edit survives a re-import. There is no admin edit UI for them yet.
- Recompute hooks run after *Complete lesson*, after a quiz is graded, after an assignment review, and whenever a student opens a course page. Every hook is fail-open: grading logs a failure and lets the student action succeed, and a database that predates the migration still renders (grades simply read as unavailable).
- Enrollment rows now carry a completion state: `active` → `completed` (set automatically once the pass bar is met and every published lesson is done) → `certified` (set when an admin issues the certificate). `revoked` is unchanged. All student-facing pages — course home, lessons, quizzes, assignments, coding challenges, sidebar badges and certificate requests — accept `active`, `completed` and `certified`, so finishing a course never locks the student out of it.
- Student surfaces: a status chip (Access active / Completed / Certified) plus the overall percentage and letter grade on each **My courses** card, and a *Completion & grading* panel on the course page with one bar per component, its weight, the overall grade and the certificate eligibility verdict.
- Admin surface: the grade and attendance captured at request time are shown on **Certificates** ("At request: A · 86.4% · attendance 100%"). The student roster page (`/admin/students`) has no enrollment table, so it carries no grade column.

### Lesson videos and watch time
- Video player on the lesson page, with resume-from-last-position and provider-aware embeds.
- Server-side watch-time accounting: the browser sends a heartbeat to `POST /learning/lesson/watch`, and the server only credits forward movement, capped per ping, with a minimum ping gap.
- Configurable completion rule: a student must watch `lesson_watch_completion_percent` (default 90%) of the video duration before the lesson can be marked complete. Setting lives in Admin → **Lesson library** → *Watch-time rule* (`POST /admin/lessons/watch-rule`, 10–100%).
- **Lessons** page for students (`/student/lessons`): all published lessons of active courses with a progress ribbon, course/status filters, per-lesson watched percentage, last-watched time, and Open / Resume / Review actions.
- Every video/watch column is probed at runtime: on a database that predates the video layer the pages still render and simply report no video data, with a migration notice for the admin.

### Assessments
- Quizzes with question banks, publishing, attempts, auto-grading, results and admin quiz reports (filterable, paginated) with retake requests and admin decisions.
- Coding challenges with an in-browser editor, submissions and admin review.
- Assignments with student submission, file upload and admin grading.

### Attendance
- A successful student login opens the day's attendance row as pending; the student confirms presence before the deadline, otherwise the row closes as absent. Grace window is configurable (`attendance_grace_minutes`, 1–120 minutes) from the *Attendance confirmation window* card on Admin → **Students** (`POST /admin/students/attendance`).

### Payments
- Manual payment flow: student submits proof, admin approves or rejects, revenue notifications, receipt PDF download for both sides, payment settings.

### Certificates
- Admin issues and rejects certificates, students request them, verification and download public endpoints.
- A certificate is printed with its owner's **Student ID** (`users.account_reference`) rather than a number minted per document, so the ID on the paper is the same one the student signs up with and the one they type into `/verify`. `certificates.certificate_id` is therefore indexed, not unique — the uniqueness that matters is `UNIQUE(student_id, course_id)`.
- The **Certificates** page (`/admin/certificates`) owns its own settings: the *Certificate settings* card edits `certificate_available_days` (`POST /admin/certificates/settings`), and an *Eligibility requirements* card above the queue states the three bars an admin is enforcing. The ID prefix no longer lives here — it moved to the *Student ID scheme* card on the admin's account settings page, because the same number identifies the account as well as the document.
- A request is gated on grade and attendance, not just lesson completion: the overall grade must reach `certificate_min_grade_percent` (default 50) and attendance — present as a share of decided days since access was granted — must reach `certificate_min_attendance_percent` (default 75). The refusal states the exact shortfall ("Overall grade is 46.33% (F); you need at least 50%."), and the same requirements are published on both sides: read-only on the Certificates page for the admin and in a collapsible *What you need for a certificate* panel on the student **My courses** page, plus on the course page before the student submits.
- Attendance is only enforced once at least one day has been decided, so a student who just enrolled is not blocked by an unmeasurable 0%. Pending days are ignored; only present and absent count.
- The grade, letter and attendance percentage achieved at request time are snapshotted onto the certificate row, so the record shows what the student had earned when they asked, and issuing sets the enrollment to `certified`.

### Communication and moderation
- Announcements (admin publish, threaded replies), student notifications inbox, review moderation queue.

### Outgoing email (SMTP, no mail libraries)
- `App\Services\MailService` speaks SMTP itself over `stream_socket_client` — STARTTLS or SSL, `AUTH LOGIN`, then a `multipart/alternative` message carrying the HTML body and a plain-text alternative. There is no Composer dependency and no reliance on PHP's `mail()`.
- **Everything a handover needs is in the `MAIL_*` block of `.env`.** Put the company mailbox in `MAIL_USERNAME` and `MAIL_FROM_ADDRESS` and its 16-character Google App Password (spaces removed) in `MAIL_PASSWORD`, and every automated message — student OTP, payment requests and decisions, certificate alerts, developer revenue summary — starts going out as that address. No address, name or password is written anywhere in the code, so the client changes credentials, tests and deploys without touching a template.
- Internal inboxes resolve through one cascade, so the sending address is never repeated: `MAIL_ADMIN_NOTIFICATION_ADDRESS` (the HR queue: new registrations and payment approvals) and `DEVELOPER_NOTIFICATION_MAIL_ADDRESS` (all three developer events — registration, approved share, monthly summary) each fall back to `MAIL_FROM_ADDRESS` when left blank, and `MAIL_CERTIFICATE_REQUEST_ADDRESS` falls back to the HR inbox. `MAIL_DEVELOPER_NAME` is the greeting on developer mail; a blank inbox skips that one copy without failing the event.
- `app/Services/EmailMessages.php` holds the wording, `app/Services/EmailLayout.php` the design. Each event is written per role, in the tone a company would send it: the student gets the approval-request acknowledgement and the approval welcome with the *Your Login Credentials* block (email, Student ID, password reference), HR and the developer get the detail tables, and the developer's monthly summary carries the per-student `No. / Name / Student ID / Course / Amount / Your share (15%)` table with totals. Signature blocks switch between the HR Department and the LMS Automated System, and the legal links are built from `COMPANY_SITE_URL`.
- Events covered: `otp.verify_email`, `otp.reset_password`, `otp.change_email`, registration (HR + developer), payment submitted (student, HR, developer with the 15% share), payment approved (student, the approving admin, developer), payment rejected (student), monthly revenue summary, certificate requested / issued / rejected.
- **Every attempt is logged.** One `email_logs` row per message with its template key, recipient, subject, `sent`/`failed` and `related_user_id`, so a delivery problem is visible in the admin workspace instead of only in the PHP error log. `related_user_id` names the student for every copy of that student's event — including the HR and developer copies — so one account's full mail history is queryable. The log is the *Email delivery log* card on Admin → **Announcements**, whose *Clear activity* button (`POST /admin/announcements/email-log/clear`) empties it between test rounds after a browser confirmation; the rows only lose their link to an account, never their record of what was sent.
- **The envelope decides whether a mailbox can render the message.** Each part of the `multipart/alternative` body must open with its own `Content-Type` and `Content-Transfer-Encoding: base64` header lines and have those headers cut off by a real blank line (CRLF CRLF) before the payload, with the boundary closing the body. When a part's header block is left open, Gmail files the whole part away as header noise and prints the base64 instead of the email — so the header lines are built with the same double-quoted PHP strings as the rest of the payload, and `tests/auth-flow-smoke.php` reads the wire bytes the way a client does: it splits on the boundary, separates each part's headers from its body, decodes base64 strictly and refuses a message that is not shaped like mail.

### In-app notifications and sidebar badges
- `App\Services\NotificationService` publishes to eight categories (announcement, lesson, quiz, assignment, course, payment, grade, certificate) and maps each to one of six sidebar areas: `notifications`, `lessons`, `courses`, `assignments`, `quizzes`, `payments`.
- Fan-out happens on the admin's own action, not on a cron: publishing or updating a lesson (`/admin/lessons`), a quiz (`/admin/quizzes`), an assignment (`/admin/assignments`) or a course (`/admin/courses`) notifies every enrolled student of that course; a payment submission or decision, an assignment review, a retake decision and a certificate request/issue/rejection notify that student alone. Each call carries the deep link the student should land on.
- The sidebar renders the unread count for its area on the entry itself (`dashboard-nav-badge` in `app/Views/layouts/header.php`), computed once per request by `unreadByArea()`, with the **Assignments** count additionally covering published assignments the student has not yet submitted. A zero renders no badge at all, and counts above 99 show `99+`.
- Re-publishing the same source within `COALESCE_MINUTES` (30) refreshes the existing notification instead of stacking duplicates, so an admin editing one lesson five times does not flood the inbox. Deleting a lesson, quiz, assignment or retake record — singly or through a bulk purge — retires the notifications that pointed at it, so no badge ever leads a student to a dead page.

### Account settings (student and admin)
- Student: `/student/settings`. Admin: `/admin/account`. Both render `app/Views/account/settings.php` inside the normal dashboard shell.
- What a user may change is the profile: display name (`POST /account/name`, 2–150 characters), password (`POST /account/password` — the current password must verify, the new one must be at least 12 characters and differ from the old one, failures are rate-limited per account, and the change is written to `audit_logs`) and email address, which is only applied after a six-digit OTP delivered to the new address (`POST /account/email/request`, `POST /account/email/confirm`); the pending change is shown on the page until it expires.
- Every account carries a public ID in `users.account_reference` — `TSG-CER-001` for a student, `TSG-ADM-00001` for an admin. It is stored, not derived, and it is the number quoted in emails, receipts and on certificates, so the student's own ID is what a visitor types into `/verify`.
- Admins only, on the same page: the **Student ID scheme** card (`POST /admin/account/identity-scheme`) sets the prefix and the first number every new Student ID is minted from (`student_id_prefix`, `student_id_next_number`), and its *Re-issue existing students* checkbox renumbers the whole roll oldest-first in one transaction — clearing the old references first so the unique index cannot collide, skipping numbers an admin already answers to, carrying already-issued certificates along with their owner and moving the counter past the last number handed out. New sign-ups continue from there. The card shows a live example and the student count, and a confirmation dialog repeats the values before they are saved.
- Also admins only: their **own** Admin ID is an editable field (`POST /admin/account/id`) behind a confirmation dialog that quotes the old and the new value and lists what a rename touches. An ID that is malformed or already taken by another account is refused and the old one stays. Both writes land in `audit_logs` (`account.identity_scheme_updated`, `account.id_updated`).
- A student's own ID is read-only on their page: it is printed on documents, so only the admin's scheme writes it. Admin → **Settings** (`/admin/settings`) stays what it was: authentication limits only. Cards on both settings pages follow the platform's 22px gap (16px below 640px).

### Testing tools: bulk delete
- Admin → **Assignments**, **Quizzes** and **Lesson library** each carry a *Delete all* card (`app/Views/components/purge-zone.php`) posting to `/admin/assignments/purge`, `/admin/quizzes/purge` and `/admin/lessons/purge`. The card can scope the delete to one course or leave it at "every course".
- Admin → **Students** carries the same card for the whole directory (`POST /admin/students/purge`, phrase `DELETE ALL STUDENTS`): it removes every student account with its enrolments, lesson progress, attendance, quiz and challenge attempts, assignment submissions, payments, grades, certificates, OTPs, sign-in events and uploaded files, plus the notifications that pointed at the removed payments, submissions and certificate requests. Courses, content, admin accounts and the email delivery log stay, and the Student ID counter keeps running so a re-registered student never inherits somebody else's printed reference. It is the reset that lets one sign-up be followed from registration mail to certificate mail.
- The safety is confirmation, not concealment: `App\Core\PurgeGuard` requires the phrase typed exactly (`DELETE ALL ASSIGNMENTS` / `DELETE ALL QUIZZES` / `DELETE ALL LESSONS` / `DELETE ALL STUDENTS`), a permanent-deletion checkbox and a server-side re-check, because a form can be bypassed with curl and a mistyped phrase must delete nothing.
- A purge removes the dependants with the records — submissions and uploaded files, attempts, answers and grades, stored private files — rebuilds the affected students' grades, retires the matching notifications and writes an audit row recording the scale of the deletion (`assignment.bulk_deleted`, `quiz.bulk_deleted`, `lesson.bulk_deleted`, `student.bulk_deleted`, `email_log.cleared`). Use it to clear data built up while content was being written before real student work begins.

### Dashboards and navigation
- Admin overview with KPI cards, top courses and operation panels; student overview with enrolments, progress and pending actions.
- Sidebar navigation is generated from one ordered list per role: numbering follows list order (inserting an entry never leaves duplicate numbers), each entry has its own icon, and the active entry is highlighted for the whole sub-route group.
- Consistent page shell across dashboards: topbar with search and breadcrumb, heading with actions, panels, metric grids, data-label responsive tables, empty states and notes; 22 px between major blocks, 20 px inside panels, 16 px grid gaps.
- On a phone (`max-width: 760px`) an admin data table marked `admin-table-stacked` stops being a table: the header row is taken out of the layout, each row becomes a bordered card and every cell a `label / value` line, so nothing is ever hidden behind a horizontal scroll. The workspace tables that list many columns — announcements, assignments, quizzes, challenges, payments, reviews, certificates — carry that class.

## Testing the lesson video and watch-time module

Work through this in order. Steps 1–3 are the happy path, 4–8 prove the rule is actually enforced.

**Step 0 — automated smoke test.** From the project folder run:

```bash
/Applications/XAMPP/xamppfiles/bin/php tests/lesson-watch-flow-smoke.php
```

It exercises the watch-time service against the database and exits non-zero on failure. Run the other smoke scripts in `tests/` the same way.

**Step 1 — attach a short video as admin.** Log in as admin → **Lesson library** → the *Add lesson* card: pick the target course, type a module title and lesson title, set the status to Published, put a clip you can measure in the video link — for example `https://www.w3schools.com/html/mov_bbb.mp4` — and set Video length to that clip's length (`10` seconds, shown as `0:10`). Save: you stay on the Lesson library with a confirmation, and the new lesson appears in the list below with `Video 0:10`.

**Step 2 — see the player as student.** Log in as a student who has access to that course → **Lessons** → open the lesson. The player must render, the watch bar must show `0%`, and the required watch time must be stated (`you must watch 90% …`).

**Step 3 — completion is blocked.** Without playing anything, open the lesson and try *Complete lesson*. The button stays locked (the hint under it reads "Unlocks after 90% watched") and submitting the form anyway must leave the lesson incomplete with the refusal message showing the time watched so far.

**Step 4 — watch time accrues.** Play the clip. Open DevTools → Network and watch for `POST …/learning/lesson/watch` roughly every 10 seconds; each response carries `watched`, `required` and `ratio`. In parallel verify from the database:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -h 127.0.0.1 -u root tsg_academy_lms \
  -e "SELECT student_id, lesson_id, watched_seconds, last_position_seconds, completed_at, updated_at
      FROM lesson_progress ORDER BY updated_at DESC LIMIT 5;"
```

`watched_seconds` must climb while the tab is playing and freeze when you pause or switch tabs.

**Step 5 — try to cheat it.** Each row is a separate attempt; all of them must fail to give free credit.

| Attempt | Expected behaviour |
| --- | --- |
| Seek straight to the end | Only the capped delta is credited (one ping ≈ 20 s max), so a 10 s clip can be satisfied but a 10 min clip cannot |
| Reload the page | `last_position_seconds` is restored and the player resumes; the earlier seconds are not re-credited |
| Switch browser tab | Heartbeats stop, `watched_seconds` stops growing |
| Send a forged heartbeat with a huge position | Request is rejected with `{"ok":false,"error":"position"}` |
| Send two heartbeats back to back | The second is ignored (minimum ping gap) |

Forge a heartbeat from the console on the lesson page (CSRF token is read from the form):

```js
const body = new FormData();
body.append('_csrf', document.querySelector('input[name="_csrf"]').value);
body.append('lesson_id', new URLSearchParams(location.search).get('lesson_id'));
body.append('position', '99999');
fetch('/TSG-Academy-LMS/learning/lesson/watch', { method: 'POST', body })
  .then(r => r.json()).then(console.log);
```

Honest limit: the guards (`MAX_DELTA_SECONDS = 20`, `MIN_PING_SECONDS = 8`) mean a hand-driven client can still earn roughly a third of the video length by seeking forward. Short demo clips are therefore weaker proof than a full-length lesson video — the rule throttles casual cheating, it is not DRM.

**Step 6 — admin sees the effect.** As admin, **Lesson library** shows the per-lesson completion reach and the watched percentages; the student row should now be in progress or complete.

**Step 7 — fallbacks.** A lesson with no video, or with a video but no length set, must remain completable the old way — the watch rule only applies when a duration exists. On a database that has not been re-imported with the current schema file, the video columns are missing: both lesson pages must still open, the admin page shows the migration notice and hides the video filter, and the student page drops the watch-tracking note.

**Step 8 — downstream effect.** Finish the last lesson of a course, then confirm lesson completion feeds the course progress used by the certificate request flow.

## Testing the grading engine and certificate eligibility

Re-import the schema first (back up the database), otherwise every grade read fails open and the panels stay empty:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root tsg_academy_lms < database/migrations/001_initial_schema.sql
```

Then, in order:

| Step | Action | Expected |
| --- | --- | --- |
| 1 | Open a course as an enrolled student | The *Completion & grading* panel shows five bars; components the course has no items for read **Not offered in this course** with a `—`, not 0% |
| 2 | Watch part of a video lesson, then finish it | The video bar rises with the watched share; `course_grades.video_pct` matches the average across published lessons |
| 3 | Submit a quiz attempt | The quiz bar updates without a page reload of your own — the next course page load recomputes it. Best attempt per quiz counts |
| 4 | Have an admin review an assignment | Exercise or project bar updates according to `assignment_type`; un-reviewed submissions do not count |
| 5 | Check **My courses** | Chip still reads *Access active* while the pass bar or lesson completion is unmet |
| 6 | Meet the bar (overall ≥ `certificate_min_grade_percent`, every published lesson complete) | Chip becomes **Completed**, the grade badge shows `86.4% · A`, and the course, lessons, quizzes and assignments remain open to the student |
| 7 | Request a certificate below the grade or attendance bar | Refused with the exact shortfall quoted on the page, and no certificate row created |
| 8 | Request it once eligible | Certificate row stores the snapshot (`overall_percent`, `letter_grade`, `attendance_percent`); **Certificates** shows "At request: …" |
| 9 | Admin issues it | Enrollment chip becomes **Certified**; the student keeps full access to the course |
| 10 | Verify weight redistribution | Remove/hide the only final quiz, reload the course page: `components_mask` loses that bit and the overall is recalculated over the remaining weights (it does not drop by 14%) |

Numbers to trust: attendance counts only decided days since `granted_at` (pending is ignored), and legacy `lesson_progress` rows written before the watch-time layer have no logged seconds, so an old completed lesson contributes 0% video unless it has no video length (then completion counts as 100%).

## Automated tests

`tests/` holds CLI smoke scripts that need only PHP and the database:

| Script | Covers | Status |
| --- | --- | --- |
| `lesson-watch-flow-smoke.php` | video providers, duration parsing, watch accrual caps, threshold auto-completion, replay safety | passes |
| `attendance-flow-smoke.php` | sign-in window, present mark, expiry sweep, duplicate guard, summaries, settings | passes |
| `auth-flow-smoke.php` | registration, delivery, the MIME envelope decoded the way a mail client decodes it, OTP activation, replay rejection, login, password reset, duplicate rejection, `email_logs`, rate limiting | passes |
| `email-notification-smoke.php` | every `MAIL_*` recipient resolves, all email events render as HTML and plain text, both alternatives of the envelope carry real CRLFs and decode cleanly, notification fan-out, badge counts, coalescing, source retirement | passes |
| `purge-tools-smoke.php` | wrong phrase deletes nothing, the four bulk deletes empty every dependant in foreign-key order, files/notifications/grades/audit handled, the delivery log keeps its rows when an account goes and clears on request | passes |
| `interface-render-smoke.php` | dashboard, announcements, student inbox, both account settings pages and all four purge workspaces render; sidebar badges count real unread work; `.env` / `.env.example` keys agree | passes |
| `certificate-verify-smoke.php` | one Academy ID opening several certificates, the multi-certificate banner, per-row expiry, the download link naming the live course, lower-case and malformed input | passes |
| `admin-identity-flow-smoke.php` | both admin identity POSTs end to end in a child process: scheme save and re-numbering, certificates carried along, counter, stale CSRF writing nothing, Admin ID rename with duplicate/malformed refusal, audit rows, a student bounced by the role guard | passes |

Scripts that write bulk rows only ever run against a scratch database, and the guard is part of the design: each refuses any `--database=` value that does not contain `tmp`, `check`, `scratch` or `test`, and creates and imports the schema itself when the database does not exist yet (`purge-tools`, `interface-render`, `certificate-verify`, `admin-identity`). `email-notification-smoke.php` takes `--database=` too but expects the schema to be imported first. The rest run on the development database and delete the fixture rows they create.

```bash
/Applications/XAMPP/xamppfiles/bin/php tests/auth-flow-smoke.php
/Applications/XAMPP/xamppfiles/bin/php tests/email-notification-smoke.php
/Applications/XAMPP/xamppfiles/bin/php tests/purge-tools-smoke.php --database=tsg_purge_check_tmp
/Applications/XAMPP/xamppfiles/bin/php tests/interface-render-smoke.php --database=tsg_render_check_tmp --drop=1
/Applications/XAMPP/xamppfiles/bin/php tests/certificate-verify-smoke.php --database=tsg_verify_check_tmp --drop=1
/Applications/XAMPP/xamppfiles/bin/php tests/admin-identity-flow-smoke.php --database=tsg_identity_check_tmp --drop=1
```

`auth-flow-smoke.php` does not stub the mailer. It starts a throwaway SMTP server on `127.0.0.1`, points `MAIL_*` at it and reads the messages the application actually puts on the wire, so the handshake, the login, the MIME envelope and the `email_logs` row are all exercised for real. Nothing leaves the machine. The `MAIL_*` values it needs are overridden inside the script, so it runs unchanged on a development `.env`.

Its parser is deliberately as unforgiving as a mailbox: every captured message is split on its boundary, each part's header block is separated from its payload, base64 is decoded in strict mode and a part whose headers are not terminated by a real blank line fails the run. That is the guard against the bug this script once sat through silently — a single-quoted `'\r\n'` in a part header sent the four characters to the server instead of a line break, and Gmail answered by showing the base64 text of every automated mail.

`admin-identity-flow-smoke.php` uses the same child-process trick for a different reason: the two admin identity handlers end in a redirect and an `exit`, which would take the parent down with them, so each POST is replayed in a throwaway process and every claim is read back out of the database rather than out of the HTML.

`tests/TEST-PLAN.md` lists the manual regression matrix that goes with the scripts above.

## Documentation

This README is the single feature catalogue for the project: what exists, where each control lives, and how to prove it works. Earlier planning, audit and decision records were kept in `docs/` and have been retired — recover any of them from git history (`git log --diff-filter=D -- docs/` to find the commit, then `git show <commit>:docs/OPEN-DECISIONS.md`).

**Status:** public site, authentication, courses/lessons, lesson video watch time, quizzes, coding challenges, assignments, attendance, payments, certificates, announcements, review moderation, account settings, automated email with delivery logging, notification badges and both dashboards are implemented, on top of the weighted grading engine with automatic `completed`/`certified` enrollment states and grade + attendance certificate eligibility. Do not deploy to production or load real student data before the pending security review and the outstanding business decisions (quiz policy, payment and access grants, commission, certificate and invoice details, authentication limits — the retired open-decisions list names them in full) are closed, and before the `MAIL_*` block has been filled in with the company mailbox and tested end to end against a real SMTP server: the automated messages have been verified against a loopback server, not against Gmail. Grading weights and the certificate thresholds are seeded settings with no admin editing screen yet, and the grading engine has only been verified numerically against a scratch database — its UI has not been walked through in a browser.
