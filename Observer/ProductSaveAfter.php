<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductSaveAfter implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param ChangeTracker $tracker
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ChangeTracker $tracker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getEvent()->getData('product');
            $ids = array_merge(
                (array) $product->getData(ProductSaveBefore::PREVIOUS_WEBSITES),
                (array) $product->getWebsiteIds()
            );
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);
            $this->tracker->markWebsites($ids);
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng: change tracking failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
