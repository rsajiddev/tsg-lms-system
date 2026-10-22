# Implementation Plan

The work follows the requested dependency order. A phase is complete only after its server rules, UI, authorization, data changes, and relevant checks are implemented.

| Phase | Scope | Status |
| --- | --- | --- |
| A | Stabilize MVC/configuration/database boundaries, health check, XAMPP setup | Complete for the local development environment: health, session, routing, exception foundations, and initial schema verification |
| B | Student registration, OTP, login/logout, reset, sessions, CSRF, role/ownership checks, audit | Core flows implemented and smoke-checked with a test mail stub. Gmail-compatible SMTP and a new-student admin alert are implemented; private Gmail App Password configuration and real delivery checks are pending. XAMPP admin provisioning also needs environment verification. |
| C | Public site integration and the two real responsive dashboards | Public MVC pages integrated. Role-specific Student/Admin overview dashboards now read account, course, payment, quiz, progress, and notification data from the database; browser validation remains pending. |
| D | Course/content management, student-specific grants, access checks, progress | Not started |
| E | Manual payment verification, private receipts, explicit student/course access grant | Not started; company workflow decisions remain open |
| F-H | Quiz management, timed attempts, anti-cheating submission, server grading/results | Not started; policies remain open |
| I | Assignments, submissions, review, progress status | Not started |
| J | Announcements, recipient notifications, unread status, private replies | Not started |
| K | Database-backed weekly/monthly admin reporting and period filters | Not started |
| L | Approved SMTP, invoices, certificates, and remaining proposal features | Not started; provider/legal decisions remain open |
| M | Security, performance, accessibility, backup/restore, release review | Not started |

Next work: configure Gmail SMTP and `MAIL_ADMIN_NOTIFICATION_ADDRESS` in the private `.env`; verify student OTP, admin registration alert, password-reset email, account activation, and login. Then validate the Student/Admin dashboard views. Payment, access-grant, quiz-release, and announcement notifications depend on their later phases and are not implemented yet.
