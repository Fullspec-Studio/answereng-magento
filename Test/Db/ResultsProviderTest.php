<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\ResultsProvider;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Admin\SummaryView;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class ResultsProviderTest extends DbTestCase
{
    private FakeSettings $settings;
    private FakeTransport $transport;
    private ArrayCache $cache;
    private FixedClock $clock;
    private SyncStateRepository $state;
    private ResultsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store']);
        $this->transport = new FakeTransport();
        $this->cache = new ArrayCache();
        $this->clock = new FixedClock();
        $this->state = new SyncStateRepository($this->db);
        $engine = new EngineClient($this->transport, new NullLogger());
        $summaries = new SummaryProvider($this->settings, $this->state, $engine, $this->cache, $this->clock);
        $this->provider = new ResultsProvider($this->settings, $this->state, $engine, $this->cache, $this->clock,
            $summaries);
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . "/Fixtures/read_api/$name"), true);
    }

    private function applied(): array
    {
        $fix = $this->fixture('fixes.json')[1];
        $fix['status'] = 'applied';
        $fix['applied_at'] = '2026-10-01T09:00:00+00:00';
        return [$fix];
    }

    private function enqueueAll(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')))
            ->enqueue(FakeTransport::json(200, $this->fixture('fixes.json')))
            ->enqueue(FakeTransport::json(200, $this->applied()));
    }

    public function testFreshSummaryLoadsBothListsOnceAndCachesThem(): void
    {
        $this->enqueueAll();
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::FRESH, $view->summary->state);
        self::assertCount(2, $view->fixes ?? []);
        self::assertSame(118, $view->fixes[0]->fixId);
        self::assertCount(1, $view->applied ?? []);
        self::assertFalse($view->fixesStale);
        self::assertCount(3, $this->transport->requests);
        self::assertStringContainsString('/fixes?status=suggested', $this->transport->requests[1]['url']);
        self::assertStringContainsString('/fixes?status=applied', $this->transport->requests[2]['url']);
        $fp = $this->settings->forStore(1)->fingerprint();
        self::assertSame(SummaryProvider::CACHE_SECONDS, $this->cache->ttls['answereng_fixes_suggested_' . $fp]);
        self::assertSame(SummaryProvider::CACHE_SECONDS, $this->cache->ttls['answereng_fixes_applied_' . $fp]);
        $snapshot = $this->state->get(1);
        self::assertNotNull($snapshot->snapshotFixesJson);
        self::assertNotNull($snapshot->snapshotAppliedJson);

        $again = $this->provider->forView(1);
        self::assertCount(3, $this->transport->requests);
        self::assertCount(2, $again->fixes ?? []);
        self::assertCount(1, $again->applied ?? []);
    }

    public function testHangingEngineFallsBackToTheSnapshotWithinTheBudget(): void
    {
        $this->enqueueAll();
        $this->provider->forView(1);
        $this->cache->items = [];
        $this->transport->requests = [];
        $this->transport->onSend = function (string $method, string $url, int $timeout): void {
            $this->clock->advance($timeout);
        };
        for ($i = 0; $i < 6; $i++) {
            $this->transport->enqueue(new TransportException('timed out'));
        }
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::STALE, $view->summary->state);
        self::assertCount(2, $view->fixes ?? []);
        self::assertCount(1, $view->applied ?? []);
        self::assertTrue($view->fixesStale);
        $total = array_sum(array_column($this->transport->requests, 'timeout'));
        self::assertLessThanOrEqual(20, $total);
        foreach ($this->transport->requests as $r) {
            self::assertStringNotContainsString('/fixes', $r['url']);
        }
    }

    public function testSuggestedFailureUsesTheSnapshotAndSkipsApplied(): void
    {
        $this->enqueueAll();
        $this->provider->forView(1);
        $this->provider->forgetFixes(1);
        $this->transport->requests = [];
        $this->transport->enqueue(FakeTransport::json(503, null))->enqueue(FakeTransport::json(503, null));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::FRESH, $view->summary->state);
        self::assertCount(2, $view->fixes ?? []);
        self::assertTrue($view->fixesStale);
        self::assertCount(2, $this->transport->requests);
    }

    public function testAppliedFailureHidesOnlyTheAppliedSection(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')))
            ->enqueue(FakeTransport::json(200, $this->fixture('fixes.json')))
            ->enqueue(FakeTransport::json(503, null))->enqueue(FakeTransport::json(503, null));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::FRESH, $view->summary->state);
        self::assertCount(2, $view->fixes ?? []);
        self::assertNull($view->applied);
        self::assertFalse($view->fixesStale);
        self::assertNull($this->state->get(1)->snapshotFixesJson);
    }

    public function testNoRunMeansNoFixRequests(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary_no_runs.json')));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::NO_RUN, $view->summary->state);
        self::assertNull($view->fixes);
        self::assertNull($view->applied);
        self::assertCount(1, $this->transport->requests);
    }

    public function testFixesCacheIsPerConnection(): void
    {
        $this->enqueueAll();
        $this->provider->forView(1);
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store', 'apiKey' => 'ae_other_workspace']);
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')))
            ->enqueue(FakeTransport::json(200, []))
            ->enqueue(FakeTransport::json(200, []));
        $view = $this->provider->forView(1);
        self::assertCount(6, $this->transport->requests);
        self::assertSame([], $view->fixes);
        self::assertSame([], $view->applied);
    }

    public function testForgetFixesKeepsTheSummary(): void
    {
        $this->enqueueAll();
        $this->provider->forView(1);
        $this->provider->forgetFixes(1);
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('fixes.json')))
            ->enqueue(FakeTransport::json(200, $this->applied()));
        $this->provider->forView(1);
        self::assertCount(5, $this->transport->requests);
        self::assertStringContainsString('/fixes?status=suggested', $this->transport->requests[3]['url']);

        $this->provider->forget(1);
        self::assertSame([], $this->cache->items);
    }
}
