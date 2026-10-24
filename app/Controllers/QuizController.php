<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
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
        $questions = $db->query('SELECT qq.id,qq.quiz_id,qq.question_text,qq.marks,qq.sort_order,q.title AS quiz_title FROM quiz_questions qq JOIN quizzes q ON q.id=qq.quiz_id ORDER BY q.id,qq.sort_order,qq.id')->fetchAll();
        $optionStmt=$db->prepare('SELECT option_text,is_correct FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');
        foreach ($questions as &$question) { $optionStmt->execute([(int)$question['id']]); $question['options']=$optionStmt->fetchAll(); }
        unset($question);
        View::render('admin/quizzes', ['title'=>'Quiz management','user'=>$admin,'courses'=>$courses,'quizzes'=>$quizzes,'questions'=>$questions,'message'=>$_SESSION['_flash']??null]);
        unset($_SESSION['_flash']);
    }

    public function saveQuiz(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $id=(int)($_POST['id']??0); $courseId=(int)($_POST['course_id']??0); $week=(int)($_POST['week_number']??0);
        $title=trim((string)($_POST['title']??'')); $instructions=trim((string)($_POST['instructions']??''));
        $status=(string)($_POST['status']??'draft'); $minutes=(int)($_POST['time_limit_minutes']??20);
        $questionCount=(int)($_POST['question_count']??75); $passMark=(string)($_POST['pass_mark_percent']??'50'); $attemptLimit=(int)($_POST['attempt_limit']??1);
        $unlock=$this->dateValue($_POST['unlock_at']??''); $close=$this->dateValue($_POST['close_at']??'');
        if ($courseId<1 || $week<1 || $week>52 || mb_strlen($title)<3 || mb_strlen($title)>200 || mb_strlen($instructions)>10000
            || !in_array($status,['draft','scheduled','released','closed'],true) || $minutes<1 || $minutes>600
            || ($id===0 && $status!=='draft') || $questionCount<1 || $questionCount>75 || !is_numeric($passMark) || (float)$passMark<0 || (float)$passMark>100
            || $attemptLimit<1 || $attemptLimit>10 || ($status==='scheduled' && $unlock===null) || ($unlock!==null && $close!==null && $close<=$unlock)) {
            throw new RuntimeException('Enter valid quiz details. Question count must be 1–75, duration 1–600 minutes, pass mark 0–100%, and attempts 1–10.');
        }
        $db=Database::connection();
        $course=$db->prepare('SELECT id FROM courses WHERE id=?'); $course->execute([$courseId]);
        if (!$course->fetchColumn()) throw new RuntimeException('Select an existing course.');
        if ($id>0) {
            $questionTotal=$db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?'); $questionTotal->execute([$id]);
            if ((int)$questionTotal->fetchColumn()>$questionCount) throw new RuntimeException('Question count cannot be lower than the questions already saved.');
        }
        if (in_array($status,['scheduled','released'],true)) $this->assertReadyForRelease($db,$id,$questionCount);
        if ($id>0) {
            $existing=$db->prepare('SELECT course_id,week_number FROM quizzes WHERE id=?'); $existing->execute([$id]);
            $prior=$existing->fetch();
            if (!$prior) throw new RuntimeException('Quiz not found.');
            if ((int)$prior['course_id']!==$courseId || (int)$prior['week_number']!==$week) {
                $conflict=$db->prepare('SELECT id FROM quizzes WHERE course_id=? AND week_number=? AND id<>?'); $conflict->execute([$courseId,$week,$id]);
                if ($conflict->fetchColumn()) throw new RuntimeException('This course already has a quiz for that week.');
            }
            $stmt=$db->prepare('UPDATE quizzes SET course_id=?,week_number=?,title=?,instructions=?,status=?,time_limit_seconds=?,question_count=?,pass_mark_percent=?,attempt_limit=?,unlock_at=?,close_at=? WHERE id=?');
            $stmt->execute([$courseId,$week,$title,$instructions?:null,$status,$minutes*60,$questionCount,$passMark,$attemptLimit,$unlock,$close,$id]);
            $quizId=$id;
        } else {
            $stmt=$db->prepare('INSERT INTO quizzes(course_id,week_number,title,instructions,status,time_limit_seconds,question_count,pass_mark_percent,attempt_limit,unlock_at,close_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId,$week,$title,$instructions?:null,$status,$minutes*60,$questionCount,$passMark,$attemptLimit,$unlock,$close,(int)$admin['id']]);
            $quizId=(int)$db->lastInsertId();
        }
        $this->audit($db,(int)$admin['id'],'quiz.saved',$quizId,['course_id'=>$courseId,'week_number'=>$week,'status'=>$status]);
        $_SESSION['_flash']='Quiz saved.';
        $this->redirect('/admin/quizzes#quiz-'.$quizId);
    }

    public function saveQuestion(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $this->csrf();
        $quizId=(int)($_POST['quiz_id']??0); $questionId=(int)($_POST['question_id']??0);
        $text=trim((string)($_POST['question_text']??'')); $marks=(string)($_POST['marks']??'1');
        $rawOptions=$_POST['options']??[]; $correct=(int)($_POST['correct_option']??-1);
        if ($quizId<1 || mb_strlen($text)<3 || mb_strlen($text)>10000 || !is_numeric($marks) || (float)$marks<=0 || (float)$marks>1000
            || !is_array($rawOptions) || count($rawOptions)!==4 || $correct<0 || $correct>3) throw new RuntimeException('Each question needs text, positive marks, four options, and exactly one correct answer.');
        $options=[];
        foreach (array_values($rawOptions) as $option) {
            $option=trim((string)$option);
            if ($option==='' || mb_strlen($option)>2000) throw new RuntimeException('All four answer options are required and must be under 2,000 characters.');
            $options[]=$option;
        }
        $db=Database::connection();
        $quiz=$db->prepare('SELECT id,status,question_count FROM quizzes WHERE id=?'); $quiz->execute([$quizId]); $quizRow=$quiz->fetch();
        if (!$quizRow || $quizRow['status']!=='draft') throw new RuntimeException('Questions can only be changed while the quiz is in draft.');
        $attempts=$db->prepare('SELECT 1 FROM quiz_attempts WHERE quiz_id=? LIMIT 1'); $attempts->execute([$quizId]);
        if ($attempts->fetchColumn()) throw new RuntimeException('Questions are locked after a student has started an attempt.');
        $db->beginTransaction();
        try {
            if ($questionId>0) {
                $belongs=$db->prepare('SELECT id FROM quiz_questions WHERE id=? AND quiz_id=?'); $belongs->execute([$questionId,$quizId]);
                if (!$belongs->fetchColumn()) throw new RuntimeException('Question does not belong to this quiz.');
                $update=$db->prepare('UPDATE quiz_questions SET question_text=?,marks=? WHERE id=?'); $update->execute([$text,$marks,$questionId]);
                $db->prepare('DELETE FROM quiz_options WHERE question_id=?')->execute([$questionId]);
            } else {
                $count=$db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?'); $count->execute([$quizId]);
                if ((int)$count->fetchColumn()>=(int)$quizRow['question_count']) throw new RuntimeException('The configured question count has been reached. Edit the quiz settings before adding more questions.');
                $max=$db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id=?'); $max->execute([$quizId]);
                $insert=$db->prepare('INSERT INTO quiz_questions(quiz_id,question_text,marks,sort_order) VALUES(?,?,?,?)'); $insert->execute([$quizId,$text,$marks,(int)$max->fetchColumn()]);
                $questionId=(int)$db->lastInsertId();
            }
            $optionInsert=$db->prepare('INSERT INTO quiz_options(question_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)');
            foreach ($options as $index=>$option) $optionInsert->execute([$questionId,$option,$index===$correct?1:0,$index+1]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $this->audit($db,(int)$admin['id'],'quiz.question_saved',$questionId,['quiz_id'=>$quizId]);
        $_SESSION['_flash']='Question saved with four options and one answer key.';
        $this->redirect('/admin/quizzes#quiz-'.$quizId);
    }

    public function courseQuizzes(): void
    {
        $student=AuthMiddleware::requireRole('student');
        $courseId=filter_input(INPUT_GET,'course_id',FILTER_VALIDATE_INT)?:0;
        $db=Database::connection();
        $course=$db->prepare("SELECT c.id,c.title FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.id=? AND a.student_id=? AND a.status='active' LIMIT 1");
        $course->execute([$courseId,(int)$student['id']]); $courseRow=$course->fetch();
        if (!$courseRow) { http_response_code(404); View::render('errors/404',['title'=>'Course unavailable','user'=>$student]); return; }
        $stmt=$db->prepare("SELECT q.id,q.week_number,q.title,q.instructions,q.time_limit_seconds,q.question_count,q.attempt_limit,q.unlock_at,q.close_at,(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS actual_question_count,(SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=?) AS used_attempts,(SELECT qa.id FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=? AND qa.status='in_progress' ORDER BY qa.id DESC LIMIT 1) AS active_attempt FROM quizzes q WHERE q.course_id=? AND (q.status='released' OR (q.status='scheduled' AND q.unlock_at IS NOT NULL AND q.unlock_at<=UTC_TIMESTAMP())) AND (q.unlock_at IS NULL OR q.unlock_at<=UTC_TIMESTAMP()) AND (q.close_at IS NULL OR q.close_at>UTC_TIMESTAMP()) ORDER BY q.week_number,q.id");
        $stmt->execute([(int)$student['id'],(int)$student['id'],$courseId]);
        View::render('learning/quizzes',['title'=>'Course quizzes','user'=>$student,'course'=>$courseRow,'quizzes'=>$stmt->fetchAll()]);
    }

    public function start(): void
    {
        $student=AuthMiddleware::requireRole('student'); $this->csrf();
        $quizId=(int)($_POST['quiz_id']??0); $db=Database::connection(); $studentId=(int)$student['id'];
        $db->beginTransaction();
        try {
            $stmt=$db->prepare("SELECT q.* FROM quizzes q JOIN student_course_access a ON a.course_id=q.course_id AND a.student_id=? AND a.status='active' WHERE q.id=? AND (q.status='released' OR (q.status='scheduled' AND q.unlock_at IS NOT NULL AND q.unlock_at<=UTC_TIMESTAMP())) AND (q.unlock_at IS NULL OR q.unlock_at<=UTC_TIMESTAMP()) AND (q.close_at IS NULL OR q.close_at>UTC_TIMESTAMP()) FOR UPDATE");
            $stmt->execute([$studentId,$quizId]); $quiz=$stmt->fetch();
            if (!$quiz) throw new RuntimeException('This quiz is unavailable or you do not have course access.');
            $active=$db->prepare("SELECT id,deadline_at FROM quiz_attempts WHERE quiz_id=? AND student_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $active->execute([$quizId,$studentId]); $existing=$active->fetch();
            if ($existing && strtotime($existing['deadline_at'].' UTC')>time()) {
                $db->commit(); $this->redirect('/learning/quiz/attempt?attempt_id='.(int)$existing['id']);
            }
            if ($existing) $this->finishAttempt($db,(int)$existing['id'],[], 'deadline', true);
            $used=$db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND student_id=?'); $used->execute([$quizId,$studentId]);
            if ((int)$used->fetchColumn()>=(int)$quiz['attempt_limit']) throw new RuntimeException('You have used all attempts allowed for this quiz.');
            $questionCheck=$db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?'); $questionCheck->execute([$quizId]);
            if ((int)$questionCheck->fetchColumn()!==(int)$quiz['question_count']) throw new RuntimeException('This quiz is not ready yet. Contact the Academy team.');
            $start=$db->prepare('INSERT INTO quiz_attempts(quiz_id,student_id,started_at,deadline_at) VALUES(?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))');
            $start->execute([$quizId,$studentId,(int)$quiz['time_limit_seconds']]); $attemptId=(int)$db->lastInsertId();
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $this->redirect('/learning/quiz/attempt?attempt_id='.$attemptId);
    }

    public function attempt(): void
    {
        $student=AuthMiddleware::requireRole('student'); $attemptId=filter_input(INPUT_GET,'attempt_id',FILTER_VALIDATE_INT)?:0;
        $db=Database::connection(); $attempt=$this->ownedAttempt($db,$attemptId,(int)$student['id'],false,true);
        if (!$attempt) { http_response_code(404); View::render('errors/404',['title'=>'Quiz attempt unavailable','user'=>$student]); return; }
        if ($attempt['status']!=='in_progress') $this->redirect('/learning/quiz/result?attempt_id='.$attemptId);
        if (strtotime($attempt['deadline_at'].' UTC')<=time()) {
            $db->beginTransaction();
            try { $this->finishAttempt($db,$attemptId,[],'deadline',true); $db->commit(); }
            catch (\Throwable $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
            $this->redirect('/learning/quiz/result?attempt_id='.$attemptId);
        }
        $questions=$db->prepare('SELECT id,question_text,marks,sort_order FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order,id'); $questions->execute([(int)$attempt['quiz_id']]);
        $optionStmt=$db->prepare('SELECT id,option_text,sort_order FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');
        $items=$questions->fetchAll();
        foreach ($items as &$question) { $optionStmt->execute([(int)$question['id']]); $question['options']=$optionStmt->fetchAll(); }
        unset($question);
        $invalidQuestion=count($items)!==(int)$attempt['question_count'];
        foreach ($items as $item) if (count($item['options'])!==4) $invalidQuestion=true;
        if ($invalidQuestion) throw new RuntimeException('Quiz content is incomplete. Contact the Academy team.');
        View::render('learning/quiz-attempt',['title'=>$attempt['title'],'user'=>$student,'attempt'=>$attempt,'questions'=>$items,'deadlineEpoch'=>(int)strtotime($attempt['deadline_at'].' UTC')]);
    }

    public function submit(): void
    {
        $student=AuthMiddleware::requireRole('student'); $this->csrf();
        $attemptId=(int)($_POST['attempt_id']??0); $reason=(string)($_POST['submission_reason']??'manual');
        if (!in_array($reason,['manual','tab_hidden','focus_lost','navigation','deadline'],true)) $reason='manual';
        $raw=$_POST['answers']??[]; $answers=is_array($raw)?$raw:[];
        $db=Database::connection(); $db->beginTransaction();
        try {
            $attempt=$this->ownedAttempt($db,$attemptId,(int)$student['id'],true,true);
            if (!$attempt) throw new RuntimeException('Quiz attempt not found.');
            if ($attempt['status']==='in_progress') $this->finishAttempt($db,$attemptId,$answers,$reason,false);
            $db->commit();
        } catch (\Throwable $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
        $this->redirect('/learning/quiz/result?attempt_id='.$attemptId);
    }

    public function result(): void
    {
        $student=AuthMiddleware::requireRole('student'); $attemptId=filter_input(INPUT_GET,'attempt_id',FILTER_VALIDATE_INT)?:0;
        $db=Database::connection(); $attempt=$this->ownedAttempt($db,$attemptId,(int)$student['id']);
        if (!$attempt) { http_response_code(404); View::render('errors/404',['title'=>'Quiz result unavailable','user'=>$student]); return; }
        if ($attempt['status']==='in_progress') $this->redirect('/learning/quiz/attempt?attempt_id='.$attemptId);
        View::render('learning/quiz-result',['title'=>'Quiz result','user'=>$student,'attempt'=>$attempt]);
    }

    private function finishAttempt(PDO $db,int $attemptId,array $submitted,string $reason,bool $forceTimeout): void
    {
        $stmt=$db->prepare("SELECT qa.id,qa.quiz_id,qa.deadline_at,qa.status,q.pass_mark_percent FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id WHERE qa.id=? FOR UPDATE");
        $stmt->execute([$attemptId]); $attempt=$stmt->fetch();
        if (!$attempt || $attempt['status']!=='in_progress') return;
        $timedOut=$forceTimeout || strtotime($attempt['deadline_at'].' UTC')<=time();
        if ($timedOut) $reason='deadline';
        $questions=$db->prepare('SELECT id,marks FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order,id'); $questions->execute([(int)$attempt['quiz_id']]);
        $optionStmt=$db->prepare('SELECT id,is_correct FROM quiz_options WHERE question_id=?');
        $insert=$db->prepare('INSERT INTO quiz_answers(attempt_id,question_id,selected_option_id,awarded_marks) VALUES(?,?,?,?)');
        $score=0.0; $total=0.0; $correct=0;
        foreach ($questions->fetchAll() as $question) {
            $qid=(int)$question['id']; $marks=(float)$question['marks']; $total+=$marks;
            $optionStmt->execute([$qid]); $valid=[]; $correctId=null;
            foreach ($optionStmt->fetchAll() as $option) { $valid[(int)$option['id']]=true; if ((int)$option['is_correct']===1) $correctId=(int)$option['id']; }
            $selected=filter_var($submitted[(string)$qid]??null,FILTER_VALIDATE_INT);
            if ($selected===false || $selected===null || !isset($valid[$selected])) $selected=null;
            $awarded=$selected!==null && $selected===$correctId ? $marks : 0.0;
            if ($awarded>0) $correct++;
            $score+=$awarded; $insert->execute([$attemptId,$qid,$selected,$awarded]);
        }
        $percentage=$total>0?round(($score/$total)*100,2):0.0;
        $status=$timedOut?'timed_out':($reason==='manual'?'submitted':'auto_submitted');
        $update=$db->prepare('UPDATE quiz_attempts SET submitted_at=UTC_TIMESTAMP(),status=?,score=?,total_marks=?,percentage=?,correct_count=?,result=?,submission_reason=? WHERE id=? AND status=\'in_progress\'');
        $update->execute([$status,$score,$total,$percentage,$correct,$percentage>=(float)$attempt['pass_mark_percent']?'pass':'fail',$reason,$attemptId]);
    }

    private function ownedAttempt(PDO $db,int $attemptId,int $studentId,bool $lock=false,bool $requireCurrentAccess=false): ?array
    {
        $accessJoin=$requireCurrentAccess?" JOIN student_course_access a ON a.student_id=qa.student_id AND a.course_id=q.course_id AND a.status='active'":'';
        $stmt=$db->prepare('SELECT qa.*,q.title,q.week_number,q.course_id,q.time_limit_seconds,q.question_count,q.pass_mark_percent,c.title AS course_title FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id'.$accessJoin.' WHERE qa.id=? AND qa.student_id=?'.($lock?' FOR UPDATE':''));
        $stmt->execute([$attemptId,$studentId]); return $stmt->fetch()?:null;
    }

    private function assertReadyForRelease(PDO $db,int $quizId,int $expected): void
    {
        if ($quizId<1) throw new RuntimeException('Save the quiz as a draft and add its questions before releasing it.');
        $count=$db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?'); $count->execute([$quizId]);
        if ((int)$count->fetchColumn()!==$expected) throw new RuntimeException('A quiz can only be released when its configured number of questions is complete.');
        $options=$db->prepare('SELECT COUNT(*),SUM(CASE WHEN option_count=4 AND correct_count=1 THEN 1 ELSE 0 END) FROM (SELECT qq.id,COUNT(qo.id) AS option_count,SUM(CASE WHEN qo.is_correct=1 THEN 1 ELSE 0 END) AS correct_count FROM quiz_questions qq LEFT JOIN quiz_options qo ON qo.question_id=qq.id WHERE qq.quiz_id=? GROUP BY qq.id) qcheck');
        $options->execute([$quizId]); $result=$options->fetch(PDO::FETCH_NUM);
        if (!$result || (int)$result[0]!==$expected || (int)$result[1]!==$expected) throw new RuntimeException('Every question must have exactly four options and exactly one correct answer before release.');
    }

    private function dateValue(mixed $value): ?string
    {
        $value=trim((string)$value); if ($value==='') return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone((require BASE_PATH.'/config/app.php')['timezone']));
        $errors=\DateTimeImmutable::getLastErrors();
        if (!$date || ($errors!==false && ($errors['warning_count']>0 || $errors['error_count']>0))) throw new RuntimeException('Enter valid quiz release and closing dates.');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function audit(PDO $db,int $actor,string $action,int $entity,array $metadata): void
    {
        $stmt=$db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,\'quiz\',?,?)');
        $stmt->execute([$actor,$action,$entity,json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }

    private function csrf(): void { if (!Security::verifyCsrf($_POST['_csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.'); }

    private function redirect(string $path): never
    {
        header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').$path); exit;
    }
}
