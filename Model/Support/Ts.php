<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

/** UTC DATETIME strings for SQL. */
class Ts
{
    public static function fmt(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function parse(?string $value): ?\DateTimeImmutable
    {
        return ($value === null || $value === '')
            ? null
            : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
