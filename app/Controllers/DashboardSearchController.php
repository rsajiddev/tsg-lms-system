<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use PDO;

/** Role-scoped quick search across the records available in each dashboard. */
final class DashboardSearchController
{
    public function search(): void
    {
        $session = $_SESSION['user'] ?? [];
        $role = (string) ($session['role'] ?? '');
        if (!in_array($role, ['admin', 'student'], true)) {
            http_response_code(401);
            $this->respond([]);
            return;
        }
        $user = AuthMiddleware::requireRole($role);
        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query) < 2) {
            $this->respond([]);
            return;
        }
        $query = mb_substr($query, 0, 80);
        $like = '%' . $query . '%';
        $studentId = (int) $user['id'];
        $db = Database::connection();
        $results = [];

        // Each source is isolated: older installations can still search the modules whose
        // schema has already been imported, and no one query can reveal the other role's data.
        $collect = static function (string $sql, array $params) use ($db, &$results): void {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $results[] = [
                        'type' => (string) ($row['type'] ?? 'Result'),
                        'label' => trim((string) ($row['label'] ?? '')),
                        'detail' => trim((string) ($row['detail'] ?? '')),
                        'path' => (string) ($row['path'] ?? '/dashboard'),
                    ];
                }
            } catch (\Throwable) {
                // A missing optional module table does not take down search for the rest.
            }
        };

        if ($role === 'admin') {
            $collect("SELECT 'Student' type,full_name label,CONCAT(email,' · ',COALESCE(account_reference,'Student')) detail,CONCAT('/admin/students?student_id=',id) path FROM users WHERE role='student' AND (full_name LIKE ? OR email LIKE ? OR account_reference LIKE ?) ORDER BY full_name LIMIT 4", [$like, $like, $like]);
            $collect("SELECT 'Course' type,title label,CONCAT(category,' · ',visibility) detail,'/admin/courses' path FROM courses WHERE title LIKE ? OR category LIKE ? ORDER BY title LIMIT 3", [$like, $like]);
            $collect("SELECT 'Lesson' type,l.title label,CONCAT(c.title,' · ',m.title) detail,'/admin/lessons' path FROM lessons l JOIN course_modules m ON m.id=l.module_id JOIN courses c ON c.id=m.course_id WHERE l.title LIKE ? OR m.title LIKE ? OR c.title LIKE ? ORDER BY l.id DESC LIMIT 3", [$like, $like, $like]);
            $collect("SELECT 'Code challenge' type,ch.title label,CONCAT(c.title,' · ',ch.status) detail,'/admin/coding-challenges' path FROM coding_challenges ch JOIN courses c ON c.id=ch.course_id WHERE ch.title LIKE ? OR c.title LIKE ? OR ch.instructions LIKE ? ORDER BY ch.id DESC LIMIT 3", [$like, $like, $like]);
            $collect("SELECT 'Assignment' type,a.title label,CONCAT(c.title,' · ',a.status) detail,CONCAT('/admin/assignments#assignment-',a.id) path FROM assignments a JOIN courses c ON c.id=a.course_id WHERE a.title LIKE ? OR c.title LIKE ? ORDER BY a.id DESC LIMIT 4", [$like, $like]);
            $collect("SELECT 'Submission' type,a.title label,CONCAT(u.full_name,' · ',c.title) detail,CONCAT('/admin/assignments#submission-',s.id) path FROM assignment_submissions s JOIN assignments a ON a.id=s.assignment_id JOIN courses c ON c.id=a.course_id JOIN users u ON u.id=s.student_id WHERE a.title LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR s.submission_text LIKE ? ORDER BY s.submitted_at DESC LIMIT 4", [$like, $like, $like, $like]);
            $collect("SELECT 'Quiz' type,q.title label,CONCAT(c.title,' · ',q.status) detail,'/admin/quizzes' path FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.title LIKE ? OR c.title LIKE ? ORDER BY q.id DESC LIMIT 3", [$like, $like]);
            $collect("SELECT 'Quiz result' type,CONCAT(q.title,' · ',u.full_name) label,CONCAT(COALESCE(qa.result,'Pending'),' · ',COALESCE(qa.percentage,0),'% · ',c.title) detail,'/admin/quiz-reports' path FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN users u ON u.id=qa.student_id JOIN courses c ON c.id=q.course_id WHERE q.title LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR c.title LIKE ? ORDER BY COALESCE(qa.submitted_at,qa.started_at) DESC LIMIT 4", [$like, $like, $like, $like]);
            $collect("SELECT 'Payment' type,CONCAT(u.full_name,' · ',c.title) label,CONCAT(p.currency,' ',p.amount,' · ',p.status,' · ',p.transaction_reference) detail,'/admin/payments' path FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE u.full_name LIKE ? OR u.email LIKE ? OR c.title LIKE ? OR p.transaction_reference LIKE ? ORDER BY p.created_at DESC LIMIT 4", [$like, $like, $like, $like]);
            $collect("SELECT 'Announcement' type,title label,body detail,'/admin/announcements' path FROM notifications WHERE title LIKE ? OR body LIKE ? ORDER BY created_at DESC LIMIT 3", [$like, $like]);
            $collect("SELECT 'Certificate' type,CONCAT(u.full_name,' · ',c.title) label,CONCAT(COALESCE(cert.certificate_id,'Not issued'),' · ',cert.status) detail,'/admin/certificates' path FROM certificates cert JOIN users u ON u.id=cert.student_id JOIN courses c ON c.id=cert.course_id WHERE u.full_name LIKE ? OR u.email LIKE ? OR c.title LIKE ? OR cert.certificate_id LIKE ? ORDER BY cert.requested_at DESC LIMIT 3", [$like, $like, $like, $like]);
            $collect("SELECT 'Review' type,full_name label,CONCAT(rating,' / 5 · ',status,' · ',content) detail,'/admin/reviews' path FROM reviews WHERE full_name LIKE ? OR content LIKE ? OR context_label LIKE ? ORDER BY created_at DESC LIMIT 3", [$like, $like, $like]);
            $collect("SELECT 'Chat' type,u.full_name label,m.message detail,CONCAT('/admin/chats?thread_id=',t.id) path FROM chat_messages m JOIN chat_threads t ON t.id=m.thread_id JOIN users u ON u.id=t.student_id WHERE u.full_name LIKE ? OR u.email LIKE ? OR m.message LIKE ? ORDER BY m.created_at DESC LIMIT 3", [$like, $like, $like]);
        } else {
            $collect("SELECT 'Course' type,c.title label,CONCAT(COALESCE(c.category,'Course'),' · ',COALESCE(c.level_label,'All levels')) detail,CONCAT('/learning?course_id=',c.id) path FROM student_course_access access JOIN courses c ON c.id=access.course_id WHERE access.student_id=? AND access.status IN ('active','completed','certified') AND (access.expires_at IS NULL OR access.expires_at>UTC_TIMESTAMP()) AND (c.title LIKE ? OR c.category LIKE ? OR c.short_description LIKE ?) ORDER BY c.title LIMIT 4", [$studentId, $like, $like, $like]);
            $collect("SELECT 'Lesson' type,l.title label,CONCAT(c.title,' · Lesson') detail,CONCAT('/learning/lesson?lesson_id=',l.id) path FROM student_course_access access JOIN courses c ON c.id=access.course_id JOIN course_modules cm ON cm.course_id=c.id JOIN lessons l ON l.module_id=cm.id WHERE access.student_id=? AND access.status IN ('active','completed','certified') AND (access.expires_at IS NULL OR access.expires_at>UTC_TIMESTAMP()) AND l.status='published' AND l.title LIKE ? ORDER BY l.id DESC LIMIT 4", [$studentId, $like]);
            $collect("SELECT 'Assignment' type,a.title label,CONCAT(c.title,' · ',COALESCE(s.status,'Not submitted')) detail,CONCAT('/learning/assignments?course_id=',c.id) path FROM student_course_access access JOIN courses c ON c.id=access.course_id JOIN assignments a ON a.course_id=c.id LEFT JOIN assignment_submissions s ON s.id=(SELECT latest.id FROM assignment_submissions latest WHERE latest.assignment_id=a.id AND latest.student_id=? ORDER BY latest.id DESC LIMIT 1) WHERE access.student_id=? AND access.status IN ('active','completed','certified') AND (access.expires_at IS NULL OR access.expires_at>UTC_TIMESTAMP()) AND a.title LIKE ? ORDER BY a.id DESC LIMIT 4", [$studentId, $studentId, $like]);
            $collect("SELECT 'Quiz' type,q.title label,CONCAT(c.title,' · ',COALESCE(latest.result,'Not attempted')) detail,CONCAT('/learning/quizzes?course_id=',c.id) path FROM student_course_access access JOIN courses c ON c.id=access.course_id JOIN quizzes q ON q.course_id=c.id LEFT JOIN (SELECT quiz_id,MAX(id) last_id,student_id FROM quiz_attempts WHERE student_id=? GROUP BY quiz_id,student_id) pick ON pick.quiz_id=q.id LEFT JOIN quiz_attempts latest ON latest.id=pick.last_id WHERE access.student_id=? AND access.status IN ('active','completed','certified') AND (access.expires_at IS NULL OR access.expires_at>UTC_TIMESTAMP()) AND q.title LIKE ? ORDER BY q.id DESC LIMIT 4", [$studentId, $studentId, $like]);
            $collect("SELECT 'Code challenge' type,ch.title label,CONCAT(c.title,' · ',COALESCE(a.reported_status,'Not attempted')) detail,CONCAT('/learning/challenges?course_id=',c.id) path FROM student_course_access access JOIN courses c ON c.id=access.course_id JOIN coding_challenges ch ON ch.course_id=c.id LEFT JOIN (SELECT challenge_id,MAX(id) last_id,student_id FROM coding_challenge_attempts WHERE student_id=? GROUP BY challenge_id,student_id) pick ON pick.challenge_id=ch.id LEFT JOIN coding_challenge_attempts a ON a.id=pick.last_id WHERE access.student_id=? AND access.status IN ('active','completed','certified') AND (access.expires_at IS NULL OR access.expires_at>UTC_TIMESTAMP()) AND ch.status='published' AND ch.title LIKE ? ORDER BY ch.id DESC LIMIT 4", [$studentId, $studentId, $like]);
            $collect("SELECT 'Payment' type,c.title label,CONCAT(p.currency,' ',p.amount,' · ',p.status,' · ',p.transaction_reference) detail,'/student/payments' path FROM payments p JOIN courses c ON c.id=p.course_id WHERE p.student_id=? AND (c.title LIKE ? OR p.transaction_reference LIKE ? OR p.status LIKE ?) ORDER BY p.created_at DESC LIMIT 4", [$studentId, $like, $like, $like]);
            $collect("SELECT 'Announcement' type,n.title label,n.body detail,CONCAT('/notifications/view?notification_id=',n.id) path FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.student_id=? AND n.published_at IS NOT NULL AND (n.title LIKE ? OR n.body LIKE ?) ORDER BY n.published_at DESC LIMIT 4", [$studentId, $like, $like]);
            $collect("SELECT 'Certificate' type,c.title label,CONCAT(COALESCE(cert.certificate_id,'Request'),' · ',cert.status) detail,'/student/courses' path FROM certificates cert JOIN courses c ON c.id=cert.course_id WHERE cert.student_id=? AND (c.title LIKE ? OR cert.certificate_id LIKE ? OR cert.status LIKE ?) ORDER BY cert.requested_at DESC LIMIT 3", [$studentId, $like, $like, $like]);
            $collect("SELECT 'Chat' type,'Academy support' label,m.message detail,CONCAT('/student/chats?thread_id=',t.id) path FROM chat_threads t JOIN chat_messages m ON m.thread_id=t.id WHERE t.student_id=? AND m.message LIKE ? ORDER BY m.created_at DESC LIMIT 3", [$studentId, $like]);
        }

        $collect('SELECT \'My task\' type,title label,IF(is_done=1,\'Completed\',\'To do\') detail,\'/dashboard#my-tasks\' path FROM dashboard_tasks WHERE user_id=? AND title LIKE ? ORDER BY is_done,created_at DESC LIMIT 3', [$studentId, $like]);
        $this->respond(array_slice($results, 0, 12));
    }

    private function respond(array $results): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
