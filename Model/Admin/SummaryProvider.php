<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\ReadBudget;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Headline visibility for the panel (spec §7). Cache entries and the stored
 * fallback snapshot are keyed by the connection fingerprint, so results from
 * another store, key or engine are never shown; a fallback is labeled stale.
 */
class SummaryProvider
{
    public const CACHE_SECONDS = 900;
    public const NO_RUN_CACHE_SECONDS = 60;
    public const CACHE_PREFIX = 'answereng_summary_';
    public const DASHBOARD = 'https://app.answereng.io/stores/';

    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param EngineClient $engine
     * @param KeyValueCache $cache
     * @param Clock $clock
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly EngineClient $engine,
        private readonly KeyValueCache $cache,
        private readonly Clock $clock
    ) {
    }

    /**
     * For view.
     *
     * With a budget the summary read shares it with the page's other reads; a transient failure stops it,
     * so callers can tell from $budget->stopped() that the remaining reads must not be sent.
     *
     * @param int $storeId
     * @param ReadBudget|null $budget
     * @return SummaryView
     */
    public function forView(int $storeId, ?ReadBudget $budget = null): SummaryView
    {
        $s = $this->settings->forStore($storeId);
        // A test or staging engine's stores are not on app.answereng.io, so there is nothing to link to.
        $url = $s->usesProductionEngine() ? self::DASHBOARD . rawurlencode($s->storeKey) : null;
        if (!$s->enabled || $s->apiKey === '' || $s->storeKey === '') {
            return new SummaryView(SummaryView::DISABLED, $url);
        }
        $key = self::CACHE_PREFIX . $s->fingerprint();
        $cached = $this->cache->load($key);
        if ($cached !== null) {
            return $this->fromJson($cached, SummaryView::FRESH, $url, null);
        }
        $r = $this->engine->summary($s->connection(), $s->storeKey, $budget);
        if ($r->isSuccess() && $r->json !== null) {
            $json = json_encode($r->json, JSON_THROW_ON_ERROR);
            $noRun = ($r->json['latest_run'] ?? null) === null;
            $this->cache->save($key, $json, $noRun ? self::NO_RUN_CACHE_SECONDS : self::CACHE_SECONDS);
            if (!$noRun) {
                $this->state->saveSnapshot($storeId, $s->fingerprint(), $json, $this->clock->now());
            }
            return $this->fromJson($json, SummaryView::FRESH, $url, null);
        }
        if ($r->status === 404) {
            // Synced before with this key: the store was deleted in AnswerEng or the API key changed.
            $syncedBefore = $this->state->get($storeId)->syncedStoreKey === $s->storeKey;
            return new SummaryView($syncedBefore ? SummaryView::OUT_OF_SYNC : SummaryView::NOT_SYNCED, $url);
        }
        if ($r->status === 409 && $r->code() === 'store_pending_deletion') {
            return new SummaryView(SummaryView::PENDING_DELETION, $url);
        }
        $state = $this->state->get($storeId);
        if ($r->isTransient() && $state->snapshotJson !== null && $state->snapshotFingerprint === $s->fingerprint()) {
            return $this->fromJson($state->snapshotJson, SummaryView::STALE, $url, $state->snapshotFetchedAt);
        }
        return new SummaryView(SummaryView::UNAVAILABLE, $url);
    }

    /**
     * Drop the cached summary so the next view refetches ("Check again").
     *
     * @param int $storeId
     * @return void
     */
    public function forget(int $storeId): void
    {
        $this->cache->remove(self::CACHE_PREFIX . $this->settings->forStore($storeId)->fingerprint());
    }

    /**
     * From json.
     *
     * @param string $json
     * @param string $state
     * @param string|null $url
     * @param \DateTimeImmutable|null $fetchedAt
     * @return SummaryView
     */
    private function fromJson(string $json, string $state, ?string $url, ?\DateTimeImmutable $fetchedAt): SummaryView
    {
        $data = json_decode($json, true) ?: [];
        if (($data['latest_run'] ?? null) === null) {
            $preview = is_array($data['preview'] ?? null) ? $data['preview'] : null;
            $previewState = match ($preview['state'] ?? null) {
                'running' => SummaryView::PREVIEW_RUNNING,
                'complete' => SummaryView::PREVIEW_COMPLETE,
                default => null,
            };
            if ($state !== SummaryView::STALE && $previewState !== null) {
                return new SummaryView($previewState, $url, null, null, null, null, $fetchedAt, $preview);
            }
            return new SummaryView(
                $state === SummaryView::STALE ? SummaryView::STALE : SummaryView::NO_RUN,
                $url,
                null,
                null,
                null,
                null,
                $fetchedAt
            );
        }
        $v = is_array($data['visibility'] ?? null) ? $data['visibility'] : [];
        $num = static fn ($x): ?float => is_numeric($x) ? (float) $x : null;
        return new SummaryView(
            $state,
            $url,
            $num($v['rate'] ?? null),
            $num($v['low'] ?? null),
            $num($v['high'] ?? null),
            is_string($data['latest_run']['started_at'] ?? null) ? $data['latest_run']['started_at'] : null,
            $fetchedAt
        );
    }
}
