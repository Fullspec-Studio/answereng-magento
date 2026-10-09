<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

class SyncResult
{
    public const PUSHED = 'pushed';
    public const CLEARED = 'cleared';
    public const BLOCKED = 'blocked';
    public const PAUSED = 'paused';
    public const RETRYING = 'retrying';
    public const EXHAUSTED = 'exhausted';
    public const SKIPPED = 'skipped';
    public const THROTTLED = 'throttled';
    public const SUPERSEDED = 'superseded';
    public const FAILED = 'failed';

    /**
     * Constructor.
     *
     * @param string $status
     * @param string $message
     * @param \DateTimeImmutable|null $until
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?\DateTimeImmutable $until = null
    ) {
    }

    /**
     * Is error.
     *
     * @return bool
     */
    public function isError(): bool
    {
        return in_array(
            $this->status,
            [self::BLOCKED, self::PAUSED, self::RETRYING, self::EXHAUSTED, self::THROTTLED, self::FAILED],
            true
        );
    }
}
