<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;

/**
 * Spec §6 "Configuration saves": compares each view's EFFECTIVE settings
 * hash, so a default- or website-scope change bumps exactly the views it
 * affects. A bump marks the view dirty, resets retries and pauses, and
 * makes any in-flight push's outcome superseded.
 */
class ConfigChangeHandler
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly Clock $clock
    ) {
    }

    /**
     * @return list<int>
     */
    public function handle(): array
    {
        $bumped = [];
        foreach ($this->settings->allStoreIds() as $storeId) {
            $hash = $this->settings->forStore($storeId)->hash();
            if ($this->state->applySettingsHash($storeId, $hash, $this->clock->now())) {
                $bumped[] = $storeId;
            }
        }
        return $bumped;
    }
}
