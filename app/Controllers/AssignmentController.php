<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PrivateStorage;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\AccountIdentity;
use App\Services\NotificationService;
use PDO;
use RuntimeException;

final class AssignmentController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courses = $db->query("SELECT id,title FROM lms_courses WHERE visibility IN ('published','draft','hidden') ORDER BY title")->fetchAll();
        $modules = $db->query('SELECT id,course_id,title,sort_order FROM lms_course_modules ORDER BY course_id,sort_order,id')->fetchAll();
        $assignments = $db->query('SELECT a.*,c.title AS course_title,m.title AS module_title,(SELECT COUNT(*) FROM lms_assignment_submissions s WHERE s.assignment_id=a.id) AS submission_count FROM lms_assignments a JOIN lms_courses c ON c.id=a.course_id LEFT JOIN lms_course_modules m ON m.id=a.module_id ORDER BY a.id DESC')->fetchAll();
        $submissions = $db->query("SELECT s.*,a.title AS assignment_title,a.max_marks,a.late_deduction_percent,a.assignment_type,c.title AS course_title,u.id AS student_id,u.full_name AS student_name,u.email AS student_email,u.account_reference AS student_reference FROM lms_assignment_submissions s JOIN lms_assignments a ON a.id=s.assignment_id JOIN lms_courses c ON c.id=a.course_id JOIN lms_users u ON u.id=s.student_id WHERE s.status IN ('submitted','resubmit_requested') ORDER BY s.submitted_at ASC,s.id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($submissions as &$submission) {
            if (trim((string) ($submission['student_reference'] ?? '')) === '') {
                $submission['student_reference'] = AccountIdentity::ensure($db, (int) $submission['student_id']);
            }
        }
        unset($submission);
        View::render('admin/assignments', ['title' => 'Assignment management', 'user' => $admin, 'courses' => $courses, 'modules' => $modules, 'assignments' => $assignments, 'submissions' => $submissions, 'message' => $_SESSION['_flash'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    public function save(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $id = (int)($_POST['id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $type = (string)($_POST['assignment_type'] ?? 'exercise');
        $topic = (string)($_POST['assignment_topic'] ?? 'general');
        $weekRaw = trim((string)($_POST['week_number'] ?? ''));
        $week = $weekRaw === '' ? null : (int)$weekRaw;
        $title = trim((string)($_POST['title'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $status = (string)($_POST['status'] ?? 'draft');
        $marks = (string)($_POST['max_marks'] ?? '100');
        $dueAt = $this->dateValue($_POST['due_at'] ?? '');
        $cutoffAt = $this->dateValue($_POST['admin_cutoff_at'] ?? '');
        $deduction = trim((string)($_POST['late_deduction_percent'] ?? '0'));
        if ($courseId < 1 || !in_array($type, ['exercise', 'mini_project'], true) || !in_array($topic, ['general', 'php_mysql'], true) || ($week !== null && ($week < 1 || $week > 52)) || mb_strlen($title) < 3 || mb_strlen($title) > 200 || mb_strlen($instructions) > 10000 || !is_numeric($marks) || (float)$marks <= 0 || (float)$marks > 100000 || !is_numeric($deduction) || (float)$deduction < 0 || (float)$deduction > 100 || ($cutoffAt !== null && $dueAt !== null && strtotime($cutoffAt . ' UTC') < strtotime($dueAt . ' UTC')) || !in_array($status, ['draft', 'published', 'closed'], true)) throw new RuntimeException('Enter valid assignment details. The Admin cutoff must be on or after the due date.');
        $db = Database::connection();
        $isUpdate = $id > 0;
        $course = $db->prepare('SELECT id, title FROM lms_courses WHERE id=?');
        $course->execute([$courseId]);
        $courseRow = $course->fetch();
        if (!$courseRow) throw new RuntimeException('Select an existing course.');
        if ($moduleId > 0) {
            $module = $db->prepare('SELECT id FROM lms_course_modules WHERE id=? AND course_id=?');
            $module->execute([$moduleId, $courseId]);
            if (!$module->fetchColumn()) throw new RuntimeException('Select a module from the chosen course.');
        } else {
            $moduleId = 0;
        }
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE lms_assignments SET course_id=?,assignment_type=?,module_id=?,assignment_topic=?,week_number=?,title=?,instructions=?,due_at=?,admin_cutoff_at=?,late_deduction_percent=?,max_marks=?,status=? WHERE id=?');
            $stmt->execute([$courseId, $type, $moduleId ?: null, $topic, $week, $title, $instructions ?: null, $dueAt, $cutoffAt, $deduction, $marks, $status, $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO lms_assignments(course_id,assignment_type,module_id,assignment_topic,week_number,title,instructions,due_at,admin_cutoff_at,late_deduction_percent,max_marks,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId, $type, $moduleId ?: null, $topic, $week, $title, $instructions ?: null, $dueAt, $cutoffAt, $deduction, $marks, $status, (int)$admin['id']]);
            $id = (int)$db->lastInsertId();
        }
        $this->audit($db, (int)$admin['id'], 'assignment.saved', $id);
        // Only a published assignment is news to the students; drafts and closed work stay quiet.
        if ($status === 'published') {
            $courseTitle = (string) ($courseRow['title'] ?? 'your course');
            $deadline = $dueAt !== null ? ' It is due on ' . gmdate('j F Y', strtotime($dueAt . ' UTC')) . '.' : '';
            $body = ($isUpdate
                ? 'The assignment “' . $title . '” in ' . $courseTitle . ' has been updated.'
                : 'A new assignment, “' . $title . '”, is available in ' . $courseTitle . '.') . $deadline . ' Available marks: ' . rtrim(rtrim(number_format((float)$marks, 2), '0'), '.') . '. Open it from your Assignments page to read the full brief.';
            (new NotificationService($db))->notifyCourse($courseId, NotificationService::ASSIGNMENT, ($isUpdate ? 'Assignment updated: ' : 'New assignment: ') . $title, $body, 'assignment', $id, '/learning/assignments?course_id=' . $courseId, (int)$admin['id']);
        }
        $_SESSION['_flash'] = 'Assignment saved.';
        $this->redirect('/admin/assignments#assignment-' . $id);
    }

    public function studentIndex(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $course = $db->prepare("SELECT c.id,c.title FROM lms_courses c JOIN lms_student_course_access a ON a.course_id=c.id WHERE c.id=? AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) LIMIT 1");
        $course->execute([$courseId, (int)$student['id']]);
        $courseRow = $course->fetch();
        if (!$courseRow) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Course unavailable', 'user' => $student]);
            return;
        }
        $stmt = $db->prepare("SELECT a.id,a.assignment_type,a.assignment_topic,a.module_id,m.title AS module_title,a.week_number,a.title,a.instructions,a.due_at,a.admin_cutoff_at,a.late_deduction_percent,a.max_marks,a.status,s.id AS submission_id,s.submission_text,s.github_url,s.file_path,s.submitted_late,s.submitted_at,s.status AS submission_status,s.marks_awarded,s.feedback,s.rubric_technical,s.rubric_problem_solving,s.rubric_code_quality,s.rubric_timeliness,s.rubric_completeness FROM lms_assignments a LEFT JOIN lms_course_modules m ON m.id=a.module_id LEFT JOIN lms_assignment_submissions s ON s.id=(SELECT latest.id FROM lms_assignment_submissions latest WHERE latest.assignment_id=a.id AND latest.student_id=? ORDER BY latest.id DESC LIMIT 1) WHERE a.course_id=? AND a.status IN ('published','closed') ORDER BY a.week_number,a.id");
        $stmt->execute([(int)$student['id'], $courseId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (int)$row['id'];
            if (!isset($rows[$key])) $rows[$key] = $row;
        }
        View::render('learning/assignments', ['title' => 'Course assignments', 'user' => $student, 'course' => $courseRow, 'assignments' => array_values($rows), 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function submit(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $this->csrf();
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $text = trim((string)($_POST['submission_text'] ?? ''));
        $githubUrl = trim((string)($_POST['github_url'] ?? ''));
        if ($githubUrl !== '' && !$this->validGithubUrl($githubUrl)) throw new RuntimeException('Enter a valid public GitHub repository URL.');
        if (mb_strlen($text) > 50000) throw new RuntimeException('Submission text must be under 50,000 characters.');
        $db = Database::connection();
        $db->beginTransaction();
        $stored = null;
        try {
            $stmt = $db->prepare("SELECT a.course_id,a.status,a.assignment_topic,a.due_at,a.admin_cutoff_at FROM lms_assignments a JOIN lms_student_course_access x ON x.course_id=a.course_id AND x.student_id=? AND x.status IN ('active','completed','certified') AND (x.expires_at IS NULL OR x.expires_at>UTC_TIMESTAMP()) WHERE a.id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([(int)$student['id'], $assignmentId]);
            $assignment = $stmt->fetch();
            $now = time();
            $effectiveCutoff = $assignment ? ($assignment['admin_cutoff_at'] ?: $assignment['due_at']) : null;
            $pastCutoff = $effectiveCutoff !== null && strtotime($effectiveCutoff . ' UTC') < $now;
            if (!$assignment || $assignment['status'] !== 'published' || $pastCutoff) throw new RuntimeException('This assignment is unavailable or its final submission cutoff has passed.');
            $revisionCount = $db->prepare('SELECT COUNT(*) FROM lms_assignment_submissions WHERE assignment_id=? AND student_id=?');
            $revisionCount->execute([$assignmentId, (int)$student['id']]);
            if ((int)$revisionCount->fetchColumn() >= 5) throw new RuntimeException('The maximum of five submissions for this assignment has been reached. Contact your instructor if you need help.');
            $latest = $db->prepare('SELECT status FROM lms_assignment_submissions WHERE assignment_id=? AND student_id=? ORDER BY id DESC LIMIT 1');
            $latest->execute([$assignmentId, (int)$student['id']]);
            $priorStatus = $latest->fetchColumn();
            if ($priorStatus !== false && $priorStatus !== 'resubmit_requested') throw new RuntimeException('Your latest submission is already under review or has been graded.');
            $upload = $_FILES['submission_file'] ?? null;
            if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $stored = $this->storeFile($upload);
            if (($assignment['assignment_topic'] === 'php_mysql' && $githubUrl === '') || ($text === '' && $githubUrl === '' && $stored === null)) throw new RuntimeException($assignment['assignment_topic'] === 'php_mysql' ? 'Add your PHP/MySQL practice repository as a GitHub link before submitting.' : 'Add a GitHub repository link, write a response, or attach a file before submitting.');
            $late = $assignment['due_at'] !== null && strtotime($assignment['due_at'] . ' UTC') < $now;
            $insert = $db->prepare("INSERT INTO lms_assignment_submissions(assignment_id,student_id,submission_text,github_url,file_path,submitted_late,status) VALUES(?,?,?,?,?,?, 'submitted')");
            $insert->execute([$assignmentId, (int)$student['id'], $text ?: null, $githubUrl ?: null, $stored['relative'] ?? null, $late ? 1 : 0]);
            $this->audit($db, (int)$student['id'], 'assignment.submitted', (int)$db->lastInsertId());
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($stored) @unlink($stored['absolute']);
            throw $e;
        }
        $_SESSION['_flash'] = 'Assignment submitted for review.';
        $this->redirect('/learning/assignments?course_id=' . (int)$assignment['course_id']);
    }

    public function review(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $id = (int)($_POST['submission_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? 'reviewed');
        $feedback = trim((string)($_POST['feedback'] ?? ''));
        $rubricNames = ['technical' => 'rubric_technical', 'problem_solving' => 'rubric_problem_solving', 'code_quality' => 'rubric_code_quality', 'timeliness' => 'rubric_timeliness', 'completeness' => 'rubric_completeness'];
        if ($id < 1 || !in_array($decision, ['reviewed', 'resubmit_requested'], true) || mb_strlen($feedback) > 5000) throw new RuntimeException('Enter valid review feedback.');
        $rubric = [];
        foreach ($rubricNames as $key => $column) {
            $value = trim((string)($_POST[$column] ?? ''));
            if ($decision === 'reviewed' && (!is_numeric($value) || (float)$value < 0 || (float)$value > 5)) throw new RuntimeException('Score each rubric criterion from zero to five before saving a review.');
            $rubric[$column] = $value === '' ? null : (float)$value;
        }
        $db = Database::connection();
        $find = $db->prepare('SELECT a.max_marks,a.late_deduction_percent,a.course_id,a.title AS assignment_title,s.submitted_late,s.student_id FROM lms_assignment_submissions s JOIN lms_assignments a ON a.id=s.assignment_id WHERE s.id=?');
        $find->execute([$id]);
        $assignment = $find->fetch();
        if (!$assignment) throw new RuntimeException('Submission was not found.');
        $marks = null;
        if ($decision === 'reviewed') {
            $raw = array_sum(array_map(static fn($value): float => (float)$value, $rubric));
            $marks = round(((float)$assignment['max_marks'] * $raw / 25) * ((int)$assignment['submitted_late'] === 1 ? 1 - (float)$assignment['late_deduction_percent'] / 100 : 1), 2);
        }
        $update = $db->prepare('UPDATE lms_assignment_submissions SET status=?,marks_awarded=?,feedback=?,rubric_technical=?,rubric_problem_solving=?,rubric_code_quality=?,rubric_timeliness=?,rubric_completeness=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?');
        $update->execute([$decision, $marks, $feedback ?: null, ...array_values($rubric), (int)$admin['id'], $id]);
        $this->audit($db, (int)$admin['id'], 'assignment.' . $decision, $id);
        // A reviewed rubric changes the course grade matrix; keep it current without risking the review.
        if ($decision === 'reviewed') {
            try {
                (new \App\Services\CourseGrading($db))->recompute((int)$assignment['student_id'], (int)$assignment['course_id']);
            } catch (\Throwable $exception) {
                error_log('[COURSE GRADING] Assignment review hook skipped: ' . $exception->getMessage());
            }
        }
        $_SESSION['_flash'] = $decision === 'reviewed' ? 'Submission reviewed and marks saved.' : 'Resubmission requested with feedback.';
        // The student must see the outcome of a review without opening the workspace to look for it.
        $assignmentTitle = (string) ($assignment['assignment_title'] ?? 'your assignment');
        (new NotificationService($db))->notifyStudents(
            [(int) $assignment['student_id']],
            NotificationService::GRADE,
            $decision === 'reviewed' ? 'Assignment graded: ' . $assignmentTitle : 'Feedback on ' . $assignmentTitle,
            $decision === 'reviewed'
                ? 'Your submission for “' . $assignmentTitle . '” has been reviewed. Marks awarded: ' . rtrim(rtrim(number_format((float) $marks, 2), '0'), '.') . ' of ' . rtrim(rtrim(number_format((float) $assignment['max_marks'], 2), '0'), '.') . '. Read the feedback and rubric scores in your Assignments page.'
                : 'Your instructor asked for a resubmission of “' . $assignmentTitle . '”. Read the feedback and upload your updated work from your Assignments page.',
            'assignment_submission',
            $id,
            '/learning/assignments?course_id=' . (int) $assignment['course_id'],
            (int) $admin['id']
        );
        $this->redirect('/admin/assignments#submission-' . $id);
    }

    /**
     * Testing tool: remove every assignment, and the submissions hanging off it, in one action.
     *
     * The wrapper keeps a failed purge on the page instead of dropping the admin on a blank
     * error screen, so the reason (wrong phrase, missing tick, expired session) can be read and
     * corrected without losing the work that is still there.
     */
    public function purgeAll(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performPurgeAll($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect(PurgeGuard::returnPath('/admin/assignments'));
        }
    }

    private function performPurgeAll(array $admin): void
    {
        $this->csrf();
        PurgeGuard::assertConfirmed('DELETE ALL ASSIGNMENTS');
        $courseId = PurgeGuard::scopeCourseId();
        $db = Database::connection();
        $list = $db->prepare('SELECT id,title FROM lms_assignments' . ($courseId > 0 ? ' WHERE course_id=?' : '') . ' ORDER BY id');
        $list->execute($courseId > 0 ? [$courseId] : []);
        $assignmentIds = array_map('intval', array_column($list->fetchAll(), 'id'));
        if ($assignmentIds === []) {
            $_SESSION['_flash'] = 'There are no assignments left to delete.';
            $this->redirect(PurgeGuard::returnPath('/admin/assignments'));
        }
        $in = implode(',', array_fill(0, count($assignmentIds), '?'));
        $submitted = $db->prepare("SELECT id,file_path FROM lms_assignment_submissions WHERE assignment_id IN ($in)");
        $submitted->execute($assignmentIds);
        $submissions = $submitted->fetchAll();
        $files = PurgeGuard::removeStoredFiles(array_column($submissions, 'file_path'));
        $pairs = $db->prepare("SELECT DISTINCT s.student_id,a.course_id FROM lms_assignment_submissions s JOIN lms_assignments a ON a.id=s.assignment_id WHERE s.assignment_id IN ($in)");
        $pairs->execute($assignmentIds);
        $affected = $pairs->fetchAll();
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM lms_assignment_submissions WHERE assignment_id IN ($in)")->execute($assignmentIds);
            $db->prepare("DELETE FROM lms_assignments WHERE id IN ($in)")->execute($assignmentIds);
            $db->prepare('INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,\'assignment\',NULL,?)')
                ->execute([(int)$admin['id'], 'assignment.bulk_deleted', json_encode([
                    'course_id' => $courseId,
                    'assignments_deleted' => count($assignmentIds),
                    'submissions_deleted' => count($submissions),
                    'files_removed' => $files,
                ], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        // Announcements that pointed at the removed work would lead students to a dead page.
        (new NotificationService($db))->forgetSource('assignment', $assignmentIds);
        (new NotificationService($db))->forgetSource('assignment_submission', array_map('intval', array_column($submissions, 'id')));
        PurgeGuard::refreshGrades($affected);
        $_SESSION['_flash'] = sprintf(
            'Deleted %d assignment(s) and %d submission(s).%s Student grades that depended on this work were recalculated.',
            count($assignmentIds),
            count($submissions),
            $files > 0 ? ' ' . $files . ' uploaded file(s) were removed from storage.' : ''
        );
        $this->redirect(PurgeGuard::returnPath('/admin/assignments'));
    }

    public function file(): void
    {
        $session = $_SESSION['user'] ?? null;
        if (!is_array($session) || !in_array($session['role'] ?? '', ['admin', 'student'], true)) $this->redirect('/login');
        $user = AuthMiddleware::requireRole((string)$session['role']);
        $id = filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $sql = 'SELECT file_path FROM lms_assignment_submissions WHERE id=?';
        $params = [$id];
        if ($user['role'] === 'student') {
            $sql .= ' AND student_id=?';
            $params[] = (int)$user['id'];
        }
        $stmt = $db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $relative = $stmt->fetchColumn();
        if (!is_string($relative) || !preg_match('#^assignments/[a-f0-9]{48}\.(pdf|jpg|png|webp|zip|txt)$#', $relative)) {
            http_response_code(404);
            return;
        }
        $root = realpath(BASE_PATH . '/storage/uploads/private');
        $file = $root === false ? false : realpath($root . '/' . $relative);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            http_response_code(404);
            return;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($file));
        header('Content-Disposition: attachment; filename="assignment-submission-' . (int)$id . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    private function storeFile(array $upload): array
    {
        $tmp = (string)($upload['tmp_name'] ?? '');
        $actualSize = is_uploaded_file($tmp) ? filesize($tmp) : false;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $actualSize === false || $actualSize < 1 || $actualSize > self::MAX_UPLOAD_BYTES) throw new RuntimeException('Upload one assignment file up to 10 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
        $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/zip' => 'zip', 'text/plain' => 'txt', 'application/x-zip-compressed' => 'zip'];
        if (!is_string($mime) || !isset($types[$mime])) throw new RuntimeException('Allowed submission formats: PDF, JPG, PNG, WebP, ZIP or TXT.');
        $sample = (string)file_get_contents((string)$upload['tmp_name'], false, null, 0, 16);
        $signatureMatches = match ($mime) {
            'application/pdf' => str_starts_with($sample, '%PDF-'),
            'image/jpeg' => str_starts_with($sample, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($sample, "\x89PNG\r\n\x1A\n"),
            'image/webp' => substr($sample, 0, 4) === 'RIFF' && substr($sample, 8, 4) === 'WEBP',
            'application/zip', 'application/x-zip-compressed' => str_starts_with($sample, "PK\x03\x04") || str_starts_with($sample, "PK\x05\x06"),
            'text/plain' => mb_check_encoding((string)file_get_contents((string)$upload['tmp_name']), 'UTF-8') && !str_contains($sample, "\0"),
            default => false,
        };
        if (!$signatureMatches) throw new RuntimeException('The uploaded file content does not match an allowed document type.');
        $dir = PrivateStorage::directory('assignments');
        $name = bin2hex(random_bytes(24)) . '.' . $types[$mime];
        $path = $dir . '/' . $name;
        if (!move_uploaded_file((string)$upload['tmp_name'], $path)) throw new RuntimeException('The assignment file could not be stored. Check that the private upload folder still has free space, then submit again.');
        @chmod($path, 0600);
        return ['absolute' => $path, 'relative' => 'assignments/' . $name];
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new \DateTimeZone((require BASE_PATH . '/config/app.php')['timezone']));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Enter a valid due date.');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private function validGithubUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && strtolower((string)($parts['host'] ?? '')) === 'github.com'
            && !isset($parts['user'], $parts['pass'], $parts['port'])
            && preg_match('#^/[A-Za-z0-9-]+/[A-Za-z0-9._-]+/?$#', (string)($parts['path'] ?? '')) === 1
            && !isset($parts['query'], $parts['fragment']);
    }
    private function audit(PDO $db, int $actor, string $action, int $id): void
    {
        $db->prepare('INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,\'assignment_submission\',?)')->execute([$actor, $action, $id]);
    }
    private function csrf(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
    }
    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
