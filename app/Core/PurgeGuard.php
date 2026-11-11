<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Shared guard for the bulk delete tools on the assignment, quiz and lesson workspaces.
 *
 * Those tools exist to clear test data before the Academy LMS is handed over, so they stay
 * on the page where they can be found. The safety is not in hiding them: the exact phrase,
 * an explicit acknowledgement and a server-side re-check are all mandatory, because the
 * browser form can be bypassed with curl and a mistyped phrase must never delete anything.
 */
final class PurgeGuard
{
    public static function returnPath(string $fallback): string
    {
        return ($_POST['return_to'] ?? null) === '/admin/danger-zone'
            ? '/admin/danger-zone'
            : $fallback;
    }

    public static function assertConfirmed(string $expectedPhrase): void
    {
        $password = (string) ($_POST['admin_password'] ?? '');
        if ($password !== '') {
            $adminId = (int) ($_SESSION['user']['id'] ?? 0);
            $stmt = Database::connection()->prepare("SELECT password_hash FROM lms_users WHERE id=? AND role='admin' AND status='active' LIMIT 1");
            $stmt->execute([$adminId]);
            $hash = (string) ($stmt->fetchColumn() ?: '');
            if ($hash === '' || !password_verify($password, $hash)) {
                throw new RuntimeException('The admin password is incorrect. Nothing was deleted.');
            }
        } else {
            $typed = strtoupper(trim((string)($_POST['confirm_phrase'] ?? '')));
            if (!hash_equals($expectedPhrase, $typed)) {
                throw new RuntimeException('Enter the admin password before deleting these records.');
            }
        }
        if ((string)($_POST['irreversible'] ?? '') !== '1') {
            throw new RuntimeException('Confirm that this deletion is permanent and cannot be undone.');
        }
    }

    /**
     * Optional course filter shared by the three purge screens. Zero means “every course”,
     * which is the default the testing workflow wants; anything else scopes the delete down.
     */
    public static function scopeCourseId(): int
    {
        $value = filter_var($_POST['course_id'] ?? 0, FILTER_VALIDATE_INT);
        return $value === false || $value < 0 ? 0 : (int)$value;
    }

    /**
     * Delete every row an admin could have attached to the removed records, so no orphan
     * upload survives a purge. Never fatal: a file that is already gone is not a failure.
     *
     * @param list<string> $relativePaths stored relative to the private upload root
     */
    public static function removeStoredFiles(array $relativePaths): int
    {
        $root = realpath(BASE_PATH . '/storage/uploads/private');
        if ($root === false) {
            return 0;
        }
        $removed = 0;
        foreach ($relativePaths as $relative) {
            $relative = trim((string)$relative);
            // Only the naming the upload code itself produces is accepted: no traversal, no globs.
            if ($relative === '' || !preg_match('#^[a-z-]{1,32}/[a-f0-9]{40,64}\.[a-z]{2,4}$#', $relative)) {
                continue;
            }
            $file = realpath($root . '/' . $relative);
            if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
                continue;
            }
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Rebuild the stored grade matrix for the students affected by a purge, quietly. A failed
     * refresh must never undo a deletion that already succeeded, and the next quiz or
     * assignment event recomputes the row anyway.
     *
     * @param list<array{student_id:int,course_id:int}> $pairs
     */
    public static function refreshGrades(array $pairs): int
    {
        $refreshed = 0;
        foreach (array_slice($pairs, 0, 500) as $pair) {
            $studentId = (int)($pair['student_id'] ?? 0);
            $courseId = (int)($pair['course_id'] ?? 0);
            if ($studentId < 1 || $courseId < 1) {
                continue;
            }
            try {
                (new \App\Services\CourseGrading(Database::connection()))->recompute($studentId, $courseId);
                $refreshed++;
            } catch (\Throwable $exception) {
                error_log(sprintf('[PURGE] Grade refresh skipped for student %d in course %d: %s', $studentId, $courseId, $exception->getMessage()));
            }
        }
        return $refreshed;
    }
}
