<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PrivateStorage;
use App\Core\Security;
use App\Core\View;
use App\Services\MailService;
use App\Services\AccessPlan;
use App\Services\NotificationService;
use App\Services\DocumentPdf;
use PDO;
use RuntimeException;

final class PaymentController
{
    private const MAX_PROOF_BYTES = 5_242_880;

    public function newPage(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $stmt = $db->prepare("SELECT c.id,c.title,c.duration_label,c.level_label,COALESCE(p.currency,c.currency) AS currency,p.recorded_price,p.live_price FROM courses c LEFT JOIN (SELECT course_id,MAX(CASE WHEN option_code='recorded_only' THEN price END) AS recorded_price,MAX(CASE WHEN option_code='live_recorded' THEN price END) AS live_price,MAX(currency) AS currency FROM course_pricing_options GROUP BY course_id) p ON p.course_id=c.id WHERE c.id=? AND c.visibility='published' LIMIT 1");
        $stmt->execute([$courseId]);
        $course = $stmt->fetch();
        if (!$course) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Course unavailable', 'user' => $student]);
            return;
        }
        $access = $db->prepare("SELECT 1 FROM student_course_access WHERE student_id=? AND course_id=? AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1");
        $access->execute([(int) $student['id'], $courseId]);
        if ($access->fetchColumn()) {
            $this->redirect('/learning?course_id=' . $courseId);
        }
        $pending = $db->prepare("SELECT 1 FROM payments WHERE student_id=? AND course_id=? AND status='pending' LIMIT 1");
        $pending->execute([(int) $student['id'], $courseId]);
        $history = $db->prepare('SELECT id,amount,currency,transaction_reference,payment_proof_path,receipt_number,receipt_issued_at,status,review_note,created_at FROM payments WHERE student_id=? AND course_id=? ORDER BY id DESC LIMIT 5');
        $history->execute([(int) $student['id'], $courseId]);
        $paymentDetails = [];
        foreach ($db->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'payment_%'")->fetchAll() as $setting) {
            $paymentDetails[$setting['setting_key']] = $setting['setting_value'];
        }
        $paymentAccounts = $db->query('SELECT account_title,bank_name,account_number,iban,wallet_name,wallet_number,instructions FROM payment_accounts WHERE is_active=1 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
        if ($paymentAccounts === [] && count(array_filter($paymentDetails, static fn($value): bool => trim((string)$value) !== '')) > 0) {
            $paymentAccounts[] = [
                'account_title' => $paymentDetails['payment_account_title'] ?? '', 'bank_name' => $paymentDetails['payment_bank_name'] ?? '',
                'account_number' => $paymentDetails['payment_account_number'] ?? '', 'iban' => $paymentDetails['payment_iban'] ?? '',
                'wallet_name' => $paymentDetails['payment_wallet_name'] ?? '', 'wallet_number' => $paymentDetails['payment_wallet_number'] ?? '',
                'instructions' => $paymentDetails['payment_instructions'] ?? '',
            ];
        }
        $flash = $_SESSION['_flash'] ?? null;
        if (is_string($flash)) $flash = ['type' => 'success', 'text' => $flash];
        View::render('payments/form', [
            'title' => 'Submit course payment',
            'user' => $student,
            'course' => $course,
            'hasPendingPayment' => (bool) $pending->fetchColumn(),
            'payments' => $history->fetchAll(),
            'paymentDetails' => $paymentDetails,
            'paymentAccounts' => $paymentAccounts,
            'message' => is_array($flash) ? ($flash['text'] ?? null) : null,
            'messageType' => is_array($flash) ? ($flash['type'] ?? 'success') : 'success',
        ]);
        unset($_SESSION['_flash']);
    }

    public function submit(): void
    {
        $student = AuthMiddleware::requireRole('student');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $option = (string) ($_POST['option_code'] ?? '');
        $reference = trim((string) ($_POST['transaction_reference'] ?? ''));
        if ($courseId < 1 || !in_array($option, ['recorded_only', 'live_recorded'], true) || mb_strlen($reference) < 3 || mb_strlen($reference) > 190 || preg_match('/[\r\n\x00-\x1F]/', $reference)) {
            $this->paymentError(max(0, $courseId), 'Enter a valid learning option and transaction reference.');
        }
        $db = Database::connection();
        $price = $db->prepare("SELECT c.title,c.currency AS course_currency,p.price,p.currency FROM courses c JOIN course_pricing_options p ON p.course_id=c.id WHERE c.id=? AND c.visibility='published' AND p.option_code=? AND p.price>0 LIMIT 1");
        $price->execute([$courseId, $option]);
        $course = $price->fetch();
        if (!$course) {
            $this->paymentError($courseId, 'This course option is unavailable. Refresh the catalogue and try again.');
        }
        $access = $db->prepare("SELECT 1 FROM student_course_access WHERE student_id=? AND course_id=? AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1");
        $access->execute([(int) $student['id'], $courseId]);
        if ($access->fetchColumn()) {
            $this->redirect('/learning?course_id=' . $courseId);
        }
        $pending = $db->prepare("SELECT 1 FROM payments WHERE student_id=? AND course_id=? AND status='pending' LIMIT 1");
        $pending->execute([(int) $student['id'], $courseId]);
        if ($pending->fetchColumn()) {
            $_SESSION['_flash'] = ['type' => 'info', 'text' => 'A payment for this course is already awaiting review.'];
            $this->redirect('/payments/new?course_id=' . $courseId);
        }
        $duplicateReference = $db->prepare('SELECT 1 FROM payments WHERE transaction_reference=? LIMIT 1');
        $duplicateReference->execute([$reference]);
        if ($duplicateReference->fetchColumn()) $this->paymentError($courseId, 'That transaction reference has already been submitted. Check it and contact the Academy if you believe this is an error.');

        try {
            $proof = $this->storePaymentProof($_FILES['payment_proof'] ?? null);
        } catch (RuntimeException $exception) {
            $this->paymentError($courseId, $exception->getMessage());
        }

        $submittedAt = gmdate('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            // The chosen option is a fact about the purchase, so it is written on the payment row
            // instead of living only in the audit blob. A database that has not been re-imported
            // yet still records the purchase; only the plan column is left out of the statement.
            $recordsPlan = AccessPlan::columnStored($db, 'payments.plan_code');
            $insert = $db->prepare($recordsPlan
                ? "INSERT INTO payments(student_id,course_id,plan_code,amount,currency,transaction_reference,payment_proof_path,status) VALUES(?,?,?,?,?,?,?,'pending')"
                : "INSERT INTO payments(student_id,course_id,amount,currency,transaction_reference,payment_proof_path,status) VALUES(?,?,?,?,?,?,'pending')");
            $fields = [(int) $student['id'], $courseId];
            if ($recordsPlan) $fields[] = AccessPlan::normalise($option);
            $fields[] = $course['price'];
            $fields[] = $course['currency'] ?: $course['course_currency'];
            $fields[] = $reference;
            $fields[] = $proof['relative_path'];
            $insert->execute($fields);
            $paymentId = (int) $db->lastInsertId();
            $audit = $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'payment.submitted','payment',?,JSON_OBJECT('course_id',?,'option_code',?))");
            $audit->execute([(int) $student['id'], $paymentId, $courseId, $option]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            @unlink($proof['absolute_path']);
            throw $exception;
        }
        $currency = (string) ($course['currency'] ?: $course['course_currency']);
        // The enrolment journey ends here, so the remembered course is dropped. Leaving it behind
        // would pull a later sign-in back into a checkout that has already been completed.
        unset($_SESSION['enrol_course_id'], $_SESSION['enrol_course_slug'], $_SESSION['enrol_course_title']);
        $paymentEmailSent = (new MailService($db))->sendPaymentSubmitted([
            'student_id' => (int) $student['id'],
            'student_name' => (string) $student['name'],
            'student_email' => (string) $student['email'],
            'course_title' => (string) $course['title'],
            'amount' => (float) $course['price'],
            'currency' => $currency,
            'reference' => $reference,
            'submitted_at' => $submittedAt,
        ]);
        // The student is waiting on this review, so the workspace shows the same state change.
        (new NotificationService($db))->notifyStudents(
            [(int) $student['id']],
            NotificationService::PAYMENT,
            'Payment approval requested',
            'Your payment of ' . MailService::money((float) $course['price'], $currency) . ' for ' . $course['title'] . ' has been submitted for verification. You will be notified here and by email as soon as an administrator approves it. Our team aims to respond within 24 hours.',
            'payment',
            $paymentId,
            '/student/payments',
            (int) $student['id']
        );
        $_SESSION['_flash'] = [
            'type' => $paymentEmailSent ? 'success' : 'error',
            'text' => $paymentEmailSent
                ? 'Your payment approval request has been emailed to the admin. You will receive a confirmation soon.'
                : 'Your payment was submitted, but we could not confirm the admin email delivery. The request is in the review queue and you will be notified after verification.',
        ];
        $this->redirect('/payments/new?course_id=' . $courseId);
    }

    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $rows = $db->query("SELECT p.id,p.amount,p.currency,p.transaction_reference,p.payment_proof_path,p.created_at,p.status,u.full_name AS student_name,u.email AS student_email,c.title AS course_title FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.status='pending' ORDER BY p.created_at ASC,p.id ASC")->fetchAll();
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $nextMonth = (new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $monthOptions = $this->revenueMonthOptions($db);
        $revenueStmt = $db->prepare("SELECT currency, COUNT(CASE WHEN COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? THEN 1 END) AS payment_count, COUNT(DISTINCT CASE WHEN COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? THEN student_id END) AS student_count, COALESCE(SUM(amount),0) AS lifetime_revenue, COALESCE(SUM(CASE WHEN COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? THEN amount ELSE 0 END),0) AS monthly_revenue FROM payments WHERE status='approved' GROUP BY currency ORDER BY currency");
        $revenueStmt->execute([$monthStart, $nextMonth, $monthStart, $nextMonth, $monthStart, $nextMonth]);
        $revenue = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalsStmt = $db->query("SELECT COUNT(DISTINCT student_id) AS student_count, COUNT(*) AS payment_count FROM payments WHERE status='approved'");
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['student_count' => 0, 'payment_count' => 0];
        $approvedRows = $db->query("SELECT p.id,p.amount,p.currency,p.transaction_reference,COALESCE(p.reviewed_at,p.created_at) AS paid_at,u.full_name AS student_name,u.email AS student_email,c.title AS course_title FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.status='approved' ORDER BY COALESCE(p.reviewed_at,p.created_at) DESC,p.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($revenue as &$currencyTotals) {
            $currencyTotals['developer_share'] = MailService::developerShareOf((float) $currencyTotals['monthly_revenue']);
            $currencyTotals['lifetime_developer_share'] = MailService::developerShareOf((float) $currencyTotals['lifetime_revenue']);
        }
        unset($currencyTotals);
        $paymentValues = [];
        foreach ($db->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'payment_%'")->fetchAll() as $setting) $paymentValues[$setting['setting_key']] = $setting['setting_value'];
        $paymentAccounts = $db->query('SELECT account_title,bank_name,account_number,iban,wallet_name,wallet_number,instructions FROM payment_accounts ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
        if ($paymentAccounts === [] && count(array_filter($paymentValues, static fn($value): bool => trim((string)$value) !== '')) > 0) {
            $paymentAccounts[] = ['account_title'=>$paymentValues['payment_account_title'] ?? '', 'bank_name'=>$paymentValues['payment_bank_name'] ?? '', 'account_number'=>$paymentValues['payment_account_number'] ?? '', 'iban'=>$paymentValues['payment_iban'] ?? '', 'wallet_name'=>$paymentValues['payment_wallet_name'] ?? '', 'wallet_number'=>$paymentValues['payment_wallet_number'] ?? '', 'instructions'=>$paymentValues['payment_instructions'] ?? ''];
        }
        View::render('admin/payments', ['title' => 'Revenue and payment', 'user' => $admin, 'payments' => $rows, 'approvedPayments' => $approvedRows, 'paymentValues' => $paymentValues, 'paymentAccounts' => $paymentAccounts, 'revenue' => $revenue, 'revenueTotals' => $totals, 'developerSharePercent' => MailService::developerSharePercent(), 'revenueMonthOptions' => $monthOptions, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function decision(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['review_note'] ?? ''));
        if ($paymentId < 1 || !in_array($decision, ['approved', 'rejected'], true) || mb_strlen($note) > 1000) throw new RuntimeException('Choose a valid payment decision and keep the note under 1,000 characters.');
        if ($decision === 'rejected' && $note === '') throw new RuntimeException('Add a review note before rejecting this payment.');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $lookup = $db->prepare("SELECT p.student_id,p.course_id,p.amount,p.currency,p.transaction_reference,p.status,u.email AS student_email,u.full_name AS student_name,c.title AS course_title,c.duration_label" . (AccessPlan::columnStored($db, 'payments.plan_code') ? ",p.plan_code" : '') . " FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.id=? FOR UPDATE");
            $lookup->execute([$paymentId]);
            $payment = $lookup->fetch();
            if (!$payment || $payment['status'] !== 'pending') throw new RuntimeException('This payment is no longer awaiting review.');
            $receiptNumber = $decision === 'approved' ? 'TSG-RCP-' . gmdate('Y') . '-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT) : null;
            $decidedAt = gmdate('Y-m-d H:i:s');
            $update = $db->prepare('UPDATE payments SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),review_note=?,receipt_number=?,receipt_issued_at=IF(?=\'approved\',UTC_TIMESTAMP(),NULL) WHERE id=? AND status=\'pending\'');
            $update->execute([$decision, (int) $admin['id'], $note !== '' ? $note : null, $receiptNumber, $decision, $paymentId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('This payment could not be updated. Refresh the queue and try again.');
            if ($decision === 'approved') {
                $grantedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $grantedAtSql = $grantedAt->format('Y-m-d H:i:s');
                $expiresAt = \App\Services\CourseDuration::expiry((string) ($payment['duration_label'] ?? ''), $grantedAt);
                // The plan travels from the payment onto the enrolment, so approving an upgrade moves
                // a Recorded Only student to Live + Recorded without an extra click. When either
                // column has not been imported the statement simply omits it, which leaves an
                // existing enrolment's plan exactly as it was rather than clearing it.
                $carriesPlan = AccessPlan::columnStored($db, 'payments.plan_code')
                    && AccessPlan::columnStored($db, 'student_course_access.plan_code');
                $grant = $db->prepare("INSERT INTO student_course_access(student_id,course_id,status,granted_by,granted_at,expires_at,revoked_by,revoked_at"
                    . ($carriesPlan ? ',plan_code' : '')
                    . ") VALUES(?,?,'active',?,?,?,NULL,NULL" . ($carriesPlan ? ',?' : '')
                    . ") ON DUPLICATE KEY UPDATE status='active',granted_by=VALUES(granted_by),granted_at=VALUES(granted_at),expires_at=VALUES(expires_at),revoked_by=NULL,revoked_at=NULL"
                    . ($carriesPlan ? ",plan_code=VALUES(plan_code)" : ''));
                $grantParams = [(int) $payment['student_id'], (int) $payment['course_id'], (int) $admin['id'], $grantedAtSql, $expiresAt];
                if ($carriesPlan) $grantParams[] = AccessPlan::normalise($payment['plan_code'] ?? null);
                $grant->execute($grantParams);
            }
            $audit = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,\'payment\',?,JSON_OBJECT(\'course_id\',?,\'student_id\',?))');
            $audit->execute([(int) $admin['id'], 'payment.' . $decision, $paymentId, (int) $payment['course_id'], (int) $payment['student_id']]);
            if ($decision === 'approved') {
                $grantAudit = $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'course_access.granted','course',?,JSON_OBJECT('student_id',?,'payment_id',?))");
                $grantAudit->execute([(int) $admin['id'], (int) $payment['course_id'], (int) $payment['student_id'], $paymentId]);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $mailContext = [
            'student_id' => (int) $payment['student_id'],
            'student_name' => (string) $payment['student_name'],
            'student_email' => (string) $payment['student_email'],
            'course_title' => (string) $payment['course_title'],
            'amount' => (float) $payment['amount'],
            'currency' => (string) $payment['currency'],
            'reference' => (string) ($payment['transaction_reference'] ?? ''),
            'decision' => $decision,
            'note' => $note,
            'decided_at' => $decidedAt,
            'receipt_number' => (string) ($receiptNumber ?? '—'),
            'reviewer_name' => (string) $admin['name'],
            'reviewer_email' => (string) $admin['email'],
        ];
        (new MailService($db))->sendPaymentDecision($mailContext);
        // Approval grants access and rejection asks for a correction; either way the student
        // must see it in the workspace, not only in their inbox.
        $money = MailService::money((float) $payment['amount'], (string) $payment['currency']);
        (new NotificationService($db))->notifyStudents(
            [(int) $payment['student_id']],
            NotificationService::PAYMENT,
            $decision === 'approved' ? 'Payment approved — access granted' : 'Payment needs a correction',
            $decision === 'approved'
                ? 'Your payment of ' . $money . ' for ' . $payment['course_title'] . ' has been approved and your course access is now active. Your receipt number is ' . ($receiptNumber ?: '—') . '. Sign in and start your first lesson whenever you are ready.'
                : 'Your payment of ' . $money . ' for ' . $payment['course_title'] . ' could not be verified.' . ($note !== '' ? ' Note from the review team: ' . $note : ' Please submit corrected payment details from the course catalogue or contact the Academy.') . ' Your access will be granted as soon as the payment is verified.',
            'payment',
            $paymentId,
            $decision === 'approved' ? '/student/courses' : '/payments/new?course_id=' . (int) $payment['course_id'],
            (int) $admin['id']
        );
        $_SESSION['_flash'] = $decision === 'approved'
            ? 'Payment approved successfully. Course access has been granted to the student.'
            : 'Payment rejected; the student can submit corrected payment details.';
        $this->redirect('/admin/payments');
    }

    public function receipt(): void
    {
        $payment = $this->approvedReceipt();
        $document = DocumentPdf::receipt($payment);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($document));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9-]/', '-', $payment['receipt_number']) . '.pdf"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $document;
    }

    private function receiptUser(): array
    {
        $sessionUser = $_SESSION['user'] ?? null;
        if (!is_array($sessionUser) || !in_array($sessionUser['role'] ?? '', ['admin', 'student'], true)) {
            $this->redirect('/login');
        }
        return AuthMiddleware::requireRole((string) $sessionUser['role']);
    }

    /** Load an approved, issued receipt; the student may only load their own. */
    private function approvedReceipt(): array
    {
        $user = $this->receiptUser();
        $paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT) ?: 0;
        $sql = "SELECT p.id,p.amount,p.currency,p.transaction_reference,p.receipt_number,p.receipt_issued_at,p.reviewed_at,p.status,u.full_name AS student_name,u.email AS student_email,c.title AS course_title FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.id=?";
        $params = [$paymentId];
        if ($user['role'] === 'student') {
            $sql .= ' AND p.student_id=?';
            $params[] = (int) $user['id'];
        }
        $sql .= " AND p.status='approved'";
        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || !is_string($payment['receipt_number']) || $payment['receipt_number'] === '') {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Receipt unavailable', 'user' => $user]);
            exit;
        }
        return $payment;
    }

    public function revenueReceipt(): void
    {
        AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $requestedPeriod = $_GET['period'] ?? '';
        if (!is_string($requestedPeriod)) {
            http_response_code(400);
            return;
        }
        $period = trim($requestedPeriod);
        if ($period === 'six-months') {
            $report = $this->sixMonthRevenueReport($db);
            $document = DocumentPdf::revenueHistory($report);
            $filename = 'TSG-revenue-last-6-months-' . gmdate('Y-m') . '.pdf';
        } else {
            if ($period === '') {
                $period = gmdate('Y-m');
            }
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
                http_response_code(400);
                return;
            }
            $monthStart = new \DateTimeImmutable($period . '-01 00:00:00', new \DateTimeZone('UTC'));
            $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC'));
            if ($monthStart > $currentMonth) {
                http_response_code(400);
                return;
            }
            $nextMonth = $monthStart->modify('+1 month');
            $stmt = $db->prepare("SELECT currency,COUNT(*) AS payment_count,COUNT(DISTINCT student_id) AS student_count,COALESCE(SUM(amount),0) AS monthly_revenue FROM payments WHERE status='approved' AND COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? GROUP BY currency ORDER BY currency");
            $stmt->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
            $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rates as &$row) {
                $row['developer_share'] = MailService::developerShareOf((float) $row['monthly_revenue']);
            }
            unset($row);
            $counts = $db->prepare("SELECT COUNT(*) AS payment_count,COUNT(DISTINCT student_id) AS student_count FROM payments WHERE status='approved' AND COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<?");
            $counts->execute([$monthStart->format('Y-m-d H:i:s'), $nextMonth->format('Y-m-d H:i:s')]);
            $totals = $counts->fetch(PDO::FETCH_ASSOC) ?: ['payment_count' => 0, 'student_count' => 0];
            $report = [
                'period' => $monthStart->format('F Y'),
                'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                'student_count' => (int) $totals['student_count'],
                'payment_count' => (int) $totals['payment_count'],
                'currency_totals' => array_map(static fn(array $rate): array => [
                    'currency' => $rate['currency'],
                    'revenue' => $rate['monthly_revenue'],
                    'payment_count' => $rate['payment_count'],
                    'student_count' => $rate['student_count'],
                    'developer_share' => $rate['developer_share'],
                ], $rates),
                'months' => [[
                    'label' => $monthStart->format('F Y'),
                    'payment_count' => (int) $totals['payment_count'],
                    'student_count' => (int) $totals['student_count'],
                    'rates' => $rates,
                ]],
            ];
            $document = DocumentPdf::revenueHistory($report);
            $filename = 'TSG-revenue-' . $period . '.pdf';
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($document));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $document;
    }

    /**
     * List every month from the first approved payment through the current UTC month.
     * Including empty months lets administrators export a complete chronological series.
     *
     * @return list<array{value:string,label:string}>
     */
    private function revenueMonthOptions(PDO $db): array
    {
        $firstPayment = $db->query("SELECT MIN(COALESCE(reviewed_at,created_at)) FROM payments WHERE status='approved'")->fetchColumn();
        $current = new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC'));
        $first = is_string($firstPayment) && $firstPayment !== ''
            ? (new \DateTimeImmutable($firstPayment, new \DateTimeZone('UTC')))->modify('first day of this month')->setTime(0, 0)
            : $current;
        if ($first > $current) {
            $first = $current;
        }
        $options = [];
        for ($month = $first; $month <= $current; $month = $month->modify('+1 month')) {
            $options[] = ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')];
        }
        return array_reverse($options);
    }

    /** Build the six-month overview and one currency-aware summary row for each month. */
    private function sixMonthRevenueReport(PDO $db): array
    {
        $zone = new \DateTimeZone('UTC');
        $current = new \DateTimeImmutable('first day of this month 00:00:00', $zone);
        $start = $current->modify('-5 months');
        $end = $current->modify('+1 month');
        $startValue = $start->format('Y-m-d H:i:s');
        $endValue = $end->format('Y-m-d H:i:s');

        $byMonthCurrency = $db->prepare("SELECT DATE_FORMAT(COALESCE(reviewed_at,created_at),'%Y-%m') AS period_key,currency,COUNT(*) AS payment_count,COUNT(DISTINCT student_id) AS student_count,COALESCE(SUM(amount),0) AS monthly_revenue FROM payments WHERE status='approved' AND COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? GROUP BY period_key,currency ORDER BY period_key,currency");
        $byMonthCurrency->execute([$startValue, $endValue]);
        $currencyRows = $byMonthCurrency->fetchAll(PDO::FETCH_ASSOC);

        $byMonth = $db->prepare("SELECT DATE_FORMAT(COALESCE(reviewed_at,created_at),'%Y-%m') AS period_key,COUNT(*) AS payment_count,COUNT(DISTINCT student_id) AS student_count FROM payments WHERE status='approved' AND COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? GROUP BY period_key");
        $byMonth->execute([$startValue, $endValue]);
        $monthTotals = [];
        foreach ($byMonth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $monthTotals[$row['period_key']] = [
                'payment_count' => (int) $row['payment_count'],
                'student_count' => (int) $row['student_count'],
            ];
        }

        $overall = $db->prepare("SELECT currency,COUNT(*) AS payment_count,COUNT(DISTINCT student_id) AS student_count,COALESCE(SUM(amount),0) AS revenue FROM payments WHERE status='approved' AND COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<? GROUP BY currency ORDER BY currency");
        $overall->execute([$startValue, $endValue]);
        $currencyTotals = [];
        foreach ($overall->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['developer_share'] = MailService::developerShareOf((float) $row['revenue']);
            $currencyTotals[] = $row;
        }

        $months = [];
        for ($month = $start; $month < $end; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $month->format('F Y'),
                'payment_count' => $monthTotals[$key]['payment_count'] ?? 0,
                'student_count' => $monthTotals[$key]['student_count'] ?? 0,
                'rates' => [],
            ];
        }
        foreach ($currencyRows as $row) {
            $row['developer_share'] = MailService::developerShareOf((float) $row['monthly_revenue']);
            $months[$row['period_key']]['rates'][] = $row;
        }

        $periodPaymentCount = array_sum(array_column($monthTotals, 'payment_count'));
        $distinctStudents = $db->prepare("SELECT COUNT(DISTINCT student_id) FROM payments WHERE status='approved' AND COALESCE(reviewed_at,created_at)>=? AND COALESCE(reviewed_at,created_at)<?");
        $distinctStudents->execute([$startValue, $endValue]);

        return [
            'period' => $start->format('F Y') . ' – ' . $current->format('F Y'),
            'start' => $start,
            'end' => $end->modify('-1 day'),
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'payment_count' => $periodPaymentCount,
            'student_count' => (int) $distinctStudents->fetchColumn(),
            'currency_totals' => $currencyTotals,
            'months' => array_values($months),
        ];
    }

    public function notifyDeveloper(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $db = Database::connection();
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $nextMonth = (new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        // One report per currency, because amounts in different currencies cannot be added.
        $rowsStmt = $db->prepare("SELECT p.amount,p.currency,p.student_id,u.full_name AS student_name,c.title AS course_title FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.status='approved' AND COALESCE(p.reviewed_at,p.created_at)>=? AND COALESCE(p.reviewed_at,p.created_at)<? ORDER BY p.currency,p.id");
        $rowsStmt->execute([$monthStart, $nextMonth]);
        $groups = [];
        foreach ($rowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[strtoupper((string) ($row['currency'] ?: 'PKR'))][] = $row;
        }
        if ($groups === []) {
            $_SESSION['_flash'] = ['type' => 'error', 'text' => 'No approved payments were recorded this month, so there is nothing to report.'];
            $this->redirect('/admin/payments');
        }
        // Duplicate protection: the figures that would go out are fingerprinted (month + each
        // currency's payment count and rounded total). An identical fingerprint to the last one
        // sent means nothing has changed since, so a double-click or an accidental repeat stops
        // here instead of mailing the developer the same report twice. New approvals move the
        // total and are therefore reported again, as they should be.
        $fingerprintParts = [];
        $totals = [];
        foreach ($groups as $currency => $rows) {
            $total = 0.0;
            foreach ($rows as $row) $total += (float) $row['amount'];
            $totals[$currency] = $total;
            $fingerprintParts[] = $currency . ':' . count($rows) . ':' . number_format($total, 2, '.', '');
        }
        sort($fingerprintParts);
        $fingerprint = md5(gmdate('Y-m') . '|' . implode('|', $fingerprintParts));
        $previous = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='developer_revenue_notify_fingerprint'")->fetchColumn();
        if (is_string($previous) && $previous === $fingerprint) {
            $_SESSION['_flash'] = ['type' => 'error', 'text' => 'The current month\'s revenue summary has already been sent with these exact figures, so no duplicate notification was issued.'];
            $this->redirect('/admin/payments');
        }
        $mail = new MailService($db);
        $sent = true;
        $reports = 0;
        foreach ($groups as $currency => $rows) {
            $total = $totals[$currency];
            $sent = $mail->sendDeveloperRevenueReport([
                'period' => gmdate('F Y'),
                'start_date' => $monthStart,
                'end_date' => (new \DateTimeImmutable($nextMonth, new \DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d H:i:s'),
                'currency' => $currency,
                'student_count' => count(array_unique(array_column($rows, 'student_id'))),
                'total_revenue' => $total,
                'developer_share' => MailService::developerShareOf($total),
                'rows' => array_map(static fn(array $row): array => [
                    'student_name' => (string) $row['student_name'],
                    'student_id' => (int) $row['student_id'],
                    'course_title' => (string) $row['course_title'],
                    'amount' => (float) $row['amount'],
                ], $rows),
            ]) && $sent;
            $reports++;
        }
        // The fingerprint is only stored once every currency has actually gone out, so a partial
        // or failed run leaves the button free to retry rather than locking the month shut.
        if ($sent) {
            $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')
                ->execute(['developer_revenue_notify_fingerprint', $fingerprint, (int) $admin['id']]);
        }
        $_SESSION['_flash'] = ['type' => $sent ? 'success' : 'error', 'text' => $sent
            ? 'Monthly revenue summary sent to the developer inbox (' . $reports . ' ' . ($reports === 1 ? 'currency' : 'currencies') . ').'
            : 'Could not send the revenue summary. Check the developer notification address and SMTP settings in the email log.'];
        $this->redirect('/admin/payments');
    }

    public function proof(): void
    {
        $sessionUser = $_SESSION['user'] ?? null;
        if (!is_array($sessionUser) || !in_array($sessionUser['role'] ?? '', ['admin', 'student'], true)) $this->redirect('/login');
        $user = AuthMiddleware::requireRole((string) $sessionUser['role']);
        $paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT) ?: 0;
        $sql = 'SELECT payment_proof_path FROM payments WHERE id=?';
        $params = [$paymentId];
        if ($user['role'] === 'student') { $sql .= ' AND student_id=?'; $params[] = (int) $user['id']; }
        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $relative = $stmt->fetchColumn();
        if (!is_string($relative) || !preg_match('#^(?:payment-proofs|receipts)/[a-f0-9]{48}\.(pdf|jpg|png|webp)$#', $relative)) {
            http_response_code(404);
            return;
        }
        $proofDirectory = str_starts_with($relative, 'payment-proofs/') ? 'payment-proofs' : 'receipts';
        $root = realpath(BASE_PATH . '/storage/uploads/private/' . $proofDirectory);
        $file = $root === false ? false : realpath($root . '/' . basename($relative));
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            http_response_code(404);
            return;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="submitted-payment-proof-' . $paymentId . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    private function storePaymentProof(mixed $upload): array
    {
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw new RuntimeException('Upload payment proof as PDF, JPG, PNG, or WebP (maximum 5 MB).');
        }
        if ((int) $upload['size'] < 1 || (int) $upload['size'] > self::MAX_PROOF_BYTES) throw new RuntimeException('Payment proof files must be no larger than 5 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!is_string($mime) || !isset($extensions[$mime])) throw new RuntimeException('Payment proof must be a PDF, JPG, PNG, or WebP file.');
        $directory = PrivateStorage::directory('payment-proofs');
        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        $absolute = $directory . '/' . $filename;
        if (!move_uploaded_file((string) $upload['tmp_name'], $absolute)) throw new RuntimeException('The payment proof could not be saved. Try again.');
        @chmod($absolute, 0600);
        return ['absolute_path' => $absolute, 'relative_path' => 'payment-proofs/' . $filename];
    }

    private function paymentError(int $courseId, string $message): never
    {
        $_SESSION['_flash'] = ['type' => 'error', 'text' => $message];
        $this->redirect('/payments/new?course_id=' . $courseId);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
