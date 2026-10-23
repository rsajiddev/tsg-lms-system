<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\MailService;
use PDO;
use RuntimeException;

final class PaymentController
{
    private const MAX_RECEIPT_BYTES = 5_242_880;

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
        $access = $db->prepare("SELECT 1 FROM student_course_access WHERE student_id=? AND course_id=? AND status='active' LIMIT 1");
        $access->execute([(int) $student['id'], $courseId]);
        if ($access->fetchColumn()) {
            $this->redirect('/learning?course_id=' . $courseId);
        }
        $pending = $db->prepare("SELECT 1 FROM payments WHERE student_id=? AND course_id=? AND status='pending' LIMIT 1");
        $pending->execute([(int) $student['id'], $courseId]);
        $history = $db->prepare('SELECT id,amount,currency,transaction_reference,status,review_note,created_at FROM payments WHERE student_id=? AND course_id=? ORDER BY id DESC LIMIT 5');
        $history->execute([(int) $student['id'], $courseId]);
        View::render('payments/form', [
            'title' => 'Submit course payment', 'user' => $student, 'course' => $course,
            'hasPendingPayment' => (bool) $pending->fetchColumn(), 'payments' => $history->fetchAll(),
            'message' => $_SESSION['_flash'] ?? null,
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
        $access = $db->prepare("SELECT 1 FROM student_course_access WHERE student_id=? AND course_id=? AND status='active' LIMIT 1");
        $access->execute([(int) $student['id'], $courseId]);
        if ($access->fetchColumn()) {
            $this->redirect('/learning?course_id=' . $courseId);
        }
        $pending = $db->prepare("SELECT 1 FROM payments WHERE student_id=? AND course_id=? AND status='pending' LIMIT 1");
        $pending->execute([(int) $student['id'], $courseId]);
        if ($pending->fetchColumn()) {
            $_SESSION['_flash'] = 'A payment for this course is already awaiting review.';
            $this->redirect('/payments/new?course_id=' . $courseId);
        }
        $duplicateReference = $db->prepare('SELECT 1 FROM payments WHERE transaction_reference=? LIMIT 1');
        $duplicateReference->execute([$reference]);
        if ($duplicateReference->fetchColumn()) $this->paymentError($courseId, 'That transaction reference has already been submitted. Check it and contact the Academy if you believe this is an error.');

        try {
            $receipt = $this->storeReceipt($_FILES['receipt'] ?? null);
        } catch (RuntimeException $exception) {
            $this->paymentError($courseId, $exception->getMessage());
        }

        $db->beginTransaction();
        try {
            $insert = $db->prepare("INSERT INTO payments(student_id,course_id,amount,currency,transaction_reference,receipt_path,status) VALUES(?,?,?,?,?,?,'pending')");
            $insert->execute([(int) $student['id'], $courseId, $course['price'], $course['currency'] ?: $course['course_currency'], $reference, $receipt['relative_path']]);
            $paymentId = (int) $db->lastInsertId();
            $audit = $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'payment.submitted','payment',?,JSON_OBJECT('course_id',?,'option_code',?))");
            $audit->execute([(int) $student['id'], $paymentId, $courseId, $option]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            @unlink($receipt['absolute_path']);
            throw $exception;
        }
        (new MailService())->sendPaymentSubmitted($student['email'], $student['name'], $course['title'], (string) $course['price'], (string) ($course['currency'] ?: $course['course_currency']), $reference);
        $_SESSION['_flash'] = 'Payment details submitted. Course access will be enabled after admin verification.';
        $this->redirect('/payments/new?course_id=' . $courseId);
    }

    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $rows = Database::connection()->query("SELECT p.id,p.amount,p.currency,p.transaction_reference,p.receipt_path,p.created_at,p.status,u.full_name AS student_name,u.email AS student_email,c.title AS course_title FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.status='pending' ORDER BY p.created_at ASC,p.id ASC")->fetchAll();
        View::render('admin/payments', ['title' => 'Payment review', 'user' => $admin, 'payments' => $rows, 'message' => $_SESSION['_flash'] ?? null]);
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
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $lookup = $db->prepare("SELECT p.student_id,p.course_id,p.amount,p.currency,p.status,u.email AS student_email,u.full_name AS student_name,c.title AS course_title FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.id=? FOR UPDATE");
            $lookup->execute([$paymentId]);
            $payment = $lookup->fetch();
            if (!$payment || $payment['status'] !== 'pending') throw new RuntimeException('This payment is no longer awaiting review.');
            $update = $db->prepare('UPDATE payments SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),review_note=? WHERE id=? AND status=\'pending\'');
            $update->execute([$decision, (int) $admin['id'], $note !== '' ? $note : null, $paymentId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('This payment could not be updated. Refresh the queue and try again.');
            if ($decision === 'approved') {
                $grant = $db->prepare("INSERT INTO student_course_access(student_id,course_id,status,granted_by,granted_at,revoked_by,revoked_at) VALUES(?,?,'active',?,UTC_TIMESTAMP(),NULL,NULL) ON DUPLICATE KEY UPDATE status='active',granted_by=VALUES(granted_by),granted_at=UTC_TIMESTAMP(),revoked_by=NULL,revoked_at=NULL");
                $grant->execute([(int) $payment['student_id'], (int) $payment['course_id'], (int) $admin['id']]);
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
        (new MailService())->sendPaymentDecision($payment['student_email'], $payment['student_name'], $payment['course_title'], (string) $payment['amount'], (string) $payment['currency'], $decision === 'approved', $note);
        $_SESSION['_flash'] = $decision === 'approved' ? 'Payment approved and course access granted.' : 'Payment rejected; the student can submit corrected payment details.';
        $this->redirect('/admin/payments');
    }

    public function receipt(): void
    {
        $sessionUser = $_SESSION['user'] ?? null;
        if (!is_array($sessionUser) || !in_array($sessionUser['role'] ?? '', ['admin', 'student'], true)) {
            $this->redirect('/login');
        }
        $user = AuthMiddleware::requireRole((string) $sessionUser['role']);
        $paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $sql = 'SELECT receipt_path FROM payments WHERE id=?';
        $params = [$paymentId];
        if ($user['role'] === 'student') { $sql .= ' AND student_id=?'; $params[] = (int) $user['id']; }
        $stmt = $db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $relative = $stmt->fetchColumn();
        if (!is_string($relative) || !preg_match('#^receipts/[a-f0-9]{48}\.(pdf|jpg|png|webp)$#', $relative)) { http_response_code(404); return; }
        $root = realpath(BASE_PATH . '/storage/uploads/private/receipts');
        $file = $root === false ? false : realpath($root . '/' . basename($relative));
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); return; }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) { http_response_code(404); return; }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="payment-receipt-' . $paymentId . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    private function storeReceipt(mixed $upload): array
    {
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw new RuntimeException('Upload a payment receipt as PDF, JPG, PNG, or WebP (maximum 5 MB).');
        }
        if ((int) $upload['size'] < 1 || (int) $upload['size'] > self::MAX_RECEIPT_BYTES) throw new RuntimeException('Receipt files must be no larger than 5 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
        $extensions = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!is_string($mime) || !isset($extensions[$mime])) throw new RuntimeException('Receipt must be a PDF, JPG, PNG, or WebP file.');
        $directory = BASE_PATH . '/storage/uploads/private/receipts';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Private receipt storage is unavailable.');
        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        $absolute = $directory . '/' . $filename;
        if (!move_uploaded_file((string) $upload['tmp_name'], $absolute)) throw new RuntimeException('The receipt could not be saved. Try again.');
        @chmod($absolute, 0600);
        return ['absolute_path'=>$absolute,'relative_path'=>'receipts/'.$filename];
    }

    private function paymentError(int $courseId, string $message): never
    {
        $_SESSION['_flash'] = $message;
        $this->redirect('/payments/new?course_id=' . $courseId);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
