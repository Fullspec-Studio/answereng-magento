<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

class ConnectionReport
{
    public const NO_KEY = 'no_key';
    public const UNREACHABLE = 'unreachable';
    public const REJECTED = 'rejected';
    public const FOUND = 'found';
    public const WAITING_FIRST_RUN = 'waiting_first_run';
    public const FIRST_SYNC_NEEDED = 'first_sync_needed';

    /**
     * Constructor.
     *
     * @param string $status
     * @param string $message
     * @param list<string> $existingKeys
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $existingKeys = []
    ) {
    }

    /**
     * Whether the connection works (the store may still need its first sync).
     *
     * @return bool
     */
    public function ok(): bool
    {
        return in_array($this->status, [self::FOUND, self::WAITING_FIRST_RUN, self::FIRST_SYNC_NEEDED], true);
    }
}
