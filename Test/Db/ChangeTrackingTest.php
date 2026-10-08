<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class ChangeTrackingTest extends DbTestCase
{
    public function testMarksOnlyEnabledViewsOfTheGivenWebsites(): void
    {
        $settings = new FakeSettings();
        $settings->put(1, [], 1);
        $settings->put(2, [], 2);
        $settings->put(3, ['enabled' => false], 1);
        $state = new SyncStateRepository($this->db);
        $tracker = new ChangeTracker($settings, $state, new FixedClock());
        $tracker->markWebsites([1]);
        self::assertSame([true, false, false], [$state->get(1)->dirty, $state->get(2)->dirty, $state->get(3)->dirty]);
        $tracker->markAll();
        self::assertTrue($state->get(2)->dirty);
        self::assertFalse($state->get(3)->dirty);
    }

    public function testConfigChangesBumpExactlyTheViewsWhoseEffectiveSettingsChanged(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $state = new SyncStateRepository($this->db);
        $handler = new ConfigChangeHandler($settings, $state, new FixedClock(), new ArrayCache());
        self::assertSame([1, 2], $handler->handle());       // first save records hashes
        self::assertSame([], $handler->handle());           // nothing changed
        $settings->put(2, ['brands' => ['Acme', 'New Brand']]);
        self::assertSame([2], $handler->handle());
        self::assertSame([2, 3], [$state->get(1)->configRevision, $state->get(2)->configRevision]);
    }

    public function testAConnectionChangeDropsTheOldSummaryCacheAndSnapshot(): void
    {
        $settings = new FakeSettings();
        $one = $settings->put(1);
        $two = $settings->put(2);
        $state = new SyncStateRepository($this->db);
        $cache = new ArrayCache();
        $clock = new FixedClock();
        foreach ([$one, $two] as $s) {
            $cache->save(SummaryProvider::CACHE_PREFIX . $s->fingerprint(), '{"latest_run":null}', 900);
            $state->saveSnapshot($s->storeId, $s->fingerprint(), '{"latest_run":null}', $clock->now());
        }
        $handler = new ConfigChangeHandler($settings, $state, $clock, $cache);
        $handler->handle();
        self::assertCount(2, $cache->items);                 // unchanged connections keep theirs

        $settings->put(1, ['apiKey' => 'ae_other']);        // switch view 1's connection away...
        $handler->handle();
        self::assertSame([SummaryProvider::CACHE_PREFIX . $two->fingerprint()], array_keys($cache->items));
        self::assertSame([null, null, null], [$state->get(1)->snapshotFingerprint, $state->get(1)->snapshotJson,
            $state->get(1)->snapshotFetchedAt]);
        self::assertSame($two->fingerprint(), $state->get(2)->snapshotFingerprint);

        $settings->put(1);                                   // ...and back: nothing old to show as fresh
        $handler->handle();
        self::assertNull($cache->load(SummaryProvider::CACHE_PREFIX . $one->fingerprint()));
        self::assertNull($state->get(1)->snapshotJson);
    }
}
