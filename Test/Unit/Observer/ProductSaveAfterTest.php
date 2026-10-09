<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Observer;

use Fullspec\AnswerEng\Observer\ProductSaveAfter;
use Fullspec\AnswerEng\Observer\ProductSaveBefore;
use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProductSaveAfterTest extends TestCase
{
    public function testMarksTheUnionOfPreviousAndCurrentWebsites(): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getWebsiteIds', 'getData'])->getMock();
        $product->method('getWebsiteIds')->willReturn([2, 3]);
        $product->method('getData')->with(ProductSaveBefore::PREVIOUS_WEBSITES)->willReturn([1, 2]);
        $event = new Event(['product' => $product]);
        $tracker = $this->createMock(ChangeTracker::class);
        $tracker->expects(self::once())->method('markWebsites')->with([1, 2, 3]);
        (new ProductSaveAfter($tracker, $this->createMock(LoggerInterface::class)))->execute(new Observer(['event' => $event]));
    }

    public function testSwallowsAndLogsTrackerFailures(): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(["getWebsiteIds", "getData"])->getMock();
        $product->method("getWebsiteIds")->willReturn([1]);
        $product->method("getData")->willReturn([]);
        $tracker = $this->createMock(ChangeTracker::class);
        $tracker->method("markWebsites")->willThrowException(new \RuntimeException("db down"));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method("error")
            ->with(self::stringContains("change tracking failed"), self::arrayHasKey("exception_class"));
        (new ProductSaveAfter($tracker, $logger))
            ->execute(new Observer(["event" => new Event(["product" => $product])]));
    }
}
