<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/** The product-website links are gone after delete; capture them first. */
class ProductDeleteBefore implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param ProductResource $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductResource $resource,
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
            $product->setData(
                ProductSaveBefore::PREVIOUS_WEBSITES,
                array_map('intval', (array) $this->resource->getWebsiteIds($product))
            );
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng: change tracking failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
