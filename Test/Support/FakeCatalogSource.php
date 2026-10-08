<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Catalog\CatalogSource;
use Fullspec\AnswerEng\Model\Catalog\ProductRecord;

final class FakeCatalogSource implements CatalogSource
{
    /** @var array<int,array<int,ProductRecord>> storeId => [entityId => record] */
    public array $products = [];
    /** @var list<list<int>> */
    public array $chunks = [];
    public ?\Throwable $failOnChunk = null;
    /** @var list<int> ids that vanish between id capture and chunk load */
    public array $deletedMidway = [];

    public function add(int $storeId, int $entityId, ?ProductRecord $r = null): void
    {
        $this->products[$storeId][$entityId] = $r ?? new ProductRecord("SKU-$entityId", "Product $entityId",
            'Short', 'Long', 10.0, 'boots', []);
    }

    public function eligibleIds(int $storeId): array
    {
        return array_keys($this->products[$storeId] ?? []);
    }

    public function loadChunk(int $storeId, array $ids): array
    {
        $this->chunks[] = $ids;
        if ($this->failOnChunk !== null && count($this->chunks) > 1) {
            throw $this->failOnChunk;
        }
        $out = [];
        foreach ($ids as $id) {
            if (!in_array($id, $this->deletedMidway, true) && isset($this->products[$storeId][$id])) {
                $out[] = $this->products[$storeId][$id];
            }
        }
        return $out;
    }
}
