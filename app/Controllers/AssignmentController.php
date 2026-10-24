<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use PDO;
use RuntimeException;

final class AssignmentController
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $courses = $db->query("SELECT id,title FROM courses WHERE visibility IN ('published','draft','hidden') ORDER BY title")->fetchAll();
        $assignments = $db->query('SELECT a.*,c.title AS course_title,(SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id=a.id) AS submission_count FROM assignments a JOIN courses c ON c.id=a.course_id ORDER BY a.id DESC')->fetchAll();
        $submissions = $db->query("SELECT s.*,a.title AS assignment_title,a.max_marks,c.title AS course_title,u.full_name AS student_name,u.email AS student_email FROM assignment_submissions s JOIN assignments a ON a.id=s.assignment_id JOIN courses c ON c.id=a.course_id JOIN users u ON u.id=s.student_id WHERE s.status IN ('submitted','resubmit_requested') ORDER BY s.submitted_at ASC,s.id ASC")->fetchAll();
        View::render('admin/assignments', ['title'=>'Assignment management','user'=>$admin,'courses'=>$courses,'assignments'=>$assignments,'submissions'=>$submissions,'message'=>$_SESSION['_flash']??null]);
        unset($_SESSION['_flash']);
    }

    public function save(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $this->csrf();
        $id=(int)($_POST['id']??0); $courseId=(int)($_POST['course_id']??0); $weekRaw=trim((string)($_POST['week_number']??''));
        $week=$weekRaw===''?null:(int)$weekRaw; $title=trim((string)($_POST['title']??'')); $instructions=trim((string)($_POST['instructions']??''));
        $status=(string)($_POST['status']??'draft'); $marks=(string)($_POST['max_marks']??'100'); $dueAt=$this->dateValue($_POST['due_at']??'');
        if($courseId<1 || ($week!==null&&($week<1||$week>52)) || mb_strlen($title)<3 || mb_strlen($title)>200 || mb_strlen($instructions)>10000 || !is_numeric($marks) || (float)$marks<=0 || (float)$marks>100000 || !in_array($status,['draft','published','closed'],true)) throw new RuntimeException('Enter valid assignment details.');
        $db=Database::connection(); $course=$db->prepare('SELECT id FROM courses WHERE id=?'); $course->execute([$courseId]);
        if(!$course->fetchColumn()) throw new RuntimeException('Select an existing course.');
        if($id>0){$stmt=$db->prepare('UPDATE assignments SET course_id=?,week_number=?,title=?,instructions=?,due_at=?,max_marks=?,status=? WHERE id=?');$stmt->execute([$courseId,$week,$title,$instructions?:null,$dueAt,$marks,$status,$id]);}
        else {$stmt=$db->prepare('INSERT INTO assignments(course_id,week_number,title,instructions,due_at,max_marks,status,created_by) VALUES(?,?,?,?,?,?,?,?)');$stmt->execute([$courseId,$week,$title,$instructions?:null,$dueAt,$marks,$status,(int)$admin['id']]);$id=(int)$db->lastInsertId();}
        $this->audit($db,(int)$admin['id'],'assignment.saved',$id);
        $_SESSION['_flash']='Assignment saved.'; $this->redirect('/admin/assignments#assignment-'.$id);
    }

    public function studentIndex(): void
    {
        $student=AuthMiddleware::requireRole('student'); $courseId=filter_input(INPUT_GET,'course_id',FILTER_VALIDATE_INT)?:0; $db=Database::connection();
        $course=$db->prepare("SELECT c.id,c.title FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.id=? AND a.student_id=? AND a.status='active' LIMIT 1");
        $course->execute([$courseId,(int)$student['id']]); $courseRow=$course->fetch();
        if(!$courseRow){http_response_code(404);View::render('errors/404',['title'=>'Course unavailable','user'=>$student]);return;}
        $stmt=$db->prepare("SELECT a.id,a.week_number,a.title,a.instructions,a.due_at,a.max_marks,s.id AS submission_id,s.submission_text,s.submitted_at,s.status AS submission_status,s.marks_awarded,s.feedback FROM assignments a LEFT JOIN assignment_submissions s ON s.id=(SELECT latest.id FROM assignment_submissions latest WHERE latest.assignment_id=a.id AND latest.student_id=? ORDER BY latest.id DESC LIMIT 1) WHERE a.course_id=? AND a.status IN ('published','closed') ORDER BY a.week_number,a.id");
        $stmt->execute([(int)$student['id'],$courseId]);
        $rows=[]; foreach($stmt->fetchAll() as $row){$key=(int)$row['id']; if(!isset($rows[$key]))$rows[$key]=$row;}
        View::render('learning/assignments',['title'=>'Course assignments','user'=>$student,'course'=>$courseRow,'assignments'=>array_values($rows),'message'=>$_SESSION['_flash']??null]); unset($_SESSION['_flash']);
    }

    public function submit(): void
    {
        $student=AuthMiddleware::requireRole('student'); $this->csrf();
        $assignmentId=(int)($_POST['assignment_id']??0); $text=trim((string)($_POST['submission_text']??''));
        if(mb_strlen($text)>50000) throw new RuntimeException('Submission text must be under 50,000 characters.');
        $db=Database::connection();
        $db->beginTransaction();$stored=null;
        try {
            $stmt=$db->prepare("SELECT a.course_id,a.status,a.due_at FROM assignments a JOIN student_course_access x ON x.course_id=a.course_id AND x.student_id=? AND x.status='active' WHERE a.id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([(int)$student['id'],$assignmentId]); $assignment=$stmt->fetch();
            if(!$assignment || $assignment['status']!=='published' || ($assignment['due_at']!==null && strtotime($assignment['due_at'].' UTC')<time())) throw new RuntimeException('This assignment is unavailable or its deadline has passed.');
            $revisionCount=$db->prepare('SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id=? AND student_id=?');$revisionCount->execute([$assignmentId,(int)$student['id']]);
            if((int)$revisionCount->fetchColumn()>=5)throw new RuntimeException('The maximum of five submissions for this assignment has been reached. Contact your instructor if you need help.');
            $latest=$db->prepare('SELECT status FROM assignment_submissions WHERE assignment_id=? AND student_id=? ORDER BY id DESC LIMIT 1');$latest->execute([$assignmentId,(int)$student['id']]);$priorStatus=$latest->fetchColumn();
            if($priorStatus!==false&&$priorStatus!=='resubmit_requested')throw new RuntimeException('Your latest submission is already under review or has been graded.');
            $upload=$_FILES['submission_file']??null;
            if(is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $stored=$this->storeFile($upload);
            if($text==='' && $stored===null) throw new RuntimeException('Write a response or attach a file before submitting.');
            $insert=$db->prepare("INSERT INTO assignment_submissions(assignment_id,student_id,submission_text,file_path,status) VALUES(?,?,?,?,'submitted')");
            $insert->execute([$assignmentId,(int)$student['id'],$text?:null,$stored['relative']??null]);
            $this->audit($db,(int)$student['id'],'assignment.submitted',(int)$db->lastInsertId());
            $db->commit();
        } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();if($stored)@unlink($stored['absolute']);throw $e;}
        $_SESSION['_flash']='Assignment submitted for review.'; $this->redirect('/learning/assignments?course_id='.(int)$assignment['course_id']);
    }

    public function review(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $this->csrf(); $id=(int)($_POST['submission_id']??0);
        $decision=(string)($_POST['decision']??'reviewed'); $marks=trim((string)($_POST['marks_awarded']??'')); $feedback=trim((string)($_POST['feedback']??''));
        if($id<1||!in_array($decision,['reviewed','resubmit_requested'],true)||!is_numeric($marks)||mb_strlen($feedback)>5000) throw new RuntimeException('Enter a valid review, mark and feedback.');
        $db=Database::connection(); $find=$db->prepare('SELECT a.max_marks FROM assignment_submissions s JOIN assignments a ON a.id=s.assignment_id WHERE s.id=?');$find->execute([$id]);$max=$find->fetchColumn();
        if($max===false||(float)$marks<0||(float)$marks>(float)$max) throw new RuntimeException('Marks must be between zero and the assignment maximum.');
        $update=$db->prepare('UPDATE assignment_submissions SET status=?,marks_awarded=?,feedback=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?');
        $update->execute([$decision,$marks,$feedback?:null,(int)$admin['id'],$id]); $this->audit($db,(int)$admin['id'],'assignment.'.$decision,$id);
        $_SESSION['_flash']=$decision==='reviewed'?'Submission reviewed and marks saved.':'Resubmission requested with feedback.'; $this->redirect('/admin/assignments#submission-'.$id);
    }

    public function file(): void
    {
        $session=$_SESSION['user']??null; if(!is_array($session)||!in_array($session['role']??'',['admin','student'],true))$this->redirect('/login');
        $user=AuthMiddleware::requireRole((string)$session['role']); $id=filter_input(INPUT_GET,'submission_id',FILTER_VALIDATE_INT)?:0; $db=Database::connection();
        $sql='SELECT file_path FROM assignment_submissions WHERE id=?';$params=[$id]; if($user['role']==='student'){$sql.=' AND student_id=?';$params[]=(int)$user['id'];}
        $stmt=$db->prepare($sql.' LIMIT 1');$stmt->execute($params);$relative=$stmt->fetchColumn();
        if(!is_string($relative)||!preg_match('#^assignments/[a-f0-9]{48}\.(pdf|jpg|png|webp|zip|txt)$#',$relative)){http_response_code(404);return;}
        $root=realpath(BASE_PATH.'/storage/uploads/private');$file=$root===false?false:realpath($root.'/'.$relative);
        if($root===false||$file===false||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);return;}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream';header('Content-Type: '.$mime);header('Content-Length: '.(string)filesize($file));header('Content-Disposition: attachment; filename="assignment-submission-'.(int)$id.'.'.pathinfo($file,PATHINFO_EXTENSION).'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($file);
    }

    private function storeFile(array $upload): array
    {
        $tmp=(string)($upload['tmp_name']??'');$actualSize=is_uploaded_file($tmp)?filesize($tmp):false;
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||$actualSize===false||$actualSize<1||$actualSize>self::MAX_UPLOAD_BYTES) throw new RuntimeException('Upload one assignment file up to 10 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);$types=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/zip'=>'zip','text/plain'=>'txt','application/x-zip-compressed'=>'zip'];
        if(!is_string($mime)||!isset($types[$mime]))throw new RuntimeException('Allowed submission formats: PDF, JPG, PNG, WebP, ZIP or TXT.');
        $sample=(string)file_get_contents((string)$upload['tmp_name'],false,null,0,16);
        $signatureMatches=match($mime){
            'application/pdf'=>str_starts_with($sample,'%PDF-'),
            'image/jpeg'=>str_starts_with($sample,"\xFF\xD8\xFF"),
            'image/png'=>str_starts_with($sample,"\x89PNG\r\n\x1A\n"),
            'image/webp'=>substr($sample,0,4)==='RIFF'&&substr($sample,8,4)==='WEBP',
            'application/zip','application/x-zip-compressed'=>str_starts_with($sample,"PK\x03\x04")||str_starts_with($sample,"PK\x05\x06"),
            'text/plain'=>mb_check_encoding((string)file_get_contents((string)$upload['tmp_name']),'UTF-8')&&!str_contains($sample,"\0"),
            default=>false,
        };
        if(!$signatureMatches)throw new RuntimeException('The uploaded file content does not match an allowed document type.');
        $dir=BASE_PATH.'/storage/uploads/private/assignments';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private assignment storage is unavailable.');@chmod($dir,0700);
        $name=bin2hex(random_bytes(24)).'.'.$types[$mime];$path=$dir.'/'.$name;if(!move_uploaded_file((string)$upload['tmp_name'],$path))throw new RuntimeException('The assignment file could not be stored.');@chmod($path,0600);
        return ['absolute'=>$path,'relative'=>'assignments/'.$name];
    }

    private function dateValue(mixed $value): ?string
    {
        $value=trim((string)$value);if($value==='')return null;$date=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value,new \DateTimeZone((require BASE_PATH.'/config/app.php')['timezone']));$errors=\DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count'])))throw new RuntimeException('Enter a valid due date.');return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private function audit(PDO $db,int $actor,string $action,int $id): void {$db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,\'assignment_submission\',?)')->execute([$actor,$action,$id]);}
    private function csrf(): void {if(!Security::verifyCsrf($_POST['_csrf']??null))throw new RuntimeException('Your session expired. Refresh and try again.');}
    private function redirect(string $path): never {header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').$path);exit;}
}
