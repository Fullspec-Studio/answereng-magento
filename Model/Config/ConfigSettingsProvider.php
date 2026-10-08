<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ConfigSettingsProvider implements SettingsProvider
{
    public const DEFAULT_ENGINE_URL = 'https://api.answereng.io';
    public const PATH_ENGINE_URL = 'answereng/connection/engine_url';
    public const PATH_API_KEY = 'answereng/connection/api_key';
    public const PATH_ENABLED = 'answereng/store/enabled';
    public const PATH_STORE_KEY = 'answereng/store/store_key';
    public const PATH_BRANDS = 'answereng/brand/brand_names';
    public const PATH_COMPETITORS = 'answereng/brand/competitors';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function forStore(int $storeId): Settings
    {
        $engineUrl = trim((string) $this->config->getValue(self::PATH_ENGINE_URL));
        $encrypted = trim((string) $this->config->getValue(self::PATH_API_KEY));
        return new Settings(
            $storeId,
            (bool) $this->storeValue(self::PATH_ENABLED, $storeId),
            rtrim($engineUrl === '' ? self::DEFAULT_ENGINE_URL : $engineUrl, '/'),
            $encrypted === '' ? '' : trim($this->encryptor->decrypt($encrypted)),
            StoreKeyPolicy::normalize((string) $this->storeValue(self::PATH_STORE_KEY, $storeId)),
            $this->lines((string) $this->storeValue(self::PATH_BRANDS, $storeId)),
            $this->lines((string) $this->storeValue(self::PATH_COMPETITORS, $storeId))
        );
    }

    public function allStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $ids[] = (int) $store->getId();
        }
        sort($ids);
        return $ids;
    }

    public function enabledStoreIds(): array
    {
        return array_values(array_filter(
            $this->allStoreIds(),
            fn (int $id) => (bool) $this->storeValue(self::PATH_ENABLED, $id)
        ));
    }

    public function storeIdsForWebsites(array $websiteIds): array
    {
        $ids = [];
        foreach (array_unique($websiteIds) as $websiteId) {
            /** @var \Magento\Store\Model\Website $website */
            $website = $this->storeManager->getWebsite((int) $websiteId);
            foreach ($website->getStoreIds() as $storeId) {
                $ids[] = (int) $storeId;
            }
        }
        return array_values(array_intersect($this->enabledStoreIds(), $ids));
    }

    public function storeName(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);
        return $store->getName() . ' (' . $store->getCode() . ')';
    }

    public function storeIdByCode(string $code): ?int
    {
        foreach ($this->storeManager->getStores(false) as $store) {
            if ($store->getCode() === $code) {
                return (int) $store->getId();
            }
        }
        return null;
    }

    private function storeValue(string $path, int $storeId): mixed
    {
        return $this->config->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $out = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }
        return $out;
    }
}
