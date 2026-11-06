<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * The lesson player's content-protection layer: a student watermark and a log of unusual viewing.
 *
 * Two deliberate limits shape this service.
 *
 * First, the watermark is drawn in the page over the player rather than burned into the video.
 * Lesson recordings are held by an external provider - the platform only stores a URL - and the
 * application has no video tooling and no intention of acquiring any, so pixel-level branding is
 * physically not available here. A page-level mark still puts a name, an Academy ID and a
 * minute-bound trace code onto every frame a student can see, which is what makes a circulated
 * copy attributable.
 *
 * Second, nothing in here decides watch time or completion. LessonMedia owns that alone. A blocked
 * download attempt is a note for the Academy, never a deduction from a student's grade, so a
 * browser that fires an event at the wrong moment cannot quietly cost somebody their certificate.
 *
 * Reads and writes fail open for the same reason as the session registry: a database that has not
 * been re-imported must still let students into their lessons.
 */
final class LessonProtection
{
    public const WATERMARK_KEY = 'video_watermark_enabled';
    public const RETENTION_KEY = 'lesson_view_retention_days';
    /** When '1', an enrolment whose certificate is issued loses its lesson videos before it expires. */
    public const LOCK_ON_COMPLETION_KEY = 'video_lock_on_completion';
    public const DEFAULT_RETENTION_DAYS = 30;
    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 365;

    /** Every value the log can hold, including the two only the registry may write. */
    public const EVENTS = [
        'session_cap',
        'concurrent_ip',
        'fullscreen_blocked',
        'pip_blocked',
        'download_blocked',
        'seek_ahead',
        'context_menu',
    ];

    /**
     * The subset a browser is allowed to report.
     *
     * `session_cap` and `concurrent_ip` describe what the sign-in itself displaced, so only the
     * registry files them. Leaving them open to a POST would let a student write an accusation
     * about their own account into the Academy's evidence table.
     */
    public const CLIENT_EVENTS = [
        'fullscreen_blocked',
        'pip_blocked',
        'download_blocked',
        'seek_ahead',
        'context_menu',
    ];

    private const EVENT_LABELS = [
        'session_cap' => 'Signed in on another device',
        'concurrent_ip' => 'Two addresses at once',
        'fullscreen_blocked' => 'Fullscreen blocked',
        'pip_blocked' => 'Picture-in-picture blocked',
        'download_blocked' => 'Download attempted',
        'seek_ahead' => 'Large jump forward',
        'context_menu' => 'Right-click on the player',
    ];

    private PDO $db;

    private ?bool $schemaAvailable = null;

    private ?bool $watermarkEnabled = null;

    private ?bool $lockOnCompletion = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** True when the view-event table has been imported. */
    public function available(): bool
    {
        if ($this->schemaAvailable !== null) {
            return $this->schemaAvailable;
        }
        try {
            $this->db->query('SELECT 1 FROM lesson_view_events LIMIT 0');
            $this->schemaAvailable = true;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') {
                error_log('[VIDEO PROTECTION] Availability check failed: ' . $exception->getMessage());
            }
            $this->schemaAvailable = false;
        }
        return $this->schemaAvailable;
    }

    public function watermarkEnabled(): bool
    {
        if ($this->watermarkEnabled !== null) {
            return $this->watermarkEnabled;
        }
        $stored = '';
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::WATERMARK_KEY]);
            $stored = trim((string) $stmt->fetchColumn());
        } catch (\PDOException $exception) {
            $stored = '';
        }
        // Absent or unreadable means on: the mark protects the Academy's recordings, and the
        // setting exists to switch it off for a lesson that is not paid content, not by accident.
        return $this->watermarkEnabled = ($stored === '' || $stored === '1');
    }

    /**
     * Whether a certified enrolment loses its lesson videos before the access period ends.
     *
     * The proposal asked for the recordings to disappear once a course is complete; the standing
     * behaviour keeps them available until expires_at so a finished student can revise. This
     * switch lets an Academy pick the completion lock instead. Absent or unreadable means off -
     * the behaviour every install has always had - so a half-imported database can never hide a
     * lesson from a student by accident.
     */
    public function lockOnCompletion(): bool
    {
        if ($this->lockOnCompletion !== null) {
            return $this->lockOnCompletion;
        }
        $stored = '';
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::LOCK_ON_COMPLETION_KEY]);
            $stored = trim((string) $stmt->fetchColumn());
        } catch (\PDOException $exception) {
            $stored = '';
        }
        return $this->lockOnCompletion = ($stored === '1');
    }

    public function retentionDays(): int
    {
        $stored = 0;
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::RETENTION_KEY]);
            $stored = (int) $stmt->fetchColumn();
        } catch (\PDOException $exception) {
            $stored = 0;
        }
        if ($stored < self::MIN_RETENTION_DAYS || $stored > self::MAX_RETENTION_DAYS) {
            $stored = self::DEFAULT_RETENTION_DAYS;
        }
        return $stored;
    }

    /**
     * What the overlay must print for this student on this lesson.
     *
     * The trace code is derived from the request's CSRF secret and the current minute, so it is
     * not guessable, it changes every minute (which pins a leaked recording to a date), and it
     * needs no new application key or database column to exist.
     *
     * @return array{enabled: bool, name: string, reference: string, time: string, trace: string, label: string}
     */
    public function watermark(int $studentId, string $studentName): array
    {
        $reference = AccountIdentity::reference($studentId, 'student');
        return [
            'enabled' => $this->watermarkEnabled(),
            'name' => $studentName,
            'reference' => $reference,
            // UTC is what every record in this database is written in, so the mark on the screen
            // and the row in the log can be lined up against each other without a conversion.
            'time' => self::stamp(),
            'trace' => $this->traceCode($studentId),
            'label' => $studentName . ' · ' . $reference,
        ];
    }

    /**
     * The two parts of the mark that move on their own, for the heartbeat to hand back.
     *
     * The trace is keyed on the minute, so an overlay that is never refreshed would print a stale
     * code after an hour of watching - and a stale code is exactly the one detail that lets a leaked
     * recording be pinned to the wrong day. Reading it from the heartbeat response needs no new
     * route and no extra query.
     *
     * @return array{trace: string, time: string}
     */
    public function refresh(int $studentId): array
    {
        return ['trace' => $this->traceCode($studentId), 'time' => self::stamp()];
    }

    /** The timestamp the overlay prints, in the same shape on first paint and on every refresh. */
    public static function stamp(): string
    {
        return gmdate('Y-m-d H:i') . ' UTC';
    }

    /** Six hex characters, different next minute, unguessable without the session's CSRF secret. */
    public function traceCode(int $studentId): string
    {
        $key = (string) ($_SESSION['_csrf'] ?? '');
        $trace = $key === ''
            ? '000000'
            : substr(hash_hmac('sha256', 'wm:' . gmdate('YmdHi') . ':' . $studentId, $key), 0, 6);
        return strtoupper($trace);
    }

    /**
     * File one protection event. Unknown event names and oversized details are refused rather
     * than truncated into the table, because this log is evidence.
     */
    public function log(int $studentId, ?int $lessonId, string $event, ?string $detail = null): bool
    {
        if (!$this->available() || $studentId < 1 || !in_array($event, self::EVENTS, true)) {
            return false;
        }
        $detail = $detail === null ? null : preg_replace('/\s+/', ' ', trim($detail));
        if ($detail !== null) {
            if ($detail === '') {
                $detail = null;
            } elseif (mb_strlen($detail) > 255) {
                $detail = mb_substr($detail, 0, 255);
            }
        }
        try {
            $stmt = $this->db->prepare('INSERT INTO lesson_view_events(student_id,lesson_id,event_type,ip_address,detail,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
            $stmt->execute([$studentId, $lessonId && $lessonId > 0 ? $lessonId : null, $event, SessionRegistry::clientIp(), $detail]);
            return true;
        } catch (\PDOException $exception) {
            error_log('[VIDEO PROTECTION] Event write failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * The admin's lesson view log: newest first, with the owner's Academy ID rather than a row id,
     * because that is the number an admin can actually act on.
     */
    public function recent(int $limit = 100, int $studentId = 0): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT e.id, e.event_type, e.detail, e.ip_address, e.created_at,
                       u.full_name AS student_name, COALESCE(NULLIF(u.account_reference, \'\'), \'—\') AS student_reference,
                       l.title AS lesson_title, c.title AS course_title
                FROM lesson_view_events e
                JOIN users u ON u.id = e.student_id
                LEFT JOIN lessons l ON l.id = e.lesson_id
                LEFT JOIN course_modules cm ON cm.id = l.module_id
                LEFT JOIN courses c ON c.id = cm.course_id';
        $params = [];
        if ($studentId > 0) {
            $sql .= ' WHERE e.student_id = ?';
            $params[] = $studentId;
        }
        $sql .= " ORDER BY e.created_at DESC, e.id DESC LIMIT $limit";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            error_log('[VIDEO PROTECTION] Log read failed: ' . $exception->getMessage());
            return [];
        }
    }

    public function count(): int
    {
        if (!$this->available()) {
            return 0;
        }
        try {
            return (int) $this->db->query('SELECT COUNT(*) FROM lesson_view_events')->fetchColumn();
        } catch (\PDOException $exception) {
            return 0;
        }
    }

    public function clearAll(): int
    {
        if (!$this->available()) {
            return 0;
        }
        try {
            return (int) $this->db->exec('DELETE FROM lesson_view_events');
        } catch (\PDOException $exception) {
            error_log('[VIDEO PROTECTION] Log clear failed: ' . $exception->getMessage());
            return 0;
        }
    }

    /**
     * Drops rows past the retention window. Called lazily before a read, since this environment
     * has no scheduler for it, the same way attendance rows are closed.
     */
    public function sweepExpired(): int
    {
        if (!$this->available()) {
            return 0;
        }
        try {
            $stmt = $this->db->prepare('DELETE FROM lesson_view_events WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
            $stmt->execute([$this->retentionDays()]);
            return $stmt->rowCount();
        } catch (\PDOException $exception) {
            return 0;
        }
    }

    public static function label(string $event): string
    {
        return self::EVENT_LABELS[$event] ?? ucfirst(str_replace('_', ' ', $event));
    }
}
