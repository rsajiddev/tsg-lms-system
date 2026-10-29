<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Security;
use App\Core\View;
use App\Services\CourseGrading;
use App\Services\MailService;
use PDO;
use RuntimeException;

/**
 * Certificate requests, admin issuing, public verification, and the certificate settings
 * that shape an issued identifier.
 *
 * Certificate images live below storage/uploads/private, which is outside the
 * document root and blocked by .htaccess. Every reader goes through this
 * controller so a path is never printed, linked, or guessed from the client.
 */
final class CertificateController
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const STORAGE_FOLDER = 'certificates';
    private const FILE_PATTERN = '#^certificates/[a-f0-9]{48}\.jpg$#';
    private const PUBLIC_ID_PATTERN = '/^[A-Z][A-Z0-9-]{3,63}$/';
    private const CONTACT_FALLBACK = 'info@techsgstudio.com';

    /** Certificate defaults keep the workspace usable before an admin edits anything. */
    public const PREFIX_KEY = 'certificate_id_prefix';
    public const DAYS_KEY = 'certificate_available_days';
    public const DEFAULT_PREFIX = 'TSG-CERT';
    public const DEFAULT_DAYS = 15;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 3650;
    public const MAX_PREFIX_LENGTH = 24;
    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9]*(-[A-Z0-9]+)*$/';

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

        (new MailService())->sendCertificateRequest(
            (string) $user['name'],
            (string) $user['email'],
            (string) $course['title'],
            (string) $certificateId,
            $remarks
        );

        $_SESSION['_flash'] = 'Your certificate request was submitted and is now awaiting review.';
        $this->redirect('/student/courses');
    }

    /** Admin queue of every certificate request and issued certificate. */
    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $settings = $this->certificateSettings($db);
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'requested', 'issued', 'expired', 'rejected'], true)) $status = 'all';
        $search = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($search) > 80) $search = mb_substr($search, 0, 80);

        $certificates = [];
        $counts = ['all' => 0, 'requested' => 0, 'issued' => 0, 'expired' => 0, 'rejected' => 0];
        $schemaAvailable = true;
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
                        SUM(cert.status = 'rejected') AS rejected
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
            ];
            $conditions = $where;
            if ($status !== 'all') $conditions[] = $statusFilters[$status];
            $filterClause = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

            // The grade snapshot ships in the same migration; render blanks if an older import lacks it.
            $snapshotColumns = $this->certificateSnapshotAvailable($db)
                ? 'cert.overall_percent, cert.letter_grade, cert.attendance_percent,'
                : 'NULL AS overall_percent, NULL AS letter_grade, NULL AS attendance_percent,';

            $list = $db->prepare(
                "SELECT cert.id, cert.certificate_id, cert.status, cert.requested_at, cert.issued_at, cert.expires_at,
                        cert.student_remarks, cert.admin_remarks, cert.notified_at, cert.file_path IS NOT NULL AS has_file,
                        {$snapshotColumns}
                        u.full_name AS student_name, u.email AS student_email,
                        c.title AS course_title, c.duration_label, c.level_label,
                        reviewer.full_name AS reviewed_by_name
                 FROM certificates cert
                 JOIN users u ON u.id = cert.student_id
                 JOIN courses c ON c.id = cert.course_id
                 LEFT JOIN users reviewer ON reviewer.id = cert.reviewed_by{$filterClause}
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
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
            'schemaAvailable' => $schemaAvailable,
            'contactAddress' => $this->contactAddress(),
            'certificateValues' => [
                'prefix' => $settings[0],
                'days' => $settings[1],
                'max_prefix_length' => self::MAX_PREFIX_LENGTH,
                'min_days' => self::MIN_DAYS,
                'max_days' => self::MAX_DAYS,
            ],
            'criteria' => $this->eligibilityCriteria($db, (int) $settings[1]),
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Saves the identifier prefix and validity period. Branding is validated here rather than in
     * the browser, so a crafted request can never store a prefix that would leak into public URLs.
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
        $prefix = strtoupper(trim((string) ($_POST[self::PREFIX_KEY] ?? '')));
        if ($prefix === '') $prefix = self::DEFAULT_PREFIX;
        if (mb_strlen($prefix) > self::MAX_PREFIX_LENGTH || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new RuntimeException('Certificate ID prefix must be up to ' . self::MAX_PREFIX_LENGTH . ' characters: uppercase letters, digits or single hyphens, starting with a letter (for example TSG-CERT).');
        }
        $rawDays = trim((string) ($_POST[self::DAYS_KEY] ?? ''));
        if (!ctype_digit($rawDays) || (int) $rawDays < self::MIN_DAYS || (int) $rawDays > self::MAX_DAYS) {
            throw new RuntimeException('Certificate availability must be between ' . self::MIN_DAYS . ' and ' . self::MAX_DAYS . ' days.');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $upsert = $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            $upsert->execute([self::PREFIX_KEY, $prefix, (int) $admin['id']]);
            $upsert->execute([self::DAYS_KEY, (string) (int) $rawDays, (int) $admin['id']]);
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'certificate.settings_updated','app_settings',JSON_OBJECT('prefix',?, 'days', ?))")->execute([(int) $admin['id'], $prefix, (int) $rawDays]);
            $db->commit();
            $_SESSION['_flash'] = 'Certificate settings saved.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $this->redirect('/admin/certificates');
    }

    /**
     * The three bars a student must clear before a certificate can be requested, read live from
     * the same settings the grading engine enforces, so this page never quotes a stale number.
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
        if (!is_array($upload)) throw new RuntimeException('Attach the signed certificate as a JPG image.');

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT cert.id, cert.status, cert.course_id, cert.student_id, u.full_name, u.email, c.title AS course_title
             FROM certificates cert
             JOIN users u ON u.id = cert.student_id
             JOIN courses c ON c.id = cert.course_id
             WHERE cert.id = ? LIMIT 1'
        );
        $stmt->execute([$certificateRowId]);
        $certificate = $stmt->fetch();
        if (!is_array($certificate)) throw new RuntimeException('That certificate request no longer exists.');
        if ((string) $certificate['status'] === 'issued') throw new RuntimeException('This certificate has already been issued.');

        $storedPath = $this->storeCertificateJpg($upload);
        [$prefix, $days] = $this->certificateSettings($db);
        $publicId = $this->nextCertificateId($db, $prefix);

        $update = $db->prepare(
            "UPDATE certificates
             SET certificate_id = ?, status = 'issued', admin_remarks = ?, file_path = ?,
                 issued_by = ?, reviewed_by = ?, issued_at = UTC_TIMESTAMP(),
                 expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), notified_at = NULL
             WHERE id = ? AND status <> 'issued'"
        );
        $update->execute([
            $publicId,
            $remarks !== '' ? $remarks : null,
            $storedPath,
            (int) $admin['id'],
            (int) $admin['id'],
            $days,
            $certificateRowId,
        ]);
        if ($update->rowCount() !== 1) {
            @unlink(BASE_PATH . '/storage/uploads/private/' . $storedPath);
            throw new RuntimeException('The certificate could not be issued. Reload the page and try again.');
        }

        // Issuing lifts the enrollment to certified so the student portal and roster reflect it.
        $db->prepare("UPDATE student_course_access SET status = 'certified' WHERE student_id = ? AND course_id = ? AND status IN ('active','completed')")
            ->execute([(int) $certificate['student_id'], (int) $certificate['course_id']]);

        $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)')
            ->execute([(int) $admin['id'], 'certificate.issued', 'certificate', $certificateRowId, json_encode(['certificate_id' => $publicId, 'valid_days' => $days], JSON_THROW_ON_ERROR)]);

        $notified = (new MailService())->sendCertificateIssued(
            (string) $certificate['email'],
            (string) $certificate['full_name'],
            (string) $certificate['course_title'],
            $publicId,
            gmdate('j F Y'),
            (new \DateTimeImmutable('+' . $days . ' days', new \DateTimeZone('UTC')))->format('j F Y'),
            $this->verifyUrl($publicId)
        );
        if ($notified) {
            $db->prepare('UPDATE certificates SET notified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$certificateRowId]);
        }

        $_SESSION['_flash'] = 'Certificate ' . $publicId . ' issued' . ($notified ? ' and the student has been notified.' : '. The notification email could not be delivered — check MAIL_* settings.');
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
        $stmt = $db->prepare("UPDATE certificates SET status = 'rejected', admin_remarks = ?, reviewed_by = ? WHERE id = ? AND status = 'requested'");
        $stmt->execute([$remarks, (int) $admin['id'], $certificateRowId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Only pending requests can be rejected.');

        $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,\'certificate\',?)')
            ->execute([(int) $admin['id'], 'certificate.rejected', $certificateRowId]);

        $_SESSION['_flash'] = 'Certificate request rejected. The student can correct the issue and request again.';
        $this->redirect('/admin/certificates');
    }

    /** Public verification page — no sign-in required. */
    public function verify(): void
    {
        $rawId = strtoupper(trim((string) ($_GET['cert_id'] ?? '')));
        $result = ['state' => 'empty', 'certificate' => null];
        $contact = $this->contactAddress();

        if ($rawId !== '') {
            if (!$this->publicLookupAllowed($rawId)) {
                $result = ['state' => 'limited', 'certificate' => null];
            } elseif (preg_match(self::PUBLIC_ID_PATTERN, $rawId) !== 1) {
                $result = ['state' => 'invalid', 'certificate' => null];
            } else {
                try {
                    $db = Database::connection();
                    $stmt = $db->prepare(
                        "SELECT cert.certificate_id, cert.issued_at, cert.expires_at, cert.admin_remarks,
                                cert.file_path IS NOT NULL AS has_file,
                                u.full_name AS student_name, c.title AS course_title, c.duration_label, c.level_label
                         FROM certificates cert
                         JOIN users u ON u.id = cert.student_id
                         JOIN courses c ON c.id = cert.course_id
                         WHERE cert.certificate_id = ? AND cert.status = 'issued'
                         LIMIT 1"
                    );
                    $stmt->execute([$rawId]);
                    $certificate = $stmt->fetch();
                    if (!is_array($certificate)) {
                        $result = ['state' => 'not_found', 'certificate' => null];
                    } elseif ($certificate['expires_at'] !== null && (string) $certificate['expires_at'] <= gmdate('Y-m-d H:i:s')) {
                        $result = ['state' => 'expired', 'certificate' => $certificate];
                    } else {
                        $result = ['state' => 'valid', 'certificate' => $certificate];
                    }
                } catch (\PDOException $exception) {
                    // Never leak connection or schema detail to an anonymous visitor.
                    error_log('[CERTIFICATE] Verification lookup failed: ' . $exception->getMessage());
                    $result = ['state' => 'unavailable', 'certificate' => null];
                }
            }
        }

        View::render('pages/verify', [
            'title' => 'Verify a certificate',
            'query' => $rawId,
            'result' => $result,
            'contact' => $contact,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
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

        $db = Database::connection();
        try {
            $stmt = $db->prepare(
                "SELECT certificate_id, file_path FROM certificates
                 WHERE certificate_id = ? AND status = 'issued' AND file_path IS NOT NULL
                       AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                 LIMIT 1"
            );
            $stmt->execute([$rawId]);
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
        if ((new \finfo(FILEINFO_MIME_TYPE))->file($file) !== 'image/jpeg') {
            $this->notFound();
            return;
        }

        $safeName = preg_replace('/[^A-Za-z0-9-]/', '-', (string) $certificate['certificate_id']) ?: 'certificate';
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="' . $safeName . '.jpg"');
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

    private function certificateSettings(PDO $db): array
    {
        $prefix = self::DEFAULT_PREFIX;
        $days = self::DEFAULT_DAYS;
        try {
            $statement = $db->prepare('SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN (?, ?)');
            $statement->execute([self::PREFIX_KEY, self::DAYS_KEY]);
            foreach ($statement->fetchAll() as $row) {
                if ($row['setting_key'] === self::PREFIX_KEY) $prefix = strtoupper(trim((string) $row['setting_value']));
                if ($row['setting_key'] === self::DAYS_KEY) $days = (int) $row['setting_value'];
            }
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1 || mb_strlen($prefix) > self::MAX_PREFIX_LENGTH) {
            $prefix = self::DEFAULT_PREFIX;
        }
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            $days = self::DEFAULT_DAYS;
        }
        return [$prefix, $days];
    }

    private function nextCertificateId(PDO $db, string $prefix): string
    {
        $year = (int) gmdate('Y');
        $count = $db->prepare('SELECT COUNT(*) FROM certificates WHERE certificate_id LIKE ?');
        $count->execute([$prefix . '-' . $year . '-%']);
        $sequence = (int) $count->fetchColumn() + 1;
        $exists = $db->prepare('SELECT 1 FROM certificates WHERE certificate_id = ? LIMIT 1');
        do {
            $candidate = sprintf('%s-%d-%03d', $prefix, $year, $sequence);
            $exists->execute([$candidate]);
            if (!$exists->fetch()) return $candidate;
            $sequence++;
        } while ($sequence < 100000);
        throw new RuntimeException('The certificate sequence is exhausted for this year.');
    }

    private function storeCertificateJpg(array $upload): string
    {
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Attach the signed certificate as a JPG image.');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('The certificate image must be 5 MB or smaller.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime !== 'image/jpeg') throw new RuntimeException('Only JPG certificate images are accepted.');
        $info = @getimagesize($tmp);
        if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) throw new RuntimeException('The uploaded file is not a readable JPG image.');
        if ((string) file_get_contents($tmp, false, null, 0, 3) !== "\xFF\xD8\xFF") {
            throw new RuntimeException('The uploaded file content does not match a JPG image.');
        }
        $directory = BASE_PATH . '/storage/uploads/private/' . self::STORAGE_FOLDER;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private certificate storage is unavailable.');
        }
        @chmod($directory, 0700);
        $relative = self::STORAGE_FOLDER . '/' . bin2hex(random_bytes(24)) . '.jpg';
        if (!move_uploaded_file($tmp, BASE_PATH . '/storage/uploads/private/' . $relative)) {
            throw new RuntimeException('The certificate file could not be stored.');
        }
        @chmod(BASE_PATH . '/storage/uploads/private/' . $relative, 0600);
        return $relative;
    }

    private function contactAddress(): string
    {
        $configured = trim((string) (getenv('MAIL_CERTIFICATE_REQUEST_ADDRESS') ?: ''));
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
