<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/** Every 5 minutes: views dirty and quiet for 5 minutes, plus due retries (spec §6). */
class SyncDirty
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncRunner $runner,
        private readonly Clock $clock
    ) {
    }

    public function execute(): void
    {
        foreach ($this->state->selectForDebounce($this->settings->enabledStoreIds(), $this->clock->now()) as $storeId) {
            $this->runner->sync($storeId, 'debounce');
        }
    }
}
