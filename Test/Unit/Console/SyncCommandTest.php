<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Console;

use Fullspec\AnswerEng\Console\Command\Sync;
use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Test\Support\FakeRunner;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    private FakeSettings $settings;
    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->settings = new FakeSettings();
        $this->settings->put(1);
        $this->settings->put(2);
        $this->settings->put(3, ['enabled' => false]);
        $this->runner = new FakeRunner();
    }

    private function runSync(array $input): CommandTester
    {
        $tester = new CommandTester(new Sync($this->settings, $this->runner));
        $tester->execute($input);
        return $tester;
    }

    public function testSyncsEveryEnabledViewByDefault(): void
    {
        $t = $this->runSync([]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertSame([[1, 'cli', true], [2, 'cli', true]], $this->runner->calls);
    }

    public function testSyncsOneViewByCode(): void
    {
        $this->runSync(['--store' => 'store2']);
        self::assertSame([[2, 'cli', true]], $this->runner->calls);
    }

    public function testUnknownOrDisabledViewFails(): void
    {
        self::assertSame(Command::FAILURE, $this->runSync(['--store' => 'nope'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->runSync(['--store' => 'store3'])->getStatusCode());
        self::assertSame([], $this->runner->calls);
    }

    public function testAnErrorResultFailsTheCommand(): void
    {
        $this->runner->result = new SyncResult(SyncResult::THROTTLED, 'Rate limited by AnswerEng — try again after 2026-10-07 12:10 UTC.');
        $t = $this->runSync(['--store' => 'store1']);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('Rate limited', $t->getDisplay());
    }
}
