<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use PDO;
use RuntimeException;

final class CourseAdminController
{
    public function index(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courses = $db->query("SELECT c.id,c.title,c.slug,c.visibility,c.category,c.short_description,c.description,c.duration_label,c.level_label,COUNT(DISTINCT cm.id) AS module_count,COUNT(DISTINCT l.id) AS lesson_count,(SELECT price FROM course_pricing_options WHERE course_id=c.id AND option_code='recorded_only' LIMIT 1) AS recorded_price,(SELECT price FROM course_pricing_options WHERE course_id=c.id AND option_code='live_recorded' LIMIT 1) AS live_price FROM courses c LEFT JOIN course_modules cm ON cm.course_id=c.id LEFT JOIN lessons l ON l.module_id=cm.id GROUP BY c.id ORDER BY c.updated_at DESC,c.id DESC")->fetchAll();
        $lessons = $db->query('SELECT l.id,l.title,l.body,l.status,l.sort_order,cm.id AS module_id,cm.title AS module_title,c.id AS course_id,c.title AS course_title FROM lessons l JOIN course_modules cm ON cm.id=l.module_id JOIN courses c ON c.id=cm.course_id ORDER BY c.title,cm.sort_order,cm.id,l.sort_order,l.id')->fetchAll();
        $students = $db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL ORDER BY full_name")->fetchAll();
        View::render('admin/courses', ['title'=>'Course management','user'=>$admin,'courses'=>$courses,'lessons'=>$lessons,'students'=>$students,'message'=>$_SESSION['_flash']??null]);
        unset($_SESSION['_flash']);
    }

    public function save(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $title=trim((string)($_POST['title']??'')); $slug=strtolower(trim((string)($_POST['slug']??'')));
        $shortDescription=trim((string)($_POST['short_description']??''));
        $description=trim((string)($_POST['description']??'')); $category=trim((string)($_POST['category']??''));
        $duration=trim((string)($_POST['duration_label']??'')); $level=trim((string)($_POST['level_label']??''));
        $recordedPrice=(string)($_POST['recorded_price']??''); $livePrice=(string)($_POST['live_price']??'');
        $visibility=(string)($_POST['visibility']??'draft'); $id=(int)($_POST['id']??0);
        if (mb_strlen($title)<3 || mb_strlen($title)>200 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug) || !in_array($visibility,['draft','published','hidden','archived'],true)
            || !is_numeric($recordedPrice) || !is_numeric($livePrice) || (float)$recordedPrice<0 || (float)$livePrice<0
            || mb_strlen($shortDescription)>500 || mb_strlen($duration)>100 || mb_strlen($level)>50) {
            throw new RuntimeException('Enter valid course details, duration, and both non-negative prices.');
        }
        $db=Database::connection();
        if ($id>0) {
            $stmt=$db->prepare('UPDATE courses SET title=?,slug=?,category=?,short_description=?,description=?,duration_label=?,level_label=?,visibility=? WHERE id=?');
            $stmt->execute([$title,$slug,$category?:null,$shortDescription?:null,$description?:null,$duration?:null,$level?:null,$visibility,$id]);
            $courseId=$id;
        } else {
            $stmt=$db->prepare('INSERT INTO courses (title,slug,category,short_description,description,duration_label,level_label,price,currency,visibility,created_by) VALUES (?,?,?,?,?,?,?,?,\'PKR\',?,?)');
            $stmt->execute([$title,$slug,$category?:null,$shortDescription?:null,$description?:null,$duration?:null,$level?:null,$recordedPrice,$visibility,(int)$admin['id']]);
            $courseId=(int)$db->lastInsertId();
        }
        $pricing=$db->prepare('INSERT INTO course_pricing_options(course_id,option_code,display_label,price,currency) VALUES(?,?,?,?,\'PKR\') ON DUPLICATE KEY UPDATE price=VALUES(price),display_label=VALUES(display_label)');
        $pricing->execute([$courseId,'recorded_only','Recorded Only',$recordedPrice]);
        $pricing->execute([$courseId,'live_recorded','Live + Recorded',$livePrice]);
        $this->audit($db,(int)$admin['id'],'course.saved','course',$courseId);
        $_SESSION['_flash']='Course saved.';
        $this->redirect('/admin/courses');
    }

    public function saveLesson(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $this->csrf();
        $db=Database::connection(); $courseId=(int)($_POST['course_id']??0); $lessonId=(int)($_POST['lesson_id']??0); $moduleId=(int)($_POST['module_id']??0);
        $moduleTitle=trim((string)($_POST['module_title']??'')); $lessonTitle=trim((string)($_POST['lesson_title']??''));
        $body=trim((string)($_POST['body']??'')); $status=(string)($_POST['status']??'draft');
        if ($courseId<1 || mb_strlen($moduleTitle)<2 || mb_strlen($lessonTitle)<2 || mb_strlen($lessonTitle)>200 || !in_array($status,['draft','published'],true) || mb_strlen($body)>500000) throw new RuntimeException('Enter a valid module and lesson title, content, and publishing status.');
        $db->beginTransaction();
        try {
            $check=$db->prepare('SELECT id FROM courses WHERE id=?'); $check->execute([$courseId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Course not found.');
            if ($lessonId>0) {
                $moduleCheck=$db->prepare('SELECT id FROM course_modules WHERE id=? AND course_id=?'); $moduleCheck->execute([$moduleId,$courseId]);
                if (!$moduleCheck->fetchColumn()) throw new RuntimeException('The selected lesson module does not belong to this course.');
                $lessonCheck=$db->prepare('SELECT id FROM lessons WHERE id=? AND module_id=?'); $lessonCheck->execute([$lessonId,$moduleId]);
                if (!$lessonCheck->fetchColumn()) throw new RuntimeException('Lesson not found in the selected module.');
                $update=$db->prepare('UPDATE lessons SET title=?,body=?,status=? WHERE id=? AND module_id=?');
                $update->execute([$lessonTitle,$body?:null,$status,$lessonId,$moduleId]);
                $action='lesson.updated';
            } else {
                $module=$db->prepare('SELECT id FROM course_modules WHERE course_id=? AND title=? LIMIT 1'); $module->execute([$courseId,$moduleTitle]);
                $moduleId=(int)($module->fetchColumn() ?: 0);
                if (!$moduleId) { $insert=$db->prepare('INSERT INTO course_modules(course_id,title,sort_order) VALUES(?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM course_modules existing WHERE existing.course_id=?))'); $insert->execute([$courseId,$moduleTitle,$courseId]); $moduleId=(int)$db->lastInsertId(); }
                $lesson=$db->prepare('INSERT INTO lessons(module_id,title,body,status,sort_order) VALUES(?,?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons existing WHERE existing.module_id=?))');
                $lesson->execute([$moduleId,$lessonTitle,$body?:null,$status,$moduleId]); $lessonId=(int)$db->lastInsertId();
                $action='lesson.created';
            }
            $this->audit($db,(int)$admin['id'],$action,'lesson',$lessonId,['status'=>$status]);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $_SESSION['_flash']=$status==='published'?'Lesson published for students with active course access.':'Lesson saved as a draft.';
        $this->redirect('/admin/courses');
    }

    public function access(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $this->csrf(); $db=Database::connection();
        $studentId=(int)($_POST['student_id']??0); $courseId=(int)($_POST['course_id']??0); $action=(string)($_POST['access_action']??'grant');
        if ($studentId<1 || $courseId<1 || !in_array($action,['grant','revoke'],true)) throw new RuntimeException('Choose a student, course, and valid access action.');
        $db->beginTransaction();
        try {
            if ($action==='grant') {
                $stmt=$db->prepare("INSERT INTO student_course_access(student_id,course_id,status,granted_by,granted_at,revoked_by,revoked_at) SELECT ?,?,'active',?,UTC_TIMESTAMP(),NULL,NULL FROM users u JOIN courses c ON c.id=? WHERE u.id=? AND u.role='student' AND u.status='active' AND u.email_verified_at IS NOT NULL ON DUPLICATE KEY UPDATE status='active',granted_by=VALUES(granted_by),granted_at=UTC_TIMESTAMP(),revoked_by=NULL,revoked_at=NULL");
                $stmt->execute([$studentId,$courseId,(int)$admin['id'],$courseId,$studentId]);
                if ($stmt->rowCount()===0) throw new RuntimeException('Active verified student or course not found.');
            } else {
                $stmt=$db->prepare("UPDATE student_course_access SET status='revoked',revoked_by=?,revoked_at=UTC_TIMESTAMP() WHERE student_id=? AND course_id=? AND status='active'"); $stmt->execute([(int)$admin['id'],$studentId,$courseId]);
                if ($stmt->rowCount()===0) throw new RuntimeException('No active access grant exists for that student/course.');
            }
            $this->audit($db,(int)$admin['id'],'course_access.'.$action,'course',$courseId,['student_id'=>$studentId]);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $_SESSION['_flash']='Course access updated.'; $this->redirect('/admin/courses');
    }

    private function csrf(): void { if (!Security::verifyCsrf($_POST['_csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.'); }
    private function audit(PDO $db,int $actor,string $action,string $entity,int $id,array $metadata=[]): void { $stmt=$db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)'); $stmt->execute([$actor,$action,$entity,$id,$metadata?json_encode($metadata,JSON_THROW_ON_ERROR):null]); }
    private function redirect(string $path): never { header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').$path); exit; }
}
