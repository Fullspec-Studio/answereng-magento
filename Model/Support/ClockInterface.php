<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

interface ClockInterface
{
    /**
     * Current time in UTC.
     */
    public function now(): \DateTimeImmutable;
}
