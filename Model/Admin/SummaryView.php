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
     * @param string|null $dashboardUrl null when the view uses a test engine
     * @param float|null $rate
     * @param float|null $low
     * @param float|null $high
     * @param string|null $runStartedAt
     * @param \DateTimeImmutable|null $fetchedAt
     * @param array<string,mixed>|null $preview
     * @param list<array{started_at:string|null,rate:float,low:float,high:float}> $trend visibility_trend
     * @param array<string,array{rate:float,low:float,high:float}> $engineBreakdown by raw engine id
     * @param array<string,array{rate:float,low:float,high:float}> $shareOfVoice by brand; "__store__" is you
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $dashboardUrl,
        public readonly ?float $rate = null,
        public readonly ?float $low = null,
        public readonly ?float $high = null,
        public readonly ?string $runStartedAt = null,
        public readonly ?\DateTimeImmutable $fetchedAt = null,
        public readonly ?array $preview = null,
        public readonly array $trend = [],
        public readonly array $engineBreakdown = [],
        public readonly array $shareOfVoice = []
    ) {
    }
}
