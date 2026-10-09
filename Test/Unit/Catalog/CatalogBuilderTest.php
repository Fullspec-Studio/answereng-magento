<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Catalog;

use Fullspec\AnswerEng\Model\Catalog\CatalogBuilder;
use Fullspec\AnswerEng\Model\Catalog\ExportResult;
use Fullspec\AnswerEng\Model\Catalog\ProductRecord;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Test\Support\FakeCatalogSource;
use PHPUnit\Framework\TestCase;

final class CatalogBuilderTest extends TestCase
{
    private FakeCatalogSource $source;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->source = new FakeCatalogSource();
        $this->settings = new Settings(1, true, 'https://e', 'ae', 'demo-outdoor-store', ['Acme Outdoor'],
            ['Merrell', 'Salomon', 'KEEN']);
    }

    private function decode(ExportResult $r): array
    {
        return json_decode($r->json, true, 64, JSON_THROW_ON_ERROR);
    }

    public function testPayloadKeyOrderMatchesTheFrozenContract(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/catalog_push.json'), true);
        $this->source->add(1, 7, new ProductRecord('ACME-TRAIL-2', 'Acme Trail 2', '', 'Long', 139.99, 'hiking-boots',
            ['waterproof' => 'yes']));
        $payload = $this->decode((new CatalogBuilder($this->source))->build($this->settings));
        self::assertSame(array_keys($fixture), array_keys($payload));
        self::assertSame(array_keys($fixture['products'][0]), array_keys($payload['products'][0]));
        self::assertSame(['demo-outdoor-store', ['Acme Outdoor'], ['Merrell', 'Salomon', 'KEEN']],
            [$payload['store_key'], $payload['brand_names'], $payload['competitors']]);
    }

    public function testMappingFallbacksAndEmptyAttributesEncodeAsAnObject(): void
    {
        $this->source->add(1, 1, new ProductRecord('S1', 'Boot', '', '<p>Long &amp; warm</p>', null, '',
            ['color' => '  ', 'size' => '']));
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertStringContainsString('"attributes":{}', $result->json);
        $p = $this->decode($result)['products'][0];
        self::assertSame(['S1', 'Boot', 'Long & warm', null, ''],
            [$p['sku'], $p['title'], $p['description'], $p['price'], $p['category']]);
    }

    public function testShortDescriptionWinsWhenPresent(): void
    {
        $this->source->add(1, 1, new ProductRecord('S1', 'Boot', '<b>Short</b>', 'Long', 5.0, 'c', ['fit' => 'wide']));
        $p = $this->decode((new CatalogBuilder($this->source))->build($this->settings))['products'][0];
        self::assertSame(['Short', ['fit' => 'wide']], [$p['description'], $p['attributes']]);
    }

    public function testInvalidUtf8AndEmojiNeverBreakEncoding(): void
    {
        $this->source->add(1, 1, new ProductRecord("S\xC3\x28", "Bad \xFF name 🥾", '', '', 1.0, 'c', []));
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertSame(1, $result->count);
        self::assertStringContainsString('🥾', $this->decode($result)['products'][0]['title']);
    }

    public function testMoreThan5000IsRefusedWithoutLoadingAnything(): void
    {
        for ($i = 1; $i <= 5001; $i++) {
            $this->source->add(1, $i);
        }
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertSame([ExportResult::TOO_MANY, 5001], [$result->kind, $result->count]);
        self::assertSame([], $this->source->chunks);
    }

    public function testNoEligibleProductsIsEmpty(): void
    {
        self::assertSame(ExportResult::EMPTY, (new CatalogBuilder($this->source))->build($this->settings)->kind);
    }

    public function testLoadsCapturedIdsInChunksOf500AndDropsProductsDeletedMidway(): void
    {
        for ($i = 1; $i <= 1100; $i++) {
            $this->source->add(1, $i);
        }
        $this->source->deletedMidway = [5, 600];
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertSame([500, 500, 100], array_map('count', $this->source->chunks));
        self::assertSame(1098, $result->count);
    }

    public function testAllProductsVanishingMidwayIsEmpty(): void
    {
        $this->source->add(1, 1);
        $this->source->deletedMidway = [1];
        self::assertSame(ExportResult::EMPTY, (new CatalogBuilder($this->source))->build($this->settings)->kind);
    }

    public function testAChunkFailureAbortsTheWholeExport(): void
    {
        for ($i = 1; $i <= 600; $i++) {
            $this->source->add(1, $i);
        }
        $this->source->failOnChunk = new \RuntimeException('db gone');
        $this->expectException(\RuntimeException::class);
        (new CatalogBuilder($this->source))->build($this->settings);
    }
}
