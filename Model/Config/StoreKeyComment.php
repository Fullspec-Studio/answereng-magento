<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;

/** Field comment for the store key: the warning before it changes (spec §3). */
class StoreKeyComment implements CommentInterface
{
    /**
     * Constructor.
     *
     * @param RequestInterface $request
     * @param SyncStateRepository $state
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly SyncStateRepository $state,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCommentText($elementValue)
    {
        $default = (string) __('Leave blank to use this store view\'s domain when you enable it. '
            . 'Each key is a separate AnswerEng store.');
        $storeId = (int) $this->request->getParam('store');
        if ($storeId <= 0) {
            return $default;
        }
        $synced = $this->state->get($storeId)->syncedStoreKey;
        if ($synced === null) {
            return $default;
        }
        return (string) __(
            'This view has synced as "%1". Changing it registers a NEW store in AnswerEng that counts '
            . 'against your plan; the old store keeps its data and its slot until you delete it '
            . '(Catalog › AnswerEng, Danger zone).',
            $this->escaper->escapeHtml($synced)
        );
    }
}
