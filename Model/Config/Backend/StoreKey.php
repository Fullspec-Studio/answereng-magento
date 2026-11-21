<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config\Backend;

use Fullspec\AnswerEng\Model\Config\DuplicateStoreKeyException;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Config\StoreKeyPolicy;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Spec §3: the store key is persisted. A blank key on an enabled view is
 * filled ONCE from the view's base-URL host; later base-URL changes never
 * move it. Keys must be unique across enabled views.
 */
class StoreKey extends Value
{
    /**
     * Constructor.
     *
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param StoreManagerInterface $storeManager
     * @param SettingsProvider $settings
     * @param SyncStateRepository $state
     * @param ManagerInterface $messages
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly StoreManagerInterface $storeManager,
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly ManagerInterface $messages,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $storeId = (int) $this->getScopeId();
        $key = StoreKeyPolicy::normalize((string) $this->getValue());
        $enabled = (bool) $this->getFieldsetDataValue('enabled');
        if ($key === '' && $enabled) {
            $key = StoreKeyPolicy::fromBaseUrl((string) $this->storeManager->getStore($storeId)->getBaseUrl());
        }
        if ($enabled) {
            $others = [];
            foreach ($this->settings->enabledStoreIds() as $otherId) {
                if ($otherId !== $storeId) {
                    $others[$otherId] = $this->settings->forStore($otherId)->storeKey;
                }
            }
            try {
                StoreKeyPolicy::assertUnique($key, $others);
            } catch (DuplicateStoreKeyException $e) {
                throw new LocalizedException(__(
                    'AnswerEng store key "%1" is already used by store view %2. Store keys must be unique.',
                    $key,
                    $this->settings->storeName($e->storeId)
                ));
            }
        }
        $this->setValue($key);
        return parent::beforeSave();
    }

    /**
     * @inheritdoc
     */
    public function afterSave()
    {
        $key = (string) $this->getValue();
        $settings = $this->settings->forStore((int) $this->getScopeId());
        // Only for the real engine: a local key is normal when testing against a local or staging engine.
        if ($settings->enabled && $settings->usesProductionEngine() && StoreKeyPolicy::looksLocal($key)) {
            $this->messages->addWarningMessage(__(
                'Store key "%1" looks like a local address. It doesn\'t identify your shop, and AnswerEng '
                . 'refuses a key another account already uses. Set it to your shop\'s public domain.',
                $key
            ));
        }
        $synced = $this->state->get((int) $this->getScopeId())->syncedStoreKey;
        if ($this->isValueChanged() && $synced !== null && $synced !== (string) $this->getValue()) {
            $this->messages->addWarningMessage(__(
                'This store view had synced to AnswerEng as "%1". The new key registers a NEW AnswerEng store that '
                . 'counts against your plan; "%1" keeps its data and its slot until you delete it '
                . '(Catalog › AnswerEng, Danger zone).',
                $synced
            ));
        }
        return parent::afterSave();
    }
}
