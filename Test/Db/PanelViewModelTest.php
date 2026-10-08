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
    }
}
