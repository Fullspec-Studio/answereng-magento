<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\ConfigDisabler;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Registry\RemoteStore;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Support\Ts;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\ErrorDetail;
use Fullspec\AnswerEng\Model\Sync\OutcomeKind;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Spec §7 / §7.1. Every destructive action (clear, delete, cancel) first runs
 * the connection check — same engine URL and the live X-AnswerEng-Account
 * equal to the identity's — and refuses while another view uses the store.
 */
class RemoteStoreActions
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncLog $log,
        private readonly EngineClient $engine,
        private readonly Classifier $classifier,
        private readonly RemoteStoreRegistry $registry,
        private readonly RoleResolver $roles,
        private readonly ConfigDisabler $disabler,
        private readonly Clock $clock
    ) {
    }

    public function testConnection(int $storeId): ConnectionReport
    {
        $s = $this->settings->forStore($storeId);
        if ($s->apiKey === '') {
            return new ConnectionReport(ConnectionReport::NO_KEY,
                (string) __('Enter an API key first. Nothing is sent to AnswerEng without one.'));
        }
        $r = $this->engine->listStores($s->connection());
        if ($r->status === null || $r->status >= 500) {
            return new ConnectionReport(ConnectionReport::UNREACHABLE,
                (string) __('AnswerEng is unreachable (it may be waking up — try again in a minute).'));
        }
        if (!$r->isSuccess()) {
            return new ConnectionReport(ConnectionReport::REJECTED, $this->refusal($r));
        }
        $this->noteAccount($s, $r);
        $keys = $this->storeKeys($r);
        if (!in_array($s->storeKey, $keys, true)) {
            return new ConnectionReport(ConnectionReport::FIRST_SYNC_NEEDED, (string) __(
                'Connected — first sync needed (store "%1" will be created on first sync; it uses 1 of your plan\'s stores).',
                $s->storeKey
            ), $keys);
        }
        $summary = $this->engine->summary($s->connection(), $s->storeKey);
        if ($summary->isSuccess() && ($summary->json['latest_run'] ?? null) === null) {
            return new ConnectionReport(ConnectionReport::WAITING_FIRST_RUN,
                (string) __('Connected — waiting for the first analysis run.'), $keys);
        }
        return new ConnectionReport(ConnectionReport::FOUND,
            (string) __('Connected — store "%1" found.', $s->storeKey), $keys);
    }

    public function clear(int $storeId, string $adminUser): ActionResult
    {
        $rs = $this->roles->currentFor($storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('This store view has no current AnswerEng store to clear.'));
        }
        $s = $this->settings->forStore($storeId);
        if (($error = $this->guard($rs, $s) ?? $this->crossView($rs, $storeId)) !== null) {
            return new ActionResult(false, $error);
        }
        $r = $this->engine->clearCatalog($s->connection(), $rs->storeKey);
        $o = $this->classifier->classify($r, true);
        if ($o->kind === OutcomeKind::Success || $o->kind === OutcomeKind::AlreadyClear) {
            $this->log->append($storeId, $this->clock->now(), 'admin', 'catalog_cleared', $rs->storeKey, $adminUser);
            return new ActionResult(true, (string) __('Cleared the AnswerEng catalog for "%1". History and results are '
                . 'kept, and the store still counts toward your plan. The next sync restores the catalog.', $rs->storeKey));
        }
        return new ActionResult(false, $this->refusal($r));
    }

    public function delete(int $storeId, int $identityId, string $typedKey, bool $acknowledged, string $adminUser): ActionResult
    {
        $rs = $this->identityForView($identityId, $storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('That AnswerEng store is not linked to this store view.'));
        }
        if ($typedKey !== $rs->storeKey || !$acknowledged) {
            return new ActionResult(false, (string) __('Type the store key exactly and tick the box to confirm.'));
        }
        $s = $this->settings->forStore($storeId);
        if (($error = $this->guard($rs, $s) ?? $this->crossView($rs, $storeId)) !== null) {
            return new ActionResult(false, $error);
        }
        $wasCurrent = $this->roles->isCurrent($rs, $storeId);
        $r = $this->engine->deleteStore($s->connection(), $rs->storeKey);
        if ($r->isSuccess()) {
            $purgeAfter = $this->purgeAfter($r) ?? $this->clock->now()->modify('+7 days');
            $this->registry->setStatus($rs->id, 'pending_deletion', $purgeAfter);
            if ($wasCurrent) {
                $this->disabler->disable($storeId);
            }
            $this->log->append($storeId, $this->clock->now(), 'admin', 'deletion_requested',
                $rs->storeKey . ' purge_after=' . Ts::fmt($purgeAfter), $adminUser);
            $message = (string) __('AnswerEng will permanently delete "%1" on %2 UTC. You can cancel until then.',
                $rs->storeKey, $purgeAfter->format('Y-m-d H:i'));
            return new ActionResult(true, $wasCurrent
                ? $message . ' ' . __('Syncing for this store view is now disabled.') : $message);
        }
        if ($r->code() === 'store_pending_deletion') {
            $this->registry->setStatus($rs->id, 'pending_deletion', $this->purgeAfter($r));
        }
        return new ActionResult(false, $this->refusal($r, $rs->storeKey));
    }

    public function cancel(int $storeId, int $identityId, string $adminUser): ActionResult
    {
        $rs = $this->identityForView($identityId, $storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('That AnswerEng store is not linked to this store view.'));
        }
        $s = $this->settings->forStore($storeId);
        if (($error = $this->guard($rs, $s)) !== null) {
            return new ActionResult(false, $error);
        }
        $r = $this->engine->restoreStore($s->connection(), $rs->storeKey);
        if ($r->isSuccess()) {
            $this->registry->setStatus($rs->id, 'active', null);
            $this->log->append($storeId, $this->clock->now(), 'admin', 'deletion_cancelled', $rs->storeKey, $adminUser);
            return new ActionResult(true, (string) __('Deletion of "%1" cancelled. Re-enable syncing for this store view '
                . 'when you are ready.', $rs->storeKey));
        }
        return new ActionResult(false, $this->refusal($r, $rs->storeKey));
    }

    public function forget(int $storeId, int $identityId, string $adminUser): ActionResult
    {
        $rs = $this->identityForView($identityId, $storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('That AnswerEng store is not linked to this store view.'));
        }
        if ($this->roles->isCurrent($rs, $storeId)) {
            return new ActionResult(false, (string) __('"%1" is this store view\'s current AnswerEng store. Change the '
                . 'store key or disable the view before forgetting it.', $rs->storeKey));
        }
        $this->registry->forget($rs->id, $storeId);
        $this->log->append($storeId, $this->clock->now(), 'admin', 'forgotten', $rs->storeKey, $adminUser);
        return new ActionResult(true, (string) __('Forgot "%1" here. Nothing was changed in AnswerEng: the store and its '
            . 'plan slot are untouched.', $rs->storeKey));
    }

    /** Reconcile remote statuses for identities reachable through the CURRENT connection. */
    public function refreshStatuses(int $storeId): void
    {
        $s = $this->settings->forStore($storeId);
        $identities = array_filter($this->registry->forView($storeId),
            static fn (RemoteStore $r) => $r->engineUrl === $s->engineUrl);
        if ($s->apiKey === '' || $identities === []) {
            return;
        }
        $r = $this->engine->listStores($s->connection());
        if (!$r->isSuccess() || $r->account === null) {
            return;
        }
        $this->noteAccount($s, $r);
        $remote = [];
        foreach ((array) $r->json as $item) {
            if (is_array($item) && is_string($item['store_key'] ?? null)) {
                $remote[$item['store_key']] = $item;
            }
        }
        $now = $this->clock->now();
        foreach ($identities as $rs) {
            if ($rs->accountId !== $r->account) {
                continue;
            }
            $item = $remote[$rs->storeKey] ?? null;
            if ($item !== null) {
                $pending = in_array($item['status'] ?? 'active', ['pending_deletion', 'purging'], true);
                $purge = is_string($item['purge_after'] ?? null) ? new \DateTimeImmutable($item['purge_after']) : null;
                $this->registry->setStatus($rs->id, $pending ? 'pending_deletion' : 'active', $pending ? $purge : null);
            } elseif ($rs->remoteStatus === 'pending_deletion' && $rs->purgeAfter !== null && $rs->purgeAfter <= $now) {
                $this->registry->setStatus($rs->id, 'deleted', null);
            }
        }
    }

    private function guard(RemoteStore $rs, Settings $s): ?string
    {
        $mismatch = (string) __('"%1" was synced through a different AnswerEng connection (engine %2, account %3). '
            . 'Reconnect with that connection to manage it, or Forget it here.', $rs->storeKey, $rs->engineUrl, $rs->accountId);
        if ($s->apiKey === '') {
            return (string) __('Enter an API key first.');
        }
        if ($s->engineUrl !== $rs->engineUrl) {
            return $mismatch;
        }
        $r = $this->engine->listStores($s->connection());
        if ($r->status === null || $r->status >= 500) {
            return (string) __('AnswerEng is unreachable (it may be waking up — try again in a minute).');
        }
        if (!$r->isSuccess()) {
            return $this->refusal($r);
        }
        $this->noteAccount($s, $r);
        return $r->account === $rs->accountId ? null : $mismatch;
    }

    private function crossView(RemoteStore $rs, int $storeId): ?string
    {
        $others = array_values(array_diff($this->roles->activeUsers($rs->id), [$storeId]));
        if ($others === []) {
            return null;
        }
        return (string) __('"%1" is the active AnswerEng store for store view %2. Disable that view or change its '
            . 'store key first.', $rs->storeKey, implode(', ', array_map([$this->settings, 'storeName'], $others)));
    }

    private function identityForView(int $identityId, int $storeId): ?RemoteStore
    {
        $rs = $this->registry->get($identityId);
        return ($rs !== null && in_array($storeId, $this->registry->viewsOf($identityId), true)) ? $rs : null;
    }

    private function noteAccount(Settings $s, EngineResult $r): void
    {
        if ($r->account !== null) {
            $this->state->recordAccount($s->storeId, $r->account, $s->apiKeyHash());
        }
    }

    /**
     * @return list<string>
     */
    private function storeKeys(EngineResult $r): array
    {
        $keys = [];
        foreach ((array) $r->json as $item) {
            if (is_array($item) && is_string($item['store_key'] ?? null)) {
                $keys[] = $item['store_key'];
            }
        }
        return $keys;
    }

    private function purgeAfter(EngineResult $r): ?\DateTimeImmutable
    {
        $value = $r->purgeAfter();
        return $value === null ? null : (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function refusal(EngineResult $r, string $storeKey = ''): string
    {
        return match ($r->code()) {
            'invalid_key' => (string) __('API key rejected or account inactive — check the key.'),
            'run_in_progress' => (string) __('An analysis run is in progress; try again in a few minutes.'),
            'unknown_store' => (string) __('AnswerEng has no store "%1" (it may already have been deleted).', $storeKey),
            'purge_in_progress' => (string) __('Deletion has already started and can no longer be cancelled.'),
            'store_pending_deletion' => (string) __('"%1" is already scheduled for deletion.', $storeKey),
            default => (string) __('AnswerEng refused the request (%1).', ErrorDetail::of($r)),
        };
    }
}
