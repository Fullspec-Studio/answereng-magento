<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductDeleteAfter implements ObserverInterface
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
            $product = $observer->getEvent()->getData('product');
            $this->tracker->markWebsites((array) $product->getData(ProductSaveBefore::PREVIOUS_WEBSITES));
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng: change tracking failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
