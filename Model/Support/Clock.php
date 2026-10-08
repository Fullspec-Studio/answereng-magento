<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

interface Clock
{
    /** Current time in UTC. */
    public function now(): \DateTimeImmutable;
}
