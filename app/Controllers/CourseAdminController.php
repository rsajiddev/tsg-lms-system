<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\CourseDuration;
use App\Services\LessonMedia;
use App\Services\NotificationService;
use PDO;
use RuntimeException;

final class CourseAdminController
{
    /**
     * Course catalogue and enrolment. Lesson editing lives on the lesson library page, which
     * already loads the same rows with filters, so this page no longer queries lessons at all.
     */
    public function index(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courseImagesAvailable = $this->courseImageColumnAvailable($db);
        $imageColumn = $courseImagesAvailable ? 'c.image_path' : 'NULL AS image_path';
        $courses = $db->query("SELECT c.id,c.title,c.slug,c.visibility,c.category,c.short_description,c.description,c.duration_label,c.level_label,$imageColumn,COUNT(DISTINCT cm.id) AS module_count,COUNT(DISTINCT l.id) AS lesson_count,(SELECT price FROM course_pricing_options WHERE course_id=c.id AND option_code='recorded_only' LIMIT 1) AS recorded_price,(SELECT price FROM course_pricing_options WHERE course_id=c.id AND option_code='live_recorded' LIMIT 1) AS live_price FROM courses c LEFT JOIN course_modules cm ON cm.course_id=c.id LEFT JOIN lessons l ON l.module_id=cm.id GROUP BY c.id ORDER BY c.updated_at DESC,c.id DESC")->fetchAll();
        $students = $db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL ORDER BY full_name")->fetchAll();
        View::render('admin/courses', ['title' => 'Course management', 'user' => $admin, 'courses' => $courses, 'students' => $students, 'courseImagesAvailable' => $courseImagesAvailable, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    /**
     * Standalone lesson catalogue.
     *
     * The course page still owns the lesson editors, but finding one lesson meant opening
     * every course accordion. This page lists every lesson across the academy with its
     * publish state, registered video length and how many enrolled students finished it.
     */
    public function lessons(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $media = new LessonMedia($db);
        $videosAvailable = $this->lessonVideoColumnsAvailable($db);
        $watchAvailable = $media->available();
        $search = substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $courseId = max(0, (int) ($_GET['course_id'] ?? 0));
        $status = (string) ($_GET['status'] ?? 'all');
        $videoFilter = (string) ($_GET['video'] ?? 'all');
        if (!in_array($status, ['all', 'published', 'draft'], true)) {
            $status = 'all';
        }
        if (!$videosAvailable || !in_array($videoFilter, ['all', 'with', 'without', 'no-length'], true)) {
            $videoFilter = 'all';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;

        $conditions = [];
        $params = [];
        if ($search !== '') {
            $conditions[] = '(l.title LIKE ? OR cm.title LIKE ? OR c.title LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle);
        }
        if ($courseId > 0) {
            $conditions[] = 'c.id = ?';
            $params[] = $courseId;
        }
        if ($status !== 'all') {
            $conditions[] = 'l.status = ?';
            $params[] = $status;
        }
        if ($videoFilter === 'with') {
            $conditions[] = "l.video_url IS NOT NULL AND l.video_url <> ''";
        } elseif ($videoFilter === 'without') {
            $conditions[] = "(l.video_url IS NULL OR l.video_url = '')";
        } elseif ($videoFilter === 'no-length') {
            $conditions[] = "l.video_url IS NOT NULL AND l.video_url <> '' AND l.video_duration_seconds IS NULL";
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $videoColumns = $videosAvailable
            ? 'l.video_url,l.video_provider,l.video_duration_seconds'
            : 'NULL AS video_url,\'none\' AS video_provider,NULL AS video_duration_seconds';
        $from = 'FROM lessons l JOIN course_modules cm ON cm.id = l.module_id JOIN courses c ON c.id = cm.course_id';
        $count = $db->prepare("SELECT COUNT(*) $from$where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $videoMetrics = $videosAvailable
            ? ",COALESCE(SUM(l.video_url IS NOT NULL AND l.video_url <> ''),0) AS with_video
              ,COALESCE(SUM(l.video_url IS NOT NULL AND l.video_url <> '' AND l.video_duration_seconds IS NULL),0) AS missing_length"
            : ',0 AS with_video,0 AS missing_length';
        $summary = $db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(l.status = 'published'),0) AS published$videoMetrics $from$where");
        $summary->execute($params);
        $metrics = $summary->fetch() ?: [];

        // Watch columns arrive with the same schema as the video columns, so an older import
        // still gets the lesson list - it simply reports no progress instead of failing.
        $watchColumn = $watchAvailable
            ? '(SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.watched_seconds > 0) AS watching_count'
            : '0 AS watching_count';
        $stmt = $db->prepare("SELECT l.id,l.title,l.body,l.status,l.sort_order,l.created_at,$videoColumns,cm.id AS module_id,cm.title AS module_title,
                    c.id AS course_id,c.title AS course_title,c.visibility AS course_visibility,
                    (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.completed_at IS NOT NULL) AS completed_count,
                    (SELECT COUNT(*) FROM student_course_access sca WHERE sca.course_id = c.id AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())) AS enrolled_count,
                    $watchColumn
              $from$where
              ORDER BY c.title,cm.sort_order,cm.id,l.sort_order,l.id
              LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        // Slug is included because the same list feeds the "Add lesson" target-course picker.
        $courses = $db->query('SELECT id, title, slug FROM courses ORDER BY title')->fetchAll();

        View::render('admin/lessons', [
            'title' => 'Lesson library',
            'user' => $admin,
            'lessons' => $stmt->fetchAll(),
            'courses' => $courses,
            'metrics' => $metrics,
            'videosAvailable' => $videosAvailable,
            'watchAvailable' => $watchAvailable,
            'watchPercent' => $media->watchPercent(),
            'watchMinPercent' => LessonMedia::MIN_PERCENT,
            'watchMaxPercent' => LessonMedia::MAX_PERCENT,
            'search' => $search,
            'courseId' => $courseId,
            'status' => $status,
            'videoFilter' => $videoFilter,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'purgeCount' => (int) $db->query('SELECT COUNT(*) FROM lessons')->fetchColumn(),
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
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
        // Students already enrolled must see a published course change, not just read it in the catalogue.
        if ($id > 0 && $visibility === 'published') {
            (new NotificationService($db))->notifyCourse($courseId, NotificationService::COURSE, 'Course updated: ' . $title, 'The course page for “' . $title . '” has been updated by your instructors. Check the outline, dates and pricing details in My courses.', 'course', $courseId, '/learning?course_id=' . $courseId, (int) $admin['id']);
        }
        $_SESSION['_flash'] = 'Course saved.';
        $this->redirect('/admin/courses');
    }

    private function storeCourseImage(mixed $upload): ?string
    {
        if ($upload === null || !is_array($upload)) return null;
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) return null;
        if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('The course image could not be uploaded. Choose a JPG, PNG, or WebP image up to 3 MB.');
        }

        $temporaryPath = $upload['tmp_name'];
        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > 3 * 1024 * 1024) {
            throw new RuntimeException('Course images must be no larger than 3 MB.');
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

    /** Same fail-open contract as the course image column: an old schema simply hides the fields. */
    private function lessonVideoColumnsAvailable(PDO $db): bool
    {
        try {
            $db->query('SELECT video_url,video_provider,video_duration_seconds FROM lessons LIMIT 0');
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
        $videoInput = trim((string)($_POST['video_url'] ?? ''));
        $durationInput = trim((string)($_POST['video_duration'] ?? ''));
        if ($courseId < 1 || mb_strlen($moduleTitle) < 2 || mb_strlen($lessonTitle) < 2 || mb_strlen($lessonTitle) > 200 || !in_array($status, ['draft', 'published'], true) || mb_strlen($body) > 500000) throw new RuntimeException('Enter a valid module and lesson title, content, and publishing status.');
        $videoColumns = $this->lessonVideoColumnsAvailable($db);
        $videoUrl = LessonMedia::normaliseUrl($videoInput);
        $videoDuration = LessonMedia::parseDuration($durationInput);
        if (mb_strlen($videoInput) > 600) throw new RuntimeException('The lesson video link must stay under 600 characters.');
        if ($durationInput !== '' && $videoDuration === null) throw new RuntimeException('Enter the video length as seconds, mm:ss, or h:mm:ss.');
        if (($videoUrl !== null || $videoDuration !== null) && !$videoColumns) {
            throw new RuntimeException('Lesson videos require the updated database/migrations/001_initial_schema.sql. Import it, then try again.');
        }
        if ($videoUrl === null) {
            $videoDuration = null;
        }
        $videoProvider = $videoUrl === null ? 'none' : LessonMedia::detectProvider($videoUrl);
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT id, title FROM courses WHERE id=?');
            $check->execute([$courseId]);
            $courseRow = $check->fetch();
            if (!$courseRow) throw new RuntimeException('Course not found.');
            if ($lessonId > 0) {
                $moduleCheck = $db->prepare('SELECT id FROM course_modules WHERE id=? AND course_id=?');
                $moduleCheck->execute([$moduleId, $courseId]);
                if (!$moduleCheck->fetchColumn()) throw new RuntimeException('The selected lesson module does not belong to this course.');
                $lessonCheck = $db->prepare('SELECT id FROM lessons WHERE id=? AND module_id=?');
                $lessonCheck->execute([$lessonId, $moduleId]);
                if (!$lessonCheck->fetchColumn()) throw new RuntimeException('Lesson not found in the selected module.');
                $assignments = 'title=?,body=?,status=?';
                $params = [$lessonTitle, $body ?: null, $status];
                if ($videoColumns) {
                    $assignments .= ',video_url=?,video_provider=?,video_duration_seconds=?';
                    $params[] = $videoUrl;
                    $params[] = $videoProvider;
                    $params[] = $videoDuration;
                }
                $params[] = $lessonId;
                $params[] = $moduleId;
                $update = $db->prepare("UPDATE lessons SET $assignments WHERE id=? AND module_id=?");
                $update->execute($params);
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
                $columns = 'module_id,title,body,status';
                $placeholders = '?,?,?,?';
                $params = [$moduleId, $lessonTitle, $body ?: null, $status];
                if ($videoColumns) {
                    $columns .= ',video_url,video_provider,video_duration_seconds';
                    $placeholders .= ',?,?,?';
                    $params[] = $videoUrl;
                    $params[] = $videoProvider;
                    $params[] = $videoDuration;
                }
                $columns .= ',sort_order';
                $placeholders .= ',(SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons existing WHERE existing.module_id=?)';
                $params[] = $moduleId;
                $lesson = $db->prepare("INSERT INTO lessons($columns) VALUES($placeholders)");
                $lesson->execute($params);
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
        // A published lesson is new course material, so the enrolled students are told about it
        // and their sidebar badge lights up; drafts stay invisible to them.
        if ($status === 'published') {
            $courseTitle = (string) ($courseRow['title'] ?? 'your course');
            $notificationBody = $action === 'lesson.created'
                ? 'A new lesson, “' . $lessonTitle . '”, is now available in ' . $courseTitle . '. Open it from your Lessons page to keep your progress moving.'
                : 'The lesson “' . $lessonTitle . '” in ' . $courseTitle . ' has been updated. Read the latest version before you mark it complete.';
            (new NotificationService($db))->notifyCourse($courseId, NotificationService::LESSON, ($action === 'lesson.created' ? 'New lesson: ' : 'Lesson updated: ') . $lessonTitle, $notificationBody, 'lesson', $lessonId, '/learning/lesson?lesson_id=' . $lessonId, (int) $admin['id']);
        }
        // Lesson editing now lives only on the lesson library, so the admin always returns there.
        $this->redirect('/admin/lessons');
    }

    /**
     * Stores the share of a lesson video a student must watch before the lesson can be completed.
     * The rule is kept next to the library it governs, and validated server-side. A rejected value
     * comes back to the library as a readable error instead of a blank failure page, because the
     * control is a single number field an admin can simply correct.
     */
    public function saveWatchRule(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performSaveWatchRule($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/lessons');
        }
    }

    private function performSaveWatchRule(array $admin): void
    {
        $this->csrf();
        $raw = trim((string) ($_POST[LessonMedia::WATCH_KEY] ?? ''));
        if (!ctype_digit($raw) || (int) $raw < LessonMedia::MIN_PERCENT || (int) $raw > LessonMedia::MAX_PERCENT) {
            throw new RuntimeException('The lesson watch requirement must be between ' . LessonMedia::MIN_PERCENT . ' and ' . LessonMedia::MAX_PERCENT . '%.');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')
                ->execute([LessonMedia::WATCH_KEY, $raw, (int) $admin['id']]);
            $this->audit($db, (int) $admin['id'], 'lesson.watch_rule_updated', 'app_settings', 0, ['percent' => (int) $raw]);
            $db->commit();
            $_SESSION['_flash'] = 'Watch-time rule saved. Lessons now need ' . (int) $raw . '% of the video.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $this->redirect('/admin/lessons');
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

    /**
     * Testing tool: clear the lesson library, or one course worth of it, in a single action.
     *
     * Learner progress is filed under each lesson, so it is removed first and the stored grade
     * rows are recalculated afterwards; a lesson that vanished silently would otherwise leave a
     * student looking at a percentage they can no longer earn back.
     */
    public function purgeLessons(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performPurgeLessons($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/lessons');
        }
    }

    private function performPurgeLessons(array $admin): void
    {
        $this->csrf();
        PurgeGuard::assertConfirmed('DELETE ALL LESSONS');
        $courseId = PurgeGuard::scopeCourseId();
        $db = Database::connection();
        $list = $db->prepare('SELECT l.id FROM lessons l JOIN course_modules cm ON cm.id=l.module_id' . ($courseId > 0 ? ' WHERE cm.course_id=?' : '') . ' ORDER BY l.id');
        $list->execute($courseId > 0 ? [$courseId] : []);
        $lessonIds = array_map('intval', $list->fetchAll(PDO::FETCH_COLUMN));
        if ($lessonIds === []) {
            $_SESSION['_flash'] = 'There are no lessons left to delete.';
            $this->redirect('/admin/lessons');
        }
        $in = implode(',', array_fill(0, count($lessonIds), '?'));
        $progress = $db->prepare("SELECT lp.student_id, cm.course_id, COUNT(*) AS c FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id JOIN course_modules cm ON cm.id=l.module_id WHERE lp.lesson_id IN ($in) GROUP BY lp.student_id, cm.course_id");
        $progress->execute($lessonIds);
        $affected = array_map(static fn(array $row): array => ['student_id' => (int) $row['student_id'], 'course_id' => (int) $row['course_id']], $progress->fetchAll());
        $progressRows = 0;
        $db->beginTransaction();
        try {
            $drop = $db->prepare("DELETE lp FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE lp.lesson_id IN ($in)");
            $drop->execute($lessonIds);
            $progressRows = $drop->rowCount();
            // A removed lesson takes its viewing reports with it. The foreign key would cascade them
            // away on its own; deleting them explicitly is what puts a number in the audit row, and it
            // is skipped on a database that has not been re-imported with the table yet.
            $viewRows = 0;
            try {
                $events = $db->prepare("DELETE FROM lesson_view_events WHERE lesson_id IN ($in)");
                $events->execute($lessonIds);
                $viewRows = $events->rowCount();
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() !== '42S02') throw $exception;
            }
            $db->prepare("DELETE FROM lessons WHERE id IN ($in)")->execute($lessonIds);
            $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,NULL,?)')
                ->execute([(int) $admin['id'], 'lesson.bulk_deleted', 'lesson', json_encode([
                    'course_id' => $courseId,
                    'lessons_deleted' => count($lessonIds),
                    'progress_deleted' => $progressRows,
                    'view_events_deleted' => $viewRows,
                ], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        (new NotificationService($db))->forgetSource('lesson', $lessonIds);
        PurgeGuard::refreshGrades($affected);
        $_SESSION['_flash'] = sprintf(
            'Deleted %d lesson(s) and %d progress record(s). Module counts and student grades were recalculated.',
            count($lessonIds),
            $progressRows
        );
        $this->redirect('/admin/lessons');
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
