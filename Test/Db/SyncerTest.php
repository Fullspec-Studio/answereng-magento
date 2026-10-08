<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Catalog\CatalogBuilder;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Model\Sync\Syncer;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeCatalogSource;
use Fullspec\AnswerEng\Test\Support\FakeLock;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class SyncerTest extends DbTestCase
{
    private const ACCT = 'acct_0123456789abcdef0123456789abcdef';
    private FakeSettings $settings;
    private FakeCatalogSource $catalog;
    private FakeTransport $transport;
    private FakeLock $lock;
    private FixedClock $clock;
    private SyncStateRepository $state;
    private SyncLog $log;
    private RemoteStoreRegistry $registry;
    private Syncer $syncer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->catalog = new FakeCatalogSource();
        $this->transport = new FakeTransport();
        $this->lock = new FakeLock();
        $this->clock = new FixedClock();
        $this->state = new SyncStateRepository($this->db);
        $this->log = new SyncLog($this->db);
        $this->registry = new RemoteStoreRegistry($this->db);
        $this->syncer = new Syncer($this->settings, $this->state, $this->log, new CatalogBuilder($this->catalog),
            new EngineClient($this->transport, new NullLogger()), new Classifier(), $this->registry,
            $this->lock, $this->clock, new NullLogger());
        $this->settings->put(1, ['storeKey' => 'shop.example.com']);
        $this->catalog->add(1, 10);
    }

    private function ok(int $status = 200, array $json = ['store_id' => 5]): void
    {
        $this->transport->enqueue(FakeTransport::json($status, $json, ['X-AnswerEng-Account' => self::ACCT]));
    }

    private function failWith(int $status, array $json = []): void
    {
        $this->transport->enqueue(FakeTransport::json($status, $json, ['X-AnswerEng-Account' => self::ACCT]));
    }

    public function testPushRecordsSuccessAccountRegistryAndLog(): void
    {
        $this->state->markDirty([1], $this->clock->now()->modify('-1 hour'));
        $this->ok();
        $result = $this->syncer->sync(1, 'debounce');
        self::assertSame(SyncResult::PUSHED, $result->status);
        $req = $this->transport->requests[0];
        self::assertSame('https://engine.test/catalog', $req['url']);
        self::assertSame('shop.example.com', json_decode((string) $req['body'], true)['store_key']);
        $s = $this->state->get(1);
        self::assertSame(['ok', false, 1, 'shop.example.com', self::ACCT],
            [$s->state, $s->dirty, $s->productCount, $s->syncedStoreKey, $s->accountId]);
        self::assertSame('shop.example.com', $this->registry->forView(1)[0]->storeKey);
        self::assertSame('pushed', $this->log->recent(1)[0]['outcome']);
    }

    public function testNoApiKeyNeverContactsTheEngine(): void
    {
        $this->settings->put(1, ['apiKey' => '']);
        self::assertSame(SyncResult::BLOCKED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
        self::assertSame(['blocked', 'no_api_key'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
    }

    public function testDisabledViewIsSkipped(): void
    {
        $this->settings->put(1, ['enabled' => false]);
        self::assertSame(SyncResult::SKIPPED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
    }

    public function testDuplicateKeyIsBlocked(): void
    {
        $this->settings->put(2, ['storeKey' => 'store1.example.com']);
        $this->settings->put(1, ['storeKey' => 'store1.example.com']);
        self::assertSame(SyncResult::BLOCKED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame('duplicate_store_key', $this->state->get(1)->reason);
        self::assertSame([], $this->transport->requests);
    }

    public function testTooManyProductsIsBlockedWithoutARequest(): void
    {
        for ($i = 100; $i <= 5100; $i++) {
            $this->catalog->add(1, $i);
        }
        self::assertSame(SyncResult::BLOCKED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame('too_many_products', $this->state->get(1)->reason);
        self::assertSame([], $this->transport->requests);
    }

    public function testEmptyCatalogNeverSyncedCallsNothing(): void
    {
        $this->catalog->products = [];
        self::assertSame(SyncResult::CLEARED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
        self::assertSame(['ok', 'catalog_cleared'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
    }

    public function testEmptyCatalogAfterASyncClearsTheEngine(): void
    {
        $this->ok();
        $this->syncer->sync(1, 'debounce');
        $this->catalog->products = [];
        $this->failWith(404, ['code' => 'unknown_store']);   // already clear is fine
        self::assertSame(SyncResult::CLEARED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame(['DELETE', 'https://engine.test/stores/shop.example.com/catalog'],
            [$this->transport->requests[1]['method'], $this->transport->requests[1]['url']]);
        self::assertSame([0, 'catalog_cleared'], [$this->state->get(1)->productCount, $this->state->get(1)->reason]);
    }

    public function testTransientFailuresRetryThenExhaust(): void
    {
        $this->failWith(504);
        self::assertSame(SyncResult::RETRYING, $this->syncer->sync(1, 'debounce')->status);
        $this->transport->enqueue(new TransportException('reset'));
        $this->syncer->sync(1, 'debounce');
        $this->failWith(502);
        self::assertSame(SyncResult::EXHAUSTED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame(['exhausted', 3], [$this->state->get(1)->state, $this->state->get(1)->attempts]);
    }

    public function testARunInProgressSaysSoAndATransientClearsAStaleReason(): void
    {
        $this->failWith(403, ['code' => 'store_limit', 'limit' => 1]);
        $this->syncer->sync(1, 'debounce');
        self::assertSame('store_limit', $this->state->get(1)->reason);
        $this->failWith(409, ['code' => 'run_in_progress']);
        $result = $this->syncer->sync(1, 'manual', true);
        self::assertSame([SyncResult::RETRYING, 'Analysis run in progress — will clear when it finishes.'],
            [$result->status, $result->message]);
        self::assertSame(['retrying', 'run_in_progress'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
        $this->failWith(504);
        $result = $this->syncer->sync(1, 'manual', true);
        self::assertSame('AnswerEng is waking up or unreachable; retrying shortly.', $result->message);
        self::assertNull($this->state->get(1)->reason);
        $this->transport->enqueue(FakeTransport::json(429, ['code' => 'throttled'], ['Retry-After' => '60']));
        $result = $this->syncer->sync(1, 'manual', true);
        self::assertSame([SyncResult::EXHAUSTED,
            'AnswerEng could not be reached after 3 attempts. The nightly sync will try again.'],
            [$result->status, $result->message]);
    }

    public function testStoreLimitPausesWithItsOwnReason(): void
    {
        $this->failWith(403, ['error' => 'plan allows 1 store(s)', 'code' => 'store_limit', 'limit' => 1]);
        $result = $this->syncer->sync(1, 'debounce');
        self::assertSame(SyncResult::PAUSED, $result->status);
        self::assertStringContainsString('allows 1 store(s)', $result->message);
        self::assertStringContainsString('upgrade your plan', $result->message);
        self::assertSame(['paused', 'store_limit'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
        self::assertSame('HTTP 403 store_limit limit=1', $this->log->recent(1)[0]['detail']);
    }

    public function testACatalogEditDuringARejectedPushLeavesTheViewToResync(): void
    {
        $this->transport->onSend = function (): void {
            $this->state->markDirty([1], $this->clock->now());   // merchant fixes the product mid-push
        };
        $this->failWith(422, ['code' => 'validation_failed', 'errors' => [['loc' => ['body', 'products', 0, 'title'],
            'type' => 'string_too_short']]]);
        self::assertSame(SyncResult::PAUSED, $this->syncer->sync(1, 'debounce')->status);
        $s = $this->state->get(1);
        self::assertSame(['ok', null, true], [$s->state, $s->reason, $s->dirty]);
        $this->clock->advance(SyncStateRepository::DEBOUNCE_SECONDS);
        self::assertSame([1], $this->state->selectForDebounce([1], $this->clock->now()));
    }

    public function testManualSyncHonoursAThrottleWindow(): void
    {
        $this->transport->enqueue(FakeTransport::json(429, ['code' => 'throttled'], ['Retry-After' => '600']));
        $this->syncer->sync(1, 'debounce');
        $result = $this->syncer->sync(1, 'manual', true);
        self::assertSame(SyncResult::THROTTLED, $result->status);
        self::assertSame('2026-10-07 12:10:00', $result->until?->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->transport->requests);
    }

    public function testAConfigChangeDuringThePushDiscardsTheOutcomeButKeepsTheRemoteRecord(): void
    {
        $this->transport->onSend = function (): void {
            $this->state->applySettingsHash(1, 'changed-mid-push', $this->clock->now());
        };
        $this->ok();
        self::assertSame(SyncResult::SUPERSEDED, $this->syncer->sync(1, 'debounce')->status);
        self::assertNull($this->state->get(1)->syncedStoreKey);
        self::assertTrue($this->state->get(1)->dirty);
        self::assertCount(1, $this->registry->forView(1));   // remote side effect still recorded
    }

    public function testAHeldLockSkipsWithoutARequest(): void
    {
        $this->lock->held['answereng_sync_1'] = true;
        self::assertSame(SyncResult::SKIPPED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
    }

    public function testACatalogFailureIsTransientAndPushesNothing(): void
    {
        for ($i = 11; $i <= 600; $i++) {
            $this->catalog->add(1, $i);
        }
        $this->catalog->failOnChunk = new \RuntimeException('db gone');
        self::assertSame(SyncResult::FAILED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
        self::assertSame('retrying', $this->state->get(1)->state);
        self::assertSame([], $this->lock->held);
    }

    public function testSuccessWithoutAnAccountHeaderSkipsTheRegistry(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, ['store_id' => 1]));
        self::assertSame(SyncResult::PUSHED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->registry->forView(1));
    }
}
