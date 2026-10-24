<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$courses = is_array($courses ?? null) ? $courses : [];
$assignments = is_array($assignments ?? null) ? $assignments : [];
$submissions = is_array($submissions ?? null) ? $submissions : [];
$message = $message ?? null;
?>
<section class="dashboard-page admin-workspace assignment-admin-page">
  <header class="admin-header">
    <div class="admin-header-main"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
      <p class="eyebrow">ADMIN WORKSPACE</p>
      <h1>Assignments</h1>
      <p>Create course work, track submissions, and review student progress.</p>
    </div>
  </header>
  <?php if ($message): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
  <section class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-titles">
        <h2>Create assignment</h2>
        <p>Only published assignments are visible to enrolled students.</p>
      </div>
    </div>
    <form method="post" action="<?= $escape($baseUrl) ?>/admin/assignments/save" class="admin-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
      <div class="admin-form-row-3">
        <div class="admin-field"><label class="admin-label" for="assignment-course">Course <span class="req">*</span></label><select class="admin-select" id="assignment-course" name="course_id" required><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= $escape($course['title']) ?></option><?php endforeach; ?></select></div>
        <div class="admin-field"><label class="admin-label" for="assignment-week">Week <span class="admin-hint">(optional)</span></label><input class="admin-input" id="assignment-week" type="number" min="1" max="52" name="week_number" placeholder="e.g. 3"></div>
        <div class="admin-field"><label class="admin-label" for="assignment-marks">Maximum marks <span class="req">*</span></label><input class="admin-input" id="assignment-marks" type="number" min="0.01" max="100000" step="0.01" name="max_marks" value="100" required></div>
      </div>
      <div class="admin-field"><label class="admin-label" for="assignment-title">Assignment title <span class="req">*</span></label><input class="admin-input" id="assignment-title" name="title" maxlength="200" required placeholder="What should students build?"></div>
      <div class="admin-field"><label class="admin-label" for="assignment-instructions">Instructions</label><textarea class="admin-textarea" id="assignment-instructions" name="instructions" rows="4" maxlength="10000" placeholder="Describe the task, the deliverable and any resources students need."></textarea></div>
      <div class="admin-form-row">
        <div class="admin-field"><label class="admin-label" for="assignment-due">Due date <span class="admin-hint">(optional)</span></label><input class="admin-input" id="assignment-due" type="datetime-local" name="due_at"></div>
        <div class="admin-field"><label class="admin-label" for="assignment-status">Status <span class="req">*</span></label><select class="admin-select" id="assignment-status" name="status">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
          </select></div>
      </div>
      <div class="form-actions"><button class="button" type="submit">Save assignment</button></div>
    </form>
  </section>
  <section class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-titles">
        <h2>Assignment catalogue</h2>
        <p><?= count($assignments) ?> assignment(s)</p>
      </div>
    </div>
    <?php if ($assignments === []): ?><div class="admin-empty-state">
        <h3>No assignments yet</h3>
        <p>Create an assignment above to begin.</p>
      </div><?php else: ?><div class="assignment-admin-list"><?php foreach ($assignments as $a): ?><article class="assignment-admin-row" id="assignment-<?= (int)$a['id'] ?>">
            <div>
              <p class="eyebrow"><?= $escape($a['course_title']) ?><?= !empty($a['week_number']) ? ' · WEEK ' . (int)$a['week_number'] : '' ?></p>
              <h3><?= $escape($a['title']) ?></h3>
              <p><?= $escape($a['instructions'] ?? '') ?></p>
            </div><span class="dashboard-status status-<?= $escape($a['status']) ?>"><?= $escape(ucfirst($a['status'])) ?></span><span><?= $escape($a['submission_count']) ?> submissions</span><span><?= $escape($a['max_marks']) ?> marks</span>
          </article><?php endforeach; ?></div><?php endif; ?>
  </section>
  <section class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-titles">
        <h2>Submissions to review</h2>
        <p>Save marks and feedback, or ask a student to resubmit.</p>
      </div>
    </div>
    <?php if ($submissions === []): ?><div class="admin-empty-state">
        <h3>No submissions awaiting review</h3>
        <p>Student work will appear here after submission.</p>
      </div><?php else: ?><div class="assignment-review-list"><?php foreach ($submissions as $s): ?><article class="assignment-review-item" id="submission-<?= (int)$s['id'] ?>">
            <div class="assignment-submission-heading">
              <div>
                <p class="eyebrow"><?= $escape($s['course_title']) ?></p>
                <h3><?= $escape($s['assignment_title']) ?></h3>
                <p><?= $escape($s['student_name']) ?> · <?= $escape($s['student_email']) ?> · <?= $escape(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></p>
              </div><span class="dashboard-status status-<?= $escape($s['status']) ?>"><?= $escape(str_replace('_', ' ', ucfirst($s['status']))) ?></span>
            </div>
            <?php if (!empty($s['submission_text'])): ?><div class="assignment-submission-text"><?= nl2br($escape($s['submission_text'])) ?></div><?php endif; ?><?php if (!empty($s['file_path'])): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning/assignments/file?submission_id=<?= (int)$s['id'] ?>">Download student file</a><?php endif; ?>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/assignments/review" class="admin-form assignment-review-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
              <div class="admin-form-row">
                <div class="admin-field"><label class="admin-label" for="review-marks-<?= (int)$s['id'] ?>">Marks <span class="admin-hint">(out of <?= $escape($s['max_marks']) ?>)</span></label><input class="admin-input" id="review-marks-<?= (int)$s['id'] ?>" type="number" min="0" max="<?= $escape($s['max_marks']) ?>" step="0.01" name="marks_awarded" value="<?= $escape($s['marks_awarded'] ?? '0') ?>" required></div>
                <div class="admin-field"><label class="admin-label" for="review-feedback-<?= (int)$s['id'] ?>">Feedback</label><textarea class="admin-textarea" id="review-feedback-<?= (int)$s['id'] ?>" name="feedback" rows="2" maxlength="5000"><?= $escape($s['feedback'] ?? '') ?></textarea></div>
              </div>
              <div class="form-actions"><button class="button" name="decision" value="reviewed" type="submit">Save review</button><button class="button button-secondary" name="decision" value="resubmit_requested" type="submit">Request resubmission</button></div>
            </form>
          </article><?php endforeach; ?></div><?php endif; ?>
  </section>
</section>