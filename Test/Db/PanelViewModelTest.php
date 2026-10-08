<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\PanelViewModel;
use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeDisabler;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Psr\Log\NullLogger;

final class PanelViewModelTest extends DbTestCase
{
    public function testViewDataCarriesRolesStatusesAndPermissions(): void
    {
        $settings = new FakeSettings();
        $s = $settings->put(1, ['storeKey' => 'new.example.com']);
        $state = new SyncStateRepository($this->db);
        $registry = new RemoteStoreRegistry($this->db);
        $clock = new FixedClock();
        $state->recordAccount(1, 'acct_a', $s->apiKeyHash());
        $old = $registry->recordPush('https://engine.test', 'acct_a', 'old.example.com', 1, $clock->now());
        $registry->recordPush('https://engine.test', 'acct_a', 'new.example.com', 1, $clock->now()->modify('+1 minute'));
        $transport = new FakeTransport();
        // refreshStatuses (GET /stores) then summary (GET /stores/{key}/summary)
        $transport->enqueue(FakeTransport::json(200, [], ['X-AnswerEng-Account' => 'acct_a']))
            ->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        $engine = new EngineClient($transport, new NullLogger());
        $roles = new RoleResolver($settings, $state, $registry);
        $log = new SyncLog($this->db);
        $actions = new RemoteStoreActions($settings, $state, $log, $engine, new Classifier(), $registry, $roles,
            new FakeDisabler($settings), $clock);
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r) => $r !== 'Fullspec_AnswerEng::delete_store');
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route) => "/admin/$route");
        $vm = new PanelViewModel($settings, $state, $log, new SummaryProvider($settings, $state, $engine, new ArrayCache(), $clock),
            $actions, $registry, $roles, $auth, $url);

        $data = $vm->forView(1);
        self::assertSame('ok', $data['state']);
        self::assertSame(['current', 'previous'], array_column($data['identities'], 'role'));
        self::assertSame($old, $data['identities'][1]['id']);
        self::assertSame([true, false], [$data['canSync'], $data['canDelete']]);
        self::assertSame('not_synced', $data['summary']->state);
        self::assertSame('/admin/answereng/action/delete', $data['urls']['delete']);
        self::assertTrue($data['canClear']);
        self::assertNull($data['pendingDeletion']);
    }

    private function viewModel(FakeSettings $settings, FakeTransport $transport, bool $allowAll = true): PanelViewModel
    {
        $state = new SyncStateRepository($this->db);
        $registry = new RemoteStoreRegistry($this->db);
        $clock = new FixedClock();
        $engine = new EngineClient($transport, new NullLogger());
        $roles = new RoleResolver($settings, $state, $registry);
        $log = new SyncLog($this->db);
        $actions = new RemoteStoreActions($settings, $state, $log, $engine, new Classifier(), $registry, $roles,
            new FakeDisabler($settings), $clock);
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn($allowAll);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route) => "/admin/$route");
        return new PanelViewModel($settings, $state, $log,
            new SummaryProvider($settings, $state, $engine, new ArrayCache(), $clock), $actions, $registry, $roles, $auth, $url);
    }

    public function testDisabledViewStillOffersClearForItsCurrentIdentity(): void
    {
        $settings = new FakeSettings();
        $s = $settings->put(1, ['storeKey' => 'shop.example.com', 'enabled' => false]);
        (new SyncStateRepository($this->db))->recordAccount(1, 'acct_a', $s->apiKeyHash());
        (new RemoteStoreRegistry($this->db))->recordPush('https://engine.test', 'acct_a', 'shop.example.com', 1,
            (new FixedClock())->now());
        $transport = new FakeTransport();
        $transport->enqueue(FakeTransport::json(200, [], ['X-AnswerEng-Account' => 'acct_a']));
        $data = $this->viewModel($settings, $transport)->forView(1);
        self::assertFalse($data['enabled']);
        self::assertTrue($data['canClear']);
    }

    public function testPendingDeletionIdentityIsExposedWithPurgeDate(): void
    {
        $settings = new FakeSettings();
        $s = $settings->put(1, ['storeKey' => 'shop.example.com']);
        $state = new SyncStateRepository($this->db);
        $registry = new RemoteStoreRegistry($this->db);
        $clock = new FixedClock();
        $state->recordAccount(1, 'acct_a', $s->apiKeyHash());
        $id = $registry->recordPush('https://engine.test', 'acct_a', 'shop.example.com', 1, $clock->now());
        $purge = $clock->now()->modify('+7 days');
        $registry->setStatus($id, 'pending_deletion', $purge);
        $transport = new FakeTransport();
        $transport->enqueue(FakeTransport::json(200, [
            ['store_key' => 'shop.example.com', 'status' => 'pending_deletion', 'purge_after' => $purge->format('c')],
        ], ['X-AnswerEng-Account' => 'acct_a']))->enqueue(FakeTransport::json(409, ['code' => 'store_pending_deletion']));
        $data = $this->viewModel($settings, $transport)->forView(1);
        self::assertSame(['id' => $id, 'storeKey' => 'shop.example.com', 'purgeAfter' => $purge->format('Y-m-d H:i')],
            $data['pendingDeletion']);
    }

    public function testDefaultRowsCarrySyncPermissionAndUrlAndHonourWebsite(): void
    {
        $settings = new FakeSettings();
        $settings->put(1, [], 1);
        $settings->put(2, [], 2);
        $vm = $this->viewModel($settings, new FakeTransport());
        $rows = $vm->forDefault();
        self::assertSame([1, 2], array_column($rows, 'storeId'));
        self::assertTrue($rows[0]['canSync']);
        self::assertSame('/admin/answereng/action/syncnow', $rows[0]['syncUrl']);
        self::assertSame([2], array_column($vm->forDefault(2), 'storeId'));
    }

    /** Found in the local end-to-end run: paused views showed only "paused (store_limit)". */
    public function testPausedAndClearedViewsCarryTheMerchantMessage(): void
    {
        $settings = new FakeSettings();
        $settings->put(1, [], 1);
        $settings->put(2, [], 1);
        $state = new SyncStateRepository($this->db);
        $log = new SyncLog($this->db);
        $clock = new FixedClock();
        $state->ensure(1);
        $state->ensure(2);
        $state->recordPaused(1, $state->get(1)->configRevision, 'store_limit');
        $log->append(1, $clock->now(), 'debounce', 'paused', 'HTTP 403 store_limit limit=3');
        $state->recordSuccess(2, $state->get(2)->configRevision, $clock->now(), $clock->now(), 0, null, 'catalog_cleared');
        $rows = $this->viewModel($settings, new FakeTransport())->forDefault();
        self::assertStringContainsString('allows 3 store(s)', (string) $rows[0]['message']);
        self::assertStringStartsWith('No eligible products', (string) $rows[1]['message']);
        $transport = new FakeTransport();
        $transport->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        $view = $this->viewModel($settings, $transport)->forView(1);
        self::assertStringContainsString('upgrade your plan', (string) $view['message']);
    }
}
