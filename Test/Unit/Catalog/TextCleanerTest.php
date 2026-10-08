<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Catalog;

use Fullspec\AnswerEng\Model\Catalog\TextCleaner;
use PHPUnit\Framework\TestCase;

final class TextCleanerTest extends TestCase
{
    public function testStripsTagsDecodesEntitiesAndCollapsesWhitespace(): void
    {
        self::assertSame('Waterproof & warm — 2 layers 🥾',
            TextCleaner::plain("<p>Waterproof &amp; warm&nbsp;&mdash;<br>2\n\tlayers 🥾</p>"));
    }
}
