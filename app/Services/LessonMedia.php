<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Recorded lesson video and the watch-time rule.
 *
 * A lesson may carry one video (a direct file, or a YouTube/Vimeo link that is re-written to an
 * embed URL). When a video has a known duration, the student must accumulate
 * lesson_watch_completion_percent of that duration in watched seconds before the lesson can be
 * marked complete; the completion timestamp is written by the server the moment the threshold is
 * reached, so certificates and course progress can keep trusting lesson_progress.completed_at.
 *
 * Watch time is accumulated from heartbeats only: the browser reports where the playhead is, and
 * the server converts that into capped forward deltas. Nothing a client claims about totals is
 * trusted, and heartbeats that arrive faster than the minimum interval add nothing and are not
 * allowed to move the remembered playhead either.
 */
final class LessonMedia
{
    public const WATCH_KEY = 'lesson_watch_completion_percent';
    public const DEFAULT_PERCENT = 90;
    public const MIN_PERCENT = 10;
    public const MAX_PERCENT = 100;

    /** Longest forward jump a single heartbeat may credit, so scrubbing cannot buy full credit. */
    public const MAX_DELTA_SECONDS = 20;
    /** Shortest gap between two credited heartbeats, which bounds the credit per wall-clock second. */
    public const MIN_PING_SECONDS = 8;
    /** Player heartbeats are scheduled every ten seconds by the browser. */
    public const HEARTBEAT_SECONDS = 10;

    private PDO $db;

    private ?bool $schemaAvailable = null;

    private ?int $percent = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** True when the video columns have been imported; the lesson flow degrades to manual completion. */
    public function available(): bool
    {
        if ($this->schemaAvailable !== null) {
            return $this->schemaAvailable;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                      AND ((TABLE_NAME = 'lessons' AND COLUMN_NAME IN ('video_url', 'video_provider', 'video_duration_seconds'))
                           OR (TABLE_NAME = 'lesson_progress' AND COLUMN_NAME IN ('watched_seconds', 'last_position_seconds')))"
            );
            $stmt->execute();
            $this->schemaAvailable = (int) $stmt->fetchColumn() >= 5;
        } catch (\PDOException $exception) {
            error_log('[LESSON MEDIA] Availability check failed: ' . $exception->getMessage());
            $this->schemaAvailable = false;
        }
        return $this->schemaAvailable;
    }

    public function watchPercent(): int
    {
        if ($this->percent !== null) {
            return $this->percent;
        }
        $stored = 0;
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::WATCH_KEY]);
            $stored = (int) $stmt->fetchColumn();
        } catch (\PDOException $exception) {
            $stored = 0;
        }
        if ($stored < self::MIN_PERCENT || $stored > self::MAX_PERCENT) {
            $stored = self::DEFAULT_PERCENT;
        }
        return $this->percent = $stored;
    }

    /** Provider is always derived from the stored URL so a hand-edited record cannot break the player. */
    public static function detectProvider(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') return 'none';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'youtube.com') || $host === 'youtu.be' || $host === 'www.youtube.com') return 'youtube';
        if (str_contains($host, 'vimeo.com') || str_contains($host, 'player.vimeo.com')) return 'vimeo';
        if (preg_match('/\\.mp4$|\\.webm$|\\.ogv$|\\.m4v$|\\.mov$/i', $url) === 1) return 'file';
        return 'embed';
    }

    /** Accepts 90, "90", "1:30", "12:34" or "1:02:03" and returns whole seconds. */
    public static function parseDuration(mixed $input): ?int
    {
        $value = trim((string) $input);
        if ($value === '') return null;
        if (str_contains($value, ':')) {
            $parts = array_map(static fn(string $part): int => (int) trim($part), explode(':', $value));
            if ($parts === [] || count($parts) > 3) return null;
            $seconds = 0;
            foreach ($parts as $part) {
                if ($part < 0 || ($part > 59 && count($parts) > 1)) return null;
                $seconds = $seconds * 60 + $part;
            }
            return $seconds > 0 ? min($seconds, 86400) : null;
        }
        if (preg_match('/^[0-9]+$/', $value) !== 1) return null;
        $seconds = (int) $value;
        return $seconds > 0 ? min($seconds, 86400) : null;
    }

    public static function formatDuration(?int $seconds): string
    {
        $seconds = (int) $seconds;
        if ($seconds < 1) return '—';
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;
        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainder)
            : sprintf('%d:%02d', $minutes, $remainder);
    }

    /**
     * Live class link, or null when the lesson holds no class.
     *
     * Stricter than the recording link on purpose. This value is rendered as a clickable address on
     * a signed-in page, so https is required outright - a javascript: or data: value stored here
     * would run against every student who opens the lesson. Credentials in the address are refused
     * too, because https://zoom.us@evil.test reads as a Zoom domain to a student and lands on
     * evil.test. Which provider the link points at is not checked: the Academy uses Zoom and Google
     * Meet today, and a host allowlist would break the next one for no safety.
     */
    public static function normaliseMeetingUrl(?string $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') return null;
        if (mb_strlen($value) > 600) {
            throw new RuntimeException('The live class link must stay under 600 characters.');
        }
        if (preg_match('#^https://#i', $value) !== 1) {
            throw new RuntimeException('Live class links must start with https:// so the meeting opens in a new tab.');
        }
        $parts = parse_url($value);
        if ($parts === false || empty($parts['host'])) {
            throw new RuntimeException('That live class link is not a complete address.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || str_contains((string) ($parts['authority'] ?? ''), '@')) {
            throw new RuntimeException('A live class link may not carry credentials or an @ in its address.');
        }
        return $value;
    }

    /** A meeting platform is metadata independent from both the video URL and meeting address. */
    public static function normaliseMeetingPlatform(mixed $platform): ?string
    {
        $value = trim((string) $platform);
        if ($value === '') return null;
        if (!in_array($value, ['google_meet', 'zoom'], true)) {
            throw new RuntimeException('Choose Google Meet or Zoom as the live meeting platform.');
        }
        return $value;
    }

    /** Preserve legacy forms and older records by recognising the two supported meeting hosts. */
    public static function detectMeetingPlatform(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host === 'meet.google.com' || str_ends_with($host, '.meet.google.com')) return 'google_meet';
        if ($host === 'zoom.us' || str_ends_with($host, '.zoom.us')) return 'zoom';
        return null;
    }

    /**
     * Class instant as a UTC database timestamp, or null when no time was given.
     *
     * The admin field posts a wall clock with no offset and is labelled UTC, so the typed value is
     * stored as typed. Converting it against the server's own timezone would make two students in
     * two countries read two different start times off one class.
     */
    public static function parseMeetingTime(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $matches) !== 1) {
            throw new RuntimeException('Enter the class date and time in the form the field shows (year-month-day, hour:minute).');
        }
        [, $year, $month, $day, $hour, $minute] = $matches;
        if ((int) $year < 2000 || (int) $year > 2999 || !checkdate((int) $month, (int) $day, (int) $year)
            || (int) $hour > 23 || (int) $minute > 59) {
            throw new RuntimeException('That class date or time does not exist. Check the year, month, day and hour.');
        }
        return sprintf('%s-%s-%s %s:%s:00', $year, $month, $day, $hour, $minute);
    }

    /** Normalises a pasted link into the URL the player actually loads. */
    public static function normaliseUrl(?string $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') return null;
        if (preg_match('#^(https?://|//)#i', $value) !== 1) {
            $value = 'https://' . ltrim($value, '/');
        }
        return mb_substr($value, 0, 600);
    }

    /** Embeddable player address, or null when the lesson has no video. */
    public static function embedUrl(?string $url, string $provider): ?string
    {
        $value = self::normaliseUrl($url);
        if ($value === null || $provider === 'none') return null;
        if ($provider === 'youtube') {
            $videoId = '';
            if (preg_match('#(?:v=|youtu\.be/|embed/|shorts/)([A-Za-z0-9_-]{6,20})#', $value, $match) === 1) {
                $videoId = $match[1];
            }
            return $videoId === '' ? $value : 'https://www.youtube-nocookie.com/embed/' . $videoId;
        }
        if ($provider === 'vimeo') {
            if (preg_match('#vimeo\.com/(?:video/)?([0-9]+)#', $value, $match) === 1) {
                return 'https://player.vimeo.com/video/' . $match[1];
            }
            return str_contains($value, 'player.vimeo.com') ? $value : null;
        }
        return $value;
    }

    /** Seconds a student must accumulate before the lesson may be completed. */
    public function requiredSeconds(?int $duration): int
    {
        $duration = (int) $duration;
        if ($duration < 1) return 0;
        return (int) ceil($duration * $this->watchPercent() / 100);
    }

    /**
     * Everything the lesson page and the completion rule need about one lesson and one student.
     *
     * @return array<string, mixed>
     */
    public function state(int $studentId, int $lessonId): array
    {
        $media = ['video_url' => null, 'provider' => 'none', 'duration' => null];
        $progress = ['watched' => 0, 'position' => 0, 'completed' => false];
        if (!$this->available()) {
            return $this->decorate($media, $progress);
        }
        try {
            $stmt = $this->db->prepare('SELECT video_url, video_provider, video_duration_seconds FROM lessons WHERE id = ? LIMIT 1');
            $stmt->execute([$lessonId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $url = $row['video_url'] === null ? null : (string) $row['video_url'];
                $media = [
                    'video_url' => $url,
                    'provider' => self::detectProvider($url),
                    'duration' => $row['video_duration_seconds'] === null ? null : (int) $row['video_duration_seconds'],
                ];
            }
            $stmt = $this->db->prepare('SELECT watched_seconds, last_position_seconds, completed_at FROM lesson_progress WHERE student_id = ? AND lesson_id = ? LIMIT 1');
            $stmt->execute([$studentId, $lessonId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $progress = [
                    'watched' => (int) $row['watched_seconds'],
                    'position' => (int) $row['last_position_seconds'],
                    'completed' => $row['completed_at'] !== null,
                ];
            }
        } catch (\PDOException $exception) {
            $this->schemaAvailable = false;
            error_log('[LESSON MEDIA] State read failed: ' . $exception->getMessage());
        }
        return $this->decorate($media, $progress);
    }

    /**
     * Records one player heartbeat and returns the refreshed state.
     *
     * @param int $positionSeconds Where the playhead is, or elapsed page seconds for an embed.
     * @return array<string, mixed>
     */
    public function record(int $studentId, int $lessonId, int $positionSeconds): array
    {
        $state = $this->state($studentId, $lessonId);
        if (!$state['has_video']) {
            return $state;
        }
        return $this->apply($studentId, $lessonId, $positionSeconds, $state);
    }

    /**
     * Adds credit for a forward playhead move, then completes the lesson when the rule is satisfied.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function apply(int $studentId, int $lessonId, int $positionSeconds, array $state): array
    {
        if (!$this->available()) return $state;
        $position = max(0, min(86400, $positionSeconds));
        $duration = $state['duration'] === null ? null : (int) $state['duration'];
        $wasCompleted = (bool) $state['completed'];
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT watched_seconds, last_position_seconds, completed_at, TIMESTAMPDIFF(SECOND, updated_at, UTC_TIMESTAMP()) AS ping_gap FROM lesson_progress WHERE student_id = ? AND lesson_id = ? FOR UPDATE');
            $stmt->execute([$studentId, $lessonId]);
            $row = $stmt->fetch();
            $watched = (int) ($row['watched_seconds'] ?? 0);
            $lastPosition = (int) ($row['last_position_seconds'] ?? 0);
            $gap = $row === false || $row['ping_gap'] === null ? self::HEARTBEAT_SECONDS : (int) $row['ping_gap'];
            $refused = $gap < self::MIN_PING_SECONDS;
            $delta = $refused ? 0 : max(0, $position - $lastPosition);
            if ($delta > self::MAX_DELTA_SECONDS) $delta = self::MAX_DELTA_SECONDS;
            $watched += $delta;
            if ($duration !== null) $watched = min($watched, $duration);
            // A refused heartbeat must not advance the playhead, or a stray report would silently
            // lock the student out of ever earning credit again for the rest of the recording.
            $position = $refused ? $lastPosition : max($position, $lastPosition);
            $required = $this->requiredSeconds($duration);
            $metRule = $duration !== null && $required > 0 && $watched >= $required;
            $stamp = gmdate('Y-m-d H:i:s');
            if ($row === false) {
                $stmt = $this->db->prepare('INSERT INTO lesson_progress(student_id, lesson_id, watched_seconds, last_position_seconds, completed_at, updated_at) VALUES(?,?,?,?,?,?)');
                $stmt->execute([$studentId, $lessonId, $watched, $position, $metRule ? $stamp : null, $stamp]);
            } else {
                $stmt = $this->db->prepare('UPDATE lesson_progress SET watched_seconds = ?, last_position_seconds = ?, completed_at = COALESCE(completed_at, ?), updated_at = ? WHERE student_id = ? AND lesson_id = ?');
                $stmt->execute([$watched, $position, $metRule ? $stamp : null, $stamp, $studentId, $lessonId]);
            }
            $this->db->commit();
        } catch (\PDOException $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[LESSON MEDIA] Watch write failed: ' . $exception->getMessage());
            return $state;
        }
        $state['watched'] = $watched;
        $state['position'] = $position;
        $state['ratio'] = $duration ? min(100, round($watched / $duration * 100, 1)) : 0.0;
        $state['completed'] = $wasCompleted || $metRule;
        $state['just_completed'] = $metRule && !$wasCompleted;
        return $state;
    }

    /**
     * Why a manual completion must wait, or null when the student may complete the lesson.
     */
    public function completionBlock(int $studentId, int $lessonId): ?string
    {
        $state = $this->state($studentId, $lessonId);
        if (!$state['rule_applies']) return null;
        if ($state['completed']) return null;
        return 'Watch at least ' . self::formatDuration((int) $state['required'])
            . ' of this lesson video first — you are at ' . self::formatDuration((int) $state['watched'])
            . ' (' . rtrim(rtrim(number_format((float) $state['ratio'], 1), '0'), '.') . '%).';
    }

    /**
     * @param array<string, mixed> $media
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    private function decorate(array $media, array $progress): array
    {
        $duration = $media['duration'] === null ? null : (int) $media['duration'];
        $required = $this->requiredSeconds($duration);
        $watched = (int) $progress['watched'];
        return [
            'available' => $this->available(),
            'video_url' => $media['video_url'],
            'provider' => $media['provider'],
            'embed_url' => self::embedUrl($media['video_url'], $media['provider']),
            'duration' => $duration,
            'duration_label' => self::formatDuration($duration),
            'watched' => $watched,
            'watched_label' => self::formatDuration($watched),
            'position' => (int) $progress['position'],
            'required' => $required,
            'required_label' => self::formatDuration($required ?: $duration),
            'ratio' => $duration ? min(100, round($watched / $duration * 100, 1)) : 0.0,
            'completed' => (bool) $progress['completed'],
            'has_video' => $media['provider'] !== 'none' && $media['video_url'] !== null,
            // Only a direct file exposes its playhead; embeds fall back to visible page seconds.
            'trackable' => $media['provider'] === 'file',
            // Without a duration there is nothing to measure against, so completion stays manual.
            'rule_applies' => $this->available() && $media['provider'] !== 'none' && $duration !== null && $required > 0,
            'percent' => $this->watchPercent(),
            'heartbeat' => self::HEARTBEAT_SECONDS,
        ];
    }
}
