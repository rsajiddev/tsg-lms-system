<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;
use App\Services\PaymentReceiptJpg;
use App\Services\PaymentReceiptPdf;
use PDO;

final class ReportsController
{
    private const TYPES = ['students', 'attendance', 'quizzes', 'assignments', 'payments', 'all'];

    public function index(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $period = $this->period($_GET['period'] ?? 'monthly');
        $reports = $this->reports(Database::connection(), $period);
        View::render('admin/reports', [
            'title' => 'Reports', 'user' => $admin, 'period' => $period,
            'reports' => $reports, 'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }

    public function download(): void
    {
        AuthMiddleware::requireRole('admin');
        $type = (string)($_GET['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) { http_response_code(404); return; }
        $period = $this->period($_GET['period'] ?? 'monthly');
        $reports = $this->reports(Database::connection(), $period);
        $report = $type === 'all' ? $this->consolidated($reports, $period) : $reports[$type];
        $report['type'] = $type;
        $pdf = PaymentReceiptPdf::fromJpeags(PaymentReceiptJpg::renderReport($report));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="techsg-' . $type . '-' . $period['key'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    /**
     * A single summarised export that rolls every desk into one report (payments are kept out of
     * this pack): the headline figure from each section becomes a summary tile, and each section's
     * metrics are listed as Section / Measure / Value rows so nothing is left out.
     */
    private function consolidated(array $reports, array $period): array
    {
        $sections = ['students' => 'Students', 'attendance' => 'Attendance', 'quizzes' => 'Quizzes', 'assignments' => 'Assignments'];
        $metrics = [];
        $rows = [];
        foreach ($sections as $key => $name) {
            $report = $reports[$key] ?? [];
            $sectionMetrics = array_slice(is_array($report['metrics'] ?? null) ? $report['metrics'] : [], 0, 3);
            foreach ($sectionMetrics as $index => $metric) {
                $label = (string) ($metric['label'] ?? '');
                $rows[] = [$name, $label, (string) ($metric['value'] ?? '—')];
                if ($index === 0) $metrics[] = ['label' => $name . ' · ' . $label, 'value' => (string) ($metric['value'] ?? '—')];
            }
        }
        return [
            'type' => 'all',
            'period' => $period['label'],
            'period_range' => $period['start_date'] . ' – ' . $period['end_date'],
            'title' => 'Consolidated academy report',
            'generated_at' => date('M j, Y · g:i A T'),
            'metrics' => $metrics,
            'chart' => [],
            'columns' => ['Section', 'Measure', 'Value'],
            'rows' => $rows,
        ];
    }

    private function period(mixed $value): array
    {
        $key = $value === 'weekly' ? 'weekly' : 'monthly';
        $zone = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $zone);
        $start = $key === 'weekly' ? $now->modify('monday this week')->setTime(0, 0) : $now->modify('first day of this month')->setTime(0, 0);
        $end = $key === 'weekly' ? $start->modify('+7 days') : $start->modify('+1 month');
        return ['key' => $key, 'label' => ucfirst($key), 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'), 'start_date' => $start->format('M j, Y'), 'end_date' => $end->modify('-1 second')->format('M j, Y')];
    }

    private function reports(PDO $db, array $period): array
    {
        $from = $period['start']; $until = $period['end'];
        $attendanceSummary = $db->prepare("SELECT COUNT(*) AS total, SUM(status='present') AS present, SUM(status='absent') AS absent, SUM(status='pending') AS pending FROM attendance_records WHERE attendance_date>=DATE(?) AND attendance_date<DATE(?)");
        $attendanceSummary->execute([$from, $until]);
        $attendanceTotals = $attendanceSummary->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentRows = $db->prepare("SELECT u.full_name,u.email,u.status,u.last_login_at,COUNT(a.id) AS marked_days,SUM(a.status='present') AS present_days,ROUND(100*SUM(a.status='present')/NULLIF(COUNT(a.id),0),1) AS attendance_rate FROM users u LEFT JOIN attendance_records a ON a.student_id=u.id AND a.attendance_date>=DATE(?) AND a.attendance_date<DATE(?) WHERE u.role='student' GROUP BY u.id ORDER BY attendance_rate DESC,u.full_name ASC LIMIT 60");
        $studentRows->execute([$from, $until]);
        $studentTotal = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
        $studentStatusCounts = $db->query("SELECT status,COUNT(*) AS total FROM users WHERE role='student' GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $attendanceRows = $db->prepare("SELECT a.attendance_date,u.full_name,a.status,a.first_login_at,a.marked_at FROM attendance_records a JOIN users u ON u.id=a.student_id WHERE a.attendance_date>=DATE(?) AND a.attendance_date<DATE(?) ORDER BY a.attendance_date DESC,u.full_name LIMIT 60");
        $attendanceRows->execute([$from, $until]);
        $quizRows = $db->prepare("SELECT u.full_name,c.title AS course_title,q.title AS quiz_title,qa.score,qa.total_marks,qa.percentage,qa.result,qa.submitted_at FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id WHERE qa.submitted_at>=? AND qa.submitted_at<? AND qa.status IN ('submitted','auto_submitted','timed_out') ORDER BY qa.submitted_at DESC LIMIT 60");
        $quizRows->execute([$from, $until]);
        $assignmentRows = $db->prepare("SELECT u.full_name,c.title AS course_title,a.title AS assignment_title,s.status,s.marks_awarded,a.max_marks,s.submitted_at FROM assignment_submissions s JOIN users u ON u.id=s.student_id JOIN assignments a ON a.id=s.assignment_id JOIN courses c ON c.id=a.course_id WHERE s.submitted_at>=? AND s.submitted_at<? ORDER BY s.submitted_at DESC LIMIT 60");
        $assignmentRows->execute([$from, $until]);
        $paymentRows = $db->prepare("SELECT u.full_name,c.title AS course_title,p.amount,p.currency,p.transaction_reference,COALESCE(p.reviewed_at,p.created_at) AS paid_at FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id WHERE p.status='approved' AND COALESCE(p.reviewed_at,p.created_at)>=? AND COALESCE(p.reviewed_at,p.created_at)<? ORDER BY COALESCE(p.reviewed_at,p.created_at) DESC LIMIT 60");
        $paymentRows->execute([$from, $until]);
        $paymentData = $paymentRows->fetchAll(PDO::FETCH_ASSOC);
        $quizData = $quizRows->fetchAll(PDO::FETCH_ASSOC);
        $assignmentData = $assignmentRows->fetchAll(PDO::FETCH_ASSOC);
        $studentData = $studentRows->fetchAll(PDO::FETCH_ASSOC);
        $attendanceData = $attendanceRows->fetchAll(PDO::FETCH_ASSOC);
        $paidTotal = [];
        foreach ($paymentData as $row) $paidTotal[$row['currency']] = ($paidTotal[$row['currency']] ?? 0) + (float)$row['amount'];
        $passCount = count(array_filter($quizData, static fn($row): bool => ($row['result'] ?? '') === 'pass'));
        $assignmentReviewed = count(array_filter($assignmentData, static fn($row): bool => ($row['status'] ?? '') === 'reviewed'));
        $dateLabel = $period['start_date'] . ' – ' . $period['end_date'];
        return [
            'students' => ['type'=>'students','period'=>$period['label'],'period_range'=>$dateLabel,'title'=>'Student activity and attendance','generated_at'=>date('M j, Y · g:i A T'),'metrics'=>[['label'=>'Student accounts','value'=>(string)$studentTotal],['label'=>'Present days','value'=>(string)(int)($attendanceTotals['present'] ?? 0)],['label'=>'Attendance rate','value'=>((int)($attendanceTotals['total'] ?? 0) ? number_format(100*(int)($attendanceTotals['present'] ?? 0)/(int)$attendanceTotals['total'],1).'%' : '—')]],'chart'=>array_map(static fn($state,$count)=>['label'=>ucfirst((string)$state).' accounts','value'=>(float)$count],array_keys($studentStatusCounts),array_values($studentStatusCounts)),'columns'=>['Student','Email','State','Present days','Attendance'],'rows'=>array_map(static fn($r)=>[$r['full_name'],$r['email'],$r['status'],(string)(int)$r['present_days'],($r['attendance_rate'] ?? null) !== null ? $r['attendance_rate'].'%' : '—'],$studentData)],
            'attendance' => ['type'=>'attendance','period'=>$period['label'],'period_range'=>$dateLabel,'title'=>'Attendance report','generated_at'=>date('M j, Y · g:i A T'),'metrics'=>[['label'=>'Records','value'=>(string)(int)($attendanceTotals['total'] ?? 0)],['label'=>'Present','value'=>(string)(int)($attendanceTotals['present'] ?? 0)],['label'=>'Absent','value'=>(string)(int)($attendanceTotals['absent'] ?? 0)]],'chart'=>[['label'=>'Present','value'=>(float)($attendanceTotals['present'] ?? 0)],['label'=>'Absent','value'=>(float)($attendanceTotals['absent'] ?? 0)],['label'=>'Pending','value'=>(float)($attendanceTotals['pending'] ?? 0)]],'columns'=>['Date','Student','Status','First login','Confirmed'],'rows'=>array_map(static fn($r)=>[$r['attendance_date'],$r['full_name'],ucfirst($r['status']),$r['first_login_at'],$r['marked_at'] ?: '—'],$attendanceData)],
            'quizzes' => ['type'=>'quizzes','period'=>$period['label'],'period_range'=>$dateLabel,'title'=>'Quiz performance report','generated_at'=>date('M j, Y · g:i A T'),'metrics'=>[['label'=>'Submitted attempts','value'=>(string)count($quizData)],['label'=>'Passed','value'=>(string)$passCount],['label'=>'Failed','value'=>(string)(count($quizData)-$passCount)]],'chart'=>[['label'=>'Passed attempts','value'=>(float)$passCount],['label'=>'Failed attempts','value'=>(float)(count($quizData)-$passCount)]],'columns'=>['Student','Course','Quiz','Score','Result'],'rows'=>array_map(static fn($r)=>[$r['full_name'],$r['course_title'],$r['quiz_title'],($r['score'] ?? '—').'/'.($r['total_marks'] ?? '—'),strtoupper((string)($r['result'] ?? 'pending'))],$quizData)],
            'assignments' => ['type'=>'assignments','period'=>$period['label'],'period_range'=>$dateLabel,'title'=>'Assignment submissions report','generated_at'=>date('M j, Y · g:i A T'),'metrics'=>[['label'=>'Submissions','value'=>(string)count($assignmentData)],['label'=>'Reviewed','value'=>(string)$assignmentReviewed],['label'=>'Awaiting review','value'=>(string)(count($assignmentData)-$assignmentReviewed)]],'chart'=>[['label'=>'Reviewed','value'=>(float)$assignmentReviewed],['label'=>'Awaiting review','value'=>(float)(count($assignmentData)-$assignmentReviewed)]],'columns'=>['Student','Course','Assignment','Status','Marks'],'rows'=>array_map(static fn($r)=>[$r['full_name'],$r['course_title'],$r['assignment_title'],ucwords(str_replace('_',' ',$r['status'])),($r['marks_awarded'] ?? '—').'/'.$r['max_marks']],$assignmentData)],
            'payments' => ['type'=>'payments','period'=>$period['label'],'period_range'=>$dateLabel,'title'=>'Approved payment report','generated_at'=>date('M j, Y · g:i A T'),'metrics'=>[['label'=>'Approved payments','value'=>(string)count($paymentData)],['label'=>'Currencies','value'=>(string)count($paidTotal)],['label'=>'Revenue','value'=>implode(' · ',array_map(static fn($c,$v)=>$c.' '.number_format($v,2),array_keys($paidTotal),array_values($paidTotal))) ?: '0.00']],'chart'=>array_map(static fn($currency,$value)=>['label'=>$currency.' revenue','value'=>(float)$value],array_keys($paidTotal),array_values($paidTotal)),'columns'=>['Student','Course','Amount','Reference','Paid on'],'rows'=>array_map(static fn($r)=>[$r['full_name'],$r['course_title'],$r['currency'].' '.number_format((float)$r['amount'],2),$r['transaction_reference'],$r['paid_at']],$paymentData)],
        ];
    }
}
