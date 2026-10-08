<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

interface SettingsProvider
{
    public function forStore(int $storeId): Settings;

    /**
     * @return list<int> every store view id (excluding admin)
     */
    public function allStoreIds(): array;

    /**
     * @return list<int>
     */
    public function enabledStoreIds(): array;

    /**
     * @param list<int> $websiteIds
     * @return list<int> enabled store views on those websites
     */
    public function storeIdsForWebsites(array $websiteIds): array;

    public function storeName(int $storeId): string;

    public function storeIdByCode(string $code): ?int;
}
