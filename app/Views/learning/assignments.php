<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$assignments = is_array($assignments ?? null) ? $assignments : [];
$message = $message ?? null; ?>
<section class="dashboard-page learning-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
    <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int)$course['id'] ?>"><?= $escape($course['title']) ?></a><i>/</i><strong>Assignments</strong></div>
  </div>
  <header class="dashboard-heading">
    <div>
      <p class="eyebrow">COURSE WORK</p>
      <h1><?= $escape($course['title']) ?> assignments</h1>
      <p>Submit your work and follow review feedback here.</p>
    </div>
  </header><?php \App\Core\View::toast('success', $message ?? null); ?><?php \App\Core\View::toast('error', $error ?? null); ?>
  <?php if ($assignments === []): ?><section class="dashboard-panel">
      <div class="dashboard-empty">
        <div>
          <h2>No assignments available</h2>
          <p>Published course assignments will appear here.</p>
        </div>
      </div>
    </section><?php else: ?><div class="assignment-student-list"><?php foreach ($assignments as $a):
      $isPhp = ($a['assignment_topic'] ?? 'general') === 'php_mysql';
      $effectiveCutoff = $a['admin_cutoff_at'] ?: $a['due_at'];
      $canSubmit = $a['status'] === 'published'
        && (empty($a['submission_id']) || ($a['submission_status'] ?? '') === 'resubmit_requested')
        && ($effectiveCutoff === null || strtotime($effectiveCutoff . ' UTC') >= time());
      $typeLabel = str_replace('_', ' ', ucfirst($a['assignment_type'] ?? 'exercise'));
      $rubricLabels = [
        'rubric_technical' => 'Technical Knowledge',
        'rubric_problem_solving' => 'Problem Solving',
        'rubric_code_quality' => 'Code Quality',
        'rubric_timeliness' => 'Timeliness',
        'rubric_completeness' => 'Completeness',
      ];
      $graded = ($a['submission_status'] ?? '') === 'reviewed' && $a['rubric_technical'] !== null;
    ?>
      <article class="dashboard-panel assignment-student-card">
          <div class="dashboard-panel-heading">
            <div>
              <p class="eyebrow"><?= $escape($typeLabel) ?><?= !empty($a['module_title']) ? ' · ' . $escape($a['module_title']) : ' · WHOLE COURSE' ?><?= $isPhp ? ' · PHP/MYSQL PRACTICE' : '' ?><?= !empty($a['week_number']) ? ' · WEEK ' . (int)$a['week_number'] : '' ?></p>
              <h2><?= $escape($a['title']) ?></h2>
            </div><span class="dashboard-status status-<?= $escape($a['submission_status'] ?? $a['status']) ?>"><?= $escape(str_replace('_', ' ', ucfirst($a['submission_status'] ?? $a['status']))) ?></span>
          </div>
          <p><?= nl2br($escape($a['instructions'] ?? '')) ?></p>
          <div class="quiz-meta"><span>Maximum <?= $escape($a['max_marks']) ?> marks</span><?php if (!empty($a['due_at'])): ?><span>Due <?= $escape(date('M j, Y H:i', strtotime($a['due_at']))) ?></span><?php endif; ?><?php if (!empty($a['admin_cutoff_at'])): ?><span>Closes <?= $escape(date('M j, Y H:i', strtotime($a['admin_cutoff_at']))) ?></span><?php endif; ?><?php if (!empty($a['submitted_late'])): ?><span>Late deduction <?= $escape($a['late_deduction_percent']) ?>%</span><?php endif; ?><?php if (($a['marks_awarded'] ?? null) !== null): ?><span>Score <?= $escape($a['marks_awarded']) ?> / <?= $escape($a['max_marks']) ?></span><?php endif; ?></div>
          <?php if (!empty($a['github_url'])): ?><p class="assignment-submission-link"><strong>Your repository</strong> <a class="dashboard-inline-link" href="<?= $escape($a['github_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= $escape($a['github_url']) ?></a></p><?php endif; ?>
          <?php if (!empty($a['file_path'])): ?><p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning/assignments/file?submission_id=<?= (int)$a['submission_id'] ?>">Download your submitted file</a></p><?php endif; ?>
          <?php if ($graded): ?>
            <ul class="assignment-rubric-result"><?php foreach ($rubricLabels as $column => $label): ?><li><span><?= $escape($label) ?></span><strong><?= $escape($a[$column]) ?>/5</strong></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if (!empty($a['feedback'])): ?><div class="payment-help"><strong>Instructor feedback</strong>
              <p><?= $escape($a['feedback']) ?></p>
            </div><?php endif; ?>
          <?php if ($canSubmit): ?><form method="post" action="<?= $escape($baseUrl) ?>/learning/assignments/submit" enctype="multipart/form-data" class="admin-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>"><label class="admin-label">GitHub repository URL<?= $isPhp ? ' <span class="req">*</span>' : '' ?><input class="admin-input" type="url" name="github_url" maxlength="500" placeholder="https://github.com/username/repository" <?= $isPhp ? 'required' : '' ?>></label><label class="admin-label">Your response<textarea class="admin-textarea" name="submission_text" rows="5" maxlength="50000" placeholder="Write your response, add a GitHub link, or attach a file."></textarea></label><label class="admin-label">Attach file <span>PDF, JPG, PNG, WebP, ZIP or TXT · maximum 10 MB</span><input class="admin-input" type="file" name="submission_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.txt,application/pdf,image/jpeg,image/png,image/webp,application/zip,text/plain"></label><button class="button" type="submit"><?= ($a['submission_status'] ?? '') === 'resubmit_requested' ? 'Submit revised assignment' : 'Submit assignment' ?></button></form><?php elseif (!empty($a['submission_id'])): ?><p class="admin-hint">Submitted <?= $escape(date('M j, Y H:i', strtotime($a['submitted_at']))) ?>.<?php if (($a['submission_status'] ?? '') === 'resubmit_requested'): ?> Use the form above to submit your revised work.<?php endif; ?></p><?php endif; ?>
        </article><?php endforeach; ?></div><?php endif; ?>
</section>