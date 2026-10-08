<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Roles are derived, never stored (spec §7.1): an identity is CURRENT for a
 * view when it equals the view's effective target right now — enabled, same
 * store key, same engine URL, and the account last seen for the CURRENT key.
 */
class RoleResolver
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly RemoteStoreRegistry $registry
    ) {
    }

    public function isCurrent(RemoteStore $r, int $storeId): bool
    {
        $s = $this->settings->forStore($storeId);
        $state = $this->state->get($storeId);
        return $s->enabled
            && $s->storeKey === $r->storeKey
            && $s->engineUrl === $r->engineUrl
            && $state->accountKeyHash === $s->apiKeyHash()
            && $state->accountId === $r->accountId;
    }

    public function currentFor(int $storeId): ?RemoteStore
    {
        foreach ($this->registry->forView($storeId) as $r) {
            if ($this->isCurrent($r, $storeId)) {
                return $r;
            }
        }
        return null;
    }

    /**
     * The identity Clear acts on: like currentFor but ignoring the enabled flag (spec §7).
     */
    public function clearTargetFor(int $storeId): ?RemoteStore
    {
        $s = $this->settings->forStore($storeId);
        $state = $this->state->get($storeId);
        foreach ($this->registry->forView($storeId) as $r) {
            if ($s->storeKey === $r->storeKey
                && $s->engineUrl === $r->engineUrl
                && $state->accountKeyHash === $s->apiKeyHash()
                && $state->accountId === $r->accountId
            ) {
                return $r;
            }
        }
        return null;
    }

    /** @return list<int> views for which this identity is current */
    public function activeUsers(int $identityId): array
    {
        $identity = $this->registry->get($identityId);
        if ($identity === null) {
            return [];
        }
        return array_values(array_filter($this->registry->viewsOf($identityId),
            fn (int $storeId) => $this->isCurrent($identity, $storeId)));
    }
}
