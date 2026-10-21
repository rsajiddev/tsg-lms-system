# Implementation Plan

The work follows the requested dependency order. A phase is complete only after its server rules, UI, authorization, data changes, and relevant checks are implemented.

| Phase | Scope | Status |
| --- | --- | --- |
| A | Stabilize MVC/configuration/database boundaries, health check, XAMPP setup | Complete for the local development environment: health, session, routing, exception foundations, and initial schema verification |
| B | Student registration, OTP, login/logout, reset, sessions, CSRF, role/ownership checks, audit | In progress: flows and migration 002 implemented; integration smoke checks pass. SMTP delivery and XAMPP admin provisioning need environment setup/verification |
| C | Public site integration and the two real responsive dashboards | Not started |
| D | Course/content management, student-specific grants, access checks, progress | Not started |
| E | Manual payment verification, private receipts, explicit student/course access grant | Not started; company workflow decisions remain open |
| F-H | Quiz management, timed attempts, anti-cheating submission, server grading/results | Not started; policies remain open |
| I | Assignments, submissions, review, progress status | Not started |
| J | Announcements, recipient notifications, unread status, private replies | Not started |
| K | Database-backed weekly/monthly admin reporting and period filters | Not started |
| L | Approved SMTP, invoices, certificates, and remaining proposal features | Not started; provider/legal decisions remain open |
| M | Security, performance, accessibility, backup/restore, release review | Not started |

Next work: Phase B authentication and authorization before adding protected business modules. The legacy public website still needs integration through the MVC app in Phase C.
