<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

class SummaryView
{
    public const DISABLED = 'disabled';
    public const FRESH = 'fresh';
    public const STALE = 'stale';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_SYNCED = 'not_synced';
    /** The engine has no store under this key and API key, but this view synced with this key before. */
    public const OUT_OF_SYNC = 'out_of_sync';
    public const NO_RUN = 'no_run';
    public const PENDING_DELETION = 'pending_deletion';
    public const PREVIEW_RUNNING = 'preview_running';
    public const PREVIEW_COMPLETE = 'preview_complete';

    /**
     * Constructor.
     *
     * @param string $state
     * @param string $dashboardUrl
     * @param float|null $rate
     * @param float|null $low
     * @param float|null $high
     * @param string|null $runStartedAt
     * @param \DateTimeImmutable|null $fetchedAt
     * @param array<string,mixed>|null $preview
     */
    public function __construct(
        public readonly string $state,
        public readonly string $dashboardUrl,
        public readonly ?float $rate = null,
        public readonly ?float $low = null,
        public readonly ?float $high = null,
        public readonly ?string $runStartedAt = null,
        public readonly ?\DateTimeImmutable $fetchedAt = null,
        public readonly ?array $preview = null
    ) {
    }
}
