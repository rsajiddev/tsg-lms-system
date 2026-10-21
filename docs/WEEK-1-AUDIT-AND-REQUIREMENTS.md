# Week 1 — Audit, Requirements & Architecture Decisions

## 1. Existing source audit

The supplied ZIP is a small public-facing PHP website, not yet a complete LMS. It contains `index.php`, `courses.php`, `about.php`, `contact.php`, shared header/footer, CSS and JavaScript. The current courses are hard-coded in PHP; the search/filter controls are primarily presentation and are not connected to a database-backed course catalogue. The contact form has no server-side submission handler. Links point to login/register/verify and policy pages that are not present in the supplied source set. The shared header loads Tailwind and Font Awesome from CDNs.

**Preserve:** branding, copy, existing public-page layout, CSS assets and header/footer as a visual/reference baseline.

**Do not treat as complete:** authentication, database, student/admin authorization, payment processing, real search/filtering, quizzes, assignments, reports, notifications, email, invoices, certificates or audit trails.

**Packaging cleanup:** do not ship `.git/`, `__MACOSX/`, OS metadata or secrets in the production package. Existing pages are preserved in `legacy-site/` as a reference, separate from the new MVC application.

## 2. Confirmed product boundaries

- Stack: PHP 8+, MySQL, HTML, CSS and JavaScript; local development via XAMPP.
- Exactly two application roles/dashboards: **Student** and **Admin**. No Instructor role or Instructor Dashboard.
- Public visitors can browse published public course information only.
- Student private records/content are accessible only to that student and only with active course access.
- Admin course, payment, quiz, assignment, access and notification actions require server-side authorization and should be audited.
- Payment approval must not unlock every student: access is granted to the exact student + exact course.
- Hiding/archiving a course must preserve historical payment, invoice, quiz, progress and audit data.

## 3. Added requirements from the latest planning discussion

### Timed quiz and anti-cheating handling
- Quiz duration is configurable (e.g. 15 or 20 minutes); the server stores `started_at` and `deadline_at`.
- When the page becomes hidden or loses focus, the browser sends a best-effort auto-submit request. Navigating away from the quiz also triggers submission when feasible.
- The server independently enforces the deadline, validates attempt ownership/status and accepts only one final submission. Browser-side JavaScript is not a security boundary; it cannot guarantee detection of every cheating method or browser crash/offline case.
- Record submission reason (`manual`, `tab_hidden`, `window_blur`, `deadline`) for review. Avoid claiming that browser signals prove cheating; they are assessment-policy events.

### MCQ scoring
- Support configurable question counts; latest user requirement describes 75 MCQs, each with four options. The current schema default is 75, while the earlier proposal described 20 MCQs per weekly quiz. **This is a scope discrepancy to confirm with the company.** The schema is configurable so either rule can be used without redesign.
- Correct answers stay server-side and are not sent to the student during an attempt.
- On final submission, the server calculates correct count, awarded marks, total marks, percentage and pass/fail. Pass mark is configurable; the starter schema defaults to 50% only as a provisional setting, not a company-approved rule.

### Reports and notifications
- Generate weekly and monthly admin reports from submitted quiz results, graded assignments, lesson progress and completed tasks; define date/timezone boundaries and denominator rules before final report implementation.
- Admin can publish announcements. Students receive per-user notification records with unread/read state and can reply. Admin can view the reply, student, timestamp and announcement thread.

## 4. Acceptance criteria (foundation)

- Existing public site source is preserved separately.
- New app starts through one front controller and routes through a Router.
- Configuration and database credentials are externalized; `.env` is ignored by Git.
- PDO is configured for exceptions, UTF-8 and native prepared statements.
- Output escaping and CSRF helpers exist for subsequent form workflows.
- Initial relational schema includes only student/admin roles and the core entities needed for courses, access, payments, quizzes, assignments, notifications, replies and audit history.
- No claim is made that authentication or business workflows are already complete; they are later implementation tasks.

## 5. Company decisions to confirm before production behaviour is locked

1. Weekly quiz question count: proposal says 20 MCQs per weekly quiz; latest instruction says 75. Current schema supports a configurable count and defaults to 75 pending confirmation.
2. Passing score: provisional 50%; company must approve.
3. Quiz attempts: schema defaults to one attempt; confirm retries/retakes.
4. Auto-submit events: tab hidden/window blur/navigation are currently planned to auto-submit, with no warning first per the latest instruction. Confirm whether a warning grace period is desired.
5. Assignment task types, file formats, max upload size, grading and resubmission rules.
6. Reports: which metrics count as “completed tasks,” whether late work counts, and required date/timezone boundaries.
7. Announcement audience: all students or selected students; whether student replies are public to admin only or visible to other students (recommended: admin only).
8. Payment approval/unlock, rejection/resubmission, invoice details, SMTP provider and any third-party integrations.

## 6. Test-first workflow

For each module: write acceptance criteria → implement server-side rules → connect UI → run positive tests → run permission/negative tests → regression test existing pages → record result. Never move a payment, quiz, access-control or upload workflow to “done” based on UI-only testing.
