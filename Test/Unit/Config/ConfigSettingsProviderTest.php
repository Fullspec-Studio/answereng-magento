<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\ConfigSettingsProvider as P;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConfigSettingsProviderTest extends TestCase
{
    public function testReadsScopedValuesDecryptsTheKeyAndAppliesTheHostedDefault(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope = 'default', $scopeId = null) => match ($path) {
                P::PATH_ENGINE_URL => '  ',
                P::PATH_API_KEY => 'encrypted',
                P::PATH_ENABLED => '1',
                P::PATH_STORE_KEY => ' shop.example.com ',
                P::PATH_BRANDS => "Acme\n\n  Acme Outdoor \nAcme",
                P::PATH_COMPETITORS => "Merrell\r\nSalomon",
                default => null,
            }
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->with('encrypted')->willReturn('ae_live');
        $p = new P($config, $encryptor, $this->createMock(StoreManagerInterface::class));
        $s = $p->forStore(3);
        self::assertSame([3, true, 'https://api.answereng.io', 'ae_live', 'shop.example.com', ['Acme', 'Acme Outdoor'],
            ['Merrell', 'Salomon']],
            [$s->storeId, $s->enabled, $s->engineUrl, $s->apiKey, $s->storeKey, $s->brandNames, $s->competitors]);
    }
}
