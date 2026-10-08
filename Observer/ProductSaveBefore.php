<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/** Stash the PERSISTED website ids so a website that loses the product is resynced too. */
class ProductSaveBefore implements ObserverInterface
{
    public const PREVIOUS_WEBSITES = '_answereng_previous_website_ids';

    public function __construct(
        private readonly ProductResource $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getEvent()->getData('product');
            $previous = $product->getId() ? $this->resource->getWebsiteIds($product) : [];
            $product->setData(self::PREVIOUS_WEBSITES, array_map('intval', (array) $previous));
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng: change tracking failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
