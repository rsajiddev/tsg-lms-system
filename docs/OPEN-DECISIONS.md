# Open Company Decisions

These items remain configurable or unimplemented until approved.

1. **Weekly quiz question count:** Admins can configure 1–75 questions per quiz, supporting both the proposal's 20 MCQs and the latest 75-question instruction. The Admin UI defaults new quizzes to 75; confirm the company-wide production default.
2. **Quiz policy:** Duration, pass threshold, and attempt limit are configurable per quiz (defaults are 20 minutes, 50%, and one attempt). Randomization, result visibility policy, release calendar, and application timezone for release cutoffs still need approval.
3. **Auto-submit policy:** timed attempts use a server-enforced deadline; hidden-tab and focus-loss browser events trigger best-effort auto-submission. Browser events cannot detect every browser/device scenario or guarantee delivery after a crash/offline event. Confirm student-facing wording and whether focus loss should submit in production.
4. **Payment and access:** implement payment approval and the exact student/course access grant as distinct actions, following the latest instruction. Rejection resubmission and refund/reversal access policy still need approval.
5. **Commission:** proposed 15% is not approved. Confirm calculation base, fees/taxes/refunds adjustments, recipient, and payout timing before enabling.
6. **Invoices and certificates:** legal issuer details, numbering/tax fields, invoice retention, certificate eligibility/verification rules, and approved templates.
7. **Email and integrations:** SMTP provider, sender, designated payment-verification recipient, notification templates, retry schedule, payment methods, and any API credentials.
8. **Assignments and reporting:** allowed file formats/size, due-date and resubmission rules, grading, completed-task definition, late-work treatment, reporting denominators, and required filters.
9. **Announcement targeting:** all students or selected students by default; confirm that student replies are visible only to admins (recommended by the access model).
10. **Course lifecycle:** confirm existing students retain access when a course is hidden (recommended default), and approve archive/retention behavior for historical records.
11. **Authentication policy:** implementation uses proposed starter values of 10-minute OTP expiry, five OTP attempts, 60-second resend cooldown, three resends per 15 minutes, five login failures per 15 minutes, and 12-character minimum passwords. Confirm these, session idle/absolute lifetime, and whether Admin MFA is required.
12. **Email delivery:** provide the approved SMTP/mail provider, sender identity, credentials, and delivery/retry policy. The current adapter uses PHP's configured mail transport and environment-provided sender; actual delivery is not verified.
