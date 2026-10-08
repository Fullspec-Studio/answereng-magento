<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Console;

use Fullspec\AnswerEng\Console\Command\Sync;
use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Test\Support\FakeRunner;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    private FakeSettings $settings;
    private FakeRunner $runner;
    private State $appState;

    protected function setUp(): void
    {
        $this->settings = new FakeSettings();
        $this->settings->put(1);
        $this->settings->put(2);
        $this->settings->put(3, ['enabled' => false]);
        $this->runner = new FakeRunner();
        $this->appState = $this->createMock(State::class);
    }

    private function runSync(array $input): CommandTester
    {
        $tester = new CommandTester(new Sync($this->settings, $this->runner, $this->appState));
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

    public function testAThrowingViewFailsButTheNextStillRuns(): void
    {
        $this->runner->throwFor = [1];
        $t = $this->runSync([]);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertSame([[1, 'cli', true], [2, 'cli', true]], $this->runner->calls);
        self::assertStringContainsString('boom', $t->getDisplay());
    }

    /** Found in the local end-to-end run: without an area the export failed with "Area code is not set". */
    public function testSetsTheCronAreaBeforeSyncing(): void
    {
        $this->appState->expects(self::once())->method('setAreaCode')->with('crontab');
        self::assertSame(Command::SUCCESS, $this->runSync([])->getStatusCode());
    }

    public function testAnAlreadySetAreaIsKept(): void
    {
        $this->appState->method('setAreaCode')->willThrowException(new LocalizedException(new Phrase('Area code is already set')));
        self::assertSame(Command::SUCCESS, $this->runSync([])->getStatusCode());
        self::assertSame([[1, 'cli', true], [2, 'cli', true]], $this->runner->calls);
    }
}
