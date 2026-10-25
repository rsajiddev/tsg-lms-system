<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\CourseDuration;
use PDO;
use RuntimeException;

final class CourseAdminController
{
    public function index(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courseImagesAvailable = $this->courseImageColumnAvailable($db);
        $imageColumn = $courseImagesAvailable ? 'c.image_path' : 'NULL AS image_path';
        $courses = $db->query("SELECT c.id,c.title,c.slug,c.visibility,c.category,c.short_description,c.description,c.duration_label,c.level_label,$imageColumn,COUNT(DISTINCT cm.id) AS module_count,COUNT(DISTINCT l.id) AS lesson_count,(SELECT price FROM course_pricing_options WHERE course_id=c.id AND option_code='recorded_only' LIMIT 1) AS recorded_price,(SELECT price FROM course_pricing_options WHERE course_id=c.id AND option_code='live_recorded' LIMIT 1) AS live_price FROM courses c LEFT JOIN course_modules cm ON cm.course_id=c.id LEFT JOIN lessons l ON l.module_id=cm.id GROUP BY c.id ORDER BY c.updated_at DESC,c.id DESC")->fetchAll();
        $lessons = $db->query('SELECT l.id,l.title,l.body,l.status,l.sort_order,cm.id AS module_id,cm.title AS module_title,c.id AS course_id,c.title AS course_title FROM lessons l JOIN course_modules cm ON cm.id=l.module_id JOIN courses c ON c.id=cm.course_id ORDER BY c.title,cm.sort_order,cm.id,l.sort_order,l.id')->fetchAll();
        $students = $db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL ORDER BY full_name")->fetchAll();
        View::render('admin/courses', ['title' => 'Course management', 'user' => $admin, 'courses' => $courses, 'lessons' => $lessons, 'students' => $students, 'courseImagesAvailable' => $courseImagesAvailable, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function save(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $title = trim((string)($_POST['title'] ?? ''));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $shortDescription = trim((string)($_POST['short_description'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $duration = trim((string)($_POST['duration_label'] ?? ''));
        $level = trim((string)($_POST['level_label'] ?? ''));
        $recordedPrice = (string)($_POST['recorded_price'] ?? '');
        $livePrice = (string)($_POST['live_price'] ?? '');
        $visibility = (string)($_POST['visibility'] ?? 'draft');
        $id = (int)($_POST['id'] ?? 0);
        if (
            mb_strlen($title) < 3 || mb_strlen($title) > 200 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || !in_array($visibility, ['draft', 'published', 'hidden', 'archived'], true)
            || !is_numeric($recordedPrice) || !is_numeric($livePrice) || (float)$recordedPrice < 0 || (float)$livePrice < 0
            || mb_strlen($shortDescription) > 500 || mb_strlen($duration) > 100 || mb_strlen($level) > 50
        ) {
            throw new RuntimeException('Enter valid course details, duration, and both non-negative prices.');
        }
        CourseDuration::expiry($duration);
        $db = Database::connection();
        $courseImagesAvailable = $this->courseImageColumnAvailable($db);
        $imageUpload = $_FILES['image'] ?? null;
        $hasImageUpload = is_array($imageUpload) && ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$courseImagesAvailable && $hasImageUpload) {
            throw new RuntimeException('Course image upload requires the updated database/migrations/001_initial_schema.sql. Import it, then try again.');
        }
        $newImagePath = $this->storeCourseImage($imageUpload);
        $oldImagePath = null;
        try {
            $db->beginTransaction();
            if ($id > 0) {
                $existingImageSelect = $courseImagesAvailable ? 'image_path' : 'NULL AS image_path';
                $existingCourse = $db->prepare("SELECT $existingImageSelect FROM courses WHERE id=? FOR UPDATE");
                $existingCourse->execute([$id]);
                $previousImage = $existingCourse->fetch();
                if (!$previousImage) throw new RuntimeException('Course not found. Refresh and try again.');
                $oldImagePath = is_string($previousImage['image_path'] ?? null) ? $previousImage['image_path'] : null;
                if ($newImagePath !== null) {
                    $stmt = $db->prepare('UPDATE courses SET title=?,slug=?,category=?,short_description=?,description=?,duration_label=?,level_label=?,image_path=?,visibility=? WHERE id=?');
                    $stmt->execute([$title, $slug, $category ?: null, $shortDescription ?: null, $description ?: null, $duration ?: null, $level ?: null, $newImagePath, $visibility, $id]);
                } else {
                    $stmt = $db->prepare('UPDATE courses SET title=?,slug=?,category=?,short_description=?,description=?,duration_label=?,level_label=?,visibility=? WHERE id=?');
                    $stmt->execute([$title, $slug, $category ?: null, $shortDescription ?: null, $description ?: null, $duration ?: null, $level ?: null, $visibility, $id]);
                }
                $courseId = $id;
            } else {
                if ($courseImagesAvailable) {
                    $stmt = $db->prepare('INSERT INTO courses (title,slug,category,short_description,description,duration_label,level_label,image_path,price,currency,visibility,created_by) VALUES (?,?,?,?,?,?,?,?,?,\'PKR\',?,?)');
                    $stmt->execute([$title, $slug, $category ?: null, $shortDescription ?: null, $description ?: null, $duration ?: null, $level ?: null, $newImagePath, $recordedPrice, $visibility, (int)$admin['id']]);
                } else {
                    $stmt = $db->prepare('INSERT INTO courses (title,slug,category,short_description,description,duration_label,level_label,price,currency,visibility,created_by) VALUES (?,?,?,?,?,?,?,?,\'PKR\',?,?)');
                    $stmt->execute([$title, $slug, $category ?: null, $shortDescription ?: null, $description ?: null, $duration ?: null, $level ?: null, $recordedPrice, $visibility, (int)$admin['id']]);
                }
                $courseId = (int)$db->lastInsertId();
            }
            $pricing = $db->prepare('INSERT INTO course_pricing_options(course_id,option_code,display_label,price,currency) VALUES(?,?,?,?,\'PKR\') ON DUPLICATE KEY UPDATE price=VALUES(price),display_label=VALUES(display_label)');
            $pricing->execute([$courseId, 'recorded_only', 'Recorded Only', $recordedPrice]);
            $pricing->execute([$courseId, 'live_recorded', 'Live + Recorded', $livePrice]);
            $this->audit($db, (int)$admin['id'], 'course.saved', 'course', $courseId);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $this->removeCourseImage($newImagePath);
            throw $exception;
        }
        if ($newImagePath !== null && $oldImagePath !== null) $this->removeCourseImage($oldImagePath);
        $_SESSION['_flash'] = 'Course saved.';
        $this->redirect('/admin/courses');
    }

    private function storeCourseImage(mixed $upload): ?string
    {
        if ($upload === null || !is_array($upload)) return null;
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) return null;
        if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('The course image could not be uploaded. Choose a JPG, PNG, or WebP image up to 600 KB.');
        }

        $temporaryPath = $upload['tmp_name'];
        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > 600 * 1024) {
            throw new RuntimeException('Course images must be no larger than 600 KB.');
        }

        $imageTypes = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_PNG => ['image/png', 'png']];
        if (defined('IMAGETYPE_WEBP')) $imageTypes[IMAGETYPE_WEBP] = ['image/webp', 'webp'];
        $imageInfo = @getimagesize($temporaryPath);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $imageType = is_array($imageInfo) ? (int) ($imageInfo[2] ?? 0) : 0;
        if (!isset($imageTypes[$imageType]) || $mime !== $imageTypes[$imageType][0]) {
            throw new RuntimeException('Use a valid JPG, PNG, or WebP image for the course artwork.');
        }
        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000 || $width * $height > 20000000) {
            throw new RuntimeException('The course image dimensions are too large. Choose an image up to 20 megapixels.');
        }

        $directory = BASE_PATH . '/public/assets/uploads/courses';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Course image storage is unavailable.');
        }
        $relativePath = 'assets/uploads/courses/' . bin2hex(random_bytes(16)) . '.' . $imageTypes[$imageType][1];
        if (!move_uploaded_file($temporaryPath, BASE_PATH . '/public/' . $relativePath)) {
            throw new RuntimeException('The course image could not be stored.');
        }
        return $relativePath;
    }

    private function courseImageColumnAvailable(PDO $db): bool
    {
        try {
            $db->query('SELECT image_path FROM courses LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1054) return false;
            throw $exception;
        }
    }

    private function removeCourseImage(?string $relativePath): void
    {
        if ($relativePath === null || !preg_match('~^assets/uploads/courses/[a-f0-9]{32}\.(?:jpg|png|webp)$~', $relativePath)) return;
        $directory = realpath(BASE_PATH . '/public/assets/uploads/courses');
        $file = realpath(BASE_PATH . '/public/' . $relativePath);
        if ($directory !== false && $file !== false && str_starts_with($file, $directory . DIRECTORY_SEPARATOR) && is_file($file)) {
            unlink($file);
        }
    }

    public function saveLesson(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $db = Database::connection();
        $courseId = (int)($_POST['course_id'] ?? 0);
        $lessonId = (int)($_POST['lesson_id'] ?? 0);
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $moduleTitle = trim((string)($_POST['module_title'] ?? ''));
        $lessonTitle = trim((string)($_POST['lesson_title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $status = (string)($_POST['status'] ?? 'draft');
        if ($courseId < 1 || mb_strlen($moduleTitle) < 2 || mb_strlen($lessonTitle) < 2 || mb_strlen($lessonTitle) > 200 || !in_array($status, ['draft', 'published'], true) || mb_strlen($body) > 500000) throw new RuntimeException('Enter a valid module and lesson title, content, and publishing status.');
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT id FROM courses WHERE id=?');
            $check->execute([$courseId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Course not found.');
            if ($lessonId > 0) {
                $moduleCheck = $db->prepare('SELECT id FROM course_modules WHERE id=? AND course_id=?');
                $moduleCheck->execute([$moduleId, $courseId]);
                if (!$moduleCheck->fetchColumn()) throw new RuntimeException('The selected lesson module does not belong to this course.');
                $lessonCheck = $db->prepare('SELECT id FROM lessons WHERE id=? AND module_id=?');
                $lessonCheck->execute([$lessonId, $moduleId]);
                if (!$lessonCheck->fetchColumn()) throw new RuntimeException('Lesson not found in the selected module.');
                $update = $db->prepare('UPDATE lessons SET title=?,body=?,status=? WHERE id=? AND module_id=?');
                $update->execute([$lessonTitle, $body ?: null, $status, $lessonId, $moduleId]);
                $action = 'lesson.updated';
            } else {
                $module = $db->prepare('SELECT id FROM course_modules WHERE course_id=? AND title=? LIMIT 1');
                $module->execute([$courseId, $moduleTitle]);
                $moduleId = (int)($module->fetchColumn() ?: 0);
                if (!$moduleId) {
                    $insert = $db->prepare('INSERT INTO course_modules(course_id,title,sort_order) VALUES(?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM course_modules existing WHERE existing.course_id=?))');
                    $insert->execute([$courseId, $moduleTitle, $courseId]);
                    $moduleId = (int)$db->lastInsertId();
                }
                $lesson = $db->prepare('INSERT INTO lessons(module_id,title,body,status,sort_order) VALUES(?,?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons existing WHERE existing.module_id=?))');
                $lesson->execute([$moduleId, $lessonTitle, $body ?: null, $status, $moduleId]);
                $lessonId = (int)$db->lastInsertId();
                $action = 'lesson.created';
            }
            $this->audit($db, (int)$admin['id'], $action, 'lesson', $lessonId, ['status' => $status]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $_SESSION['_flash'] = $status === 'published' ? 'Lesson published for students with active course access.' : 'Lesson saved as a draft.';
        $this->redirect('/admin/courses');
    }

    public function access(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $db = Database::connection();
        $studentId = (int)($_POST['student_id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        $action = (string)($_POST['access_action'] ?? 'grant');
        if ($studentId < 1 || $courseId < 1 || !in_array($action, ['grant', 'revoke'], true)) throw new RuntimeException('Choose a student, course, and valid access action.');
        $db->beginTransaction();
        try {
            if ($action === 'grant') {
                $durationQuery = $db->prepare('SELECT duration_label FROM courses WHERE id=?');
                $durationQuery->execute([$courseId]);
                $duration = $durationQuery->fetchColumn();
                if ($duration === false) throw new RuntimeException('Course not found.');
                $grantedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $grantedAtSql = $grantedAt->format('Y-m-d H:i:s');
                $expiresAt = CourseDuration::expiry((string)$duration, $grantedAt);
                $stmt = $db->prepare("INSERT INTO student_course_access(student_id,course_id,status,granted_by,granted_at,expires_at,revoked_by,revoked_at) SELECT ?,?,'active',?,?,?,NULL,NULL FROM users u JOIN courses c ON c.id=? WHERE u.id=? AND u.role='student' AND u.status='active' AND u.email_verified_at IS NOT NULL ON DUPLICATE KEY UPDATE status='active',granted_by=VALUES(granted_by),granted_at=VALUES(granted_at),expires_at=VALUES(expires_at),revoked_by=NULL,revoked_at=NULL");
                $stmt->execute([$studentId, $courseId, (int)$admin['id'], $grantedAtSql, $expiresAt, $courseId, $studentId]);
                if ($stmt->rowCount() === 0) throw new RuntimeException('Active verified student or course not found.');
            } else {
                $stmt = $db->prepare("UPDATE student_course_access SET status='revoked',revoked_by=?,revoked_at=UTC_TIMESTAMP() WHERE student_id=? AND course_id=? AND status='active'");
                $stmt->execute([(int)$admin['id'], $studentId, $courseId]);
                if ($stmt->rowCount() === 0) throw new RuntimeException('No active access grant exists for that student/course.');
            }
            $this->audit($db, (int)$admin['id'], 'course_access.' . $action, 'course', $courseId, ['student_id' => $studentId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $_SESSION['_flash'] = 'Course access updated.';
        $this->redirect('/admin/courses');
    }

    public function delete(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($courseId < 1) throw new RuntimeException('Choose a valid course to delete.');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $course = $db->prepare('SELECT id,title FROM courses WHERE id=? FOR UPDATE');
            $course->execute([$courseId]);
            $row = $course->fetch();
            if (!$row) {
                $db->rollBack();
                $_SESSION['_flash'] = 'Course not found. It may already have been deleted.';
                $this->redirect('/admin/courses');
            }
            $history = $db->prepare("SELECT (SELECT COUNT(*) FROM payments WHERE course_id=?) + (SELECT COUNT(*) FROM student_course_access WHERE course_id=?) + (SELECT COUNT(*) FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id WHERE q.course_id=?) + (SELECT COUNT(*) FROM assignment_submissions s JOIN assignments a ON a.id=s.assignment_id WHERE a.course_id=?) + (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id JOIN course_modules cm ON cm.id=l.module_id WHERE cm.course_id=?)");
            $history->execute([$courseId, $courseId, $courseId, $courseId, $courseId]);
            if ((int)$history->fetchColumn() > 0) {
                $db->rollBack();
                $_SESSION['_flash'] = 'This course has student or payment history. Archive it to preserve those records instead of deleting it.';
                $this->redirect('/admin/courses');
            }

            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'course.deleted','course',?,?)")
                ->execute([(int)$admin['id'], $courseId, json_encode(['title' => $row['title']], JSON_THROW_ON_ERROR)]);
            $db->prepare('DELETE qop FROM quiz_options qop JOIN quiz_questions qq ON qq.id=qop.question_id JOIN quizzes q ON q.id=qq.quiz_id WHERE q.course_id=?')->execute([$courseId]);
            $db->prepare('DELETE qa FROM quiz_answers qa JOIN quiz_questions qq ON qq.id=qa.question_id JOIN quizzes q ON q.id=qq.quiz_id WHERE q.course_id=?')->execute([$courseId]);
            $db->prepare('DELETE qq FROM quiz_questions qq JOIN quizzes q ON q.id=qq.quiz_id WHERE q.course_id=?')->execute([$courseId]);
            $db->prepare('DELETE FROM quizzes WHERE course_id=?')->execute([$courseId]);
            $db->prepare('DELETE FROM assignments WHERE course_id=?')->execute([$courseId]);
            $db->prepare('DELETE l FROM lessons l JOIN course_modules cm ON cm.id=l.module_id WHERE cm.course_id=?')->execute([$courseId]);
            $db->prepare('DELETE FROM course_modules WHERE course_id=?')->execute([$courseId]);
            $db->prepare('DELETE FROM course_pricing_options WHERE course_id=?')->execute([$courseId]);
            $db->prepare('DELETE FROM courses WHERE id=?')->execute([$courseId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $_SESSION['_flash'] = 'Course deleted. Courses with student or payment history must be archived to retain their records.';
        $this->redirect('/admin/courses');
    }

    private function csrf(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
    }
    private function audit(PDO $db, int $actor, string $action, string $entity, int $id, array $metadata = []): void
    {
        $stmt = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
        $stmt->execute([$actor, $action, $entity, $id, $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null]);
    }
    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
