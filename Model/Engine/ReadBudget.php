<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

use Fullspec\AnswerEng\Model\Support\ClockInterface;

/**
 * A shared time budget for the reads behind one page view: 20 s in total, each attempt at most 15 s
 * and never more than what is left, a retry only when at least 5 s remain.
 */
class ReadBudget
{
    public const TOTAL = 20;
    public const ATTEMPT_MAX = 15;
    public const RETRY_MIN = 5;

    /** @var int */
    private readonly int $startedAt;
    /** @var bool */
    private bool $stopped = false;

    /**
     * Constructor.
     *
     * @param ClockInterface $clock
     * @param int $totalSeconds
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $totalSeconds = self::TOTAL
    ) {
        $this->startedAt = $clock->now()->getTimestamp();
    }

    /**
     * Whole seconds left, never negative.
     *
     * @return int
     */
    public function remaining(): int
    {
        return max(0, $this->totalSeconds - ($this->clock->now()->getTimestamp() - $this->startedAt));
    }

    /**
     * Timeout for the next attempt.
     *
     * @return int
     */
    public function attemptTimeout(): int
    {
        return min(self::ATTEMPT_MAX, $this->remaining());
    }

    /**
     * Whether enough time is left to retry.
     *
     * @return bool
     */
    public function allowsRetry(): bool
    {
        return $this->remaining() >= self::RETRY_MIN;
    }

    /**
     * Stop the remaining reads (after a transient failure).
     *
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Whether no further read may be sent.
     *
     * @return bool
     */
    public function stopped(): bool
    {
        return $this->stopped || $this->remaining() <= 0;
    }
}
