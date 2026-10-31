<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$courses = is_array($courses ?? null) ? $courses : [];
$modules = is_array($modules ?? null) ? $modules : [];
$assignments = is_array($assignments ?? null) ? $assignments : [];
$submissions = is_array($submissions ?? null) ? $submissions : [];
$message = $message ?? null;
$error = $error ?? null;
$purgeCount = count($assignments);
?>
<section class="dashboard-page admin-workspace assignment-admin-page">
  <header class="admin-header">
    <div class="admin-header-main"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
      <p class="eyebrow">ADMIN WORKSPACE</p>
      <h1>Assignments</h1>
      <p>Create course work, track submissions, and review student progress.</p>
    </div>
  </header>
  <?php \App\Core\View::toast('success', $message ?? null); ?>
  <?php \App\Core\View::toast('error', $error ?? null); ?>
  <section class="admin-card">
    <div class="admin-card-header">
      <div class="admin-card-titles">
        <h2>Create assignment</h2>
        <p>Only published assignments are visible to enrolled students.</p>
      </div>
    </div>
    <form method="post" action="<?= $escape($baseUrl) ?>/admin/assignments/save" class="admin-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
      <div class="admin-form-row-3">
        <div class="admin-field"><label class="admin-label" for="assignment-course">Course <span class="req">*</span></label><select class="admin-select" id="assignment-course" name="course_id" required data-assignment-course><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= $escape($course['title']) ?></option><?php endforeach; ?></select></div>
        <div class="admin-field"><label class="admin-label" for="assignment-module">Module <span class="admin-hint">(optional)</span></label><select class="admin-select" id="assignment-module" name="module_id" data-assignment-module><option value="0">Whole course</option><?php foreach ($modules as $module): ?><option value="<?= (int)$module['id'] ?>" data-course-id="<?= (int)$module['course_id'] ?>"><?= $escape($module['title']) ?></option><?php endforeach; ?></select></div>
        <div class="admin-field"><label class="admin-label" for="assignment-type">Assignment type <span class="req">*</span></label><select class="admin-select" id="assignment-type" name="assignment_type"><option value="exercise">Exercise</option><option value="mini_project">Mini Project</option></select></div>
      </div>
      <div class="admin-form-row-3">
        <div class="admin-field"><label class="admin-label" for="assignment-topic">Practice category <span class="req">*</span></label><select class="admin-select" id="assignment-topic" name="assignment_topic"><option value="general">General</option><option value="php_mysql">PHP/MySQL practice</option></select><span class="admin-hint">PHP/MySQL practice requires a GitHub repository link on submission.</span></div>
        <div class="admin-field"><label class="admin-label" for="assignment-week">Week <span class="admin-hint">(optional)</span></label><input class="admin-input" id="assignment-week" type="number" min="1" max="52" name="week_number" placeholder="e.g. 3"></div>
        <div class="admin-field"><label class="admin-label" for="assignment-marks">Maximum marks <span class="req">*</span></label><input class="admin-input" id="assignment-marks" type="number" min="0.01" max="100000" step="0.01" name="max_marks" value="100" required></div>
      </div>
      <div class="admin-field"><label class="admin-label" for="assignment-title">Assignment title <span class="req">*</span></label><input class="admin-input" id="assignment-title" name="title" maxlength="200" required placeholder="What should students build?"></div>
      <div class="admin-field"><label class="admin-label" for="assignment-instructions">Instructions</label><textarea class="admin-textarea" id="assignment-instructions" name="instructions" rows="4" maxlength="10000" placeholder="Describe the task, the deliverable and any resources students need."></textarea></div>
      <div class="admin-form-row-3">
        <div class="admin-field"><label class="admin-label" for="assignment-due">Due date <span class="admin-hint">(optional)</span></label><input class="admin-input" id="assignment-due" type="datetime-local" name="due_at"></div>
        <div class="admin-field"><label class="admin-label" for="assignment-cutoff">Admin cutoff <span class="admin-hint">(optional)</span></label><input class="admin-input" id="assignment-cutoff" type="datetime-local" name="admin_cutoff_at"><span class="admin-hint">Hard stop for late submissions; must be on or after the due date.</span></div>
        <div class="admin-field"><label class="admin-label" for="assignment-late">Late deduction (%)</label><input class="admin-input" id="assignment-late" type="number" min="0" max="100" step="0.01" name="late_deduction_percent" value="0"><span class="admin-hint">Applied to marks when a student submits after the due date.</span></div>
      </div>
      <div class="admin-form-row">
        <div class="admin-field"><label class="admin-label" for="assignment-status">Status <span class="req">*</span></label><select class="admin-select" id="assignment-status" name="status">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="closed">Closed</option>
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
              <p class="eyebrow"><?= $escape($a['course_title']) ?><?= !empty($a['week_number']) ? ' · WEEK ' . (int)$a['week_number'] : '' ?> · <?= $escape(str_replace('_', ' ', ucfirst($a['assignment_type'] ?? 'exercise'))) ?><?php if (!empty($a['module_title'])): ?> · <?= $escape($a['module_title']) ?><?php else: ?> · WHOLE COURSE<?php endif; ?><?= ($a['assignment_topic'] ?? 'general') === 'php_mysql' ? ' · PHP/MYSQL' : '' ?></p>
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
            <?php if (!empty($s['github_url'])): ?><p class="assignment-submission-link"><strong>GitHub repository</strong> <a class="dashboard-inline-link" href="<?= $escape($s['github_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= $escape($s['github_url']) ?></a></p><?php endif; ?>
            <?php if (!empty($s['submission_text'])): ?><div class="assignment-submission-text"><?= nl2br($escape($s['submission_text'])) ?></div><?php endif; ?><?php if (!empty($s['file_path'])): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning/assignments/file?submission_id=<?= (int)$s['id'] ?>">Download student file</a><?php endif; ?>
            <?php if ((int)($s['submitted_late'] ?? 0) === 1): ?><p class="assignment-late-flag">Submitted late<?= !empty($s['late_deduction_percent']) ? ' · ' . $escape($s['late_deduction_percent']) . '% deduction applies' : '' ?></p><?php endif; ?>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/assignments/review" class="admin-form assignment-review-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
              <p class="assignment-rubric-lead">Score each criterion from 0 to 5. Marks are calculated out of <?= $escape($s['max_marks']) ?> across the five criteria.</p>
              <div class="assignment-rubric-grid">
                <?php
                  $rubricCriteria = [
                    'rubric_technical' => 'Technical Knowledge',
                    'rubric_problem_solving' => 'Problem Solving',
                    'rubric_code_quality' => 'Code Quality',
                    'rubric_timeliness' => 'Timeliness',
                    'rubric_completeness' => 'Completeness',
                  ];
                  foreach ($rubricCriteria as $column => $label): $fieldId = 'review-' . $column . '-' . (int)$s['id'];
                ?>
                <div class="admin-field"><label class="admin-label" for="<?= $escape($fieldId) ?>"><?= $escape($label) ?> <span class="admin-hint">(0&ndash;5)</span></label><input class="admin-input" id="<?= $escape($fieldId) ?>" type="number" min="0" max="5" step="0.5" name="<?= $escape($column) ?>" value="<?= $escape($s[$column] ?? '') ?>"></div>
                <?php endforeach; ?>
              </div>
              <div class="admin-field"><label class="admin-label" for="review-feedback-<?= (int)$s['id'] ?>">Feedback</label><textarea class="admin-textarea" id="review-feedback-<?= (int)$s['id'] ?>" name="feedback" rows="2" maxlength="5000"><?= $escape($s['feedback'] ?? '') ?></textarea></div>
              <div class="form-actions"><button class="button" name="decision" value="reviewed" type="submit">Save review &amp; marks</button><button class="button button-secondary" name="decision" value="resubmit_requested" type="submit">Request resubmission</button></div>
            </form>
          </article><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php
  // Testing tool: clears the assignment bank built up while the course content was being written.
  $purgeAction = '/admin/assignments/purge';
  $purgePhrase = 'DELETE ALL ASSIGNMENTS';
  $purgeTitle = 'Delete all assignments';
  $purgeDescription = 'Removes every assignment with its submissions, reviews and uploaded files. Use it to clear test data before student work begins.';
  $purgeScopeCourses = $courses;
  require BASE_PATH . '/app/Views/components/purge-zone.php';
  ?>

  <?php
  $usageEyebrow = 'Assignment desk';
  $usageTitle = 'How to use assignments';
  $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
  $usageBullets = [
      'Attach every assignment to a course and module. Students only see assignments that belong to a course they have access to.',
      'Set a clear deadline. Submissions that arrive after it are flagged, so you can decide whether to accept or reject them.',
      'Published is the switch. Draft assignments stay hidden from students until you publish them from the assignment card.',
      'Students upload their work. Files are stored privately and stay visible only to you and the submitting student.',
      'Review from one list. The submissions card shows each upload with the student, lesson and deadline state next to your grade and feedback fields.',
      'Feedback goes straight to the student. Grading an assignment notifies the student and shows your notes inside their own assignments view.',
  ];
  require BASE_PATH . '/app/Views/components/usage-guide.php';
  ?>
</section>
<script>
  (() => {
    const course = document.querySelector('[data-assignment-course]');
    const module = document.querySelector('[data-assignment-module]');
    if (!course || !module) return;
    const filter = () => {
      const selected = course.value;
      let visibleNone = true;
      module.querySelectorAll('option[data-course-id]').forEach(option => {
        const match = option.dataset.courseId === selected;
        option.hidden = !match;
        if (match) visibleNone = false;
        if (!match && option.selected) module.value = '0';
      });
      if (visibleNone && module.selected && module.value !== '0') module.value = '0';
    };
    filter();
    course.addEventListener('change', filter);
  })();
</script>