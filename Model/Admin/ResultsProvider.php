<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\ReadBudget;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncState;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * The status page's engine reads: summary, then suggested fixes, then applied fixes, sharing one
 * ReadBudget. A transient failure stops the remaining reads and the page falls back to the snapshot
 * for whatever it holds. Cache entries and the snapshot are keyed by the connection fingerprint.
 */
class ResultsProvider
{
    public const SUGGESTED_PREFIX = 'answereng_fixes_suggested_';
    public const APPLIED_PREFIX = 'answereng_fixes_applied_';

    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param EngineClient $engine
     * @param KeyValueCache $cache
     * @param Clock $clock
     * @param SummaryProvider $summaries
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly EngineClient $engine,
        private readonly KeyValueCache $cache,
        private readonly Clock $clock,
        private readonly SummaryProvider $summaries
    ) {
    }

    /**
     * Results for the status page.
     *
     * @param int $storeId
     * @param ReadBudget|null $budget shared with the page's other engine reads; a new one when null
     * @return ResultsView
     */
    public function forView(int $storeId, ?ReadBudget $budget = null): ResultsView
    {
        $budget ??= new ReadBudget($this->clock);
        $summary = $this->summaries->forView($storeId, $budget);
        $s = $this->settings->forStore($storeId);
        if ($summary->state === SummaryView::STALE) {
            return $this->fromSnapshot($summary, $s, $this->state->get($storeId));
        }
        // FRESH means a completed run (no run and previews have their own states).
        if ($summary->state !== SummaryView::FRESH) {
            return new ResultsView($summary, null, null, false);
        }
        $fp = $s->fingerprint();
        $suggested = $this->load($s, 'suggested', self::SUGGESTED_PREFIX . $fp, $budget);
        if ($suggested === null) {
            if ($budget->stopped()) {
                return $this->fromSnapshot($summary, $s, $this->state->get($storeId));
            }
            return new ResultsView($summary, null, null, false);
        }
        $applied = $this->load($s, 'applied', self::APPLIED_PREFIX . $fp, $budget);
        if ($applied !== null && ($suggested[1] || $applied[1])) {
            $this->state->saveFixesSnapshot($storeId, $fp, $suggested[0], $applied[0]);
        }
        return new ResultsView(
            $summary,
            $this->decode($suggested[0]),
            $applied === null ? null : $this->decode($applied[0]),
            false
        );
    }

    /**
     * Drop the cached summary and both fix lists ("Refresh", and after marking a fix applied or undoing it:
     * the summary carries the suggested-fix total).
     *
     * @param int $storeId
     * @return void
     */
    public function forget(int $storeId): void
    {
        $this->summaries->forget($storeId);
        $this->forgetFixes($storeId);
    }

    /**
     * Drop both cached fix lists, keeping the summary.
     *
     * @param int $storeId
     * @return void
     */
    public function forgetFixes(int $storeId): void
    {
        $fp = $this->settings->forStore($storeId)->fingerprint();
        $this->cache->remove(self::SUGGESTED_PREFIX . $fp);
        $this->cache->remove(self::APPLIED_PREFIX . $fp);
    }

    /**
     * One fix list as JSON from the cache or the engine.
     *
     * @param Settings $s
     * @param string $status
     * @param string $key
     * @param ReadBudget $budget
     * @return array{0: string, 1: bool}|null the JSON and whether it was fetched now; null when unavailable
     */
    private function load(Settings $s, string $status, string $key, ReadBudget $budget): ?array
    {
        $cached = $this->cache->load($key);
        if ($cached !== null) {
            return [$cached, false];
        }
        $r = $this->engine->fixes($s->connection(), $s->storeKey, $status, $budget);
        if (!$r->isSuccess() || $r->json === null || !array_is_list($r->json)) {
            return null;
        }
        $json = json_encode($r->json, JSON_THROW_ON_ERROR);
        // Fixes are only read once a run completed, so the summary's long TTL applies.
        $this->cache->save($key, $json, SummaryProvider::CACHE_SECONDS);
        return [$json, true];
    }

    /**
     * The fix lists from the fallback snapshot, labeled stale.
     *
     * @param SummaryView $summary
     * @param Settings $s
     * @param SyncState $state
     * @return ResultsView
     */
    private function fromSnapshot(SummaryView $summary, Settings $s, SyncState $state): ResultsView
    {
        if ($state->snapshotFingerprint !== $s->fingerprint()) {
            return new ResultsView($summary, null, null, true);
        }
        return new ResultsView(
            $summary,
            $state->snapshotFixesJson === null ? null : $this->decode($state->snapshotFixesJson),
            $state->snapshotAppliedJson === null ? null : $this->decode($state->snapshotAppliedJson),
            true
        );
    }

    /**
     * Decode.
     *
     * @param string $json
     * @return list<FixView>
     */
    private function decode(string $json): array
    {
        return FixView::listFromJson(json_decode($json, true));
    }
}
