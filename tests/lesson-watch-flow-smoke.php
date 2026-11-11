<?php

declare(strict_types=1);

namespace {
    define('BASE_PATH', dirname(__DIR__));
    require BASE_PATH . '/app/Core/Env.php';
    require BASE_PATH . '/app/Core/Database.php';
    require BASE_PATH . '/app/Services/LessonMedia.php';
    \App\Core\Env::load(BASE_PATH . '/.env');

    $db = \App\Core\Database::connection();
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    $token = bin2hex(random_bytes(6));
    $ids = ['student' => 0, 'course' => 0, 'module' => 0, 'lessons' => []];
    $percentKey = \App\Services\LessonMedia::WATCH_KEY;
    $previousPercent = null;

    /* Pure helpers run without a database, so they are checked before anything is seeded. */
    $mediaClass = \App\Services\LessonMedia::class;
    $assert($mediaClass::detectProvider('') === 'none', 'An empty link must not be a video.');
    $assert($mediaClass::detectProvider('https://www.youtube.com/watch?v=dQw4w9WgXcQ') === 'youtube', 'A YouTube watch link must be detected.');
    $assert($mediaClass::detectProvider('https://youtu.be/dQw4w9WgXcQ') === 'youtube', 'A shortened YouTube link must be detected.');
    $assert($mediaClass::detectProvider('https://vimeo.com/76979871') === 'vimeo', 'A Vimeo link must be detected.');
    $assert($mediaClass::detectProvider('https://cdn.example.com/lessons/intro.mp4') === 'file', 'A direct file must be detected.');
    $assert($mediaClass::detectProvider('https://media.example.com/player?id=4') === 'embed', 'Any other link must fall back to a generic embed.');
    $assert(
        $mediaClass::embedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube') === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'YouTube links must be re-written to the consent-free embed.'
    );
    $assert($mediaClass::embedUrl('https://vimeo.com/76979871', 'vimeo') === 'https://player.vimeo.com/video/76979871', 'Vimeo links must be re-written to the player.');
    $assert($mediaClass::embedUrl(null, 'none') === null, 'A lesson without a video has no embed URL.');
    $assert($mediaClass::normaliseUrl('youtube.com/watch?v=abc12345678') === 'https://youtube.com/watch?v=abc12345678', 'A scheme-less link must be normalised.');
    $assert($mediaClass::parseDuration('95') === 95, 'Plain seconds must parse.');
    $assert($mediaClass::parseDuration('12:34') === 754, 'mm:ss must parse.');
    $assert($mediaClass::parseDuration('1:02:03') === 3723, 'h:mm:ss must parse.');
    $assert($mediaClass::parseDuration('10:80') === null, 'An out-of-range clock segment must be rejected.');
    $assert($mediaClass::parseDuration('later') === null, 'Non-numeric lengths must be rejected.');
    $assert($mediaClass::formatDuration(3723) === '1:02:03', 'Hour lengths must be formatted.');
    $assert($mediaClass::formatDuration(null) === '—', 'An unknown length must render as a dash.');

    $media = new \App\Services\LessonMedia($db);
    if (!$media->available()) {
        throw new RuntimeException('The lesson video columns are missing. Import database/migrations/001_initial_schema.sql, then run this test again.');
    }

    /* Pin the watch requirement so the expected thresholds below are deterministic. */
    $stored = $db->prepare('SELECT setting_value FROM lms_app_settings WHERE setting_key = ?');
    $stored->execute([$percentKey]);
    $found = $stored->fetchColumn();
    $previousPercent = $found === false ? null : (string) $found;
    $db->prepare('INSERT INTO lms_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$percentKey, '50']);
    $media = new \App\Services\LessonMedia($db);
    $assert($media->watchPercent() === 50, 'The stored watch percentage must be read back.');
    $assert($media->requiredSeconds(200) === 100, 'Half of a 200 second video must require 100 seconds.');
    $assert($media->requiredSeconds(null) === 0, 'An unknown length must disable the rule.');

    $db->prepare("INSERT INTO lms_users(full_name,email,password_hash,role,email_verified_at,status) VALUES(?,?,?,?,UTC_TIMESTAMP(),'active')")
        ->execute(['Lesson Watch Smoke ' . $token, 'lesson-watch-' . $token . '@example.test', password_hash('Smoke Test 1!', PASSWORD_BCRYPT), 'student']);
    $ids['student'] = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO lms_courses(title,slug,visibility,duration_label) VALUES(?,?,?,?)")
        ->execute(['Lesson Watch Smoke Course ' . $token, 'lesson-watch-smoke-' . $token, 'published', '4 weeks']);
    $ids['course'] = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO lms_course_modules(course_id,title,sort_order) VALUES(?,?,1)')
        ->execute([$ids['course'], 'Smoke module']);
    $ids['module'] = (int) $db->lastInsertId();

    $insertLesson = $db->prepare('INSERT INTO lms_lessons(module_id,title,body,video_url,video_provider,video_duration_seconds,sort_order,status) VALUES(?,?,?,?,?,?,1,\'published\')');
    $seedLesson = static function (string $title, ?string $url, ?int $duration) use ($insertLesson, $db, $ids): int {
        $insertLesson->execute([$ids['module'], $title, 'Smoke lesson body', $url, \App\Services\LessonMedia::detectProvider($url), $duration]);
        return (int) $db->lastInsertId();
    };
    $trackedLesson = $seedLesson('Tracked recording', 'https://cdn.example.com/smoke-' . $token . '.mp4', 200);
    $untimedLesson = $seedLesson('Length unknown', 'https://www.youtube.com/watch?v=smoke' . $token, null);
    $plainLesson = $seedLesson('No video at all', null, null);
    $ids['lessons'] = [$trackedLesson, $untimedLesson, $plainLesson];

    $rewind = static function (int $studentId, int $lessonId, int $seconds) use ($db): void {
        $db->prepare('UPDATE lms_lesson_progress SET updated_at = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE student_id = ? AND lesson_id = ?')
            ->execute([$seconds, $studentId, $lessonId]);
    };

    try {
        /* A lesson without a video cannot be tracked and is never blocked. */
        $state = $media->state($ids['student'], $plainLesson);
        $assert($state['has_video'] === false && $state['rule_applies'] === false, 'A lesson without a video must stay manually completable.');
        $assert($media->record($ids['student'], $plainLesson, 120)['watched'] === 0, 'A video-less heartbeat must credit nothing.');
        $assert($media->completionBlock($ids['student'], $plainLesson) === null, 'A video-less lesson must not be blocked.');
        $assert($db->query('SELECT COUNT(*) FROM lms_lesson_progress WHERE lesson_id = ' . $plainLesson)->fetchColumn() == 0, 'A video-less heartbeat must not store progress.');

        /* Embeds are played in the page but cannot expose the playhead. */
        $state = $media->state($ids['student'], $untimedLesson);
        $assert($state['has_video'] === true && $state['trackable'] === false, 'A YouTube lesson must render as an embed.');
        $assert($state['embed_url'] === 'https://www.youtube-nocookie.com/embed/smoke' . $token, 'The embed address must be re-written.');
        $assert($state['rule_applies'] === false, 'An unknown video length must disable the watch rule.');
        $assert($media->completionBlock($ids['student'], $untimedLesson) === null, 'A lesson without a length must not be blocked.');
        $untimed = $media->record($ids['student'], $untimedLesson, 60);
        $assert($untimed['watched'] === 20 && $untimed['completed'] === false, 'An untimed recording still banks watch time but never auto-completes.');

        /* A direct file records forward playhead movement only. */
        $state = $media->state($ids['student'], $trackedLesson);
        $assert($state['trackable'] === true && $state['required'] === 100, 'A 200 second file at 50% must require 100 seconds.');

        $state = $media->record($ids['student'], $trackedLesson, 15);
        $assert($state['watched'] === 15, 'The first heartbeat must credit its forward move.');
        $assert($state['completed'] === false, 'Fifteen seconds cannot complete the lesson.');

        $state = $media->record($ids['student'], $trackedLesson, 90);
        $assert($state['watched'] === 15, 'A heartbeat that arrives too fast must be ignored.');

        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 30);
        $assert($state['watched'] === 30, 'A normal forward move is credited in full.');

        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 120);
        $assert($state['watched'] === 50, 'A 90 second scrub forward may only credit the capped delta.');
        $assert($state['position'] === 120, 'The furthest credited playhead position must be remembered.');

        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 130);
        $assert($state['watched'] === 60, 'Each accepted heartbeat may credit at most the cap, and a refused one must not move the playhead.');
        $blocked = $media->completionBlock($ids['student'], $trackedLesson);
        $assert(is_string($blocked) && str_contains($blocked, 'Watch at least 1:40'), 'Below the threshold the student must be told what is missing: ' . $blocked);
        $assert($state['completed'] === false && $state['just_completed'] === false, 'Sixty seconds is under the hundred second requirement.');

        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 150);
        $assert($state['watched'] === 80, 'Watch time must accumulate across heartbeats.');

        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 175);
        $assert($state['watched'] === 100, 'The threshold-crossing heartbeat must still be credited.');
        $assert($state['completed'] === true && $state['just_completed'] === true, 'Crossing the watch threshold must complete the lesson server side.');
        $verify = $db->prepare('SELECT completed_at, watched_seconds FROM lms_lesson_progress WHERE student_id = ? AND lesson_id = ?');
        $verify->execute([$ids['student'], $trackedLesson]);
        $row = $verify->fetch();
        $assert($row !== false && $row['completed_at'] !== null && (int) $row['watched_seconds'] === 100, 'The stored row must carry the completion stamp certificates rely on.');
        $assert($media->completionBlock($ids['student'], $trackedLesson) === null, 'A completed lesson must never be blocked again.');

        /* Rewinding, replaying and re-watching cannot remove or inflate credit. */
        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 20);
        $assert($state['watched'] === 100, 'Seeking backwards must not reduce or add watch time.');
        $rewind($ids['student'], $trackedLesson, 10);
        $state = $media->record($ids['student'], $trackedLesson, 400);
        $assert($state['watched'] === 120, 'A jump past the end of the video may only credit the capped delta.');
        $assert($state['ratio'] <= 100.0, 'Watch progress must never be reported above the video length.');

        /* The percentage is clamped, so a bad setting cannot disable the rule silently. */
        $db->prepare('UPDATE lms_app_settings SET setting_value = ? WHERE setting_key = ?')->execute(['7', $percentKey]);
        $assert((new \App\Services\LessonMedia($db))->watchPercent() === 90, 'An out-of-range percentage must fall back to the default.');

        echo "Lesson watch smoke checks passed: provider and embed detection, duration parsing, video-less and untimed fallbacks, "
            . "capped forward-delta watch accounting, rate guarding, threshold auto-completion, and replay safety.\n";
    } finally {
        /* Every row below was created by this run, so a blocked delete is retried with checks off */
        /* instead of leaving smoke data behind. A local database can hold a stale InnoDB foreign   */
        /* key dictionary that refuses parent deletes even with no children at all.                */
        $deleteOwnedRows = static function (string $sql, array $params = []) use ($db): void {
            try {
                $db->prepare($sql)->execute($params);
            } catch (PDOException $exception) {
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    $db->prepare($sql)->execute($params);
                } finally {
                    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
        };
        /* The shared setting is restored first, because it outlives this run whichever delete an */
        /* old local database happens to block.                                                    */
        if ($previousPercent === null) {
            $deleteOwnedRows('DELETE FROM lms_app_settings WHERE setting_key = ?', [$percentKey]);
        } else {
            $deleteOwnedRows('UPDATE lms_app_settings SET setting_value = ? WHERE setting_key = ?', [$previousPercent, $percentKey]);
        }
        if ($ids['lessons'] !== []) {
            $placeholders = implode(',', array_fill(0, count($ids['lessons']), '?'));
            $deleteOwnedRows('DELETE FROM lms_lesson_progress WHERE lesson_id IN (' . $placeholders . ')', $ids['lessons']);
            $deleteOwnedRows('DELETE FROM lms_lessons WHERE id IN (' . $placeholders . ')', $ids['lessons']);
        }
        if ($ids['module'] > 0) $deleteOwnedRows('DELETE FROM lms_course_modules WHERE id = ?', [$ids['module']]);
        if ($ids['course'] > 0) $deleteOwnedRows('DELETE FROM lms_courses WHERE id = ?', [$ids['course']]);
        if ($ids['student'] > 0) $deleteOwnedRows('DELETE FROM lms_users WHERE id = ?', [$ids['student']]);
    }
}
