<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;

/** Marks enabled views dirty; the 5-minute cron pushes them once they go quiet. */
class ChangeTracker
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param list<int> $websiteIds
     */
    public function markWebsites(array $websiteIds): void
    {
        $ids = $this->settings->storeIdsForWebsites(array_values(array_unique(array_map('intval', $websiteIds))));
        $this->state->markDirty($ids, $this->clock->now());
    }

    public function markAll(): void
    {
        $this->state->markDirty($this->settings->enabledStoreIds(), $this->clock->now());
    }
}
