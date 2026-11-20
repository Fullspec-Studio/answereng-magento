<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Admin\SummaryView;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\ReadBudget;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class SummaryProviderTest extends DbTestCase
{
    private FakeSettings $settings;
    private FakeTransport $transport;
    private ArrayCache $cache;
    private SyncStateRepository $state;
    private SummaryProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store']);
        $this->transport = new FakeTransport();
        $this->cache = new ArrayCache();
        $this->state = new SyncStateRepository($this->db);
        $this->provider = new SummaryProvider($this->settings, $this->state,
            new EngineClient($this->transport, new NullLogger()), $this->cache, new FixedClock());
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . "/Fixtures/read_api/$name"), true);
    }

    public function testATestEngineHasNoDashboardLink(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        self::assertNull($this->provider->forView(1)->dashboardUrl);
    }

    public function testFreshResultIsCachedAndSnapshotted(): void
    {
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store', 'engineUrl' => 'https://api.answereng.io']);
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::FRESH, $view->state);
        self::assertNotNull($view->rate);
        self::assertSame('https://app.answereng.io/stores/demo-outdoor-store', $view->dashboardUrl);
        self::assertSame(SummaryView::FRESH, $this->provider->forView(1)->state);
        self::assertCount(1, $this->transport->requests);
        self::assertNotNull($this->state->get(1)->snapshotJson);
    }

    public function testUnreachableEngineFallsBackToAMatchingSnapshotMarkedStale(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $this->provider->forView(1);
        $this->cache->items = [];
        $this->transport->enqueue(FakeTransport::json(504, null))->enqueue(FakeTransport::json(504, null));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::STALE, $view->state);
        self::assertSame('2026-10-07 12:00:00', $view->fetchedAt?->format('Y-m-d H:i:s'));
    }

    public function testASnapshotFromAnotherConnectionIsNeverShown(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $this->provider->forView(1);
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store', 'apiKey' => 'ae_other_workspace']);
        $this->transport->enqueue(FakeTransport::json(504, null))->enqueue(FakeTransport::json(504, null));
        self::assertSame(SummaryView::UNAVAILABLE, $this->provider->forView(1)->state);
    }

    public function testNon404ClientErrorsNeverFallBackToASnapshot(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $this->provider->forView(1);
        $this->cache->items = [];
        $this->transport->enqueue(FakeTransport::json(403, ['code' => 'invalid_key']));
        self::assertSame(SummaryView::UNAVAILABLE, $this->provider->forView(1)->state);
        $this->transport->enqueue(FakeTransport::json(404, null));
        self::assertSame(SummaryView::NOT_SYNCED, $this->provider->forView(1)->state);
    }

    public function testUnknownStoreAfterASyncWithThisKeyIsOutOfSync(): void
    {
        $this->state->ensure(1);
        $rev = $this->state->get(1)->configRevision;
        $at = new \DateTimeImmutable('2026-11-05 21:59:00');
        $this->state->recordSuccess(1, $rev, $at, $at, 181, 'demo-outdoor-store', null);
        $this->transport->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        self::assertSame(SummaryView::OUT_OF_SYNC, $this->provider->forView(1)->state);
    }

    public function testUnknownStoreAfterASyncWithAnotherKeyIsNotSynced(): void
    {
        $this->state->ensure(1);
        $rev = $this->state->get(1)->configRevision;
        $at = new \DateTimeImmutable('2026-11-05 21:59:00');
        $this->state->recordSuccess(1, $rev, $at, $at, 181, 'old-key', null);
        $this->transport->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        self::assertSame(SummaryView::NOT_SYNCED, $this->provider->forView(1)->state);
    }

    public function testNotSyncedNoRunPendingAndDisabled(): void
    {
        $this->transport->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        self::assertSame(SummaryView::NOT_SYNCED, $this->provider->forView(1)->state);
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary_no_runs.json')));
        self::assertSame(SummaryView::NO_RUN, $this->provider->forView(1)->state);
        $this->cache->items = [];
        $this->transport->enqueue(FakeTransport::json(409, ['code' => 'store_pending_deletion']));
        self::assertSame(SummaryView::PENDING_DELETION, $this->provider->forView(1)->state);
        $this->settings->put(1, ['enabled' => false]);
        self::assertSame(SummaryView::DISABLED, $this->provider->forView(1)->state);
    }

    public function testRunningPreviewIsShortCachedAndNotSnapshotted(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary_preview_running.json')));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::PREVIEW_RUNNING, $view->state);
        self::assertSame([SummaryProvider::NO_RUN_CACHE_SECONDS], array_values($this->cache->ttls));
        self::assertNull($this->state->get(1)->snapshotJson);
    }

    public function testCompletePreviewCarriesItsData(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary_preview_complete.json')));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::PREVIEW_COMPLETE, $view->state);
        self::assertSame(4, $view->preview['answers_recommended']);
        self::assertSame(15, $view->preview['answers']);
    }

    public function testFailedPreviewIsNoRunAndARealRunKeepsTheLongCache(): void
    {
        $failed = $this->fixture('summary_no_runs.json');
        $failed['preview'] = ['state' => 'failed'];
        $this->transport->enqueue(FakeTransport::json(200, $failed));
        self::assertSame(SummaryView::NO_RUN, $this->provider->forView(1)->state);
        $this->provider->forget(1);
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $this->provider->forView(1);
        self::assertContains(SummaryProvider::CACHE_SECONDS, $this->cache->ttls);
    }

    public function testForgetDropsTheCachedSummary(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary_preview_running.json')));
        $this->provider->forView(1);
        $this->provider->forget(1);
        self::assertSame([], $this->cache->items);
    }

    public function testATransientFailureStopsTheBudget(): void
    {
        $budget = new ReadBudget(new FixedClock());
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        self::assertSame(SummaryView::FRESH, $this->provider->forView(1, $budget)->state);
        self::assertFalse($budget->stopped());
        $this->cache->items = [];
        $this->transport->enqueue(FakeTransport::json(504, null))->enqueue(FakeTransport::json(504, null));
        self::assertSame(SummaryView::STALE, $this->provider->forView(1, $budget)->state);
        self::assertTrue($budget->stopped());
        self::assertSame(15, $this->transport->requests[1]['timeout']);
    }
}
