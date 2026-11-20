<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\StateMessage;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Psr\Log\LoggerInterface;

/** Everything status.phtml renders, as plain arrays/value objects. */
class PanelViewModel
{
    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param SyncLog $log
     * @param SummaryProvider $summaries
     * @param RemoteStoreActions $actions
     * @param RemoteStoreRegistry $registry
     * @param RoleResolver $roles
     * @param AuthorizationInterface $authorization
     * @param UrlInterface $url
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncLog $log,
        private readonly SummaryProvider $summaries,
        private readonly RemoteStoreActions $actions,
        private readonly RemoteStoreRegistry $registry,
        private readonly RoleResolver $roles,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * One row per enabled view.
     *
     * @param int|null $websiteId
     * @return list<array<string,mixed>>
     */
    public function forDefault(?int $websiteId = null): array
    {
        $rows = [];
        $canSync = $this->authorization->isAllowed('Fullspec_AnswerEng::sync');
        $ids = $websiteId !== null && $websiteId > 0
            ? $this->settings->storeIdsForWebsites([$websiteId])
            : $this->settings->enabledStoreIds();
        foreach ($ids as $storeId) {
            $state = $this->state->get($storeId);
            $rows[] = [
                'storeId' => $storeId,
                'name' => $this->settings->storeName($storeId),
                'storeKey' => $this->settings->forStore($storeId)->storeKey,
                'state' => $state->state,
                'reason' => $state->reason,
                'message' => $this->message($storeId, $state->state, $state->reason, $state->retryReason, null),
                'lastSyncAt' => $state->lastSyncAt?->format('Y-m-d H:i'),
                'productCount' => $state->productCount,
                'canSync' => $canSync,
                'syncUrl' => $this->url->getUrl('answereng/action/syncnow'),
            ];
        }
        return $rows;
    }

    /**
     * For view.
     *
     * @param int $storeId
     * @return array<string,mixed>
     */
    public function forView(int $storeId): array
    {
        $keys = null;
        try {
            $keys = $this->actions->refreshStatuses($storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('AnswerEng status refresh failed', ['exception' => get_class($e)]);
        }
        $s = $this->settings->forStore($storeId);
        $state = $this->state->get($storeId);
        if ($keys === null && $state->state === SyncStateRepository::PAUSED && $state->reason === 'store_limit') {
            try {
                $keys = $this->actions->existingStoreKeys($storeId);   // store_limit lists them (spec §6)
            } catch (\Throwable $e) {
                $this->logger->warning('AnswerEng store list failed', ['exception' => get_class($e)]);
            }
        }
        $identities = [];
        foreach ($this->registry->forView($storeId) as $rs) {
            $others = array_values(array_diff($this->roles->activeUsers($rs->id), [$storeId]));
            $identities[] = [
                'id' => $rs->id,
                'storeKey' => $rs->storeKey,
                'engineUrl' => $rs->engineUrl,
                'role' => $this->roles->isCurrent($rs, $storeId) ? 'current' : 'previous',
                'status' => $rs->remoteStatus,
                'purgeAfter' => $rs->purgeAfter?->format('Y-m-d H:i'),
                'usedBy' => array_map([$this->settings, 'storeName'], $others),
            ];
        }
        $pending = null;
        foreach ($identities as $i) {
            if ($i['status'] === 'pending_deletion' && ($pending === null || $i['role'] === 'current')) {
                $pending = ['id' => $i['id'], 'storeKey' => $i['storeKey'], 'purgeAfter' => $i['purgeAfter']];
            }
        }
        return [
            'pendingDeletion' => $pending,
            'storeId' => $storeId,
            'enabled' => $s->enabled,
            'storeKey' => $s->storeKey,
            'testEngine' => $s->usesProductionEngine() ? null : $this->engineAddress($s->engineUrl),
            'state' => $state->state,
            'reason' => $state->reason,
            'message' => $this->message(
                $storeId,
                $state->state,
                $state->reason,
                $state->retryReason,
                $pending['purgeAfter'] ?? null,
                $state->reason === 'store_limit' ? ($keys ?? []) : []
            ),
            'lastSyncAt' => $state->lastSyncAt?->format('Y-m-d H:i'),
            'productCount' => $state->productCount,
            'log' => $this->log->recent($storeId),
            'summary' => $this->summaries->forView($storeId),
            'identities' => $identities,
            'canClear' => $this->roles->clearTargetFor($storeId) !== null,
            'canSync' => $this->authorization->isAllowed('Fullspec_AnswerEng::sync'),
            'canDelete' => $this->authorization->isAllowed('Fullspec_AnswerEng::delete_store'),
            'urls' => [
                'syncNow' => $this->url->getUrl('answereng/action/syncnow'),
                'refreshSummary' => $this->url->getUrl('answereng/action/refreshsummary'),
                'testConnection' => $this->url->getUrl('answereng/action/testconnection'),
                'clear' => $this->url->getUrl('answereng/action/clear'),
                'delete' => $this->url->getUrl('answereng/action/delete'),
                'cancel' => $this->url->getUrl('answereng/action/cancel'),
                'forget' => $this->url->getUrl('answereng/action/forget'),
            ],
        ];
    }

    /**
     * The merchant sentence for the state. Paused/blocked details (limit, HTTP status) come from the
     * latest sync-log entry with that outcome, which holds only the sanitized ErrorDetail string.
     */
    /**
     * Message.
     *
     * @param int $storeId
     * @param string $state
     * @param string|null $reason
     * @param string|null $retryReason
     * @param string|null $purgeAfter
     * @param list<string> $existingKeys
     */
    private function message(
        int $storeId,
        string $state,
        ?string $reason,
        ?string $retryReason,
        ?string $purgeAfter,
        array $existingKeys = []
    ): ?string {
        $detail = '';
        if (in_array($state, [SyncStateRepository::PAUSED, SyncStateRepository::BLOCKED], true)) {
            foreach ($this->log->recent($storeId) as $entry) {
                if ($entry['outcome'] === $state) {
                    $detail = (string) ($entry['detail'] ?? '');
                    break;
                }
            }
        }
        return StateMessage::for($state, $reason, $retryReason, $detail, $purgeAfter, $existingKeys);
    }

    /**
     * Host and port of a test engine, for the panel's "connected to a test engine at …" note.
     *
     * @param string $engineUrl
     * @return string
     */
    private function engineAddress(string $engineUrl): string
    {
        $rest = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $engineUrl);
        return explode('/', $rest, 2)[0];
    }
}
