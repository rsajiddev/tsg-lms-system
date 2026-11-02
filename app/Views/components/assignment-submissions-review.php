<?php use App\Core\Security; ?>
<section class="admin-card assignment-submissions-card">
  <div class="assignment-submissions-banner">
    <div><p class="eyebrow">ASSESSMENT WORKFLOW</p><h2>Submissions to review</h2><p>Review student work, award marks and send clear feedback.</p></div>
    <span class="assignment-review-count"><?= number_format(count($submissions)) ?> waiting</span>
  </div>
  <?php if ($submissions === []): ?>
    <div class="admin-empty-state"><h3>No submissions awaiting review</h3><p>Student work will appear here after submission.</p></div>
  <?php else: ?>
    <div class="assignment-review-list">
      <?php foreach ($submissions as $s): ?>
        <article class="assignment-review-item" id="submission-<?= (int) $s['id'] ?>">
          <div class="assignment-submission-heading"><div><p class="eyebrow"><?= $escape($s['course_title']) ?></p><h3><?= $escape($s['assignment_title']) ?></h3><p><?= $escape($s['student_name']) ?> · <?= $escape($s['student_email']) ?> · <?= $escape(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></p></div><span class="dashboard-status status-<?= $escape($s['status']) ?>"><?= $escape(str_replace('_', ' ', ucfirst($s['status']))) ?></span></div>
          <?php if (!empty($s['github_url'])): ?><p class="assignment-submission-link"><strong>GitHub repository</strong> <a class="dashboard-inline-link" href="<?= $escape($s['github_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= $escape($s['github_url']) ?></a></p><?php endif; ?>
          <?php if (!empty($s['submission_text'])): ?><div class="assignment-submission-text"><?= nl2br($escape($s['submission_text'])) ?></div><?php endif; ?>
          <?php if (!empty($s['file_path'])): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning/assignments/file?submission_id=<?= (int) $s['id'] ?>">Download student file</a><?php endif; ?>
          <?php if ((int) ($s['submitted_late'] ?? 0) === 1): ?><p class="assignment-late-flag">Submitted late<?= !empty($s['late_deduction_percent']) ? ' · ' . $escape($s['late_deduction_percent']) . '% deduction applies' : '' ?></p><?php endif; ?>
          <form method="post" action="<?= $escape($baseUrl) ?>/admin/assignments/review" class="admin-form assignment-review-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="submission_id" value="<?= (int) $s['id'] ?>">
            <p class="assignment-rubric-lead">Score each criterion from 0 to 5. Marks are calculated out of <?= $escape($s['max_marks']) ?> across the five criteria.</p>
            <div class="assignment-rubric-grid">
              <?php $rubricCriteria = ['rubric_technical' => 'Technical Knowledge', 'rubric_problem_solving' => 'Problem Solving', 'rubric_code_quality' => 'Code Quality', 'rubric_timeliness' => 'Timeliness', 'rubric_completeness' => 'Completeness']; foreach ($rubricCriteria as $column => $label): $fieldId = 'review-' . $column . '-' . (int) $s['id']; ?>
                <div class="admin-field"><label class="admin-label" for="<?= $escape($fieldId) ?>"><?= $escape($label) ?> <span class="admin-hint">(0&ndash;5)</span></label><input class="admin-input" id="<?= $escape($fieldId) ?>" type="number" min="0" max="5" step="0.5" name="<?= $escape($column) ?>" value="<?= $escape($s[$column] ?? '') ?>"></div>
              <?php endforeach; ?>
            </div>
            <div class="admin-field"><label class="admin-label" for="review-feedback-<?= (int) $s['id'] ?>">Feedback</label><textarea class="admin-textarea" id="review-feedback-<?= (int) $s['id'] ?>" name="feedback" rows="2" maxlength="5000"><?= $escape($s['feedback'] ?? '') ?></textarea></div>
            <div class="form-actions"><button class="button" name="decision" value="reviewed" type="submit">Save review &amp; marks</button><button class="button button-secondary" name="decision" value="resubmit_requested" type="submit">Request resubmission</button></div>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
