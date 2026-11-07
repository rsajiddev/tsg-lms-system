<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\PrivateStorage;
use App\Core\Security;
use App\Core\View;
use App\Services\CourseGrading;
use App\Services\AccountIdentity;
use App\Services\AccessPlan;
use App\Services\MailService;
use App\Services\NotificationService;
use PDO;
use RuntimeException;

/**
 * Certificate requests, admin issuing, public verification, and the certificate settings
 * that shape the validity window.
 *
 * A certificate is issued under its owner's account ID rather than a number of its own, so the
 * ID printed on the document is the one the student was given at sign-up and the one they type
 * into the verification page. A student with two courses therefore has two certificates that
 * share one ID, and the public page lists them together.
 *
 * Certificate images live below storage/uploads/private, which is outside the
 * document root and blocked by .htaccess. Every reader goes through this
 * controller so a path is never printed, linked, or guessed from the client.
 */
final class CertificateController
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const STORAGE_FOLDER = 'certificates';
    /** The signed document is JPG or PDF; the extension in the stored name is the format of record. */
    private const FILE_PATTERN = '#^certificates/[a-f0-9]{48}\.(jpg|pdf)$#';
    private const TEST_STORAGE_FOLDER = 'certificate-tests';
    private const TEST_FILE_PATTERN = '#^certificate-tests/[a-f0-9]{48}\.(jpg|pdf)$#';
    private const PUBLIC_ID_PATTERN = '/^[A-Z][A-Z0-9-]{3,63}$/';
    private const CONTACT_FALLBACK = 'info@techsgstudio.com';

    /** Certificate defaults keep the workspace usable before an admin edits anything. */
    public const DAYS_KEY = 'certificate_available_days';
    public const DEFAULT_DAYS = 15;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 3650;

    /** A student asks for the certificate of a course they have finished. */
    public function request(): void
    {
        $user = AuthMiddleware::requireRole('student');
        try {
            $this->createRequest($user);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/student/courses');
        }
    }

    private function createRequest(array $user): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $studentId = (int) $user['id'];
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($courseId < 1) throw new RuntimeException('Choose the course you want a certificate for.');
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (mb_strlen($remarks) > 1000) throw new RuntimeException('Your remarks must be 1000 characters or fewer.');

        $db = Database::connection();
        $access = $db->prepare(
            "SELECT c.id, c.title,
                    (SELECT COUNT(*) FROM course_modules cm JOIN lessons l ON l.module_id = cm.id
                     WHERE cm.course_id = c.id AND l.status = 'published') AS lesson_count,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                     JOIN course_modules cm ON cm.id = l.module_id
                     WHERE lp.student_id = ? AND cm.course_id = c.id AND lp.completed_at IS NOT NULL) AS completed_lessons
             FROM student_course_access sca
             JOIN courses c ON c.id = sca.course_id
             WHERE sca.student_id = ? AND sca.course_id = ? AND sca.status IN ('active','completed','certified')
                   AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             LIMIT 1"
        );
        $access->execute([$studentId, $studentId, $courseId]);
        $course = $access->fetch();
        if (!is_array($course)) throw new RuntimeException('This course is not available in your learning space.');
        if ((int) $course['lesson_count'] < 1 || (int) $course['completed_lessons'] < (int) $course['lesson_count']) {
            throw new RuntimeException('Complete every published lesson in this course before requesting a certificate.');
        }
        // Grade and attendance are now the real bar for a certificate, not just lesson completion.
        $grading = new CourseGrading($db);
        $eligibility = $grading->eligibility($studentId, $courseId);
        if (!$eligibility['ok']) {
            throw new RuntimeException('You are not eligible for a certificate yet. ' . implode(' ', $eligibility['missing']));
        }
        $snapshot = [
            'overall' => round($eligibility['overall'], 2),
            'letter' => $eligibility['letter'],
            'attendance' => $eligibility['attendance'] === null ? null : round($eligibility['attendance'], 2),
        ];

        $existing = $db->prepare('SELECT id, status FROM certificates WHERE student_id = ? AND course_id = ? LIMIT 1');
        $existing->execute([$studentId, $courseId]);
        $row = $existing->fetch();
        if (is_array($row) && in_array((string) $row['status'], ['requested', 'issued'], true)) {
            throw new RuntimeException($row['status'] === 'issued'
                ? 'Your certificate for this course has already been issued.'
                : 'Your certificate request for this course is already awaiting review.');
        }

        if (is_array($row)) {
            $db->prepare("UPDATE certificates SET status = 'requested', student_remarks = ?, admin_remarks = NULL, overall_percent = ?, letter_grade = ?, attendance_percent = ?, requested_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$remarks !== '' ? $remarks : null, $snapshot['overall'], $snapshot['letter'], $snapshot['attendance'], (int) $row['id']]);
            $certificateId = (int) $row['id'];
        } else {
            $db->prepare("INSERT INTO certificates(student_id, course_id, status, student_remarks, overall_percent, letter_grade, attendance_percent, requested_at) VALUES(?,?,'requested',?,?,?,?,UTC_TIMESTAMP())")
                ->execute([$studentId, $courseId, $remarks !== '' ? $remarks : null, $snapshot['overall'], $snapshot['letter'], $snapshot['attendance']]);
            $certificateId = (int) $db->lastInsertId();
        }

        $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)")
            ->execute([$studentId, 'certificate.requested', 'certificate', $certificateId, json_encode(['course_id' => $courseId], JSON_THROW_ON_ERROR)]);

        (new MailService($db))->sendCertificateRequest([
            'student_id' => $studentId,
            'student_name' => (string) $user['name'],
            'student_email' => (string) $user['email'],
            'course_title' => (string) $course['title'],
            'reference' => (string) $certificateId,
            'remarks' => $remarks,
        ]);
        // Confirmation in the workspace as well as the inbox, so the student can see the queue.
        (new NotificationService($db))->notifyStudents(
            [$studentId],
            NotificationService::CERTIFICATE,
            'Certificate request submitted',
            'Your certificate request for ' . $course['title'] . ' has been received and is now awaiting review. You will be notified here as soon as it is issued, or if anything needs to be corrected.',
            'certificate_request',
            $certificateId,
            '/student/courses',
            $studentId
        );

        $_SESSION['_flash'] = 'Your certificate request was submitted and is now awaiting review.';
        $this->redirect('/student/courses');
    }

    /** Request a sample upload without touching the official eligibility or certificate workflow. */
    public function testRequest(): void
    {
        $user = AuthMiddleware::requireRole('student');
        try {
            $this->performTestRequest($user);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/student/courses');
        }
    }

    private function performTestRequest(array $user): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $studentId = (int) $user['id'];
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($courseId < 1) throw new RuntimeException('Choose the course for the test certificate workflow.');

        $db = Database::connection();
        $access = $db->prepare(
            "SELECT c.title
             FROM student_course_access sca
             JOIN courses c ON c.id = sca.course_id
             WHERE sca.student_id = ? AND sca.course_id = ?
                   AND sca.status IN ('active','completed','certified')
                   AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             LIMIT 1"
        );
        $access->execute([$studentId, $courseId]);
        $courseTitle = $access->fetchColumn();
        if (!is_string($courseTitle) || $courseTitle === '') {
            throw new RuntimeException('This course is not available in your learning space.');
        }

        $db->beginTransaction();
        try {
            $existing = $db->prepare('SELECT id FROM certificate_test_requests WHERE student_id = ? AND course_id = ? FOR UPDATE');
            $existing->execute([$studentId, $courseId]);
            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException('A test certificate request already exists for this course. Ask the admin to clear all test requests before trying again.');
            }

            $db->prepare("INSERT INTO certificate_test_requests(student_id, course_id, status, requested_at) VALUES(?, ?, 'requested', UTC_TIMESTAMP())")
                ->execute([$studentId, $courseId]);
            $testRequestId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)')
                ->execute([
                    $studentId,
                    'certificate.test_requested',
                    'certificate_test_request',
                    $testRequestId,
                    json_encode(['course_id' => $courseId, 'eligibility_checked' => false], JSON_THROW_ON_ERROR),
                ]);
            $db->commit();
        } catch (\PDOException $exception) {
            if ($db->inTransaction()) $db->rollBack();
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new RuntimeException('A test certificate request already exists for this course. Ask the admin to clear all test requests before trying again.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        $mailed = (new MailService($db))->sendCertificateTestRequest([
            'student_id' => $studentId,
            'student_name' => (string) $user['name'],
            'student_email' => (string) $user['email'],
            'course_title' => $courseTitle,
            'reference' => (string) $testRequestId,
        ]);
        (new NotificationService($db))->notifyStudents(
            [$studentId],
            NotificationService::CERTIFICATE,
            'Test certificate request submitted',
            'Your TEST ONLY request for ' . $courseTitle . ' is with the academy team. This does not issue an official certificate or change your eligibility.',
            'certificate_test_request',
            $testRequestId,
            '/student/courses',
            $studentId
        );

        $_SESSION['_flash'] = $mailed
            ? 'Test-only request submitted. The certificate team has been emailed; this did not issue an official certificate.'
            : 'Test-only request saved, but the certificate team email could not be delivered. Check the mail settings.';
        $this->redirect('/student/courses');
    }

    /** Admin queue of every certificate request and issued certificate. */
    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $days = $this->validityDays($db);
        // Shown on the settings card so the admin can see which ID the next certificate takes, and
        // so the format fields can be edited from this very page. preview() applies the year switch.
        $scheme = AccountIdentity::scheme($db);
        $nextStudentId = AccountIdentity::preview($scheme, $scheme['next']);
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'requested', 'issued', 'expired', 'rejected', 'revoked'], true)) $status = 'all';
        $search = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($search) > 80) $search = mb_substr($search, 0, 80);

        $certificates = [];
        $counts = ['all' => 0, 'requested' => 0, 'issued' => 0, 'expired' => 0, 'rejected' => 0, 'revoked' => 0];
        $schemaAvailable = true;
        $testRequests = [];
        $testRequestsAvailable = true;
        try {
            $testList = $db->query(
                "SELECT t.id, t.student_id, t.course_id, t.status, t.requested_at, t.issued_at, t.file_path,
                        t.file_type, u.full_name AS student_name, u.email AS student_email,
                        u.account_reference, c.title AS course_title
                 FROM certificate_test_requests t
                 JOIN users u ON u.id = t.student_id
                 JOIN courses c ON c.id = t.course_id
                 ORDER BY t.status = 'requested' DESC, t.requested_at DESC, t.id DESC
                 LIMIT 200"
            );
            $testRequests = $testList->fetchAll();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $testRequestsAvailable = false;
        }
        try {
            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR c.title LIKE ? OR cert.certificate_id LIKE ?)';
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like, $like);
            }
            $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

            $summary = $db->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(cert.status = 'requested') AS requested,
                        SUM(cert.status = 'issued' AND (cert.expires_at IS NULL OR cert.expires_at > UTC_TIMESTAMP())) AS issued,
                        SUM(cert.status = 'issued' AND cert.expires_at IS NOT NULL AND cert.expires_at <= UTC_TIMESTAMP()) AS expired,
                        SUM(cert.status = 'rejected') AS rejected,
                        SUM(cert.status = 'revoked') AS revoked
                 FROM certificates cert
                 JOIN users u ON u.id = cert.student_id
                 JOIN courses c ON c.id = cert.course_id" . $clause
            );
            $summary->execute($params);
            foreach ((array) ($summary->fetch() ?: []) as $key => $value) {
                if (array_key_exists($key, $counts)) $counts[$key] = (int) $value;
            }

            $statusFilters = [
                'requested' => "cert.status = 'requested'",
                'issued' => "(cert.status = 'issued' AND (cert.expires_at IS NULL OR cert.expires_at > UTC_TIMESTAMP()))",
                'expired' => "(cert.status = 'issued' AND cert.expires_at IS NOT NULL AND cert.expires_at <= UTC_TIMESTAMP())",
                'rejected' => "cert.status = 'rejected'",
                'revoked' => "cert.status = 'revoked'",
            ];
            $conditions = $where;
            if ($status !== 'all') $conditions[] = $statusFilters[$status];
            $filterClause = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

            // The grade snapshot ships in the same migration; render blanks if an older import lacks it.
            $snapshotColumns = $this->certificateSnapshotAvailable($db)
                ? 'cert.overall_percent, cert.letter_grade, cert.attendance_percent,'
                : 'NULL AS overall_percent, NULL AS letter_grade, NULL AS attendance_percent,';

            $list = $db->prepare(
                "SELECT cert.id, cert.course_id, cert.certificate_id, cert.status, cert.requested_at, cert.issued_at, cert.expires_at,
                        cert.revoked_at, cert.revoked_reason,
                        cert.student_remarks, cert.admin_remarks, cert.notified_at, cert.file_path IS NOT NULL AS has_file,
                        LOWER(SUBSTRING_INDEX(cert.file_path, '.', -1)) AS file_ext,
                        {$snapshotColumns}
                        u.full_name AS student_name, u.email AS student_email,
                        c.title AS course_title, c.duration_label, c.level_label,
                        reviewer.full_name AS reviewed_by_name, revoker.full_name AS revoked_by_name
                 FROM certificates cert
                 JOIN users u ON u.id = cert.student_id
                 JOIN courses c ON c.id = cert.course_id
                 LEFT JOIN users reviewer ON reviewer.id = cert.reviewed_by
                 LEFT JOIN users revoker ON revoker.id = cert.revoked_by{$filterClause}
                 ORDER BY cert.status = 'requested' DESC, cert.requested_at DESC, cert.id DESC
                 LIMIT 200"
            );
            $list->execute($params);
            $certificates = $list->fetchAll();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $schemaAvailable = false;
        }

        View::render('admin/certificates', [
            'title' => 'Certificates',
            'user' => $admin,
            'certificates' => $certificates,
            'testRequests' => $testRequests,
            'testRequestsAvailable' => $testRequestsAvailable,
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
            'schemaAvailable' => $schemaAvailable,
            'contactAddress' => $this->contactAddress(),
            'certificateValues' => [
                'days' => $days,
                'min_days' => self::MIN_DAYS,
                'max_days' => self::MAX_DAYS,
                'idExample' => $nextStudentId,
                'prefix' => $scheme['prefix'],
                'start' => $scheme['next'],
                'include_year' => $scheme['include_year'],
                'max_sequence' => AccountIdentity::MAX_SEQUENCE,
            ],
            'criteria' => $this->eligibilityCriteria($db, $days),
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Saves how long an issued certificate stays verifiable and the Certificate ID format.
     *
     * The number of days is validated here rather than in the browser, so a crafted request cannot
     * park an absurd value in the settings table. The ID format is the student account scheme, which
     * a certificate inherits because it is issued under its owner's ID: the admin sets the prefix,
     * whether a year segment is included, and the serial new IDs start from. It is applied forward
     * only - existing student IDs and already-issued certificates are never rewritten here.
     */
    public function saveSettings(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performSaveSettings($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/certificates');
        }
    }

    private function performSaveSettings(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $rawDays = trim((string) ($_POST[self::DAYS_KEY] ?? ''));
        if (!ctype_digit($rawDays) || (int) $rawDays < self::MIN_DAYS || (int) $rawDays > self::MAX_DAYS) {
            throw new RuntimeException('Certificate availability must be between ' . self::MIN_DAYS . ' and ' . self::MAX_DAYS . ' days.');
        }
        // Only reject the format fields when the page actually posted them, so an older request
        // that carries just the days still saves instead of erroring on absent inputs.
        $postsFormat = array_key_exists('student_id_prefix', $_POST) || array_key_exists('student_id_start', $_POST);
        $prefix = AccountIdentity::cleanPrefix((string) ($_POST['student_id_prefix'] ?? ''));
        $rawStart = trim((string) ($_POST['student_id_start'] ?? ''));
        $includeYear = ((string) ($_POST['student_id_include_year'] ?? '')) === '1';
        if ($postsFormat && (!ctype_digit($rawStart) || (int) $rawStart < 1 || (int) $rawStart > AccountIdentity::MAX_SEQUENCE)) {
            throw new RuntimeException('Enter the serial number new certificate IDs start from, between 1 and ' . AccountIdentity::MAX_SEQUENCE . '.');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $upsert = $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            $upsert->execute([self::DAYS_KEY, (string) (int) $rawDays, (int) $admin['id']]);
            if ($postsFormat) {
                AccountIdentity::saveScheme($db, $prefix, (int) $rawStart, (int) $admin['id'], $includeYear);
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'certificate.settings_updated','app_settings',?)")
                ->execute([(int) $admin['id'], json_encode(['days' => (int) $rawDays, 'format_updated' => $postsFormat, 'prefix' => $postsFormat ? $prefix : null, 'include_year' => $postsFormat ? $includeYear : null, 'start' => $postsFormat ? (int) $rawStart : null], JSON_THROW_ON_ERROR)]);
            $db->commit();
            $_SESSION['_flash'] = 'Certificate settings saved.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $this->redirect('/admin/certificates');
    }

    /**
     * The certificate bars a student must clear, read live from the same settings the grading
     * engine enforces, so this page never quotes a stale number.
     *
     * @return array{grade:int,attendance:int,validDays:int}
     */
    private function eligibilityCriteria(PDO $db, int $validDays): array
    {
        $thresholds = (new CourseGrading($db))->thresholds();
        return ['grade' => $thresholds['grade'], 'attendance' => $thresholds['attendance'], 'validDays' => $validDays];
    }

    /** Approve a request: store the JPG, mint the public ID, and start the validity window. */
    public function issue(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performIssue($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/certificates');
        }
    }

    private function performIssue(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $certificateRowId = filter_var($_POST['certificate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($certificateRowId < 1) throw new RuntimeException('Choose the certificate request to issue.');
        $remarks = trim((string) ($_POST['admin_remarks'] ?? ''));
        if (mb_strlen($remarks) > 1000) throw new RuntimeException('Admin remarks must be 1000 characters or fewer.');
        $upload = $_FILES['certificate_file'] ?? null;
        if (!is_array($upload)) throw new RuntimeException('Attach the signed certificate as a JPG image or a PDF document.');

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT cert.id, cert.status, cert.course_id, cert.student_id, cert.file_path, u.full_name, u.email, c.title AS course_title
             FROM certificates cert
             JOIN users u ON u.id = cert.student_id
             JOIN courses c ON c.id = cert.course_id
             WHERE cert.id = ? LIMIT 1'
        );
        $stmt->execute([$certificateRowId]);
        $certificate = $stmt->fetch();
        if (!is_array($certificate)) throw new RuntimeException('That certificate request no longer exists.');
        if ((string) $certificate['status'] === 'issued') throw new RuntimeException('This certificate has already been issued.');
        $wasRevoked = (string) $certificate['status'] === 'revoked';
        $previousFile = (string) ($certificate['file_path'] ?? '');

        [$storedPath, $fileType] = $this->storeCertificateFile($upload);
        $days = $this->validityDays($db);
        // The certificate is issued under its owner's own account ID, so the number printed on
        // the document is the one the student already quotes everywhere else and types into the
        // verification page. Accounts from before the ID column are filled in here first.
        $publicId = AccountIdentity::ensure($db, (int) $certificate['student_id']);

        $update = $db->prepare(
            "UPDATE certificates
             SET certificate_id = ?, status = 'issued', admin_remarks = ?, file_path = ?, file_type = ?,
                 issued_by = ?, reviewed_by = ?, issued_at = UTC_TIMESTAMP(),
                 expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), notified_at = NULL,
                 revoked_by = NULL, revoked_at = NULL, revoked_reason = NULL
             WHERE id = ? AND status <> 'issued'"
        );
        $update->execute([
            $publicId,
            $remarks !== '' ? $remarks : null,
            $storedPath,
            $fileType,
            (int) $admin['id'],
            (int) $admin['id'],
            $days,
            $certificateRowId,
        ]);
        if ($update->rowCount() !== 1) {
            @unlink(BASE_PATH . '/storage/uploads/private/' . $storedPath);
            throw new RuntimeException('The certificate could not be issued. Reload the page and try again.');
        }
        if ($wasRevoked && $previousFile !== '') $this->removeStoredCertificateFile($previousFile);

        // Issuing lifts the enrollment to certified so the student portal and roster reflect it.
        $db->prepare("UPDATE student_course_access SET status = 'certified' WHERE student_id = ? AND course_id = ? AND status IN ('active','completed')")
            ->execute([(int) $certificate['student_id'], (int) $certificate['course_id']]);

        $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)')
            ->execute([(int) $admin['id'], $wasRevoked ? 'certificate.reissued' : 'certificate.issued', 'certificate', $certificateRowId, json_encode(['certificate_id' => $publicId, 'valid_days' => $days], JSON_THROW_ON_ERROR)]);

        $notified = (new MailService($db))->sendCertificateIssued([
            'student_id' => (int) $certificate['student_id'],
            'student_email' => (string) $certificate['email'],
            'student_name' => (string) $certificate['full_name'],
            'course_title' => (string) $certificate['course_title'],
            'certificate_id' => $publicId,
            'issued_on' => gmdate('j F Y'),
            'expires_on' => (new \DateTimeImmutable('+' . $days . ' days', new \DateTimeZone('UTC')))->format('j F Y'),
            'verify_url' => $this->verifyUrl($publicId),
        ]);
        if ($notified) {
            $db->prepare('UPDATE certificates SET notified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$certificateRowId]);
        }
        // The badge has to light up even when the email could not be delivered.
        (new NotificationService($db))->notifyStudents(
            [(int) $certificate['student_id']],
            NotificationService::CERTIFICATE,
            'Your certificate is ready',
            'Congratulations! Your certificate for ' . $certificate['course_title'] . ' has been issued as ' . $publicId . ' and is ready to download from your courses page.',
            'certificate',
            $certificateRowId,
            '/certificate/download?cert_id=' . rawurlencode($publicId) . '&course_id=' . (int) $certificate['course_id'],
            (int) $admin['id']
        );

        $_SESSION['_flash'] = 'Certificate ' . $publicId . ' issued' . ($notified ? ' and the student has been notified.' : '. The notification email could not be delivered — check MAIL_* settings.');
        $this->redirect('/admin/certificates');
    }

    /** Attach a conspicuously test-only sample file to a separate test request. */
    public function issueTest(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $storedPath = '';
        try {
            if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                throw new RuntimeException('Your session expired. Refresh the page and try again.');
            }
            $testRequestId = filter_var($_POST['test_request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            if ($testRequestId < 1) throw new RuntimeException('Choose a test certificate request.');
            $upload = $_FILES['certificate_file'] ?? null;
            if (!is_array($upload)) throw new RuntimeException('Attach a clearly marked sample JPG image or PDF document.');

            $db = Database::connection();
            $db->beginTransaction();
            try {
                $find = $db->prepare(
                    "SELECT t.student_id, t.course_id, t.status, u.full_name, c.title AS course_title
                     FROM certificate_test_requests t
                     JOIN users u ON u.id = t.student_id
                     JOIN courses c ON c.id = t.course_id
                     WHERE t.id = ? FOR UPDATE"
                );
                $find->execute([$testRequestId]);
                $request = $find->fetch();
                if (!is_array($request)) throw new RuntimeException('That test certificate request no longer exists.');
                if ((string) $request['status'] !== 'requested') throw new RuntimeException('A sample has already been uploaded for this test request.');

                [$storedPath, $fileType] = $this->storeCertificateFile($upload, self::TEST_STORAGE_FOLDER);
                $update = $db->prepare(
                    "UPDATE certificate_test_requests
                     SET status = 'issued', file_path = ?, file_type = ?, issued_by = ?, issued_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'requested'"
                );
                $update->execute([$storedPath, $fileType, (int) $admin['id'], $testRequestId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('The test sample could not be saved. Reload the page and try again.');

                $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)')
                    ->execute([
                        (int) $admin['id'],
                        'certificate.test_sample_uploaded',
                        'certificate_test_request',
                        $testRequestId,
                        json_encode(['course_id' => (int) $request['course_id'], 'file_type' => $fileType], JSON_THROW_ON_ERROR),
                    ]);
                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                if ($storedPath !== '') $this->removeStoredCertificateFile($storedPath);
                throw $exception;
            }

            (new NotificationService($db))->notifyStudents(
                [(int) $request['student_id']],
                NotificationService::CERTIFICATE,
                'Your test certificate sample is ready',
                'A TEST ONLY sample for ' . $request['course_title'] . ' is ready to download from My courses. It is not an official certificate.',
                'certificate_test_request',
                $testRequestId,
                '/student/courses',
                (int) $admin['id']
            );
            $_SESSION['_flash'] = 'Test-only sample uploaded. It is separate from the official certificate register.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/admin/certificates');
    }

    /** Remove every test request and uploaded sample; official certificate rows are untouched. */
    public function clearTestRequests(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                throw new RuntimeException('Your session expired. Refresh the page and try again.');
            }
            $db = Database::connection();
            $db->beginTransaction();
            try {
                $rows = $db->query('SELECT id, file_path FROM certificate_test_requests FOR UPDATE')->fetchAll();
                $count = count($rows);
                $paths = array_values(array_filter(
                    array_column($rows, 'file_path'),
                    static fn(mixed $path): bool => is_string($path) && $path !== ''
                ));
                if ($count > 0) {
                    $db->exec('DELETE FROM certificate_test_requests');
                    $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,?,?,?)')
                        ->execute([
                            (int) $admin['id'],
                            'certificate.test_requests_cleared',
                            'certificate_test_request',
                            json_encode(['requests' => $count], JSON_THROW_ON_ERROR),
                        ]);
                }
                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                throw $exception;
            }
            $cleanup = $this->removeStoredTestFiles(is_array($paths) ? $paths : []);
            if (($cleanup['failed'] ?? 0) > 0) {
                $_SESSION['_flash_error'] = $count . ' test request(s) were cleared, but ' . $cleanup['failed'] . ' sample file(s) could not be removed. Check the server log and private upload folder; official certificates and eligibility rules were not changed.';
            } else {
                $_SESSION['_flash'] = $count === 0
                    ? 'There are no test certificate requests to clear.'
                    : $count . ' test request(s) cleared; ' . $cleanup['removed'] . ' sample file(s) removed. Official certificates and eligibility rules were not changed.';
            }
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/admin/certificates');
    }

    /** Revoke an issued certificate and remove its certified access state. */
    public function revoke(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performRevoke($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/certificates');
        }
    }

    private function performRevoke(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $certificateRowId = filter_var($_POST['certificate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $reason = trim((string) ($_POST['revoked_reason'] ?? ''));
        if ($certificateRowId < 1) throw new RuntimeException('Choose the certificate to revoke.');
        if ($reason === '') throw new RuntimeException('Add a reason before revoking this certificate.');
        if (mb_strlen($reason) > 1000) throw new RuntimeException('The revocation reason must be 1000 characters or fewer.');

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT cert.student_id, cert.course_id, cert.certificate_id, cert.status,
                        u.full_name, u.email, c.title AS course_title
                 FROM certificates cert
                 JOIN users u ON u.id = cert.student_id
                 JOIN courses c ON c.id = cert.course_id
                 WHERE cert.id = ? FOR UPDATE'
            );
            $stmt->execute([$certificateRowId]);
            $certificate = $stmt->fetch();
            if (!is_array($certificate)) throw new RuntimeException('That certificate no longer exists.');
            if ((string) $certificate['status'] !== 'issued') {
                throw new RuntimeException('Only an issued certificate can be revoked.');
            }

            $update = $db->prepare(
                "UPDATE certificates
                 SET status = 'revoked', revoked_by = ?, revoked_at = UTC_TIMESTAMP(), revoked_reason = ?, reviewed_by = ?
                 WHERE id = ? AND status = 'issued'"
            );
            $update->execute([(int) $admin['id'], $reason, (int) $admin['id'], $certificateRowId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('The certificate could not be revoked. Reload the page and try again.');

            $db->prepare("UPDATE student_course_access SET status = 'completed' WHERE student_id = ? AND course_id = ? AND status = 'certified'")
                ->execute([(int) $certificate['student_id'], (int) $certificate['course_id']]);
            $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)')
                ->execute([
                    (int) $admin['id'],
                    'certificate.revoked',
                    'certificate',
                    $certificateRowId,
                    json_encode(['certificate_id' => $certificate['certificate_id'], 'reason' => $reason], JSON_THROW_ON_ERROR),
                ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        $notified = (new MailService($db))->sendCertificateRevoked([
            'student_id' => (int) $certificate['student_id'],
            'student_email' => (string) $certificate['email'],
            'student_name' => (string) $certificate['full_name'],
            'course_title' => (string) $certificate['course_title'],
            'certificate_id' => (string) $certificate['certificate_id'],
            'reason' => $reason,
        ]);
        (new NotificationService($db))->notifyStudents(
            [(int) $certificate['student_id']],
            NotificationService::CERTIFICATE,
            'Your certificate was revoked',
            'The certificate for ' . $certificate['course_title'] . ' (' . $certificate['certificate_id'] . ') was revoked. Reason: ' . $reason,
            'certificate',
            $certificateRowId,
            '/student/courses',
            (int) $admin['id']
        );

        $_SESSION['_flash'] = 'Certificate ' . $certificate['certificate_id'] . ' revoked' . ($notified ? ' and the student has been emailed.' : '. The notification email could not be delivered — check MAIL_* settings.');
        $this->redirect('/admin/certificates');
    }

    /** Decline a request with a reason the student can read. */
    public function reject(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performReject($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/certificates');
        }
    }

    private function performReject(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $certificateRowId = filter_var($_POST['certificate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $remarks = trim((string) ($_POST['admin_remarks'] ?? ''));
        if ($certificateRowId < 1) throw new RuntimeException('Choose the certificate request to reject.');
        if ($remarks === '') throw new RuntimeException('Add a short reason so the student knows what to correct.');
        if (mb_strlen($remarks) > 1000) throw new RuntimeException('Admin remarks must be 1000 characters or fewer.');

        $db = Database::connection();
        $find = $db->prepare(
            'SELECT cert.student_id, cert.course_id, u.full_name, u.email, c.title AS course_title
             FROM certificates cert
             JOIN users u ON u.id = cert.student_id
             JOIN courses c ON c.id = cert.course_id
             WHERE cert.id = ? LIMIT 1'
        );
        $find->execute([$certificateRowId]);
        $certificate = $find->fetch();
        $stmt = $db->prepare("UPDATE certificates SET status = 'rejected', admin_remarks = ?, reviewed_by = ? WHERE id = ? AND status = 'requested'");
        $stmt->execute([$remarks, (int) $admin['id'], $certificateRowId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Only pending requests can be rejected.');

        $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,\'certificate\',?)')
            ->execute([(int) $admin['id'], 'certificate.rejected', $certificateRowId]);

        // A rejection needs an action from the student, so it must reach them twice over.
        if (is_array($certificate)) {
            (new MailService($db))->sendCertificateRejected([
                'student_id' => (int) $certificate['student_id'],
                'student_email' => (string) $certificate['email'],
                'student_name' => (string) $certificate['full_name'],
                'course_title' => (string) $certificate['course_title'],
                'remarks' => $remarks,
            ]);
            (new NotificationService($db))->notifyStudents(
                [(int) $certificate['student_id']],
                NotificationService::CERTIFICATE,
                'Certificate request needs attention',
                'Your certificate request for ' . $certificate['course_title'] . ' could not be approved yet. Reason from the review team: ' . $remarks . ' Correct the outstanding requirement and submit a new request from your courses page.',
                'certificate_request',
                $certificateRowId,
                '/student/courses',
                (int) $admin['id']
            );
        }

        $_SESSION['_flash'] = 'Certificate request rejected. The student can correct the issue and request again.';
        $this->redirect('/admin/certificates');
    }

    /** Public verification page — no sign-in required. */
    public function verify(): void
    {
        $lookup = trim((string) ($_GET['cert_id'] ?? ''));
        $isPublicId = preg_match(self::PUBLIC_ID_PATTERN, strtoupper($lookup)) === 1;
        $publicId = $isPublicId ? strtoupper($lookup) : '';
        $result = ['state' => 'empty', 'certificates' => []];
        $contact = $this->contactAddress();

        if ($lookup !== '') {
            if (mb_strlen($lookup) > 120) {
                $result = ['state' => 'invalid', 'certificates' => []];
            } elseif (!$this->publicLookupAllowed($isPublicId ? $publicId : $lookup)) {
                $result = ['state' => 'limited', 'certificates' => []];
            } else {
                try {
                    $db = Database::connection();
                    $rows = [];
                    if ($isPublicId) {
                        // One ID opens every official certificate and any separate test samples
                        // behind that account. Test entries carry a visible non-official marker.
                        $stmt = $db->prepare(
                            "SELECT cert.id AS certificate_row_id, cert.course_id, cert.certificate_id,
                                        cert.issued_at, cert.expires_at, cert.revoked_at, cert.revoked_reason,
                                        cert.admin_remarks, cert.status AS certificate_status,
                                        cert.file_path IS NOT NULL AS has_file,
                                        LOWER(SUBSTRING_INDEX(cert.file_path, '.', -1)) AS file_ext,
                                        0 AS is_test_certificate,
                                        u.full_name AS student_name, u.account_reference AS student_id,
                                        c.title AS course_title, c.duration_label, c.level_label
                             FROM certificates cert
                             JOIN users u ON u.id = cert.student_id
                             JOIN courses c ON c.id = cert.course_id
                             WHERE cert.certificate_id = ? AND cert.status IN ('issued', 'revoked')
                             ORDER BY cert.status = 'revoked',
                                      (cert.expires_at IS NOT NULL AND cert.expires_at <= UTC_TIMESTAMP()), cert.issued_at DESC"
                        );
                        $stmt->execute([$publicId]);
                        $rows = array_values(array_filter((array) $stmt->fetchAll(), 'is_array'));
                        $rows = array_merge($rows, $this->testCertificateLookup($db, $publicId, true));
                    } else {
                        // Exact-name lookup is deliberately limited to clearly marked test samples;
                        // official certificates remain searchable only by their public Academy ID.
                        $rows = $this->testCertificateLookup($db, $lookup, false);
                    }
                    if ($rows === []) {
                        $result = ['state' => 'not_found', 'certificates' => []];
                    } else {
                        usort($rows, static fn(array $a, array $b): int => (int) ($a['is_test_certificate'] ?? 0) <=> (int) ($b['is_test_certificate'] ?? 0));
                        $now = gmdate('Y-m-d H:i:s');
                        $issued = array_filter($rows, static fn(array $row): bool => (string) $row['certificate_status'] === 'issued');
                        $live = array_filter($issued, static function (array $row) use ($now): bool {
                            return (string) ($row['expires_at'] ?? '') === '' || (string) $row['expires_at'] > $now;
                        });
                        $state = $live !== [] ? 'valid' : ($issued !== [] ? 'expired' : 'revoked');
                        $result = ['state' => $state, 'certificates' => $rows];
                    }
                } catch (\PDOException $exception) {
                    // Never leak connection or schema detail to an anonymous visitor.
                    error_log('[CERTIFICATE] Verification lookup failed: ' . $exception->getMessage());
                    $result = ['state' => 'unavailable', 'certificates' => []];
                }
            }
        }

        View::render('pages/verify', [
            'title' => 'Verify a certificate',
            'query' => $lookup,
            'result' => $result,
            'contact' => $contact,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }

    /**
     * Test-only sample records can be searched by Academy ID or exact student name.
     * An older schema without the test table simply has no test samples to show.
     *
     * @return list<array<string,mixed>>
     */
    private function testCertificateLookup(PDO $db, string $query, bool $byId): array
    {
        try {
            $sql = "SELECT t.id AS certificate_row_id, t.course_id, u.account_reference AS certificate_id,
                           t.issued_at, NULL AS expires_at, NULL AS revoked_at, NULL AS revoked_reason,
                           'TEST ONLY — SAMPLE, NOT OFFICIAL' AS admin_remarks, 'issued' AS certificate_status,
                           t.file_path IS NOT NULL AS has_file, t.file_type AS file_ext, 1 AS is_test_certificate,
                           u.full_name AS student_name, u.account_reference AS student_id,
                           c.title AS course_title, c.duration_label, c.level_label
                    FROM certificate_test_requests t
                    JOIN users u ON u.id = t.student_id
                    JOIN courses c ON c.id = t.course_id
                    WHERE t.status = 'issued' AND t.file_path IS NOT NULL AND ";
            $sql .= $byId ? 'u.account_reference = ?' : 'u.full_name = ?';
            $sql .= ' ORDER BY t.issued_at DESC, t.id DESC LIMIT 50';
            $stmt = $db->prepare($sql);
            $stmt->execute([$query]);
            return array_values(array_filter((array) $stmt->fetchAll(), 'is_array'));
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') return [];
            throw $exception;
        }
    }

    /** Serve an issued, unexpired certificate image without exposing its path. */
    public function download(): void
    {
        $rawId = strtoupper(trim((string) ($_GET['cert_id'] ?? '')));
        if (preg_match(self::PUBLIC_ID_PATTERN, $rawId) !== 1) {
            $this->notFound();
            return;
        }
        if (!$this->publicLookupAllowed($rawId)) {
            $this->notFound();
            return;
        }

        $courseId = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $sql = "SELECT certificate_id, file_path FROM certificates
                 WHERE certificate_id = ? AND status = 'issued' AND file_path IS NOT NULL
                       AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())";
        $params = [$rawId];
        // Several certificates can share one ID, so the page that linked here names the course.
        // Without that hint the most recent live certificate is served.
        if ($courseId > 0) {
            $sql .= ' AND course_id = ?';
            $params[] = $courseId;
        }
        $sql .= ' ORDER BY issued_at DESC LIMIT 1';

        $db = Database::connection();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $certificate = $stmt->fetch();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $this->notFound();
            return;
        }
        if (!is_array($certificate) || !preg_match(self::FILE_PATTERN, (string) $certificate['file_path'])) {
            $this->notFound();
            return;
        }

        $root = realpath(BASE_PATH . '/storage/uploads/private');
        $file = $root === false ? false : realpath($root . '/' . (string) $certificate['file_path']);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            $this->notFound();
            return;
        }
        // The format served is the format the file really is, taken from its own content and then
        // required to agree with the extension stored against it - so a JPG is handed back as a JPG
        // and a PDF as a PDF, and a mislabelled file is refused rather than guessed at.
        $declared = strtolower(pathinfo((string) $certificate['file_path'], PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        $format = match (true) {
            $mime === 'image/jpeg' && $declared === 'jpg' => 'jpg',
            $mime === 'application/pdf' && $declared === 'pdf' => 'pdf',
            default => null,
        };
        if ($format === null) {
            $this->notFound();
            return;
        }

        $safeName = preg_replace('/[^A-Za-z0-9-]/', '-', (string) $certificate['certificate_id']) ?: 'certificate';
        header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'image/jpeg'));
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="' . $safeName . '.' . $format . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    /** Serve an uploaded test sample from the separate test-only register. */
    public function downloadTest(): void
    {
        $testRequestId = filter_var($_GET['test_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($testRequestId < 1) {
            $this->notFound();
            return;
        }
        try {
            $stmt = Database::connection()->prepare(
                "SELECT id, file_path, file_type FROM certificate_test_requests
                 WHERE id = ? AND status = 'issued' AND file_path IS NOT NULL LIMIT 1"
            );
            $stmt->execute([$testRequestId]);
            $testCertificate = $stmt->fetch();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $this->notFound();
            return;
        }
        if (!is_array($testCertificate) || !preg_match(self::TEST_FILE_PATTERN, (string) $testCertificate['file_path'])) {
            $this->notFound();
            return;
        }

        $root = realpath(BASE_PATH . '/storage/uploads/private');
        $file = $root === false ? false : realpath($root . '/' . (string) $testCertificate['file_path']);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            $this->notFound();
            return;
        }
        $declared = strtolower(pathinfo((string) $testCertificate['file_path'], PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        $format = match (true) {
            $mime === 'image/jpeg' && $declared === 'jpg' && (string) $testCertificate['file_type'] === 'jpg' => 'jpg',
            $mime === 'application/pdf' && $declared === 'pdf' && (string) $testCertificate['file_type'] === 'pdf' => 'pdf',
            default => null,
        };
        if ($format === null) {
            $this->notFound();
            return;
        }

        header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'image/jpeg'));
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="TEST-SAMPLE-' . (int) $testCertificate['id'] . '.' . $format . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    /**
     * Certificate identifiers are short and sequential, so public lookups are
     * throttled per address to keep bulk enumeration impractical. A caller that
     * is rate limited gets a neutral result instead of an error page.
     */
    private function publicLookupAllowed(string $certificateId): bool
    {
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        try {
            $limiter = new RateLimiter(Database::connection());
            $limiter->assertAllowed('certificate_verify', [$ip], 40, 600);
            $limiter->record('certificate_verify', [$ip, 'cert:' . $certificateId]);
            return true;
        } catch (\RuntimeException $exception) {
            return false;
        } catch (\PDOException $exception) {
            // A missing rate-limit table must not take verification offline.
            error_log('[CERTIFICATE] Rate limiting unavailable: ' . $exception->getMessage());
            return true;
        }
    }

    /** How many days an issued certificate stays verifiable, falling back to the default. */
    private function validityDays(PDO $db): int
    {
        $days = self::DEFAULT_DAYS;
        try {
            $statement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $statement->execute([self::DAYS_KEY]);
            $stored = $statement->fetchColumn();
            if ($stored !== false) $days = (int) $stored;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
        }
        return $days >= self::MIN_DAYS && $days <= self::MAX_DAYS ? $days : self::DEFAULT_DAYS;
    }

    /**
     * Stores the signed certificate the admin attached, in either of the two accepted formats.
     *
     * A JPG is checked the way a certificate image always was (finfo, getimagesize and the JPEG
     * magic bytes). A PDF is a document, not an image, so it is checked by its %PDF header and
     * finfo type instead of getimagesize, which cannot read one. The extension on the random,
     * server-generated filename is also the format of record, so the download hands back what was
     * uploaded and never trusts a client-supplied name.
     *
     * @return array{0:string,1:string} the storage-relative path and the stored format (jpg|pdf)
     */
    private function storeCertificateFile(array $upload, string $storageFolder = self::STORAGE_FOLDER): array
    {
        if (!in_array($storageFolder, [self::STORAGE_FOLDER, self::TEST_STORAGE_FOLDER], true)) {
            throw new RuntimeException('The certificate upload folder is not available.');
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Attach the signed certificate as a JPG image or a PDF document.');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('The certificate file must be 5 MB or smaller.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $magic = (string) file_get_contents($tmp, false, null, 0, 4);
        if ($mime === 'image/jpeg' && str_starts_with($magic, "\xFF\xD8\xFF")) {
            $info = @getimagesize($tmp);
            if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) throw new RuntimeException('The uploaded file is not a readable JPG image.');
            $extension = 'jpg';
        } elseif ($mime === 'application/pdf' && str_starts_with($magic, '%PDF')) {
            $extension = 'pdf';
        } else {
            throw new RuntimeException('Only JPG images and PDF documents are accepted as a certificate.');
        }
        PrivateStorage::directory($storageFolder);
        $relative = $storageFolder . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
        if (!move_uploaded_file($tmp, BASE_PATH . '/storage/uploads/private/' . $relative)) {
            throw new RuntimeException('The certificate file could not be stored.');
        }
        @chmod(BASE_PATH . '/storage/uploads/private/' . $relative, 0600);
        return [$relative, $extension];
    }

    private function removeStoredCertificateFile(string $relative): void
    {
        if (!preg_match(self::FILE_PATTERN, $relative) && !preg_match(self::TEST_FILE_PATTERN, $relative)) {
            error_log('[CERTIFICATE] Refused to remove an invalid stored file path.');
            return;
        }
        $root = realpath(BASE_PATH . '/storage/uploads/private');
        $file = $root === false ? false : realpath($root . '/' . $relative);
        if ($file === false) return;
        if ($root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            error_log('[CERTIFICATE] Refused to remove a file outside private storage.');
            return;
        }
        if (!@unlink($file)) {
            error_log('[CERTIFICATE] Could not remove a replaced private certificate file.');
        }
    }

    /** Remove only sample files whose relative paths match the private test-upload naming. */
    /** @return array{removed:int,failed:int} */
    private function removeStoredTestFiles(array $relativePaths): array
    {
        $root = realpath(BASE_PATH . '/storage/uploads/private');
        if ($root === false) return ['removed' => 0, 'failed' => 0];
        $result = ['removed' => 0, 'failed' => 0];
        foreach ($relativePaths as $relative) {
            $relative = trim((string) $relative);
            if (!preg_match(self::TEST_FILE_PATTERN, $relative)) {
                error_log('[CERTIFICATE] Refused to remove an invalid stored test-certificate path.');
                $result['failed']++;
                continue;
            }
            $file = realpath($root . '/' . $relative);
            if ($file === false) continue;
            if (!str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
                error_log('[CERTIFICATE] Refused to remove a test-certificate file outside private storage.');
                $result['failed']++;
                continue;
            }
            if (@unlink($file)) {
                $result['removed']++;
            } else {
                error_log('[CERTIFICATE] Could not remove a cleared test-certificate file.');
                $result['failed']++;
            }
        }
        return $result;
    }

    private function contactAddress(): string
    {
        $configured = trim((string) (getenv('MAIL_FROM_ADDRESS') ?: ''));
        if (filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) return $configured;
        return self::CONTACT_FALLBACK;
    }

    private function verifyUrl(string $certificateId): string
    {
        return rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/verify?cert_id=' . rawurlencode($certificateId);
    }

    private function certificateSnapshotAvailable(PDO $db): bool
    {
        try {
            $db->query('SELECT overall_percent FROM certificates LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1054 || (string) $exception->getCode() === '42S02') return false;
            throw $exception;
        }
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/404', ['title' => 'Certificate unavailable']);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
