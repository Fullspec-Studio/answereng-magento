<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

/** Raw, store-view-scoped product data from a CatalogSourceInterface. */
class ProductRecord
{
    /**
     * Constructor.
     *
     * @param string $sku
     * @param string $name
     * @param string $shortDescription
     * @param string $description
     * @param float|null $price
     * @param string $category
     * @param array<string,string> $attributes attribute code => storefront text
     */
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $shortDescription,
        public readonly string $description,
        public readonly ?float $price,
        public readonly string $category,
        public readonly array $attributes
    ) {
    }
}
