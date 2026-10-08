<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

interface CatalogSource
{
    /**
     * Ids of products eligible for this store view: enabled, visible
     * individually (catalog, search or both), assigned to its website.
     *
     * @return list<int>
     */
    public function eligibleIds(int $storeId): array;

    /**
     * Load the given ids (some may have vanished — they are simply absent).
     *
     * @param list<int> $ids
     * @return list<ProductRecord>
     */
    public function loadChunk(int $storeId, array $ids): array;
}
