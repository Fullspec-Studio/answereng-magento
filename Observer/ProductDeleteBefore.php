<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** The product-website links are gone after delete; capture them first. */
class ProductDeleteBefore implements ObserverInterface
{
    public function __construct(private readonly ProductResource $resource)
    {
    }

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getData('product');
        $product->setData(
            ProductSaveBefore::PREVIOUS_WEBSITES,
            array_map('intval', (array) $this->resource->getWebsiteIds($product))
        );
    }
}
