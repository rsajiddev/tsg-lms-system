<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$danger = is_array($danger ?? null) ? $danger : [];
$message = $message ?? null;
$students = (int) ($danger['students'] ?? 0);
$certificates = (int) ($danger['certificates'] ?? 0);
$assignments = (int) ($danger['assignments'] ?? 0);
$quizzes = (int) ($danger['quizzes'] ?? 0);
$lessons = (int) ($danger['lessons'] ?? 0);
$announcements = (int) ($danger['announcements'] ?? 0);
$activity = (int) ($danger['activity'] ?? 0);
$courses = is_array($danger['courses'] ?? null) ? $danger['courses'] : [];
?>

<section class="dashboard-page admin-workspace danger-zone-page" data-reveal>

    <header class="admin-header danger-zone-header">
        <div class="admin-header-main">
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow danger-zone-eyebrow">ADMIN WORKSPACE</p>
            <h1>Danger Zone</h1>
            <p>Every permanent, bulk delete in the academy lives here. Each tool is guarded on purpose &mdash; type the exact phrase, tick the warning, then the button unlocks. Nothing on this page can be undone, so read the guidelines above every action.</p>
        </div>
    </header>

    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>

    <section class="danger-guidelines" role="note" aria-labelledby="danger-guidelines-title">
        <div class="danger-guidelines-head">
            <span class="danger-guidelines-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg></span>
            <h2 id="danger-guidelines-title">Read before deleting &mdash; mandatory for every admin</h2>
        </div>
        <ol class="danger-guidelines-list">
            <li><strong>Deletion is permanent.</strong> Once a card is fired the records and any uploaded files are gone for good. There is no undo and no trash bin.</li>
            <li><strong>Export anything you may need first.</strong> Download reports, certificates or the payment ledger from their own pages before clearing the data behind them.</li>
            <li><strong>The live count is your safety number.</strong> Each card shows how many records match right now. If that number is unexpected, stop and investigate before typing the phrase.</li>
            <li><strong>Never run these while students are active.</strong> Wiping students, quizzes, assignments or lessons during a live batch destroys real enrolments, attempts and progress along with test data.</li>
            <li><strong>Use this for test / handover cleanup only.</strong> These tools exist to reset a development or transferred installation, not as a day-to-day management shortcut.</li>
            <li><strong>Scoped cards respect the course you pick.</strong> Assignments, quizzes and lessons can be limited to one course; leaving it on &ldquo;All courses&rdquo; clears every course at once.</li>
            <li><strong>&ldquo;Clear All Data&rdquo; keeps the academy itself.</strong> It removes test students and their records and restarts the ID counter, but admin accounts, login credentials, settings, courses and content are preserved.</li>
            <li><strong>One admin, one action, one confirmation.</strong> Type the phrase, tick the acknowledgement and confirm the browser prompt. If any step feels rushed or unclear, close the page and return later.</li>
        </ol>
    </section>

    <?php
    // Each delete tool that used to sit at the foot of its own workspace is gathered here under a
    // caption naming that workspace, so an admin always knows which section a card clears. The forms
    // post to the same endpoints and phrases as before; only their home has changed.
    $dangerCards = [
        [
            'category' => 'System &amp; handover',
            'source' => ['/admin/settings', 'Security settings'],
            'action' => '/admin/settings/clear-data',
            'phrase' => 'CLEAR ALL DATA',
            'title' => 'Clear All Data &amp; Reset System',
            'description' => 'Removes every test student with their enrollments, payments, quiz attempts, results, progress, submissions, attendance, certificates, messages and uploaded files, and restarts the ID counter from the saved scheme. Admin accounts, login credentials, system settings, the ID format configuration, courses and content are kept.',
            'count' => $students + $certificates,
        ],
        [
            'category' => 'People',
            'source' => ['/admin/students', 'Student directory'],
            'action' => '/admin/students/purge',
            'phrase' => 'DELETE ALL STUDENTS',
            'title' => 'Delete all students',
            'description' => 'Removes every student account with its enrollments, attendance, submissions, quiz attempts, payments, grades, certificates, chat conversations and uploaded files. Admin accounts, courses and the email delivery log stay untouched.',
            'count' => $students,
        ],
        [
            'category' => 'Communications',
            'source' => ['/admin/announcements', 'Announcement board'],
            'action' => '/admin/announcements/purge',
            'phrase' => 'DELETE ALL ANNOUNCEMENTS',
            'title' => 'Clear the announcement board',
            'description' => 'Removes every announcement and automated student update with its recipient records and private replies, which also clears the unread badges in student sidebars.',
            'count' => $announcements,
        ],
        [
            'category' => 'Reports &amp; logs',
            'source' => ['/admin/activity', 'Recent activity'],
            'action' => '/admin/activity/clear',
            'phrase' => 'CLEAR RECENT ACTIVITY',
            'title' => 'Clear recent activity',
            'description' => 'Removes the automated student updates with their recipient records and private replies, and empties the e-mail delivery log. Announcements you published stay on the board.',
            'count' => $activity,
        ],
        [
            'category' => 'Course content',
            'source' => ['/admin/lessons', 'Lesson library'],
            'action' => '/admin/lessons/purge',
            'phrase' => 'DELETE ALL LESSONS',
            'title' => 'Delete all lessons',
            'description' => 'Removes every lesson with its module placement and the student watch progress recorded against it.',
            'count' => $lessons,
            'scope' => true,
        ],
        [
            'category' => 'Course content',
            'source' => ['/admin/quizzes', 'Quiz management'],
            'action' => '/admin/quizzes/purge',
            'phrase' => 'DELETE ALL QUIZZES',
            'title' => 'Delete all quizzes',
            'description' => 'Removes every quiz with its questions, attempts, results and retake requests. Attempt history cannot be recovered afterwards.',
            'count' => $quizzes,
            'scope' => true,
        ],
        [
            'category' => 'Course content',
            'source' => ['/admin/assignments', 'Assignments'],
            'action' => '/admin/assignments/purge',
            'phrase' => 'DELETE ALL ASSIGNMENTS',
            'title' => 'Delete all assignments',
            'description' => 'Removes every assignment with its submissions, reviews and uploaded files. Use it to clear test data before student work begins.',
            'count' => $assignments,
            'scope' => true,
        ],
    ];
    foreach ($dangerCards as $card):
        ?>
        <div class="danger-card-group">
            <div class="danger-card-category">
                <span class="danger-category-label"><?= $card['category'] ?></span>
                <a class="danger-source-link" href="<?= $escape($baseUrl . $card['source'][0]) ?>">From <?= $escape($card['source'][1]) ?> <span aria-hidden="true">&rarr;</span></a>
            </div>
            <?php
            $purgeAction = $card['action'];
            $purgePhrase = $card['phrase'];
            $purgeTitle = $card['title'];
            $purgeDescription = $card['description'];
            $purgeCount = (int) $card['count'];
            $purgeScopeCourses = !empty($card['scope']) ? $courses : [];
            unset($purgeButtonLabel, $purgeAck);
            require BASE_PATH . '/app/Views/components/purge-zone.php';
            ?>
        </div>
    <?php endforeach; ?>

    <?php
    // The counter re-arm is deliberately NOT a deletion: it keeps every record and only moves where
    // the next ID starts. It lives here beside the wipe it so often follows, and reuses the exact same
    // phrase-and-acknowledge guard as the delete cards, which the shared script on this page already wires.
    ?>
    <div class="danger-card-group">
        <div class="danger-card-category">
            <span class="danger-category-label">Identifiers</span>
            <a class="danger-source-link" href="<?= $escape($baseUrl) ?>/admin/settings">From Security settings <span aria-hidden="true">&rarr;</span></a>
        </div>
        <section class="admin-card admin-card-testing danger-card danger-card-caution">
            <div class="admin-card-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon caution">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </div>
                    <div class="admin-card-titles">
                        <h2>Reset ID Counter</h2>
                        <p>Moves the student ID counter to a fresh starting serial <em>without deleting any record</em>. Numbers still held by an existing student or printed on a certificate are skipped, so a reset can never hand out a duplicate ID. The next new ID is currently <code><?= $escape($danger['idPreview'] ?? '') ?></code>. Leave the serial blank to resume after the highest ID in use.</p>
                    </div>
                </div>
            </div>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/settings/reset-counter" class="admin-form" data-purge-form>
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <input type="hidden" name="return_to" value="/admin/danger-zone">
                <div class="admin-grid-2">
                    <div class="admin-field">
                        <label class="admin-label" for="reset-id-start">New starting serial number</label>
                        <input class="admin-input" id="reset-id-start" type="number" name="student_id_start" min="1" max="9999999" placeholder="Leave blank to resume after the highest ID in use">
                        <span class="admin-hint">For example <code>1</code> makes the next new ID the first number of the saved format.</span>
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="reset-counter-confirm">Type <code>RESET ID COUNTER</code> to confirm <span class="req">*</span></label>
                        <input class="admin-input" id="reset-counter-confirm" type="text" name="confirm_phrase" autocomplete="off" spellcheck="false" data-purge-phrase required placeholder="RESET ID COUNTER">
                    </div>
                </div>
                <div class="admin-field"><label class="purge-acknowledgement"><input type="checkbox" name="irreversible" value="1" data-purge-ack><span>I understand this only moves the counter and deletes no student, certificate or setting.</span></label></div>
                <div class="form-actions"><button class="button button-danger" type="submit" data-purge-submit disabled>Reset ID counter</button></div>
            </form>
        </section>
    </div>

</section>
