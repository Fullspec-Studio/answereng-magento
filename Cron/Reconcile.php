<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/** Nightly full push of every enabled, non-paused view whose retry (if any) is due. */
class Reconcile
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
        foreach ($this->state->selectForReconcile($this->settings->enabledStoreIds(), $this->clock->now()) as $storeId) {
            $this->runner->sync($storeId, 'reconcile');
        }
    }
}
