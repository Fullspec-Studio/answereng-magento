<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductSaveAfter implements ObserverInterface
{
    public function __construct(private readonly ChangeTracker $tracker)
    {
    }

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getData('product');
        $ids = array_merge(
            (array) $product->getData(ProductSaveBefore::PREVIOUS_WEBSITES),
            (array) $product->getWebsiteIds()
        );
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $this->tracker->markWebsites($ids);
    }
}
