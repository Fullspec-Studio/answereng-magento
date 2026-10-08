<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\SyncStateRepository as Repo;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class SyncStateRepositoryTest extends DbTestCase
{
    private Repo $repo;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new Repo($this->db);
        $this->clock = new FixedClock();
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function set(int $storeId, array $fields): void
    {
        $this->repo->ensure($storeId);
        if ($fields === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn ($k) => "`$k` = ?", array_keys($fields)));
        $this->db->execute("UPDATE answereng_sync_state SET $sets WHERE store_id = ?",
            [...array_values($fields), $storeId]);
    }

    public function testEnsureIsIdempotentAndGetReturnsDefaults(): void
    {
        $this->repo->ensure(1);
        $this->repo->ensure(1);
        $s = $this->repo->get(1);
        self::assertSame([Repo::OK, false, 0, 1], [$s->state, $s->dirty, $s->attempts, $s->configRevision]);
    }

    public function testMarkDirtyResetsExhaustedAndCatalogDependentPausesOnly(): void
    {
        $this->set(1, ['state' => 'exhausted', 'attempts' => 3]);
        $this->set(2, ['state' => 'paused', 'reason' => 'validation_failed']);
        $this->set(3, ['state' => 'paused', 'reason' => 'product_limit']);
        $this->set(4, ['state' => 'paused', 'reason' => 'invalid_key']);
        $this->set(5, ['state' => 'blocked', 'reason' => 'too_many_products']);
        $this->repo->markDirty([1, 2, 3, 4, 5, 6], $this->now());
        $states = [];
        foreach (range(1, 6) as $id) {
            $s = $this->repo->get($id);
            self::assertTrue($s->dirty);
            self::assertEquals($this->now(), $s->lastChangeAt);
            $states[$id] = [$s->state, $s->reason, $s->attempts];
        }
        self::assertSame([
            1 => ['ok', null, 0], 2 => ['ok', null, 0], 3 => ['ok', null, 0],
            4 => ['paused', 'invalid_key', 0], 5 => ['blocked', 'too_many_products', 0], 6 => ['ok', null, 0],
        ], $states);
    }

    public function testDebounceSelection(): void
    {
        $old = '2026-10-07 11:50:00';   // 10 min ago
        $recent = '2026-10-07 11:58:00'; // 2 min ago
        $this->set(1, ['dirty' => 1, 'last_change_at' => $old]);                                    // yes
        $this->set(2, ['dirty' => 1, 'last_change_at' => $recent]);                                 // too recent
        $this->set(3, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'blocked']);              // yes
        $this->set(4, ['dirty' => 0, 'state' => 'retrying', 'next_attempt_at' => '2026-10-07 11:59:00']); // due, clean
        $this->set(5, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'retrying',
            'next_attempt_at' => '2026-10-07 12:10:00']);                                          // not due
        $this->set(6, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'exhausted']);            // never
        $this->set(7, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'paused']);               // never
        $this->set(8, ['dirty' => 1, 'last_change_at' => $old]);                                    // disabled
        self::assertSame([1, 3, 4], $this->repo->selectForDebounce([1, 2, 3, 4, 5, 6, 7], $this->now()));
    }

    public function testReconcileSelection(): void
    {
        $this->set(1, []);
        $this->set(2, ['state' => 'blocked']);
        $this->set(3, ['state' => 'exhausted']);
        $this->set(4, ['state' => 'retrying', 'next_attempt_at' => '2026-10-07 11:59:00']);
        $this->set(5, ['state' => 'retrying', 'next_attempt_at' => '2026-10-07 12:10:00']);
        $this->set(6, ['state' => 'paused']);
        self::assertSame([1, 2, 3, 4, 9], $this->repo->selectForReconcile([1, 2, 3, 4, 5, 6, 9], $this->now()));
    }

    public function testSuccessClearsDirtyUnlessEditedDuringThePush(): void
    {
        $started = $this->now();
        $this->set(1, ['dirty' => 1, 'last_change_at' => '2026-10-07 11:00:00', 'state' => 'retrying', 'attempts' => 2]);
        $this->set(2, ['dirty' => 1, 'last_change_at' => '2026-10-07 12:00:30']); // edit during the push
        $this->clock->advance(60);
        $rev = $this->repo->get(1)->configRevision;
        self::assertTrue($this->repo->recordSuccess(1, $rev, $started, $this->now(), 42, 'shop.example.com', null));
        self::assertTrue($this->repo->recordSuccess(2, $rev, $started, $this->now(), 42, 'shop.example.com', null));
        $one = $this->repo->get(1);
        self::assertSame([false, 'ok', 0, 42, 'shop.example.com'],
            [$one->dirty, $one->state, $one->attempts, $one->productCount, $one->syncedStoreKey]);
        self::assertTrue($this->repo->get(2)->dirty);
    }

    public function testOutcomeIsDiscardedWhenTheRevisionMovedOn(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        self::assertTrue($this->repo->applySettingsHash(1, 'new-hash', $this->now()));
        self::assertFalse($this->repo->recordSuccess(1, $rev, $this->now(), $this->now(), 1, 'k', null));
        self::assertFalse($this->repo->recordPaused(1, $rev, 'invalid_key'));
        self::assertSame('ok', $this->repo->get(1)->state);
    }

    public function testRepeatingAnIdenticalSuccessStillCountsAsApplied(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        self::assertTrue($this->repo->recordSuccess(1, $rev, $this->now(), $this->now(), 3, 'k', null));
        self::assertTrue($this->repo->recordSuccess(1, $rev, $this->now(), $this->now(), 3, 'k', null));
    }

    public function testTransientRetriesAtFiveThenFifteenMinutesThenExhausts(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        $this->repo->recordTransient(1, $rev, $this->now(), null);
        $s = $this->repo->get(1);
        self::assertSame(['retrying', 1, '2026-10-07 12:05:00'], [$s->state, $s->attempts, $s->nextAttemptAt?->format('Y-m-d H:i:s')]);
        $this->repo->recordTransient(1, $rev, $this->now(), null);
        $s = $this->repo->get(1);
        self::assertSame(['retrying', 2, '2026-10-07 12:15:00'], [$s->state, $s->attempts, $s->nextAttemptAt?->format('Y-m-d H:i:s')]);
        $this->repo->recordTransient(1, $rev, $this->now(), null);
        $s = $this->repo->get(1);
        self::assertSame(['exhausted', 3, null], [$s->state, $s->attempts, $s->nextAttemptAt]);
    }

    public function testThrottleHonoursRetryAfterAndIsReportedForManualSyncs(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        $this->repo->recordTransient(1, $rev, $this->now(), 900);
        $s = $this->repo->get(1);
        self::assertSame(['retrying', 'throttled', '2026-10-07 12:15:00'],
            [$s->state, $s->retryReason, $s->nextAttemptAt?->format('Y-m-d H:i:s')]);
        self::assertSame('2026-10-07 12:15:00', $this->repo->throttledUntil(1, $this->now())?->format('Y-m-d H:i:s'));
        $this->clock->advance(901);
        self::assertNull($this->repo->throttledUntil(1, $this->now()));
    }

    public function testPausedAndBlockedResetRetryBookkeeping(): void
    {
        $this->set(1, ['state' => 'retrying', 'attempts' => 2, 'next_attempt_at' => '2026-10-07 12:15:00']);
        $rev = $this->repo->get(1)->configRevision;
        $this->repo->recordPaused(1, $rev, 'store_limit');
        $s = $this->repo->get(1);
        self::assertSame(['paused', 'store_limit', 0, null], [$s->state, $s->reason, $s->attempts, $s->nextAttemptAt]);
        $this->repo->recordBlocked(1, $rev, 'too_many_products');
        self::assertSame(['blocked', 'too_many_products'], [$this->repo->get(1)->state, $this->repo->get(1)->reason]);
    }

    public function testSettingsHashBumpsOnlyOnChangeAndResetsTheView(): void
    {
        $this->set(1, ['state' => 'paused', 'reason' => 'invalid_key', 'attempts' => 0, 'dirty' => 0]);
        self::assertTrue($this->repo->applySettingsHash(1, 'h1', $this->now()));
        $s = $this->repo->get(1);
        self::assertSame([2, 'ok', null, true], [$s->configRevision, $s->state, $s->reason, $s->dirty]);
        self::assertFalse($this->repo->applySettingsHash(1, 'h1', $this->now()));
        self::assertSame(2, $this->repo->get(1)->configRevision);
    }

    public function testAccountAndSnapshot(): void
    {
        $this->repo->recordAccount(1, 'acct_x', 'keyhash');
        $this->repo->saveSnapshot(1, 'fp', '{"a":1}', $this->now());
        $s = $this->repo->get(1);
        self::assertSame(['acct_x', 'keyhash', 'fp', '{"a":1}'],
            [$s->accountId, $s->accountKeyHash, $s->snapshotFingerprint, $s->snapshotJson]);
    }
}
