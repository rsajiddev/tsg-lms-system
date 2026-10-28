# TSG Academy LMS

PHP 8 / MySQL / MVC application that runs on XAMPP with no Composer packages, no CDN and no build step. The original marketing site is kept untouched in `legacy-site/` for reference; the LMS itself is served by a single front controller and Router.

This README is the single catalogue of what is implemented. Every feature that ships gets recorded here as it is finished, so one file answers "what does the system do today?".

## Quick start

1. Copy this folder into `htdocs/`.
2. Create the MySQL database `tsg_academy_lms` in phpMyAdmin.
3. Import `database/migrations/001_initial_schema.sql`.
4. Copy `.env.example` to `.env` and set `APP_URL`, the DB values, and approved `MAIL_*` SMTP sender/transport settings.
5. Start Apache and MySQL, then open the project URL.
6. Create the first admin with `php bin/create-admin.php`.

`database/migrations/001_initial_schema.sql` is the canonical schema for fresh installs **and** for incremental upgrades. New schema changes belong in that file; its guarded `ALTER`/`INSERT` statements let an existing database pick up the additions when the updated file is imported again. Back up the database before re-importing. Mail transport must be configured before registration and password-reset OTPs can be delivered.

## Implemented features

### Public website
- Home page with course highlights, reviews and academy call-to-action.
- Course catalogue (`/courses`), per-course detail pages (`/courses/{slug}`), About, Contact.
- Legal pages: Privacy Policy, Refund Policy, Terms of Service.
- Public certificate verification (`/verify`) and certificate download (`/certificate/download`).
- Public course review submission (`/reviews`).
- `/health` reports database connectivity without exposing connection details.

### Accounts and security
- Student and admin registration with email OTP verification, resend with rate limits.
- Login/logout with per-IP and per-account throttling, password reset by OTP, session CSRF tokens on every mutating request.
- Role-aware middleware (`student` / `admin`), password hashing, output escaping, upload and file-download guards.
- Security settings page for the admin: OTP window, login protection, registration limits, password-reset limits.

### Courses, modules and lessons
- Admin course builder: course records with slug, category, visibility, cover image upload, duration, level, pricing, plus explicit module/lesson sort order and publish/draft state.
- Lesson editor (per lesson, under Lessons & publishing on the course page) for content, video link and length, and publishing status.
- **Lesson library** (`/admin/lessons`): the lesson home page — the **Add lesson** authoring form (target course, module, title, content, video link and length, status) plus every lesson across all courses in one searchable list with filters by course / status / video state, KPI cards (total, published, with video, missing length), completion reach per lesson, pagination, and deep links into the owning lesson editor.
- Student enrolment management per course (grant/revoke access, expiry), with access enforced on every learning page.

### Lesson videos and watch time
- Video player on the lesson page, with resume-from-last-position and provider-aware embeds.
- Server-side watch-time accounting: the browser sends a heartbeat to `POST /learning/lesson/watch`, and the server only credits forward movement, capped per ping, with a minimum ping gap.
- Configurable completion rule: a student must watch `lesson_watch_completion_percent` (default 90%) of the video duration before the lesson can be marked complete. Setting lives in Admin → Security settings → **Lesson video**.
- **Lessons** page for students (`/student/lessons`): all published lessons of active courses with a progress ribbon, course/status filters, per-lesson watched percentage, last-watched time, and Open / Resume / Review actions.
- Every video/watch column is probed at runtime: on a database that predates the video layer the pages still render and simply report no video data, with a migration notice for the admin.

### Assessments
- Quizzes with question banks, publishing, attempts, auto-grading, results and admin quiz reports (filterable, paginated) with retake requests and admin decisions.
- Coding challenges with an in-browser editor, submissions and admin review.
- Assignments with student submission, file upload and admin grading.

### Attendance
- A successful student login opens the day's attendance row as pending; the student confirms presence before the deadline, otherwise the row closes as absent. Grace window is configurable (`attendance_grace_minutes`).

### Payments
- Manual payment flow: student submits proof, admin approves or rejects, revenue notifications, receipt PDF download for both sides, payment settings.

### Certificates
- Admin issues and rejects certificates, students request them, prefix and availability window configurable, verification and download public endpoints.

### Communication and moderation
- Announcements (admin publish, threaded replies), student notifications inbox, review moderation queue.

### Dashboards and navigation
- Admin overview with KPI cards, top courses and operation panels; student overview with enrolments, progress and pending actions.
- Sidebar navigation is generated from one ordered list per role: numbering follows list order (inserting an entry never leaves duplicate numbers), each entry has its own icon, and the active entry is highlighted for the whole sub-route group.
- Consistent page shell across dashboards: topbar with search and breadcrumb, heading with actions, panels, metric grids, data-label responsive tables, empty states and notes; 22 px between major blocks, 20 px inside panels, 16 px grid gaps.

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

## Automated tests

`tests/` holds CLI smoke scripts that need only PHP and the database:

| Script | Covers | Status |
| --- | --- | --- |
| `lesson-watch-flow-smoke.php` | video providers, duration parsing, watch accrual caps, threshold auto-completion, replay safety | passes |
| `attendance-flow-smoke.php` | sign-in window, present mark, expiry sweep, duplicate guard, summaries, settings | passes |
| `auth-flow-smoke.php` | registration, OTP verify, password reset | **stale** — it stubs PHP `mail()`, which `MailService` no longer uses since OTPs moved to the SMTP socket transport, so its "one verification message" assertion always fails. Needs a mailer stub instead of a `mail()` override. |

`docs/TEST-PLAN.md` and `tests/TEST-PLAN.md` list the manual regression matrix.

## Documentation

- [Project audit](docs/PROJECT-AUDIT.md) — findings against the original codebase.
- [Implementation plan](docs/IMPLEMENTATION-PLAN.md) — the phased build order.
- [Open company decisions](docs/OPEN-DECISIONS.md) — items still waiting on a business answer.
- [Test plan](docs/TEST-PLAN.md) — manual and automated test matrix.
- [XAMPP setup guide](docs/SETUP-XAMPP.md) — local environment walkthrough.
- [Week 1 notes](docs/WEEK-1-AUDIT-AND-REQUIREMENTS.md) and [Week 2 notes](docs/WEEK-2-MVC-FOUNDATION.md) — the original audit and MVC foundation records.

**Status:** public site, authentication, courses/lessons, lesson video watch time, quizzes, coding challenges, assignments, attendance, payments, certificates, announcements, review moderation, admin settings and both dashboards are implemented. Do not deploy to production or load real student data before the pending security review and the open company decisions in `docs/OPEN-DECISIONS.md` are closed.
