<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Admin\KeyValueCache;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;

/**
 * Spec §6 "Configuration saves": compares each view's EFFECTIVE settings
 * hash, so a default- or website-scope change bumps exactly the views it
 * affects. A bump marks the view dirty, resets retries and pauses, and
 * makes any in-flight push's outcome superseded. A view whose connection
 * fingerprint changed also loses its cached summary and fallback snapshot
 * (spec §7), so switching away and back never shows an old result as fresh.
 */
class ConfigChangeHandler
{
    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param Clock $clock
     * @param KeyValueCache $cache
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly Clock $clock,
        private readonly KeyValueCache $cache
    ) {
    }

    /**
     * Handle.
     *
     * @return list<int>
     */
    public function handle(): array
    {
        $bumped = [];
        foreach ($this->settings->allStoreIds() as $storeId) {
            $s = $this->settings->forStore($storeId);
            $this->dropStaleSummary($storeId, $s->fingerprint());
            $hash = $s->hash();
            if ($this->state->applySettingsHash($storeId, $hash, $this->clock->now())) {
                $bumped[] = $storeId;
            }
        }
        return $bumped;
    }

    /**
     * Drop stale summary.
     *
     * Cache entry and snapshot are always written together under the same fingerprint, so the stored one
     * names the only live cache entry.
     *
     * @param int $storeId
     * @param string $fingerprint
     */
    private function dropStaleSummary(int $storeId, string $fingerprint): void
    {
        $old = $this->state->get($storeId)->snapshotFingerprint;
        if ($old !== null && $old !== $fingerprint) {
            $this->cache->remove(SummaryProvider::CACHE_PREFIX . $old);
            $this->state->clearSnapshot($storeId);
        }
    }
}
