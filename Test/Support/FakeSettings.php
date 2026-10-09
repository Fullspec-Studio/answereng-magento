<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;

final class FakeSettings implements SettingsProvider
{
    /** @var array<int,Settings> */
    public array $stores = [];
    /** @var array<int,int> storeId => websiteId */
    public array $websites = [];

    public function put(int $storeId, array $o = [], int $websiteId = 1): Settings
    {
        $this->websites[$storeId] = $websiteId;
        return $this->stores[$storeId] = new Settings(
            $storeId,
            $o['enabled'] ?? true,
            $o['engineUrl'] ?? 'https://engine.test',
            $o['apiKey'] ?? 'ae_live',
            $o['storeKey'] ?? "store$storeId.example.com",
            $o['brands'] ?? ['Acme'],
            $o['competitors'] ?? ['Rival']
        );
    }

    public function forStore(int $storeId): Settings
    {
        return $this->stores[$storeId];
    }

    public function allStoreIds(): array
    {
        $ids = array_keys($this->stores);
        sort($ids);
        return $ids;
    }

    public function enabledStoreIds(): array
    {
        return array_values(array_filter($this->allStoreIds(), fn ($id) => $this->stores[$id]->enabled));
    }

    public function storeIdsForWebsites(array $websiteIds): array
    {
        return array_values(array_filter(
            $this->enabledStoreIds(),
            fn ($id) => in_array($this->websites[$id], $websiteIds, true)
        ));
    }

    public function storeName(int $storeId): string
    {
        return "Store $storeId";
    }

    public function storeIdByCode(string $code): ?int
    {
        return preg_match('/^store(\d+)$/', $code, $m) ? (int) $m[1] : null;
    }
}
