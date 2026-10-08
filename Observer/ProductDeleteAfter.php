<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductDeleteAfter implements ObserverInterface
{
    public function __construct(private readonly ChangeTracker $tracker)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        $this->tracker->markWebsites((array) $product->getData(ProductSaveBefore::PREVIOUS_WEBSITES));
    }
}
