<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Everything status.phtml renders, as plain arrays/value objects. */
class PanelViewModel
{
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
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * One row per enabled view.
     *
     * @return list<array<string,mixed>>
     */
    public function forDefault(): array
    {
        $rows = [];
        foreach ($this->settings->enabledStoreIds() as $storeId) {
            $state = $this->state->get($storeId);
            $rows[] = [
                'storeId' => $storeId,
                'name' => $this->settings->storeName($storeId),
                'storeKey' => $this->settings->forStore($storeId)->storeKey,
                'state' => $state->state,
                'reason' => $state->reason,
                'lastSyncAt' => $state->lastSyncAt?->format('Y-m-d H:i'),
                'productCount' => $state->productCount,
            ];
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function forView(int $storeId): array
    {
        try {
            $this->actions->refreshStatuses($storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('AnswerEng status refresh failed', ['exception' => get_class($e)]);
        }
        $s = $this->settings->forStore($storeId);
        $state = $this->state->get($storeId);
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
        return [
            'storeId' => $storeId,
            'enabled' => $s->enabled,
            'storeKey' => $s->storeKey,
            'state' => $state->state,
            'reason' => $state->reason,
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
                'testConnection' => $this->url->getUrl('answereng/action/testconnection'),
                'clear' => $this->url->getUrl('answereng/action/clear'),
                'delete' => $this->url->getUrl('answereng/action/delete'),
                'cancel' => $this->url->getUrl('answereng/action/cancel'),
                'forget' => $this->url->getUrl('answereng/action/forget'),
            ],
        ];
    }
}
