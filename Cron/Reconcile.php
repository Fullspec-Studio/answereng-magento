<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProviderInterface;
use Fullspec\AnswerEng\Model\Support\ClockInterface;
use Fullspec\AnswerEng\Model\Sync\SyncRunnerInterface;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Psr\Log\LoggerInterface;

/** Nightly full push of every enabled, non-paused view whose retry (if any) is due. */
class Reconcile
{
    /**
     * Constructor.
     *
     * @param SettingsProviderInterface $settings
     * @param SyncStateRepository $state
     * @param SyncRunnerInterface $runner
     * @param ClockInterface $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SettingsProviderInterface $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncRunnerInterface $runner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute(): void
    {
        $due = $this->state->selectForReconcile($this->settings->enabledStoreIds(), $this->clock->now());
        foreach ($due as $storeId) {
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
