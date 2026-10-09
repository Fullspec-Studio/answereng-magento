<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\ConfigSettingsProvider as P;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConfigSettingsProviderTest extends TestCase
{
    public function testReadsScopedValuesTheAlreadyDecryptedKeyAndAppliesTheHostedDefault(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope = 'default', $scopeId = null) => match ($path) {
                P::PATH_ENGINE_URL => '  ',
                P::PATH_API_KEY => ' ae_live ',
                P::PATH_ENABLED => '1',
                P::PATH_STORE_KEY => ' shop.example.com ',
                P::PATH_BRANDS => "Acme\n\n  Acme Outdoor \nAcme",
                P::PATH_COMPETITORS => "Merrell\r\nSalomon",
                default => null,
            }
        );
        $p = new P($config, $this->createMock(StoreManagerInterface::class));
        $s = $p->forStore(3);
        self::assertSame([3, true, 'https://api.answereng.io', 'ae_live', 'shop.example.com', ['Acme', 'Acme Outdoor'],
            ['Merrell', 'Salomon']],
            [$s->storeId, $s->enabled, $s->engineUrl, $s->apiKey, $s->storeKey, $s->brandNames, $s->competitors]);
    }

    /**
     * ScopeConfig returns the decrypted key only because config.xml declares the
     * Encrypted backend model for the path; without it the provider would send
     * the ciphertext. Found in the local end-to-end run (double decryption).
     */
    public function testConfigXmlDeclaresTheEncryptedBackendModelForTheApiKey(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertNotFalse($xml);
        $node = $xml->default->answereng->connection->api_key;
        self::assertSame('Magento\Config\Model\Config\Backend\Encrypted', (string) $node['backend_model']);
    }
}
