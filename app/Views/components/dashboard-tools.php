<?php
use App\Core\Security;
$tasks=is_array($dashboardTasks??null)?$dashboardTasks:[];
?>
<aside class="dashboard-tools-rail">
 <section class="dashboard-tool-card dashboard-clock-card"><p class="eyebrow">YOUR LOCAL TIME</p><strong data-live-clock>--:--:--</strong><span data-live-date>Loading date…</span><div class="dashboard-calendar"><span>Today</span><b><?= Security::escape(date('D, M j, Y')) ?></b></div></section>
 <section class="dashboard-tool-card todo-card" id="my-tasks"><header><div><p class="eyebrow">PERSONAL ORGANIZER</p><h2>My tasks</h2></div><span class="todo-count"><?= count(array_filter($tasks,static fn($t)=>(int)$t['is_done']===0)) ?> open</span></header>
  <form class="todo-add-form" method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/add"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><label class="sr-only" for="dashboard-task-title">New task</label><input id="dashboard-task-title" name="title" maxlength="240" placeholder="Add a task…" required><button type="submit" aria-label="Add task">+</button></form>
  <div class="todo-filter"><button type="button" class="is-active" data-task-filter="pending">To do</button><button type="button" data-task-filter="done">Completed</button></div>
  <ul class="todo-list"><?php foreach($tasks as $task): ?><li data-task-row data-task-done="<?= (int)$task['is_done'] ?>">
   <div class="todo-task-main">
    <form method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/toggle"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="todo-check<?= (int)$task['is_done']?' is-done':'' ?>" type="submit" aria-label="Toggle task completion"></button></form>
    <span class="todo-title<?= (int)$task['is_done']?' is-done':'' ?>"><?= Security::escape($task['title']) ?><?php if(!empty($task['reminder_at'])&&(int)$task['is_done']===0): ?><small>Reminder <?= Security::escape(date('M j · g:i A',strtotime($task['reminder_at'].' UTC'))) ?></small><?php endif ?></span>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/delete"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="todo-delete" type="submit" aria-label="Delete task">×</button></form>
   </div>
   <?php if(!(int)$task['is_done']): ?><details class="todo-reminder"><summary>Add alert</summary><form method="post" action="<?= Security::escape($baseUrl) ?>/dashboard/tasks/reminder"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><label>Remind me<input type="datetime-local" name="reminder_at" required></label><button class="button" type="submit">Set alert</button></form></details><?php endif ?>
  </li><?php endforeach ?></ul>
  <?php if($tasks===[]): ?><p class="todo-empty">Add a task to keep it on your dashboard.</p><?php endif ?>
  <p class="todo-email-note">Alerts are emailed to your account address at the selected time.</p>
 </section>
</aside>
