<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use RuntimeException;

final class CodingChallengeController
{
    private const ELIGIBLE_COURSE_SLUG = 'full-stack-web-development';

    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courses = $db->prepare("SELECT id,title FROM courses WHERE slug=? AND visibility IN ('published','draft','hidden') ORDER BY title");
        $courses->execute([self::ELIGIBLE_COURSE_SLUG]);
        $courses = $courses->fetchAll();
        $challenges = $db->prepare('SELECT cc.*,c.title AS course_title,(SELECT COUNT(*) FROM coding_challenge_attempts a WHERE a.challenge_id=cc.id) AS attempt_count FROM coding_challenges cc JOIN courses c ON c.id=cc.course_id WHERE c.slug=? ORDER BY cc.updated_at DESC,cc.id DESC');
        $challenges->execute([self::ELIGIBLE_COURSE_SLUG]);
        $challenges = $challenges->fetchAll();
        View::render('admin/coding-challenges', ['title' => 'Coding challenges', 'user' => $admin, 'courses' => $courses, 'challenges' => $challenges, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function save(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $courseId = filter_var($_POST['course_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $html = (string)($_POST['starter_html'] ?? '');
        $css = (string)($_POST['starter_css'] ?? '');
        $js = (string)($_POST['starter_js'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');
        $testsRaw = trim((string)($_POST['tests_json'] ?? ''));
        $tests = json_decode($testsRaw, true);
        if ($courseId < 1 || mb_strlen($title) < 3 || mb_strlen($title) > 200 || mb_strlen($instructions) > 10000 || strlen($html) > 100000 || strlen($css) > 100000 || strlen($js) > 100000 || !in_array($status, ['draft', 'published'], true) || !is_array($tests) || !$this->validTests($tests)) {
            throw new RuntimeException('Check the challenge details. Add 1–20 valid tests and keep each starter file under 100 KB.');
        }
        $db = Database::connection();
        $course = $db->prepare('SELECT id FROM courses WHERE id=? AND slug=?');
        $course->execute([$courseId, self::ELIGIBLE_COURSE_SLUG]);
        if (!$course->fetchColumn()) throw new RuntimeException('Coding challenges can only be attached to Full Stack Web Development.');
        $testsJson = json_encode($tests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($testsJson)) throw new RuntimeException('Challenge tests could not be saved.');
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE coding_challenges SET course_id=?,title=?,instructions=?,starter_html=?,starter_css=?,starter_js=?,tests_json=?,status=? WHERE id=? AND course_id=?');
            $stmt->execute([$courseId, $title, $instructions, $html, $css, $js, $testsJson, $status, $id, $courseId]);
            if ($stmt->rowCount() === 0) {
                $check = $db->prepare('SELECT id FROM coding_challenges WHERE id=? AND course_id=?');
                $check->execute([$id, $courseId]);
                if (!$check->fetchColumn()) throw new RuntimeException('Challenge not found.');
            }
        } else {
            $stmt = $db->prepare('INSERT INTO coding_challenges(course_id,title,instructions,starter_html,starter_css,starter_js,tests_json,status,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId, $title, $instructions, $html, $css, $js, $testsJson, $status, (int)$admin['id']]);
            $id = (int)$db->lastInsertId();
        }
        $_SESSION['_flash'] = 'Coding challenge saved.';
        $this->redirect('/admin/coding-challenges#challenge-' . $id);
    }

    public function studentIndex(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $courseRow = null;
        $hasCodingCourseAccess = false;
        if ($courseId > 0) {
            $course = $db->prepare("SELECT c.id,c.title FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.id=? AND c.slug=? AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) LIMIT 1");
            $course->execute([$courseId, self::ELIGIBLE_COURSE_SLUG, (int)$student['id']]);
            $courseRow = $course->fetch();
            if (!$courseRow) {
                http_response_code(404);
                View::render('errors/404', ['title' => 'Course unavailable', 'user' => $student]);
                return;
            }
            $hasCodingCourseAccess = true;
            $stmt = $db->prepare("SELECT cc.id,cc.course_id,cc.title,cc.instructions,cc.tests_json,c.title AS course_title,(SELECT COUNT(*) FROM coding_challenge_attempts ca WHERE ca.challenge_id=cc.id AND ca.student_id=?) AS attempt_count,(SELECT ca.reported_status FROM coding_challenge_attempts ca WHERE ca.challenge_id=cc.id AND ca.student_id=? ORDER BY ca.id DESC LIMIT 1) AS latest_status FROM coding_challenges cc JOIN courses c ON c.id=cc.course_id WHERE cc.course_id=? AND c.slug=? AND cc.status='published' ORDER BY cc.id");
            $stmt->execute([(int)$student['id'], (int)$student['id'], $courseId, self::ELIGIBLE_COURSE_SLUG]);
        } else {
            $access = $db->prepare("SELECT c.id,c.title FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.slug=? AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) LIMIT 1");
            $access->execute([self::ELIGIBLE_COURSE_SLUG, (int)$student['id']]);
            $courseRow = $access->fetch();
            $hasCodingCourseAccess = is_array($courseRow);
            if (!$hasCodingCourseAccess) {
                $courseRow = ['id' => 0, 'title' => 'Code Challenges'];
            }
            $stmt = $db->prepare("SELECT cc.id,cc.course_id,cc.title,cc.instructions,cc.tests_json,c.title AS course_title,(SELECT COUNT(*) FROM coding_challenge_attempts ca WHERE ca.challenge_id=cc.id AND ca.student_id=?) AS attempt_count,(SELECT ca.reported_status FROM coding_challenge_attempts ca WHERE ca.challenge_id=cc.id AND ca.student_id=? ORDER BY ca.id DESC LIMIT 1) AS latest_status FROM coding_challenges cc JOIN courses c ON c.id=cc.course_id JOIN student_course_access a ON a.course_id=c.id AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) WHERE c.slug=? AND cc.status='published' ORDER BY c.title,cc.id");
            $stmt->execute([(int)$student['id'], (int)$student['id'], (int)$student['id'], self::ELIGIBLE_COURSE_SLUG]);
        }
        $challenges = $stmt->fetchAll();
        foreach ($challenges as &$challenge) {
            $tests = json_decode((string)$challenge['tests_json'], true);
            $challenge['test_count'] = is_array($tests) ? count($tests) : 0;
        }
        unset($challenge);
        View::render('learning/coding-challenges', ['title' => 'Coding challenges', 'user' => $student, 'course' => $courseRow, 'challenges' => $challenges, 'hasCodingCourseAccess' => $hasCodingCourseAccess, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function editor(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $challengeId = filter_input(INPUT_GET, 'challenge_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $stmt = $db->prepare("SELECT cc.*,c.title AS course_title FROM coding_challenges cc JOIN courses c ON c.id=cc.course_id JOIN student_course_access a ON a.course_id=c.id AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) WHERE cc.id=? AND c.slug=? AND cc.status='published' LIMIT 1");
        $stmt->execute([(int)$student['id'], $challengeId, self::ELIGIBLE_COURSE_SLUG]);
        $challenge = $stmt->fetch();
        if (!$challenge) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Challenge unavailable', 'user' => $student]);
            return;
        }
        $attempts = $db->prepare('SELECT reported_status,test_results,created_at FROM coding_challenge_attempts WHERE challenge_id=? AND student_id=? ORDER BY id DESC LIMIT 10');
        $attempts->execute([$challengeId, (int)$student['id']]);
        View::render('learning/coding-editor', ['title' => $challenge['title'], 'user' => $student, 'challenge' => $challenge, 'attempts' => $attempts->fetchAll(), 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function submitAttempt(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $this->csrf();
        $challengeId = filter_var($_POST['challenge_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $html = (string)($_POST['html_code'] ?? '');
        $css = (string)($_POST['css_code'] ?? '');
        $js = (string)($_POST['js_code'] ?? '');
        $reported = (string)($_POST['reported_status'] ?? '');
        $results = json_decode((string)($_POST['test_results'] ?? ''), true);
        if (strlen($html) > 100000 || strlen($css) > 100000 || strlen($js) > 100000 || !in_array($reported, ['pass', 'fail'], true) || !is_array($results) || count($results) > 20 || strlen((string)($_POST['test_results'] ?? '')) > 20000) {
            throw new RuntimeException('Run the challenge checks first, then save a valid attempt.');
        }
        $db = Database::connection();
        $allowed = $db->prepare("SELECT cc.tests_json FROM coding_challenges cc JOIN courses c ON c.id=cc.course_id JOIN student_course_access a ON a.course_id=cc.course_id AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) WHERE cc.id=? AND c.slug=? AND cc.status='published' LIMIT 1");
        $allowed->execute([(int)$student['id'], $challengeId, self::ELIGIBLE_COURSE_SLUG]);
        $challengeTests = $allowed->fetchColumn();
        if (!is_string($challengeTests)) throw new RuntimeException('This challenge is unavailable for your account.');
        $expectedTests = json_decode($challengeTests, true);
        if (!is_array($expectedTests) || count($expectedTests) < 1 || count($expectedTests) > 20) throw new RuntimeException('This challenge does not have valid practice checks.');
        $safeResults = [];
        if (count($results) !== count($expectedTests)) throw new RuntimeException('Run all practice checks again before saving this attempt.');
        foreach ($results as $index => $result) {
            if (!is_array($result) || !isset($result['name'], $result['passed']) || !is_string($result['name']) || !is_bool($result['passed'])) throw new RuntimeException('The challenge result format is invalid.');
            $expectedName = $expectedTests[$index]['name'] ?? null;
            if (!is_string($expectedName) || !hash_equals($expectedName, $result['name'])) throw new RuntimeException('The challenge results do not match its current checks. Run them again.');
            $safeResults[] = ['name' => $expectedName, 'passed' => $result['passed']];
        }
        if (count($safeResults) < 1 || count($safeResults) > 20 || $reported !== (count(array_filter($safeResults, static fn(array $item): bool => $item['passed'])) === count($safeResults) ? 'pass' : 'fail')) {
            throw new RuntimeException('Run all practice checks again before saving this attempt.');
        }
        $db->prepare('INSERT INTO coding_challenge_attempts(challenge_id,student_id,html_code,css_code,js_code,reported_status,test_results) VALUES(?,?,?,?,?,?,?)')->execute([$challengeId, (int)$student['id'], $html, $css, $js, $reported, json_encode($safeResults, JSON_UNESCAPED_UNICODE)]);
        $_SESSION['_flash'] = 'Practice attempt saved. Browser checks are for learning and are not official graded marks.';
        $this->redirect('/learning/challenge?challenge_id=' . $challengeId);
    }

    private function validTests(array $tests): bool
    {
        if (count($tests) < 1 || count($tests) > 20) return false;
        foreach ($tests as $test) {
            if (!is_array($test) || !is_string($test['name'] ?? null) || mb_strlen(trim($test['name'])) < 2 || mb_strlen($test['name']) > 160 || !is_string($test['selector'] ?? null) || trim($test['selector']) === '' || mb_strlen($test['selector']) > 160 || !in_array($test['assertion'] ?? '', ['exists', 'text_contains', 'attribute_equals', 'style_equals'], true)) return false;
            if (($test['assertion'] ?? '') !== 'exists' && (!is_string($test['expected'] ?? null) || mb_strlen($test['expected']) > 500)) return false;
            if (($test['assertion'] ?? '') === 'attribute_equals' && (!is_string($test['attribute'] ?? null) || !preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]{0,63}$/', $test['attribute']))) return false;
            if (($test['assertion'] ?? '') === 'style_equals' && (!is_string($test['property'] ?? null) || !preg_match('/^[a-zA-Z-]{1,64}$/', $test['property']))) return false;
        }
        return true;
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
