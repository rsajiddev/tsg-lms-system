<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\AccountIdentity;
use App\Services\NotificationService;
use PDO;
use RuntimeException;

final class QuizController
{
    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courses = $db->query("SELECT id,title FROM courses WHERE visibility IN ('published','draft','hidden') ORDER BY title")->fetchAll();
        $quizzes = $db->query("SELECT q.*,c.title AS course_title,(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS actual_question_count,(SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id) AS attempt_count FROM quizzes q JOIN courses c ON c.id=q.course_id ORDER BY q.updated_at DESC,q.id DESC")->fetchAll();
        $retakeRequests = $db->query("SELECT ra.id,ra.quiz_id,ra.student_id,ra.student_note,ra.student_requested_at,q.title AS quiz_title,q.course_id,c.title AS course_title,u.full_name AS student_name,u.email AS student_email,u.account_reference AS student_reference FROM quiz_retake_approvals ra JOIN quizzes q ON q.id=ra.quiz_id JOIN courses c ON c.id=q.course_id JOIN users u ON u.id=ra.student_id WHERE ra.request_status='pending' AND ra.used_at IS NULL ORDER BY ra.student_requested_at,ra.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($retakeRequests as &$request) {
            if (trim((string) ($request['student_reference'] ?? '')) === '') {
                $request['student_reference'] = AccountIdentity::ensure($db, (int) $request['student_id']);
            }
        }
        unset($request);
        $questions = $db->query('SELECT qq.id,qq.quiz_id,qq.question_text,qq.explanation,qq.marks,qq.sort_order,q.title AS quiz_title,q.quiz_type FROM quiz_questions qq JOIN quizzes q ON q.id=qq.quiz_id ORDER BY q.id,qq.sort_order,qq.id')->fetchAll();
        $optionStmt = $db->prepare('SELECT option_text,is_correct FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');
        foreach ($questions as &$question) {
            $optionStmt->execute([(int)$question['id']]);
            $question['options'] = $optionStmt->fetchAll();
        }
        unset($question);
        View::render('admin/quizzes', ['title' => 'Quiz management', 'user' => $admin, 'courses' => $courses, 'quizzes' => $quizzes, 'questions' => $questions, 'retakeRequests' => $retakeRequests, 'message' => $_SESSION['_flash'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    public function adminReports(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $search = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
        $type = (string)($_GET['type'] ?? 'all');
        $result = (string)($_GET['result'] ?? 'all');
        $allowedTypes = ['practice', 'lesson', 'module', 'final'];
        $allowedResults = ['pass', 'fail'];
        if (!in_array($type, $allowedTypes, true)) $type = 'all';
        if (!in_array($result, $allowedResults, true)) $result = 'all';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;

        $conditions = ["qa.status <> 'in_progress'"];
        $params = [];
        if ($search !== '') {
            $conditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR q.title LIKE ? OR c.title LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle, $needle);
        }
        if ($type !== 'all') {
            $conditions[] = 'q.quiz_type = ?';
            $params[] = $type;
        }
        if ($result !== 'all') {
            $conditions[] = 'qa.result = ?';
            $params[] = $result;
        }
        $where = implode(' AND ', $conditions);

        $count = $db->prepare("SELECT COUNT(*) FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id WHERE $where");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $summaryStmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(qa.result='pass'),0) AS passed, COALESCE(SUM(qa.result='fail'),0) AS failed FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id WHERE $where");
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch() ?: [];
        $stmt = $db->prepare("SELECT qa.id,qa.started_at,qa.submitted_at,qa.status,qa.score,qa.total_marks,qa.percentage,qa.correct_count,qa.result,qa.submission_reason,
                                     TIMESTAMPDIFF(SECOND,qa.started_at,qa.submitted_at) AS duration_seconds,
                                     u.id AS student_id,u.full_name AS student_name,u.email AS student_email,u.account_reference AS student_reference,
                                     q.title AS quiz_title,q.week_number,q.quiz_type,q.pass_mark_percent,
                                     COALESCE(NULLIF((SELECT COUNT(*) FROM quiz_attempt_questions qat WHERE qat.attempt_id=qa.id),0),q.question_count) AS question_count,
                                     c.title AS course_title
                              FROM quiz_attempts qa
                              JOIN users u ON u.id=qa.student_id
                              JOIN quizzes q ON q.id=qa.quiz_id
                              JOIN courses c ON c.id=q.course_id
                              WHERE $where
                              ORDER BY COALESCE(qa.submitted_at,qa.started_at) DESC,qa.id DESC
                              LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);

        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attempts as &$attempt) {
            if (trim((string) ($attempt['student_reference'] ?? '')) === '') {
                $attempt['student_reference'] = AccountIdentity::ensure($db, (int) $attempt['student_id']);
            }
        }
        unset($attempt);

        View::render('admin/quiz-reports', [
            'title' => 'Quiz reports', 'user' => $admin, 'attempts' => $attempts,
            'search' => $search, 'type' => $type, 'resultFilter' => $result,
            'total' => $total, 'page' => $page, 'pages' => $pages,
            'summary' => $summary,
        ]);
    }

    public function saveQuiz(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $id = (int)($_POST['id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        $week = (int)($_POST['week_number'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $quizType = (string)($_POST['quiz_type'] ?? 'lesson');
        $practiceTopic = (string)($_POST['practice_topic'] ?? 'general');
        $status = (string)($_POST['status'] ?? 'draft');
        $minutes = (int)($_POST['time_limit_minutes'] ?? 20);
        $questionCount = (int)($_POST['question_count'] ?? 75);
        $passMark = (string)($_POST['pass_mark_percent'] ?? '50');
        $attemptLimit = (int)($_POST['attempt_limit'] ?? 1);
        $unlock = $this->dateValue($_POST['unlock_at'] ?? '');
        $close = $this->dateValue($_POST['close_at'] ?? '');
        if (!in_array($quizType, ['practice', 'lesson', 'module', 'final'], true) || !in_array($practiceTopic, ['general', 'php_mysql'], true)) throw new RuntimeException('Choose a valid assessment type and practice category.');
        if ($quizType !== 'practice') $practiceTopic = 'general';
        if ($quizType === 'lesson') {
            if ($questionCount < 5 || $questionCount > 10) throw new RuntimeException('Lesson quizzes must contain 5–10 questions.');
            $minutes = 0;
            $passMark = '60';
            $attemptLimit = 3;
        } elseif ($quizType === 'module') {
            if ($questionCount < 15 || $questionCount > 20) throw new RuntimeException('Module tests must contain 15–20 questions.');
            $attemptLimit = max(1, min(10, $attemptLimit));
        } elseif ($quizType === 'final') {
            $questionCount = 30;
            $passMark = '60';
            $attemptLimit = 1;
        } else {
            $minutes = 0;
            $attemptLimit = 255;
        }
        if (
            $courseId < 1 || $week < 1 || $week > 52 || mb_strlen($title) < 3 || mb_strlen($title) > 200 || mb_strlen($instructions) > 10000
            || !in_array($status, ['draft', 'scheduled', 'released', 'closed'], true)
            || (in_array($quizType, ['module', 'final'], true) && ($minutes < 1 || $minutes > 600))
            || ($id === 0 && $status !== 'draft') || $questionCount < 1 || $questionCount > 75 || !is_numeric($passMark) || (float)$passMark < 0 || (float)$passMark > 100
            || $attemptLimit < 1 || ($quizType !== 'practice' && $attemptLimit > 10) || ($status === 'scheduled' && $unlock === null) || ($unlock !== null && $close !== null && $close <= $unlock)
        ) {
            throw new RuntimeException('Check the assessment settings. Lesson quizzes use 5–10 questions, module tests 15–20 timed questions, and final tests exactly 30 timed questions.');
        }
        $db = Database::connection();
        $course = $db->prepare('SELECT id, title FROM courses WHERE id=?');
        $course->execute([$courseId]);
        $courseRow = $course->fetch();
        if (!$courseRow) throw new RuntimeException('Select an existing course.');
        if ($id > 0) {
            $questionTotal = $db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?');
            $questionTotal->execute([$id]);
            if ((int)$questionTotal->fetchColumn() > $questionCount) throw new RuntimeException('Question count cannot be lower than the questions already saved.');
        }
        if (in_array($status, ['scheduled', 'released'], true)) $this->assertReadyForRelease($db, $id, $questionCount, $quizType);
        if ($id > 0) {
            $existing = $db->prepare('SELECT course_id,week_number FROM quizzes WHERE id=?');
            $existing->execute([$id]);
            $prior = $existing->fetch();
            if (!$prior) throw new RuntimeException('Quiz not found.');
            $stmt = $db->prepare('UPDATE quizzes SET course_id=?,week_number=?,title=?,instructions=?,quiz_type=?,practice_topic=?,status=?,time_limit_seconds=?,question_count=?,pass_mark_percent=?,attempt_limit=?,unlock_at=?,close_at=? WHERE id=?');
            $stmt->execute([$courseId, $week, $title, $instructions ?: null, $quizType, $practiceTopic, $status, $minutes * 60, $questionCount, $passMark, $attemptLimit, $unlock, $close, $id]);
            $quizId = $id;
        } else {
            $stmt = $db->prepare('INSERT INTO quizzes(course_id,week_number,title,instructions,quiz_type,practice_topic,status,time_limit_seconds,question_count,pass_mark_percent,attempt_limit,unlock_at,close_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId, $week, $title, $instructions ?: null, $quizType, $practiceTopic, $status, $minutes * 60, $questionCount, $passMark, $attemptLimit, $unlock, $close, (int)$admin['id']]);
            $quizId = (int)$db->lastInsertId();
        }
        $this->audit($db, (int)$admin['id'], 'quiz.saved', $quizId, ['course_id' => $courseId, 'week_number' => $week, 'status' => $status]);
        // A released or scheduled test is something the student has to act on, so it is announced
        // to the enrolled students; drafts stay private to the admin workspace.
        if (in_array($status, ['released', 'scheduled'], true)) {
            $courseTitle = (string) ($courseRow['title'] ?? 'your course');
            $labels = ['practice' => 'Practice test', 'lesson' => 'Lesson quiz', 'module' => 'Module test', 'final' => 'Final test'];
            $kind = $labels[$quizType] ?? 'Assessment';
            $timing = $status === 'scheduled' && $unlock !== null
                ? ' It opens on ' . gmdate('j F Y, g:i a', strtotime($unlock . ' UTC')) . ' UTC.' . ($close !== null ? ' It closes on ' . gmdate('j F Y, g:i a', strtotime($close . ' UTC')) . ' UTC.' : '')
                : ($close !== null ? ' It closes on ' . gmdate('j F Y, g:i a', strtotime($close . ' UTC')) . ' UTC.' : '');
            $body = 'A ' . strtolower($kind) . ', “' . $title . '”, is ' . ($status === 'released' ? 'now open' : 'scheduled to open') . ' in ' . $courseTitle . '. Questions: ' . $questionCount . '; pass mark: ' . rtrim(rtrim(number_format((float)$passMark, 2), '0'), '.') . '%.' . $timing . ' Find it under Quizzes & results in your workspace.';
            (new NotificationService($db))->notifyCourse($courseId, NotificationService::QUIZ, $kind . ' ' . ($status === 'released' ? 'open' : 'scheduled') . ': ' . $title, $body, 'quiz', $quizId, '/learning/quizzes?course_id=' . $courseId, (int)$admin['id']);
        }
        unset($_SESSION['_flash']);
        $this->redirect('/admin/quizzes#quiz-' . $quizId);
    }

    public function deleteQuiz(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $quizId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($quizId === false) throw new RuntimeException('Choose a valid quiz to delete.');

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $quizStmt = $db->prepare('SELECT id,title FROM quizzes WHERE id=? FOR UPDATE');
            $quizStmt->execute([$quizId]);
            $quiz = $quizStmt->fetch();
            if (!$quiz) throw new RuntimeException('Quiz not found. It may already have been deleted.');

            $attemptStmt = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=?');
            $attemptStmt->execute([$quizId]);
            $attemptCount = (int) $attemptStmt->fetchColumn();
            $retakeStmt = $db->prepare('SELECT id FROM quiz_retake_approvals WHERE quiz_id=?');
            $retakeStmt->execute([$quizId]);
            $retakeIds = array_map('intval', $retakeStmt->fetchAll(PDO::FETCH_COLUMN));

            // Keep an audit record while removing learner data tied to the quiz.
            $this->audit($db, (int) $admin['id'], 'quiz.deleted', (int) $quiz['id'], [
                'title' => (string) $quiz['title'],
                'attempt_count' => $attemptCount,
                'attempt_history_deleted' => true,
            ]);

            $deleteAnswers = $db->prepare('DELETE FROM quiz_answers
                WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id=?)
                   OR question_id IN (SELECT id FROM quiz_questions WHERE quiz_id=?)
                   OR selected_option_id IN (
                       SELECT qo.id FROM quiz_options qo
                       JOIN quiz_questions qq ON qq.id=qo.question_id
                       WHERE qq.quiz_id=?
                   )');
            $deleteAnswers->execute([$quizId, $quizId, $quizId]);

            // Attempts cascade their randomized question/option snapshots.
            $db->prepare('DELETE FROM quiz_attempts WHERE quiz_id=?')->execute([$quizId]);
            $db->prepare('DELETE qo FROM quiz_options qo JOIN quiz_questions qq ON qq.id=qo.question_id WHERE qq.quiz_id=?')->execute([$quizId]);
            $db->prepare('DELETE FROM quiz_questions WHERE quiz_id=?')->execute([$quizId]);
            $db->prepare('DELETE FROM quizzes WHERE id=?')->execute([$quizId]);
            $db->commit();
            // Remove the announcements that pointed at this quiz so no dead link is left behind.
            (new NotificationService($db))->forgetSource('quiz', [(int) $quizId]);
            (new NotificationService($db))->forgetSource('quiz_retake', $retakeIds);

            $_SESSION['_flash'] = sprintf(
                'Quiz deleted. %d linked student attempt record(s) and their results were also removed.',
                $attemptCount
            );
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        $this->redirect('/admin/quizzes');
    }

    /**
     * Testing tool: delete every quiz, its question bank and all learner attempts with it.
     *
     * The single-quiz delete already proved the order the foreign keys demand; this is the same
     * walk repeated over the whole set, so nothing is left half-removed and no attempt history
     * survives without its quiz.
     */
    public function purgeAll(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performPurgeAll($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect(PurgeGuard::returnPath('/admin/quizzes'));
        }
    }

    private function performPurgeAll(array $admin): void
    {
        $this->csrf();
        PurgeGuard::assertConfirmed('DELETE ALL QUIZZES');
        $courseId = PurgeGuard::scopeCourseId();
        $db = Database::connection();
        $list = $db->prepare('SELECT id,title FROM quizzes' . ($courseId > 0 ? ' WHERE course_id=?' : '') . ' ORDER BY id');
        $list->execute($courseId > 0 ? [$courseId] : []);
        $quizIds = array_map('intval', $list->fetchAll(PDO::FETCH_COLUMN));
        if ($quizIds === []) {
            $_SESSION['_flash'] = 'There are no quizzes left to delete.';
            $this->redirect(PurgeGuard::returnPath('/admin/quizzes'));
        }
        $in = implode(',', array_fill(0, count($quizIds), '?'));
        $attemptStmt = $db->prepare("SELECT qa.id, qa.student_id, q.course_id FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id WHERE qa.quiz_id IN ($in)");
        $attemptStmt->execute($quizIds);
        $attempts = $attemptStmt->fetchAll();
        $retakeStmt = $db->prepare("SELECT id FROM quiz_retake_approvals WHERE quiz_id IN ($in)");
        $retakeStmt->execute($quizIds);
        $retakeIds = array_map('intval', $retakeStmt->fetchAll(PDO::FETCH_COLUMN));
        $questionStmt = $db->prepare("SELECT COUNT(*) FROM quiz_questions WHERE quiz_id IN ($in)");
        $questionStmt->execute($quizIds);
        $questionCount = (int) $questionStmt->fetchColumn();
        $affected = [];
        foreach ($attempts as $attempt) {
            $affected[(int) $attempt['student_id'] . ':' . (int) $attempt['course_id']] = [
                'student_id' => (int) $attempt['student_id'],
                'course_id' => (int) $attempt['course_id'],
            ];
        }
        $db->beginTransaction();
        try {
            // Attempts hold the only reference to the graded answers, and the answer rows point at
            // questions and options, so the history has to leave before the bank it describes.
            $answers = $db->prepare("DELETE FROM quiz_answers
                WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id IN ($in))
                   OR question_id IN (SELECT id FROM quiz_questions WHERE quiz_id IN ($in))
                   OR selected_option_id IN (
                       SELECT qo.id FROM quiz_options qo JOIN quiz_questions qq ON qq.id=qo.question_id WHERE qq.quiz_id IN ($in)
                   )");
            $answers->execute(array_merge($quizIds, $quizIds, $quizIds));
            // The randomised question and option snapshots follow their attempt automatically.
            $db->prepare("DELETE FROM quiz_attempts WHERE quiz_id IN ($in)")->execute($quizIds);
            $db->prepare("DELETE qo FROM quiz_options qo JOIN quiz_questions qq ON qq.id=qo.question_id WHERE qq.quiz_id IN ($in)")->execute($quizIds);
            $db->prepare("DELETE FROM quiz_retake_approvals WHERE quiz_id IN ($in)")->execute($quizIds);
            $db->prepare("DELETE FROM quiz_questions WHERE quiz_id IN ($in)")->execute($quizIds);
            $db->prepare("DELETE FROM quizzes WHERE id IN ($in)")->execute($quizIds);
            $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,NULL,?)')
                ->execute([(int) $admin['id'], 'quiz.bulk_deleted', 'quiz', json_encode([
                    'course_id' => $courseId,
                    'quizzes_deleted' => count($quizIds),
                    'questions_deleted' => $questionCount,
                    'attempts_deleted' => count($attempts),
                    'retake_requests_deleted' => count($retakeIds),
                ], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        // Retire the announcements that told students about this work.
        (new NotificationService($db))->forgetSource('quiz', $quizIds);
        (new NotificationService($db))->forgetSource('quiz_retake', $retakeIds);
        PurgeGuard::refreshGrades(array_values($affected));
        $_SESSION['_flash'] = sprintf(
            'Deleted %d quiz(s), %d question(s) and %d attempt record(s). Student grades that depended on these results were recalculated.',
            count($quizIds),
            $questionCount,
            count($attempts)
        );
        $this->redirect(PurgeGuard::returnPath('/admin/quizzes'));
    }

    public function saveQuestion(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $quizId = (int)($_POST['quiz_id'] ?? 0);
        $questionId = (int)($_POST['question_id'] ?? 0);
        $text = trim((string)($_POST['question_text'] ?? ''));
        $explanation = trim((string)($_POST['explanation'] ?? ''));
        $marks = (string)($_POST['marks'] ?? '1');
        $rawOptions = $_POST['options'] ?? [];
        $correct = (int)($_POST['correct_option'] ?? -1);
        if (
            $quizId < 1 || mb_strlen($text) < 3 || mb_strlen($text) > 10000 || mb_strlen($explanation) > 5000 || !is_numeric($marks) || (float)$marks <= 0 || (float)$marks > 1000
            || !is_array($rawOptions) || count($rawOptions) !== 4 || $correct < 0 || $correct > 3
        ) throw new RuntimeException('Each question needs text, positive marks, four options, and exactly one correct answer.');
        $options = [];
        foreach (array_values($rawOptions) as $option) {
            $option = trim((string)$option);
            if ($option === '' || mb_strlen($option) > 2000) throw new RuntimeException('All four answer options are required and must be under 2,000 characters.');
            $options[] = $option;
        }
        $db = Database::connection();
        $quiz = $db->prepare('SELECT id,status,question_count,quiz_type FROM quizzes WHERE id=?');
        $quiz->execute([$quizId]);
        $quizRow = $quiz->fetch();
        if (!$quizRow || $quizRow['status'] !== 'draft') throw new RuntimeException('Questions can only be changed while the quiz is in draft.');
        $isNewQuestion = $questionId === 0;
        if ($quizRow['quiz_type'] === 'practice' && $explanation === '') throw new RuntimeException('Practice questions need an explanation so students can learn from a wrong answer.');
        $attempts = $db->prepare('SELECT 1 FROM quiz_attempts WHERE quiz_id=? LIMIT 1');
        $attempts->execute([$quizId]);
        if ($attempts->fetchColumn()) throw new RuntimeException('Questions are locked after a student has started an attempt.');
        $db->beginTransaction();
        try {
            if ($questionId > 0) {
                $belongs = $db->prepare('SELECT id FROM quiz_questions WHERE id=? AND quiz_id=?');
                $belongs->execute([$questionId, $quizId]);
                if (!$belongs->fetchColumn()) throw new RuntimeException('Question does not belong to this quiz.');
                $update = $db->prepare('UPDATE quiz_questions SET question_text=?,explanation=?,marks=? WHERE id=?');
                $update->execute([$text, $explanation ?: null, $marks, $questionId]);
                $db->prepare('DELETE FROM quiz_options WHERE question_id=?')->execute([$questionId]);
            } else {
                $count = $db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?');
                $count->execute([$quizId]);
                if ((int)$count->fetchColumn() >= (int)$quizRow['question_count']) throw new RuntimeException('The configured question count has been reached. Edit the quiz settings before adding more questions.');
                $max = $db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id=?');
                $max->execute([$quizId]);
                $insert = $db->prepare('INSERT INTO quiz_questions(quiz_id,question_text,explanation,marks,sort_order) VALUES(?,?,?,?,?)');
                $insert->execute([$quizId, $text, $explanation ?: null, $marks, (int)$max->fetchColumn()]);
                $questionId = (int)$db->lastInsertId();
            }
            $optionInsert = $db->prepare('INSERT INTO quiz_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)');
            foreach ($options as $index => $option) $optionInsert->execute([$questionId, $option, $index === $correct ? 1 : 0, $index + 1]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $this->audit($db, (int)$admin['id'], 'quiz.question_saved', $questionId, ['quiz_id' => $quizId]);
        if ($isNewQuestion) {
            $count = $db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?');
            $count->execute([$quizId]);
            if ((int) $count->fetchColumn() === (int) $quizRow['question_count']) {
                $_SESSION['_flash'] = 'Assessment and all ' . (int) $quizRow['question_count'] . ' questions saved successfully.';
            } else {
                unset($_SESSION['_flash']);
            }
        } else {
            unset($_SESSION['_flash']);
        }
        $this->redirect('/admin/quizzes#quiz-' . $quizId);
    }

    public function courseQuizzes(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $course = $db->prepare("SELECT c.id,c.title FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.id=? AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) LIMIT 1");
        $course->execute([$courseId, (int)$student['id']]);
        $courseRow = $course->fetch();
        if (!$courseRow) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Course unavailable', 'user' => $student]);
            return;
        }
        $stmt = $db->prepare("SELECT q.id,q.week_number,q.title,q.instructions,q.quiz_type,q.practice_topic,q.time_limit_seconds,q.question_count,q.attempt_limit,q.unlock_at,q.close_at,(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS actual_question_count,(SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=?) AS used_attempts,(SELECT qa.id FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=? AND qa.status='in_progress' ORDER BY qa.id DESC LIMIT 1) AS active_attempt,ra.request_status AS retake_status,ra.used_at AS retake_used_at FROM quizzes q LEFT JOIN quiz_retake_approvals ra ON ra.quiz_id=q.id AND ra.student_id=? WHERE q.course_id=? AND ((q.status='released' OR (q.status='scheduled' AND q.unlock_at IS NOT NULL AND q.unlock_at<=UTC_TIMESTAMP())) OR (q.status IN ('released','closed') AND q.quiz_type='final' AND (SELECT COUNT(*) FROM quiz_attempts prior WHERE prior.quiz_id=q.id AND prior.student_id=? AND prior.status<>'in_progress')=1 AND ra.used_at IS NULL)) AND (q.unlock_at IS NULL OR q.unlock_at<=UTC_TIMESTAMP()) AND ((q.close_at IS NULL OR q.close_at>UTC_TIMESTAMP()) OR (q.status IN ('released','closed') AND q.quiz_type='final' AND ra.used_at IS NULL)) ORDER BY q.week_number,q.id");
        $stmt->execute([(int)$student['id'], (int)$student['id'], (int)$student['id'], $courseId, (int)$student['id']]);
        View::render('learning/quizzes', ['title' => 'Course quizzes', 'user' => $student, 'course' => $courseRow, 'quizzes' => $stmt->fetchAll(), 'message' => $_SESSION['_flash'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]);
        // The retake request lands here, so the confirmation has to be carried by this page and
        // consumed by it - otherwise it waits for some unrelated page to show it.
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    public function start(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $this->csrf();
        $quizId = (int)($_POST['quiz_id'] ?? 0);
        $db = Database::connection();
        $studentId = (int)$student['id'];
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT q.* FROM quizzes q JOIN student_course_access a ON a.course_id=q.course_id AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) WHERE q.id=? AND ((q.status='released' OR (q.status='scheduled' AND q.unlock_at IS NOT NULL AND q.unlock_at<=UTC_TIMESTAMP())) OR (q.status IN ('released','closed') AND q.quiz_type='final' AND EXISTS (SELECT 1 FROM quiz_retake_approvals allowed WHERE allowed.quiz_id=q.id AND allowed.student_id=? AND allowed.request_status='approved' AND allowed.used_at IS NULL))) AND (q.unlock_at IS NULL OR q.unlock_at<=UTC_TIMESTAMP()) AND ((q.close_at IS NULL OR q.close_at>UTC_TIMESTAMP()) OR (q.status IN ('released','closed') AND q.quiz_type='final' AND EXISTS (SELECT 1 FROM quiz_retake_approvals allowed WHERE allowed.quiz_id=q.id AND allowed.student_id=? AND allowed.request_status='approved' AND allowed.used_at IS NULL))) FOR UPDATE");
            $stmt->execute([$studentId, $quizId, $studentId, $studentId]);
            $quiz = $stmt->fetch();
            if (!$quiz) throw new RuntimeException('This quiz is unavailable or you do not have course access.');
            $active = $db->prepare("SELECT id,deadline_at FROM quiz_attempts WHERE quiz_id=? AND student_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $active->execute([$quizId, $studentId]);
            $existing = $active->fetch();
            if ($existing && ($existing['deadline_at'] === null || strtotime($existing['deadline_at'] . ' UTC') > time())) {
                $db->commit();
                $this->redirect('/learning/quiz/attempt?attempt_id=' . (int)$existing['id']);
            }
            if ($existing) $this->finishAttempt($db, (int)$existing['id'], [], 'deadline', true);
            $used = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND student_id=?');
            $used->execute([$quizId, $studentId]);
            $usedCount = (int)$used->fetchColumn();
            $retake = null;
            if ($quiz['quiz_type'] === 'final') {
                $retakeStmt = $db->prepare('SELECT id,request_status,used_at FROM quiz_retake_approvals WHERE quiz_id=? AND student_id=? FOR UPDATE');
                $retakeStmt->execute([$quizId, $studentId]);
                $retake = $retakeStmt->fetch() ?: null;
                if ($usedCount > 0 && !($usedCount === 1 && $retake && $retake['request_status'] === 'approved' && $retake['used_at'] === null)) {
                    throw new RuntimeException('The final test allows one graded attempt. Request an admin-approved retake if required.');
                }
            } elseif ($quiz['quiz_type'] === 'lesson' && $usedCount >= 3) {
                throw new RuntimeException('You have used all three graded attempts for this lesson quiz.');
            } elseif ($quiz['quiz_type'] === 'module' && $usedCount >= (int)$quiz['attempt_limit']) {
                throw new RuntimeException('You have used all attempts allowed for this module test.');
            }
            $questionCheck = $db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?');
            $questionCheck->execute([$quizId]);
            if ((int)$questionCheck->fetchColumn() !== (int)$quiz['question_count']) throw new RuntimeException('This quiz is not ready yet. Contact the Academy team.');
            $timed = in_array($quiz['quiz_type'], ['module', 'final'], true);
            $start = $db->prepare('INSERT INTO quiz_attempts(quiz_id,student_id,started_at,deadline_at) VALUES(?,?,UTC_TIMESTAMP(),CASE WHEN ?=1 THEN DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) ELSE NULL END)');
            $start->execute([$quizId, $studentId, $timed ? 1 : 0, (int)$quiz['time_limit_seconds']]);
            $attemptId = (int)$db->lastInsertId();
            $questionIdsStmt = $db->prepare('SELECT id FROM quiz_questions WHERE quiz_id=?');
            $questionIdsStmt->execute([$quizId]);
            $questionIds = array_map('intval', $questionIdsStmt->fetchAll(PDO::FETCH_COLUMN));
            shuffle($questionIds);
            $saveQuestionOrder = $db->prepare('INSERT INTO quiz_attempt_questions(attempt_id,question_id,sort_order) VALUES(?,?,?)');
            $saveOptionOrder = $db->prepare('INSERT INTO quiz_attempt_options(attempt_id,question_id,option_id,sort_order) VALUES(?,?,?,?)');
            $optionIdsStmt = $db->prepare('SELECT id FROM quiz_options WHERE question_id=?');
            foreach ($questionIds as $position => $questionId) {
                $saveQuestionOrder->execute([$attemptId, $questionId, $position + 1]);
                $optionIdsStmt->execute([$questionId]);
                $optionIds = array_map('intval', $optionIdsStmt->fetchAll(PDO::FETCH_COLUMN));
                shuffle($optionIds);
                foreach ($optionIds as $optionPosition => $optionId) $saveOptionOrder->execute([$attemptId, $questionId, $optionId, $optionPosition + 1]);
            }
            if ($quiz['quiz_type'] === 'final' && $usedCount === 1 && $retake) {
                $db->prepare("UPDATE quiz_retake_approvals SET used_at=UTC_TIMESTAMP() WHERE id=? AND request_status='approved' AND used_at IS NULL")->execute([(int)$retake['id']]);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $this->redirect('/learning/quiz/attempt?attempt_id=' . $attemptId);
    }

    public function attempt(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $attemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $attempt = $this->ownedAttempt($db, $attemptId, (int)$student['id'], false, true);
        if (!$attempt) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Quiz attempt unavailable', 'user' => $student]);
            return;
        }
        if ($attempt['status'] !== 'in_progress') $this->redirect('/learning/quiz/result?attempt_id=' . $attemptId);
        $this->ensureAttemptOrder($db, $attemptId);
        if ($attempt['deadline_at'] !== null && strtotime($attempt['deadline_at'] . ' UTC') <= time()) {
            $db->beginTransaction();
            try {
                $this->finishAttempt($db, $attemptId, [], 'deadline', true);
                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                throw $exception;
            }
            $this->redirect('/learning/quiz/result?attempt_id=' . $attemptId);
        }
        $questions = $db->prepare('SELECT q.id,q.question_text,q.marks,aq.sort_order FROM quiz_attempt_questions aq JOIN quiz_questions q ON q.id=aq.question_id WHERE aq.attempt_id=? ORDER BY aq.sort_order');
        $questions->execute([$attemptId]);
        $optionStmt = $db->prepare('SELECT o.id,o.option_text,ao.sort_order FROM quiz_attempt_options ao JOIN quiz_options o ON o.id=ao.option_id WHERE ao.attempt_id=? AND ao.question_id=? ORDER BY ao.sort_order');
        $items = $questions->fetchAll();
        foreach ($items as &$question) {
            $optionStmt->execute([$attemptId, (int)$question['id']]);
            $question['options'] = $optionStmt->fetchAll();
        }
        unset($question);
        $invalidQuestion = count($items) !== (int)$attempt['question_count'];
        foreach ($items as $item) if (count($item['options']) !== 4) $invalidQuestion = true;
        if ($invalidQuestion) throw new RuntimeException('Quiz content is incomplete. Contact the Academy team.');
        View::render('learning/quiz-attempt', ['title' => $attempt['title'], 'user' => $student, 'attempt' => $attempt, 'questions' => $items, 'deadlineEpoch' => $attempt['deadline_at'] ? (int)strtotime($attempt['deadline_at'] . ' UTC') : 0]);
    }

    public function submit(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $this->csrf();
        $attemptId = (int)($_POST['attempt_id'] ?? 0);
        $reason = (string)($_POST['submission_reason'] ?? 'manual');
        if (!in_array($reason, ['manual', 'tab_hidden', 'focus_lost', 'navigation', 'deadline'], true)) $reason = 'manual';
        $raw = $_POST['answers'] ?? [];
        $answers = is_array($raw) ? $raw : [];
        $db = Database::connection();
        $db->beginTransaction();
        $gradedCourseId = null;
        try {
            $attempt = $this->ownedAttempt($db, $attemptId, (int)$student['id'], true, true);
            if (!$attempt) throw new RuntimeException('Quiz attempt not found.');
            if ($attempt['status'] === 'in_progress') $this->finishAttempt($db, $attemptId, $answers, $reason, false);
            $gradedCourseId = (int)($attempt['course_id'] ?? 0);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        // Refresh the course grade matrix after a graded attempt; never let it break the result page.
        if ($gradedCourseId > 0) {
            try {
                (new \App\Services\CourseGrading($db))->recompute((int)$student['id'], $gradedCourseId);
            } catch (\Throwable $exception) {
                error_log('[COURSE GRADING] Quiz submit hook skipped: ' . $exception->getMessage());
            }
        }
        $this->redirect('/learning/quiz/result?attempt_id=' . $attemptId);
    }

    public function result(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $attemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $attempt = $this->ownedAttempt($db, $attemptId, (int)$student['id']);
        if (!$attempt) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Quiz result unavailable', 'user' => $student]);
            return;
        }
        if (trim((string) ($attempt['student_reference'] ?? '')) === '') {
            $attempt['student_reference'] = AccountIdentity::ensure($db, (int) $attempt['student_id']);
        }
        if ($attempt['status'] === 'in_progress') $this->redirect('/learning/quiz/attempt?attempt_id=' . $attemptId);
        $answers = $db->prepare('SELECT q.id,q.question_text,q.explanation,a.selected_option_id,a.awarded_marks,q.marks,(SELECT o.id FROM quiz_options o WHERE o.question_id=q.id AND o.is_correct=1 LIMIT 1) AS correct_option_id,(SELECT o.option_text FROM quiz_options o WHERE o.question_id=q.id AND o.is_correct=1 LIMIT 1) AS correct_option_text,selected.option_text AS selected_option_text FROM quiz_answers a JOIN quiz_questions q ON q.id=a.question_id LEFT JOIN quiz_options selected ON selected.id=a.selected_option_id WHERE a.attempt_id=? ORDER BY (SELECT aq.sort_order FROM quiz_attempt_questions aq WHERE aq.attempt_id=a.attempt_id AND aq.question_id=a.question_id)');
        $answers->execute([$attemptId]);
        View::render('learning/quiz-result', ['title' => 'Quiz result', 'user' => $student, 'attempt' => $attempt, 'answers' => $answers->fetchAll()]);
    }

    public function requestRetake(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $this->csrf();
        $quizId = (int)($_POST['quiz_id'] ?? 0);
        $note = trim((string)($_POST['student_note'] ?? ''));
        if ($quizId < 1 || mb_strlen($note) > 1000) throw new RuntimeException('Add a valid retake request note (up to 1,000 characters).');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $quiz = $db->prepare("SELECT q.id,q.quiz_type,q.course_id FROM quizzes q JOIN student_course_access a ON a.course_id=q.course_id AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) WHERE q.id=? AND q.quiz_type='final' AND q.status IN ('released','closed') FOR UPDATE");
            $quiz->execute([(int)$student['id'], $quizId]);
            $quizRow = $quiz->fetch();
            if (!$quizRow) throw new RuntimeException('This final test is unavailable.');
            $attemptCount = $db->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND student_id=? AND status<>'in_progress'");
            $attemptCount->execute([$quizId, (int)$student['id']]);
            $allAttempts = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND student_id=?');
            $allAttempts->execute([$quizId, (int)$student['id']]);
            if ((int)$attemptCount->fetchColumn() !== 1 || (int)$allAttempts->fetchColumn() !== 1) throw new RuntimeException('A retake request is available only after your first final-test attempt is complete.');
            $request = $db->prepare("INSERT INTO quiz_retake_approvals(quiz_id,student_id,request_status,student_requested_at,student_note) VALUES(?,?,'pending',UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE student_note=IF(used_at IS NULL AND request_status<>'approved',VALUES(student_note),student_note),student_requested_at=IF(used_at IS NULL AND request_status<>'approved',UTC_TIMESTAMP(),student_requested_at),request_status=IF(used_at IS NULL AND request_status<>'approved','pending',request_status)");
            $request->execute([$quizId, (int)$student['id'], $note ?: null]);
            $this->audit($db, (int)$student['id'], 'quiz.retake_requested', $quizId, ['student_id' => (int)$student['id']]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = 'Final-test retake request sent to the admin.';
        $this->redirect('/learning/quizzes?course_id=' . (int)$quizRow['course_id']);
    }

    public function retakeDecision(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if ($requestId < 1 || !in_array($decision, ['approved', 'rejected'], true) || mb_strlen($note) > 1000) throw new RuntimeException('Choose a valid retake decision and optional note.');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $find = $db->prepare("SELECT id,quiz_id,student_id,request_status,used_at FROM quiz_retake_approvals WHERE id=? FOR UPDATE");
            $find->execute([$requestId]);
            $request = $find->fetch();
            if (!$request || $request['request_status'] !== 'pending' || $request['used_at'] !== null) throw new RuntimeException('This retake request is no longer pending.');
            $update = $db->prepare('UPDATE quiz_retake_approvals SET request_status=?,approved_by=?,approved_at=UTC_TIMESTAMP(),admin_note=? WHERE id=? AND request_status=\'pending\' AND used_at IS NULL');
            $update->execute([$decision, (int)$admin['id'], $note ?: null, $requestId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('The retake request changed; refresh and try again.');
            $this->audit($db, (int)$admin['id'], 'quiz.retake_' . $decision, (int)$request['quiz_id'], ['student_id' => (int)$request['student_id']]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = 'Final-test retake request ' . $decision . '.';
        // The student asked for this and is waiting on the answer.
        $quizTitle = '';
        $titleStmt = $db->prepare('SELECT title FROM quizzes WHERE id=?');
        $titleStmt->execute([(int) $request['quiz_id']]);
        $quizTitle = (string) ($titleStmt->fetchColumn() ?: 'your assessment');
        (new NotificationService($db))->notifyStudents(
            [(int) $request['student_id']],
            NotificationService::QUIZ,
            $decision === 'approved' ? 'Retake approved: ' . $quizTitle : 'Retake request declined',
            $decision === 'approved'
                ? 'Your request to retake “' . $quizTitle . '” has been approved. Start the new attempt from Quizzes & results.'
                : 'Your request to retake “' . $quizTitle . '” was not approved.' . ($note !== '' ? ' Note from your instructor: ' . $note : ' Speak to your instructor if you believe this was a mistake.'),
            'quiz_retake',
            $requestId,
            '/learning/quizzes',
            (int) $admin['id']
        );
        $this->redirect('/admin/quizzes#retake-requests');
    }

    private function finishAttempt(PDO $db, int $attemptId, array $submitted, string $reason, bool $forceTimeout): void
    {
        $stmt = $db->prepare("SELECT qa.id,qa.quiz_id,qa.deadline_at,qa.status,q.pass_mark_percent,q.quiz_type FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id WHERE qa.id=? FOR UPDATE");
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch();
        if (!$attempt || $attempt['status'] !== 'in_progress') return;
        $timedOut = $forceTimeout || ($attempt['deadline_at'] !== null && strtotime($attempt['deadline_at'] . ' UTC') <= time());
        if ($timedOut) $reason = 'deadline';
        $questions = $db->prepare('SELECT q.id,q.marks FROM quiz_attempt_questions aq JOIN quiz_questions q ON q.id=aq.question_id WHERE aq.attempt_id=? ORDER BY aq.sort_order');
        $questions->execute([$attemptId]);
        $optionStmt = $db->prepare('SELECT id,is_correct FROM quiz_options WHERE question_id=?');
        $insert = $db->prepare('INSERT INTO quiz_answers(attempt_id,question_id,selected_option_id,awarded_marks) VALUES(?,?,?,?)');
        $score = 0.0;
        $total = 0.0;
        $correct = 0;
        foreach ($questions->fetchAll() as $question) {
            $qid = (int)$question['id'];
            $marks = (float)$question['marks'];
            $total += $marks;
            $optionStmt->execute([$qid]);
            $valid = [];
            $correctId = null;
            foreach ($optionStmt->fetchAll() as $option) {
                $valid[(int)$option['id']] = true;
                if ((int)$option['is_correct'] === 1) $correctId = (int)$option['id'];
            }
            $selected = filter_var($submitted[(string)$qid] ?? null, FILTER_VALIDATE_INT);
            if ($selected === false || $selected === null || !isset($valid[$selected])) $selected = null;
            $awarded = $selected !== null && $selected === $correctId ? $marks : 0.0;
            if ($awarded > 0) $correct++;
            $score += $awarded;
            $insert->execute([$attemptId, $qid, $selected, $awarded]);
        }
        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;
        $status = $timedOut ? 'timed_out' : ($reason === 'manual' ? 'submitted' : 'auto_submitted');
        $update = $db->prepare('UPDATE quiz_attempts SET submitted_at=UTC_TIMESTAMP(),status=?,score=?,total_marks=?,percentage=?,correct_count=?,result=?,submission_reason=? WHERE id=? AND status=\'in_progress\'');
        $update->execute([$status, $score, $total, $percentage, $correct, $percentage >= (float)$attempt['pass_mark_percent'] ? 'pass' : 'fail', $reason, $attemptId]);
    }

    private function ownedAttempt(PDO $db, int $attemptId, int $studentId, bool $lock = false, bool $requireCurrentAccess = false): ?array
    {
        $accessJoin = $requireCurrentAccess ? " JOIN student_course_access a ON a.student_id=qa.student_id AND a.course_id=q.course_id AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP())" : '';
        $stmt = $db->prepare('SELECT qa.*,q.title,q.week_number,q.course_id,q.quiz_type,q.time_limit_seconds,q.question_count,q.pass_mark_percent,c.title AS course_title,u.full_name AS student_name,u.account_reference AS student_reference FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id JOIN users u ON u.id=qa.student_id' . $accessJoin . ' WHERE qa.id=? AND qa.student_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$attemptId, $studentId]);
        return $stmt->fetch() ?: null;
    }

    /** Preserve access to attempts created before randomized order snapshots existed. */
    private function ensureAttemptOrder(PDO $db, int $attemptId): void
    {
        $db->beginTransaction();
        try {
            $attempt = $db->prepare("SELECT id,status FROM quiz_attempts WHERE id=? AND status='in_progress' FOR UPDATE");
            $attempt->execute([$attemptId]);
            if (!$attempt->fetch()) {
                $db->commit();
                return;
            }
            $existing = $db->prepare('SELECT COUNT(*) FROM quiz_attempt_questions WHERE attempt_id=?');
            $existing->execute([$attemptId]);
            if ((int)$existing->fetchColumn() === 0) {
                $questionIds = $db->prepare('SELECT id FROM quiz_questions WHERE quiz_id=(SELECT quiz_id FROM quiz_attempts WHERE id=?) ORDER BY sort_order,id');
                $questionIds->execute([$attemptId]);
                $saveQuestion = $db->prepare('INSERT INTO quiz_attempt_questions(attempt_id,question_id,sort_order) VALUES(?,?,?)');
                $saveOption = $db->prepare('INSERT INTO quiz_attempt_options(attempt_id,question_id,option_id,sort_order) VALUES(?,?,?,?)');
                $options = $db->prepare('SELECT id FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');
                foreach ($questionIds->fetchAll(PDO::FETCH_COLUMN) as $index => $questionId) {
                    $questionId = (int)$questionId;
                    $saveQuestion->execute([$attemptId, $questionId, $index + 1]);
                    $options->execute([$questionId]);
                    foreach ($options->fetchAll(PDO::FETCH_COLUMN) as $optionIndex => $optionId) {
                        $saveOption->execute([$attemptId, $questionId, (int)$optionId, $optionIndex + 1]);
                    }
                }
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    private function assertReadyForRelease(PDO $db, int $quizId, int $expected, string $quizType): void
    {
        if ($quizId < 1) throw new RuntimeException('Save the quiz as a draft and add its questions before releasing it.');
        $count = $db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?');
        $count->execute([$quizId]);
        if ((int)$count->fetchColumn() !== $expected) throw new RuntimeException('A quiz can only be released when its configured number of questions is complete.');
        $options = $db->prepare('SELECT COUNT(*),SUM(CASE WHEN option_count=4 AND correct_count=1 THEN 1 ELSE 0 END) FROM (SELECT qq.id,COUNT(qo.id) AS option_count,SUM(CASE WHEN qo.is_correct=1 THEN 1 ELSE 0 END) AS correct_count FROM quiz_questions qq LEFT JOIN quiz_options qo ON qo.question_id=qq.id WHERE qq.quiz_id=? GROUP BY qq.id) qcheck');
        $options->execute([$quizId]);
        $result = $options->fetch(PDO::FETCH_NUM);
        if (!$result || (int)$result[0] !== $expected || (int)$result[1] !== $expected) throw new RuntimeException('Every question must have exactly four options and exactly one correct answer before release.');
        if ($quizType === 'practice') {
            $missingExplanations = $db->prepare("SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=? AND (explanation IS NULL OR TRIM(explanation)='')");
            $missingExplanations->execute([$quizId]);
            if ((int)$missingExplanations->fetchColumn() > 0) throw new RuntimeException('Add an explanation to every practice question before releasing it.');
        }
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone((require BASE_PATH . '/config/app.php')['timezone']));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) throw new RuntimeException('Enter valid quiz release and closing dates.');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function audit(PDO $db, int $actor, string $action, int $entity, array $metadata): void
    {
        $stmt = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,\'quiz\',?,?)');
        $stmt->execute([$actor, $action, $entity, json_encode($metadata, JSON_THROW_ON_ERROR)]);
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
