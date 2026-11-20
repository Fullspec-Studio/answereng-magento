<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\ReadBudget;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class ReadBudgetTest extends TestCase
{
    public function testAttemptTimeoutShrinksWithTheBudget(): void
    {
        $clock = new FixedClock();
        $budget = new ReadBudget($clock);
        self::assertSame(15, $budget->attemptTimeout());
        self::assertTrue($budget->allowsRetry());
        $clock->advance(8);
        self::assertSame(12, $budget->attemptTimeout());
        $clock->advance(8);
        self::assertSame(4, $budget->attemptTimeout());
        self::assertFalse($budget->allowsRetry());
        self::assertFalse($budget->stopped());
        $clock->advance(4);
        self::assertTrue($budget->stopped());
        self::assertSame(0, $budget->remaining());
    }

    public function testStopEndsTheBudgetEarly(): void
    {
        $budget = new ReadBudget(new FixedClock());
        self::assertFalse($budget->stopped());
        $budget->stop();
        self::assertTrue($budget->stopped());
    }
}
