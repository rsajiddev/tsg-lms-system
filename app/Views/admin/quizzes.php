<?php
use App\Core\Security;
$baseUrl=rtrim((require BASE_PATH.'/config/app.php')['url'],'/');
$escape=static fn(mixed $value): string=>Security::escape((string)$value);
$quizzesById=[]; foreach ($quizzes as $quiz) $quizzesById[(int)$quiz['id']]=$quiz;
$questionsByQuiz=[]; foreach ($questions as $question) $questionsByQuiz[(int)$question['quiz_id']][]=$question;
?>
<section class="dashboard-page quiz-admin-page">
  <header class="dashboard-heading"><div><p class="eyebrow">ADMIN WORKSPACE</p><h1>Quiz management</h1><p>Create weekly assessments, write four-option questions, and release them to enrolled students.</p></div></header>
  <?php if (!empty($message)): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
  <section class="dashboard-panel quiz-admin-create"><div class="dashboard-panel-heading"><div><p class="eyebrow">NEW ASSESSMENT</p><h2>Create a weekly quiz</h2></div></div>
    <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/save" class="quiz-settings-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
      <label class="admin-label">Course<select class="admin-select" name="course_id" required><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= $escape($course['title']) ?></option><?php endforeach; ?></select></label>
      <label class="admin-label">Week<input class="admin-input" type="number" name="week_number" min="1" max="52" value="1" required></label>
      <label class="admin-label">Question count<input class="admin-input" type="number" name="question_count" min="1" max="75" value="75" required><small>Choose 20 or 75 to match the course policy; other counts up to 75 are supported.</small></label>
      <label class="admin-label">Time limit (minutes)<input class="admin-input" type="number" name="time_limit_minutes" min="1" max="600" value="20" required></label>
      <label class="admin-label">Pass mark (%)<input class="admin-input" type="number" name="pass_mark_percent" min="0" max="100" step="0.01" value="50" required></label>
      <label class="admin-label">Attempts allowed<input class="admin-input" type="number" name="attempt_limit" min="1" max="10" value="1" required></label>
      <label class="admin-label quiz-field-wide">Quiz title<input class="admin-input" name="title" maxlength="200" required placeholder="Week 1 Assessment"></label>
      <label class="admin-label quiz-field-wide">Instructions<textarea class="admin-textarea" name="instructions" rows="3" maxlength="10000" placeholder="Explain the quiz rules and topics."></textarea></label>
      <label class="admin-label">Available from<input class="admin-input" type="datetime-local" name="unlock_at"><small>Leave blank to release immediately when status is Released.</small></label>
      <label class="admin-label">Closes at<input class="admin-input" type="datetime-local" name="close_at"></label>
      <label class="admin-label">Status<select class="admin-select" name="status"><option value="draft">Draft — add questions before release</option></select></label>
      <div class="quiz-field-wide"><button class="button" type="submit">Create quiz</button></div>
    </form>
  </section>
  <div class="quiz-admin-list">
  <?php if ($quizzes===[]): ?><section class="dashboard-panel"><div class="dashboard-empty"><div><h2>No quizzes created</h2><p>Create a draft above, then add the required questions before releasing it.</p></div></div></section><?php endif; ?>
  <?php foreach ($quizzes as $quiz): $quizId=(int)$quiz['id']; ?>
    <section class="dashboard-panel quiz-admin-card" id="quiz-<?= $quizId ?>">
      <div class="dashboard-panel-heading"><div><p class="eyebrow"><?= $escape($quiz['course_title']) ?> · WEEK <?= (int)$quiz['week_number'] ?></p><h2><?= $escape($quiz['title']) ?></h2></div><span class="dashboard-status status-<?= $escape($quiz['status']) ?>"><?= $escape(ucfirst($quiz['status'])) ?></span></div>
      <p class="quiz-admin-summary"><?= (int)$quiz['actual_question_count'] ?> / <?= (int)$quiz['question_count'] ?> questions · <?= (int)ceil((int)$quiz['time_limit_seconds']/60) ?> minutes · <?= (int)$quiz['attempt_limit'] ?> attempt(s) · <?= $escape($quiz['pass_mark_percent']) ?>% pass · <?= (int)$quiz['attempt_count'] ?> attempt(s) started</p>
      <details class="quiz-details"><summary>Edit quiz settings</summary>
        <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/save" class="quiz-settings-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="id" value="<?= $quizId ?>">
          <label class="admin-label">Course<select class="admin-select" name="course_id" required><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>" <?= (int)$course['id']===(int)$quiz['course_id']?'selected':'' ?>><?= $escape($course['title']) ?></option><?php endforeach; ?></select></label>
          <label class="admin-label">Week<input class="admin-input" type="number" name="week_number" min="1" max="52" value="<?= (int)$quiz['week_number'] ?>" required></label>
          <label class="admin-label">Question count<input class="admin-input" type="number" name="question_count" min="1" max="75" value="<?= (int)$quiz['question_count'] ?>" required></label>
          <label class="admin-label">Time limit (minutes)<input class="admin-input" type="number" name="time_limit_minutes" min="1" max="600" value="<?= (int)ceil((int)$quiz['time_limit_seconds']/60) ?>" required></label>
          <label class="admin-label">Pass mark (%)<input class="admin-input" type="number" name="pass_mark_percent" min="0" max="100" step="0.01" value="<?= $escape($quiz['pass_mark_percent']) ?>" required></label>
          <label class="admin-label">Attempts allowed<input class="admin-input" type="number" name="attempt_limit" min="1" max="10" value="<?= (int)$quiz['attempt_limit'] ?>" required></label>
          <label class="admin-label quiz-field-wide">Title<input class="admin-input" name="title" maxlength="200" value="<?= $escape($quiz['title']) ?>" required></label>
          <label class="admin-label quiz-field-wide">Instructions<textarea class="admin-textarea" name="instructions" rows="3" maxlength="10000"><?= $escape($quiz['instructions']) ?></textarea></label>
          <label class="admin-label">Available from<input class="admin-input" type="datetime-local" name="unlock_at" value="<?= $quiz['unlock_at'] ? $escape(date('Y-m-d\TH:i',strtotime($quiz['unlock_at'].' UTC'))) : '' ?>"></label>
          <label class="admin-label">Closes at<input class="admin-input" type="datetime-local" name="close_at" value="<?= $quiz['close_at'] ? $escape(date('Y-m-d\TH:i',strtotime($quiz['close_at'].' UTC'))) : '' ?>"></label>
          <label class="admin-label">Status<select class="admin-select" name="status"><?php foreach (['draft','scheduled','released','closed'] as $status): ?><option value="<?= $status ?>" <?= $quiz['status']===$status?'selected':'' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></label>
          <div class="quiz-field-wide"><button class="button button-secondary" type="submit">Save quiz settings</button></div>
        </form>
      </details>
      <?php if ($quiz['status']==='draft' && (int)$quiz['attempt_count']===0): ?>
      <div class="quiz-question-editor"><h3>Questions</h3>
        <?php foreach (($questionsByQuiz[$quizId]??[]) as $question): ?>
          <details class="quiz-question-edit"><summary>Edit question <?= (int)$question['sort_order'] ?> · <?= $escape(mb_strimwidth($question['question_text'],0,95,'…')) ?></summary>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/questions/save" class="quiz-question-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="quiz_id" value="<?= $quizId ?>"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
              <label class="admin-label quiz-field-wide">Question<textarea class="admin-textarea" name="question_text" rows="3" maxlength="10000" required><?= $escape($question['question_text']) ?></textarea></label><label class="admin-label">Marks<input class="admin-input" type="number" name="marks" min="0.01" max="1000" step="0.01" value="<?= $escape($question['marks']) ?>" required></label>
              <?php foreach ($question['options'] as $index=>$option): ?><label class="quiz-option-label"><input type="radio" name="correct_option" value="<?= $index ?>" <?= (int)$option['is_correct']===1?'checked':'' ?> required><span>Correct</span><input class="admin-input" name="options[]" maxlength="2000" value="<?= $escape($option['option_text']) ?>" required></label><?php endforeach; ?>
              <div class="quiz-field-wide"><button class="button button-secondary" type="submit">Save question</button></div>
            </form>
          </details>
        <?php endforeach; ?>
        <?php if ((int)$quiz['actual_question_count']<(int)$quiz['question_count']): ?>
        <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/questions/save" class="quiz-question-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="quiz_id" value="<?= $quizId ?>">
          <label class="admin-label quiz-field-wide">New question<textarea class="admin-textarea" name="question_text" rows="3" maxlength="10000" required placeholder="Write one multiple-choice question"></textarea></label><label class="admin-label">Marks<input class="admin-input" type="number" name="marks" min="0.01" max="1000" step="0.01" value="1" required></label>
          <?php for ($index=0;$index<4;$index++): ?><label class="quiz-option-label"><input type="radio" name="correct_option" value="<?= $index ?>" <?= $index===0?'required':'' ?>><span>Correct</span><input class="admin-input" name="options[]" maxlength="2000" required placeholder="Option <?= $index+1 ?>"></label><?php endfor; ?>
          <div class="quiz-field-wide"><button class="button" type="submit">Add question</button></div>
        </form>
        <?php endif; ?>
      </div>
      <?php elseif ((int)$quiz['attempt_count']>0): ?><p class="quiz-locked-note">Question content is locked because at least one student attempt has started.</p><?php elseif ($quiz['status']!=='draft'): ?><p class="quiz-locked-note">Question editing is available in draft status only. Return the quiz to draft before editing.</p><?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
</section>
