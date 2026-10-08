<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Catalog\CatalogBuilder;
use Fullspec\AnswerEng\Model\Catalog\ExportResult;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Support\SyncLock;
use Psr\Log\LoggerInterface;

/**
 * One sync of one store view (spec §6): lock → revision + settings snapshot
 * → guards → export → push (or clear) → classify → one revision-guarded
 * state update. A successful remote side effect is recorded in the registry
 * unconditionally, even when the state update is superseded.
 */
class Syncer implements SyncRunner
{
    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param SyncLog $log
     * @param CatalogBuilder $builder
     * @param EngineClient $engine
     * @param Classifier $classifier
     * @param RemoteStoreRegistry $registry
     * @param SyncLock $lock
     * @param Clock $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncLog $log,
        private readonly CatalogBuilder $builder,
        private readonly EngineClient $engine,
        private readonly Classifier $classifier,
        private readonly RemoteStoreRegistry $registry,
        private readonly SyncLock $lock,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function sync(int $storeId, string $source, bool $manual = false): SyncResult
    {
        $lockName = 'answereng_sync_' . $storeId;
        if (!$this->lock->acquire($lockName)) {
            $this->log->append($storeId, $this->clock->now(), $source, 'skipped', 'another sync is running');
            return new SyncResult(SyncResult::SKIPPED, (string) __('Another sync for this store view is running.'));
        }
        try {
            return $this->run($storeId, $source, $manual);
        } finally {
            $this->lock->release($lockName);
        }
    }

    /**
     * The sync itself, under the view's lock.
     *
     * @param int $storeId
     * @param string $source
     * @param bool $manual
     * @return SyncResult
     */
    private function run(int $storeId, string $source, bool $manual): SyncResult
    {
        $revision = $this->state->get($storeId)->configRevision;
        $s = $this->settings->forStore($storeId);
        if (!$s->enabled) {
            return new SyncResult(SyncResult::SKIPPED, (string) __('This store view is not enabled for AnswerEng.'));
        }
        $guards = [['no_api_key', $s->apiKey === ''], ['no_brand_names', $s->brandNames === []],
            ['no_store_key', $s->storeKey === ''], ['duplicate_store_key', $this->isDuplicate($s)]];
        foreach ($guards as [$reason, $hit]) {
            if ($hit) {
                return $this->blocked($storeId, $revision, $source, $reason);
            }
        }
        $now = $this->clock->now();
        if ($manual && ($until = $this->state->throttledUntil($storeId, $now)) !== null) {
            return new SyncResult(SyncResult::THROTTLED, (string) __(
                'Rate limited by AnswerEng — try again after %1 UTC.',
                $until->format('Y-m-d H:i')
            ), $until);
        }

        $startedAt = $now;
        try {
            $export = $this->builder->build($s);
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng catalog export failed', ['store_id' => $storeId,
                'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $applied = $this->state->recordTransient(
                $storeId,
                $revision,
                $this->clock->now(),
                null,
                SyncStateRepository::CAUSE_EXPORT_FAILED
            );
            if (!$applied) {
                return $this->superseded($storeId, $source);
            }
            $this->log->append(
                $storeId,
                $this->clock->now(),
                $source,
                SyncResult::FAILED,
                'catalog export failed: ' . get_class($e)
            );
            return new SyncResult(SyncResult::FAILED, $this->transientResult($storeId)->message);
        }

        if ($export->kind === ExportResult::TOO_MANY) {
            return $this->blocked(
                $storeId,
                $revision,
                $source,
                'too_many_products',
                "{$export->count} eligible products"
            );
        }
        if ($export->kind === ExportResult::EMPTY) {
            return $this->clearEmpty($s, $revision, $startedAt, $source);
        }

        $result = $this->engine->postCatalog($s->connection(), $export->json);
        $this->afterEngineCall($s, $result, true);
        $outcome = $this->classifier->classify($result);
        return $this->apply($s, $revision, $startedAt, $outcome, $export->count, $source, null);
    }

    /**
     * Clear empty.
     *
     * @param Settings $s
     * @param int $revision
     * @param \DateTimeImmutable $startedAt
     * @param string $source
     * @return SyncResult
     */
    private function clearEmpty(Settings $s, int $revision, \DateTimeImmutable $startedAt, string $source): SyncResult
    {
        if ($this->state->get($s->storeId)->syncedStoreKey === null) {
            return $this->apply(
                $s,
                $revision,
                $startedAt,
                new Outcome(OutcomeKind::AlreadyClear, null, 'never synced'),
                0,
                $source,
                'catalog_cleared'
            );
        }
        $result = $this->engine->clearCatalog($s->connection(), $s->storeKey);
        $this->afterEngineCall($s, $result, false);
        $outcome = $this->classifier->classify($result, true);
        return $this->apply($s, $revision, $startedAt, $outcome, 0, $source, 'catalog_cleared');
    }

    /**
     * After engine call.
     *
     * @param Settings $s
     * @param EngineResult $result
     * @param bool $isPush
     * @return void
     */
    private function afterEngineCall(Settings $s, EngineResult $result, bool $isPush): void
    {
        if ($result->account === null) {
            if ($isPush && $result->isSuccess()) {
                $this->logger->warning(
                    'AnswerEng push succeeded without an account header',
                    ['store_id' => $s->storeId]
                );
            }
            return;
        }
        $this->state->recordAccount($s->storeId, $result->account, $s->apiKeyHash());
        if ($isPush && $result->isSuccess()) {
            $this->registry->recordPush(
                $s->engineUrl,
                $result->account,
                $s->storeKey,
                $s->storeId,
                $this->clock->now()
            );
        }
    }

    /**
     * Apply.
     *
     * @param Settings $s
     * @param int $revision
     * @param \DateTimeImmutable $startedAt
     * @param Outcome $o
     * @param int $count
     * @param string $source
     * @param string|null $successReason
     * @return SyncResult
     */
    private function apply(
        Settings $s,
        int $revision,
        \DateTimeImmutable $startedAt,
        Outcome $o,
        int $count,
        string $source,
        ?string $successReason
    ): SyncResult {
        $now = $this->clock->now();
        $id = $s->storeId;
        switch ($o->kind) {
            case OutcomeKind::Success:
            case OutcomeKind::AlreadyClear:
                $applied = $this->state->recordSuccess(
                    $id,
                    $revision,
                    $startedAt,
                    $now,
                    $count,
                    $successReason === null ? $s->storeKey : null,
                    $successReason
                );
                $result = $successReason === null
                    ? new SyncResult(SyncResult::PUSHED, (string) __('Sent %1 products to AnswerEng.', $count))
                    : new SyncResult(SyncResult::CLEARED, StateMessage::cleared());
                break;
            case OutcomeKind::Transient:
            case OutcomeKind::Throttled:
                $applied = $this->state->recordTransient(
                    $id,
                    $revision,
                    $now,
                    $o->kind === OutcomeKind::Throttled ? $o->retryAfterSeconds : null,
                    $o->reason === 'run_in_progress' ? SyncStateRepository::CAUSE_RUN_IN_PROGRESS : null
                );
                $result = $this->transientResult($id);
                break;
            default:
                $applied = $this->state->recordPaused($id, $revision, (string) $o->reason, $startedAt);
                $result = new SyncResult(
                    SyncResult::PAUSED,
                    StateMessage::paused((string) $o->reason, $o->detail, $o->purgeAfter)
                );
        }
        if (!$applied) {
            return $this->superseded($id, $source);
        }
        $this->log->append($id, $now, $source, $result->status, $o->detail);
        return $result;
    }

    /**
     * The outcome was discarded because the configuration changed meanwhile (revision guard).
     *
     * @param int $storeId
     * @param string $source
     */
    private function superseded(int $storeId, string $source): SyncResult
    {
        $this->log->append(
            $storeId,
            $this->clock->now(),
            $source,
            SyncResult::SUPERSEDED,
            'superseded by a configuration change'
        );
        return new SyncResult(SyncResult::SUPERSEDED, (string) __('The configuration changed during the sync; '
            . 'the new settings will sync shortly.'));
    }

    /**
     * RETRYING or EXHAUSTED for the state a transient failure just wrote, worded by StateMessage.
     *
     * @param int $storeId
     */
    private function transientResult(int $storeId): SyncResult
    {
        $st = $this->state->get($storeId);
        return new SyncResult(
            $st->state === SyncStateRepository::EXHAUSTED ? SyncResult::EXHAUSTED : SyncResult::RETRYING,
            (string) StateMessage::for($st->state, $st->reason, $st->retryReason)
        );
    }

    /**
     * Blocked.
     *
     * @param int $storeId
     * @param int $revision
     * @param string $source
     * @param string $reason
     * @param string|null $detail
     * @return SyncResult
     */
    private function blocked(
        int $storeId,
        int $revision,
        string $source,
        string $reason,
        ?string $detail = null
    ): SyncResult {
        if (!$this->state->recordBlocked($storeId, $revision, $reason)) {
            return $this->superseded($storeId, $source);
        }
        $this->log->append($storeId, $this->clock->now(), $source, 'blocked', $detail ?? $reason);
        return new SyncResult(SyncResult::BLOCKED, StateMessage::blocked($reason));
    }

    /**
     * Is duplicate.
     *
     * @param Settings $s
     * @return bool
     */
    private function isDuplicate(Settings $s): bool
    {
        foreach ($this->settings->enabledStoreIds() as $other) {
            if ($other !== $s->storeId && $this->settings->forStore($other)->storeKey === $s->storeKey) {
                return true;
            }
        }
        return false;
    }
}
