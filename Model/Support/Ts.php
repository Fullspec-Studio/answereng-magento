<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

// phpcs:disable Magento2.Functions.StaticFunction -- pure functions, no state or dependencies to intercept.
/** UTC DATETIME strings for SQL. */
class Ts
{
    /**
     * Format as UTC `Y-m-d H:i:s`.
     *
     * @param \DateTimeImmutable $t
     * @return string
     */
    public static function fmt(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Parse.
     *
     * @param string|null $value
     * @return \DateTimeImmutable|null
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        return ($value === null || $value === '')
            ? null
            : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
