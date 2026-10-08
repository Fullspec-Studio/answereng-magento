<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Group;
use Magento\Framework\App\ResourceConnection;
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

    public function __construct(
        private readonly ProductCollectionFactory $products,
        private readonly CategoryCollectionFactory $categories,
        private readonly AttributeCollectionFactory $attributes,
        private readonly Visibility $visibility,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource
    ) {
    }

    public function eligibleIds(int $storeId): array
    {
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()]);
        return array_map('intval', $collection->getAllIds());
    }

    public function loadChunk(int $storeId, array $ids): array
    {
        $store = $this->storeManager->getStore($storeId);
        $codes = $this->visibleCodes();
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(array_merge(['name', 'short_description', 'description'], $codes))
            ->addCategoryIds();
        $prices = $this->prices((int) $store->getWebsiteId(), $ids);
        $rootId = (int) $this->storeManager->getGroup((string) $store->getStoreGroupId())->getRootCategoryId();
        $categoryUrlKeys = $this->categoryKeys($storeId, $rootId, $collection->getItems());

        $records = [];
        foreach ($collection->getItems() as $product) {
            $attributes = [];
            foreach ($codes as $code) {
                $attribute = $product->getResource()->getAttribute($code);
                $value = $attribute ? $attribute->getFrontend()->getValue($product) : null;
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
     * @param list<int> $ids
     * @return array<int,float>
     */
    private function prices(int $websiteId, array $ids): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from($this->resource->getTableName('catalog_product_index_price'), ['entity_id', 'min_price'])
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
            if (str_starts_with((string) $category->getPath() . '/', $prefix)) {
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
