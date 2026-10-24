# Implementation Plan

The work follows the requested dependency order. A phase is complete only after its server rules, UI, authorization, data changes, and relevant checks are implemented.

| Phase | Scope | Status |
| --- | --- | --- |
| A | Stabilize MVC/configuration/database boundaries, health check, XAMPP setup | Complete for the local development environment: health, session, routing, exception foundations, and initial schema verification |
| B | Student registration, OTP, login/logout, reset, sessions, CSRF, role/ownership checks, audit | Core flows implemented and smoke-checked with a test mail stub. Gmail-compatible SMTP and a new-student admin alert are implemented; private Gmail App Password configuration and real delivery checks are pending. XAMPP admin provisioning also needs environment verification. |
| C | Public site integration and the two real responsive dashboards | Public MVC pages integrated. Role-specific Student/Admin overview dashboards now read account, course, payment, quiz, progress, and notification data from the database; browser validation remains pending. |
| D | Course/content management, student-specific grants, access checks, progress | In progress: admin course/grant management, lesson authoring/editing/publishing, student grant checks, and lesson completion are implemented. Browser validation and any missing edge cases remain. |
| E | Manual payment verification, private receipts, explicit student/course access grant | In progress: student payment submissions, private receipt upload/download, admin approve/reject, and approval-triggered course access grants are implemented. SMTP delivery and full browser workflow validation remain. |
| F-H | Quiz management, timed attempts, anti-cheating submission, server grading/results | Implemented for local workflow: Admin quiz/question authoring and release checks, Student-owned timed attempts, best-effort focus/hidden-page submission, server deadline enforcement, grading, and private answer keys. Browser workflow validation and final production policy approval remain. |
| I | Assignments, submissions, review, progress status | Not started |
| J | Announcements, recipient notifications, unread status, private replies | Not started |
| K | Database-backed weekly/monthly admin reporting and period filters | Not started |
| L | Approved SMTP, invoices, certificates, and remaining proposal features | Not started; provider/legal decisions remain open |
| M | Security, performance, accessibility, backup/restore, release review | Not started |

Next work: validate the quiz workflow in the browser with a small draft assessment: author questions, release, start as an enrolled student, submit/timeout, and confirm the result. Then continue to Phase I assignments. Quiz-release notifications depend on the later notification phase and are not implemented yet.
