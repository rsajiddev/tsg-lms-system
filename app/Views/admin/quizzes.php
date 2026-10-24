<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$courses = $courses ?? [];
$quizzes = $quizzes ?? [];
$questions = $questions ?? [];
$message = $message ?? null;
$questionsByQuiz = [];
foreach ($questions as $question) {
    $questionsByQuiz[(int) $question['quiz_id']][] = $question;
}
$optionLetters = ['A', 'B', 'C', 'D'];
?>

<section class="dashboard-page admin-workspace" data-reveal>

    <header class="admin-header">
        <div class="admin-header-main">
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Quiz management</h1>
            <p>Create weekly assessments, write four-option questions, and release them to enrolled students.</p>
        </div>
    </header>

    <?php if (!empty($message)): ?>
        <div class="admin-alert admin-alert-success" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span><?= $escape($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Create quiz -->
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="9" y1="14" x2="15" y2="14"></line>
                        <line x1="12" y1="11" x2="12" y2="17"></line>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Create a weekly quiz</h2>
                    <p>Set the assessment window, scoring rules, and release status</p>
                </div>
            </div>
            <span class="count-pill">Saves as draft</span>
        </div>

        <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/save" class="admin-form quiz-settings-form">
            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">

            <div class="admin-form-row-3">
                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-course">Course <span class="req">*</span></label>
                    <select class="admin-select" id="quiz-new-course" name="course_id" required>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= (int) $course['id'] ?>"><?= $escape($course['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="admin-hint">The assessment is attached to this course only.</span>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-week">Week <span class="req">*</span></label>
                    <input class="admin-input" id="quiz-new-week" type="number" name="week_number" min="1" max="52" value="1" required>
                    <span class="admin-hint">One assessment per course, per week.</span>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-count">Question count <span class="req">*</span></label>
                    <input class="admin-input" id="quiz-new-count" type="number" name="question_count" min="1" max="75" value="75" required>
                    <span class="admin-hint">Choose 20 or 75 to match the course policy; other counts up to 75 are supported.</span>
                </div>
            </div>

            <div class="admin-form-row-3">
                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-time">Time limit (minutes) <span class="req">*</span></label>
                    <input class="admin-input" id="quiz-new-time" type="number" name="time_limit_minutes" min="1" max="600" value="20" required>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-pass">Pass mark (%) <span class="req">*</span></label>
                    <input class="admin-input" id="quiz-new-pass" type="number" name="pass_mark_percent" min="0" max="100" step="0.01" value="50" required>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-attempts">Attempts allowed <span class="req">*</span></label>
                    <input class="admin-input" id="quiz-new-attempts" type="number" name="attempt_limit" min="1" max="10" value="1" required>
                </div>
            </div>

            <div class="admin-field">
                <label class="admin-label" for="quiz-new-title">Quiz title <span class="req">*</span></label>
                <input class="admin-input" id="quiz-new-title" name="title" maxlength="200" required placeholder="Week 1 Assessment">
            </div>

            <div class="admin-field">
                <label class="admin-label" for="quiz-new-instructions">Instructions</label>
                <textarea class="admin-textarea" id="quiz-new-instructions" name="instructions" rows="3" maxlength="10000" placeholder="Explain the quiz rules and topics."></textarea>
                <span class="admin-hint">Shown to students on the instructions screen.</span>
            </div>

            <div class="admin-form-row-3">
                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-unlock">Available from</label>
                    <input class="admin-input" id="quiz-new-unlock" type="datetime-local" name="unlock_at">
                    <span class="admin-hint">Leave blank to release immediately when status is Released.</span>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-close">Closes at</label>
                    <input class="admin-input" id="quiz-new-close" type="datetime-local" name="close_at">
                    <span class="admin-hint">Optional hard stop for the attempt window.</span>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="quiz-new-status">Status</label>
                    <select class="admin-select" id="quiz-new-status" name="status">
                        <option value="draft">Draft — add questions before release</option>
                    </select>
                    <span class="admin-hint">A quiz can only be released once every question is written.</span>
                </div>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Create quiz
                </button>
            </div>
        </form>
    </section>

    <!-- Quiz library -->
    <div class="quiz-admin-list">
        <?php if ($quizzes === []): ?>
            <section class="admin-card">
                <div class="admin-empty-state">
                    <div class="admin-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    </div>
                    <h3>No quizzes created</h3>
                    <p>Create a draft above, then add the required questions before releasing it.</p>
                </div>
            </section>
        <?php endif; ?>

        <?php foreach ($quizzes as $quiz): $quizId = (int) $quiz['id']; ?>
            <section class="admin-card quiz-admin-card" id="quiz-<?= $quizId ?>">
                <div class="admin-card-header">
                    <div class="admin-card-title-group">
                        <div class="admin-card-icon violet">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 11l3 3L22 4"></path>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                            </svg>
                        </div>
                        <div class="admin-card-titles">
                            <p class="eyebrow"><?= $escape($quiz['course_title']) ?> &middot; WEEK <?= (int) $quiz['week_number'] ?></p>
                            <h2><?= $escape($quiz['title']) ?></h2>
                        </div>
                    </div>
                    <span class="dashboard-status status-<?= $escape($quiz['status']) ?>"><?= $escape(ucfirst($quiz['status'])) ?></span>
                </div>

                <div class="quiz-meta">
                    <span><?= (int) $quiz['actual_question_count'] ?> / <?= (int) $quiz['question_count'] ?> questions</span>
                    <span><?= (int) ceil((int) $quiz['time_limit_seconds'] / 60) ?> minutes</span>
                    <span><?= (int) $quiz['attempt_limit'] ?> attempt(s)</span>
                    <span><?= $escape($quiz['pass_mark_percent']) ?>% pass</span>
                    <span><?= (int) $quiz['attempt_count'] ?> started</span>
                </div>

                <details class="quiz-details">
                    <summary>Edit quiz settings</summary>
                    <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/save" class="admin-form quiz-settings-form">
                        <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $quizId ?>">

                        <div class="admin-form-row-3">
                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-course">Course <span class="req">*</span></label>
                                <select class="admin-select" id="quiz-<?= $quizId ?>-course" name="course_id" required>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?= (int) $course['id'] ?>" <?= (int) $course['id'] === (int) $quiz['course_id'] ? 'selected' : '' ?>><?= $escape($course['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-week">Week <span class="req">*</span></label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-week" type="number" name="week_number" min="1" max="52" value="<?= (int) $quiz['week_number'] ?>" required>
                            </div>

                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-count">Question count <span class="req">*</span></label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-count" type="number" name="question_count" min="1" max="75" value="<?= (int) $quiz['question_count'] ?>" required>
                                <span class="admin-hint">Cannot drop below the questions already saved.</span>
                            </div>
                        </div>

                        <div class="admin-form-row-3">
                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-time">Time limit (minutes) <span class="req">*</span></label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-time" type="number" name="time_limit_minutes" min="1" max="600" value="<?= (int) ceil((int) $quiz['time_limit_seconds'] / 60) ?>" required>
                            </div>

                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-pass">Pass mark (%) <span class="req">*</span></label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-pass" type="number" name="pass_mark_percent" min="0" max="100" step="0.01" value="<?= $escape($quiz['pass_mark_percent']) ?>" required>
                            </div>

                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-attempts">Attempts allowed <span class="req">*</span></label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-attempts" type="number" name="attempt_limit" min="1" max="10" value="<?= (int) $quiz['attempt_limit'] ?>" required>
                            </div>
                        </div>

                        <div class="admin-field">
                            <label class="admin-label" for="quiz-<?= $quizId ?>-title">Quiz title <span class="req">*</span></label>
                            <input class="admin-input" id="quiz-<?= $quizId ?>-title" name="title" maxlength="200" value="<?= $escape($quiz['title']) ?>" required>
                        </div>

                        <div class="admin-field">
                            <label class="admin-label" for="quiz-<?= $quizId ?>-instructions">Instructions</label>
                            <textarea class="admin-textarea" id="quiz-<?= $quizId ?>-instructions" name="instructions" rows="3" maxlength="10000"><?= $escape($quiz['instructions']) ?></textarea>
                        </div>

                        <div class="admin-form-row-3">
                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-unlock">Available from</label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-unlock" type="datetime-local" name="unlock_at" value="<?= $quiz['unlock_at'] ? $escape(date('Y-m-d\TH:i', strtotime($quiz['unlock_at'] . ' UTC'))) : '' ?>">
                                <span class="admin-hint">Leave blank to release immediately when status is Released.</span>
                            </div>

                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-close">Closes at</label>
                                <input class="admin-input" id="quiz-<?= $quizId ?>-close" type="datetime-local" name="close_at" value="<?= $quiz['close_at'] ? $escape(date('Y-m-d\TH:i', strtotime($quiz['close_at'] . ' UTC'))) : '' ?>">
                            </div>

                            <div class="admin-field">
                                <label class="admin-label" for="quiz-<?= $quizId ?>-status">Status</label>
                                <select class="admin-select" id="quiz-<?= $quizId ?>-status" name="status">
                                    <?php foreach (['draft', 'scheduled', 'released', 'closed'] as $status): ?>
                                        <option value="<?= $status ?>" <?= $quiz['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button class="button button-secondary" type="submit">Save quiz settings</button>
                        </div>
                    </form>
                </details>

                <?php if ($quiz['status'] === 'draft' && (int) $quiz['attempt_count'] === 0): ?>
                    <div class="quiz-question-editor">
                        <div class="quiz-question-editor-head">
                            <h3>Questions</h3>
                            <span class="count-pill"><?= (int) $quiz['actual_question_count'] ?> of <?= (int) $quiz['question_count'] ?> added</span>
                        </div>

                        <?php foreach (($questionsByQuiz[$quizId] ?? []) as $question): $questionId = (int) $question['id']; ?>
                            <details class="quiz-question-edit">
                                <summary>
                                    <span class="quiz-question-index">Q<?= (int) $question['sort_order'] ?></span>
                                    <span class="quiz-question-text"><?= $escape(mb_strimwidth($question['question_text'], 0, 95, '…')) ?></span>
                                    <span class="count-pill"><?= $escape($question['marks']) ?> mark(s)</span>
                                </summary>
                                <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/questions/save" class="admin-form quiz-question-form">
                                    <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
                                    <input type="hidden" name="question_id" value="<?= $questionId ?>">

                                    <div class="admin-field">
                                        <label class="admin-label" for="question-<?= $questionId ?>-text">Question <span class="req">*</span></label>
                                        <textarea class="admin-textarea" id="question-<?= $questionId ?>-text" name="question_text" rows="3" maxlength="10000" required><?= $escape($question['question_text']) ?></textarea>
                                    </div>

                                    <div class="admin-form-row quiz-question-meta">
                                        <div class="admin-field">
                                            <label class="admin-label" for="question-<?= $questionId ?>-marks">Marks <span class="req">*</span></label>
                                            <input class="admin-input" id="question-<?= $questionId ?>-marks" type="number" name="marks" min="0.01" max="1000" step="0.01" value="<?= $escape($question['marks']) ?>" required>
                                        </div>
                                        <div class="admin-field">
                                            <span class="admin-label">Answer key</span>
                                            <span class="admin-hint">Mark the radio beside the correct option. Exactly one answer is allowed.</span>
                                        </div>
                                    </div>

                                    <fieldset class="quiz-option-list">
                                        <legend class="admin-label">Answer options <span class="req">*</span></legend>
                                        <div class="quiz-option-rows">
                                            <?php foreach (($question['options'] ?? []) as $index => $option): ?>
                                                <div class="quiz-option-row">
                                                    <span class="quiz-option-letter"><?= $optionLetters[$index] ?? chr(65 + $index) ?></span>
                                                    <input class="admin-input" type="text" name="options[]" maxlength="2000" value="<?= $escape($option['option_text']) ?>" required aria-label="Option <?= $optionLetters[$index] ?? chr(65 + $index) ?>">
                                                    <label class="quiz-option-key">
                                                        <input type="radio" name="correct_option" value="<?= $index ?>" <?= (int) $option['is_correct'] === 1 ? 'checked' : '' ?> required>
                                                        <span>Correct</span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>

                                    <div class="form-actions">
                                        <button class="button button-secondary" type="submit">Save question</button>
                                    </div>
                                </form>
                            </details>
                        <?php endforeach; ?>

                        <?php if ((int) $quiz['actual_question_count'] < (int) $quiz['question_count']): ?>
                            <div class="quiz-question-composer">
                                <div class="quiz-question-composer-head">
                                    <span class="quiz-question-composer-mark" aria-hidden="true">+</span>
                                    <div>
                                        <h4>Add a new question</h4>
                                        <p>Write the prompt, set the marks, then mark one correct answer.</p>
                                    </div>
                                </div>
                                <form method="post" action="<?= $escape($baseUrl) ?>/admin/quizzes/questions/save" class="admin-form quiz-question-form">
                                    <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

                                    <div class="admin-field">
                                        <label class="admin-label" for="question-new-<?= $quizId ?>-text">Question <span class="req">*</span></label>
                                        <textarea class="admin-textarea" id="question-new-<?= $quizId ?>-text" name="question_text" rows="3" maxlength="10000" required placeholder="Write one multiple-choice question"></textarea>
                                    </div>

                                    <div class="admin-form-row quiz-question-meta">
                                        <div class="admin-field">
                                            <label class="admin-label" for="question-new-<?= $quizId ?>-marks">Marks <span class="req">*</span></label>
                                            <input class="admin-input" id="question-new-<?= $quizId ?>-marks" type="number" name="marks" min="0.01" max="1000" step="0.01" value="1" required>
                                        </div>
                                        <div class="admin-field">
                                            <span class="admin-label">Answer key</span>
                                            <span class="admin-hint">Mark the radio beside the correct option. Exactly one answer is allowed.</span>
                                        </div>
                                    </div>

                                    <fieldset class="quiz-option-list">
                                        <legend class="admin-label">Answer options <span class="req">*</span></legend>
                                        <div class="quiz-option-rows">
                                            <?php for ($index = 0; $index < 4; $index++): ?>
                                                <div class="quiz-option-row">
                                                    <span class="quiz-option-letter"><?= $optionLetters[$index] ?></span>
                                                    <input class="admin-input" type="text" name="options[]" maxlength="2000" required placeholder="Option <?= $optionLetters[$index] ?>" aria-label="Option <?= $optionLetters[$index] ?>">
                                                    <label class="quiz-option-key">
                                                        <input type="radio" name="correct_option" value="<?= $index ?>" <?= $index === 0 ? 'required' : '' ?>>
                                                        <span>Correct</span>
                                                    </label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </fieldset>

                                    <div class="form-actions">
                                        <button class="button" type="submit">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                            </svg>
                                            Add question
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ((int) $quiz['attempt_count'] > 0): ?>
                    <p class="quiz-locked-note">Question content is locked because at least one student attempt has started.</p>
                <?php elseif ($quiz['status'] !== 'draft'): ?>
                    <p class="quiz-locked-note">Question editing is available in draft status only. Return the quiz to draft before editing.</p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

</section>
