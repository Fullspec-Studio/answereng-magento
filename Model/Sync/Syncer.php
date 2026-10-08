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

    private function run(int $storeId, string $source, bool $manual): SyncResult
    {
        $revision = $this->state->get($storeId)->configRevision;
        $s = $this->settings->forStore($storeId);
        if (!$s->enabled) {
            return new SyncResult(SyncResult::SKIPPED, (string) __('This store view is not enabled for AnswerEng.'));
        }
        foreach ([['no_api_key', $s->apiKey === ''], ['no_brand_names', $s->brandNames === []],
                     ['no_store_key', $s->storeKey === ''], ['duplicate_store_key', $this->isDuplicate($s)]] as [$reason, $hit]) {
            if ($hit) {
                return $this->blocked($storeId, $revision, $source, $reason);
            }
        }
        $now = $this->clock->now();
        if ($manual && ($until = $this->state->throttledUntil($storeId, $now)) !== null) {
            return new SyncResult(SyncResult::THROTTLED, (string) __('Rate limited by AnswerEng — try again after %1 UTC.',
                $until->format('Y-m-d H:i')), $until);
        }

        $startedAt = $now;
        try {
            $export = $this->builder->build($s);
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng catalog export failed', ['store_id' => $storeId,
                'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $this->state->recordTransient($storeId, $revision, $this->clock->now(), null);
            $this->log->append($storeId, $this->clock->now(), $source, 'error', 'catalog export failed: ' . get_class($e));
            return new SyncResult(SyncResult::FAILED, (string) __('Reading the catalog failed; nothing was sent. It will be retried.'));
        }

        if ($export->kind === ExportResult::TOO_MANY) {
            return $this->blocked($storeId, $revision, $source, 'too_many_products', "{$export->count} eligible products");
        }
        if ($export->kind === ExportResult::EMPTY) {
            return $this->clearEmpty($s, $revision, $startedAt, $source);
        }

        $result = $this->engine->postCatalog($s->connection(), $export->json);
        $this->afterEngineCall($s, $result, true);
        return $this->apply($s, $revision, $startedAt, $this->classifier->classify($result), $export->count, $source, null);
    }

    private function clearEmpty(Settings $s, int $revision, \DateTimeImmutable $startedAt, string $source): SyncResult
    {
        if ($this->state->get($s->storeId)->syncedStoreKey === null) {
            return $this->apply($s, $revision, $startedAt,
                new Outcome(OutcomeKind::AlreadyClear, null, 'never synced'), 0, $source, 'catalog_cleared');
        }
        $result = $this->engine->clearCatalog($s->connection(), $s->storeKey);
        $this->afterEngineCall($s, $result, false);
        return $this->apply($s, $revision, $startedAt, $this->classifier->classify($result, true), 0, $source, 'catalog_cleared');
    }

    private function afterEngineCall(Settings $s, EngineResult $result, bool $isPush): void
    {
        if ($result->account === null) {
            if ($isPush && $result->isSuccess()) {
                $this->logger->warning('AnswerEng push succeeded without an account header', ['store_id' => $s->storeId]);
            }
            return;
        }
        $this->state->recordAccount($s->storeId, $result->account, $s->apiKeyHash());
        if ($isPush && $result->isSuccess()) {
            $this->registry->recordPush($s->engineUrl, $result->account, $s->storeKey, $s->storeId, $this->clock->now());
        }
    }

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
                $applied = $this->state->recordSuccess($id, $revision, $startedAt, $now, $count,
                    $successReason === null ? $s->storeKey : null, $successReason);
                $result = $successReason === null
                    ? new SyncResult(SyncResult::PUSHED, (string) __('Sent %1 products to AnswerEng.', $count))
                    : new SyncResult(SyncResult::CLEARED, StateMessage::cleared());
                break;
            case OutcomeKind::Transient:
            case OutcomeKind::Throttled:
                $applied = $this->state->recordTransient($id, $revision, $now,
                    $o->kind === OutcomeKind::Throttled ? $o->retryAfterSeconds : null);
                $exhausted = $this->state->get($id)->state === SyncStateRepository::EXHAUSTED;
                $result = $exhausted
                    ? new SyncResult(SyncResult::EXHAUSTED, (string) __('AnswerEng could not be reached after 3 attempts. '
                        . 'The nightly sync will try again.'))
                    : new SyncResult(SyncResult::RETRYING, $o->kind === OutcomeKind::Throttled
                        ? (string) __('Rate limited, retrying.')
                        : (string) __('AnswerEng is waking up or unreachable; retrying shortly.'));
                break;
            default:
                $applied = $this->state->recordPaused($id, $revision, (string) $o->reason, $startedAt);
                $result = new SyncResult(SyncResult::PAUSED,
                    StateMessage::paused((string) $o->reason, $o->detail, $o->purgeAfter));
        }
        if (!$applied) {
            $this->log->append($id, $now, $source, 'superseded', 'superseded by a configuration change');
            return new SyncResult(SyncResult::SUPERSEDED, (string) __('The configuration changed during the sync; '
                . 'the new settings will sync shortly.'));
        }
        $this->log->append($id, $now, $source, $result->status, $o->detail);
        return $result;
    }

    private function blocked(int $storeId, int $revision, string $source, string $reason, ?string $detail = null): SyncResult
    {
        $this->state->recordBlocked($storeId, $revision, $reason);
        $this->log->append($storeId, $this->clock->now(), $source, 'blocked', $detail ?? $reason);
        return new SyncResult(SyncResult::BLOCKED, StateMessage::blocked($reason));
    }

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
