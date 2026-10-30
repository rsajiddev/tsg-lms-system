<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * The public ID an account is quoted by.
 *
 * Students are numbered from a scheme the admin controls on their own settings page - a prefix
 * plus the number the next sign-up starts at, for example TSG-CER-001. A certificate is issued
 * under its owner's ID rather than a number of its own, so the one reference identifies the
 * account and opens the certificate on the public verification page. Admins type their own ID
 * because it doubles as the label they check their workspace against.
 *
 * An ID is never reused or quietly rewritten: enrolments, attendance, submissions, grades and
 * certificates are all filed under it, and it is printed on documents that have already left
 * the building.
 */
final class AccountIdentity
{
    public const PREFIX_KEY = 'student_id_prefix';
    public const NEXT_KEY = 'student_id_next_number';
    public const DEFAULT_PREFIX = 'TSG-CER';
    public const ADMIN_PREFIX = 'TSG-ADM';
    /** The pre-column format, kept so an account without a stored ID still shows something stable. */
    public const LEGACY_STUDENT_PREFIX = 'TSG-STU-';
    public const MAX_PREFIX_LENGTH = 24;
    public const MAX_REFERENCE_LENGTH = 64;
    public const MIN_WIDTH = 3;
    public const MAX_SEQUENCE = 9999999;

    /** Prefixes have to survive being printed on a certificate and typed into a search box. */
    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9]*(-[A-Z0-9]+)*$/';
    public const REFERENCE_PATTERN = '/^[A-Z][A-Z0-9-]{3,63}$/';

    /** @var array<int,string> One lookup per account per request. */
    private static array $references = [];

    /** PREFIX-001, padded to at least three digits and grown when the sequence outgrows them. */
    public static function format(string $prefix, int $sequence): string
    {
        $digits = (string) max(1, $sequence);
        return $prefix . '-' . str_pad($digits, max(self::MIN_WIDTH, strlen($digits)), '0', STR_PAD_LEFT);
    }

    /** Upper-cases a typed prefix and rejects anything that would leak into a public URL. */
    public static function cleanPrefix(string $raw, bool $strict = true): string
    {
        $prefix = strtoupper(trim($raw));
        if ($prefix === '') return self::DEFAULT_PREFIX;
        if (mb_strlen($prefix) > self::MAX_PREFIX_LENGTH || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            if (!$strict) return self::DEFAULT_PREFIX;
            throw new RuntimeException('The ID prefix must be up to ' . self::MAX_PREFIX_LENGTH . ' characters: uppercase letters, digits or single hyphens, starting with a letter (for example TSG-CER).');
        }
        return $prefix;
    }

    /** A whole ID as an admin types it for their own account. */
    public static function cleanReference(string $raw): string
    {
        $reference = strtoupper(trim($raw));
        if (preg_match(self::REFERENCE_PATTERN, $reference) !== 1 || mb_strlen($reference) < 4) {
            throw new RuntimeException('An ID must be 4 to ' . self::MAX_REFERENCE_LENGTH . ' characters: uppercase letters, digits or single hyphens, starting with a letter (for example TSG-CER-001).');
        }
        return $reference;
    }

    /**
     * The scheme as it stands, falling back to the defaults when nothing has been saved yet or
     * a stored value has been edited by hand into something unusable.
     *
     * @return array{prefix:string,next:int}
     */
    public static function scheme(?PDO $db = null): array
    {
        $db = $db ?? Database::connection();
        $prefix = self::DEFAULT_PREFIX;
        $next = 1;
        try {
            $stmt = $db->prepare('SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN (?, ?)');
            $stmt->execute([self::PREFIX_KEY, self::NEXT_KEY]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string) $row['setting_key'] === self::PREFIX_KEY) $prefix = (string) $row['setting_value'];
                else $next = (int) $row['setting_value'];
            }
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
        }
        return ['prefix' => self::cleanPrefix($prefix, false), 'next' => max(1, min($next, self::MAX_SEQUENCE))];
    }

    /**
     * Hands out the next student ID and moves the counter along.
     *
     * The counter row is locked for the duration, so two sign-ups landing in the same second
     * cannot be given the same ID, and a number already taken by a hand-written ID is skipped
     * instead of failing the registration. Callers that are already inside a transaction are
     * joined rather than given one of their own.
     */
    public static function issue(?PDO $db = null): string
    {
        $db = $db ?? Database::connection();
        $joined = $db->inTransaction();
        if (!$joined) $db->beginTransaction();
        try {
            $scheme = self::scheme($db);
            $db->prepare('INSERT IGNORE INTO app_settings(setting_key, setting_value) VALUES(?, ?)')
                ->execute([self::NEXT_KEY, (string) $scheme['next']]);
            $lock = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? FOR UPDATE');
            $lock->execute([self::NEXT_KEY]);
            $sequence = max(1, (int) $lock->fetchColumn());

            $taken = $db->prepare('SELECT 1 FROM users WHERE account_reference = ? LIMIT 1');
            $reference = '';
            for (; $sequence <= self::MAX_SEQUENCE; $sequence++) {
                $candidate = self::format($scheme['prefix'], $sequence);
                $taken->execute([$candidate]);
                if (!$taken->fetch()) { $reference = $candidate; break; }
            }
            if ($reference === '') throw new RuntimeException('The student ID sequence is exhausted.');

            $db->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?')
                ->execute([(string) ($sequence + 1), self::NEXT_KEY]);
            if (!$joined) $db->commit();
            return $reference;
        } catch (\Throwable $exception) {
            if (!$joined && $db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    /** The ID to show for an account: the stored one when there is one, the old derived form otherwise. */
    public static function reference(int $userId, string $role = 'student'): string
    {
        if ($userId < 1) return '—';
        if (isset(self::$references[$userId])) return self::$references[$userId];
        $stored = '';
        try {
            $stmt = Database::connection()->prepare('SELECT account_reference FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $stored = trim((string) $stmt->fetchColumn());
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
        }
        self::$references[$userId] = $stored !== '' ? $stored : self::derived($userId, $role);
        return self::$references[$userId];
    }

    /**
     * Guarantees an account has a stored ID and returns it.
     *
     * Accounts created before the column existed, or written straight into the database by a
     * script, are filled in on the spot: a certificate has to be issued under a number that the
     * student can actually see on their own settings page, and a derived guess would not match.
     */
    public static function ensure(PDO $db, int $userId): string
    {
        if ($userId < 1) return '—';
        $stmt = $db->prepare('SELECT account_reference, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return '—';
        $stored = trim((string) ($row['account_reference'] ?? ''));
        if ($stored !== '') {
            self::remember($userId, $stored);
            return $stored;
        }
        if ((string) ($row['role'] ?? '') === 'admin') {
            $reference = self::derived($userId, 'admin');
            if (self::isTaken($db, $reference, $userId)) {
                throw new RuntimeException('That admin ID is already in use. Set a unique one under Account settings first.');
            }
        } else {
            $reference = self::issue($db);
        }
        $db->prepare('UPDATE users SET account_reference = ? WHERE id = ?')->execute([$reference, $userId]);
        self::remember($userId, $reference);
        return $reference;
    }

    /** Keeps this request honest after an ID has just been changed. */
    public static function remember(int $userId, string $reference): void
    {
        if ($userId < 1) return;
        self::$references[$userId] = $reference;
    }

    /**
     * Stores the scheme the admin typed on their own settings page.
     *
     * The prefix governs every ID minted from now on. The starting number is honoured as typed:
     * if it points at a number that is already out, issue() walks past the taken ones rather
     * than handing out a duplicate, so a lower start can never overwrite somebody's certificate.
     */
    public static function saveScheme(PDO $db, string $rawPrefix, int $start, int $actorId): array
    {
        $prefix = self::cleanPrefix($rawPrefix);
        if ($start < 1 || $start > self::MAX_SEQUENCE) {
            throw new RuntimeException('The starting number must be between 1 and ' . self::MAX_SEQUENCE . '.');
        }
        $upsert = $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
        $upsert->execute([self::PREFIX_KEY, $prefix, $actorId]);
        $upsert->execute([self::NEXT_KEY, (string) $start, $actorId]);
        return ['prefix' => $prefix, 'next' => $start];
    }

    /** How many student accounts a re-issue would touch. */
    public static function countStudents(PDO $db): int
    {
        try {
            return (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            return 0;
        }
    }

    /**
     * Re-numbers every student under the saved scheme, oldest account first, and carries each
     * one's issued certificates along to the new number so the public page keeps working.
     *
     * The IDs are cleared before they are handed out again: renumbering in place would trip over
     * the unique index the moment a new number collides with a student who has not been reached
     * yet, which is exactly what a re-numbering from a lower start does. A caller that is already
     * in a transaction is joined, so the settings, the IDs and the audit row commit together.
     *
     * @return array{students:int,certificates:int}
     */
    public static function reissueStudents(PDO $db, int $actorId): array
    {
        $scheme = self::scheme($db);
        $joined = $db->inTransaction();
        if (!$joined) $db->beginTransaction();
        try {
            $students = $db->query("SELECT id FROM users WHERE role = 'student' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
            $db->exec("UPDATE users SET account_reference = NULL WHERE role = 'student' AND account_reference IS NOT NULL");

            $take = $db->prepare('UPDATE users SET account_reference = ? WHERE id = ?');
            // Only documents that already carry a number follow the account. A request still
            // waiting for review is issued under whatever ID is current when it gets approved.
            $carry = $db->prepare('UPDATE certificates SET certificate_id = ? WHERE student_id = ? AND certificate_id IS NOT NULL');
            $clash = $db->prepare('SELECT 1 FROM users WHERE account_reference = ? LIMIT 1');
            $sequence = $scheme['next'];
            $issued = 0;
            $certificates = 0;
            foreach ((array) $students as $studentId) {
                $studentId = (int) $studentId;
                $candidate = '';
                while ($sequence <= self::MAX_SEQUENCE) {
                    $test = self::format($scheme['prefix'], $sequence);
                    $clash->execute([$test]);
                    if ($clash->fetch()) { $sequence++; continue; }
                    $candidate = $test;
                    $sequence++;
                    break;
                }
                if ($candidate === '') throw new RuntimeException('The student ID sequence is exhausted.');
                $take->execute([$candidate, $studentId]);
                $carry->execute([$candidate, $studentId]);
                $certificates += $carry->rowCount();
                $issued++;
                self::remember($studentId, $candidate);
            }
            $db->prepare('UPDATE app_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')
                ->execute([(string) $sequence, $actorId, self::NEXT_KEY]);
            if (!$joined) $db->commit();
            return ['students' => $issued, 'certificates' => $certificates];
        } catch (\Throwable $exception) {
            if (!$joined && $db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    /**
     * Renames the ID one account is quoted by, and moves any certificate already issued under the
     * old number with it so the verification page keeps answering to a single ID per person.
     */
    public static function setReference(PDO $db, int $userId, string $rawReference): string
    {
        if ($userId < 1) throw new RuntimeException('That account does not exist yet.');
        $reference = self::cleanReference($rawReference);
        $stmt = $db->prepare('SELECT account_reference FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $current = trim((string) $stmt->fetchColumn());
        if ($current === $reference) return $reference;
        if (self::isTaken($db, $reference, $userId)) {
            throw new RuntimeException($reference . ' is already the ID of another account. Every ID has to be unique.');
        }
        $db->prepare('UPDATE users SET account_reference = ? WHERE id = ?')->execute([$reference, $userId]);
        // A document already printed with the old number would otherwise stop verifying.
        $db->prepare('UPDATE certificates SET certificate_id = ? WHERE student_id = ? AND certificate_id IS NOT NULL')->execute([$reference, $userId]);
        self::remember($userId, $reference);
        return $reference;
    }

    public static function derived(int $userId, string $role = 'student'): string
    {
        $prefix = $role === 'admin' ? self::ADMIN_PREFIX . '-' : self::LEGACY_STUDENT_PREFIX;
        return $prefix . str_pad((string) $userId, 5, '0', STR_PAD_LEFT);
    }

    /** True when another account already answers to this ID. */
    public static function isTaken(PDO $db, string $reference, int $exceptUserId = 0): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM users WHERE account_reference = ? AND id <> ? LIMIT 1');
        $stmt->execute([$reference, $exceptUserId]);
        return (bool) $stmt->fetch();
    }
}
