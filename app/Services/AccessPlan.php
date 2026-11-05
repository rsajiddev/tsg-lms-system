<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

/**
 * Which delivery plan an enrolment was bought on, and what that plan unlocks.
 *
 * The catalogue has offered two options (Recorded Only, Live + Recorded) from the start and the
 * checkout has always posted the chosen one, but until live class links existed the choice was
 * written only into the payment audit blob where no page could read it back. This class is the
 * single place that turns the stored value into an answer about what a student may see.
 *
 * The live-class gate deliberately fails closed. Everything else added around the player fails
 * open, because the cost there was locking a paying student out of a lesson they already own. The
 * cost here is the opposite: a meeting link handed to the wrong account cannot be taken back. An
 * unknown, missing or unreadable plan is therefore Recorded Only, always.
 */
final class AccessPlan
{
    public const RECORDED = 'recorded_only';
    public const LIVE = 'live_recorded';

    /** The columns this feature writes, checked against the database before it uses them. */
    private const COLUMNS = ['lessons.meeting_url', 'lessons.meeting_at', 'payments.plan_code', 'student_course_access.plan_code'];

    /** @var array<string, bool> */
    private static array $columnState = [];

    /**
     * Storeable codes with the label the Academy uses for them, in checkout and admin order.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::RECORDED => 'Recorded Only',
            self::LIVE => 'Live + Recorded',
        ];
    }

    /** The value written to payments.plan_code and student_course_access.plan_code. */
    public static function normalise(mixed $code): string
    {
        return is_string($code) && array_key_exists($code, self::options()) ? $code : self::RECORDED;
    }

    /** The only rule the student-facing pages ask: may this enrolment see a live class link? */
    public static function allowsLive(?string $code): bool
    {
        return $code === self::LIVE;
    }

    /**
     * Label for display. A legacy payment whose plan was never recorded reads as the entitlement
     * the account actually has today - Recorded Only - rather than claiming a purchase nobody saw.
     */
    public static function label(?string $code): string
    {
        return self::options()[self::normalise($code)];
    }

    /**
     * Whether one of the live-class columns has been imported into this database yet.
     *
     * Re-importing the schema file is the admin's action, not the code's, so every query that reads
     * or writes these columns has to compose itself without them until that has happened. The
     * answer is cached per request, and a check that cannot run reports false - which reads as
     * "no plan recorded", and the gate is closed on that.
     */
    public static function columnStored(PDO $db, string $tableColumn): bool
    {
        if (!in_array($tableColumn, self::COLUMNS, true)) {
            throw new InvalidArgumentException('Not a live-class column: ' . $tableColumn);
        }
        if (isset(self::$columnState[$tableColumn])) {
            return self::$columnState[$tableColumn];
        }
        [$table, $column] = explode('.', $tableColumn);
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            self::$columnState[$tableColumn] = (int) $stmt->fetchColumn() > 0;
        } catch (\PDOException $exception) {
            error_log('[ACCESS PLAN] Column availability check failed for ' . $tableColumn . ': ' . $exception->getMessage());
            self::$columnState[$tableColumn] = false;
        }
        return self::$columnState[$tableColumn];
    }

    /** Resets the per-request cache, used by the test harnesses when they swap databases. */
    public static function forgetColumnState(): void
    {
        self::$columnState = [];
    }
}
