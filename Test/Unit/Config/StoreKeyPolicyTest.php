<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\DuplicateStoreKeyException;
use Fullspec\AnswerEng\Model\Config\StoreKeyPolicy;
use PHPUnit\Framework\TestCase;

final class StoreKeyPolicyTest extends TestCase
{
    public function testNormalizeTrimsButNeverChangesCase(): void
    {
        self::assertSame('Shop.Example.com', StoreKeyPolicy::normalize("  Shop.Example.com \n"));
    }

    public function testFromBaseUrlTakesTheLowercasedHost(): void
    {
        self::assertSame('www.acme-outdoor.co.uk', StoreKeyPolicy::fromBaseUrl('https://WWW.Acme-Outdoor.co.uk/en/'));
        self::assertSame('', StoreKeyPolicy::fromBaseUrl('not a url'));
    }

    public function testUniquenessComparesTrimmedValuesExactly(): void
    {
        StoreKeyPolicy::assertUnique('shop.example.com', [2 => 'Shop.example.com', 3 => 'other']);
        try {
            StoreKeyPolicy::assertUnique('shop.example.com', [2 => 'other', 3 => ' shop.example.com ']);
            self::fail('expected a duplicate');
        } catch (DuplicateStoreKeyException $e) {
            self::assertSame(3, $e->storeId);
        }
    }

    public function testLocalAddressesLookLocal(): void
    {
        foreach (['localhost', 'shop.localhost', '127.0.0.1', '192.168.1.20', '::1', 'magento.test', 'shop.local',
            'host.docker.internal', ''] as $key) {
            self::assertTrue(StoreKeyPolicy::looksLocal($key), $key);
        }
        foreach (['shop.example.com', 'www.acme-outdoor.co.uk', 'demo-outdoor-store', 'testshop.com'] as $key) {
            self::assertFalse(StoreKeyPolicy::looksLocal($key), $key);
        }
    }
}
