<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeSettings;

final class RemoteStoreRegistryTest extends DbTestCase
{
    private RemoteStoreRegistry $registry;
    private SyncStateRepository $state;
    private FakeSettings $settings;
    private RoleResolver $roles;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new RemoteStoreRegistry($this->db);
        $this->state = new SyncStateRepository($this->db);
        $this->settings = new FakeSettings();
        $this->roles = new RoleResolver($this->settings, $this->state, $this->registry);
        $this->now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
    }

    /** A view that has pushed `key` through engine.test as acct_a with its current key. */
    private function pushed(int $storeId, string $key): int
    {
        $s = $this->settings->put($storeId, ['storeKey' => $key]);
        $this->state->recordAccount($storeId, 'acct_a', $s->apiKeyHash());
        return $this->registry->recordPush('https://engine.test', 'acct_a', $key, $storeId, $this->now);
    }

    public function testPushRecordsOneIdentityPerConnectionAndKey(): void
    {
        $a = $this->pushed(1, 'shop.example.com');
        self::assertSame($a, $this->registry->recordPush('https://engine.test', 'acct_a', 'shop.example.com', 1, $this->now));
        self::assertNotSame($a, $this->registry->recordPush('https://engine.test', 'acct_b', 'shop.example.com', 1, $this->now));
        self::assertCount(2, $this->registry->forView(1));
        self::assertSame('active', $this->registry->get($a)?->remoteStatus);
    }

    public function testTwoViewsShareOneIdentity(): void
    {
        $a = $this->pushed(1, 'shop.example.com');
        $this->settings->put(1, ['enabled' => false, 'storeKey' => 'shop.example.com']);
        $b = $this->pushed(2, 'shop.example.com');
        self::assertSame($a, $b);
        self::assertSame([1, 2], $this->registry->viewsOf($a));
        self::assertSame([2], $this->roles->activeUsers($a));
    }

    public function testChangingTheKeyMakesTheOldStorePreviousImmediately(): void
    {
        $old = $this->pushed(1, 'old.example.com');
        self::assertTrue($this->roles->isCurrent($this->registry->get($old), 1));
        $this->settings->put(1, ['storeKey' => 'new.example.com']);
        self::assertFalse($this->roles->isCurrent($this->registry->get($old), 1));
        self::assertNull($this->roles->currentFor(1));
    }

    public function testANewApiKeyMeansNothingIsCurrentUntilTheAccountIsSeenAgain(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->settings->put(1, ['storeKey' => 'shop.example.com', 'apiKey' => 'ae_rotated']);
        self::assertFalse($this->roles->isCurrent($this->registry->get($id), 1));
        $this->state->recordAccount(1, 'acct_a', $this->settings->forStore(1)->apiKeyHash());
        self::assertTrue($this->roles->isCurrent($this->registry->get($id), 1));
    }

    public function testStatusAndRepushAfterDeletion(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->registry->setStatus($id, 'pending_deletion', $this->now->modify('+7 days'));
        self::assertSame('2026-10-14 12:00:00', $this->registry->get($id)?->purgeAfter?->format('Y-m-d H:i:s'));
        $this->registry->setStatus($id, 'deleted', $this->now->modify('+7 days'));
        $this->pushed(1, 'shop.example.com');
        self::assertSame(['active', null], [$this->registry->get($id)?->remoteStatus, $this->registry->get($id)?->purgeAfter]);
    }

    public function testForgetRemovesTheLinkAndTheIdentityWithItsLastLink(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->pushed(2, 'shop.example.com');
        $this->registry->forget($id, 1);
        self::assertNotNull($this->registry->get($id));
        $this->registry->forget($id, 2);
        self::assertNull($this->registry->get($id));
    }
}
