<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Psr\Log\LoggerInterface;

/** Nightly full push of every enabled, non-paused view whose retry (if any) is due. */
class Reconcile
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncRunner $runner,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->state->selectForReconcile($this->settings->enabledStoreIds(), $this->clock->now()) as $storeId) {
            try {
                $this->runner->sync($storeId, 'reconcile');
            } catch (\Throwable $e) {
                $this->logger->error('AnswerEng: sync failed', [
                    'store_id' => $storeId,
                    'exception_class' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }
    }
}
