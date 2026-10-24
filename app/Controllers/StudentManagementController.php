<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;
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

        $where = ["u.role = 'student'"];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
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
        if ($studentId !== null) {
            $selectedQuery = $db->prepare("SELECT id,full_name,email,status,email_verified_at,created_at,last_login_at FROM users WHERE id=? AND role='student' LIMIT 1");
            $selectedQuery->execute([$studentId]);
            $selectedStudent = $selectedQuery->fetch() ?: null;
            if ($selectedStudent && $loginHistoryAvailable) {
                $eventsQuery = $db->prepare('SELECT event_type,ip_address,created_at FROM login_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');
                $eventsQuery->execute([$studentId]);
                $activity = $eventsQuery->fetchAll();
            }
        }

        View::render('admin/students', [
            'title' => 'Student management', 'user' => $admin,
            'students' => $studentsQuery->fetchAll(), 'search' => $search, 'statusFilter' => $status,
            'page' => $page, 'pages' => $pages, 'totalStudents' => $total,
            'selectedStudent' => $selectedStudent, 'activity' => $activity,
            'loginHistoryAvailable' => $loginHistoryAvailable,
        ]);
    }
}
