<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

interface SettingsProvider
{
    /**
     * For store.
     *
     * @param int $storeId
     * @return Settings
     */
    public function forStore(int $storeId): Settings;

    /**
     * All store ids.
     *
     * @return list<int> every store view id (excluding admin)
     */
    public function allStoreIds(): array;

    /**
     * Enabled store ids.
     *
     * @return list<int>
     */
    public function enabledStoreIds(): array;

    /**
     * Store ids for websites.
     *
     * @param list<int> $websiteIds
     * @return list<int> enabled store views on those websites
     */
    public function storeIdsForWebsites(array $websiteIds): array;

    /**
     * Store name.
     *
     * @param int $storeId
     * @return string
     */
    public function storeName(int $storeId): string;

    /**
     * Store id by code.
     *
     * @param string $code
     * @return int|null
     */
    public function storeIdByCode(string $code): ?int;
}
