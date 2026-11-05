<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * One active session per account, and a visible record of who is signed in.
 *
 * A session is stored as a hash of its PHP session id, never the id itself: a database row that
 * leaks therefore hands over nothing a attacker can put into a cookie. The registry is consulted
 * from AuthMiddleware::requireRole(), which already reads the user row on every protected request,
 * so enforcement sits in the one place every student and admin page already passes through rather
 * than being copied into each controller.
 *
 * The cap is a setting (`session_max_active`, default 1) and not a constant, because "one active
 * session" is the company rule while "this student has a phone and a laptop" is a classroom fact;
 * the Academy has to be able to answer that without a code change. A new sign-in always wins and
 * pushes the oldest session out, which is the behaviour a student who lost a device expects.
 *
 * Every read and write fails open. A database that has not been re-imported with the new tables
 * must not lock the Academy's students out of their lessons.
 */
final class SessionRegistry
{
    public const MAX_ACTIVE_KEY = 'session_max_active';
    public const DEFAULT_MAX_ACTIVE = 1;
    public const MIN_MAX_ACTIVE = 1;
    public const MAX_MAX_ACTIVE = 10;

    /** A session that is not touched for this long is closed lazily rather than by a cron. */
    public const IDLE_MINUTES = 720;

    /** last_seen_at is written at most once per this interval, so a page load costs no extra write. */
    private const TOUCH_SECONDS = 60;

    private PDO $db;

    private ?bool $schemaAvailable = null;

    private ?int $maxActive = null;

    /**
     * One verdict per account per request. A page may authorise through requireRole() twice, and
     * the session row only needs reading once - the answer cannot change inside one request.
     */
    private static array $verdicts = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** True when the session table has been imported. */
    public function available(): bool
    {
        if ($this->schemaAvailable !== null) {
            return $this->schemaAvailable;
        }
        try {
            $this->db->query('SELECT 1 FROM user_sessions LIMIT 0');
            $this->schemaAvailable = true;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') {
                error_log('[SESSIONS] Availability check failed: ' . $exception->getMessage());
            }
            $this->schemaAvailable = false;
        }
        return $this->schemaAvailable;
    }

    public function maxActive(): int
    {
        if ($this->maxActive !== null) {
            return $this->maxActive;
        }
        $stored = 0;
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::MAX_ACTIVE_KEY]);
            $stored = (int) $stmt->fetchColumn();
        } catch (\PDOException $exception) {
            $stored = 0;
        }
        if ($stored < self::MIN_MAX_ACTIVE || $stored > self::MAX_MAX_ACTIVE) {
            $stored = self::DEFAULT_MAX_ACTIVE;
        }
        return $this->maxActive = $stored;
    }

    /** Hash of the live PHP session id, or '' when no session is open (CLI harnesses, workers). */
    public function currentHash(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $id = (string) session_id();
        return $id === '' ? '' : hash('sha256', $id);
    }

    /**
     * Record the session a sign-in just created. Call this **after** session_regenerate_id(),
     * or the hash is of an id the next request will not carry.
     *
     * @return array{evicted: int, concurrent_ip: bool} what the caller may want to report
     */
    public function register(int $userId): array
    {
        $result = ['evicted' => 0, 'concurrent_ip' => false];
        $hash = $this->currentHash();
        if (!$this->available() || $hash === '' || $userId < 1) {
            return $result;
        }
        $ip = self::clientIp();
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->beginTransaction();
            // Re-registering the id you are already using must revive it, not collide with it.
            $stmt = $this->db->prepare('INSERT INTO user_sessions(user_id,session_hash,ip_address,user_agent_hash,created_at,last_seen_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),ip_address=VALUES(ip_address),user_agent_hash=VALUES(user_agent_hash),last_seen_at=VALUES(last_seen_at),revoked_at=NULL,revoked_reason=NULL');
            $stmt->execute([$userId, $hash, $ip, self::agentHash(), $now, $now]);

            // Idle rows are closed here rather than by a scheduler this environment does not have.
            $this->db->prepare('UPDATE user_sessions SET revoked_at=?,revoked_reason=? WHERE user_id=? AND revoked_at IS NULL AND last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)')
                ->execute([$now, 'idle', $userId, self::IDLE_MINUTES]);

            // Other live sessions from a different address are worth a look even if the cap allows them.
            $peers = $this->db->prepare('SELECT ip_address FROM user_sessions WHERE user_id=? AND revoked_at IS NULL AND session_hash<>?');
            $peers->execute([$userId, $hash]);
            foreach ($peers->fetchAll(PDO::FETCH_COLUMN) as $peerIp) {
                if (is_string($peerIp) && $peerIp !== '' && $peerIp !== $ip) {
                    $result['concurrent_ip'] = true;
                    break;
                }
            }

            $active = $this->db->prepare('SELECT id FROM user_sessions WHERE user_id=? AND revoked_at IS NULL ORDER BY last_seen_at DESC,id DESC FOR UPDATE');
            $active->execute([$userId]);
            $ids = array_map('intval', $active->fetchAll(PDO::FETCH_COLUMN));
            $keep = $this->maxActive();
            if (count($ids) > $keep) {
                $losers = array_slice($ids, $keep);
                $in = implode(',', array_fill_keys(range(0, count($losers) - 1), '?'));
                $this->db->prepare("UPDATE user_sessions SET revoked_at=?,revoked_reason='superseded' WHERE id IN ($in)")
                    ->execute(array_merge([$now], $losers));
                $result['evicted'] = count($losers);
            }
            $this->db->commit();
            // Both of these are notes for the Academy, never a punishment: a shared family computer
            // trips the second one constantly, and a student re-using their own phone trips the first.
            if ($result['concurrent_ip'] || $result['evicted'] > 0) {
                $this->report($userId, $result);
            }
        } catch (\PDOException $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[SESSIONS] Registration failed: ' . $exception->getMessage());
        }
        return $result;
    }

    /**
     * File what a sign-in displaced. These rows have no lesson, so the admin desk shows them
     * under "Account session" rather than inventing a lesson for something that happened at the
     * door rather than in the player.
     */
    private function report(int $userId, array $result): void
    {
        try {
            $protection = new LessonProtection($this->db);
            if ($result['concurrent_ip']) {
                $protection->log($userId, null, 'concurrent_ip', 'A live session was already open from a different address.');
            }
            if ($result['evicted'] > 0) {
                $protection->log($userId, null, 'session_cap', sprintf('%d older session(s) signed out by this sign-in.', (int) $result['evicted']));
            }
        } catch (\Throwable $exception) {
            error_log('[SESSIONS] Sign-in event log skipped: ' . $exception->getMessage());
        }
    }

    /**
     * Is the session answering this request still the one the account is allowed to use?
     *
     * @return string ok        - live row, or a session from before the registry existed (adopted)
     *              revoked - the row exists and was closed: the caller must sign the user out
     *              unknown  - nothing to decide (no session, or no table): leave the request alone
     */
    public function check(int $userId): string
    {
        $hash = $this->currentHash();
        if (!$this->available() || $hash === '' || $userId < 1) {
            return 'unknown';
        }
        $memo = $userId . '|' . $hash;
        if (isset(self::$verdicts[$memo])) {
            return self::$verdicts[$memo];
        }
        try {
            $stmt = $this->db->prepare('SELECT user_id, revoked_at, last_seen_at FROM user_sessions WHERE session_hash = ? LIMIT 1');
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                // Either a session opened before the registry was imported, or one created by a
                // code path that has not been hooked up yet. Adopt it: refusing here would log
                // every already-signed-in person out of a lesson they are halfway through.
                $this->register($userId);
                return self::$verdicts[$memo] = 'ok';
            }
            if ($row['revoked_at'] !== null || (int) $row['user_id'] !== $userId) {
                return self::$verdicts[$memo] = 'revoked';
            }
            $gap = time() - (int) strtotime((string) $row['last_seen_at'] . ' UTC');
            if ($gap > self::TOUCH_SECONDS) {
                $this->db->prepare('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE session_hash = ?')
                    ->execute([$hash]);
            }
            return self::$verdicts[$memo] = 'ok';
        } catch (\PDOException $exception) {
            error_log('[SESSIONS] Check failed open: ' . $exception->getMessage());
            return 'unknown';
        }
    }

    /** Close the session answering the request (a logout), leaving the account's other devices alone. */
    public function revokeCurrent(int $userId, string $reason): void
    {
        $hash = $this->currentHash();
        if (!$this->available() || $hash === '') {
            return;
        }
        try {
            $this->db->prepare('UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP(),revoked_reason=? WHERE session_hash=?')
                ->execute([$reason, $hash]);
        } catch (\PDOException $exception) {
            error_log('[SESSIONS] Logout revoke failed: ' . $exception->getMessage());
        }
    }

    /**
     * Close every session for one account, optionally keeping the caller's own.
     *
     * A password change must not throw the student off the device they just used to change it -
     * that device has proved it knows the old password - so `$keepCurrent` is true for that path
     * and false when an administrator is locking an account out.
     *
     * @return array{closed: int, kept: bool}
     */
    public function revokeAll(int $userId, string $reason, bool $keepCurrent = false): array
    {
        if (!$this->available() || $userId < 1) {
            return ['closed' => 0, 'kept' => false];
        }
        $hash = $this->currentHash();
        try {
            $stmt = $this->db->prepare('UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP(),revoked_reason=? WHERE user_id=? AND revoked_at IS NULL AND session_hash<>?');
            $stmt->execute([$reason, $userId, $keepCurrent ? $hash : '']);
            $closed = $stmt->rowCount();
            return ['closed' => $closed, 'kept' => $keepCurrent && $hash !== ''];
        } catch (\PDOException $exception) {
            error_log('[SESSIONS] Mass revoke failed: ' . $exception->getMessage());
            return ['closed' => 0, 'kept' => false];
        }
    }

    /** Live sessions of one account, newest activity first, for the admin's student detail card. */
    public function activeFor(int $userId): array
    {
        if (!$this->available()) {
            return [];
        }
        try {
            $stmt = $this->db->prepare('SELECT id,ip_address,user_agent_hash,created_at,last_seen_at FROM user_sessions WHERE user_id=? AND revoked_at IS NULL ORDER BY last_seen_at DESC,id DESC LIMIT 50');
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            error_log('[SESSIONS] Active list read failed: ' . $exception->getMessage());
            return [];
        }
    }

    public function isActive(int $userId): bool
    {
        return $this->activeFor($userId) !== [];
    }

    /** The session hash the login page would hand out, so a caller can stamp it in its own row. */
    public static function hashOf(?string $sessionId): string
    {
        $id = (string) $sessionId;
        return $id === '' ? '' : hash('sha256', $id);
    }

    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? (string) $ip : null;
    }

    /**
     * The browser string is stored hashed: it is enough to tell "Chrome on Windows" from "curl",
     * and a raw user-agent is personal data the platform has no business keeping per session.
     */
    public static function agentHash(): ?string
    {
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $agent === '' ? null : hash('sha256', mb_substr($agent, 0, 400));
    }

    /** Short human label for a stored agent hash, or null when nothing is recognisable. */
    public static function describeAgent(?string $hash): ?string
    {
        if ($hash === null || $hash === '') return null;
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($agent !== '' && hash('sha256', mb_substr($agent, 0, 400)) === $hash) {
            return self::agentFamily($agent);
        }
        return 'Another device';
    }

    public static function agentFamily(string $agent): string
    {
        $lower = strtolower($agent);
        if ($lower === '' || !str_contains($lower, 'mozilla')) return 'Non-browser client';
        if (str_contains($lower, 'edg')) return 'Edge';
        if (str_contains($lower, 'opr') || str_contains($lower, 'opera')) return 'Opera';
        if (str_contains($lower, 'firefox')) return 'Firefox';
        if (str_contains($lower, 'crios') || str_contains($lower, 'chrome') || str_contains($lower, 'chromium')) return 'Chrome';
        if (str_contains($lower, 'safari')) return str_contains($lower, 'iphone') || str_contains($lower, 'ipad') ? 'Safari on iOS' : 'Safari';
        return 'Browser';
    }
}
