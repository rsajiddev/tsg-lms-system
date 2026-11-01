<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Env.php';
require BASE_PATH . '/app/Core/Database.php';
require BASE_PATH . '/app/Services/EmailLayout.php';
require BASE_PATH . '/app/Services/EmailMessages.php';
require BASE_PATH . '/app/Services/AccountIdentity.php';
require BASE_PATH . '/app/Services/MailService.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\MailService;

Env::load(BASE_PATH . '/.env');
$app = require BASE_PATH . '/config/app.php';
date_default_timezone_set($app['timezone']);
$db = Database::connection();

// A single worker avoids duplicate delivery if the scheduler starts a second run.
$lock = $db->query("SELECT GET_LOCK('tsg_dashboard_task_reminders', 0)")->fetchColumn();
if ((int) $lock !== 1) {
    fwrite(STDOUT, "Reminder worker already running.\n");
    exit(0);
}

$sent = 0;
$failed = 0;
try {
    $stmt = $db->query(
        'SELECT t.id, t.user_id, t.title, u.email, u.full_name
         FROM dashboard_tasks t
         JOIN users u ON u.id = t.user_id
         WHERE t.is_done = 0 AND t.reminder_at IS NOT NULL
           AND t.reminder_at <= UTC_TIMESTAMP() AND t.reminder_sent_at IS NULL
         ORDER BY t.user_id, t.reminder_at, t.id'
    );
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $groups[(int) $row['user_id']][] = $row;
    $mailer = new MailService($db);
    foreach ($groups as $rows) {
        $email = trim((string) $rows[0]['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }
        $titles = array_map(static fn(array $row): string => (string) $row['title'], $rows);
        if (!$mailer->sendDashboardTaskReminder($email, (string) $rows[0]['full_name'], count($titles), $titles)) { $failed++; continue; }
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $update = $db->prepare("UPDATE dashboard_tasks SET reminder_sent_at = UTC_TIMESTAMP() WHERE is_done = 0 AND reminder_sent_at IS NULL AND id IN ($marks)");
        $update->execute($ids);
        $sent += $update->rowCount();
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('tsg_dashboard_task_reminders')");
}

fwrite(STDOUT, sprintf("Dashboard reminders sent: %d; failed: %d.\n", $sent, $failed));
