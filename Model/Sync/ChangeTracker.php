<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;
use Fullspec\AnswerEng\Model\Support\ClockInterface;

/** Marks enabled views dirty; the 5-minute cron pushes them once they go quiet. */
class ChangeTracker
{
    /**
     * Constructor.
     *
     * @param SettingsProviderInterface $settings
     * @param SyncStateRepository $state
     * @param ClockInterface $clock
     */
    public function __construct(
        private readonly SettingsProviderInterface $settings,
        private readonly SyncStateRepository $state,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Mark websites.
     *
     * @param list<int> $websiteIds
     */
    public function markWebsites(array $websiteIds): void
    {
        $ids = $this->settings->storeIdsForWebsites(array_values(array_unique(array_map('intval', $websiteIds))));
        $this->state->markDirty($ids, $this->clock->now());
    }

    /**
     * Mark all.
     *
     * @return void
     */
    public function markAll(): void
    {
        $this->state->markDirty($this->settings->enabledStoreIds(), $this->clock->now());
    }
}
