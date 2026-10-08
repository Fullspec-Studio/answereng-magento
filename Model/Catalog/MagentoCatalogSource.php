<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Indexer\CustomerGroupDimensionProvider;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Indexer\WebsiteDimensionProvider;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Spec §4 mapping, store-view scoped:
 * - price: min_price from catalog_product_index_price for the view's website
 *   and NOT LOGGED IN (null when not indexed);
 * - category: url_key of the deepest assigned category under the view's root
 *   (ties: lowest category id);
 * - attributes: is_visible_on_front, keyed by code, storefront text.
 */
class MagentoCatalogSource implements CatalogSource
{
    /** @var list<string>|null */
    private ?array $visibleCodes = null;

    /**
     * Constructor.
     *
     * @param ProductCollectionFactory $products
     * @param CategoryCollectionFactory $categories
     * @param AttributeCollectionFactory $attributes
     * @param Visibility $visibility
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resource
     * @param Emulation $emulation
     * @param PriceTableResolver $priceTables
     * @param DimensionFactory $dimensions
     */
    public function __construct(
        private readonly ProductCollectionFactory $products,
        private readonly CategoryCollectionFactory $categories,
        private readonly AttributeCollectionFactory $attributes,
        private readonly Visibility $visibility,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly Emulation $emulation,
        private readonly PriceTableResolver $priceTables,
        private readonly DimensionFactory $dimensions
    ) {
    }

    /**
     * @inheritdoc
     */
    public function eligibleIds(int $storeId): array
    {
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()]);
        return array_map('intval', $collection->getAllIds());
    }

    /**
     * @inheritdoc
     */
    public function loadChunk(int $storeId, array $ids): array
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            return $this->loadEmulated($storeId, $ids);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Load emulated.
     *
     * @param int $storeId
     * @param list<int> $ids
     * @return list<ProductRecord>
     */
    private function loadEmulated(int $storeId, array $ids): array
    {
        $store = $this->storeManager->getStore($storeId);
        $codes = $this->visibleCodes();
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(array_merge(['name', 'short_description', 'description'], $codes))
            ->setOrder('entity_id', 'ASC');
        // addCategoryIds() only acts on already-loaded items, so load first.
        $collection->load();
        $collection->addCategoryIds();
        $prices = $this->prices((int) $store->getWebsiteId(), $ids);
        $rootId = (int) $this->storeManager->getGroup((string) $store->getStoreGroupId())->getRootCategoryId();
        $categoryUrlKeys = $this->categoryKeys($storeId, $rootId, $collection->getItems());

        $labelMaps = [];
        $records = [];
        foreach ($collection->getItems() as $product) {
            $attributes = [];
            foreach ($codes as $code) {
                $attribute = $product->getResource()->getAttribute($code);
                $value = null;
                if ($attribute) {
                    if (!array_key_exists($code, $labelMaps)) {
                        $labelMaps[$code] = $this->labelMap($attribute, $storeId);
                    }
                    $value = $labelMaps[$code] !== null
                        ? $this->mapLabels($labelMaps[$code], $product->getData($code), $attribute->getFrontendInput())
                        : $attribute->getFrontend()->getValue($product);
                }
                if ($value !== null && $value !== false && (string) $value !== '') {
                    $attributes[$code] = (string) $value;
                }
            }
            $id = (int) $product->getId();
            $records[] = new ProductRecord(
                (string) $product->getSku(),
                (string) $product->getName(),
                (string) $product->getData('short_description'),
                (string) $product->getData('description'),
                $prices[$id] ?? null,
                $categoryUrlKeys[$id] ?? '',
                $attributes
            );
        }
        return $records;
    }

    /**
     * One option query per attribute per chunk, in the emulated view's labels.
     *
     * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute
     * @param int $storeId
     * @return array<string,string>|null null when the attribute is not source-backed
     */
    private function labelMap($attribute, int $storeId): ?array
    {
        if (!in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true)
            || !$attribute->usesSource()) {
            return null;
        }
        $attribute->setStoreId($storeId);
        $map = [];
        foreach ($attribute->getSource()->getAllOptions() as $option) {
            if (is_array($option) && isset($option['value']) && $option['value'] !== ''
                && !is_array($option['value'])
            ) {
                $map[(string) $option['value']] = (string) $option['label'];
            }
        }
        return $map;
    }

    /**
     * Map labels.
     *
     * @param array<string,string> $map
     * @param mixed $raw
     * @param string $input
     * @return string
     */
    private function mapLabels(array $map, $raw, string $input): string
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return '';
        }
        $values = $input === 'multiselect' ? explode(',', (string) $raw) : [(string) $raw];
        $labels = [];
        foreach ($values as $value) {
            $value = trim($value);
            if (isset($map[$value])) {
                $labels[] = $map[$value];
            }
        }
        return implode(', ', $labels);
    }

    /**
     * Visible codes.
     *
     * @return list<string>
     */
    private function visibleCodes(): array
    {
        if ($this->visibleCodes === null) {
            $collection = $this->attributes->create()->addFieldToFilter('is_visible_on_front', ['eq' => 1]);
            $this->visibleCodes = [];
            foreach ($collection->getItems() as $attribute) {
                $this->visibleCodes[] = (string) $attribute->getAttributeCode();
            }
        }
        return $this->visibleCodes;
    }

    /**
     * Price table.
     *
     * Price index table for the website / NOT LOGGED IN pair; in dimension
     * mode the rows live in per-dimension tables, not the base table.
     *
     * @param int $websiteId
     */
    private function priceTable(int $websiteId): string
    {
        return $this->priceTables->resolve('catalog_product_index_price', [
            $this->dimensions->create(WebsiteDimensionProvider::DIMENSION_NAME, (string) $websiteId),
            $this->dimensions->create(
                CustomerGroupDimensionProvider::DIMENSION_NAME,
                (string) Group::NOT_LOGGED_IN_ID
            ),
        ]);
    }

    /**
     * Prices.
     *
     * @param int $websiteId
     * @param list<int> $ids
     * @return array<int,float>
     */
    private function prices(int $websiteId, array $ids): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from($this->priceTable($websiteId), ['entity_id', 'min_price'])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', Group::NOT_LOGGED_IN_ID)
            ->where('entity_id IN (?)', $ids);
        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            if ($row['min_price'] !== null) {
                $out[(int) $row['entity_id']] = (float) $row['min_price'];
            }
        }
        return $out;
    }

    /**
     * Category keys.
     *
     * @param int $storeId
     * @param int $rootId
     * @param array<\Magento\Framework\DataObject> $products
     * @return array<int,string> product id => category url_key
     */
    private function categoryKeys(int $storeId, int $rootId, array $products): array
    {
        $allIds = [];
        foreach ($products as $product) {
            foreach ((array) $product->getCategoryIds() as $categoryId) {
                $allIds[(int) $categoryId] = true;
            }
        }
        if ($allIds === []) {
            return [];
        }
        $collection = $this->categories->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect('url_key')
            ->addIdFilter(array_keys($allIds));
        $categories = [];
        $prefix = '1/' . $rootId . '/';
        foreach ($collection->getItems() as $category) {
            if (str_starts_with((string) $category->getPath(), $prefix)) {
                $categories[(int) $category->getId()] = $category;
            }
        }
        $out = [];
        foreach ($products as $product) {
            $best = null;
            foreach ((array) $product->getCategoryIds() as $categoryId) {
                $c = $categories[(int) $categoryId] ?? null;
                if ($c === null) {
                    continue;
                }
                if ($best === null || (int) $c->getLevel() > (int) $best->getLevel()
                    || ((int) $c->getLevel() === (int) $best->getLevel() && (int) $c->getId() < (int) $best->getId())) {
                    $best = $c;
                }
            }
            $out[(int) $product->getId()] = $best === null ? '' : (string) $best->getData('url_key');
        }
        return $out;
    }
}
