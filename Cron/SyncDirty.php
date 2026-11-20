<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Psr\Log\LoggerInterface;

/**
 * Every 5 minutes: views dirty and quiet for 5 minutes, plus due retries (spec §6).
 * Views with auto-sync off only get their due retries.
 */
class SyncDirty
{
    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param SyncRunner $runner
     * @param Clock $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncRunner $runner,
        private readonly Clock $clock,
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
        $enabled = $this->settings->enabledStoreIds();
        $manual = array_values(array_filter(
            $enabled,
            fn (int $id) => !$this->settings->forStore($id)->autoSync
        ));
        foreach ($this->state->selectForDebounce($enabled, $this->clock->now(), $manual) as $storeId) {
            try {
                $this->runner->sync($storeId, 'debounce');
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
