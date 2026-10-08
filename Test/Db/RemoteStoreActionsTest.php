<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\ConnectionReport;
use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeDisabler;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class RemoteStoreActionsTest extends DbTestCase
{
    private const A = 'acct_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'acct_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private FakeSettings $settings;
    private FakeTransport $transport;
    private SyncStateRepository $state;
    private RemoteStoreRegistry $registry;
    private FakeDisabler $disabler;
    private SyncLog $log;
    private RemoteStoreActions $actions;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->transport = new FakeTransport();
        $this->state = new SyncStateRepository($this->db);
        $this->registry = new RemoteStoreRegistry($this->db);
        $this->disabler = new FakeDisabler($this->settings);
        $this->log = new SyncLog($this->db);
        $this->clock = new FixedClock();
        $this->actions = new RemoteStoreActions($this->settings, $this->state, $this->log,
            new EngineClient($this->transport, new NullLogger()), new Classifier(), $this->registry,
            new RoleResolver($this->settings, $this->state, $this->registry), $this->disabler, $this->clock);
    }

    private function pushed(int $storeId, string $key, string $account = self::A): int
    {
        $s = $this->settings->put($storeId, ['storeKey' => $key]);
        $this->state->recordAccount($storeId, $account, $s->apiKeyHash());
        return $this->registry->recordPush($s->engineUrl, $account, $key, $storeId, $this->clock->now());
    }

    private function respond(int $status, ?array $json, string $account = self::A, array $headers = []): void
    {
        $this->transport->enqueue(FakeTransport::json($status, $json, ['X-AnswerEng-Account' => $account] + $headers));
    }

    private function methods(): array
    {
        return array_map(static fn ($r) => $r['method'] . ' ' . $r['url'], $this->transport->requests);
    }

    public function testConnectionWithoutAKeySendsNothing(): void
    {
        $this->settings->put(1, ['apiKey' => '']);
        self::assertSame(ConnectionReport::NO_KEY, $this->actions->testConnection(1)->status);
        self::assertSame([], $this->transport->requests);
    }

    public function testConnectionOutcomes(): void
    {
        $this->settings->put(1, ['storeKey' => 'shop.example.com']);
        $this->respond(504, null);
        $this->respond(504, null);
        self::assertSame(ConnectionReport::UNREACHABLE, $this->actions->testConnection(1)->status);

        $this->transport->enqueue(FakeTransport::json(403, ['error' => 'Forbidden', 'code' => 'invalid_key']));
        self::assertSame(ConnectionReport::REJECTED, $this->actions->testConnection(1)->status);

        $this->respond(200, [['store_key' => 'other.example.com', 'status' => 'active']]);
        $report = $this->actions->testConnection(1);
        self::assertSame([ConnectionReport::FIRST_SYNC_NEEDED, ['other.example.com']], [$report->status, $report->existingKeys]);

        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'active']]);
        $this->respond(200, ['store_key' => 'shop.example.com', 'latest_run' => null]);
        self::assertSame(ConnectionReport::WAITING_FIRST_RUN, $this->actions->testConnection(1)->status);

        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'active']]);
        $this->respond(200, ['store_key' => 'shop.example.com', 'latest_run' => ['run_id' => 3]]);
        self::assertSame(ConnectionReport::FOUND, $this->actions->testConnection(1)->status);
        self::assertSame(self::A, $this->state->get(1)->accountId);
    }

    public function testClearTargetsTheCurrentStoreAfterTheConnectionCheck(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);                       // GET /stores (connection check)
        $this->respond(200, ['products_deleted' => 3]);
        $result = $this->actions->clear(1, 'admin');
        self::assertTrue($result->ok, $result->message);
        self::assertSame(['GET https://engine.test/stores', 'DELETE https://engine.test/stores/shop.example.com/catalog'],
            $this->methods());
        self::assertSame('admin', $this->log->recent(1)[0]['admin_user']);
    }

    public function testClearIsRefusedWhenTheConnectionNowPointsAtAnotherAccount(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->respond(200, [], self::B);
        $result = $this->actions->clear(1, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('different AnswerEng connection', $result->message);
        self::assertSame(['GET https://engine.test/stores'], $this->methods());
    }

    public function testClearIsRefusedWhileAnotherViewIsUsingTheStore(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->pushed(2, 'shop.example.com');
        $this->settings->put(1, ['storeKey' => 'shop.example.com']);   // still current for 1 too
        $this->respond(200, []);
        $result = $this->actions->clear(1, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('Store 2', $result->message);
    }

    public function testDeleteNeedsTheExactKeyAndTheAcknowledgement(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        self::assertFalse($this->actions->delete(1, $id, 'Shop.example.com', true, 'admin')->ok);
        self::assertFalse($this->actions->delete(1, $id, 'shop.example.com', false, 'admin')->ok);
        self::assertSame([], $this->transport->requests);
    }

    public function testDeletingTheCurrentStoreSchedulesItAndDisablesTheView(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(202, ['store_key' => 'shop.example.com', 'status' => 'pending_deletion',
            'purge_after' => '2026-10-14T12:00:00+00:00']);
        $result = $this->actions->delete(1, $id, 'shop.example.com', true, 'admin');
        self::assertTrue($result->ok, $result->message);
        self::assertStringContainsString('2026-10-14', $result->message);
        self::assertSame('pending_deletion', $this->registry->get($id)?->remoteStatus);
        self::assertSame([1], $this->disabler->disabled);
        self::assertSame('{"confirm_store_key":"shop.example.com"}', $this->transport->requests[1]['body']);
    }

    public function testDeletingAPreviousStoreLeavesCurrentSyncingAlone(): void
    {
        $old = $this->pushed(1, 'old.example.com');
        $this->pushed(1, 'new.example.com');
        $this->respond(200, []);
        $this->respond(202, ['purge_after' => '2026-10-14T12:00:00+00:00']);
        self::assertTrue($this->actions->delete(1, $old, 'old.example.com', true, 'admin')->ok);
        self::assertSame([], $this->disabler->disabled);
    }

    public function testDeleteReportsARunInProgress(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(409, ['code' => 'run_in_progress']);
        $result = $this->actions->delete(1, $id, 'shop.example.com', true, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('analysis run is in progress', $result->message);
        self::assertSame('active', $this->registry->get($id)?->remoteStatus);
    }

    public function testCancelRestoresButNeverReEnables(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->registry->setStatus($id, 'pending_deletion', $this->clock->now()->modify('+7 days'));
        $this->settings->put(1, ['enabled' => false, 'storeKey' => 'shop.example.com']);
        $this->respond(200, []);
        $this->respond(200, ['status' => 'active']);
        self::assertTrue($this->actions->cancel(1, $id, 'admin')->ok);
        self::assertSame('active', $this->registry->get($id)?->remoteStatus);
        self::assertFalse($this->settings->forStore(1)->enabled);

        $this->registry->setStatus($id, 'pending_deletion', $this->clock->now());
        $this->respond(200, []);
        $this->respond(409, ['code' => 'purge_in_progress']);
        $result = $this->actions->cancel(1, $id, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('can no longer be cancelled', $result->message);
    }

    public function testForgetIsLocalAndOnlyForPreviousStores(): void
    {
        $current = $this->pushed(1, 'shop.example.com');
        self::assertFalse($this->actions->forget(1, $current, 'admin')->ok);
        $this->settings->put(1, ['storeKey' => 'renamed.example.com']);
        self::assertTrue($this->actions->forget(1, $current, 'admin')->ok);
        self::assertNull($this->registry->get($current));
        self::assertSame([], $this->transport->requests);
    }

    public function testRefreshMarksPurgedStoresDeletedAndPendingOnesPending(): void
    {
        $gone = $this->pushed(1, 'gone.example.com');
        $this->registry->setStatus($gone, 'pending_deletion', $this->clock->now()->modify('-1 day'));
        $waiting = $this->pushed(1, 'shop.example.com');
        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'pending_deletion',
            'purge_after' => '2026-10-14T12:00:00+00:00']]);
        $this->actions->refreshStatuses(1);
        self::assertSame('deleted', $this->registry->get($gone)?->remoteStatus);
        self::assertSame(['pending_deletion', '2026-10-14 12:00:00'],
            [$this->registry->get($waiting)?->remoteStatus, $this->registry->get($waiting)?->purgeAfter?->format('Y-m-d H:i:s')]);
    }

    public function testClearWorksOnADisabledView(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->settings->put(1, ['enabled' => false, 'storeKey' => 'shop.example.com']);
        $this->respond(200, []);
        $this->respond(200, ['products_deleted' => 3]);
        $result = $this->actions->clear(1, 'admin');
        self::assertTrue($result->ok, $result->message);
        self::assertSame('DELETE https://engine.test/stores/shop.example.com/catalog', $this->methods()[1]);
    }

    public function testClearNamesTheStoreWhenPendingDeletion(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(409, ['code' => 'store_pending_deletion']);
        $result = $this->actions->clear(1, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('shop.example.com', $result->message);
    }

    public function testDeleteAndCancelOnUnknownStoreMarkTheIdentityDeleted(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(404, ['code' => 'unknown_store']);
        $result = $this->actions->delete(1, $id, 'shop.example.com', true, 'admin');
        self::assertFalse($result->ok);
        self::assertStringEndsWith('Syncing for this store view is now disabled.', $result->message);
        self::assertSame('deleted', $this->registry->get($id)?->remoteStatus);
        self::assertSame([1], $this->disabler->disabled);
        self::assertSame(['deletion_requested', 'shop.example.com already gone (HTTP 404)', 'admin'],
            [$this->log->recent(1)[0]['outcome'], $this->log->recent(1)[0]['detail'], $this->log->recent(1)[0]['admin_user']]);

        $id2 = $this->pushed(1, 'other.example.com');
        $this->registry->setStatus($id2, 'pending_deletion', $this->clock->now());
        $this->respond(200, []);
        $this->respond(404, ['code' => 'unknown_store']);
        self::assertFalse($this->actions->cancel(1, $id2, 'admin')->ok);
        self::assertSame('deleted', $this->registry->get($id2)?->remoteStatus);
    }

    public function testDeleteOfAPreviousStoreAlreadyGoneLeavesTheViewSyncing(): void
    {
        $old = $this->pushed(1, 'old.example.com');
        $this->pushed(1, 'new.example.com');
        $this->respond(200, []);
        $this->respond(404, ['code' => 'unknown_store']);
        $result = $this->actions->delete(1, $old, 'old.example.com', true, 'admin');
        self::assertFalse($result->ok);
        self::assertStringNotContainsString('disabled', $result->message);
        self::assertSame('deleted', $this->registry->get($old)?->remoteStatus);
        self::assertSame([], $this->disabler->disabled);
        self::assertTrue($this->settings->forStore(1)->enabled);
    }

    public function testDeleteIsRefusedUnlessActive(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->registry->setStatus($id, 'pending_deletion', $this->clock->now());
        self::assertFalse($this->actions->delete(1, $id, 'shop.example.com', true, 'admin')->ok);
        self::assertSame([], $this->transport->requests);
    }

    public function testDeleteSyncsLocalStatusOnPendingDeletionConflict(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(409, ['code' => 'store_pending_deletion', 'purge_after' => '2026-10-14T12:00:00+00:00']);
        self::assertFalse($this->actions->delete(1, $id, 'shop.example.com', true, 'admin')->ok);
        self::assertSame('pending_deletion', $this->registry->get($id)?->remoteStatus);
    }

    public function testRefreshToleratesAMalformedPurgeAfter(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'pending_deletion', 'purge_after' => 'garbage']]);
        $this->actions->refreshStatuses(1);
        self::assertSame('pending_deletion', $this->registry->get($id)?->remoteStatus);
    }
}
