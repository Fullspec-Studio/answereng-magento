<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Final review #12: product/category *_save_after and *_delete_after run inside the EAV save transaction;
 * change tracking must observe the *_commit_after events instead.
 */
final class EventsXmlTest extends TestCase
{
    /**
     * @return array<string,string> event name => observer class
     */
    private function events(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/events.xml');
        self::assertNotFalse($xml);
        $map = [];
        foreach ($xml->event as $event) {
            $map[(string) $event['name']] = (string) $event->observer['instance'];
        }
        return $map;
    }

    public function testChangeTrackingRunsAfterCommit(): void
    {
        $events = $this->events();
        self::assertSame('Fullspec\AnswerEng\Observer\ProductSaveAfter', $events['catalog_product_save_commit_after']);
        self::assertSame('Fullspec\AnswerEng\Observer\ProductDeleteAfter',
            $events['catalog_product_delete_commit_after']);
        self::assertSame('Fullspec\AnswerEng\Observer\MarkAllDirty', $events['catalog_category_save_commit_after']);
        self::assertSame('Fullspec\AnswerEng\Observer\ProductSaveBefore', $events['catalog_product_save_before']);
        self::assertSame('Fullspec\AnswerEng\Observer\ProductDeleteBefore', $events['catalog_product_delete_before']);
        foreach (['catalog_product_save_after', 'catalog_product_delete_after', 'catalog_category_save_after'] as $inTx) {
            self::assertArrayNotHasKey($inTx, $events);
        }
    }
}
