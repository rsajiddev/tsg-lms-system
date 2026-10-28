<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use RuntimeException;
use PDO;

final class StudentManagementController
{
    private const PAGE_SIZE = 20;

    public function index(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $loginHistoryAvailable = true;
        try {
            $db->query('SELECT 1 FROM login_events LIMIT 0');
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $loginHistoryAvailable = false;
        }
        $search = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'active', 'pending', 'suspended'], true)) $status = 'all';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $statusCounts = $db->query("SELECT COUNT(*) AS total, SUM(status='active') AS active, SUM(status='pending') AS pending, SUM(status='suspended') AS suspended FROM users WHERE role='student'")->fetch() ?: [];

        $where = ["u.role = 'student'"];
        $params = [];
        if ($search !== '') {
            if (ctype_digit($search)) {
                $where[] = '(u.id = ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                $params[] = (int) $search;
            } else {
                $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
            }
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($status !== 'all') {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $count = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $loginCounts = $loginHistoryAvailable
            ? "(SELECT COUNT(*) FROM login_events le WHERE le.user_id=u.id AND le.event_type='success') AS successful_logins,
                (SELECT COUNT(*) FROM login_events le WHERE le.user_id=u.id AND le.event_type IN ('failed','blocked')) AS failed_logins"
            : 'NULL AS successful_logins, NULL AS failed_logins';
        $studentsQuery = $db->prepare(
            "SELECT u.id,u.full_name,u.email,u.status,u.email_verified_at,u.created_at,u.last_login_at,$loginCounts
             FROM users u WHERE $whereSql ORDER BY u.created_at DESC,u.id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . $offset
        );
        $studentsQuery->execute($params);

        $studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $selectedStudent = null;
        $activity = [];
        $selectedLoginCounts = ['successful' => 0, 'failed' => 0];
        if ($studentId !== null) {
            $selectedQuery = $db->prepare("SELECT id,full_name,email,status,email_verified_at,created_at,last_login_at FROM users WHERE id=? AND role='student' LIMIT 1");
            $selectedQuery->execute([$studentId]);
            $selectedStudent = $selectedQuery->fetch() ?: null;
            if ($selectedStudent && $loginHistoryAvailable) {
                $countsQuery = $db->prepare("SELECT SUM(event_type='success') AS successful, SUM(event_type IN ('failed','blocked')) AS failed FROM login_events WHERE user_id=?");
                $countsQuery->execute([$studentId]);
                $selectedLoginCounts = $countsQuery->fetch() ?: $selectedLoginCounts;
                $eventsQuery = $db->prepare('SELECT event_type,ip_address,created_at FROM login_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');
                $eventsQuery->execute([$studentId]);
                $activity = $eventsQuery->fetchAll();
            }
        }

        View::render('admin/students', [
            'title' => 'Student management',
            'user' => $admin,
            'students' => $studentsQuery->fetchAll(),
            'search' => $search,
            'statusFilter' => $status,
            'page' => $page,
            'pages' => $pages,
            'totalStudents' => $total,
            'statusCounts' => $statusCounts,
            'selectedStudent' => $selectedStudent,
            'selectedLoginCounts' => $selectedLoginCounts,
            'activity' => $activity,
            'loginHistoryAvailable' => $loginHistoryAvailable,
            'message' => $_SESSION['_flash'] ?? null,
        ]);
        unset($_SESSION['_flash']);
    }

    public function setStatus(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $status = (string) ($_POST['status'] ?? '');
        if ($studentId < 1 || !in_array($status, ['active', 'suspended'], true)) throw new RuntimeException('Choose a valid student and account action.');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare("SELECT id,status FROM users WHERE id=? AND role='student' FOR UPDATE");
            $lock->execute([$studentId]);
            $student = $lock->fetch();
            if (!$student || !in_array($student['status'], ['active', 'suspended'], true)) throw new RuntimeException('Only active or suspended student accounts can be changed here.');
            if ($student['status'] !== $status) {
                $update = $db->prepare("UPDATE users SET status=? WHERE id=? AND role='student'");
                $update->execute([$status, $studentId]);
                $audit = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
                $audit->execute([(int) $admin['id'], $status === 'suspended' ? 'student.blocked' : 'student.unblocked', 'user', $studentId, json_encode(['previous_status' => $student['status'], 'status' => $status], JSON_THROW_ON_ERROR)]);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = $status === 'suspended'
            ? 'Student blocked. Their next request will end any active session, and sign-in is disabled.'
            : 'Student unblocked. They can sign in again.';
        $this->redirect('/admin/students?student_id=' . $studentId);
    }

    public function clearActivity(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($studentId < 1) throw new RuntimeException('Choose a valid student.');
        $db = Database::connection();
        $check = $db->prepare("SELECT id FROM users WHERE id=? AND role='student'");
        $check->execute([$studentId]);
        if (!$check->fetch()) throw new RuntimeException('Student was not found.');
        try {
            $delete = $db->prepare('DELETE FROM login_events WHERE user_id=?');
            $delete->execute([$studentId]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            throw new RuntimeException('Login history table is not available yet. Import the updated schema first.');
        }
        $deleted = $delete->rowCount();
        $audit = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
        $audit->execute([(int) $admin['id'], 'student.activity_cleared', 'user', $studentId, json_encode(['deleted_events' => $deleted], JSON_THROW_ON_ERROR)]);
        $_SESSION['_flash'] = $deleted > 0
            ? 'Cleared ' . number_format($deleted) . ' sign-in record(s) for this student.'
            : 'There was no sign-in activity to clear.';
        $this->redirect('/admin/students?student_id=' . $studentId);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
