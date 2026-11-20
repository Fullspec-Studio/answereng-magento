<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Admin;

use Fullspec\AnswerEng\Model\Admin\FixView;
use PHPUnit\Framework\TestCase;

final class FixViewTest extends TestCase
{
    public function testParsesTheContractFixture(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/read_api/fixes.json'), true);
        $fixes = FixView::listFromJson($json);
        self::assertCount(2, $fixes);
        $first = $fixes[0];
        self::assertSame([118, 'copy', 'high'], [$first->fixId, $first->kind, $first->priority]);
        self::assertCount(2, $first->reasons);
        self::assertSame('waterproof hiking boots for wide feet', $first->promptText);
        self::assertSame('perplexity', $first->engine);
        self::assertNull($first->appliedAt);
        self::assertSame(117, $fixes[1]->fixId);
    }

    public function testMalformedEntriesAreSkippedOrDefaulted(): void
    {
        self::assertSame([], FixView::listFromJson('nope'));
        self::assertSame([], FixView::listFromJson(['fix_id' => 1, 'content' => 'x']));
        self::assertSame([], FixView::listFromJson(null));
        $fixes = FixView::listFromJson([
            ['content' => 'no id'],
            ['fix_id' => '7', 'content' => 'string id'],
            ['fix_id' => 8],
            'not an entry',
            ['fix_id' => 9, 'content' => 'kept', 'kind' => 'video', 'reasons' => 'x', 'applied_at' => '2026-11-01T00:00:00Z'],
            ['fix_id' => 10, 'content' => 'mixed', 'reasons' => ['a', 3, null, 'b'], 'priority' => 5],
        ]);
        self::assertCount(2, $fixes);
        self::assertSame([9, 'video', '', [], '', '', '2026-11-01T00:00:00Z'], [
            $fixes[0]->fixId, $fixes[0]->kind, $fixes[0]->priority, $fixes[0]->reasons,
            $fixes[0]->promptText, $fixes[0]->engine, $fixes[0]->appliedAt,
        ]);
        self::assertSame(['a', 'b'], $fixes[1]->reasons);
        self::assertSame('', $fixes[1]->priority);
    }
}
