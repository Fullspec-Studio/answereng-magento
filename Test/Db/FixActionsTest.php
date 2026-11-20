<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\FixActions;
use Fullspec\AnswerEng\Model\Admin\ResultsProvider;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class FixActionsTest extends DbTestCase
{
    private const UNCONFIRMED = "Couldn't confirm that change. AnswerEng didn't respond in time. "
        . 'Refresh in a moment to see whether it was applied.';
    private FakeSettings $settings;
    private FakeTransport $transport;
    private ArrayCache $cache;
    private SyncStateRepository $state;
    private FixActions $actions;
    private string $fp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store']);
        $this->transport = new FakeTransport();
        $this->cache = new ArrayCache();
        $this->state = new SyncStateRepository($this->db);
        $clock = new FixedClock();
        $engine = new EngineClient($this->transport, new NullLogger());
        $summaries = new SummaryProvider($this->settings, $this->state, $engine, $this->cache, $clock);
        $results = new ResultsProvider($this->settings, $this->state, $engine, $this->cache, $clock, $summaries);
        $this->actions = new FixActions($this->settings, $engine, $results);
        $this->fp = $this->settings->forStore(1)->fingerprint();
        foreach (['answereng_fixes_suggested_', 'answereng_fixes_applied_', 'answereng_summary_'] as $prefix) {
            $this->cache->save($prefix . $this->fp, '[]', 300);
        }
    }

    private function assertCaches(bool $fixesKept): void
    {
        self::assertSame($fixesKept, $this->cache->load('answereng_fixes_suggested_' . $this->fp) !== null);
        self::assertSame($fixesKept, $this->cache->load('answereng_fixes_applied_' . $this->fp) !== null);
        self::assertNotNull($this->cache->load('answereng_summary_' . $this->fp), 'summary cache untouched');
    }

    public function testSuccessForgetsBothListsOnly(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, ['ok' => true]));
        $result = $this->actions->setApplied(1, 118, true);
        self::assertTrue($result->ok);
        self::assertSame('Marked as applied.', $result->message);
        self::assertSame('POST https://engine.test/stores/demo-outdoor-store/fixes/118/applied',
            $this->transport->requests[0]['method'] . ' ' . $this->transport->requests[0]['url']);
        $this->assertCaches(false);

        $this->transport->enqueue(FakeTransport::json(200, ['ok' => true]));
        $undo = $this->actions->setApplied(1, 118, false);
        self::assertSame('Moved back to drafted fixes.', $undo->message);
        self::assertSame('DELETE', $this->transport->requests[1]['method']);
    }

    public function testEngine404IsAMissingFixNotAMissingStore(): void
    {
        $before = $this->state->get(1);
        $this->transport->enqueue(FakeTransport::json(404, ['error' => 'x', 'code' => 'unknown_store']));
        $result = $this->actions->setApplied(1, 999, true);
        self::assertFalse($result->ok);
        self::assertSame('That fix no longer exists in AnswerEng. Refresh to see the current list.', $result->message);
        $this->assertCaches(true);
        self::assertEquals($before, $this->state->get(1));
    }

    public function testRefusedIsUnchanged(): void
    {
        $this->transport->enqueue(FakeTransport::json(409, ['error' => 'x', 'code' => 'fix_not_applicable']));
        $result = $this->actions->setApplied(1, 118, true);
        self::assertFalse($result->ok);
        self::assertSame("AnswerEng refused that fix, so it can't be marked as applied.", $result->message);
        $this->assertCaches(true);
    }

    /** @return array<string,array{int}> */
    public static function otherClientErrors(): array
    {
        return ['400' => [400], '403' => [403], '429 throttled before the engine' => [429]];
    }

    /** @dataProvider otherClientErrors */
    public function testOtherFourXxIsNothingChanged(int $status): void
    {
        $this->transport->enqueue(FakeTransport::json($status, ['error' => 'x']));
        $result = $this->actions->setApplied(1, 118, true);
        self::assertFalse($result->ok);
        self::assertSame("AnswerEng didn't accept that change. Nothing was changed.", $result->message);
        $this->assertCaches(true);
    }

    public function testLostResponseForgetsListsAndSaysUnconfirmed(): void
    {
        $this->transport->enqueue(new TransportException('timed out'));
        $result = $this->actions->setApplied(1, 118, true);
        self::assertFalse($result->ok);
        self::assertSame(self::UNCONFIRMED, $result->message);
        self::assertCount(1, $this->transport->requests);
        $this->assertCaches(false);
    }

    public function testFiveHundredIsUnconfirmed(): void
    {
        $this->transport->enqueue(FakeTransport::json(503, null));
        $result = $this->actions->setApplied(1, 118, false);
        self::assertSame(self::UNCONFIRMED, $result->message);
        $this->assertCaches(false);
    }

    public function testDisabledOrUnconfiguredViewSendsNothing(): void
    {
        $this->settings->put(1, ['enabled' => false]);
        $result = $this->actions->setApplied(1, 118, true);
        self::assertSame('Enable this store view and enter an API key first.', $result->message);
        $this->settings->put(1, ['apiKey' => '']);
        self::assertFalse($this->actions->setApplied(1, 118, true)->ok);
        self::assertSame([], $this->transport->requests);
    }
}
