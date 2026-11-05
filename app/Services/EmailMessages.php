<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The wording and routing of every automated email, kept separate from SMTP transport so
 * a message can be reviewed (or rendered by a CLI harness) without sending anything.
 *
 * Each builder returns a list of messages. A message is:
 *   ['template' => stable event key stored in email_logs,
 *    'to'       => recipient address,
 *    'student'  => optional user id so the send can be attributed to one account,
 *    'subject'  => inbox subject line,
 *    'view'     => EmailLayout view array]
 *
 * Recipients are resolved from the environment only — see MailService::hrAddress(),
 * developerAddress() and certificateAddress(), all of which deliver to the sending
 * mailbox — so a client redirects all mail by editing the MAIL_* block.
 */
final class EmailMessages
{
    /** Developer revenue-share percentage quoted in internal emails. */
    public const SHARE_PERCENT = MailService::DEVELOPER_SHARE_PERCENT;

    /* ---------------------------------------------------------------------- auth & OTP */

    public static function otp(string $purpose, string $name, string $code, int $validForMinutes): array
    {
        $first = trim(explode(' ', $name)[0] ?? '');
        $greeting = $first !== '' ? 'Dear ' . $first . ',' : 'Hello,';
        $expiry = 'This code expires in ' . $validForMinutes . ' minute' . ($validForMinutes === 1 ? '' : 's') . ' and can only be used once.';
        $plans = [
            'verify_email' => [
                'subject' => 'Verify your email address',
                'lead' => 'Welcome to ' . EmailLayout::company()['short'] . '. Use the code below to confirm your email address and activate your account.',
                'notice' => $expiry . ' If you did not request it, you can ignore this message — no account will be created.',
            ],
            'reset_password' => [
                'subject' => 'Reset your account password',
                'lead' => 'We received a request to reset the password for your account. Use the code below to choose a new one.',
                'notice' => $expiry . ' If you did not request a password reset, please contact our team immediately — your account details may be at risk.',
            ],
            'change_email' => [
                'subject' => 'Confirm your new email address',
                'lead' => 'Use the code below to confirm the new email address on your account. Your previous address stays active until you do.',
                'notice' => $expiry . ' If you did not request this change, please update your password and notify our team.',
            ],
        ];
        $plan = $plans[$purpose] ?? $plans['verify_email'];
        return [[
            'template' => 'otp.' . $purpose,
            'to' => '',
            'subject' => $plan['subject'],
            'view' => [
                'audience' => 'student',
                'preheader' => 'Your verification code is ' . $code . '.',
                'greeting' => $greeting,
                'paragraphs' => [$plan['lead']],
                'code' => $code,
                'closing' => ['Thank you for keeping your account secure. Please never share this code with anyone, including our staff.'],
                'notice' => $plan['notice'],
                'signature' => EmailLayout::SIGN_ACADEMY,
            ],
        ]];
    }

    /* --------------------------------------------------------------------- register */

    /**
     * Registration completed by a student (no payment submitted yet).
     * Keys: student_id, student_name, student_email.
     */
    public static function registration(array $student): array
    {
        $details = self::studentDetails($student);
        $details[] = ['label' => 'Registered On', 'value' => self::when('')];
        return [
            [
                'template' => 'registration.hr',
                'to' => MailService::hrAddress(),
                'student' => (int) ($student['student_id'] ?? 0),
                'subject' => 'New Student Registration',
                'view' => [
                    'audience' => 'hr',
                    'preheader' => $student['student_name'] . ' just registered on the LMS.',
                    'greeting' => 'Dear HR Team,',
                    'paragraphs' => ['A new student has registered on the LMS. Their payment approval request will arrive as soon as they submit their course payment.'],
                    'heading' => 'Student Details',
                    'details' => $details,
                    'closing' => ['Course access is granted automatically once the payment is approved.'],
                    'cta' => ['label' => 'Open the payment queue', 'url' => self::url('/admin/payments')],
                    'signature' => EmailLayout::SIGN_SYSTEM,
                ],
            ],
            [
                'template' => 'registration.developer',
                'to' => MailService::developerAddress(),
                'student' => (int) ($student['student_id'] ?? 0),
                'subject' => 'New Student Registration, Payment Not Yet Submitted',
                'view' => [
                    'audience' => 'developer',
                    'preheader' => $student['student_name'] . ' registered; no payment on file yet.',
                    'greeting' => MailService::developerGreeting(),
                    'paragraphs' => ['A new student has registered on the platform. The details are below for your records.'],
                    'heading' => 'Student Details',
                    'details' => $details,
                    'closing' => ['Status: Awaiting payment submission. Your share will be confirmed once the admin approves the payment.'],
                    'signature' => EmailLayout::SIGN_SYSTEM,
                ],
            ],
        ];
    }

    /* --------------------------------------------------------------------- payments */

    /**
     * Payment approval request submitted by a student.
     * Keys: student_id, student_name, student_email, course_title, amount, currency,
     * reference, submitted_at.
     */
    public static function paymentSubmitted(array $payment): array
    {
        $company = EmailLayout::company();
        $name = self::name($payment);
        $course = self::course($payment);
        $amount = MailService::money(self::amount($payment), self::currency($payment));
        $share = MailService::money(self::amount($payment) * self::SHARE_PERCENT / 100, self::currency($payment));
        $submitted = self::when((string) ($payment['submitted_at'] ?? ''));
        $details = array_merge(self::studentDetails($payment), [
            ['label' => 'Course', 'value' => $course],
            ['label' => 'Amount Paid', 'value' => $amount],
            ['label' => 'Transaction Reference', 'value' => (string) ($payment['reference'] ?? '—')],
            ['label' => 'Submitted On', 'value' => $submitted],
        ]);

        return [
            [
                'template' => 'payment.submitted.student',
                'to' => (string) ($payment['student_email'] ?? ''),
                'student' => (int) ($payment['student_id'] ?? 0),
                'subject' => "We've Received Your Payment Approval Request",
                'view' => [
                    'audience' => 'student',
                    'preheader' => 'Your payment approval request for ' . $course . ' is with our team.',
                    'greeting' => 'Dear ' . $name . ',',
                    'paragraphs' => [
                        'Thank you for choosing ' . $company['short'] . '. We have received your payment approval request of ' . $amount . ' for ' . $course . ', and our team will verify your payment receipt and approve it as soon as possible. You will be notified the moment it is done.',
                        'We are delighted to welcome you to our professional community. Choosing to invest in your skills is a brave and valuable decision, and we are proud that you have taken this step. We hope you will approach this new journey with dedication and enthusiasm. We will be with you at every stage, not only teaching you skills but preparing you to work at industry level, so that you can deliver real, production-quality products and not just practice projects.',
                    ],
                    'heading' => 'Request Summary',
                    'details' => $details,
                    'closing' => [
                        'Once your payment has been approved, your login details and Student ID will be sent to you from our official email address. You can then sign in to your LMS portal and complete your onboarding.',
                        'Congratulations once again, and welcome to ' . $company['short'] . '!',
                    ],
                    'notice' => 'Our team aims to verify every payment within 24 hours of submission.',
                    'legal' => true,
                    'signature' => EmailLayout::SIGN_HR,
                ],
            ],
            [
                'template' => 'payment.submitted.hr',
                'to' => MailService::hrAddress(),
                'student' => (int) ($payment['student_id'] ?? 0),
                'subject' => 'New Student Registration, Payment Approval Pending',
                'view' => [
                    'audience' => 'hr',
                    'preheader' => 'Verify and approve ' . $name . "'s payment within 24 hours.",
                    'greeting' => 'Dear HR Team,',
                    'paragraphs' => ['A new student has registered on the LMS and submitted a payment approval request. Please verify the payment receipt and approve it from the admin dashboard within 24 hours.'],
                    'heading' => 'Student Details',
                    'details' => $details,
                    'closing' => ['Once the payment is verified and approved, the student will automatically be notified with their login details.'],
                    'cta' => ['label' => 'Review this payment', 'url' => self::url('/admin/payments')],
                    'signature' => EmailLayout::SIGN_SYSTEM,
                ],
            ],
            [
                'template' => 'payment.submitted.developer',
                'to' => MailService::developerAddress(),
                'student' => (int) ($payment['student_id'] ?? 0),
                'subject' => 'New Student Registration, Payment Approval Requested',
                'view' => [
                    'audience' => 'developer',
                    'preheader' => $name . ' requested approval for a ' . $course . ' payment.',
                    'greeting' => MailService::developerGreeting(),
                    'paragraphs' => ['A new student has registered and requested payment approval. The details are below for your records.'],
                    'heading' => 'Student Details',
                    'details' => array_merge($details, [['label' => 'Your Share (' . self::SHARE_PERCENT . '%)', 'value' => $share]]),
                    'closing' => ['Status: Pending admin approval. Your share will be confirmed once the payment is approved.'],
                    'signature' => EmailLayout::SIGN_SYSTEM,
                ],
            ],
        ];
    }

    /**
     * Admin decision on a payment submission.
     * Keys: as paymentSubmitted plus decision (approved|rejected), note, decided_at,
     * receipt_number, reviewer_name, reviewer_email.
     */
    public static function paymentDecision(array $payment): array
    {
        $approved = ((string) ($payment['decision'] ?? '')) === 'approved';
        $company = EmailLayout::company();
        $name = self::name($payment);
        $course = self::course($payment);
        $email = (string) ($payment['student_email'] ?? '');
        $amount = MailService::money(self::amount($payment), self::currency($payment));
        $share = MailService::money(self::amount($payment) * self::SHARE_PERCENT / 100, self::currency($payment));
        $decided = self::when((string) ($payment['decided_at'] ?? ''));
        $note = trim((string) ($payment['note'] ?? ''));
        $studentId = MailService::studentId((int) ($payment['student_id'] ?? 0));

        if (!$approved) {
            $details = [
                ['label' => 'Course', 'value' => $course],
                ['label' => 'Amount Reviewed', 'value' => $amount],
                ['label' => 'Transaction Reference', 'value' => (string) ($payment['reference'] ?? '—')],
                ['label' => 'Reviewed On', 'value' => $decided],
            ];
            if ($note !== '') $details[] = ['label' => 'Review Note', 'value' => $note];
            return [[
                'template' => 'payment.rejected.student',
                'to' => $email,
                'student' => (int) ($payment['student_id'] ?? 0),
                'subject' => 'Update on Your Payment Approval Request',
                'view' => [
                    'audience' => 'student',
                    'preheader' => 'Your payment for ' . $course . ' could not be verified.',
                    'greeting' => 'Dear ' . $name . ',',
                    'paragraphs' => [
                        'Thank you for your patience. After reviewing your submission we were unable to verify the payment of ' . $amount . ' for ' . $course . '. This usually means the transaction reference or the payment receipt needs a correction.',
                        'Please submit corrected payment details from the course catalogue, or contact our team if you believe this decision was made in error. Your course access will be activated immediately once the payment is verified.',
                    ],
                    'heading' => 'Review Details',
                    'details' => $details,
                    'cta' => ['label' => 'Open the course catalogue', 'url' => self::url('/courses')],
                    'legal' => true,
                    'signature' => EmailLayout::SIGN_HR,
                ],
            ]];
        }

        return [
            [
                'template' => 'payment.approved.student',
                'to' => $email,
                'student' => (int) ($payment['student_id'] ?? 0),
                'subject' => 'Welcome to ' . EmailLayout::company()['short'] . ', Your Payment Has Been Approved',
                'view' => [
                    'audience' => 'student',
                    'preheader' => 'Your access to ' . $course . ' is now active.',
                    'greeting' => 'Dear ' . $name . ',',
                    'paragraphs' => [
                        'Wonderful news! Your payment of ' . $amount . ' for ' . $course . ' has been officially approved by the company, and we could not be happier to have you with us.',
                        'You are now part of a professional community where learning goes far beyond completing modules and collecting certificates. Here, you will build your skills in a real industry and production environment, so that you can confidently deliver industry-level products in your career.',
                    ],
                    'heading' => 'Your Login Credentials',
                    'details' => [
                        ['label' => 'Email', 'value' => $email],
                        ['label' => 'Student ID', 'value' => $studentId],
                        ['label' => 'Password', 'value' => 'The password you created during registration'],
                        ['label' => 'Course', 'value' => $course],
                        ['label' => 'Receipt Number', 'value' => (string) ($payment['receipt_number'] ?? '—')],
                    ],
                    'cta' => ['label' => 'Sign in to your LMS portal', 'url' => self::url('/login')],
                    'closing' => [
                        'We believe in you and look forward to watching you grow. We trust you will take your responsibilities seriously and maintain respectful, friendly relationships with your classmates, mentors and the company\'s directors, in line with company policy.',
                        'Congratulations once again, and welcome to ' . $company['short'] . '!',
                    ],
                    'notice' => 'Please note: any breach of company policy or unprofessional conduct will be dealt with strictly.',
                    'legal' => true,
                    'signature' => EmailLayout::SIGN_HR,
                ],
            ],
            self::paymentApprovedAdmin($payment, $decided),
            [
                'template' => 'payment.approved.developer',
                'to' => MailService::developerAddress(),
                'student' => (int) ($payment['student_id'] ?? 0),
                'subject' => 'Payment Approved, Share Confirmed',
                'view' => [
                    'audience' => 'developer',
                    'preheader' => $name . "'s payment is approved; your share is " . $share . '.',
                    'greeting' => MailService::developerGreeting(),
                    'paragraphs' => ["The following student's payment has been approved by the admin."],
                    'heading' => 'Payment Details',
                    'details' => [
                        ['label' => 'Student Name', 'value' => $name],
                        ['label' => 'Student ID', 'value' => $studentId],
                        ['label' => 'Email', 'value' => $email],
                        ['label' => 'Course', 'value' => $course],
                        ['label' => 'Total Payment', 'value' => $amount],
                        ['label' => 'Your Share (' . self::SHARE_PERCENT . '%)', 'value' => $share],
                        ['label' => 'Approved On', 'value' => $decided],
                        ['label' => 'Status', 'value' => 'Approved'],
                    ],
                    'signature' => EmailLayout::SIGN_SYSTEM,
                ],
            ],
        ];
    }

    /** Copy of the approval to the admin who recorded it (skipped when unreachable). */
    private static function paymentApprovedAdmin(array $payment, string $decided): array
    {
        $name = self::name($payment);
        $reviewer = trim((string) ($payment['reviewer_name'] ?? 'Administrator'));
        return [
            'template' => 'payment.approved.admin',
            'to' => (string) ($payment['reviewer_email'] ?? ''),
            'student' => (int) ($payment['student_id'] ?? 0),
            'subject' => 'Payment Approved by You, Access Granted',
            'view' => [
                'audience' => 'admin',
                'preheader' => $name . "'s payment of " . MailService::money(self::amount($payment), self::currency($payment)) . ' is approved.',
                'greeting' => 'Dear ' . $reviewer . ',',
                'paragraphs' => ['This confirms the payment decision you recorded in the admin dashboard. The student has been notified with their login details and course access is active.'],
                'heading' => 'Approval Record',
                'details' => array_merge(self::studentDetails($payment), [
                    ['label' => 'Approved Amount', 'value' => MailService::money(self::amount($payment), self::currency($payment))],
                    ['label' => 'Receipt Number', 'value' => (string) ($payment['receipt_number'] ?? '—')],
                    ['label' => 'Approved By', 'value' => $reviewer],
                    ['label' => 'Approved On', 'value' => $decided],
                    ['label' => 'Status', 'value' => 'Approved'],
                ]),
                'cta' => ['label' => 'View in the payment ledger', 'url' => self::url('/admin/payments')],
                'signature' => EmailLayout::SIGN_SYSTEM,
            ],
        ];
    }

    /**
     * Monthly revenue summary for the developer.
     * Keys: period, start_date, end_date, student_count, total_revenue, developer_share,
     * currency, rows[] (student_name, student_id, course_title, amount).
     */
    public static function monthlyRevenue(array $report): array
    {
        $currency = strtoupper(trim((string) ($report['currency'] ?? 'PKR'))) ?: 'PKR';
        $rows = [];
        foreach (($report['rows'] ?? []) as $index => $row) {
            if (!is_array($row)) continue;
            $amount = (float) ($row['amount'] ?? 0);
            $rows[] = [
                (string) ($index + 1),
                (string) ($row['student_name'] ?? 'Student'),
                MailService::studentId((int) ($row['student_id'] ?? 0)),
                (string) ($row['course_title'] ?? '—'),
                number_format($amount),
                number_format($amount * self::SHARE_PERCENT / 100),
            ];
        }
        $period = (string) ($report['period'] ?? 'Current month');
        return [[
            'template' => 'revenue.monthly_summary',
            'to' => MailService::developerAddress(),
            'subject' => 'Monthly Summary Report, ' . $period,
            'view' => [
                'audience' => 'developer',
                'preheader' => 'Enrollments and revenue for ' . $period . '.',
                'greeting' => MailService::developerGreeting(),
                'paragraphs' => ['We hope you are doing well. Please find below your monthly summary for ' . $period . ', covering student enrollments and revenue for the period.'],
                'heading' => 'Monthly Overview',
                'details' => [
                    ['label' => 'Reporting Period', 'value' => self::when((string) ($report['start_date'] ?? '')) . ' to ' . self::when((string) ($report['end_date'] ?? ''))],
                    ['label' => 'Total Students Enrolled', 'value' => number_format((int) ($report['student_count'] ?? 0))],
                    ['label' => 'Total Revenue', 'value' => MailService::money((float) ($report['total_revenue'] ?? 0), $currency)],
                    ['label' => 'Your Share (' . self::SHARE_PERCENT . '%)', 'value' => MailService::money((float) ($report['developer_share'] ?? 0), $currency)],
                ],
                'table' => [
                    'columns' => ['No.', 'Student Name', 'Student ID', 'Course', 'Amount Paid (' . $currency . ')', 'Your Share ' . self::SHARE_PERCENT . '% (' . $currency . ')'],
                    'rows' => $rows,
                    'footer' => $rows === []
                        ? 'No approved payments were recorded in this period.'
                        : 'Total: ' . MailService::money((float) ($report['total_revenue'] ?? 0), $currency) . '  |  Your Total Share: ' . MailService::money((float) ($report['developer_share'] ?? 0), $currency),
                ],
                'closing' => [
                    'Thank you for your continued contribution and hard work in supporting the platform. We truly value your efforts and look forward to another successful month together.',
                    'If you notice any discrepancy in the figures above, please contact the admin team.',
                ],
                'signature' => EmailLayout::SIGN_SYSTEM,
            ],
        ]];
    }

    /* ------------------------------------------------------------------ certificates */

    /**
     * Student requested a course certificate (goes to the certificate inbox).
     * Keys: student_id, student_name, student_email, course_title, reference, remarks.
     */
    public static function certificateRequest(array $request): array
    {
        $details = [
            ['label' => 'Student', 'value' => (string) ($request['student_name'] ?? 'Student')],
            ['label' => 'Student ID', 'value' => MailService::studentId((int) ($request['student_id'] ?? 0))],
            ['label' => 'Email', 'value' => (string) ($request['student_email'] ?? '')],
            ['label' => 'Course', 'value' => (string) ($request['course_title'] ?? '')],
            ['label' => 'Request Reference', 'value' => '#' . (string) ($request['reference'] ?? '')],
        ];
        $remarks = trim((string) ($request['remarks'] ?? ''));
        if ($remarks !== '') $details[] = ['label' => 'Student Remarks', 'value' => $remarks];
        return [[
            'template' => 'certificate.requested',
            'to' => MailService::certificateAddress(),
            'student' => (int) ($request['student_id'] ?? 0),
            'subject' => 'New Certificate Request Awaiting Review',
            'view' => [
                'audience' => 'admin',
                'preheader' => (string) ($request['student_name'] ?? 'A student') . ' requested a certificate.',
                'greeting' => 'Dear Certification Team,',
                'paragraphs' => ['A student has requested a course certificate. Please verify the eligibility record and issue or reject the request from the admin dashboard.'],
                'heading' => 'Request Details',
                'details' => $details,
                'cta' => ['label' => 'Open certificate requests', 'url' => self::url('/admin/certificates')],
                'signature' => EmailLayout::SIGN_SYSTEM,
            ],
        ]];
    }

    /**
     * Certificate issued.
     * Keys: student_id, student_email, student_name, course_title, certificate_id, issued_on,
     * expires_on, verify_url.
     */
    public static function certificateIssued(array $certificate): array
    {
        $course = (string) ($certificate['course_title'] ?? '');
        return [[
            'template' => 'certificate.issued',
            'to' => (string) ($certificate['student_email'] ?? ''),
            'student' => (int) ($certificate['student_id'] ?? 0),
            'subject' => 'Your Certificate Is Ready — ' . $course,
            'view' => [
                'audience' => 'student',
                'preheader' => 'Your certificate for ' . $course . ' has been issued.',
                'greeting' => 'Dear ' . (string) ($certificate['student_name'] ?? 'Student') . ',',
                'paragraphs' => [
                    'Congratulations! Your certificate for ' . $course . ' has been issued and is ready in your student workspace.',
                    'This achievement reflects your attendance, coursework and consistency. We are proud of the standard you have reached and hope it opens real professional doors for you.',
                ],
                'heading' => 'Certificate Details',
                'details' => [
                    ['label' => 'Certificate ID', 'value' => (string) ($certificate['certificate_id'] ?? '')],
                    ['label' => 'Course', 'value' => $course],
                    ['label' => 'Date of Issue', 'value' => (string) ($certificate['issued_on'] ?? '')],
                    ['label' => 'Valid Until', 'value' => (string) ($certificate['expires_on'] ?? '')],
                ],
                'cta' => ['label' => 'Download your certificate', 'url' => (string) ($certificate['verify_url'] ?? self::url('/student/courses'))],
                'closing' => ['If any detail looks incorrect, contact our team at the address below and the academy will correct it.'],
                'legal' => true,
                'signature' => EmailLayout::SIGN_HR,
            ],
        ]];
    }

    /**
     * Certificate request rejected.
     * Keys: student_id, student_email, student_name, course_title, remarks.
     */
    public static function certificateRejected(array $certificate): array
    {
        $course = (string) ($certificate['course_title'] ?? '');
        return [[
            'template' => 'certificate.rejected',
            'to' => (string) ($certificate['student_email'] ?? ''),
            'student' => (int) ($certificate['student_id'] ?? 0),
            'subject' => 'Action Needed on Your Certificate Request',
            'view' => [
                'audience' => 'student',
                'preheader' => 'Your certificate request for ' . $course . ' needs attention.',
                'greeting' => 'Dear ' . (string) ($certificate['student_name'] ?? 'Student') . ',',
                'paragraphs' => [
                    'Thank you for your certificate request for ' . $course . '. Our team was unable to approve it in its current state, so no certificate has been issued yet.',
                    'Please review the reason below, correct the outstanding requirement, and submit a new request from your student workspace. We are happy to help if anything is unclear.',
                ],
                'heading' => 'Reviewer Remarks',
                'details' => [['label' => 'Reason', 'value' => trim((string) ($certificate['remarks'] ?? '')) ?: 'Eligibility requirements are not met yet.']],
                'cta' => ['label' => 'Open my courses', 'url' => self::url('/student/courses')],
                'legal' => true,
                'signature' => EmailLayout::SIGN_HR,
            ],
        ]];
    }

    /* ---------------------------------------------------------------- public enquiries */

    /**
     * A visitor used the public contact form. Two messages fire from one event: the internal
     * alert that lands in the company inbox, and the acknowledgement that reassures the sender
     * their message was received and someone will reply. Both are sent from the same mailbox.
     * Keys: name, email, phone, subject, body, submitted_at.
     */
    public static function contactReceived(array $enquiry): array
    {
        $company = EmailLayout::company();
        $name = trim((string) ($enquiry['name'] ?? '')) ?: 'there';
        $email = trim((string) ($enquiry['email'] ?? ''));
        $phone = trim((string) ($enquiry['phone'] ?? ''));
        $subject = trim((string) ($enquiry['subject'] ?? '')) ?: 'General enquiry';
        $body = trim((string) ($enquiry['body'] ?? ''));
        $when = self::when((string) ($enquiry['submitted_at'] ?? ''));

        return [
            [
                'template' => 'contact.admin',
                'to' => MailService::hrAddress(),
                'subject' => 'New Contact Enquiry: ' . $subject,
                'view' => [
                    'audience' => 'hr',
                    'preheader' => $name . ' (' . $email . ') sent a message from the contact page.',
                    'greeting' => 'Dear Team,',
                    'paragraphs' => ['A new message has arrived through the contact form on the ' . $company['short'] . ' website. The details are below - please reply to the sender from your usual inbox.'],
                    'heading' => 'Enquiry Details',
                    'details' => [
                        ['label' => 'Name', 'value' => $name],
                        ['label' => 'Email', 'value' => $email],
                        ['label' => 'Phone', 'value' => $phone !== '' ? $phone : '—'],
                        ['label' => 'Subject', 'value' => $subject],
                        ['label' => 'Received On', 'value' => $when],
                        ['label' => 'Message', 'value' => $body],
                    ],
                    'signature' => EmailLayout::SIGN_SYSTEM,
                ],
            ],
            [
                'template' => 'contact.acknowledgement',
                'to' => $email,
                'subject' => 'We have received your message',
                'view' => [
                    'audience' => 'student',
                    'preheader' => 'Thanks for reaching out - our team will reply shortly.',
                    'greeting' => 'Dear ' . $name . ',',
                    'paragraphs' => [
                        'Thank you for contacting ' . $company['short'] . '. We have received your message and a member of our team will get back to you as soon as we can, usually within one working day.',
                        'There is nothing you need to do right now. For anything urgent you can also reach us through the details on our contact page.',
                    ],
                    'heading' => 'Your Message',
                    'details' => [
                        ['label' => 'Subject', 'value' => $subject],
                        ['label' => 'Sent From', 'value' => $email],
                        ['label' => 'Received On', 'value' => $when],
                        ['label' => 'Message', 'value' => $body],
                    ],
                    'closing' => ['We look forward to speaking with you.'],
                    'legal' => true,
                    'signature' => EmailLayout::SIGN_ACADEMY,
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------------ helpers */

    private static function studentDetails(array $row): array
    {
        return [
            ['label' => 'Name', 'value' => self::name($row)],
            ['label' => 'Student ID', 'value' => MailService::studentId((int) ($row['student_id'] ?? 0))],
            ['label' => 'Email', 'value' => (string) ($row['student_email'] ?? '')],
        ];
    }

    private static function name(array $row): string
    {
        return trim((string) ($row['student_name'] ?? '')) ?: 'Student';
    }

    private static function course(array $row): string
    {
        return trim((string) ($row['course_title'] ?? '')) ?: 'your course';
    }

    private static function amount(array $row): float
    {
        return (float) ($row['amount'] ?? 0);
    }

    private static function currency(array $row): string
    {
        return strtoupper(trim((string) ($row['currency'] ?? '')));
    }

    private static function when(string $sqlValue): string
    {
        $value = trim($sqlValue);
        if ($value === '') return gmdate('j F Y, g:i a') . ' UTC';
        try {
            $moment = new \DateTimeImmutable(str_replace(' ', 'T', substr($value, 0, 19)), new \DateTimeZone('UTC'));
            return $moment->format('j F Y, g:i a') . ' UTC';
        } catch (\Throwable) {
            return $value;
        }
    }

    private static function url(string $path): string
    {
        return rtrim((string) (getenv('APP_URL') ?: 'http://localhost/TSG-Academy-LMS'), '/') . $path;
    }
}
