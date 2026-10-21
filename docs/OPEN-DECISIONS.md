# Open Company Decisions

These items remain configurable or unimplemented until approved.

1. **Weekly quiz question count:** the proposal and dashboard specify 20 MCQs; the latest instruction also requires 75. The schema has a per-quiz `question_count` field and defaults new rows to 75, but per-quiz authoring and enforcement are not implemented yet. Confirm the production default; preserve both values through configuration.
2. **Quiz policy:** duration, passing threshold, retakes/attempt limit, randomization, result visibility, release calendar, and application timezone for release cutoffs.
3. **Auto-submit policy:** implementation must submit on hidden tab, detectable focus loss, supported in-app navigation, and server deadline expiry as requested. Confirm student-facing policy wording only. Browser events are best-effort signals and cannot detect every browser/device scenario.
4. **Payment and access:** implement payment approval and the exact student/course access grant as distinct actions, following the latest instruction. Rejection resubmission and refund/reversal access policy still need approval.
5. **Commission:** proposed 15% is not approved. Confirm calculation base, fees/taxes/refunds adjustments, recipient, and payout timing before enabling.
6. **Invoices and certificates:** legal issuer details, numbering/tax fields, invoice retention, certificate eligibility/verification rules, and approved templates.
7. **Email and integrations:** SMTP provider, sender, designated payment-verification recipient, notification templates, retry schedule, payment methods, and any API credentials.
8. **Assignments and reporting:** allowed file formats/size, due-date and resubmission rules, grading, completed-task definition, late-work treatment, reporting denominators, and required filters.
9. **Announcement targeting:** all students or selected students by default; confirm that student replies are visible only to admins (recommended by the access model).
10. **Course lifecycle:** confirm existing students retain access when a course is hidden (recommended default), and approve archive/retention behavior for historical records.
11. **Authentication policy:** implementation uses proposed starter values of 10-minute OTP expiry, five OTP attempts, 60-second resend cooldown, three resends per 15 minutes, five login failures per 15 minutes, and 12-character minimum passwords. Confirm these, session idle/absolute lifetime, and whether Admin MFA is required.
12. **Email delivery:** provide the approved SMTP/mail provider, sender identity, credentials, and delivery/retry policy. The current adapter uses PHP's configured mail transport and environment-provided sender; actual delivery is not verified.
