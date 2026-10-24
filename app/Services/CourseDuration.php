<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class CourseDuration
{
    /** Return an UTC database timestamp for supported labels such as "6 Weeks". */
    public static function expiry(string $label, ?DateTimeImmutable $start = null): string
    {
        if (!preg_match('/^\s*([1-9][0-9]{0,2})\s*(day|days|week|weeks|month|months|year|years)\s*$/i', $label, $matches)) {
            throw new RuntimeException('Set this course duration as a number of days, weeks, months, or years before granting access.');
        }

        $amount = (int) $matches[1];
        $unit = strtolower($matches[2]);
        $designator = match (rtrim($unit, 's')) {
            'day' => 'D', 'week' => 'W', 'month' => 'M', 'year' => 'Y',
        };
        $start ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $end = $start->add(new DateInterval('P' . $amount . $designator));

        return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
