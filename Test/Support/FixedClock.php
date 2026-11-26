<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Support\ClockInterface;

final class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-07 12:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+$seconds seconds");
    }
}
