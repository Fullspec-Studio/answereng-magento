<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
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
    public const DASHBOARD = 'https://app.answereng.io/stores/';

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly EngineClient $engine,
        private readonly KeyValueCache $cache,
        private readonly Clock $clock
    ) {
    }

    public function forView(int $storeId): SummaryView
    {
        $s = $this->settings->forStore($storeId);
        $url = self::DASHBOARD . rawurlencode($s->storeKey);
        if (!$s->enabled || $s->apiKey === '' || $s->storeKey === '') {
            return new SummaryView(SummaryView::DISABLED, $url);
        }
        $key = 'answereng_summary_' . $s->fingerprint();
        $cached = $this->cache->load($key);
        if ($cached !== null) {
            return $this->fromJson($cached, SummaryView::FRESH, $url, null);
        }
        $r = $this->engine->summary($s->connection(), $s->storeKey);
        if ($r->isSuccess() && $r->json !== null) {
            $json = json_encode($r->json, JSON_THROW_ON_ERROR);
            $this->cache->save($key, $json, self::CACHE_SECONDS);
            $this->state->saveSnapshot($storeId, $s->fingerprint(), $json, $this->clock->now());
            return $this->fromJson($json, SummaryView::FRESH, $url, null);
        }
        if ($r->status === 404 && $r->code() === 'unknown_store') {
            return new SummaryView(SummaryView::NOT_SYNCED, $url);
        }
        if ($r->status === 409 && $r->code() === 'store_pending_deletion') {
            return new SummaryView(SummaryView::PENDING_DELETION, $url);
        }
        $state = $this->state->get($storeId);
        if ($state->snapshotJson !== null && $state->snapshotFingerprint === $s->fingerprint()) {
            return $this->fromJson($state->snapshotJson, SummaryView::STALE, $url, $state->snapshotFetchedAt);
        }
        return new SummaryView(SummaryView::UNAVAILABLE, $url);
    }

    private function fromJson(string $json, string $state, string $url, ?\DateTimeImmutable $fetchedAt): SummaryView
    {
        $data = json_decode($json, true) ?: [];
        if (($data['latest_run'] ?? null) === null) {
            return new SummaryView($state === SummaryView::STALE ? SummaryView::STALE : SummaryView::NO_RUN, $url,
                null, null, null, null, $fetchedAt);
        }
        $v = is_array($data['visibility'] ?? null) ? $data['visibility'] : [];
        $num = static fn ($x): ?float => is_numeric($x) ? (float) $x : null;
        return new SummaryView($state, $url, $num($v['rate'] ?? null), $num($v['low'] ?? null), $num($v['high'] ?? null),
            is_string($data['latest_run']['started_at'] ?? null) ? $data['latest_run']['started_at'] : null, $fetchedAt);
    }
}
