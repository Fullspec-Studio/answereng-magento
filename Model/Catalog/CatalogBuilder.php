<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

use Fullspec\AnswerEng\Model\Config\Settings;

/**
 * Spec §4: capture the eligible id set once, guard on its size, then load
 * the captured ids in chunks. Any chunk failure propagates — a partial
 * catalog is never returned (ingestion is full-replace).
 */
class CatalogBuilder
{
    public const MAX_PRODUCTS = 5000;
    public const CHUNK = 500;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Constructor.
     *
     * @param CatalogSource $source
     */
    public function __construct(private readonly CatalogSource $source)
    {
    }

    /**
     * Build.
     *
     * @param Settings $s
     * @return ExportResult
     */
    public function build(Settings $s): ExportResult
    {
        $ids = array_values(array_unique(array_map('intval', $this->source->eligibleIds($s->storeId))));
        sort($ids, SORT_NUMERIC);
        if (count($ids) > self::MAX_PRODUCTS) {
            return ExportResult::tooMany(count($ids));
        }
        if ($ids === []) {
            return ExportResult::empty();
        }
        $products = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->source->loadChunk($s->storeId, $chunk) as $record) {
                $products[] = $this->map($record);
            }
        }
        if ($products === []) {
            return ExportResult::empty();
        }
        if (count($products) > self::MAX_PRODUCTS) {
            return ExportResult::tooMany(count($products));
        }
        $payload = [
            'store_key' => $s->storeKey,
            'brand_names' => $s->brandNames,
            'competitors' => $s->competitors,
            'products' => $products,
        ];
        return ExportResult::payload(json_encode($payload, self::JSON_FLAGS), count($products));
    }

    /**
     * One product of the catalog payload, in contract key order.
     *
     * @param ProductRecord $r
     * @return array<string,mixed> keys in contract order
     */
    private function map(ProductRecord $r): array
    {
        $short = TextCleaner::plain($r->shortDescription);
        $attributes = [];
        foreach ($r->attributes as $code => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $attributes[(string) $code] = $value;
            }
        }
        return [
            'sku' => $r->sku,
            'title' => $r->name,
            'description' => $short !== '' ? $short : TextCleaner::plain($r->description),
            'price' => $r->price,
            'category' => $r->category,
            'attributes' => $attributes === [] ? new \stdClass() : $attributes,
        ];
    }
}
