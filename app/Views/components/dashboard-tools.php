<?php
use App\Core\Security;
$tasks=is_array($dashboardTasks??null)?$dashboardTasks:[];
$openCount=count(array_filter($tasks,static fn($t)=>(int)$t['is_done']===0));
?>
<aside class="dashboard-tools-rail">
 <section class="dashboard-tool-card dashboard-clock-card"><p class="eyebrow">YOUR LOCAL TIME</p><strong data-live-clock>--:--:--</strong><span data-live-date>Loading date…</span><div class="dashboard-calendar"><span>Today</span><b><?= Security::escape(date('D, M j, Y')) ?></b></div></section>
 <section class="dashboard-tool-card todo-card" id="my-tasks">
  <header>
   <div class="todo-head-main"><span class="todo-head-icon" aria-hidden="true">✓</span><div class="todo-head-text"><p class="eyebrow">Personal organizer</p><h2>My tasks</h2></div></div>
   <span class="todo-count"><b><?= $openCount ?></b> open</span>
  </header>
  <form class="todo-add-form" method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/add"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><label class="sr-only" for="dashboard-task-title">New task</label><input id="dashboard-task-title" name="title" maxlength="240" placeholder="Add a task…" required><button type="submit" aria-label="Add task"><span aria-hidden="true">+</span></button></form>
  <div class="todo-filter"><button type="button" class="is-active" data-task-filter="pending">To do</button><button type="button" data-task-filter="done">Completed</button></div>
  <ul class="todo-list"><?php foreach($tasks as $task): ?><li data-task-row data-task-done="<?= (int)$task['is_done'] ?>"<?= (int)$task['is_done']===1?' hidden':'' ?>>
   <div class="todo-task-main">
    <form class="todo-toggle-form" method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/toggle"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="todo-check<?= (int)$task['is_done']?' is-done':'' ?>" type="submit" aria-label="Toggle task completion"></button></form>
    <span class="todo-title<?= (int)$task['is_done']?' is-done':'' ?>"><?= Security::escape($task['title']) ?><?php if(!empty($task['reminder_at'])&&(int)$task['is_done']===0): ?><span class="todo-reminder-chip"><span aria-hidden="true">⏰</span>Reminder <?= Security::escape(date('M j · g:i A',strtotime($task['reminder_at'].' UTC'))) ?></span><?php endif ?></span>
    <form class="todo-delete-form" method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/delete"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="todo-delete" type="submit" aria-label="Delete task"><span aria-hidden="true">×</span></button></form>
   </div>
   <?php if(!(int)$task['is_done']): ?><details class="todo-reminder"><summary><span class="todo-reminder-plus" aria-hidden="true">🕒</span> Set reminder</summary><form method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/reminder"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><label>Remind me at<input type="datetime-local" name="reminder_at" required></label><button class="button" type="submit">Save reminder</button></form></details><?php endif ?>
  </li><?php endforeach ?></ul>
  <?php if($tasks===[]): ?><p class="todo-empty"><span aria-hidden="true">🗒</span> Add a task to keep it on your dashboard.</p><?php endif ?>
  <p class="todo-email-note"><span aria-hidden="true">✉</span> Alerts are emailed to your account address at the selected time.</p>
 </section>
</aside>
